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
            if ($rule['type'] === 'cross_sheet') {
                $result = $this->evaluateCrossSheet($rule, $upload);
                if ($result) {
                    $results->push($result);
                }
                continue;
            }

            if ($rule['type'] === 'row_range_sum') {
                $sectionRows = $rowsBySection->get($rule['section'], collect());
                $result = $this->evaluateRowRangeSum($rule, $sectionRows, $upload);
                if ($result) {
                    $results->push($result);
                }
                continue;
            }

            $rows = $rowsBySection->get($rule['section'], collect());

            if (isset($rule['row_range'])) {
                $rows = $rows->filter(function ($row) use ($rule) {
                    $rowNum = $row->data['row_number'] ?? null;
                    return $rowNum !== null
                        && $rowNum >= $rule['row_range']['from']
                        && $rowNum <= $rule['row_range']['to'];
                });
            }

            foreach ($rows as $row) {
                $result = $this->evaluateRule($rule, $row->data, $upload, $row->id);
                if ($result) {
                    $results->push($result);
                }
            }
        }

        if ($results->isNotEmpty()) {
            collect($results)->chunk(500)->each(
                fn($batch) => RemValidationResult::insert($batch->toArray())
            );
        }

        return $results;
    }

    private function evaluateRule(array $rule, array $rowData, RemUpload $upload, int $remDataId): ?array
    {
        return match ($rule['type']) {
            'sum_equals' => $this->evaluateSumEquals($rule, $rowData, $upload, $remDataId),
            'max_le_parent' => $this->evaluateMaxLeParent($rule, $rowData, $upload, $remDataId),
            'sum_le_parent' => $this->evaluateSumLeParent($rule, $rowData, $upload, $remDataId),
            'required_and_le_parent' => $this->evaluateRequiredAndLeParent($rule, $rowData, $upload, $remDataId),
            default => null,
        };
    }

    private function evaluateCrossSheet(array $rule, RemUpload $upload): ?array
    {
        $sourceRowNumbers = $rule['source']['row_numbers'] ?? [$rule['source']['row_number'] ?? null];

        if (empty($sourceRowNumbers) || $sourceRowNumbers[0] === null) {
            return null;
        }

        $sourceRows = $upload->remData->filter(
            fn($d) => $d->section === $rule['source']['section']
                && in_array($d->data['row_number'] ?? null, $sourceRowNumbers)
        );

        if ($sourceRows->isEmpty()) {
            return null;
        }

        $sourceSum = 0;
        foreach ($sourceRows as $sourceRow) {
            $sourceValues = $sourceRow->data['values'] ?? [];
            foreach ($rule['source']['columns'] as $col) {
                $sourceSum += (float) ($sourceValues[$col] ?? 0);
            }
        }

        $hasSourceData = collect($sourceRows->first()->data['values'] ?? [])
            ->filter(fn($v) => $v !== null)->isNotEmpty();
        if (!$hasSourceData) {
            return null;
        }

        $targetRowNumbers = $rule['target']['row_numbers'] ?? [$rule['target']['row_number'] ?? null];

        $targetRows = $upload->remData->filter(
            fn($d) => $d->section === $rule['target']['section']
                && in_array($d->data['row_number'] ?? null, $targetRowNumbers)
        );

        $targetSum = 0;
        foreach ($targetRows as $targetRow) {
            $targetValues = $targetRow->data['values'] ?? [];
            foreach ($rule['target']['columns'] as $col) {
                $targetSum += (float) ($targetValues[$col] ?? 0);
            }
        }

        $passed = match ($rule['operator'] ?? 'equals') {
            'equals' => $sourceSum == $targetSum,
            'lte' => $sourceSum <= $targetSum,
            'gte' => $sourceSum >= $targetSum,
            default => false,
        };

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => $rule['key'],
            'rule_type' => 'cross_sheet',
            'severity' => $rule['severity'] ?? 'error',
            'passed' => $passed,
            'message' => $passed ? null : "[RCC] {$rule['description']}: origen {$sourceSum} vs destino {$targetSum}",
            'context' => json_encode([
                'description' => $rule['description'],
                'source_section' => $rule['source']['section'],
                'source_rows' => $sourceRowNumbers,
                'source_sum' => $sourceSum,
                'target_section' => $rule['target']['section'],
                'target_rows' => $targetRowNumbers,
                'target_sum' => $targetSum,
                'operator' => $rule['operator'] ?? 'equals',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Suma de una columna a traves de un rango de filas debe coincidir con el subtotal
     * declarado en la fila objetivo.
     *
     * Ejemplo: fila 111 = suma de filas 98-110 en columna C.
     */
    private function evaluateRowRangeSum(array $rule, Collection $sectionRows, RemUpload $upload): ?array
    {
        // 1. Buscar la fila objetivo (target_row)
        $targetRow = $sectionRows->first(
            fn($row) => ($row->data['row_number'] ?? null) === $rule['target_row']
        );

        if (!$targetRow) {
            return null;
        }

        $column = $rule['column'];
        $from = $rule['source_row_range']['from'];
        $to = $rule['source_row_range']['to'];

        // 2. Sumar la columna en el rango de filas origen
        $sourceRows = $sectionRows->filter(function ($row) use ($from, $to) {
            $rowNum = $row->data['row_number'] ?? null;
            return $rowNum !== null && $rowNum >= $from && $rowNum <= $to;
        });

        if ($sourceRows->isEmpty()) {
            return null;
        }

        $computedSum = 0;
        foreach ($sourceRows as $sourceRow) {
            $values = $sourceRow->data['values'] ?? [];
            $computedSum += (float) ($values[$column] ?? 0);
        }

        // 3. Obtener el valor declarado en la fila target
        $targetValues = $targetRow->data['values'] ?? [];
        $declaredValue = (float) ($targetValues[$column] ?? $targetRow->data['total'] ?? 0);

        // 4. Comparar
        $passed = $computedSum == $declaredValue;

        // 5. Construir resultado
        $sourceRowNumbers = $sourceRows->pluck('data.row_number')->sort()->values()->toArray();

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => $rule['key'],
            'rule_type' => 'row_range_sum',
            'severity' => $rule['severity'] ?? 'error',
            'passed' => $passed,
            'message' => $passed ? null : "[{$rule['section']}] Suma de filas {$from}-{$to} en columna {$column} ({$computedSum}) no coincide con subtotal declarado en fila {$rule['target_row']} ({$declaredValue})",
            'context' => json_encode([
                'section' => $rule['section'],
                'column' => $column,
                'source_row_range' => ['from' => $from, 'to' => $to],
                'source_rows_found' => $sourceRowNumbers,
                'target_row' => $rule['target_row'],
                'computed_sum' => $computedSum,
                'declared_value' => $declaredValue,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
        // Si target_field es literalmente 'total', usar rowData['total'];
        // si es una columna específica, usar solo $values (sin fallback a rowData['total'])
        $target = $targetField === 'total'
            ? (float) ($rowData['total'] ?? 0)
            : (float) ($values[$targetField] ?? 0);
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
        $parentCol = $rule['parent_column'];
        $parent = $parentCol === 'total'
            ? (float) ($rowData['total'] ?? 0)
            : (float) ($values[$parentCol] ?? 0);
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

    /**
     * Si parent > 0 y la columna hija esta vacia -> warning.
     * Si parent > 0 y la columna hija tiene valor -> debe ser <= parent (error si no).
     * Si parent = 0 -> no se evalua (la fila no aplica).
     */
    private function evaluateRequiredAndLeParent(array $rule, array $rowData, RemUpload $upload, int $remDataId): ?array
    {
        $values = $rowData['values'] ?? [];

        // Si la fila no tiene datos cargados, no evaluamos
        $hasData = collect($values)->filter(fn ($v) => $v !== null)->isNotEmpty();
        if (!$hasData) {
            return null;
        }

        $parentCol = $rule['parent_column'];
        $parent = $parentCol === 'total'
            ? (float) ($rowData['total'] ?? 0)
            : (float) ($values[$parentCol] ?? 0);
        $childRaw = $values[$rule['child_column']] ?? null;

        // Si el padre es 0, la regla no aplica
        if ($parent <= 0) {
            return null;
        }

        $label = ($rowData['concept'] ?? '?') . ' / ' . ($rowData['professional'] ?? '?');

        // Caso 1: parent > 0 pero child esta vacio -> warning
        if ($childRaw === null || $childRaw === '') {
            return [
                'rem_upload_id' => $upload->id,
                'rule_key' => $rule['key'],
                'rule_type' => 'required_and_le_parent',
                'severity' => 'warning',
                'passed' => false,
                'message' => "[{$rule['section']}] {$label}: Campo {$rule['child_column']} requerido: no puede estar vacio si el total ({$parent}) es mayor a 0.",
                'context' => json_encode([
                    'section' => $rule['section'],
                    'rem_data_id' => $remDataId,
                    'concept' => $rowData['concept'] ?? null,
                    'professional' => $rowData['professional'] ?? null,
                    'child_column' => $rule['child_column'],
                    'parent_column' => $rule['parent_column'],
                    'child_value' => null,
                    'parent_value' => $parent,
                    'reason' => 'missing_child',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Caso 2: child tiene valor -> verificar que no supere al padre
        $child = (float) $childRaw;
        $passed = $child <= $parent;

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => $rule['key'],
            'rule_type' => 'required_and_le_parent',
            'severity' => $rule['severity'] ?? 'error',
            'passed' => $passed,
            'message' => $passed ? null : "[{$rule['section']}] {$label}: La variable {$rule['child_column']} ({$child}) no puede ser mayor al total ({$parent}).",
            'context' => json_encode([
                'section' => $rule['section'],
                'rem_data_id' => $remDataId,
                'concept' => $rowData['concept'] ?? null,
                'professional' => $rowData['professional'] ?? null,
                'child_column' => $rule['child_column'],
                'parent_column' => $rule['parent_column'],
                'child_value' => $child,
                'parent_value' => $parent,
                'reason' => $passed ? 'ok' : 'exceeds_parent',
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
