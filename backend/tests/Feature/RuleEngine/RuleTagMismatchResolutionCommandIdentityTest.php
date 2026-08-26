<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre el fix de identidad estable en
 * RuleTagMismatchResolutionCommand::handle() (2026-08-24): el comando ya NO
 * hace su propio lookup posicional por pattern_id crudo para las filas
 * historicas -- usa $patternPlan['historical_rows'], resuelto por
 * PatternMigrationScanner::matchLivePatternsToHistorical() (identidad de
 * CONTENIDO, no posicion), la MISMA fuente que ya usan scanSection() y los
 * endpoints de QuickRevalidation/MismatchResolution.
 *
 * Todas las fixtures son sinteticas -- ninguna seccion real (A09/G, A05/*,
 * A19b/A) se toca en estos tests. El mecanismo TOTAL lider
 * (isEmbeddedLeadingTotalRow, ya cubierto en
 * SectionCalibrationMatrixServiceEmbeddedLeadingTotalRowTest) se reproduce
 * aqui solo en la medida necesaria para forzar el corrimiento de pattern_id
 * que motivo este fix.
 */
class RuleTagMismatchResolutionCommandIdentityTest extends TestCase
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

    /** Fila de dato real, modo formula: C=SUM(D:E), D/E editables (mismo patron ya probado en MismatchResolutionApiTest). */
    private function dataRowCells(int $row): array
    {
        return [
            "A{$row}" => $this->cell(false, true, false, null, "Item {$row}"),
            "C{$row}" => $this->cell(false, true, true, "=SUM(D{$row}:E{$row})", null, ["D{$row}", "E{$row}"]),
            "D{$row}" => $this->cell(true, false),
            "E{$row}" => $this->cell(true, false),
        ];
    }

    /** Fila TOTAL lider real (mecanismo #6): concepto propio en B, marcador TOTAL en C, formula hacia adelante en D. */
    private function leadingTotalRowCells(int $row, int $forwardFrom, int $forwardTo): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, 'Concepto real'),
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$forwardFrom}:D{$forwardTo})", null, array_map(fn ($r) => "D{$r}", range($forwardFrom, $forwardTo))),
            "E{$row}" => $this->cell(false, true),
        ];
    }

    /** Fila "remanente" tras excluir el TOTAL lider: modo direct-input, firma distinta de dataRowCells(). */
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
        $questions = [
            [
                'id' => 'section_review', 'type' => 'section_review',
                'response' => 'revisada', 'review_status' => 'section_reviewed',
                'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
            ],
        ];
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

    // ── Caso: shift simple P2->P1 (coincidencia EXACTA tras excluir el TOTAL lider) ──

    public function test_shift_p2_to_p1_matches_by_identity_and_passes_row_gate(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTA', 'X', 20, 22);

        $cells = $this->leadingTotalRowCells(20, 21, 22);
        $cells += $this->dataRowCells(21);
        $cells += $this->dataRowCells(22);
        $this->putCellData('TESTA', 'X', $cells);

        // Historico: P1=[20] (el TOTAL, quedara huerfano), P2=[21,22] (dato real).
        $this->seedQuestions('TESTA', 'X', [
            1 => ['rows' => [20], 'fingerprint' => 'fpv2_hist_p1_total'],
            2 => ['rows' => [21, 22], 'fingerprint' => 'fpv2_DELIBERADAMENTE_INCORRECTO'],
        ]);

        // Vivo (tras excluir 20): un unico patron, id=1, filas=[21,22] -- debe
        // matchear con historico P2 (EXACTO), no con P1 por posicion.
        $exitCode = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTA', 'section' => 'X', 'pattern_id' => 1,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode, "el patron vivo id=1 ([21,22]) debe matchear EXACTO con historico P2 y pasar el gate de filas -- salida:\n{$output}");
        $this->assertStringContainsString('Filas vivas: [21,22]', $output);
        $this->assertStringContainsString('Filas historicas: [21,22]', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    // ── Caso: A09/G-style, vivo P (subset) -> historico con MAS filas (incluye el TOTAL excluido) ──

    public function test_shrinking_pattern_resolves_correct_historical_but_row_gate_still_rejects(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTB', 'X', 10, 14);

        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->leadingTotalRowCells(12, 13, 14)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TESTB', 'X', $cells);

        // Historico: P1=[10,11] (dato real), P2=[12,13,14] (TOTAL + remanente, igual forma que A09/G real).
        $this->seedQuestions('TESTB', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);

        // Vivo (tras excluir 12): patron id=1=[10,11] (match exacto con P1),
        // patron id=2=[13,14] (subconjunto UNICO de P2=[12,13,14]).
        $exitCode = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTB', 'section' => 'X', 'pattern_id' => 2,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertNotSame(0, $exitCode, 'el gate de igualdad exacta de filas debe seguir rechazando -- excluir el TOTAL es, por definicion, un cambio de conjunto de filas');
        // La parte que este test verifica: el rechazo ahora compara contra el
        // HISTORICO CORRECTO (P2=[12,13,14], el que de verdad contenia el
        // TOTAL), no contra P3 ni ningun otro patron por posicion cruzada.
        $this->assertStringContainsString('Historicas: [12,13,14]', $output, "debe resolver contra el historico P2 real (con el TOTAL), no contra otro patron por posicion -- salida:\n{$output}");
        $this->assertStringContainsString('vivas: [13,14]', $output);
    }

    // ── Caso: seccion sin cambios -- no debe reclasificarse ──

    public function test_unchanged_section_matches_trivially_and_passes_gate(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTC', 'X', 30, 31);
        $this->putCellData('TESTC', 'X', $this->dataRowCells(30) + $this->dataRowCells(31));

        $this->seedQuestions('TESTC', 'X', [
            1 => ['rows' => [30, 31], 'fingerprint' => 'fpv2_DELIBERADAMENTE_INCORRECTO'],
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTC', 'section' => 'X', 'pattern_id' => 1,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode, "seccion sin cambios de filas debe pasar el gate limpio -- salida:\n{$output}");
        $this->assertStringContainsString('Filas historicas: [30,31]', $output);
    }

    // ── Caso: patron realmente nuevo, sin ningun historico -- debe rechazarse, nunca adivinar ──

    public function test_genuinely_new_pattern_is_rejected_never_guessed(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTD', 'X', 40, 41);
        $this->putCellData('TESTD', 'X', $this->dataRowCells(40) + $this->dataRowCells(41));

        // section_review existe (seccion ya revisada) y hay UN historico,
        // pero de filas completamente distintas ([100,101]) a las del
        // patron vivo bajo prueba ([40,41]) -- ningun historico con el que
        // comparar ESE patron especificamente (a nivel de seccion si hay
        // pattern_questions, para no disparar el guard mas grueso de
        // "seccion sin ningun pattern_question en absoluto").
        $this->seedQuestions('TESTD', 'X', [
            99 => ['rows' => [100, 101], 'fingerprint' => 'fpv2_no_relacionado'],
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTD', 'section' => 'X', 'pattern_id' => 1,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('FULL_REVALIDATION', $output, "patron nuevo sin evidencia historica debe caer a FULL_REVALIDATION, nunca adivinarse como MISMATCH tagueable -- salida:\n{$output}");
    }

    // ── Caso: MERGE ambiguo -- dos historicos distintos hoy aparecen fusionados en un unico vivo ──

    public function test_merge_pattern_is_rejected_never_guessed(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTE', 'X', 50, 53);

        // 4 filas de dato real con la MISMA firma (mismo patron vivo hoy: [50,51,52,53]).
        $cells = $this->dataRowCells(50) + $this->dataRowCells(51) + $this->dataRowCells(52) + $this->dataRowCells(53);
        $this->putCellData('TESTE', 'X', $cells);

        // Historico: dos patrones DISTINTOS que juntos cubren exactamente esas
        // mismas 4 filas -- merge real (el vivo de hoy fusiona dos historicos).
        $this->seedQuestions('TESTE', 'X', [
            1 => ['rows' => [50, 51], 'fingerprint' => 'fpv2_hist_a'],
            2 => ['rows' => [52, 53], 'fingerprint' => 'fpv2_hist_b'],
        ]);

        $exitCode = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTE', 'section' => 'X', 'pattern_id' => 1,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('FULL_REVALIDATION', $output, "merge real (vivo=[50,51,52,53] contiene dos historicos distintos) nunca debe adivinar cual de los dos es -- salida:\n{$output}");
    }

    // ── Caso: SPLIT ambiguo -- un unico historico hoy aparece dividido en dos vivos ──

    public function test_split_pattern_is_rejected_never_guessed(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TESTF', 'X', 60, 63);

        // Filas 60,61: modo formula (grupo vivo A). Filas 62,63: modo
        // direct-input (grupo vivo B) -- firma distinta, dos patrones vivos.
        $cells = $this->dataRowCells(60) + $this->dataRowCells(61)
            + $this->remainderRowCells(62) + $this->remainderRowCells(63);
        $this->putCellData('TESTF', 'X', $cells);

        // Historico: UN unico patron que cubre las 4 filas -- split real (hoy
        // aparece dividido en dos patrones vivos, ambos subconjuntos del mismo historico).
        $this->seedQuestions('TESTF', 'X', [
            1 => ['rows' => [60, 61, 62, 63], 'fingerprint' => 'fpv2_hist_unico'],
        ]);

        $exitCodeA = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTF', 'section' => 'X', 'pattern_id' => 1,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $outputA = \Illuminate\Support\Facades\Artisan::output();
        $exitCodeB = \Illuminate\Support\Facades\Artisan::call('rule:tag-mismatch-resolution', [
            'sheet' => 'TESTF', 'section' => 'X', 'pattern_id' => 2,
            '--category' => 'safe_reconfirm', '--reason' => 'test', '--by' => 'test',
        ]);
        $outputB = \Illuminate\Support\Facades\Artisan::output();

        $this->assertNotSame(0, $exitCodeA, "ninguno de los dos candidatos del split debe comprometerse -- salida:\n{$outputA}");
        $this->assertNotSame(0, $exitCodeB, "ninguno de los dos candidatos del split debe comprometerse -- salida:\n{$outputB}");
        $this->assertStringContainsString('FULL_REVALIDATION', $outputA);
        $this->assertStringContainsString('FULL_REVALIDATION', $outputB);
    }
}
