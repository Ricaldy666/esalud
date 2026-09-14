<?php

namespace App\Domain\RuleEngine\Evaluators;

use Illuminate\Support\Collection;

/**
 * REM BM -- FASE BM-2.6B (2026-09-11): evaluador GENERICO para relaciones
 * cross-hoja dentro del mismo upload/workbook (ej. BM18!E14 == BM18A!D20,
 * hallazgo real de BM-2.5/BM-2.6A). NO especifico de BM -- funciona para
 * cualquier serie/hoja (A/BM/BS/D/P) siempre que la config declare
 * explicitamente source/target.
 *
 * Arquitectura: mismo patron pluggable ya usado por SumEqualsEvaluator/
 * RequiredAndLeParentEvaluator (RuleEvaluatorInterface::supports()+
 * evaluate()) -- no se modifica ninguno de los dos evaluadores existentes.
 *
 * Config esperado (rule_type = 'cross_sheet_equals'):
 * [
 *   'sheet' => 'BM18',              // hoja FUENTE -- mismo campo 'sheet'
 *                                    // ya usado por todo rule_type (asi
 *                                    // RuleEngineService::execute() sigue
 *                                    // resolviendo $rows sin cambios)
 *   'section' => 'A',                // seccion FUENTE (para cache de
 *                                    // metadata/functional rules, igual
 *                                    // que cualquier otra regla)
 *   'source' => ['cell' => 'E14'],
 *   'target' => [
 *     'sheet' => 'BM18A',
 *     'cell' => 'D20',                       // O:
 *     'range' => 'D92:D113',                 // (mutuamente excluyente con 'cell')
 *     'aggregation' => 'sum',                // requerido si 'range' esta presente
 *   ],
 * ]
 *
 * $rows (parametro de evaluate(), igual que en cualquier otro evaluador) =
 * filas de la hoja FUENTE (rem_data agrupado por 'sheet', ya resuelto por
 * RuleEngineService::execute() via config['sheet'], sin cambios).
 *
 * config['_target_rows'] (INYECTADO por RuleEngineService::execute(), NUEVO
 * en esta fase, mismo patron ya usado para _functional_rules/_cell_metadata/
 * _section_bounds) = filas de la hoja DESTINO, resueltas del mismo upload.
 * Nunca cruza uploads -- ver RuleEngineService::execute().
 *
 * Semantica numerica: reutiliza DELIBERADAMENTE la misma tolerancia y las
 * mismas reglas de validacion que SumEqualsEvaluator::validateNumericValue()/
 * FLOAT_EPSILON (duplicado aqui, no extraido a un trait compartido -- mismo
 * patron de duplicacion deliberada ya usado en este proyecto por
 * MismatchResolutionAuditService::computeRowFingerprint() para no acoplar
 * ni modificar el archivo certificado de Serie A). Nunca se inventa una
 * segunda semantica de tolerancia distinta.
 */
class CrossSheetEqualsEvaluator implements RuleEvaluatorInterface
{
    private const FLOAT_EPSILON = 0.00001;

    public function supports(string $ruleType): bool
    {
        return $ruleType === 'cross_sheet_equals';
    }

