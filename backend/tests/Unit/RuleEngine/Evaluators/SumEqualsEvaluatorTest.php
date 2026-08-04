<?php

namespace Tests\Unit\RuleEngine\Evaluators;

use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class SumEqualsEvaluatorTest extends TestCase
{
    private SumEqualsEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SumEqualsEvaluator;
    }

    // ============================================================
    // SUPPORT
    // ============================================================

    public function test_supports_sum_equals(): void
    {
        $this->assertTrue($this->evaluator->supports('sum_equals'));
    }

    public function test_does_not_support_other_types(): void
    {
        $this->assertFalse($this->evaluator->supports('required_and_le_parent'));
        $this->assertFalse($this->evaluator->supports('max_le_parent'));
        $this->assertFalse($this->evaluator->supports(''));
    }

    // ============================================================
    // PER ROW — SUCCESS
    // ============================================================

    public function test_passes_when_sum_matches_declared(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10, 'B' => 5, 'C' => 15]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
        $this->assertSame('test_rule', $result->ruleKey);
    }

    // ============================================================
    // PER ROW — SUM MISMATCH (test 2: total incorrecto)
    // ============================================================

    public function test_fails_when_sum_differs(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10, 'B' => 5, 'C' => 20]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('sum_mismatch', $result->details[0]['reason']);
    }

    // ============================================================
    // PER ROW — COMPONENT ALTERED (test 3)
    // ============================================================

    public function test_fails_when_component_differs(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 11, 'B' => 5, 'C' => 15]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
    }

    // ============================================================
    // PER ROW — EMPTY ROW → SKIPPED (test 4)
    // ============================================================

    public function test_empty_row_is_skipped(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => null, 'B' => null, 'C' => null]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('empty_row', $result->details[0]['reason']);
    }

    // ============================================================
    // PER ROW — EMPTY ROW WITH EMPTY STRINGS → SKIPPED
    // ============================================================

    public function test_empty_row_with_empty_strings_is_skipped(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => '', 'B' => '', 'C' => '']),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(1, $result->skippedRows);
    }

    // ============================================================
    // PER ROW — EXPLICIT ZERO → PASS (test 5)
    // ============================================================

    public function test_explicit_zeros_pass(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 0, 'B' => 0, 'C' => 0]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(0, $result->skippedRows);
    }

    // ============================================================
    // PER ROW — MIXED EMPTY AND ZERO → PASS (zeros have data)
    // ============================================================

    public function test_empty_and_zero_combined_passes(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => null, 'B' => 0, 'C' => 0]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    public function test_missing_target_column_is_not_interpreted_as_zero(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10, 'B' => 5]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('missing_target_value', $result->details[0]['reason']);
        $this->assertSame('C', $result->details[0]['target_column']);
    }

    // ============================================================
    // PER ROW — NON-NUMERIC IN SOURCE → FAIL (test 6)
    // ============================================================

    public function test_non_numeric_in_source_fails(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 'TEXTO_NO_NUMERICO', 'B' => 5, 'C' => 5]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('non_numeric_value', $result->details[0]['reason']);
        $this->assertSame('A', $result->details[0]['column']);
    }

    // ============================================================
    // PER ROW — NON-NUMERIC IN TARGET → FAIL (test 7)
    // ============================================================

    public function test_non_numeric_in_target_fails(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10, 'B' => 5, 'C' => 'TEXTO']),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertSame('non_numeric_value', $result->details[0]['reason']);
        $this->assertSame('C', $result->details[0]['column']);
    }

    // ============================================================
    // PER ROW — NUMERIC STRING → PASS (test 8)
    // ============================================================

    public function test_numeric_string_passes(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => '12', 'B' => 12]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // PER ROW — DECIMAL VALUES (test 9)
    // ============================================================

    public function test_decimal_values_pass(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10.5, 'B' => 5.3, 'C' => 15.8]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // PER ROW — DECIMAL ROUNDING TOLERANCE (test 9 continued)
    // ============================================================

    public function test_decimal_rounding_within_tolerance(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 0.1, 'B' => 0.2, 'C' => 0.3]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // PER ROW — NEGATIVE VALUES (test 10)
    // ============================================================

    public function test_negative_values(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => -5, 'B' => 3, 'C' => -2]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    // ============================================================
    // ROW RANGE — VERTICAL AGGREGATION SKIP (test 11-15)
    // ============================================================

    public function test_row_range_vertical_aggregation_without_total_row_skips(): void
    {
        $config = [
            'source_letters' => ['I'],
            'target_column' => 'I',
            '_rule_key' => 'a01_d_i_sum_equals',
            'scope' => 'row_range',
            'row_from' => 70,
            'row_to' => 73,
        ];

        $rows = new Collection([
            $this->makeRow(70, ['I' => 10], 'En Espacio Amigable'),
            $this->makeRow(71, ['I' => 12], 'En otros espacios'),
            $this->makeRow(72, ['I' => 0], 'Establecimientos Educacionales'),
            $this->makeRow(73, ['I' => 1], 'En otros lugares'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(4, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame(4, $result->skippedRows);
        $this->assertSame('invalid_row_range_configuration', $result->reason);
    }

    // ============================================================
    // ROW RANGE — SINGLE ROW WITH PER_ROW SCOPE → PER_ROW (not aggregation)
    // ============================================================

    public function test_row_range_single_row_treated_as_per_row(): void
    {
        $config = [
            'source_letters' => ['A', 'B'],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
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
    // PER ROW — MULTIPLE ROWS, SOME FAIL (backward compat)
    // ============================================================

    public function test_mulitple_rows_some_fail(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 5, 'B' => 5]),
            $this->makeRow(2, ['A' => 3, 'B' => 3]),
            $this->makeRow(3, ['A' => 7, 'B' => 10]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
    }

    // ============================================================
    // PER ROW — USES RULE KEY FROM CONFIG
    // ============================================================

    public function test_uses_rule_key_from_config(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'hoja1_seccion1_a_sum_equals',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 1, 'B' => 1]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('hoja1_seccion1_a_sum_equals', $result->ruleKey);
    }

    // ============================================================
    // PER ROW — MISSING SOURCE LETTERS → INVALID CONFIG
    // ============================================================

    public function test_empty_source_letters_returns_invalid_config(): void
    {
        $config = [
            'source_letters' => [],
            'target_column' => 'C',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10, 'C' => 0]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->skippedRows);
        $this->assertSame('invalid_config', $result->reason);
    }

    // ============================================================
    // PER ROW — MISSING TARGET COLUMN → INVALID CONFIG
    // ============================================================

    public function test_empty_target_column_returns_invalid_config(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => '',
            '_rule_key' => 'test_rule',
            'scope' => 'per_row',
        ];

        $rows = new Collection([
            $this->makeRow(1, ['A' => 10, 'B' => 10]),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame('invalid_config', $result->reason);
    }

    public function test_debe_registrar_cero_does_not_fail_rows_with_positive_total_and_blocked_empty_source(): void
    {
        $config = [
            'source_letters' => ['U', 'V'],
            'target_column' => 'C',
            '_rule_key' => 'a01_uv_11_sum_equals',
            'scope' => 'per_row',
            '_cell_metadata' => [
                'U18' => ['esta_bloqueada' => true, 'es_editable' => false],
                'U20' => ['esta_bloqueada' => true, 'es_editable' => false],
                'U22' => ['esta_bloqueada' => true, 'es_editable' => false],
                'V18' => ['esta_bloqueada' => false, 'es_editable' => true],
                'V20' => ['esta_bloqueada' => false, 'es_editable' => true],
                'V22' => ['esta_bloqueada' => false, 'es_editable' => true],
                'C18' => ['esta_bloqueada' => true, 'es_editable' => false],
                'C20' => ['esta_bloqueada' => true, 'es_editable' => false],
                'C22' => ['esta_bloqueada' => true, 'es_editable' => false],
            ],
            '_functional_rules' => [
                18 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
                20 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
                22 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ],
        ];

        $rows = new Collection([
            $this->makeRow(18, ['C' => 1, 'U' => null, 'V' => 1], 'Post Aborto'),
            $this->makeRow(20, ['C' => 19, 'U' => null, 'V' => 19], 'Puerpera hasta 10 dias'),
            $this->makeRow(22, ['C' => 1, 'U' => null, 'V' => 1], 'Puerpera entre 11 y 28 dias'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
        $this->assertSame([], array_filter(
            $result->details,
            fn($detail) => ($detail['reason'] ?? null) === 'empty_not_allowed_by_functional_rule'
        ));
    }

    public function test_debe_registrar_cero_groups_missing_editable_cells_in_one_functional_warning(): void
    {
        $config = [
            'source_letters' => ['F', 'G', 'H'],
            'target_column' => 'C',
            '_rule_key' => 'a01_fgh_31_sum_equals',
            'scope' => 'per_row',
            '_cell_metadata' => [
                'F30' => ['valor_bruto' => '10 - 14 anos'],
                'G30' => ['valor_bruto' => '15 - 19 anos'],
                'H30' => ['valor_bruto' => '20 - 24 anos'],
                'F31' => ['esta_bloqueada' => false, 'es_editable' => true, 'color_fondo' => ['nombre_inferido' => 'crema']],
                'G31' => ['esta_bloqueada' => false, 'es_editable' => true, 'color_fondo' => ['nombre_inferido' => 'crema']],
                'H31' => ['esta_bloqueada' => true, 'es_editable' => false],
                'C31' => ['esta_bloqueada' => true, 'es_editable' => false],
            ],
            '_functional_rules' => [
                31 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ],
        ];

        $rows = new Collection([
            $this->makeRow(31, ['C' => 0, 'F' => null, 'G' => '', 'H' => null], 'Regulacion de Fecundidad'),
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->failedRows);
        $this->assertCount(1, $result->details);
        $this->assertSame('empty_not_allowed_by_functional_rule', $result->details[0]['reason']);
        $this->assertSame(2, $result->details[0]['pending_cells_count']);
        $this->assertSame(['F31', 'G31'], array_column($result->details[0]['pending_cells'], 'coordinate'));
        $this->assertSame(['10 - 14 anos', '15 - 19 anos'], array_column($result->details[0]['pending_cells'], 'label'));
        $this->assertSame(0, $result->details[0]['row_total']);
    }

    // ============================================================
    // HELPER
    // ============================================================

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
}
