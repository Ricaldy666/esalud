<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\MismatchResolutionAuditService;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use Illuminate\Console\Command;

/**
 * Registra la clasificacion de resolucion (SAFE_RECONFIRM / HUMAN_REVIEW /
 * STRUCTURAL_REVIEW) de UN patron MISMATCH puntual -- nunca escribe
 * fingerprints ni reglas-funcionales.json, solo la etiqueta de auditoria
 * consumida por el endpoint de resolucion.
 *
 * Dry-run por defecto (igual que rule:rebind-safe-to-structure) -- persistir
 * exige --commit. Requiere --reason y --by explicitos siempre.
 *
 * Revalida en vivo ANTES de permitir el tag:
 *  - la seccion/patron debe estar HOY en categoria MISMATCH (nunca se
 *    etiqueta un patron que ya es AUTO_MIGRATE, QUICK_CONFIRMATION, etc.);
 *  - si se pide --category=safe_reconfirm, las filas vivas del patron deben
 *    coincidir EXACTAMENTE con las filas historicas almacenadas -- un
 *    patron cuyo conjunto de filas cambio nunca puede etiquetarse
 *    safe_reconfirm (eso es, por definicion, un cambio estructural). Este
 *    gate NUNCA se modifica ni se relaja para ningun otro caso.
 *  - si se pide --category=structural_row_exclusion (2026-08-24, hallazgo
 *    A09/G P2/P4; extendido 2026-08-28 a mecanismo #12, auditoria
 *    SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12): categoria independiente
 *    para el caso especifico de una o mas filas TOTAL/subtotal tecnico que
 *    salieron del conjunto vivo -- exige que la UNICA diferencia entre
 *    filas vivas e historicas sean filas verificadas mecanicamente, EN VIVO,
 *    contra mecanismo #6 (SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow())
 *    o mecanismo #12 (SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow())
 *    -- cero adiciones, cero otras eliminaciones, y las filas excluidas deben
 *    coincidir TODAS con el MISMO mecanismo (una mezcla de #6 y #12 dentro
 *    del mismo tag se rechaza explicitamente, nunca se asume ni se resuelve
 *    en silencio -- ver gate detallado en handle()). Ningun mecanismo se
 *    infiere por seccion/hoja/rule_id: se verifica por evidencia estructural
 *    real, fila por fila, igual para cualquier seccion de la Serie A.
 */
