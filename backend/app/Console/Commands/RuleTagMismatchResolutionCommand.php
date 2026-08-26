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
 *    A09/G P2/P4): categoria independiente para el caso especifico de una o
 *    mas filas TOTAL lider (mecanismo #6,
 *    SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()) que
 *    salieron del conjunto vivo -- exige que la UNICA diferencia entre
 *    filas vivas e historicas sean filas verificadas mecanicamente como
 *    TOTAL lider (cero adiciones, cero otras eliminaciones); ver gate
 *    detallado en handle().
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

        // structural_row_exclusion (2026-08-24): categoria INDEPENDIENTE de
        // safe_reconfirm (el bloque de arriba no se toca ni se relaja) para
        // patrones cuyo unico cambio de filas es la exclusion de una o mas
        // filas TOTAL lider (mecanismo #6) ya reconocidas por el motor. El
        // gate exige, mecanicamente, TODO lo siguiente -- cualquier falla
        // aborta sin persistir nada:
        //  1. historical_rows debe existir (mismo requisito que safe_reconfirm,
        //     resuelto por identidad estable, nunca por pattern_id crudo).
        //  2. viva no puede tener NINGUNA fila que el historico no tenga
        //     (cero adiciones -- solo se permite EXCLUIR, nunca agregar).
        //  3. debe existir al menos una fila excluida (si coinciden exacto,
        //     no hay nada que justificar via esta categoria -- usar safe_reconfirm).
        //  4. CADA fila excluida debe cumplir, verificado en vivo contra
        //     cell-data real, isEmbeddedLeadingTotalRow() -- nunca se asume
        //     que "ausente del conjunto vivo" implica "es un TOTAL lider".
        $excludedTotalRows = [];
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

            foreach ($excludedTotalRows as $excludedRow) {
                if (!$scanner->isEmbeddedLeadingTotalRow($sheet, $section, $excludedRow, $sectionDecl)) {
                    $this->error(
                        "La fila {$excludedRow} no cumple el mecanismo #6 (TOTAL lider embebido, verificado en vivo contra cell-data real) -- "
                        . 'no puede excluirse via structural_row_exclusion sin evidencia mecanica real. Requiere revision humana (human_review).'
                    );

                    return self::FAILURE;
                }
            }
        }

        $this->info("Patron {$patternId} de {$sheet}/{$section}:");
        $this->line('  Categoria actual (vigente): MISMATCH');
        $this->line('  Filas vivas: [' . implode(',', $liveRows) . ']');
        $this->line('  Filas historicas: ' . ($historicalRows !== null ? '[' . implode(',', $historicalRows) . ']' : '(no disponibles)'));
        if (!empty($excludedTotalRows)) {
            $this->line('  Filas TOTAL lider excluidas (mecanismo #6, verificado en vivo): [' . implode(',', $excludedTotalRows) . ']');
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
            exclusionMechanism: !empty($excludedTotalRows) ? MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL : null,
        );
        $this->info('Tag de resolucion persistido (solo metadata -- no se toco reglas-funcionales.json ni ningun fingerprint real).');

        return self::SUCCESS;
    }
}
