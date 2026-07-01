<?php

namespace App\Domain\REM\Services;

use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use Illuminate\Support\Collection;

/**
 * Motor de Validacion Cruzada (RF-02).
 *
 * Las secciones REM tienen estructura multi-fila:
 * { concept, professional, total, values: { D: x, E: y, ... } }
 *
 * Las reglas se aplican POR FILA dentro de la seccion indicada.
 */
class RemValidationService
{
    public function validate(RemUpload $upload): Collection
    {
        $upload->validationResults()->delete();

        $rules = $upload->remTemplate->config['validation_rules'] ?? [];

        if (empty($rules)) {
            return collect();
        }

        // Agrupamos rem_data por seccion, cada seccion puede tener N filas
        $rowsBySection = $upload->remData->groupBy('section');

        $results = collect();

        foreach ($rules as $rule) {
            $rows = $rowsBySection->get($rule['section'], collect());

            foreach ($rows as $row) {
                $result = $this->evaluateRule($rule, $row->data, $upload, $row->id);
                if ($result) {
                    $results->push($result);
                }
            }
        }

        if ($results->isNotEmpty()) {
            RemValidationResult::insert($results->toArray());
        }

        return $results;
    }

    private function evaluateRule(array $rule, array $rowData, RemUpload $upload, int $remDataId): ?array
    {
        return match ($rule['type']) {
            'sum_equals' => $this->evaluateSumEquals($rule, $rowData, $upload, $remDataId),
            'max_le_parent' => $this->evaluateMaxLeParent($rule, $rowData, $upload, $remDataId),
            'sum_le_parent' => $this->evaluateSumLeParent($rule, $rowData, $upload, $remDataId),
            default => null,
        };
    }

    /**
     * Suma de columnas en values[] debe igualar el campo 'total' (o target indicado) de la fila.
     */
    private function evaluateSumEquals(array $rule, array $rowData, RemUpload $upload, int $remDataId): ?array
    {
        $values = $rowData['values'] ?? [];

        // Si la fila no tiene datos cargados (ej. archivo de prueba vacio), no evaluamos
        $hasData = collect($values)->filter(fn ($v) => $v !== null)->isNotEmpty();
        if (!$hasData) {
            return null;
        }

        $sum = 0;
        foreach ($rule['source_columns'] as $col) {
            $sum += (float) ($values[$col] ?? 0);
        }

        $targetField = $rule['target_field'] ?? 'total';
        $target = (float) ($rowData[$targetField] ?? $values[$targetField] ?? 0);
        $passed = $sum === $target;

        $label = ($rowData['concept'] ?? '?') . ' / ' . ($rowData['professional'] ?? '?');

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => $rule['key'],
            'rule_type' => 'sum_equals',
            'severity' => $rule['severity'] ?? 'error',
            'passed' => $passed,
            'message' => $passed ? null : "[{$rule['section']}] {$label}: suma {$sum} no coincide con total {$target}",
            'context' => json_encode([
                'section' => $rule['section'],
                'rem_data_id' => $remDataId,
                'concept' => $rowData['concept'] ?? null,
                'professional' => $rowData['professional'] ?? null,
                'source_columns' => $rule['source_columns'],
                'computed_sum' => $sum,
                'declared_total' => $target,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Columna hija no puede superar a columna padre, dentro de la misma fila.
     */
    private function evaluateMaxLeParent(array $rule, array $rowData, RemUpload $upload, int $remDataId): ?array
    {
        $values = $rowData['values'] ?? [];

        $hasData = collect($values)->filter(fn ($v) => $v !== null)->isNotEmpty();
        if (!$hasData) {
            return null;
        }

        $child = (float) ($values[$rule['child_column']] ?? 0);
        $parent = (float) ($values[$rule['parent_column']] ?? $rowData['total'] ?? 0);
        $passed = $child <= $parent;

        $label = ($rowData['concept'] ?? '?') . ' / ' . ($rowData['professional'] ?? '?');

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => $rule['key'],
            'rule_type' => 'max_le_parent',
            'severity' => $rule['severity'] ?? 'error',
            'passed' => $passed,
            'message' => $passed ? null : "[{$rule['section']}] {$label}: columna {$rule['child_column']} ({$child}) supera a {$rule['parent_column']} ({$parent})",
            'context' => json_encode([
                'section' => $rule['section'],
                'rem_data_id' => $remDataId,
                'concept' => $rowData['concept'] ?? null,
                'professional' => $rowData['professional'] ?? null,
                'child_column' => $rule['child_column'],
                'parent_column' => $rule['parent_column'],
                'child_value' => $child,
                'parent_value' => $parent,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function evaluateSumLeParent(array $rule, array $rowData, RemUpload $upload, int $remDataId): ?array
    {
        $values = $rowData['values'] ?? [];

        $hasData = collect($values)->filter(fn ($v) => $v !== null)->isNotEmpty();
        if (!$hasData) {
            return null;
        }

        $sum = 0;
        foreach ($rule['source_columns'] as $col) {
            $sum += (float) ($values[$col] ?? 0);
        }

        $parent = (float) ($values[$rule['parent_column']] ?? $rowData[$rule['parent_column']] ?? 0);
        $passed = $sum <= $parent;

        $label = ($rowData['concept'] ?? '?') . ' / ' . ($rowData['professional'] ?? '?');

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => $rule['key'],
            'rule_type' => 'sum_le_parent',
            'severity' => $rule['severity'] ?? 'error',
            'passed' => $passed,
            'message' => $passed ? null : "[{$rule['section']}] {$label}: suma " . implode('+', $rule['source_columns']) . " ({$sum}) supera total ({$parent})",
            'context' => json_encode([
                'section' => $rule['section'],
                'rem_data_id' => $remDataId,
                'concept' => $rowData['concept'] ?? null,
                'source_columns' => $rule['source_columns'],
                'computed_sum' => $sum,
                'parent_value' => $parent,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