class RuleTagMismatchResolutionCommand extends Command
{
    protected $signature = 'rule:tag-mismatch-resolution
                            {sheet : Hoja (ej. A05)}
                            {section : Seccion (ej. C)}
                            {pattern_id : ID del patron, tal como lo reporta migration-plan}
                            {--category= : safe_reconfirm | human_review | structural_review | structural_row_exclusion -- obligatorio}
                            {--reason= : Motivo/evidencia de la clasificacion -- obligatorio}
                            {--by= : Responsable de la clasificacion -- obligatorio}
                            {--commit : Persiste el tag. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Clasifica un patron MISMATCH puntual para el flujo de resolucion (dry-run por defecto, --commit para persistir)';

    public function handle(PatternMigrationScanner $scanner, MismatchResolutionAuditService $audit): int
    {
        $sheet = strtoupper((string) $this->argument('sheet'));
        $section = (string) $this->argument('section');
        $patternId = (int) $this->argument('pattern_id');
        $category = $this->option('category');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        if (!$category || !$reason || !$by) {
            $this->error('--category, --reason y --by son obligatorios.');

            return self::FAILURE;
        }

        if (!in_array($category, MismatchResolutionAuditService::ALLOWED_CATEGORIES, true)) {
            $this->error('Categoria invalida. Debe ser una de: ' . implode(', ', MismatchResolutionAuditService::ALLOWED_CATEGORIES));

            return self::FAILURE;
        }

        $activeStructure = RemTemplateStructure::where('status', 'active')->first();
        if (!$activeStructure) {
            $this->error('No hay ninguna estructura activa.');

            return self::FAILURE;
        }

        $estructura = $activeStructure->estructura;
        $sectionDecl = null;
        foreach ($estructura['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) === $sheet) {
                foreach ($form['sections'] ?? [] as $s) {
                    if (($s['codigo'] ?? null) === $section) {
                        $sectionDecl = $s;
                    }
                }
            }
        }
        if ($sectionDecl === null) {
            $this->error("No se encontro la seccion {$sheet}/{$section} en la estructura activa.");

            return self::FAILURE;
        }

        $plan = $scanner->scanSection($activeStructure, $sheet, $section, $sectionDecl);

        $patternPlan = null;
        foreach ($plan['patterns'] as $p) {
            if ($p['pattern_id'] === $patternId) {
                $patternPlan = $p;

                break;
            }
        }

        if ($patternPlan === null) {
            $this->error("No se encontro el patron {$patternId} en {$sheet}/{$section}.");

            return self::FAILURE;
        }

        if ($patternPlan['category'] !== 'MISMATCH') {
            $this->error("El patron {$patternId} de {$sheet}/{$section} esta HOY en categoria {$patternPlan['category']}, no MISMATCH. No se puede etiquetar.");

            return self::FAILURE;
        }

        $liveRows = $patternPlan['live_rows'];
        sort($liveRows, SORT_NUMERIC);
        $liveFingerprint = $patternPlan['live_canonical_fingerprint'];

        // Filas historicas del patron REALMENTE emparejado por identidad de
        // contenido (PatternMigrationScanner::matchLivePatternsToHistorical()),
        // no del pattern_id crudo/posicional -- hallazgo 2026-08-24: cuando
        // una fila TOTAL lider aislada sale del conjunto vivo (mecanismo #6),
        // todos los pattern_id posteriores se corren, y un lookup directo por
        // $patternId aqui comparaba contra las filas de un patron historico
        // distinto. $patternPlan ya trae la resolucion correcta (unica fuente
        // de verdad, la misma que usan scanSection() y los endpoints de
        // QuickRevalidation/MismatchResolution) -- nunca se reimplementa el
        // matching aqui. Si el matching fue ambiguo (split/merge) o el patron
        // es nuevo, $patternPlan['historical_rows'] es null Y ademas
        // $patternPlan['category'] nunca es MISMATCH en ese caso (cae a
        // FULL_REVALIDATION en scanSection()) -- el chequeo de categoria de
        // arriba ya rechaza esos casos antes de llegar aqui.
        $historicalRows = $patternPlan['historical_rows'] ?? null;

        if ($category === MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM) {
            if ($historicalRows === null) {
                $this->error('No se encontraron filas historicas para este patron -- no puede etiquetarse safe_reconfirm sin evidencia de que las filas coinciden.');

                return self::FAILURE;
            }

            $sortedHistorical = $historicalRows;
            sort($sortedHistorical, SORT_NUMERIC);

            if ($sortedHistorical !== $liveRows) {
                $this->error(
                    'Las filas vivas no coinciden con las filas historicas -- esto es un cambio estructural, nunca puede etiquetarse safe_reconfirm. '
                    . 'Historicas: [' . implode(',', $sortedHistorical) . '] vs vivas: [' . implode(',', $liveRows) . ']'
                );

                return self::FAILURE;
            }
        }

        // structural_row_exclusion (2026-08-24, mecanismo #6; extendido
        // 2026-08-28 a mecanismo #12): categoria INDEPENDIENTE de
        // safe_reconfirm (el bloque de arriba no se toca ni se relaja) para
        // patrones cuyo unico cambio de filas es la exclusion de una o mas
        // filas TOTAL/subtotal tecnico ya reconocidas por el motor. El gate
        // exige, mecanicamente, TODO lo siguiente -- cualquier falla aborta
        // sin persistir nada:
        //  1. historical_rows debe existir (mismo requisito que safe_reconfirm,
        //     resuelto por identidad estable, nunca por pattern_id crudo).
        //  2. viva no puede tener NINGUNA fila que el historico no tenga
        //     (cero adiciones -- solo se permite EXCLUIR, nunca agregar).
        //  3. debe existir al menos una fila excluida (si coinciden exacto,
        //     no hay nada que justificar via esta categoria -- usar safe_reconfirm).
        //  4. CADA fila excluida se verifica en vivo, contra cell-data real,
        //     contra AMBOS mecanismos (#6 isEmbeddedLeadingTotalRow() y #12
        //     isEmbeddedBackwardSubtotalRow()) -- nunca se asume que "ausente
        //     del conjunto vivo" implica que es un TOTAL/subtotal legitimo.
        //     Una fila que no cumple NINGUNO de los dos aborta el comando.
        //     Una fila que cumple AMBOS simultaneamente tambien aborta (no
        //     deberia ocurrir por construccion -- #6 exige evidencia
        //     exclusivamente hacia adelante, #12 exige evidencia hacia atras
        //     -- pero se rechaza explicitamente en vez de asumir cual aplica).
        //  5. TODAS las filas excluidas del mismo tag deben resolver al MISMO
        //     mecanismo -- una mezcla de #6 y #12 dentro de un unico patron
        //     se rechaza y requiere revision humana, nunca se resuelve
        //     mezclando mecanismos en un solo tag.
        $excludedTotalRows = [];
        $resolvedMechanism = null;
        if ($category === MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION) {
            if ($historicalRows === null) {
                $this->error('No se encontraron filas historicas para este patron (identidad no resuelta o ambigua) -- no puede etiquetarse structural_row_exclusion.');

                return self::FAILURE;
            }

            $sortedHistorical = $historicalRows;
            sort($sortedHistorical, SORT_NUMERIC);

            $addedRows = array_values(array_diff($liveRows, $sortedHistorical));
            if (!empty($addedRows)) {
                $this->error(
                    'Aparecen filas vivas que NO estaban en el historico -- structural_row_exclusion solo permite EXCLUSION de filas, nunca adicion ni modificacion. '
                    . 'Filas nuevas no explicadas: [' . implode(',', $addedRows) . ']. Requiere revision humana (human_review).'
                );

                return self::FAILURE;
            }

            $excludedTotalRows = array_values(array_diff($sortedHistorical, $liveRows));
            if (empty($excludedTotalRows)) {
                $this->error('Las filas vivas coinciden EXACTAMENTE con las historicas -- no hay ninguna exclusion que justificar via structural_row_exclusion. Use --category=safe_reconfirm.');

                return self::FAILURE;
            }

            $mechanismsByRow = [];
            foreach ($excludedTotalRows as $excludedRow) {
                $matches6 = $scanner->isEmbeddedLeadingTotalRow($sheet, $section, $excludedRow, $sectionDecl);
                $matches12 = $scanner->isEmbeddedBackwardSubtotalRow($sheet, $section, $excludedRow, $sectionDecl);

                if ($matches6 && $matches12) {
                    $this->error(
                        "La fila {$excludedRow} cumple simultaneamente mecanismo #6 y #12 -- caso ambiguo no soportado, requiere revision humana (human_review)."
                    );

                    return self::FAILURE;
                }

                if ($matches6) {
                    $mechanismsByRow[$excludedRow] = MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL;
                } elseif ($matches12) {
                    $mechanismsByRow[$excludedRow] = MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL;
                } else {
                    // Texto EXACTO preservado ("no cumple el mecanismo #6"):
                    // RuleTagMismatchResolutionCommandStructuralExclusionTest
                    // ya afirma este substring literal para el caso "fila
                    // ausente de evidencia" -- se conserva sin tocar y se
                    // agrega la mencion de #12 a continuacion.
                    $this->error(
                        "La fila {$excludedRow} no cumple el mecanismo #6 (TOTAL lider embebido) ni el mecanismo #12 (subtotal embebido hacia atras) -- verificado en vivo contra cell-data real -- "
                        . 'no puede excluirse via structural_row_exclusion sin evidencia mecanica real. Requiere revision humana (human_review).'
                    );

                    return self::FAILURE;
                }
            }

            $distinctMechanisms = array_values(array_unique($mechanismsByRow));
            if (count($distinctMechanisms) > 1) {
                $this->error(
                    'Las filas excluidas no resuelven todas al mismo mecanismo estructural -- se detecto una mezcla de #6 y #12 dentro del mismo patron. '
                    . 'Esta categoria no soporta mezclar mecanismos en un unico tag. Requiere revision humana (human_review). Detalle: ' . json_encode($mechanismsByRow)
                );

                return self::FAILURE;
            }

            $resolvedMechanism = $distinctMechanisms[0];
        }

        $this->info("Patron {$patternId} de {$sheet}/{$section}:");
        $this->line('  Categoria actual (vigente): MISMATCH');
        $this->line('  Filas vivas: [' . implode(',', $liveRows) . ']');
        $this->line('  Filas historicas: ' . ($historicalRows !== null ? '[' . implode(',', $historicalRows) . ']' : '(no disponibles)'));
        if (!empty($excludedTotalRows)) {
            // Texto EXACTO preservado para mecanismo #6 (regresion existente,
            // RuleTagMismatchResolutionCommandStructuralExclusionTest, no se
            // toca) -- mecanismo #12 usa el mismo formato, etiqueta distinta.
            $mechanismLine = $resolvedMechanism === MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL
                ? 'Filas subtotal embebido hacia atras excluidas (mecanismo #12, verificado en vivo)'
                : 'Filas TOTAL lider excluidas (mecanismo #6, verificado en vivo)';
            $this->line("  {$mechanismLine}: [" . implode(',', $excludedTotalRows) . ']');
        }
        $this->line("  Fingerprint vigente: {$liveFingerprint}");
        $this->line("  Categoria propuesta: {$category}");
        $this->line("  Motivo: {$reason}");
        $this->line("  Responsable: {$by}");

        if (!$commit) {
            $this->info('DRY-RUN: no se persistio ningun tag. Ejecuta con --commit para persistir.');

            return self::SUCCESS;
        }

        $audit->setTag(
            $sheet, $section, $patternId, $category, $liveFingerprint, $liveRows, $reason, $by,
            historicalRows: $category === MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION ? $historicalRows : null,
            excludedTotalRows: !empty($excludedTotalRows) ? $excludedTotalRows : null,
            exclusionMechanism: !empty($excludedTotalRows) ? $resolvedMechanism : null,
        );
        $this->info('Tag de resolucion persistido (solo metadata -- no se toco reglas-funcionales.json ni ningun fingerprint real).');

        return self::SUCCESS;
    }
}
