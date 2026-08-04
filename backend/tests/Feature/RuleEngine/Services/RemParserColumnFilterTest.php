<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Services\RemParserService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RemParserColumnFilterTest extends TestCase
{
    use RefreshDatabase;

    private function createRemTemplate(): RemTemplate
    {
        return RemTemplate::create([
            'rem_type' => 'TEST',
            'year' => 2026,
            'version' => '1.0',
            'config' => [
                'sheets' => [
                    [
                        'sheet_name' => 'Sheet1',
                        'section_code' => 'S1',
                        'is_required' => true,
                        'structure' => [
                            'header_row' => 3,
                            'data_start_row' => 5,
                            'data_end_row' => 25,
                            'concept_column' => 'A',
                            'professional_column' => 'B',
                            'total_column' => 'C',
                            'max_data_rows' => 1500,
                        ],
                        'columns' => [
                            ['letter' => 'B', 'header' => 'Professional'],
                            ['letter' => 'C', 'header' => 'Total'],
                            ['letter' => 'D', 'header' => 'Data1'],
                            ['letter' => 'E', 'header' => 'Data2'],
                        ],
                        'validation_rules' => [
                            'data_type' => 'integer',
                            'min' => 0,
                            'max' => null,
                            'allow_null' => true,
                            'allow_empty_string' => true,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createSpreadsheetWithTextInB(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        // Header row
        $sheet->setCellValue('A3', 'CONCEPTO');
        $sheet->setCellValue('B3', 'PROFESIONAL');
        $sheet->setCellValue('C3', 'TOTAL');
        $sheet->setCellValue('D3', 'DATA1');
        $sheet->setCellValue('E3', 'DATA2');

        // Row 5: concept + professional label + numeric data
        $sheet->setCellValue('A5', 'Control de salud');
        $sheet->setCellValue('B5', 'Médico/a');
        $sheet->setCellValue('C5', 10);
        $sheet->setCellValue('D5', 5);
        $sheet->setCellValue('E5', 3);

        // Row 6: only label columns, no numeric data (should not generate errors)
        $sheet->setCellValue('A6', 'Control de salud');
        $sheet->setCellValue('B6', 'Enfermera/o');
        $sheet->setCellValue('C6', 0);
        $sheet->setCellValue('D6', null);
        $sheet->setCellValue('E6', null);

        // Row 7: text in E (numeric column) — SHOULD generate error
        $sheet->setCellValue('A7', 'Control de salud');
        $sheet->setCellValue('B7', 'Matrona/ón');
        $sheet->setCellValue('C7', 5);
        $sheet->setCellValue('D7', 2);
        $sheet->setCellValue('E7', 'NO_NUMERIC');

        $path = storage_path('app/rem-uploads/test_column_b_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function createHealthCenter(): HealthCenter
    {
        return HealthCenter::create([
            'name' => 'Test Center',
            'code_deis' => 'TC' . uniqid(),
            'type' => 'CESFAM',
        ]);
    }

    public function test_text_in_column_b_does_not_generate_parse_error(): void
    {
        $template = $this->createRemTemplate();
        $storedPath = $this->createSpreadsheetWithTextInB();

        $upload = RemUpload::create([
            'rem_type' => 'TEST',
            'year' => 2026,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => $this->createHealthCenter()->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);

        $parser = app(RemParserService::class);
        $result = $parser->parse($upload);

        $this->assertNotEmpty($result->extractedData, 'Data should be extracted');

        $errorList = $result->errors['errors'] ?? [];

        $columnBErrors = array_values(array_filter($errorList, fn($e) => ($e['column'] ?? '') === 'B'));

        $this->assertCount(0, $columnBErrors, 'Column B should not generate parse errors for text values');

        $nonColumnBDataErrors = array_values(array_filter($errorList, fn($e) => ($e['column'] ?? '') !== 'B'));

        $this->assertNotEmpty($nonColumnBDataErrors, 'Text in data column E should still generate errors');

        $foundNonNumericInData = !empty(array_filter($nonColumnBDataErrors, fn($e) => ($e['column'] ?? '') === 'E'));
        $this->assertTrue($foundNonNumericInData, 'Numeric data column E should still produce validation errors for non-numeric data');
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $testFiles = glob(storage_path('app/rem-uploads/test_column_b_*.xlsx'));
        foreach ($testFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }
}
