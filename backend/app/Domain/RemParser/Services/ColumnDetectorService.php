<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedFieldDTO;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ColumnDetectorService
{
    private FormulaAnalyzerService $formulaAnalyzer;

    public function __construct(?FormulaAnalyzerService $formulaAnalyzer = null)
    {
        $this->formulaAnalyzer = $formulaAnalyzer ?? new FormulaAnalyzerService();
    }

    public function detect(
        Worksheet $worksheet,
        int $filaHeader,
        int $filaInicioDatos,
        ?int $filaFinDatos,
        string $highestCol,
        string $sheetName,
        ?string $codigoSeccion,
    ): array {
        $fields = [];
        $maxColIndex = Coordinate::columnIndexFromString($highestCol);
        $mergeMap = $this->buildMergeMap($worksheet);

        for ($col = 1; $col <= $maxColIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $headerValue = $this->getMergedCellValue($worksheet, $mergeMap, $colLetter, $filaHeader);

            $label = $this->cleanLabel($headerValue);
            if ($label === '') {
                continue;
            }

            $esTotal = $this->isTotalColumn($label);
            $esControlOculto = $this->isControlOculto(
                $worksheet, $colLetter, $filaInicioDatos, $filaFinDatos
            );

            $regla = null;
            if ($esControlOculto) {
                $formulaStr = $this->getFirstFormula($worksheet, $colLetter, $filaInicioDatos, $filaFinDatos);
                if ($formulaStr !== null) {
                    $rule = $this->formulaAnalyzer->analyze($formulaStr);
                    $regla = $rule?->toArray();
                }
            }

            $fields[] = new ParsedFieldDTO(
                letra: $colLetter,
                label: $label,
                esTotal: $esTotal,
                esControlOculto: $esControlOculto,
                reglaDetectada: $regla,
            );
        }

        return $fields;
    }

    private function cleanLabel(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $texto = trim((string) $value);
        return $texto;
    }

    private function isTotalColumn(string $label): bool
    {
        $upper = mb_strtoupper($label, 'UTF-8');
        if (str_contains($upper, 'TOTAL')) {
            return true;
        }
        if (str_contains($upper, 'AMBOS SEXOS')) {
            return true;
        }
        return false;
    }

    private function isControlOculto(Worksheet $ws, string $colLetter, int $startRow, ?int $endRow): bool
    {
        $maxRow = $endRow ?? $ws->getHighestRow();
        $filaSinFormula = false;
        $filaConDatos = false;

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $rawValue = $ws->getCell($colLetter . $row)->getValue();

            if ($rawValue === null || (is_string($rawValue) && trim($rawValue) === '')) {
                continue;
            }

            $filaConDatos = true;

            if (!is_string($rawValue) || !str_starts_with($rawValue, '=')) {
                $filaSinFormula = true;
                break;
            }
        }

        if (!$filaConDatos) {
            return false;
        }

        return !$filaSinFormula;
    }

    private function buildMergeMap(Worksheet $ws): array
    {
        $map = [];
        foreach ($ws->getMergeCells() as $range) {
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $m)) {
                $colStart = Coordinate::columnIndexFromString($m[1]);
                $rowStart = (int) $m[2];
                $colEnd = Coordinate::columnIndexFromString($m[3]);
                $rowEnd = (int) $m[4];
                $topLeftValue = $ws->getCell($m[1] . $m[2])->getValue();
                for ($r = $rowStart; $r <= $rowEnd; $r++) {
                    for ($c = $colStart; $c <= $colEnd; $c++) {
                        if ($r === $rowStart && $c === $colStart) continue;
                        $map[$r . ':' . $c] = $topLeftValue;
                    }
                }
            }
        }
        return $map;
    }

    private function getMergedCellValue(Worksheet $ws, array $mergeMap, string $colLetter, int $row): mixed
    {
        $colIndex = Coordinate::columnIndexFromString($colLetter);
        $value = $ws->getCell($colLetter . $row)->getValue();
        if ($value !== null) {
            return $value;
        }
        return $mergeMap[$row . ':' . $colIndex] ?? null;
    }

    private function getFirstFormula(Worksheet $ws, string $colLetter, int $startRow, ?int $endRow): ?string
    {
        $maxRow = $endRow ?? $ws->getHighestRow();

        for ($row = $startRow; $row <= $maxRow; $row++) {
            $rawValue = $ws->getCell($colLetter . $row)->getValue();
            if (is_string($rawValue) && str_starts_with($rawValue, '=')) {
                return $rawValue;
            }
        }

        return null;
    }
}
