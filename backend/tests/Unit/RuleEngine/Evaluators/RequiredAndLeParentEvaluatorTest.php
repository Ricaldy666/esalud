<?php

namespace Tests\Unit\RuleEngine\Evaluators;

use App\Domain\RuleEngine\Evaluators\RequiredAndLeParentEvaluator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class RequiredAndLeParentEvaluatorTest extends TestCase
{
    private RequiredAndLeParentEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RequiredAndLeParentEvaluator;
    }

    public function test_supports_required_and_le_parent(): void
    {
        $this->assertTrue($this->evaluator->supports('required_and_le_parent'));
    }

    public function test_does_not_support_other_types(): void
    {
        $this->assertFalse($this->evaluator->supports('sum_equals'));
        $this->assertFalse($this->evaluator->supports(''));
    }

    public function test_skips_when_parent_zero_or_null(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
        ];

        $rows = new Collection([
            (object) ['id' => 1, 'data' => ['values' => ['A' => 0, 'B' => ''], 'row_number' => 1]],
            (object) ['id' => 2, 'data' => ['values' => ['A' => null, 'B' => ''], 'row_number' => 2]],
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(2, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    public function test_fails_when_child_empty_and_parent_positive(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
        ];

        $rows = new Collection([
            (object) ['id' => 1, 'data' => ['values' => ['A' => 10, 'B' => ''], 'row_number' => 1]],
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertStringContainsString('B requerido', $result->details[0]['reason']);
    }

    public function test_fails_when_child_exceeds_parent(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
        ];

        $rows = new Collection([
            (object) ['id' => 1, 'data' => ['values' => ['A' => 10, 'B' => 15], 'row_number' => 1]],
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(1, $result->failedRows);
        $this->assertStringContainsString('15', $result->details[0]['reason']);
    }

    public function test_passes_when_child_valid(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
        ];

        $rows = new Collection([
            (object) ['id' => 1, 'data' => ['values' => ['A' => 10, 'B' => 5], 'row_number' => 1]],
            (object) ['id' => 2, 'data' => ['values' => ['A' => 10, 'B' => 10], 'row_number' => 2]],
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(2, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    public function test_handles_missing_parent_column(): void
    {
        $config = [
            'source_letters' => [],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
        ];

        $rows = new Collection([
            (object) ['id' => 1, 'data' => ['values' => ['A' => 10, 'B' => ''], 'row_number' => 1]],
        ]);

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }

    public function test_empty_rows_returns_zero_failures(): void
    {
        $config = [
            'source_letters' => ['A'],
            'target_column' => 'B',
            '_rule_key' => 'test_rule',
        ];

        $rows = new Collection;

        $result = $this->evaluator->evaluate($config, $rows);

        $this->assertSame(0, $result->totalRows);
        $this->assertSame(0, $result->failedRows);
    }
}
