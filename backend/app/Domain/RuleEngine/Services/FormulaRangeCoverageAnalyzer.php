<?php

namespace App\Domain\RuleEngine\Services;

/**
 * Utilidad compartida (Fase 3C-1B, CLAUDE.md punto 17.14/17.15): expande
 * sintaxis de rango Excel (COL#:COL#) y referencias individuales de una
 * formula, devolviendo el conjunto de filas cubiertas y cualquier
 * referencia a una columna distinta de la esperada. Extraida para que
 * RuleBindingReconciliationService (metodo isLegitimateTrailingTotalBeyondBounds())
 * y RuleActivateCategoryATotalCommand (descubrimiento del placeholder
 * {0,0}) usen exactamente el mismo heuristico, sin duplicarlo -- mismo
 * algoritmo ya validado en la auditoria de elegibilidad (punto 17.9), el
 * piloto (17.8) y Fase 3C-1A (17.11).
 */
class FormulaRangeCoverageAnalyzer
{
    /**
     * @return array{rows: int[], other_column_refs: string[]}
     */
    public static function analyze(string $formula, string $expectedColumn): array
    {
        $covered = [];
        $otherColumnRefs = [];
        $remaining = $formula;

        if (preg_match_all('/([A-Z]{1,3})(\d+):([A-Z]{1,3})(\d+)/', $formula, $rangeMatches, PREG_SET_ORDER)) {
            foreach ($rangeMatches as $rm) {
                [$full, $col1, $row1, $col2, $row2] = $rm;
                $row1 = (int) $row1;
                $row2 = (int) $row2;
                if (strtoupper($col1) !== strtoupper($expectedColumn) || strtoupper($col2) !== strtoupper($expectedColumn)) {
                    $otherColumnRefs[] = $full;
                }
                for ($r = min($row1, $row2); $r <= max($row1, $row2); $r++) {
                    $covered[$r] = true;
                }
                $remaining = str_replace($full, '', $remaining);
            }
        }

        if (preg_match_all('/([A-Z]{1,3})(\d+)/', $remaining, $singleMatches, PREG_SET_ORDER)) {
            foreach ($singleMatches as $sm) {
                [$full, $col, $row] = $sm;
                if (strtoupper($col) !== strtoupper($expectedColumn)) {
                    $otherColumnRefs[] = $full;
                    continue;
                }
                $covered[(int) $row] = true;
            }
        }

        $rows = array_keys($covered);
        sort($rows);

        return ['rows' => $rows, 'other_column_refs' => array_unique($otherColumnRefs)];
    }

    /**
     * true si $formula cubre EXACTAMENTE [$from:$to] (sin huecos, sin
     * filas de mas, sin referencias a otra columna).
     */
    public static function isCompleteContiguous(string $formula, string $expectedColumn, int $from, int $to): bool
    {
        $parsed = self::analyze($formula, $expectedColumn);
        if (!empty($parsed['other_column_refs'])) {
            return false;
        }

        return $parsed['rows'] === range($from, $to);
    }
}
