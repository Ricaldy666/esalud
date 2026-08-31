<?php

namespace Tests\Unit\RuleEngine\Evaluators;

use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Fase 3C-3A/3C-3B (CLAUDE.md punto 17.21/17.22). Cubre el soporte aditivo
 * de 'source_rows' en SumEqualsEvaluator::evaluateVerticalAggregation() --
 * lista explicita de filas componentes que reemplaza la iteracion implicita
 * de [row_from:row_to] cuando esta presente. Ausente: comportamiento
 * legacy byte-identico (probado explicitamente).
 *
 * NINGUNA de las 12 reglas reales (208,214,393-402) se toca aqui -- todos
 * los fixtures son sinteticos, replicando exactamente los patrones reales
 * ya auditados (B1 = A09/F.1, B4 = A26/B, ver puntos 17.20/17.21).
 */
class SumEqualsEvaluatorSourceRowsTest extends TestCase
{
    private SumEqualsEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SumEqualsEvaluator;
    }

    private function makeConfig(array $overrides): array
    {
        return array_merge([
            '_rule_key' => 'test_source_rows',
            'scope' => 'row_range',
        ], $overrides);
    }

    private function makeRow(int $rowNumber, array $values, string $concept = ''): object
    {
        return (object) [
            'id' => $rowNumber,
            'data' => [
                'values' => $values,
                'row_number' => $rowNumber,
                'concept' => $concept,
                'professional' => '',
            ],
        ];
    }

    // ============================================================
    // source_rows AUSENTE -- comportamiento legacy byte-identico
    // ============================================================

    public function test_source_rows_absent_behaves_exactly_like_legacy(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70, 'row_to' => 73, 'total_row' => 74,
            'source_letters' => ['I'], 'target_column' => 'I',
        ]);
        $this->assertArrayNotHasKey('source_rows', $config);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 2]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 1]),
            $this->makeRow(74, ['I' => 10], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(5, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
        $this->assertSame('', $result->reason);
    }

    // ============================================================
    // GUARDS: source_rows invalido
    // ============================================================

    public function test_source_rows_empty_array_rejected(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [],
        ]);
        $rows = new Collection([$this->makeRow(149, ['F' => 4]), $this->makeRow(158, ['F' => 4], 'TOTAL')]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('invalid_source_rows_configuration', $result->reason);
        $this->assertSame('invalid_source_rows_configuration', $result->details[0]['reason']);
        $this->assertStringContainsString('vacio', $result->details[0]['message']);
    }

    public function test_source_rows_with_duplicates_rejected(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 150, 153],
        ]);
        $rows = new Collection([$this->makeRow(149, ['F' => 4]), $this->makeRow(158, ['F' => 4], 'TOTAL')]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_source_rows_configuration', $result->reason);
        $this->assertStringContainsString('duplicadas', $result->details[0]['message']);
    }

    public function test_source_rows_with_non_integer_value_rejected(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 'ciento cincuenta y tres'],
        ]);
        $rows = new Collection([$this->makeRow(149, ['F' => 4]), $this->makeRow(158, ['F' => 4], 'TOTAL')]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_source_rows_configuration', $result->reason);
        $this->assertStringContainsString('enteros positivos', $result->details[0]['message']);
    }

    public function test_source_rows_with_zero_or_negative_rejected(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 0, 153],
        ]);
        $rows = new Collection([$this->makeRow(149, ['F' => 4]), $this->makeRow(158, ['F' => 4], 'TOTAL')]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_source_rows_configuration', $result->reason);
    }

    public function test_source_rows_not_array_rejected(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => '149,150,153',
        ]);
        $rows = new Collection([$this->makeRow(149, ['F' => 4]), $this->makeRow(158, ['F' => 4], 'TOTAL')]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_source_rows_configuration', $result->reason);
        $this->assertStringContainsString('debe ser un array', $result->details[0]['message']);
    }

    public function test_source_rows_outside_section_bounds_rejected_when_bounds_available(): void
    {
        // '_section_bounds' es inyectado por RuleEngineService::execute() --
        // aqui se simula directamente para probar el guard del evaluador de
        // forma aislada.
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 999], // 999 fuera de la seccion
            '_section_bounds' => ['inicio' => 146, 'fin' => 158],
        ]);
        $rows = new Collection([$this->makeRow(149, ['F' => 4]), $this->makeRow(158, ['F' => 4], 'TOTAL')]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_source_rows_configuration', $result->reason);
        $this->assertStringContainsString('fuera del rango vivo', $result->details[0]['message']);
    }

    public function test_source_rows_within_bounds_accepted_when_bounds_available(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150],
            '_section_bounds' => ['inicio' => 146, 'fin' => 158],
        ]);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
            $this->makeRow(158, ['F' => 13], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason);
    }

    public function test_section_bounds_absent_does_not_block_source_rows(): void
    {
        // Sin '_section_bounds' (ej. RuleEngineService no pudo resolver la
        // seccion) -- ese guard especifico simplemente no se aplica, el
        // resto de guards si.
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150],
        ]);
        $this->assertArrayNotHasKey('_section_bounds', $config);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
            $this->makeRow(158, ['F' => 13], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason);
    }

    // ============================================================
    // Interaccion con total_row
    // ============================================================

    public function test_total_row_takes_precedence_even_if_listed_inside_source_rows(): void
    {
        // Si por error total_row(158) tambien aparece en source_rows, sigue
        // tratandose como la fila TOTAL (nunca se suma dos veces como
        // componente) -- precedencia ya garantizada por el orden del if/elseif.
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 158],
        ]);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
            $this->makeRow(158, ['F' => 13], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason, 'debe pasar: 4+9=13, la fila 158 nunca se suma como componente pese a estar en source_rows');
    }

    public function test_missing_total_row_still_detected_with_source_rows(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 999,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150],
        ]);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('missing_total_row', $result->reason);
    }

    // ============================================================
    // Fila listada en source_rows pero ausente de $rows
    // ============================================================

    public function test_row_listed_in_source_rows_but_absent_from_rows_is_silently_excluded_from_sum(): void
    {
        // No es un error -- la fila simplemente no contribuye a la suma
        // (mismo comportamiento que un componente ausente de rem_data en el
        // modelo legacy [from:to]).
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 153],
        ]);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            // 150 y 153 deliberadamente ausentes de $rows.
            $this->makeRow(158, ['F' => 4], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason, 'suma = 4 (solo 149 presente), coincide con el total declarado 4');
    }

    // ============================================================
    // B1 -- fixture real (A09/F.1, ver punto 17.21)
    // ============================================================

    public function test_b1_fixture_passed_with_non_trivial_gaps(): void
    {
        // Replica exacta: source_rows=[149,150,153,155,157], huecos
        // (151,152,154,156) con valores GRANDES deliberados -- si el
        // mecanismo los sumara por error (tratando [149:157] como rango
        // contiguo), el resultado seria muy distinto.
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 153, 155, 157],
        ]);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
            $this->makeRow(151, ['F' => 100]), // hueco, NO debe sumarse
            $this->makeRow(152, ['F' => 200]), // hueco, NO debe sumarse
            $this->makeRow(153, ['F' => 12]),
            $this->makeRow(154, ['F' => 300]), // hueco, NO debe sumarse
            $this->makeRow(155, ['F' => 11]),
            $this->makeRow(156, ['F' => 400]), // hueco, NO debe sumarse
            $this->makeRow(157, ['F' => 7]),
            $this->makeRow(158, ['F' => 43], 'TOTAL'), // 4+9+12+11+7=43, formula Excel real
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason);
    }

    public function test_b1_fixture_would_be_wrong_with_naive_contiguous_range(): void
    {
        // Prueba de discriminacion: el MISMO fixture de arriba, sin
        // source_rows -- demuestra que el rango contiguo ingenuo arrastra
        // los huecos y produce un resultado incorrecto (falla contra el
        // TOTAL real de Excel).
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
        ]);
        $this->assertArrayNotHasKey('source_rows', $config);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
            $this->makeRow(151, ['F' => 100]),
            $this->makeRow(152, ['F' => 200]),
            $this->makeRow(153, ['F' => 12]),
            $this->makeRow(154, ['F' => 300]),
            $this->makeRow(155, ['F' => 11]),
            $this->makeRow(156, ['F' => 400]),
            $this->makeRow(157, ['F' => 7]),
            $this->makeRow(158, ['F' => 43], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows, 'sin source_rows, la suma ingenua (1043) no coincide con el TOTAL real de Excel (43) -- confirma que source_rows es necesario, no cosmetico');
        $this->assertSame('vertical_sum_mismatch', $result->details[0]['reason']);
        $this->assertSame(1043.0, $result->details[0]['calculated_sum']);
        $this->assertSame(43.0, $result->details[0]['declared_value']);
    }

    public function test_b1_fixture_failed_case(): void
    {
        $config = $this->makeConfig([
            'row_from' => 149, 'row_to' => 157, 'total_row' => 158,
            'source_letters' => ['F'], 'target_column' => 'F',
            'source_rows' => [149, 150, 153, 155, 157],
        ]);
        $rows = new Collection([
            $this->makeRow(149, ['F' => 4]),
            $this->makeRow(150, ['F' => 9]),
            $this->makeRow(153, ['F' => 12]),
            $this->makeRow(155, ['F' => 11]),
            $this->makeRow(157, ['F' => 7]),
            $this->makeRow(158, ['F' => 44], 'TOTAL'), // deliberadamente incorrecto (real=43)
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame('vertical_sum_mismatch', $result->details[0]['reason']);
        $this->assertSame(43.0, $result->details[0]['calculated_sum']);
        $this->assertSame(44.0, $result->details[0]['declared_value']);
    }

    // ============================================================
    // B4 -- fixture real (A26/B, ver punto 17.21) -- nivel evaluador
    // (el prefiltro que carga la fila 50 se prueba en el Feature test)
    // ============================================================

    public function test_b4_fixture_passed_with_external_row(): void
    {
        $config = $this->makeConfig([
            'row_from' => 54, 'row_to' => 58, 'total_row' => 59,
            'source_letters' => ['D'], 'target_column' => 'D',
            'source_rows' => [54, 55, 56, 57, 58, 50],
        ]);
        $rows = new Collection([
            $this->makeRow(50, ['D' => 3]), // termino externo, fuera de [54:58]
            $this->makeRow(54, ['D' => 0]),
            $this->makeRow(55, ['D' => 0]),
            $this->makeRow(56, ['D' => 0]),
            $this->makeRow(57, ['D' => 0]),
            $this->makeRow(58, ['D' => 0]),
            $this->makeRow(59, ['D' => 3], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason);
    }

    public function test_b4_fixture_failed_when_external_row_altered(): void
    {
        $config = $this->makeConfig([
            'row_from' => 54, 'row_to' => 58, 'total_row' => 59,
            'source_letters' => ['D'], 'target_column' => 'D',
            'source_rows' => [54, 55, 56, 57, 58, 50],
        ]);
        $rows = new Collection([
            $this->makeRow(50, ['D' => 1002]), // alterado deliberadamente (real=3)
            $this->makeRow(54, ['D' => 0]),
            $this->makeRow(55, ['D' => 0]),
            $this->makeRow(56, ['D' => 0]),
            $this->makeRow(57, ['D' => 0]),
            $this->makeRow(58, ['D' => 0]),
            $this->makeRow(59, ['D' => 3], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame(1002.0, $result->details[0]['calculated_sum']);
        $this->assertSame(3.0, $result->details[0]['declared_value']);
    }

    // ============================================================
    // Regla horizontal (per_row) -- source_rows debe ser ignorado
    // ============================================================

    public function test_source_rows_ignored_for_horizontal_per_row_rule(): void
    {
        $config = [
            '_rule_key' => 'test_horizontal',
            'scope' => 'per_row',
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            'row_from' => 11,
            'row_to' => 11,
            'source_rows' => [11], // presente pero NO debe usarse -- scope=per_row nunca llama evaluateVerticalAggregation()
        ];
        $rows = new Collection([
            $this->makeRow(11, ['A' => 5, 'B' => 3, 'C' => 8]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertNotSame('invalid_source_rows_configuration', $result->reason);
    }

    // ============================================================
    // Regla vecina normal (sin source_rows) sigue igual
    // ============================================================

    public function test_neighbor_style_rule_without_source_rows_unaffected(): void
    {
        // Mismo patron de "regla vecina" ya usado en toda la campana (ver
        // RuleEngineServiceTechnicalTotalPilotTest) -- a nivel evaluador,
        // confirma que una regla SIN source_rows (aunque otra regla en el
        // mismo lote si lo tenga -- eso se prueba a nivel Feature) produce
        // exactamente el resultado legacy.
        $config = $this->makeConfig([
            'row_from' => 20, 'row_to' => 21, 'total_row' => 22,
            'source_letters' => ['C'], 'target_column' => 'C',
        ]);
        $rows = new Collection([
            $this->makeRow(20, ['C' => 3]),
            $this->makeRow(21, ['C' => 6]),
            $this->makeRow(22, ['C' => 9], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason);
    }
}
