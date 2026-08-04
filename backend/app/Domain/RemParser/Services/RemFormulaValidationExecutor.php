<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ValidationRuleDTO;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemValidationResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RemFormulaValidationExecutor
{
    public function execute(int $uploadId, array $rules, bool $dryRun = false): array
    {
        $remData = RemData::where('rem_upload_id', $uploadId)->get();
        $grouped = $remData->groupBy('section');

        $results = [];

        if ($dryRun) {
            foreach ($rules as $rule) {
                $result = $this->executeRule($rule, $grouped);
                $results[] = $result;
            }

            return $results;
        }

        DB::transaction(function () use ($uploadId, $rules, $grouped, &$results) {
            $builtRuleKeys = array_map(fn(ValidationRuleDTO $r) => $r->ruleKey, $rules);

            RemValidationResult::where('rem_upload_id', $uploadId)
                ->whereIn('rule_key', $builtRuleKeys)
                ->delete();

            foreach ($rules as $rule) {
                $result = $this->executeRule($rule, $grouped);
                $results[] = $result;

                if ($result['total_rows'] === 0) {
                    continue;
                }

                RemValidationResult::create([
                    'rem_upload_id' => $uploadId,
                    'rule_key' => $rule->ruleKey,
                    'rule_type' => $rule->ruleType,
                    'severity' => $rule->severity,
                    'passed' => $result['failed_rows'] === 0,
                    'message' => $this->buildMessage($rule, $result),
                    'context' => [
                        'sheet' => $rule->sheet,
                        'target_column' => $rule->targetColumn,
                        'source_columns' => $rule->sourceColumns,
                        'scope' => $rule->scope,
                        'row_from' => $rule->rowFrom,
                        'row_to' => $rule->rowTo,
                        'total_rows' => $result['total_rows'],
                        'passed_rows' => $result['total_rows'] - $result['failed_rows'],
                        'failed_rows' => $result['failed_rows'],
                        'details' => $result['details'],
                    ],
                ]);
            }
        });

        return $results;
    }

    private function executeRule(ValidationRuleDTO $rule, Collection $grouped): array
    {
        $rows = $grouped->get($rule->sheet, collect());

        if ($rows->isEmpty()) {
            return [
                'rule_key' => $rule->ruleKey,
                'status' => 'skipped',
                'total_rows' => 0,
                'failed_rows' => 0,
                'details' => [],
            ];
        }

        $filtered = $rows;

        if ($rule->scope === 'row_range' || $rule->rowFrom !== null) {
            $filtered = $rows->filter(fn(RemData $rd) => (
                $rd->data['row_number'] >= $rule->rowFrom
                && $rd->data['row_number'] <= $rule->rowTo
            ));
        }

        if ($filtered->isEmpty()) {
            return [
                'rule_key' => $rule->ruleKey,
                'status' => 'skipped',
                'total_rows' => 0,
                'failed_rows' => 0,
                'details' => [],
            ];
        }

        $failed = [];
        $total = $filtered->count();

        foreach ($filtered as $rd) {
            $vals = $rd->data['values'] ?? [];

            switch ($rule->ruleType) {
                case 'sum_equals':
                    $result = $this->checkSumEquals($rule, $vals, $rd);
                    break;
                case 'required_and_le_parent':
                    $result = $this->checkRequiredAndLeParent($rule, $vals, $rd);
                    break;
                default:
                    $result = ['passed' => true];
            }

            if (!$result['passed']) {
                $failed[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rd->data['row_number'] ?? null,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'reason' => $result['reason'] ?? '',
                ];
            }
        }

        return [
            'rule_key' => $rule->ruleKey,
            'status' => \count($failed) === 0 ? 'passed' : 'failed',
            'total_rows' => $total,
            'failed_rows' => \count($failed),
            'details' => $failed,
        ];
    }

    private function checkSumEquals(ValidationRuleDTO $rule, array $vals, RemData $rd): array
    {
        $sum = 0;
        $usedCols = [];

        foreach ($rule->sourceColumns as $col) {
            $v = $vals[$col] ?? null;
            if ($v !== null && $v !== '') {
                $sum += (int) $v;
                $usedCols[] = $col;
            }
        }

        $declared = $vals[$rule->targetColumn] ?? null;
        $declaredInt = ($declared !== null && $declared !== '') ? (int) $declared : 0;

        $passed = $sum === $declaredInt;

        return [
            'passed' => $passed,
            'reason' => $passed ? '' : "Suma {$sum} !== valor declarado {$declaredInt} (col{$rule->targetColumn})",
        ];
    }

    private function checkRequiredAndLeParent(ValidationRuleDTO $rule, array $vals, RemData $rd): array
    {
        $parent = $rule->sourceColumns[0] ?? null;
        $child = $rule->targetColumn;

        if ($parent === null) {
            return ['passed' => true];
        }

        $parentVal = $vals[$parent] ?? null;
        $childVal = $vals[$child] ?? null;

        if ($parentVal === null || (int) $parentVal <= 0) {
            return ['passed' => true];
        }

        if ($childVal === null || $childVal === '') {
            return [
                'passed' => false,
                'reason' => "{$child} requerido porque {$parent} > 0, pero esta vacio",
            ];
        }

        if ((int) $childVal > (int) $parentVal) {
            return [
                'passed' => false,
                'reason' => "{$child} ({$childVal}) > {$parent} ({$parentVal})",
            ];
        }

        return ['passed' => true];
    }

    private function buildMessage(ValidationRuleDTO $rule, array $result): string
    {
        $total = $result['total_rows'];
        $failed = $result['failed_rows'];

        if ($total === 0) {
            return "Sin datos para {$rule->sheet}/{$rule->targetColumn}";
        }

        if ($failed === 0) {
            return "{$total}/{$total} filas pasaron";
        }

        return "{$failed}/{$total} filas fallaron en {$rule->sheet}/{$rule->targetColumn}";
    }
}
