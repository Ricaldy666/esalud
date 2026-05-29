<?php

namespace App\Domain\REM\Services;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class RemDiscoveryService
{
    public function discover(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new FileNotFoundException("Archivo no encontrado: {$filePath}");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'xls'], true)) {
            throw new InvalidArgumentException(
                "Formato no soportado: .{$ext}. Solo xlsx, xlsm, xls."
            );
        }

        $fileInfo = $this->getFileMetadata($filePath);

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setIncludeCharts(false);

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable $e) {
            return [
                'file' => $fileInfo,
                'workbook' => [
                    'total_sheets' => 0,
                    'visible_sheets' => 0,
                    'hidden_sheets' => 0,
                    'has_macros' => $ext === 'xlsm',
                    'creator' => null,
                    'last_modified' => null,
                ],
                'sheets' => [],
                'sheets_failed' => [['error' => $e->getMessage()]],
                'error' => 'Error al abrir el archivo: ' . $e->getMessage(),
            ];
        }

        $props = $spreadsheet->getProperties();

        $modified = $props->getModified();
        $lastModified = null;
        if ($modified instanceof \DateTimeInterface) {
            $lastModified = $modified->format('Y-m-d H:i:s');
        } elseif (is_numeric($modified)) {
            $lastModified = date('Y-m-d H:i:s', (int)$modified);
        } elseif (is_string($modified)) {
            $lastModified = $modified;
        }

        $workbook = [
            'total_sheets' => $spreadsheet->getSheetCount(),
            'visible_sheets' => 0,
            'hidden_sheets' => 0,
            'has_macros' => $ext === 'xlsm',
            'creator' => $props->getCreator() ?? null,
            'last_modified' => $lastModified,
            'title' => $props->getTitle() ?? null,
            'description' => $props->getDescription() ?? null,
        ];

        $sheets = [];
        $sheetsFailed = [];

        foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
            try {
                $sheets[] = $this->getSheetMetadata($sheet, $index);
                $sheetState = $sheet->getSheetState();
                if ($sheetState === \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE) {
                    $workbook['visible_sheets']++;
                } else {
                    $workbook['hidden_sheets']++;
                }
            } catch (\Throwable $e) {
                $sheetsFailed[] = [
                    'index' => $index,
                    'sheet_name' => $sheet->getTitle(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'file' => $fileInfo,
            'workbook' => $workbook,
            'sheets' => $sheets,
            'sheets_failed' => $sheetsFailed,
        ];
    }

    public function getSheetMetadata(Worksheet $sheet, int $index): array
    {
        $sheetName = $sheet->getTitle();
        $isHidden = $sheet->getSheetState() !== \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE;
        $dimension = $sheet->calculateWorksheetDimension();

        $maxRow = $sheet->getHighestRow();
        $maxColIndex = $sheet->getHighestColumn();
        $maxColNum = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxColIndex);

        $mergedCells = $sheet->getMergeCells();

        $sampleRows = [];
        $sampleLimit = 10;
        $colSampleLimit = min(15, $maxColNum);
        $cellsWithFormulas = 0;
        $cellsWithValues = 0;
        $cellsWithDates = 0;
        $cellsWithNumeric = 0;
        $detectedHeaderRow = null;

        $firstRowStrings = [];

        for ($row = 1; $row <= $maxRow && count($sampleRows) < $sampleLimit; $row++) {
            $rowData = ['row' => $row, 'cells' => []];
            $stringCount = 0;

            for ($col = 1; $col <= $colSampleLimit; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);

                $cell = $sheet->getCell($colLetter . $row);
                $value = $cell->getValue();
                $calculatedValue = $cell->getCalculatedValue();
                $dataType = $cell->getDataType();
                $isFormula = ($dataType === DataType::TYPE_FORMULA);
                $isFormulaAlt = str_starts_with((string)$value, '=');

                if ($isFormula || $isFormulaAlt) {
                    $cellsWithFormulas++;
                }

                if ($value !== null && $value !== '') {
                    $cellsWithValues++;
                    if ($isFormula || $isFormulaAlt) {
                        // counted above
                    } elseif (is_numeric($value)) {
                        $cellsWithNumeric++;
                    }

                    $displayValue = $calculatedValue;

                    if ($dataType === DataType::TYPE_ISO_DATE || $this->isExcelDate($calculatedValue, $value)) {
                        $cellsWithDates++;
                    }

                    if ($calculatedValue !== null && is_string($calculatedValue) && trim($calculatedValue) !== '') {
                        $stringCount++;
                    }

                    $rowData['cells'][] = [
                        'col' => $colLetter,
                        'value' => $this->normalizeValue($calculatedValue ?? $value),
                        'type' => $dataType,
                        'is_formula' => $isFormula || $isFormulaAlt,
                    ];
                }
            }

            if ($stringCount >= 3) {
                $firstRowStrings[$row] = $stringCount;
            }

            if (!empty($rowData['cells'])) {
                $sampleRows[] = $rowData;
            }
        }

        if (!empty($firstRowStrings)) {
            $detectedHeaderRow = array_key_first($firstRowStrings);
        }

        return [
            'index' => $index,
            'sheet_name' => $sheetName,
            'is_hidden' => $isHidden,
            'dimension' => $dimension,
            'max_row' => $maxRow,
            'max_column' => $maxColIndex,
            'max_column_num' => $maxColNum,
            'merged_cells' => array_values($mergedCells),
            'merged_cells_count' => count($mergedCells),
            'detected_header_row' => $detectedHeaderRow,
            'cells_with_formulas' => $cellsWithFormulas,
            'cells_with_values' => $cellsWithValues,
            'cells_with_numeric' => $cellsWithNumeric,
            'cells_with_dates' => $cellsWithDates,
            'sample_first_rows' => $sampleRows,
        ];
    }

    private function getFileMetadata(string $filePath): array
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return [
            'path' => realpath($filePath),
            'filename' => basename($filePath),
            'size_bytes' => filesize($filePath),
            'size_kb' => round(filesize($filePath) / 1024, 2),
            'mime_type' => $mimeType,
            'extension' => strtolower(pathinfo($filePath, PATHINFO_EXTENSION)),
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || $value === '-' || $value === 'N/A') {
                return null;
            }
        }
        return $value;
    }

    private function isExcelDate(mixed $calculatedValue, mixed $rawValue): bool
    {
        if (is_numeric($rawValue) && $rawValue > 40000 && $rawValue < 60000) {
            return true;
        }
        if (is_string($calculatedValue) && preg_match('/^\d{4}-\d{2}-\d{2}/', $calculatedValue)) {
            return true;
        }
        return false;
    }
}
