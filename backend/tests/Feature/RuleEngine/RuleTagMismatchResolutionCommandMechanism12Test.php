<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Espejo de RuleTagMismatchResolutionCommandStructuralExclusionTest, pero
 * para mecanismo #12 (SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow(),
 * subtotal embebido hacia atras) -- extension 2026-08-28,
 * SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12. NO modifica ningun test de
 * mecanismo #6 (ver el archivo original, sigue pasando exactamente igual).
 *
 * Fixtures 100% sinteticas (hojas TEST*), ninguna seccion real (A09/I ni
 * ninguna otra) se toca. Ningun --commit real contra datos de produccion en
 * ningun test de este archivo.
 */
class RuleTagMismatchResolutionCommandMechanism12Test extends TestCase
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

    /**
     * Subtotal embebido hacia ATRAS (mecanismo #12) -- opuesto de
     * leadingTotalRowCells del archivo #6: la formula referencia filas
     * ANTERIORES, nunca posteriores. Deliberadamente SIN texto en columna B
     * (a diferencia de leadingTotalRowCells del mecanismo #6) -- a
     * diferencia de isEmbeddedLeadingTotalRow() (que recorre TODAS las
     * columnas de texto buscando una etiqueta TOTAL), isEmbeddedBackwardSubtotalRow()
     * se detiene en la PRIMERA celda no vacia/no-formula que encuentra
     * (ver SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow(),
     * linea ~2576) -- si esa primera celda no es la etiqueta TOTAL, la fila
     * se descarta de inmediato. Por eso "TOTAL" debe ser la primera celda
     * con texto en orden de columna (A vacia, B vacia, C="TOTAL").
     */
    private function backwardSubtotalRowCells(int $row, int $backFrom, int $backTo): array
    {
        return [
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$backFrom}:D{$backTo})", null, array_map(fn ($r) => "D{$r}", range($backFrom, $backTo))),
            "E{$row}" => $this->cell(false, true),
        ];
    }

    /**
     * Replica el patron real de AR337 (regla 229, punto 17.27 de CLAUDE.md):
     * una fila con etiqueta TOTAL cuya formula mezcla referencias hacia
     * atras Y una referencia hacia una fila POSTERIOR (aunque esa fila
     * posterior sea, en la realidad, un artefacto extraviado del template) --
     * el chequeo de "referencia posterior" del mecanismo #12 debe rechazar
     * esta fila de todos modos, sin excepcion especial. Mismo cuidado de
     * orden de columnas que backwardSubtotalRowCells() de arriba.
     */
    private function backwardSubtotalWithForwardReferenceCells(int $row, int $backFrom, int $backTo, int $forwardRef): array
    {
        $deps = array_merge(array_map(fn ($r) => "D{$r}", range($backFrom, $backTo)), ["D{$forwardRef}"]);

        return [
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$forwardRef}+D{$backFrom}:D{$backTo})", null, $deps),
            "E{$row}" => $this->cell(false, true),
        ];
    }

    /**
     * Replica el patron real de la regla 87 (Familia B, punto 16.9 de
     * CLAUDE.md): una fila de DATO real, sin ninguna evidencia de fórmula
     * hacia atrás -- su columna "concepto" no dice TOTAL, es simplemente
     * texto de categoria real, y sus demas columnas son editables (captura
     * real), no formulas. El mecanismo #12 nunca debe confundir esto con un
     * subtotal.
     */
    private function realDataRowLookingLikeConceptCells(int $row): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, 'Método de Regulación de Fertilidad más Preservativos'),
            "D{$row}" => $this->cell(true, false), // editable real, no formula
            "E{$row}" => $this->cell(true, false),
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

    // ── Caso 1: #12 valido -- debe aceptar ──

    public function test_exact_backward_subtotal_exclusion_is_accepted(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TEST12A', 'X', 10, 14);

        // Fila 12 = subtotal embebido hacia atras, referencia D10:D11 (ANTERIORES).
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->backwardSubtotalRowCells(12, 10, 11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TEST12A', 'X', $cells);

        $this->seedQuestions('TEST12A', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_subtotal'],
        ]);

        // Vivo id=2 = [13,14] (subconjunto de historico P2=[12,13,14]).
        [$exitCode, $output] = $this->callTag('TEST12A', 'X', 2);

        $this->assertSame(0, $exitCode, "exclusion exacta de fila 12 (subtotal embebido hacia atras, mecanismo #12) debe aceptarse -- salida:\n{$output}");
        $this->assertStringContainsString('Filas subtotal embebido hacia atras excluidas (mecanismo #12, verificado en vivo): [12]', $output);
        $this->assertStringContainsString('Filas vivas: [13,14]', $output);
        $this->assertStringContainsString('Filas historicas: [12,13,14]', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    // ── Dry-run no modifica response/reviewed_by/reviewed_at/review_status ──

    public function test_dry_run_does_not_modify_response_or_reviewed_fields(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TEST12B', 'X', 10, 14);
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->backwardSubtotalRowCells(12, 10, 11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TEST12B', 'X', $cells);
        $this->seedQuestions('TEST12B', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_subtotal'],
        ]);

        $before = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        [$exitCode, $output] = $this->callTag('TEST12B', 'X', 2);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY-RUN', $output);

        $after = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertSame($before, $after, 'un dry-run de rule:tag-mismatch-resolution nunca debe escribir en reglas-funcionales.json (response/reviewed_by/reviewed_at/review_status deben quedar byte-identicos)');
    }

    // ── Caso: fila que dejo de cumplir #12 (sin evidencia real) -- debe rechazar ──

    public function test_excluded_row_not_matching_mechanism_12_is_rejected(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TEST12C', 'X', 10, 14);

        // Fila 12 SIN NINGUN dato de cell-data -- desaparece del vivo por
        // falta de datos, no por ser un subtotal embebido real.
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TEST12C', 'X', $cells);

        $this->seedQuestions('TEST12C', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_supuesto_subtotal'],
        ]);

        [$exitCode, $output] = $this->callTag('TEST12C', 'X', 2);

        $this->assertNotSame(0, $exitCode, "fila 12 sin evidencia de mecanismo #12 nunca debe aceptarse como exclusion -- salida:\n{$output}");
        $this->assertStringContainsString('no cumple el mecanismo #6', $output);
        $this->assertStringContainsString('ni el mecanismo #12', $output);
        $this->assertStringContainsString('La fila 12', $output);
    }

    // ── Caso: patron con AMBOS mecanismos coexistiendo -- mezcla, debe rechazar ──

    public function test_mixed_mechanism_6_and_12_within_same_pattern_is_rejected(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TEST12D', 'X', 10, 18);

        // Fila 12 = subtotal hacia atras (referencia D10:D11, mecanismo #12).
        // Fila 16 = TOTAL lider hacia adelante (referencia D17:D18, mecanismo #6).
        // Ambas desaparecen del vivo dentro del MISMO patron -- mezcla no soportada.
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->backwardSubtotalRowCells(12, 10, 11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14) + $this->remainderRowCells(15)
            + [
                'C16' => $this->cell(false, true, false, null, 'TOTAL'),
                'D16' => $this->cell(false, true, true, '=SUM(D17:D18)', null, ['D17', 'D18']),
                'E16' => $this->cell(false, true),
            ]
            + $this->remainderRowCells(17) + $this->remainderRowCells(18);
        $this->putCellData('TEST12D', 'X', $cells);

        $this->seedQuestions('TEST12D', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14, 15, 16, 17, 18], 'fingerprint' => 'fpv2_hist_p2_mixto'],
        ]);

        // Vivo id=2 tras excluir 12 y 16 = [13,14,15,17,18].
        [$exitCode, $output] = $this->callTag('TEST12D', 'X', 2);

        $this->assertNotSame(0, $exitCode, "mezcla de mecanismo #6 (fila 16) y #12 (fila 12) dentro del mismo patron debe rechazarse -- salida:\n{$output}");
        $this->assertStringContainsString('no resuelven todas al mismo mecanismo', $output);
    }

    // ── Caso: fila con referencia hacia adelante tipo AR337 -- #12 debe seguir rechazando ──

    public function test_row_with_forward_reference_like_ar337_is_not_accepted_by_mechanism_12(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TEST12E', 'X', 10, 14);

        // Fila 12: formula mezcla D10:D11 (atras) + D20 (adelante, fuera de
        // la seccion declarada, exactamente como AR337 en la regla real 229 --
        // ver punto 17.27 de CLAUDE.md). El chequeo de "referencia posterior"
        // debe rechazar la fila igual, sin ninguna excepcion especial.
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->backwardSubtotalWithForwardReferenceCells(12, 10, 11, 20)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TEST12E', 'X', $cells);

        $this->seedQuestions('TEST12E', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_ar337'],
        ]);

        [$exitCode, $output] = $this->callTag('TEST12E', 'X', 2);

        $this->assertNotSame(0, $exitCode, "fila 12 con referencia hacia adelante (patron AR337) nunca debe aceptarse via mecanismo #12 -- salida:\n{$output}");
        $this->assertStringContainsString('ni el mecanismo #12', $output);
    }

    // ── Caso: falso positivo equivalente a la regla 87 -- fila de dato real, no TOTAL ──
    // Verificado a nivel del metodo expuesto directamente (mismo codigo real
    // que usa el comando/controlador), en vez de vía el comando completo --
    // el precedente real de la regla 87 (punto 16.9 de CLAUDE.md) fue un
    // hallazgo del MECANISMO en si, no del flujo de identidad de patrones,
    // asi que se prueba aqui de forma aislada y directa.

    public function test_regla_87_equivalent_false_positive_is_rejected_for_mechanism_12(): void
    {
        Storage::fake('local');
        $structure = $this->createActiveStructure('TEST12F', 'X', 10, 14);
        $sectionDecl = $structure->estructura['forms'][0]['sections'][0];

        // Fila 12: fila de DATO real (patron real de la regla 87, A05/C fila
        // 50 -- "Método de Regulación de Fertilidad más Preservativos"),
        // columna B con texto real (no TOTAL) como PRIMERA celda no vacia,
        // columnas D/E editables (captura real), sin ninguna formula.
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->realDataRowLookingLikeConceptCells(12)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TEST12F', 'X', $cells);

        $scanner = app(\App\Domain\RuleEngine\Services\PatternMigrationScanner::class);
        $result = $scanner->isEmbeddedBackwardSubtotalRow('TEST12F', 'X', 12, $sectionDecl);

        $this->assertFalse($result, 'una fila de dato real (equivalente al falso positivo de la regla 87: texto de categoria real, sin evidencia de formula hacia atras) nunca debe ser reconocida como subtotal embebido por el mecanismo #12');
    }

    // ── Caso: mecanismo #6 sigue funcionando exactamente igual con el gate generalizado ──

    public function test_mechanism_6_still_works_via_generalized_gate(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('TEST12G', 'X', 10, 14);

        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + [
                'B12' => $this->cell(false, true, false, null, 'Concepto real'),
                'C12' => $this->cell(false, true, false, null, 'TOTAL'),
                'D12' => $this->cell(false, true, true, '=SUM(D13:D14)', null, ['D13', 'D14']),
                'E12' => $this->cell(false, true),
            ]
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TEST12G', 'X', $cells);

        $this->seedQuestions('TEST12G', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);

        [$exitCode, $output] = $this->callTag('TEST12G', 'X', 2);

        $this->assertSame(0, $exitCode, "mecanismo #6 (TOTAL lider hacia adelante) debe seguir aceptandose exactamente igual tras generalizar el gate -- salida:\n{$output}");
        $this->assertStringContainsString('Filas TOTAL lider excluidas (mecanismo #6, verificado en vivo): [12]', $output);
    }
}
