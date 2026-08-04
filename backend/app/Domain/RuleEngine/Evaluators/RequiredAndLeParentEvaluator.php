<?php

namespace App\Domain\RuleEngine\Evaluators;

use Illuminate\Support\Collection;

class RequiredAndLeParentEvaluator implements RuleEvaluatorInterface
{
    public function supports(string $ruleType): bool
    {
        return $ruleType === 'required_and_le_parent';
    }

    public function evaluate(array $config, Collection $rows): RuleEvaluationResult
    {
        $sourceLetters = $config['source_letters'] ?? [];
        $parent = $sourceLetters[0] ?? null;
        $child = $config['target_column'] ?? '';
        $ruleKey = $config['_rule_key'] ?? '?';
        $cellMetadata = $config['_cell_metadata'] ?? [];

        if ($parent === null) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 0,
                details: [],
            );
        }

        $failed = [];
        $skippedBecauseBlocked = 0;

        $isCellBlocked = function (?array $cellInfo): bool {
            return $cellInfo !== null
                && (
                    ($cellInfo['esta_bloqueada'] ?? false) === true
                    || ($cellInfo['es_editable'] ?? true) === false
                );
        };

        foreach ($rows as $rd) {
            $vals = $rd->data['values'] ?? [];
            $rowNumber = $rd->data['row_number'] ?? null;

            $parentVal = $vals[$parent] ?? null;
            $childVal = $vals[$child] ?? null;

            $childCellKey = $child . $rowNumber;
            $childCellInfo = $cellMetadata[$childCellKey] ?? null;

            if ($isCellBlocked($childCellInfo)) {
                $skippedBecauseBlocked++;
                continue;
            }

            $parentCellKey = $parent . $rowNumber;
            $parentCellInfo = $cellMetadata[$parentCellKey] ?? null;

            if ($isCellBlocked($parentCellInfo)) {
                $skippedBecauseBlocked++;
                continue;
            }

            if ($parentVal !== null && $parentVal !== '' && !is_numeric($parentVal)) {
                $failed[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'reason' => 'valor_no_numerico',
                    'column' => $parent,
                    'value' => $parentVal,
                    'message' => "Columna {$parent} contiene valor no numerico: '{$parentVal}'",
                ];
                continue;
            }

            if ($childVal !== null && $childVal !== '' && !is_numeric($childVal)) {
                $failed[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'reason' => 'valor_no_numerico',
                    'column' => $child,
                    'value' => $childVal,
                    'message' => "Columna {$child} contiene valor no numerico: '{$childVal}'",
                ];
                continue;
            }

            if ($parentVal === null || $parentVal === '' || (int) $parentVal <= 0) {
                continue;
            }

            if ($childVal === null || $childVal === '') {
                $failed[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'reason' => "{$child} requerido porque {$parent} > 0, pero esta vacio",
                ];
                continue;
            }

            if ((int) $childVal > (int) $parentVal) {
                $failed[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'reason' => "{$child} ({$childVal}) > {$parent} ({$parentVal})",
                ];
            }
        }

        return new RuleEvaluationResult(
            ruleKey: $ruleKey,
            totalRows: $rows->count(),
            failedRows: count($failed),
            details: $failed,
            skippedRows: $skippedBecauseBlocked,
        );
    }
}
