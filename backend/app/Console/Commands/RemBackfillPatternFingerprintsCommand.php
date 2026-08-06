<?php

namespace App\Console\Commands;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\EnhancedCellScanner;
use App\Domain\RemParser\Services\SectionDetectorService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Migracion de identidad de patrones (nace del bug de filterAggregators()
 * y la auditoria de compatibilidad A09/F-G, 2026-08-06). Dos modos:
 *
 * MODO INFORME (Fase 1, sin --sections): recorre todas las hojas-secciones
 * de reglas-funcionales.json y calcula que pattern_rows/pattern_fingerprint
 * le corresponderia a cada respuesta ya guardada. Solo lectura, solo
 * --dry-run.
 *
 * MODO RECONCILIACION (Fase 2, con --sheet + --sections): compara los
 * patrones vigentes hoy contra los patrones que resultarian de una
 * correccion de estructura propuesta (simulada en una transaccion con
 * ROLLBACK garantizado + Storage::fake, igual que la auditoria de
 * compatibilidad previa), reconcilia las respuestas guardadas por
 * row_fingerprint (no por pattern_id) usando PatternReconciliationService,
 * e informa el resultado. Soporta --dry-run (por defecto) o --write
 * (escritura real, gateada detras de --confirm + --target + backup
 * automatico) -- el camino de escritura esta implementado pero nunca se
 * invoca sin autorizacion explicita del operador en cada ejecucion.
 */
class RemBackfillPatternFingerprintsCommand extends Command
{
    protected $signature = 'rem:backfill-pattern-fingerprints
                            {--sheet= : Hoja (ej. A09). Obligatorio junto con --sections para el modo reconciliacion.}
                            {--section= : Filtro de una sola seccion -- solo para el modo informe generico (Fase 1)}
                            {--sections= : Lista de secciones separadas por coma (ej. F,G). Activa el modo reconciliacion (Fase 2). Requiere --sheet y --source.}
                            {--source= : Ruta al Excel fuente -- obligatorio en modo reconciliacion}
                            {--dry-run : Modo simulacion. Obligatorio si no se pasa --write.}
                            {--write : Solicita escritura real sobre reglas-funcionales.json. Requiere --confirm y --target.}
                            {--confirm= : Debe ser exactamente CONFIRMAR-MIGRACION-A09 para habilitar --write}
                            {--target= : Ruta explicita al reglas-funcionales.json real a modificar -- requerido con --write}';

    protected $description = 'Fase 1: informe de cobertura de fingerprints. Fase 2 (--sheet+--sections): reconcilia respuestas por row_fingerprint contra una estructura propuesta, en modo simulacion o escritura real gateada.';

    private const REGLAS_FUNCIONALES_PATH = 'certificacion/reglas-funcionales.json';

    public function handle(SectionCalibrationMatrixService $matrixService, PatternReconciliationService $reconciler): int
    {
        if ($this->option('sections')) {
            return $this->handleReconciliation($matrixService, $reconciler);
        }

        return $this->handleGenericBackfillReport($matrixService);
    }

    // =====================================================================
    // MODO RECONCILIACION (Fase 2)
    // =====================================================================

