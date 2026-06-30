<?php

namespace App\Domain\REM\Services;

use App\Domain\REM\Models\RemUpload;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class RemParserService
{
    public function parse(RemUpload $upload): ParseResult
    {
        $template = $upload->remTemplate;
        if (!$template || empty($template->config['sheets'])) {
            return new ParseResult(
                status: 'failed',
                errors: [['type' => 'structural', 'message' => 'Template no configurado o sin hojas definidas']]
            );
        }

        $fullPath = storage_path("app/rem-uploads/{$upload->stored_path}");
        if (!file_exists($fullPath)) {
            return new ParseResult(
                status: 'failed',
                errors: [['type' => 'structural', 'message' => 'Archivo fisico no encontrado: ' . basename($fullPath)]]
            );
        }

        try {
            $spreadsheet = $this->loadSpreadsheet($fullPath);
        } catch (\Throwable $e) {
            return new ParseResult(
                status: 'failed',
                errors: [['type' => 'structural', 'message' => 'Error al abrir archivo: ' . $e->getMessage()]]
            );
        }

        $errors = [];
        $extractedData = [];
        $totalRowsProcessed = 0;
        $totalCellsParsed = 0;
        $totalErrorCells = 0;
        $config = $template->config;
        $anyDataExtracted = false;

        foreach ($config['sheets'] as $sheetConfig) {
            $sheetName = $sheetConfig['sheet_name'];

            if (!$spreadsheet->sheetNameExists($sheetName)) {
                if ($sheetConfig['is_required'] ?? false) {
                    $errors[] = [
                        'type' => 'structural',
                        'sheet' => $sheetName,
                        'message' => "Hoja requerida '{$sheetName}' no encontrada en el archivo",
                    ];
                }
                continue;
            }

            $worksheet = $spreadsheet->getSheetByName($sheetName);

            try {
                $result = $this->parseSheet($worksheet, $sheetConfig);
                $extractedData = array_merge($extractedData, $result['data']);
                $errors = array_merge($errors, $result['errors']);
                $totalRowsProcessed += $result['rows_processed'];
                $totalCellsParsed += $result['cells_parsed'];
                $totalErrorCells += $result['error_cells'];

                if (!empty($result['data'])) {
                    $anyDataExtracted = true;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'type' => 'structural',
                    'sheet' => $sheetName,
                    'message' => "Error al procesar hoja '{$sheetName}': " . $e->getMessage(),
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();

        if (!$anyDataExtracted && !empty($errors)) {
            $status = 'failed';
        } elseif (!empty($errors)) {
            $status = 'with_errors';
        } else {
            $status = 'success';
        }

        return new ParseResult(
            status: $status,
            extractedData: $extractedData,
            errors: $this->buildErrorReport($errors),
            totalRowsProcessed: $totalRowsProcessed,
            totalCellsParsed: $totalCellsParsed,
            totalErrorCells: $totalErrorCells,
        );
    }

    private function loadSpreadsheet(string $path): Spreadsheet
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setIncludeCharts(false);
        return $reader->load($path);
    }

    private function parseSheet(Worksheet $worksheet, array $sheetConfig): array
    {
        $structure = $sheetConfig['structure'];
        $columns = $sheetConfig['columns'] ?? [];
        $validationRules = $sheetConfig['validation_rules'] ?? [];

        $headerRow = $structure['header_row'];
        $dataStartRow = $structure['data_start_row'];
        $conceptCol = $structure['concept_column'];
        $professionalCol = $structure['professional_column'] ?? null;
        $totalCol = $structure['total_column'];
        $sectionBreakPattern = $structure['section_break_pattern'] ?? null;
        $maxDataRows = $structure['max_data_rows'] ?? 1500;

        $data = [];
        $errors = [];
        $rowsProcessed = 0;
        $cellsParsed = 0;
        $errorCells = 0;
        $lastConcept = null;

        $columnLetters = array_map(fn($c) => $c['letter'], $columns);

        $sheetMaxRow = $worksheet->getHighestRow();
        $maxRow = min($sheetMaxRow, $maxDataRows);

        for ($row = $dataStartRow; $row <= $maxRow; $row++) {
            $conceptValue = $worksheet->getCell($conceptCol . $row)->getCalculatedValue();
            $professional = $professionalCol ? trim((string)($worksheet->getCell($professionalCol . $row)->getCalculatedValue() ?? '')) : '';
            $totalRaw = $worksheet->getCell($totalCol . $row)->getCalculatedValue();
            $total = is_numeric($totalRaw) ? (int)$totalRaw : null;

            $hasConcept = $conceptValue !== null && trim((string)$conceptValue) !== '';

            if ($hasConcept) {
                $conceptStr = trim((string)$conceptValue);

                if ($sectionBreakPattern && preg_match($sectionBreakPattern, $conceptStr)) {
                    break;
                }

                if (strtoupper($conceptStr) === 'TIPO DE CONTROL') {
                    continue;
                }

                $lastConcept = $conceptStr;
            }

            if ($lastConcept === null) {
                continue;
            }

            $values = [];
            $rowHasContent = false;

            foreach ($columnLetters as $colLetter) {
                $cellValue = $worksheet->getCell($colLetter . $row)->getCalculatedValue();
                $cellsParsed++;

                $validation = $this->validateCell($cellValue, $validationRules);
                if ($validation['valid']) {
                    $parsed = $validation['value'];
                } else {
                    $parsed = null;
                    $errorCells++;
                    $errors[] = [
                        'type' => 'data',
                        'sheet' => $sheetConfig['sheet_name'],
                        'row' => $row,
                        'column' => $colLetter,
                        'value' => $cellValue,
                        'reason' => $validation['reason'],
                    ];
                }

                if ($parsed !== null) {
                    $rowHasContent = true;
                }

                $values[$colLetter] = $parsed;
            }

            $values[$totalCol] = $total;

            $rowsProcessed++;

            if ($rowHasContent || $total !== null || $professional !== '') {
                $entry = [
                    'concept' => $lastConcept,
                    'professional' => $professional,
                    'total' => $total,
                    'values' => $values,
                ];

                if (!empty($sheetConfig['section_code'])) {
                    $entry['section'] = $sheetConfig['section_code'];
                }

                $data[] = $entry;
            }
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'rows_processed' => $rowsProcessed,
            'cells_parsed' => $cellsParsed,
            'error_cells' => $errorCells,
        ];
    }

    private function validateCell(mixed $value, array $rules): array
    {
        if ($value === null || $value === '') {
            if ($rules['allow_null'] ?? true) {
                return ['valid' => true, 'value' => null];
            }
            return ['valid' => false, 'value' => null, 'reason' => 'Valor vacio no permitido'];
        }

        $dataType = $rules['data_type'] ?? 'integer';

        if ($dataType === 'integer') {
            if (is_numeric($value)) {
                $intVal = (int)$value;
                if (is_float($value + 0) && ($value + 0) != $intVal) {
                    $intVal = (int)round($value);
                }
                $min = $rules['min'] ?? null;
                $max = $rules['max'] ?? null;
                if ($min !== null && $intVal < $min) {
                    return ['valid' => false, 'value' => $intVal, 'reason' => "Valor menor que minimo ({$min})"];
                }
                if ($max !== null && $intVal > $max) {
                    return ['valid' => false, 'value' => $intVal, 'reason' => "Valor mayor que maximo ({$max})"];
                }
                return ['valid' => true, 'value' => $intVal];
            }
            return ['valid' => false, 'value' => $value, 'reason' => 'No es un numero entero valido'];
        }

        return ['valid' => true, 'value' => $value];
    }

    private function buildErrorReport(array $errors): array
    {
        if (empty($errors)) {
            return [];
        }

        $structural = array_filter($errors, fn($e) => $e['type'] === 'structural');
        $dataErrors = array_filter($errors, fn($e) => $e['type'] === 'data');

        $report = [
            'summary' => [
                'total_errors' => count($errors),
                'structural_errors' => count($structural),
                'data_errors' => count($dataErrors),
            ],
        ];

        if (!empty($structural)) {
            $report['structural'] = array_values($structural);
        }

        if (!empty($dataErrors)) {
            $report['errors'] = array_values($dataErrors);
        }

        return $report;
    }
}
