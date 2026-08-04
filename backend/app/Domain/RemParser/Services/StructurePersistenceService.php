<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedTemplateDTO;
use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StructurePersistenceService
{
    public function __construct() {}

    public function persistFromFile(
        string $filePath,
        string $serie,
        int $anio,
        string $sourceFilename,
        string $status = 'draft',
        ?string $notes = null,
    ): RemTemplateStructure {
        $parser = new RemParserService;
        $parsed = $parser->parse($filePath);

        $excelData = $this->extractRawExcelData($filePath, $parsed, $serie);

        $enriched = $this->enrichWithCellData($parsed, $excelData);

        return $this->persistVersion($enriched, $serie, $anio, $sourceFilename, $status, $notes);
    }

    public function persistFromParsed(
        ParsedTemplateDTO $parsed,
        string $filePath,
        string $serie,
        int $anio,
        string $sourceFilename,
        string $status = 'draft',
        ?string $notes = null,
    ): RemTemplateStructure {
        $excelData = $this->extractRawExcelData($filePath, $parsed, $serie);
        $enriched = $this->enrichWithCellData($parsed, $excelData);

        return $this->persistVersion($enriched, $serie, $anio, $sourceFilename, $status, $notes);
    }

    private function extractRawExcelData(string $filePath, ParsedTemplateDTO $parsed, string $serie): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setIncludeCharts(false);
        $spreadsheet = $reader->load($filePath);

        $sheetName = $this->findA01SheetName($parsed, $serie);
        if (! $sheetName) {
            return [];
        }

        $ws = $spreadsheet->getSheetByName($sheetName);
        if (! $ws) {
            return [];
        }

        $highestRow = $ws->getHighestRow();
        $highestCol = $ws->getHighestColumn();

        $data = [
            'highest_row' => $highestRow,
            'highest_col_letter' => $highestCol,
            'highest_col_index' => Coordinate::columnIndexFromString($highestCol),
            'total_rows_classification' => [],
            'formula_cells' => [],
        ];

        // Classify all rows that contain "TOTAL"
        for ($r = 1; $r <= $highestRow; $r++) {
            $valA = $ws->getCell('A'.$r)->getCalculatedValue() ?? '';
            $valB = $ws->getCell('B'.$r)->getCalculatedValue() ?? '';

            if (stripos($valA, 'TOTAL') !== false || stripos($valB, 'TOTAL') !== false) {
                $hasSumFormula = false;
                $formulaCols = [];
                $colIdx = 0;
                for ($col = 'A'; $colIdx < 70; $col++, $colIdx++) {
                    $cell = $ws->getCell($col.$r);
                    if ($cell->isFormula()) {
                        $formula = $cell->getValue();
                        $hasSumFormula = $hasSumFormula || str_starts_with($formula ?? '', '=SUM(');
                        $formulaCols[$col] = $formula;
                    }
                    if ($col === 'Z') {
                        $col = 'AA';
                    }
                    if ($col === 'AZ') {
                        $col = 'BA';
                    }
                    if ($col === 'BZ') {
                        $col = 'CA';
                    }
                    if ($col === 'CZ') {
                        $col = 'DA';
                    }
                    if ($col === 'DZ') {
                        $col = 'EA';
                    }
                }

                // Classify
                $classification = $this->classifyTotalRow($r, $valA, $valB, $hasSumFormula);

                $data['total_rows_classification'][] = [
                    'row' => $r,
                    'a' => $valA,
                    'b' => $valB,
                    'has_sum_formula' => $hasSumFormula,
                    'formulas' => $formulaCols,
                    'classification' => $classification,
                ];
            }
        }

        // Collect all formula cells
        for ($r = 1; $r <= $highestRow; $r++) {
            $colIdx = 0;
            for ($col = 'A'; $colIdx < 70; $col++, $colIdx++) {
                $cell = $ws->getCell($col.$r);
                if ($cell->isFormula()) {
                    $data['formula_cells'][$col.$r] = $cell->getValue();
                }
                if ($col === 'Z') {
                    $col = 'AA';
                }
                if ($col === 'AZ') {
                    $col = 'BA';
                }
                if ($col === 'BZ') {
                    $col = 'CA';
                }
                if ($col === 'CZ') {
                    $col = 'DA';
                }
                if ($col === 'DZ') {
                    $col = 'EA';
                }
            }
        }

        $spreadsheet->disconnectWorksheets();

        return $data;
    }

    private function classifyTotalRow(int $row, string $a, string $b, bool $hasFormula): string
    {
        $a = trim($a);
        $b = trim($b);

        // True TOTAL row: has SUM formulas aggregating multiple data rows
        if ($hasFormula) {
            return 'total_real';
        }

        // Section header where column B says "TOTAL" (e.g., "TOTAL EXAMEN VPH")
        if (empty($a) && stripos($b, 'TOTAL') === 0) {
            return 'header_label';
        }

        // Row where A is a label ending in TOTAL (e.g., "TIPO DE CONTROL")
        if (stripos($a, 'TOTAL') !== false && stripos($a, 'SECCI') === false) {
            return 'subtotal_label';
        }

        // Section header with TOTAL in column B
        if (stripos($b, 'TOTAL') === 0) {
            return 'header_section';
        }

        return 'unknown';
    }

    private function enrichWithCellData(ParsedTemplateDTO $parsed, array $excelData): array
    {
        $structure = $parsed->toArray();
        $structure['_metadata'] = [
            'extracted_at' => now()->toIso8601String(),
            'extractor_version' => '2.0',
            'source_hash' => $parsed->hashEstructura,
            'total_rows' => $excelData['total_rows_classification'] ?? [],
            'formula_count' => count($excelData['formula_cells'] ?? []),
            'highest_row' => $excelData['highest_row'] ?? null,
            'highest_col' => $excelData['highest_col_letter'] ?? null,
        ];

        // Enrich each section with formula data and row types
        foreach ($structure['forms'] as &$form) {
            if ($form['sheetName'] !== 'A01') {
                continue;
            }

            foreach ($form['sections'] as &$section) {
                $dataStart = $section['filaInicioDatos'];
                $dataEnd = $section['filaFinDatos'];

                // Classify each row within the section
                $rowClassifications = [];
                if ($dataEnd !== null) {
                    for ($r = $dataStart; $r <= $dataEnd; $r++) {
                        $rowType = 'data';
                        foreach ($excelData['total_rows_classification'] as $tr) {
                            if ($tr['row'] === $r && $tr['classification'] === 'total_real') {
                                $rowType = 'total';
                                break;
                            }
                        }
                        $rowClassifications[$r] = $rowType;
                    }
                }
                $section['_row_types'] = $rowClassifications;

                // Attach formulas to fields
                foreach ($section['fields'] as &$field) {
                    $letra = $field['letra'];
                    // Check if this field has formula info
                    if ($field['esTotal'] || $field['esControlOculto']) {
                        continue; // formulas already captured by reglaDetectada
                    }
                }
                unset($field);
            }
            unset($section);
        }
        unset($form);

        return $structure;
    }

    private function persistVersion(
        array $estructura,
        string $serie,
        int $anio,
        string $sourceFilename,
        string $status,
        ?string $notes,
    ): RemTemplateStructure {
        return DB::transaction(function () use ($estructura, $serie, $anio, $sourceFilename, $status, $notes) {
            // Compute next version number
            $currentMax = RemTemplateStructure::where('anio', $anio)
                ->where('serie', $serie)
                ->max('version_number');

            $versionNumber = ($currentMax ?? 0) + 1;

            $hash = $estructura['_metadata']['source_hash']
                ?? md5(json_encode($estructura['forms'] ?? []));

            return RemTemplateStructure::create([
                'anio' => $anio,
                'serie' => $serie,
                'version_number' => $versionNumber,
                'hash_estructura' => $hash,
                'estructura' => $estructura,
                'metadata' => [
                    'apps' => [],
                    'formulas_count' => $estructura['_metadata']['formula_count'] ?? 0,
                    'total_rows_classification' => $estructura['_metadata']['total_rows'] ?? [],
                ],
                'source_filename' => $sourceFilename,
                'status' => $status,
                'notes' => $notes,
            ]);
        });
    }

    private function findA01SheetName(ParsedTemplateDTO $parsed, string $serie): ?string
    {
        // Try "A01" first
        foreach ($parsed->forms as $form) {
            if ($form->sheetName === 'A01') {
                return 'A01';
            }
        }
        // Fallback: find any sheet starting with the serie code
        foreach ($parsed->forms as $form) {
            if (str_starts_with($form->sheetName, $serie)) {
                return $form->sheetName;
            }
        }

        return null;
    }
}
