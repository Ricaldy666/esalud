<?php

namespace App\Domain\RuleEngine\Evaluators;

use Illuminate\Support\Collection;

class SumEqualsEvaluator implements RuleEvaluatorInterface
{
    private const FLOAT_EPSILON = 0.00001;

    public function supports(string $ruleType): bool
    {
        return $ruleType === 'sum_equals';
    }

    public function evaluate(array $config, Collection $rows): RuleEvaluationResult
    {
        $sourceLetters = $config['source_letters'] ?? [];
        $targetColumn = $config['target_column'] ?? '';
        $ruleKey = $config['_rule_key'] ?? '?';
        $scope = $config['scope'] ?? 'per_row';
        $functionalRules = $config['_functional_rules'] ?? [];

        if (empty($sourceLetters) || empty($targetColumn)) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 0,
                details: [[
                    'reason' => 'invalid_config',
                    'message' => 'source_letters or target_column is empty',
                ]],
                skippedRows: $rows->count(),
                reason: 'invalid_config',
            );
        }

        if ($scope === 'row_range' && $this->isVerticalAggregationPattern($sourceLetters, $targetColumn)) {
            return $this->evaluateVerticalAggregation($config, $rows, $ruleKey, $sourceLetters[0], $targetColumn);
        }

        return $this->evaluatePerRow(
            $sourceLetters,
            $targetColumn,
            $rows,
            $ruleKey,
            $functionalRules,
            $config['_cell_metadata'] ?? [],
        );
    }

    private function isVerticalAggregationPattern(array $sourceLetters, string $targetColumn): bool
    {
        return count($sourceLetters) === 1 && $sourceLetters[0] === $targetColumn;
    }

    private function evaluateVerticalAggregation(
        array $config,
        Collection $rows,
        string $ruleKey,
        string $column,
        string $targetColumn,
    ): RuleEvaluationResult {
        $rowFrom = $config['row_from'] ?? '?';
        $rowTo = $config['row_to'] ?? '?';
        $totalRowNumber = $config['total_row'] ?? null;

        if ($totalRowNumber === null) {
            $detail = [
                'reason' => 'invalid_row_range_configuration',
                'rule_key' => $ruleKey,
                'source_letters' => [$column],
                'target_column' => $targetColumn,
                'row_from' => $rowFrom,
                'row_to' => $rowTo,
                'rows_in_range' => $rows->count(),
                'message' => "Campo 'total_row' no definido en config. "
                    . "El rango [{$rowFrom}:{$rowTo}] contiene solo filas componentes; "
                    . "se necesita 'total_row' para identificar la fila total.",
            ];
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 0,
                details: [$detail],
                skippedRows: $rows->count(),
                reason: 'invalid_row_range_configuration',
            );
        }

        $totalRowNumber = (int) $totalRowNumber;

        if ($rows->isEmpty()) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 0,
                failedRows: 0,
                details: [],
                skippedRows: 0,
                reason: 'empty_range',
            );
        }

        $componentRows = [];
        $totalRow = null;

        foreach ($rows as $rd) {
            $rn = (int) ($rd->data['row_number'] ?? 0);
            if ($rn === $totalRowNumber) {
                $totalRow = $rd;
            } else {
                $componentRows[] = $rd;
            }
        }

        if ($totalRow === null) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 0,
                details: [[
                    'reason' => 'missing_total_row',
                    'total_row' => $totalRowNumber,
                    'row_from' => $rowFrom,
                    'row_to' => $rowTo,
                    'message' => "Fila total {$totalRowNumber} no encontrada en los datos. "
                        . "Rango de filas disponible: [{$rowFrom}:{$rowTo}].",
                ]],
                skippedRows: $rows->count(),
                reason: 'missing_total_row',
            );
        }

        $allEmpty = true;
        foreach ($componentRows as $rd) {
            $v = $rd->data['values'][$column] ?? null;
            if ($v !== null && $v !== '') {
                $allEmpty = false;
                break;
            }
        }
        $tv = $totalRow->data['values'][$targetColumn] ?? null;
        if ($tv !== null && $tv !== '') {
            $allEmpty = false;
        }

        if ($allEmpty) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 0,
                details: [[
                    'reason' => 'empty_row',
                    'row_from' => $rowFrom,
                    'row_to' => $rowTo,
                    'total_row' => $totalRowNumber,
                    'message' => 'Todas las filas componentes y la fila total estan vacias',
                ]],
                skippedRows: $rows->count(),
                reason: 'empty_row',
            );
        }

        $componentSum = 0;
        $componentValues = [];
        $validationDetails = [];

        foreach ($componentRows as $rd) {
            $rn = $rd->data['row_number'] ?? null;
            $val = $rd->data['values'][$column] ?? null;
            $componentValues[$rn] = $val;

            $validation = $this->validateNumericValue($val, $column, $rn);
            if ($validation !== null) {
                $validation['rem_data_id'] = $rd->id;
                $validation['concept'] = $rd->data['concept'] ?? null;
                $validationDetails[] = $validation;
            } else {
                $componentSum += (float) $val;
            }
        }

        if (!empty($validationDetails)) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: count($validationDetails),
                details: $validationDetails,
                skippedRows: 0,
                reason: 'non_numeric_value_in_components',
            );
        }

        $totalVal = $totalRow->data['values'][$targetColumn] ?? null;
        $totalRowRn = $totalRow->data['row_number'] ?? null;

        $totalValidation = $this->validateNumericValue($totalVal, $targetColumn, $totalRowRn);
        if ($totalValidation !== null) {
            $totalValidation['rem_data_id'] = $totalRow->id;
            $totalValidation['concept'] = $totalRow->data['concept'] ?? null;
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 1,
                details: [$totalValidation],
                skippedRows: 0,
                reason: 'non_numeric_total',
            );
        }

        $totalFloat = (float) $totalVal;

        if (abs($componentSum - $totalFloat) < self::FLOAT_EPSILON) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: $rows->count(),
                failedRows: 0,
                details: [],
                skippedRows: 0,
                reason: '',
            );
        }

        $componentRowNumbers = array_map(fn($rd) => $rd->data['row_number'] ?? '?', $componentRows);
        return new RuleEvaluationResult(
            ruleKey: $ruleKey,
            totalRows: $rows->count(),
            failedRows: 1,
            details: [[
                'reason' => 'vertical_sum_mismatch',
                'component_rows' => implode(',', $componentRowNumbers),
                'total_row' => $totalRowRn,
                'column' => $column,
                'target_column' => $targetColumn,
                'calculated_sum' => $componentSum,
                'declared_value' => $totalFloat,
                'component_values' => $componentValues,
                'message' => "Suma vertical col{$column} filas "
                    . implode(',', $componentRowNumbers)
                    . " = {$componentSum} !== valor declarado {$totalFloat} (row {$totalRowRn})",
            ]],
            skippedRows: 0,
            reason: 'failed',
        );
    }

    private function evaluatePerRow(
        array $sourceLetters,
        string $targetColumn,
        Collection $rows,
        string $ruleKey,
        array $functionalRules = [],
        array $cellMetadata = [],
    ): RuleEvaluationResult {
        $totalRows = 0;
        $failedRows = 0;
        $skippedRows = 0;
        $details = [];

        foreach ($rows as $rd) {
            $vals = $rd->data['values'] ?? [];
            $rowNumber = $rd->data['row_number'] ?? null;
            $fr = $functionalRules[$rowNumber] ?? null;

            $hasData = $this->rowHasData($vals, $sourceLetters, $targetColumn);

            // Functional rule: puede_quedar_vacio — skip empty rows without failing
            if ($fr && $fr['empty_behavior'] === 'puede_quedar_vacio' && !$hasData) {
                $skippedRows++;
                $details[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'reason' => 'empty_allowed_by_functional_rule',
                    'message' => 'Fila vacia omitida por decision funcional: puede_quedar_vacio',
                    'functional_decision' => [
                        'empty_behavior' => $fr['empty_behavior'],
                        'status' => $fr['status'] ?? '',
                        'functional_condition' => $fr['functional_condition'] ?? '',
                        'justification' => $fr['justification'] ?? '',
                        'informed_by' => $fr['informed_by'] ?? '',
                        'informed_at' => $fr['informed_at'] ?? null,
                        'acquisition_method' => $fr['acquisition_method'] ?? '',
                        'scope' => $fr['scope'] ?? 'global',
                        'confidence_level' => $fr['confidence_level'] ?? '',
                        'concept' => $rd->data['concept'] ?? null,
                        'professional' => $rd->data['professional'] ?? '',
                    ],
                ];
                continue;
            }

            // Functional rule: debe_registrar_cero — all cells must be explicit zero
            if ($fr && $fr['empty_behavior'] === 'debe_registrar_cero') {
                $applicableSourceLetters = array_values(array_filter(
                    $sourceLetters,
                    fn($col) => $this->isApplicableInputCell($cellMetadata, $col, $rowNumber)
                ));
                $targetIsApplicable = $this->isApplicableInputCell($cellMetadata, $targetColumn, $rowNumber);
                $applicableColumns = $targetIsApplicable
                    ? array_merge($applicableSourceLetters, [$targetColumn])
                    : $applicableSourceLetters;

                $hasNonNumeric = false;
                foreach ($applicableSourceLetters as $col) {
                    $v = $vals[$col] ?? null;
                    if ($v !== null && $v !== '' && !is_numeric($v)) {
                        $hasNonNumeric = true;
                        break;
                    }
                }
                $tv = $vals[$targetColumn] ?? null;
                if ($targetIsApplicable && $tv !== null && $tv !== '' && !is_numeric($tv)) {
                    $hasNonNumeric = true;
                }

                if ($hasNonNumeric) {
                    // fall through to normal validation
                } else {
                    $missingCells = [];
                    $anyNonZero = false;
                    foreach ($applicableColumns as $col) {
                        $v = $vals[$col] ?? null;
                        if ($v === null || $v === '') {
                            $missingCells[] = $this->buildPendingCellDetail($cellMetadata, $col, $rowNumber, $v);
                        } elseif ((float)$v !== 0.0) {
                            $anyNonZero = true;
                        }
                    }

                    if ($anyNonZero) {
                        // La fila registra prestaciones: la decision "sin datos -> 0"
                        // no aplica y se valida la suma normalmente.
                    } else {
                        if (!empty($missingCells)) {
                            // FAIL: debe_registrar_cero but cells are empty
                            $totalRows++;
                            $failedRows++;
                            $details[] = [
                                'rem_data_id' => $rd->id,
                                'row_number' => $rowNumber,
                                'concept' => $rd->data['concept'] ?? null,
                                'reason' => 'empty_not_allowed_by_functional_rule',
                                'message' => 'Fila debe registrar 0 explicito segun decision funcional: debe_registrar_cero',
                                'target_column' => $targetColumn,
                                'row_total' => $vals[$targetColumn] ?? $rd->data['total'] ?? null,
                                'pending_cells' => $missingCells,
                                'pending_cells_count' => count($missingCells),
                                'functional_decision' => [
                                    'empty_behavior' => $fr['empty_behavior'],
                                    'status' => $fr['status'] ?? '',
                                    'functional_condition' => $fr['functional_condition'] ?? '',
                                    'justification' => $fr['justification'] ?? '',
                                    'informed_by' => $fr['informed_by'] ?? '',
                                    'informed_at' => $fr['informed_at'] ?? null,
                                    'acquisition_method' => $fr['acquisition_method'] ?? '',
                                    'scope' => $fr['scope'] ?? 'global',
                                    'confidence_level' => $fr['confidence_level'] ?? '',
                                    'concept' => $rd->data['concept'] ?? null,
                                    'professional' => $rd->data['professional'] ?? '',
                                ],
                            ];
                            continue;
                        }

                        // PASS: all applicable columns have explicit zero.
                        $totalRows++;
                        $details[] = [
                            'rem_data_id' => $rd->id,
                            'row_number' => $rowNumber,
                            'concept' => $rd->data['concept'] ?? null,
                            'reason' => 'zero_expected_by_functional_rule',
                            'message' => 'Fila en cero validada por decision funcional: debe_registrar_cero',
                            'functional_decision' => [
                                'empty_behavior' => $fr['empty_behavior'],
                                'status' => $fr['status'] ?? '',
                                'functional_condition' => $fr['functional_condition'] ?? '',
                                'justification' => $fr['justification'] ?? '',
                                'informed_by' => $fr['informed_by'] ?? '',
                                'informed_at' => $fr['informed_at'] ?? null,
                                'acquisition_method' => $fr['acquisition_method'] ?? '',
                                'scope' => $fr['scope'] ?? 'global',
                                'confidence_level' => $fr['confidence_level'] ?? '',
                                'concept' => $rd->data['concept'] ?? null,
                                'professional' => $rd->data['professional'] ?? '',
                            ],
                        ];
                        continue;
                    }
                }
            }

            if (!$hasData) {
                $skippedRows++;
                $details[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'reason' => 'empty_row',
                    'message' => 'Todas las columnas origen y destino estan vacias',
                ];
                continue;
            }

            $sum = 0;
            $validSum = true;

            foreach ($sourceLetters as $col) {
                $v = $vals[$col] ?? null;
                $validation = $this->validateNumericValue($v, $col, $rowNumber);

                if ($validation !== null) {
                    $validation['rem_data_id'] = $rd->id;
                    $validation['concept'] = $rd->data['concept'] ?? null;
                    $details[] = $validation;
                    $validSum = false;
                    break;
                }

                $sum += (float) $v;
            }

            if (!$validSum) {
                $failedRows++;
                continue;
            }

            if (!array_key_exists($targetColumn, $vals) || $vals[$targetColumn] === null || $vals[$targetColumn] === '') {
                $skippedRows++;
                $details[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'target_column' => $targetColumn,
                    'source_letters' => $sourceLetters,
                    'calculated_sum' => $sum,
                    'reason' => 'missing_target_value',
                    'message' => "No se puede evaluar la suma: falta valor declarado en columna {$targetColumn} fila {$rowNumber}",
                ];
                continue;
            }

            $declared = $vals[$targetColumn];
            $declaredValidation = $this->validateNumericValue($declared, $targetColumn, $rowNumber);

            if ($declaredValidation !== null) {
                $declaredValidation['rem_data_id'] = $rd->id;
                $declaredValidation['concept'] = $rd->data['concept'] ?? null;
                $details[] = $declaredValidation;
                $failedRows++;
                continue;
            }

            $declaredFloat = (float) $declared;

            if (abs($sum - $declaredFloat) >= self::FLOAT_EPSILON) {
                $failedRows++;
                $details[] = [
                    'rem_data_id' => $rd->id,
                    'row_number' => $rowNumber,
                    'concept' => $rd->data['concept'] ?? null,
                    'professional' => $rd->data['professional'] ?? null,
                    'target_column' => $targetColumn,
                    'source_letters' => $sourceLetters,
                    'calculated_sum' => $sum,
                    'declared_value' => $declaredFloat,
                    'reason' => 'sum_mismatch',
                    'message' => "Suma {$sum} !== valor declarado {$declaredFloat} (col{$targetColumn})",
                ];
            }

            $totalRows++;
        }

        $reason = '';
        if ($failedRows > 0) {
            $reason = 'failed';
        } elseif ($skippedRows > 0 && $totalRows === 0) {
            $reason = 'empty_row';
        }

        return new RuleEvaluationResult(
            ruleKey: $ruleKey,
            totalRows: $totalRows,
            failedRows: $failedRows,
            details: $details,
            skippedRows: $skippedRows,
            reason: $reason,
        );
    }

    private function rowHasData(array $values, array $sourceLetters, string $targetColumn): bool
    {
        foreach ($sourceLetters as $col) {
            $v = $values[$col] ?? null;
            if ($v !== null && $v !== '') {
                return true;
            }
        }
        $v = $values[$targetColumn] ?? null;
        if ($v !== null && $v !== '') {
            return true;
        }
        return false;
    }

    private function isApplicableInputCell(array $cellMetadata, string $column, mixed $rowNumber): bool
    {
        if ($rowNumber === null) {
            return true;
        }

        $cell = $cellMetadata[strtoupper($column) . $rowNumber] ?? null;
        if ($cell === null) {
            return true;
        }

        return ($cell['esta_bloqueada'] ?? false) !== true
            && ($cell['es_editable'] ?? true) !== false;
    }

    private function buildPendingCellDetail(array $cellMetadata, string $column, mixed $rowNumber, mixed $value): array
    {
        $column = strtoupper($column);
        $coordinate = $column . $rowNumber;
        $cell = $cellMetadata[$coordinate] ?? [];

        return [
            'coordinate' => $coordinate,
            'column' => $column,
            'label' => $this->labelForColumn($cellMetadata, $column, $rowNumber),
            'value' => $value,
            'expected_value' => 0,
            'editable' => ($cell['es_editable'] ?? true) === true,
            'blocked' => ($cell['esta_bloqueada'] ?? false) === true,
            'color' => $cell['color_fondo']['nombre_inferido'] ?? $cell['color_fondo']['rgb'] ?? null,
        ];
    }

    private function labelForColumn(array $cellMetadata, string $column, mixed $rowNumber): string
    {
        $column = strtoupper($column);
        $currentRow = is_numeric($rowNumber) ? (int) $rowNumber : 0;

        for ($row = $currentRow - 1; $row >= max(1, $currentRow - 8); $row--) {
            $value = trim((string) ($cellMetadata[$column . $row]['valor_bruto'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return "Columna {$column}";
    }

    private function validateNumericValue(mixed $value, string $column, mixed $rowNumber): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return null;
        }

        if (is_string($value) && is_numeric($value)) {
            return null;
        }

        if (is_string($value) && !is_numeric($value)) {
            return [
                'reason' => 'non_numeric_value',
                'column' => $column,
                'row_number' => $rowNumber,
                'value' => $value,
                'message' => "Valor no numerico en columna {$column} fila {$rowNumber}: '{$value}'",
            ];
        }

        return [
            'reason' => 'invalid_type',
            'column' => $column,
            'row_number' => $rowNumber,
            'value' => get_debug_type($value),
            'message' => "Tipo de dato no soportado en columna {$column} fila {$rowNumber}: " . get_debug_type($value),
        ];
    }
}