    private function handleReconciliation(SectionCalibrationMatrixService $matrixService, PatternReconciliationService $reconciler): int
    {
        $sheet = $this->option('sheet');
        if (! $sheet) {
            $this->error('--sections requiere --sheet.');

            return self::FAILURE;
        }

        $sectionCodes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('sections')))));
        if (empty($sectionCodes)) {
            $this->error('--sections no puede resolver en una lista vacia.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if (! $write && ! $dryRun) {
            $this->error('Debe especificar --dry-run (simulacion) o --write (escritura real, requiere --confirm y --target).');

            return self::FAILURE;
        }
        if ($write && $dryRun) {
            $this->error('--write y --dry-run son mutuamente excluyentes.');

            return self::FAILURE;
        }

        $target = null;
        if ($write) {
            if ($this->option('confirm') !== 'CONFIRMAR-MIGRACION-A09') {
                $this->error('--write requiere --confirm=CONFIRMAR-MIGRACION-A09 exacto.');

                return self::FAILURE;
            }
            $target = $this->option('target');
            if (! $target) {
                $this->error('--write requiere --target=<ruta al reglas-funcionales.json real>.');

                return self::FAILURE;
            }
            if (! file_exists($target)) {
                $this->error("Archivo objetivo no encontrado: {$target}");

                return self::FAILURE;
            }
        }

        $source = $this->option('source');
        if (! $source || ! file_exists($source)) {
            $this->error('--source (ruta al Excel) es obligatorio y debe existir en modo reconciliacion.');

            return self::FAILURE;
        }

        $activeStructure = RemTemplateStructure::where('anio', 2026)->where('serie', 'A')->where('status', 'active')->first();
        if (! $activeStructure) {
            $this->error('No hay estructura activa para 2026/A.');

            return self::FAILURE;
        }

        // --- "ANTES": estado real, vigente hoy, sin tocar nada ---
        $beforeBySection = [];
        foreach ($sectionCodes as $sec) {
            $beforeBySection[$sec] = $matrixService->buildPatternMatrix($sheet, $sec);
        }

        // --- Construir estructura + cell-data PROPUESTOS, en memoria ---
        $reader = IOFactory::createReaderForFile($source);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($source);
        $worksheet = $spreadsheet->getSheetByName($sheet);
        if (! $worksheet) {
            $this->error("Hoja '{$sheet}' no encontrada en el archivo fuente.");

            return self::FAILURE;
        }

        $sectionDetector = app(SectionDetectorService::class);
        $freshSections = $sectionDetector->detect($worksheet, $sheet);
        $freshByCode = [];
        foreach ($freshSections as $s) {
            $freshByCode[$s->codigo] = $s->toArray();
        }
        foreach ($sectionCodes as $sec) {
            if (! isset($freshByCode[$sec])) {
                $this->error("Sección '{$sec}' no fue detectada por el parser en el archivo fuente.");
                $spreadsheet->disconnectWorksheets();

                return self::FAILURE;
            }
        }

        $baseEstructura = is_string($activeStructure->estructura) ? json_decode($activeStructure->estructura, true) : $activeStructure->estructura;
        $proposedEstructura = $baseEstructura;
        $formIndex = null;
        foreach ($proposedEstructura['forms'] as $i => $form) {
            if (($form['sheetName'] ?? '') === $sheet) {
                $formIndex = $i;
                break;
            }
        }
        if ($formIndex === null) {
            $this->error("Hoja '{$sheet}' no existe en la estructura activa.");
            $spreadsheet->disconnectWorksheets();

            return self::FAILURE;
        }

        $oldSections = $proposedEstructura['forms'][$formIndex]['sections'];
        $newSectionsArray = [];
        foreach ($oldSections as $section) {
            $code = $section['codigo'] ?? null;
            $newSectionsArray[] = in_array($code, $sectionCodes, true) ? $freshByCode[$code] : $section;
        }
        $proposedEstructura['forms'][$formIndex]['sections'] = $newSectionsArray;

        $scanner = new EnhancedCellScanner();
        $proposedCells = [];
        foreach ($sectionCodes as $sec) {
            $proposedCells[$sec] = $scanner->scanForSection($worksheet, $freshByCode[$sec]);
        }
        $spreadsheet->disconnectWorksheets();

        // --- "DESPUES": simulacion aislada -- DB con ROLLBACK garantizado + Storage fake ---
        $afterBySection = [];
        DB::beginTransaction();
        try {
            $proposedRow = RemTemplateStructure::create([
                'anio' => 2026,
                'serie' => 'A',
                'rem_template_id' => $activeStructure->rem_template_id,
                'version_number' => 250,
                'hash_estructura' => 'SIMULACION-RECONCILIACION-'.uniqid(),
                'estructura' => $proposedEstructura,
                'status' => 'active',
                'source_filename' => $activeStructure->source_filename,
                'notes' => 'Fila de simulacion de reconciliacion Fase 2 -- revertida por ROLLBACK, nunca debe persistir.',
            ]);
            RemTemplateStructure::where('id', $activeStructure->id)->update(['status' => 'draft']);

            // Se lee a traves del disco 'local' actualmente vinculado (no via
            // storage_path() directo) para que esto sea correcto tanto en uso
            // real (disco real) como en tests que ya hicieron Storage::fake('local')
            // antes de invocar este comando (de lo contrario, tras el fake()
            // de la linea siguiente se perderia el fixture del test y se
            // leeria el archivo real del disco, rompiendo el aislamiento).
            $realReglas = Storage::disk('local')->exists(self::REGLAS_FUNCIONALES_PATH)
                ? Storage::disk('local')->get(self::REGLAS_FUNCIONALES_PATH)
                : json_encode(['_questions' => []]);
            Storage::fake('local');
            Storage::disk('local')->put(self::REGLAS_FUNCIONALES_PATH, $realReglas);

            $fakeCellStorage = new CellDataStorageService;
            foreach ($sectionCodes as $sec) {
                $fakeCellStorage->saveCellData($sheet, $sec, $proposedCells[$sec]);
            }

            foreach ($sectionCodes as $sec) {
                $freshMatrixService = new SectionCalibrationMatrixService(
                    app(CertificationService::class),
                    app(FunctionalRuleService::class),
                    new CellDataStorageService,
                    app(ColumnRoleResolverService::class),
                );
                $afterBySection[$sec] = $freshMatrixService->buildPatternMatrix($sheet, $sec);
            }
        } finally {
            DB::rollBack();
        }

        $stillActive = RemTemplateStructure::find($activeStructure->id)?->status === 'active';
        $noResidualRows = ! RemTemplateStructure::where('version_number', 250)->exists();
        if (! $stillActive || ! $noResidualRows) {
            $this->error('ALERTA DE SEGURIDAD: la verificacion post-rollback no paso. Abortando sin continuar ni escribir nada.');

            return self::FAILURE;
        }
        $this->info('Verificado tras rollback: estructura activa real intacta, sin filas de simulación residuales.');
        $this->newLine();

        // --- Reconciliar cada sección por row_fingerprint ---
        $totales = ['reviewed' => 0, 'pending' => 0, 'requiere_revalidacion' => 0, 'unresolved' => 0];
        $reportBySection = [];

        foreach ($sectionCodes as $sec) {
            $beforePatterns = array_map(fn ($p) => ['id' => $p['id'], 'row_fingerprint' => $p['row_fingerprint'], 'filas' => $p['filas']], $beforeBySection[$sec]['patterns']);
            $afterPatterns = array_map(fn ($p) => ['id' => $p['id'], 'row_fingerprint' => $p['row_fingerprint'], 'filas' => $p['filas']], $afterBySection[$sec]['patterns']);

            $questionsByPatternId = [];
            foreach ($beforeBySection[$sec]['questions'] ?? [] as $q) {
                if (in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true) && isset($q['pattern_id'])) {
                    $questionsByPatternId[$q['pattern_id']][] = $q;
                }
            }

            $reconciled = $reconciler->reconcile($beforePatterns, $questionsByPatternId, $afterPatterns);
            $effectiveReviewed = $reconciler->computeEffectiveSectionReviewed($reconciled);
            $historicoReviewed = false;
            foreach ($beforeBySection[$sec]['questions'] ?? [] as $q) {
                if (($q['type'] ?? '') === 'section_review' && ($q['review_status'] ?? '') === 'section_reviewed') {
                    $historicoReviewed = true;
                    break;
                }
            }

            $counts = ['reviewed' => 0, 'pending' => 0, 'requiere_revalidacion' => 0, 'unresolved' => 0];
            foreach ($reconciled as $r) {
                $counts[$r['reconciliation_status']]++;
                $totales[$r['reconciliation_status']]++;
            }

            $reportBySection[$sec] = compact('reconciled', 'effectiveReviewed', 'historicoReviewed', 'counts');

            $this->line("--- {$sheet}/{$sec} ---");
            $this->table(
                ['Patrón nuevo', 'Huella (corta)', 'Filas', 'Estado', 'Deriva de'],
                array_map(fn ($r) => [
                    $r['after_pattern_id'],
                    substr($r['row_fingerprint'], 0, 20),
                    implode(',', $r['filas']),
                    $r['reconciliation_status'],
                    $r['derived_from_fingerprint'] ? implode(', ', array_map(fn ($f) => substr($f, 0, 14), $r['derived_from_fingerprint'])) : '-',
                ], $reconciled)
            );
            $this->line('section_reviewed histórico: '.($historicoReviewed ? 'SI' : 'no').' | efectivo tras reconciliación: '.($effectiveReviewed ? 'SI' : 'NO'));
            $this->newLine();
        }

        $this->warn('========== RESUMEN DE RECONCILIACIÓN ==========');
        $this->table(['Clasificación', 'Cantidad'], [
            ['reviewed (conservadas o remapeadas automáticamente)', $totales['reviewed']],
            ['pending (patrones nuevos, sin antecesor)', $totales['pending']],
            ['requiere_revalidacion (divididos/fusionados)', $totales['requiere_revalidacion']],
            ['unresolved', $totales['unresolved']],
        ]);

        if ($write) {
            return $this->executeRealWrite($sheet, $sectionCodes, $reportBySection, $target);
        }

        $this->newLine();
        $this->warn('SIMULACIÓN: nada fue escrito. reglas-funcionales.json real y estructura activa real quedaron intactos.');

        return self::SUCCESS;
    }

    /**
     * Escritura real -- implementada, pero solo se alcanza si el operador
     * pasa --write --confirm=CONFIRMAR-MIGRACION-A09 --target=<ruta>
     * explícitamente. Nunca se invoca automáticamente.
     */
    private function executeRealWrite(string $sheet, array $sectionCodes, array $reportBySection, string $target): int
    {
        $backupPath = $target.'.bak-'.now()->format('Ymd_His');
        if (! copy($target, $backupPath)) {
            $this->error("No se pudo crear el respaldo en {$backupPath}. Abortando sin escribir.");

            return self::FAILURE;
        }
        $this->info("Respaldo creado: {$backupPath}");

        $all = json_decode(file_get_contents($target), true);

        foreach ($sectionCodes as $sec) {
            $key = "{$sheet}_{$sec}";
            $existingOther = array_values(array_filter(
                $all['_questions'][$key] ?? [],
                fn ($q) => ! in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true)
            ));

            $newPatternQuestions = [];
            foreach ($reportBySection[$sec]['reconciled'] as $r) {
                if ($r['reconciliation_status'] === PatternReconciliationService::STATUS_REVIEWED && $r['carried_over_answers']) {
                    foreach ($r['carried_over_answers'] as $q) {
                        $q['pattern_rows'] = $r['filas'];
                        $q['pattern_fingerprint'] = $r['row_fingerprint'];
                        $q['backfill_status'] = 'migrated_fase2';
                        $q['reconciliation_status'] = $r['reconciliation_status'];
                        $newPatternQuestions[] = $q;
                    }
                }
                // pending/requiere_revalidacion/unresolved: no se escribe
                // ninguna respuesta activa -- se conservan unicamente como
                // historical_answers en el reporte, no en el JSON real
                // (persistir el historial estructurado es trabajo de una
                // fase posterior, fuera del alcance de esta escritura).
            }

            $all['_questions'][$key] = array_merge($existingOther, $newPatternQuestions);
        }

        file_put_contents($target, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Escritura completada sobre '.$target);

        return self::SUCCESS;
    }

    // =====================================================================
    // MODO INFORME GENÉRICO (Fase 1 -- sin cambios de comportamiento)
    // =====================================================================

    private function handleGenericBackfillReport(SectionCalibrationMatrixService $matrixService): int
    {
        if (! $this->option('dry-run')) {
            $this->error(
                'El modo informe genérico solo soporta --dry-run. No existe ningún camino de escritura aquí -- '.
                'use --sections para el modo reconciliación si necesita --write.'
            );

            return self::FAILURE;
        }

        $sheetFilter = $this->option('sheet');
        $sectionFilter = $this->option('section');
        if ($sectionFilter && ! $sheetFilter) {
            $this->error('--section requiere --sheet.');

            return self::FAILURE;
        }

        $path = storage_path('app/private/'.self::REGLAS_FUNCIONALES_PATH);
        if (! file_exists($path)) {
            $this->error("No se encontro {$path}");

            return self::FAILURE;
        }

        $all = json_decode(file_get_contents($path), true);
        if (! is_array($all) || ! isset($all['_questions'])) {
            $this->error('reglas-funcionales.json no tiene el formato esperado (falta _questions).');

            return self::FAILURE;
        }

        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        $this->info('Estructura activa detectada para fuente primaria: id='.($activeStructure->id ?? 'ninguna').
            ' (solo preguntas con structure_version == este id pueden resolverse por fuente primaria en esta fase)');
        $this->newLine();

        $keys = array_keys($all['_questions']);
        sort($keys);

        $totalPreguntas = 0;
        $statusCounts = [
            'resolved_cross_validated' => 0,
            'resolved_single_source_primary' => 0,
            'resolved_single_source_regex' => 0,
            'unresolved_no_source' => 0,
            'unresolved_mismatch' => 0,
        ];
        $unresolvedDetail = [];
        $seccionesProcesadas = 0;
        $seccionesConFuentePrimaria = 0;

        $matrixCache = [];

        foreach ($keys as $key) {
            if (! preg_match('/^([A-Z0-9]+)_(.+)$/', $key, $m)) {
                continue;
            }
            $sheet = $m[1];
            $section = $m[2];

            if ($sheetFilter && strtoupper($sheet) !== strtoupper($sheetFilter)) {
                continue;
            }
            if ($sectionFilter && strtolower($section) !== strtolower($sectionFilter)) {
                continue;
            }

            $questions = $all['_questions'][$key];
            $patternQuestions = array_values(array_filter(
                $questions,
                fn ($q) => in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true)
            ));

            if (empty($patternQuestions)) {
                continue;
            }

            $seccionesProcesadas++;

            $structureVersions = array_unique(array_map(fn ($q) => $q['structure_version'] ?? null, $patternQuestions));
            $primarySourceAvailable = $activeStructure && in_array((string) $activeStructure->id, $structureVersions, true);

            $matrixByPatternId = [];
            if ($primarySourceAvailable) {
                $cacheKey = "{$sheet}|{$section}";
                if (! isset($matrixCache[$cacheKey])) {
                    $matrixCache[$cacheKey] = $matrixService->buildPatternMatrix($sheet, $section);
                }
                foreach ($matrixCache[$cacheKey]['patterns'] ?? [] as $p) {
                    $matrixByPatternId[$p['id']] = $p['filas'];
                }
                $seccionesConFuentePrimaria++;
            }

            $this->line("--- {$sheet}/{$section} --- (".count($patternQuestions).' preguntas de patron, fuente primaria: '.($primarySourceAvailable ? 'SI' : 'no disponible').')');

            foreach ($patternQuestions as $q) {
                $totalPreguntas++;
                $patternId = $q['pattern_id'] ?? null;

                $filasPrimaria = $primarySourceAvailable ? ($matrixByPatternId[$patternId] ?? null) : null;
                $filasRegex = $this->extractRowsFromQuestionText($q['question'] ?? '');

                [$status, $filasFinal] = $this->classify($filasPrimaria, $filasRegex);
                $statusCounts[$status]++;

                if (str_starts_with($status, 'unresolved')) {
                    $unresolvedDetail[] = [
                        'hoja_seccion' => "{$sheet}/{$section}",
                        'id' => $q['id'] ?? '(sin id)',
                        'pattern_id' => $patternId,
                        'motivo' => $status,
                        'primaria' => $filasPrimaria ? implode(',', $filasPrimaria) : '(no disponible)',
                        'regex' => $filasRegex ? implode(',', $filasRegex) : '(no parseable)',
                    ];
                }
            }
        }

        $this->newLine();
        $this->warn('========== INFORME DE BACKFILL (DRY-RUN — nada fue escrito) ==========');
        $this->table(['Métrica', 'Valor'], [
            ['Hojas-secciones con preguntas de patrón', $seccionesProcesadas],
            ['  ...con fuente primaria disponible', $seccionesConFuentePrimaria],
            ['  ...solo con fuente secundaria (regex) o sin fuente', $seccionesProcesadas - $seccionesConFuentePrimaria],
            ['Total preguntas de patrón evaluadas', $totalPreguntas],
        ]);

        $this->table(['Clasificación', 'Cantidad', '% del total'], array_map(
            fn ($k, $v) => [$k, $v, $totalPreguntas > 0 ? round($v / $totalPreguntas * 100, 1).'%' : '0%'],
            array_keys($statusCounts),
            $statusCounts
        ));

        $cobertura = $totalPreguntas > 0
            ? round(($statusCounts['resolved_cross_validated'] + $statusCounts['resolved_single_source_primary'] + $statusCounts['resolved_single_source_regex']) / $totalPreguntas * 100, 1)
            : 0;
        $this->newLine();
        $this->info("Cobertura estimada del backfill (resuelto por al menos una fuente): {$cobertura}%");

        if (! empty($unresolvedDetail)) {
            $this->newLine();
            $this->warn('Casos UNRESOLVED ('.count($unresolvedDetail).'):');
            $this->table(
                ['Hoja/Sección', 'ID pregunta', 'pattern_id', 'Motivo', 'Filas (primaria)', 'Filas (regex)'],
                array_map(fn ($u) => [$u['hoja_seccion'], $u['id'], $u['pattern_id'], $u['motivo'], $u['primaria'], $u['regex']], $unresolvedDetail)
            );
        }

        $this->newLine();
        $this->warn('DRY-RUN: informe generado, reglas-funcionales.json NO fue modificado.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: ?array<int>}
     */
    private function classify(?array $filasPrimaria, ?array $filasRegex): array
    {
        $norm = fn (?array $f) => $f === null ? null : $this->sortedUnique($f);
        $p = $norm($filasPrimaria);
        $r = $norm($filasRegex);

        if ($p !== null && $r !== null) {
            return $p === $r
                ? ['resolved_cross_validated', $p]
                : ['unresolved_mismatch', null];
        }
        if ($p !== null) {
            return ['resolved_single_source_primary', $p];
        }
        if ($r !== null) {
            return ['resolved_single_source_regex', $r];
        }

        return ['unresolved_no_source', null];
    }

    private function sortedUnique(array $filas): array
    {
        $filas = array_values(array_unique(array_map('intval', $filas)));
        sort($filas, SORT_NUMERIC);

        return $filas;
    }

    /**
     * Extrae el listado de filas embebido en el texto libre de la pregunta,
     * ej. "...(Patrón 1: 91, 92, 93, 106)" -> [91, 92, 93, 106].
     * Validacion estricta: si el formato no calza exactamente, retorna null
     * en vez de intentar adivinar -- nunca se acepta una extraccion parcial.
     */
    private function extractRowsFromQuestionText(string $question): ?array
    {
        if (! preg_match('/\(Patr[oó]n\s+\d+:\s*([\d,\s]+)\)\s*$/u', $question, $m)) {
            return null;
        }

        $parts = array_map('trim', explode(',', $m[1]));
        $rows = [];
        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                return null; // formato inesperado -- no se acepta extraccion parcial
            }
            $rows[] = (int) $part;
        }

        return empty($rows) ? null : $rows;
    }
}
