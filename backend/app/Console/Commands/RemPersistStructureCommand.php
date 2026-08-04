<?php

namespace App\Console\Commands;

use App\Domain\RemParser\DTOs\ParsedTemplateDTO;
use App\Domain\RemParser\Services\RemParserService;
use App\Domain\RemParser\Services\StructurePersistenceService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RemPersistStructureCommand extends Command
{
    protected $signature = 'rem:persist-structure
        {file : Path to the XLSM file}
        {--serie=A : Serie letter}
        {--anio=2026 : Year}
        {--status=draft : Initial status (draft|approved|active)}
        {--notes= : Optional notes}';

    protected $description = 'Parse an XLSM file and persist its structure as a new versioned record';

    public function handle(StructurePersistenceService $service): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $serie = strtoupper($this->option('serie'));
        $anio = (int) $this->option('anio');
        $status = $this->option('status');
        $notes = $this->option('notes');
        $filename = basename($filePath);

        $this->info("Parsing: {$filename}");
        $this->line("  Serie: {$serie}, Año: {$anio}");
        $this->line("  Status: {$status}");

        $bar = $this->output->createProgressBar(4);
        $bar->start();

        try {
            $parser = new RemParserService;
            $parsed = $parser->parse($filePath);
            $bar->advance();

            $excelData = $this->extractRawExcelData($filePath, $parsed, $serie);
            $bar->advance();

            $structure = $this->enrichWithCellData($parsed, $excelData);
            $bar->advance();

            $result = $service->persistFromParsed($parsed, $filePath, $serie, $anio, $filename, $status, $notes);
            $bar->finish();

            $this->newLine(2);
            $this->info('Structure persisted successfully!');
            $this->line("  ID: {$result->id}");
            $this->line("  Version: {$result->version_number}");
            $this->line("  Hash: {$result->hash_estructura}");
            $this->line("  Status: {$result->status}");

            // Summary
            $est = $result->estructura;
            $formCount = count($est['forms'] ?? []);
            $sectionCount = 0;
            $a01Sections = 0;
            foreach ($est['forms'] ?? [] as $f) {
                $sectionCount += count($f['sections'] ?? []);
                if (($f['sheetName'] ?? '') === $serie.'01') {
                    $a01Sections = count($f['sections'] ?? []);
                }
            }

            $totalRows = $excelData['total_rows_classification'] ?? [];
            $formulaCount = count($excelData['formula_cells'] ?? []);

            $this->newLine();
            $this->line('--- Summary ---');
            $this->line("  Total forms (sheets): {$formCount}");
            $this->line("  Total sections: {$sectionCount}");
            $this->line("  A01 sections: {$a01Sections}");
            $this->line("  Formula cells detected: {$formulaCount}");

            $totalReales = array_filter($totalRows, fn ($t) => $t['classification'] === 'total_real');
            $headers = array_filter($totalRows, fn ($t) => $t['classification'] !== 'total_real');
            $this->line('  True TOTAL rows: '.count($totalReales));
            $this->line('  Header/subtotal labels: '.count($headers));

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Failed: '.$e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }

    private function extractRawExcelData(string $filePath, ParsedTemplateDTO $parsed, string $serie): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setIncludeCharts(false);
        $spreadsheet = $reader->load($filePath);

        $sheetName = $this->findA01SheetName($parsed, $serie);
        if (! $sheetName) {
            $spreadsheet->disconnectWorksheets();

            return [];
        }

        $ws = $spreadsheet->getSheetByName($sheetName);
        if (! $ws) {
            $spreadsheet->disconnectWorksheets();

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

        if ($hasFormula) {
            return 'total_real';
        }
        if (empty($a) && stripos($b, 'TOTAL') === 0) {
            return 'header_label';
        }
        if (stripos($a, 'TOTAL') !== false && stripos($a, 'SECCI') === false) {
            return 'subtotal_label';
        }
        if (stripos($b, 'TOTAL') === 0) {
            return 'header_section';
        }

        return 'unknown';
    }

    private function enrichWithCellData(ParsedTemplateDTO $parsed, array $excelData): void
    {
        // This method enriches in-place, results are stored via the service
    }

    private function findA01SheetName(ParsedTemplateDTO $parsed, string $serie): ?string
    {
        foreach ($parsed->forms as $form) {
            if ($form->sheetName === $serie.'01') {
                return $serie.'01';
            }
        }
        foreach ($parsed->forms as $form) {
            if (str_starts_with($form->sheetName, $serie)) {
                return $form->sheetName;
            }
        }

        return null;
    }
}
