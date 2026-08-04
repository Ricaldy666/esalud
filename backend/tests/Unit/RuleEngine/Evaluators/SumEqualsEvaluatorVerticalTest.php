<?php

namespace Tests\Unit\RuleEngine\Evaluators;

use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class SumEqualsEvaluatorVerticalTest extends TestCase
{
    private SumEqualsEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SumEqualsEvaluator;
    }

    // ============================================================
    // VERTICAL AGGREGATION — CORRECT (test 1)
    // ============================================================

    public function test_vertical_aggregation_passes(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'total_row' => 74,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

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
    // VERTICAL AGGREGATION — TOTAL INCORRECT (test 2)
    // ============================================================

    public function test_vertical_aggregation_fails_on_wrong_total(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'total_row' => 74,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 2]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 1]),
            $this->makeRow(74, ['I' => 9], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(5, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('vertical_sum_mismatch', $result->details[0]['reason']);
        $this->assertSame(10.0, $result->details[0]['calculated_sum']);
        $this->assertSame(9.0, $result->details[0]['declared_value']);
    }

    // ============================================================
    // VERTICAL AGGREGATION — COMPONENT ALTERED (test 3)
    // ============================================================

    public function test_vertical_aggregation_fails_on_altered_component(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'total_row' => 74,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 3]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 1]),
            $this->makeRow(74, ['I' => 10], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(5, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('vertical_sum_mismatch', $result->details[0]['reason']);
    }

    // ============================================================
    // VERTICAL AGGREGATION — EMPTY RANGE (test 4)
    // ============================================================

    public function test_vertical_aggregation_empty_rows_skips(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'total_row' => 74,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection;

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
        $this->assertSame('empty_range', $result->reason);
    }

    // ============================================================
    // VERTICAL AGGREGATION — EXPLICIT ZEROS (test 5)
    // ============================================================

    public function test_vertical_aggregation_explicit_zeros_passes(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 72,
            'total_row' => 73,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 0]),
            $this->makeRow(71, ['I' => 0]),
            $this->makeRow(72, ['I' => 0]),
            $this->makeRow(73, ['I' => 0], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // VERTICAL AGGREGATION — TEXT IN COMPONENT (test 6)
    // ============================================================

    public function test_vertical_aggregation_non_numeric_component_fails(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 72,
            'total_row' => 73,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 'TEXTO']),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 7], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('non_numeric_value_in_components', $result->reason);
        $this->assertSame('non_numeric_value', $result->details[0]['reason']);
        $this->assertSame(70, $result->details[0]['row_number']);
        $this->assertSame('I', $result->details[0]['column']);
    }

    // ============================================================
    // VERTICAL AGGREGATION — TEXT IN TOTAL (test 7)
    // ============================================================

    public function test_vertical_aggregation_non_numeric_total_fails(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 72,
            'total_row' => 73,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 2]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 'TEXTO'], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('non_numeric_total', $result->reason);
    }

    // ============================================================
    // VERTICAL AGGREGATION — MISSING TOTAL ROW (test 8)
    // ============================================================

    public function test_vertical_aggregation_missing_total_row_skips(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'total_row' => 99,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 2]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 1]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(4, $result->skippedRows);
        $this->assertSame('missing_total_row', $result->reason);
    }

    // ============================================================
    // VERTICAL AGGREGATION — NO TOTAL_ROW IN CONFIG (test 9)
    // ============================================================

    public function test_vertical_aggregation_no_total_row_config_skips(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);
        unset($config['total_row']);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 2]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(73, ['I' => 1]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(3, $result->skippedRows);
        $this->assertSame('invalid_row_range_configuration', $result->reason);
    }

    // ============================================================
    // VERTICAL AGGREGATION — TOTAL ROW NOT INCLUDED IN SUM (test 10)
    // ============================================================

    public function test_vertical_aggregation_total_row_not_included_in_sum(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 73,
            'total_row' => 74,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 2]),
            $this->makeRow(71, ['I' => 3]),
            $this->makeRow(72, ['I' => 4]),
            $this->makeRow(73, ['I' => 1]),
            $this->makeRow(74, ['I' => 20], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        // Sum should be 10 (2+3+4+1), NOT 110 (including total 100)
        $this->assertSame(5, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame(10.0, $result->details[0]['calculated_sum']);
        $this->assertSame(20.0, $result->details[0]['declared_value']);
    }

    // ============================================================
    // VERTICAL AGGREGATION — PER_ROW RULES STILL WORK (test 11)
    // ============================================================

    public function test_per_row_still_works_with_vertical_config(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_per_row',
            'scope' => 'per_row',
            'row_from' => 11,
            'row_to' => 11,
        ];

        $rows = new Collection([
            $this->makeRow(11, ['A' => 5, 'B' => 3, 'C' => 8]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // VERTICAL AGGREGATION — MULTIPLE COMPONENTS (test 12)
    // ============================================================

    public function test_vertical_aggregation_multiple_components(): void
    {
        $config = $this->makeConfig([
            'row_from' => 98,
            'row_to' => 110,
            'total_row' => 111,
            'source_letters' => ['L'],
            'target_column' => 'L',
        ]);

        $rows = new Collection;
        for ($i = 98; $i <= 110; $i++) {
            $rows->push($this->makeRow($i, ['L' => 1]));
        }
        $rows->push($this->makeRow(111, ['L' => 13], 'TOTAL'));

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(14, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
    }

    // ============================================================
    // VERTICAL AGGREGATION — DECIMAL VALUES
    // ============================================================

    public function test_vertical_aggregation_decimal_values(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 72,
            'total_row' => 73,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => 1.5]),
            $this->makeRow(71, ['I' => 2.3]),
            $this->makeRow(72, ['I' => 3.2]),
            $this->makeRow(73, ['I' => 7.0], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // VERTICAL AGGREGATION — EMPTY COMPONENTS + EMPTY TOTAL → SKIP
    // ============================================================

    public function test_vertical_aggregation_all_null_components_and_total(): void
    {
        $config = $this->makeConfig([
            'row_from' => 70,
            'row_to' => 72,
            'total_row' => 73,
            'source_letters' => ['I'],
            'target_column' => 'I',
        ]);

        $rows = new Collection([
            $this->makeRow(70, ['I' => null]),
            $this->makeRow(71, ['I' => null]),
            $this->makeRow(72, ['I' => null]),
            $this->makeRow(73, ['I' => null], 'TOTAL'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(4, $result->skippedRows);
        $this->assertSame('empty_row', $result->reason);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function makeConfig(array $overrides): array
    {
        return array_merge([
            '_rule_key' => 'test_vertical',
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
}
