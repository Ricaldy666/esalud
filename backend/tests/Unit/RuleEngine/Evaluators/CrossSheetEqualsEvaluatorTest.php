<?php

namespace Tests\Unit\RuleEngine\Evaluators;

use App\Domain\RuleEngine\Evaluators\CrossSheetEqualsEvaluator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * REM BM -- FASE BM-2.6B (2026-09-11). Cubre CrossSheetEqualsEvaluator en
 * aislamiento total (sin DB, sin RuleEngineService) -- misma convencion ya
 * usada por SumEqualsEvaluatorTest/RequiredAndLeParentEvaluatorTest. No
 * hardcodea BM -- las hojas de prueba se llaman SHEETA/SHEETB genericas
 * (ver CrossSheetEqualsEvaluatorBmRealFormulasTest para los casos reales
 * BM18/BM18A).
 */
class CrossSheetEqualsEvaluatorTest extends TestCase
{
    private CrossSheetEqualsEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new CrossSheetEqualsEvaluator();
    }

    private function makeRow(int $rowNumber, array $values, string $concept = 'test'): object
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

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            '_rule_key' => 'test_cross_sheet_rule',
            'sheet' => 'SHEETA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SHEETB', 'cell' => 'C3'],
        ], $overrides);
    }

    // ============================================================
    // SUPPORT
    // ============================================================

    public function test_supports_cross_sheet_equals(): void
    {
        $this->assertTrue($this->evaluator->supports('cross_sheet_equals'));
    }

    public function test_does_not_support_other_types(): void
    {
        $this->assertFalse($this->evaluator->supports('sum_equals'));
        $this->assertFalse($this->evaluator->supports('required_and_le_parent'));
        $this->assertFalse($this->evaluator->supports(''));
    }

    // ============================================================
    // SIMPLE == SIMPLE
    // ============================================================

    public function test_simple_equals_simple_correct(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => 10])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 10])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
        $this->assertSame('', $result->reason);
    }

    public function test_simple_equals_simple_incorrect_fails(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => 9])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 10])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('failed', $result->reason);
        $this->assertSame('cross_sheet_mismatch', $result->details[0]['reason']);
        $this->assertSame(9.0, $result->details[0]['source_value']);
        $this->assertSame(10.0, $result->details[0]['target_value']);
    }

    // ============================================================
    // SIMPLE == SUM(RANGE)
    // ============================================================

    public function test_simple_equals_sum_range_correct(): void
    {
        $config = $this->baseConfig([
            'source' => ['cell' => 'B3'],
            'target' => ['sheet' => 'SHEETB', 'range' => 'D1:D3', 'aggregation' => 'sum'],
        ]);
        $rows = new Collection([$this->makeRow(3, ['B' => 6])]);
        $config['_target_rows'] = new Collection([
            $this->makeRow(1, ['D' => 1]),
            $this->makeRow(2, ['D' => 2]),
            $this->makeRow(3, ['D' => 3]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame('', $result->reason);
    }

    public function test_simple_equals_sum_range_incorrect_fails(): void
    {
        $config = $this->baseConfig([
            'source' => ['cell' => 'B3'],
            'target' => ['sheet' => 'SHEETB', 'range' => 'D1:D3', 'aggregation' => 'sum'],
        ]);
        $rows = new Collection([$this->makeRow(3, ['B' => 5])]);
        $config['_target_rows'] = new Collection([
            $this->makeRow(1, ['D' => 1]),
            $this->makeRow(2, ['D' => 2]),
            $this->makeRow(3, ['D' => 3]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame('failed', $result->reason);
        $this->assertSame(5.0, $result->details[0]['source_value']);
        $this->assertSame(6.0, $result->details[0]['target_value']);
    }

    // ============================================================
    // 0 vs 0
    // ============================================================

    public function test_zero_equals_zero_passes(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => 0])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 0])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame(1, $result->totalRows);
    }

    // ============================================================
    // null/null -- ambos vacios
    // ============================================================

    public function test_null_source_and_null_target_is_skipped(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => null])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => null])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('empty_row', $result->reason);
    }

    // ============================================================
    // vacio/0 -- fuente vacia se trata como 0 (mismo criterio que
    // SumEqualsEvaluator para un componente ausente)
    // ============================================================

    public function test_empty_source_treated_as_zero_against_target_zero_passes(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => null])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 0])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
    }

    public function test_empty_source_treated_as_zero_against_nonzero_target_fails(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => ''])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 5])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame(0.0, $result->details[0]['source_value']);
        $this->assertSame(5.0, $result->details[0]['target_value']);
    }

    // Un target vacio, en cambio, NUNCA se trata como 0 -- mismo criterio
    // que SumEqualsEvaluator::evaluatePerRow() (missing_target_value nunca
    // se compara, incluso si la fuente tiene datos reales).
    public function test_nonempty_source_with_empty_target_is_skipped_never_assumes_zero(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => 7])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => null])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('missing_target_value', $result->reason);
    }

    // ============================================================
    // Strings numericas
    // ============================================================

    public function test_numeric_strings_are_compared_as_numbers(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => '10'])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => '10.0'])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->failedRows);
    }

    public function test_non_numeric_string_fails_with_non_numeric_value_reason(): void
    {
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => 'abc'])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 10])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame('non_numeric_value', $result->reason);
        $this->assertSame('non_numeric_value', $result->details[0]['reason']);
    }

    // ============================================================
    // Hoja/celda inexistente
    // ============================================================

    public function test_missing_source_row_is_skipped_with_source_cell_not_found(): void
    {
        $config = $this->baseConfig(['source' => ['cell' => 'B99']]);
        $rows = new Collection([$this->makeRow(2, ['B' => 10])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 10])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('source_cell_not_found', $result->reason);
    }

    public function test_missing_target_sheet_rows_is_skipped_with_target_cell_not_found(): void
    {
        // Hoja destino ausente por completo del upload -- _target_rows
        // queda vacio (mismo comportamiento que RuleEngineService::execute()
        // cuando $grouped->get($targetSheet) no encuentra nada).
        $config = $this->baseConfig();
        $rows = new Collection([$this->makeRow(2, ['B' => 10])]);
        $config['_target_rows'] = new Collection();

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('target_cell_not_found', $result->reason);
    }

    public function test_missing_target_cell_row_is_skipped(): void
    {
        $config = $this->baseConfig(['target' => ['sheet' => 'SHEETB', 'cell' => 'C99']]);
        $rows = new Collection([$this->makeRow(2, ['B' => 10])]);
        $config['_target_rows'] = new Collection([$this->makeRow(3, ['C' => 10])]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('target_cell_not_found', $result->reason);
    }

    // ============================================================
    // Rango vacio / invalido
    // ============================================================

    public function test_empty_range_is_skipped(): void
    {
        $config = $this->baseConfig([
            'source' => ['cell' => 'B3'],
            'target' => ['sheet' => 'SHEETB', 'range' => 'D100:D110', 'aggregation' => 'sum'],
        ]);
        $rows = new Collection([$this->makeRow(3, ['B' => 6])]);
        $config['_target_rows'] = new Collection([
            $this->makeRow(1, ['D' => 1]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('empty_range', $result->reason);
    }

    public function test_invalid_range_syntax_is_invalid_config(): void
    {
        $config = $this->baseConfig([
            'source' => ['cell' => 'B3'],
            'target' => ['sheet' => 'SHEETB', 'range' => 'D10:C5', 'aggregation' => 'sum'],
        ]);
        $rows = new Collection([$this->makeRow(3, ['B' => 6])]);
        $config['_target_rows'] = new Collection();

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_config', $result->reason);
    }

    public function test_invalid_config_missing_target_is_invalid_config(): void
    {
        $config = $this->baseConfig(['target' => ['sheet' => 'SHEETB']]);
        $rows = new Collection([$this->makeRow(2, ['B' => 10])]);
        $config['_target_rows'] = new Collection();

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_config', $result->reason);
    }
}
