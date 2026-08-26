<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la categoria structural_row_exclusion (2026-08-24, hallazgo A09/G
 * P2/P4) en RuleTagMismatchResolutionCommand -- INDEPENDIENTE de
 * safe_reconfirm, para patrones cuyo unico cambio de filas es la exclusion
 * de una o mas filas TOTAL lider (mecanismo #6,
 * SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()) ya
 * reconocidas por el motor.
 *
 * El gate exige mecanicamente:
 *  1. historical_rows resuelto por identidad estable (nunca pattern_id crudo).
 *  2. cero filas vivas ausentes del historico (solo se permite EXCLUIR, nunca agregar).
 *  3. al menos una fila realmente excluida.
 *  4. cada fila excluida verificada en vivo contra isEmbeddedLeadingTotalRow()
 *     -- nunca se asume que "ausente del vivo" implica "es TOTAL lider".
 *
 * Fixtures 100% sinteticas. safe_reconfirm NO se modifica ni se relaja --
 * ver RuleTagMismatchResolutionCommandIdentityTest para su cobertura
 * completa (shift, subconjunto, sin cambios, nuevo, split, merge), no
 * duplicada aqui salvo el caso explicito de regresion al final.
 */
class RuleTagMismatchResolutionCommandStructuralExclusionTest extends TestCase
{
    use RefreshDatabase;

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $val = null, array $deps = []): array
    {
        return [
            'valor_bruto' => $val, 'es_editable' => $editable, 'esta_bloqueada' => $blocked,
            'es_formula' => $formula, 'formula' => $formulaText, 'dependencias' => $deps,
        ];
    }

    private function createActiveStructure(string $sheet, string $section, int $filaInicio, int $filaFin): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 2,
            'hash_estructura' => 'hash_' . $sheet . '_' . $section,
            'estructura' => ['forms' => [[
                'sheetName' => $sheet,
                'sections' => [[
                    'codigo' => $section, 'titulo' => 'Seccion de prueba',
                    'filaInicioDatos' => $filaInicio, 'filaFinDatos' => $filaFin, 'filaHeader' => $filaInicio - 1,
                    'fields' => [
                        ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false],
                        ['letra' => 'B', 'label' => 'Concepto2', 'esTotal' => false, 'esControlOculto' => false],
                        ['letra' => 'C', 'label' => 'Total', 'esTotal' => true, 'esControlOculto' => false],
                        ['letra' => 'D', 'label' => 'Dato1', 'esTotal' => false, 'esControlOculto' => false],
                        ['letra' => 'E', 'label' => 'Dato2', 'esTotal' => false, 'esControlOculto' => false],
                    ],
                ]],
            ]]],
            'status' => 'active',
        ]);
    }

    private function dataRowCells(int $row): array
    {
        return [
            "A{$row}" => $this->cell(false, true, false, null, "Item {$row}"),
            "C{$row}" => $this->cell(false, true, true, "=SUM(D{$row}:E{$row})", null, ["D{$row}", "E{$row}"]),
            "D{$row}" => $this->cell(true, false),
            "E{$row}" => $this->cell(true, false),
        ];
    }

    private function leadingTotalRowCells(int $row, int $forwardFrom, int $forwardTo): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, 'Concepto real'),
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$forwardFrom}:D{$forwardTo})", null, array_map(fn ($r) => "D{$r}", range($forwardFrom, $forwardTo))),
            "E{$row}" => $this->cell(false, true),
        ];
    }

    private function remainderRowCells(int $row): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, "Remanente {$row}"),
            "D{$row}" => $this->cell(true, false),
        ];
    }

    private function putCellData(string $sheet, string $section, array $cells): void
    {
        Storage::disk('local')->put("certificacion/cell-data/{$sheet}-{$section}.json", json_encode($cells));
    }

    private function seedQuestions(string $sheet, string $section, array $patternsById): void
    {
        $questions = [[
            'id' => 'section_review', 'type' => 'section_review',
            'response' => 'revisada', 'review_status' => 'section_reviewed',
            'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
        ]];
        foreach ($patternsById as $pid => $data) {
            $questions[] = [
                'id' => "patron_{$pid}_empty", 'type' => 'pattern_question', 'pattern_id' => $pid,
                'question' => "Pregunta de prueba (Patrón {$pid}: " . implode(',', $data['rows']) . ')',
                'response' => $data['response'] ?? 'debe_registrar_cero', 'review_status' => 'reviewed',
                'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                'source_type' => 'manual', 'fingerprint_version' => 2,
                'pattern_fingerprint' => $data['fingerprint'],
                'pattern_rows' => $data['rows'],
            ];
        }

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => ["{$sheet}_{$section}" => $questions],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function callTag(string $sheet, string $section, int $patternId, string $category = 'structural_row_exclusion'): array
    {
        $exitCode = Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => $sheet, 'section' => $section, 'pattern_id' => $patternId,
            '--category' => $category, '--reason' => 'test', '--by' => 'test',
        ]);

        return [$exitCode, Artisan::output()];
    }

    // ── Caso 1: exclusion EXACTA de un TOTAL lider -- debe aceptar ──

    public function test_exact_leading_total_exclusion_is_accepted(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTG', 'X', 10, 14);

        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->leadingTotalRowCells(12, 13, 14)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TESTG', 'X', $cells);

        $this->seedQuestions('TESTG', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);

        // Vivo id=2 = [13,14] (subconjunto de historico P2=[12,13,14]).
        [$exitCode, $output] = $this->callTag('TESTG', 'X', 2);

        $this->assertSame(0, $exitCode, "exclusion exacta de fila 12 (TOTAL lider real) debe aceptarse -- salida:\n{$output}");
        $this->assertStringContainsString('Filas TOTAL lider excluidas (mecanismo #6, verificado en vivo): [12]', $output);
        $this->assertStringContainsString('Filas vivas: [13,14]', $output);
        $this->assertStringContainsString('Filas historicas: [12,13,14]', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    // ── Caso 2: diferencia con una fila ADICIONAL no-TOTAL -- debe rechazar ──

    public function test_extra_non_total_row_is_rejected(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTH', 'X', 10, 15);

        // Misma base que el caso 1, pero agrega fila 15 (mismo modo
        // direct-input que 13/14, MISMA firma -- se agrupa en el mismo
        // patron vivo) que el historico NUNCA tuvo.
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->leadingTotalRowCells(12, 13, 14)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14) + $this->remainderRowCells(15);
        $this->putCellData('TESTH', 'X', $cells);

        $this->seedQuestions('TESTH', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);

        // Vivo id=2 = [13,14,15] -- [13,14] serian subconjunto valido, pero
        // 15 es una fila NUEVA no explicada por ninguna exclusion.
        [$exitCode, $output] = $this->callTag('TESTH', 'X', 2);

        $this->assertNotSame(0, $exitCode, "fila 15 nueva no explicada por el TOTAL lider debe rechazar -- salida:\n{$output}");
        $this->assertStringContainsString('Aparecen filas vivas que NO estaban en el historico', $output);
        $this->assertStringContainsString('[15]', $output);
    }

    // ── Caso 3: fila "excluida" que NO cumple el mecanismo #6 -- debe rechazar ──

    public function test_excluded_row_not_matching_mechanism_6_is_rejected(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTI', 'X', 10, 14);

        // Fila 12 SIN NINGUN dato de cell-data (ausente por completo) --
        // "desaparece" del vivo por falta de datos, no por ser un TOTAL
        // lider real. isEmbeddedLeadingTotalRow(12) debe devolver false
        // (sin evidencia: ninguna celda, ninguna etiqueta TOTAL, ninguna
        // formula hacia adelante).
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TESTI', 'X', $cells);

        $this->seedQuestions('TESTI', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_supuesto_total'],
        ]);

        [$exitCode, $output] = $this->callTag('TESTI', 'X', 2);

        $this->assertNotSame(0, $exitCode, "fila 12 sin evidencia de mecanismo #6 nunca debe aceptarse como exclusion -- salida:\n{$output}");
        $this->assertStringContainsString('no cumple el mecanismo #6', $output);
        $this->assertStringContainsString('La fila 12', $output);
    }

    // ── Caso 4: SPLIT ambiguo -- debe rechazar (categoria nunca llega a MISMATCH) ──

    public function test_split_is_rejected_for_structural_row_exclusion_too(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTJ', 'X', 60, 63);

        $cells = $this->dataRowCells(60) + $this->dataRowCells(61)
            + $this->remainderRowCells(62) + $this->remainderRowCells(63);
        $this->putCellData('TESTJ', 'X', $cells);

        $this->seedQuestions('TESTJ', 'X', [
            1 => ['rows' => [60, 61, 62, 63], 'fingerprint' => 'fpv2_hist_unico'],
        ]);

        [$exitCodeA, $outputA] = $this->callTag('TESTJ', 'X', 1);
        [$exitCodeB, $outputB] = $this->callTag('TESTJ', 'X', 2);

        $this->assertNotSame(0, $exitCodeA, "split: ninguno de los dos candidatos debe comprometerse -- salida:\n{$outputA}");
        $this->assertNotSame(0, $exitCodeB, "split: ninguno de los dos candidatos debe comprometerse -- salida:\n{$outputB}");
        $this->assertStringContainsString('FULL_REVALIDATION', $outputA);
        $this->assertStringContainsString('FULL_REVALIDATION', $outputB);
    }

    // ── Caso 5: MERGE ambiguo -- debe rechazar ──

    public function test_merge_is_rejected_for_structural_row_exclusion_too(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTK', 'X', 50, 53);

        $cells = $this->dataRowCells(50) + $this->dataRowCells(51) + $this->dataRowCells(52) + $this->dataRowCells(53);
        $this->putCellData('TESTK', 'X', $cells);

        $this->seedQuestions('TESTK', 'X', [
            1 => ['rows' => [50, 51], 'fingerprint' => 'fpv2_hist_a'],
            2 => ['rows' => [52, 53], 'fingerprint' => 'fpv2_hist_b'],
        ]);

        [$exitCode, $output] = $this->callTag('TESTK', 'X', 1);

        $this->assertNotSame(0, $exitCode, "merge: no debe adivinar cual historico corresponde -- salida:\n{$output}");
        $this->assertStringContainsString('FULL_REVALIDATION', $output);
    }

    // ── Caso 6: patron NUEVO, sin ningun historico -- debe rechazar ──

    public function test_new_pattern_is_rejected_for_structural_row_exclusion_too(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTL', 'X', 40, 41);
        $this->putCellData('TESTL', 'X', $this->dataRowCells(40) + $this->dataRowCells(41));

        $this->seedQuestions('TESTL', 'X', [
            99 => ['rows' => [100, 101], 'fingerprint' => 'fpv2_no_relacionado'],
        ]);

        [$exitCode, $output] = $this->callTag('TESTL', 'X', 1);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('FULL_REVALIDATION', $output);
    }

    // ── Caso 7: regresion -- safe_reconfirm se comporta EXACTAMENTE igual con el nuevo gate presente ──

    public function test_safe_reconfirm_regression_unaffected_by_new_gate(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTM', 'X', 20, 22);

        $cells = $this->leadingTotalRowCells(20, 21, 22);
        $cells += $this->dataRowCells(21);
        $cells += $this->dataRowCells(22);
        $this->putCellData('TESTM', 'X', $cells);

        $this->seedQuestions('TESTM', 'X', [
            1 => ['rows' => [20], 'fingerprint' => 'fpv2_hist_p1_total'],
            2 => ['rows' => [21, 22], 'fingerprint' => 'fpv2_DELIBERADAMENTE_INCORRECTO'],
        ]);

        [$exitCode, $output] = $this->callTag('TESTM', 'X', 1, 'safe_reconfirm');

        $this->assertSame(0, $exitCode, "safe_reconfirm con match exacto debe seguir aceptando igual que antes -- salida:\n{$output}");
        $this->assertStringContainsString('Filas vivas: [21,22]', $output);
        $this->assertStringContainsString('Filas historicas: [21,22]', $output);
        $this->assertStringNotContainsString('TOTAL lider excluidas', $output, 'safe_reconfirm nunca debe mostrar la linea de exclusion (es exclusiva de structural_row_exclusion)');
    }

    public function test_safe_reconfirm_still_rejects_row_subset_exactly_as_before(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTN', 'X', 10, 14);

        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->leadingTotalRowCells(12, 13, 14)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TESTN', 'X', $cells);

        $this->seedQuestions('TESTN', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);

        [$exitCode, $output] = $this->callTag('TESTN', 'X', 2, 'safe_reconfirm');

        $this->assertNotSame(0, $exitCode, 'safe_reconfirm debe seguir rechazando el mismo subconjunto que structural_row_exclusion SI acepta -- el gate estricto no se relajo');
        $this->assertStringContainsString('cambio estructural, nunca puede etiquetarse safe_reconfirm', $output);
    }
}