    public function evaluate(array $config, Collection $rows): RuleEvaluationResult
    {
        $ruleKey = $config['_rule_key'] ?? '?';
        $sourceCell = $config['source']['cell'] ?? null;
        $target = $config['target'] ?? [];
        $targetSheet = $target['sheet'] ?? null;
        $targetCell = $target['cell'] ?? null;
        $targetRange = $target['range'] ?? null;
        $aggregation = $target['aggregation'] ?? null;

        if (!$sourceCell || !$targetSheet || (!$targetCell && !$targetRange)) {
            return $this->invalidConfig($ruleKey, $rows->count(), 'config incompleta -- requiere source.cell, target.sheet, y target.cell o target.range');
        }

        $sourceCoord = $this->parseCoordinate($sourceCell);
        if ($sourceCoord === null) {
            return $this->invalidConfig($ruleKey, $rows->count(), "source.cell invalida: '{$sourceCell}'");
        }

        if ($targetCell && $targetRange) {
            return $this->invalidConfig($ruleKey, $rows->count(), "target no puede declarar 'cell' y 'range' simultaneamente");
        }

        if ($targetRange && $aggregation !== 'sum') {
            return $this->invalidConfig($ruleKey, $rows->count(), "target.range requiere target.aggregation='sum' (unica agregacion soportada en esta fase)");
        }

        $targetRows = $config['_target_rows'] ?? new Collection();
        if (!($targetRows instanceof Collection)) {
            $targetRows = new Collection($targetRows);
        }

        // ── SOURCE: una sola celda, siempre. ───────────────────────────
        $sourceRow = $this->findRow($rows, $sourceCoord['row']);
        if ($sourceRow === null) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 0,
                failedRows: 0,
                details: [[
                    'reason' => 'source_cell_not_found',
                    'source_cell' => $sourceCell,
                    'message' => "La fila {$sourceCoord['row']} no existe en los datos de la hoja fuente -- no se puede evaluar {$sourceCell}.",
                ]],
                skippedRows: 1,
                reason: 'source_cell_not_found',
            );
        }
        $sourceRawValue = $sourceRow->data['values'][$sourceCoord['column']] ?? null;

        // ── TARGET: celda simple o SUM(rango). ─────────────────────────
        if ($targetCell !== null) {
            $targetCoord = $this->parseCoordinate($targetCell);
            if ($targetCoord === null) {
                return $this->invalidConfig($ruleKey, 0, "target.cell invalida: '{$targetCell}'");
            }

            $targetRow = $this->findRow($targetRows, $targetCoord['row']);
            if ($targetRow === null) {
                return new RuleEvaluationResult(
                    ruleKey: $ruleKey,
                    totalRows: 0,
                    failedRows: 0,
                    details: [[
                        'reason' => 'target_cell_not_found',
                        'target_sheet' => $targetSheet,
                        'target_cell' => $targetCell,
                        'message' => "La fila {$targetCoord['row']} no existe en los datos de la hoja destino '{$targetSheet}' -- no se puede evaluar {$targetSheet}!{$targetCell}.",
                    ]],
                    skippedRows: 1,
                    reason: 'target_cell_not_found',
                );
            }
            $targetRawValue = $targetRow->data['values'][$targetCoord['column']] ?? null;

            return $this->compareSingleValues(
                $ruleKey,
                $sourceCell,
                $sourceRawValue,
                "{$targetSheet}!{$targetCell}",
                $targetRawValue,
            );
        }

        // target.range + aggregation=sum
        $rangeCoords = $this->parseRange($targetRange);
        if ($rangeCoords === null) {
            return $this->invalidConfig($ruleKey, 0, "target.range invalido: '{$targetRange}'");
        }

        $componentRows = $targetRows->filter(
            fn($rd) => (int) ($rd->data['row_number'] ?? -1) >= $rangeCoords['row_start']
                && (int) ($rd->data['row_number'] ?? -1) <= $rangeCoords['row_end']
        );

        if ($componentRows->isEmpty()) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 0,
                failedRows: 0,
                details: [[
                    'reason' => 'empty_range',
                    'target_sheet' => $targetSheet,
                    'target_range' => $targetRange,
                    'message' => "El rango {$targetSheet}!{$targetRange} no tiene ninguna fila en los datos de la hoja destino.",
                ]],
                skippedRows: 1,
                reason: 'empty_range',
            );
        }

        $sum = 0.0;
        foreach ($componentRows as $rd) {
            $v = $rd->data['values'][$rangeCoords['column']] ?? null;
            $validation = $this->validateNumericValue($v, $rangeCoords['column'], $rd->data['row_number'] ?? null);
            if ($validation !== null) {
                $validation['target_sheet'] = $targetSheet;
                $validation['target_range'] = $targetRange;
                return new RuleEvaluationResult(
                    ruleKey: $ruleKey,
                    totalRows: 1,
                    failedRows: 1,
                    details: [$validation],
                    skippedRows: 0,
                    reason: 'non_numeric_value_in_target_range',
                );
            }
            if ($v !== null && $v !== '') {
                $sum += (float) $v;
            }
        }

        return $this->compareSingleValues(
            $ruleKey,
            $sourceCell,
            $sourceRawValue,
            "SUM({$targetSheet}!{$targetRange})",
            $sum,
        );
    }

    /**
     * Compara un valor fuente contra un valor destino (ya sea una celda
     * simple o una suma ya calculada) con la MISMA semantica que
     * SumEqualsEvaluator:
     *   - ambos vacios (null/'') -> skip, reason 'empty_row' (no es un
     *     error: no hay nada que comparar).
     *   - destino vacio, fuente con valor -> skip, reason
     *     'missing_target_value' (igual que sum_equals: nunca se asume 0
     *     para un valor DECLARADO ausente).
     *   - fuente vacia, destino con valor -> fuente se trata como 0 (igual
     *     que cada componente de sum_equals: un componente ausente
     *     contribuye 0 a la suma) y se compara normalmente.
     *   - no numerico en cualquier lado -> fail, reason
     *     'non_numeric_value' (mismo mensaje/forma que
     *     SumEqualsEvaluator::validateNumericValue()).
     *   - numerico en ambos lados -> igualdad con FLOAT_EPSILON (misma
     *     tolerancia que sum_equals).
     */
    private function compareSingleValues(
        string $ruleKey,
        string $sourceLabel,
        mixed $sourceRawValue,
        string $targetLabel,
        mixed $targetRawValue,
    ): RuleEvaluationResult {
        $sourceEmpty = $sourceRawValue === null || $sourceRawValue === '';
        $targetEmpty = $targetRawValue === null || $targetRawValue === '';

        if ($sourceEmpty && $targetEmpty) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 0,
                failedRows: 0,
                details: [[
                    'reason' => 'empty_row',
                    'source' => $sourceLabel,
                    'target' => $targetLabel,
                    'message' => 'Ambos lados de la relacion cross-hoja estan vacios.',
                ]],
                skippedRows: 1,
                reason: 'empty_row',
            );
        }

        if ($targetEmpty) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 0,
                failedRows: 0,
                details: [[
                    'reason' => 'missing_target_value',
                    'source' => $sourceLabel,
                    'target' => $targetLabel,
                    'message' => "No se puede evaluar: falta el valor declarado en {$targetLabel}.",
                ]],
                skippedRows: 1,
                reason: 'missing_target_value',
            );
        }

        $sourceValidation = $this->validateNumericValue($sourceRawValue, $sourceLabel, null);
        if ($sourceValidation !== null) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 1,
                failedRows: 1,
                details: [array_merge($sourceValidation, ['source' => $sourceLabel, 'target' => $targetLabel])],
                skippedRows: 0,
                reason: 'non_numeric_value',
            );
        }

        $targetValidation = $this->validateNumericValue($targetRawValue, $targetLabel, null);
        if ($targetValidation !== null) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 1,
                failedRows: 1,
                details: [array_merge($targetValidation, ['source' => $sourceLabel, 'target' => $targetLabel])],
                skippedRows: 0,
                reason: 'non_numeric_value',
            );
        }

        // Fuente vacia (ya sabemos que el destino no lo esta, por los
        // guards de arriba) se trata como 0 -- mismo criterio que un
        // componente ausente en SumEqualsEvaluator.
        $sourceFloat = $sourceEmpty ? 0.0 : (float) $sourceRawValue;
        $targetFloat = (float) $targetRawValue;

        if (abs($sourceFloat - $targetFloat) < self::FLOAT_EPSILON) {
            return new RuleEvaluationResult(
                ruleKey: $ruleKey,
                totalRows: 1,
                failedRows: 0,
                details: [],
                skippedRows: 0,
                reason: '',
            );
        }

        return new RuleEvaluationResult(
            ruleKey: $ruleKey,
            totalRows: 1,
            failedRows: 1,
            details: [[
                'reason' => 'cross_sheet_mismatch',
                'source' => $sourceLabel,
                'target' => $targetLabel,
                'source_value' => $sourceFloat,
                'target_value' => $targetFloat,
                'message' => "{$sourceLabel} = {$sourceFloat} !== {$targetLabel} = {$targetFloat}",
            ]],
            skippedRows: 0,
            reason: 'failed',
        );
    }

    private function findRow(Collection $rows, int $rowNumber): ?object
    {
        return $rows->first(fn($rd) => (int) ($rd->data['row_number'] ?? -1) === $rowNumber);
    }

    /**
     * @return array{column: string, row: int}|null
     */
    private function parseCoordinate(string $cell): ?array
    {
        if (!preg_match('/^\$?([A-Z]+)\$?(\d+)$/i', trim($cell), $m)) {
            return null;
        }

        return ['column' => strtoupper($m[1]), 'row' => (int) $m[2]];
    }

    /**
     * @return array{column: string, row_start: int, row_end: int}|null
     */
    private function parseRange(string $range): ?array
    {
        if (!preg_match('/^\$?([A-Z]+)\$?(\d+)\s*:\s*\$?([A-Z]+)\$?(\d+)$/i', trim($range), $m)) {
            return null;
        }

        $colStart = strtoupper($m[1]);
        $colEnd = strtoupper($m[3]);
        $rowStart = (int) $m[2];
        $rowEnd = (int) $m[4];

        if ($colStart !== $colEnd || $rowStart > $rowEnd) {
            return null;
        }

        return ['column' => $colStart, 'row_start' => $rowStart, 'row_end' => $rowEnd];
    }

    private function invalidConfig(string $ruleKey, int $totalRows, string $message): RuleEvaluationResult
    {
        return new RuleEvaluationResult(
            ruleKey: $ruleKey,
            totalRows: $totalRows,
            failedRows: 0,
            details: [[
                'reason' => 'invalid_config',
                'message' => $message,
            ]],
            skippedRows: $totalRows,
            reason: 'invalid_config',
        );
    }

    /**
     * Duplicado deliberado de SumEqualsEvaluator::validateNumericValue() --
     * misma semantica exacta (null/'' = valido/ausente, int/float validos,
     * string numerica valida, string no numerica = error), ver docblock de
     * clase arriba.
     */
    private function validateNumericValue(mixed $value, string $label, mixed $rowNumber): ?array
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
                'column' => $label,
                'row_number' => $rowNumber,
                'value' => $value,
                'message' => "Valor no numerico en {$label}" . ($rowNumber !== null ? " fila {$rowNumber}" : '') . ": '{$value}'",
            ];
        }

        return [
            'reason' => 'invalid_type',
            'column' => $label,
            'row_number' => $rowNumber,
            'value' => get_debug_type($value),
            'message' => "Tipo de dato no soportado en {$label}" . ($rowNumber !== null ? " fila {$rowNumber}" : '') . ': ' . get_debug_type($value),
        ];
    }
}
