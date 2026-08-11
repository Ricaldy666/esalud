<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Services\RemParserService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cubre isEmbeddedBackwardSubtotalRow() en RemParserService -- Hallazgo 4
 * (A32/F2 fila 140, 2026-08-10): un subtotal EMBEBIDO que agrega hacia
 * atras, en CUALQUIER posicion dentro del rango de la seccion (no solo al
 * cierre, a diferencia del mecanismo #8/isTrailingTotalRow), con su propia
 * etiqueta "TOTAL" viviendo en una columna que puede no ser la columna de
 * concepto fija de la seccion (por fusion vertical, ej. A130:A140 hereda
 * el concepto del bloque, dejando la fila 140 con A vacia y "TOTAL" en B).
 * Aprobada Opcion C: excluir de rem_data y patrones, conservar como nodo
 * tecnico en cell-data (ya sucede automaticamente -- la fila cae dentro
 * del rango normal de escaneo).
 *
 * Confirmado tambien vigente, sin reabrir su estructura, en A26/A.1 fila
 * 41 y A26/B fila 59 (70 ocurrencias fantasma cada una en rem_data
 * historico).
 */
class RemParserServiceEmbeddedBackwardSubtotalRowTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJASUB';
    private const YEAR = 2095;
    private const REM_TYPE = 'G';

    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function fields(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false],
            ['letra' => 'B', 'label' => 'SubConcepto', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Data1', 'esTotal' => false],
            ['letra' => 'D', 'label' => 'Data2', 'esTotal' => false],
        ];
    }

    private function createTemplate(): RemTemplate
    {
        return RemTemplate::create([
            'rem_type' => self::REM_TYPE,
            'year' => self::YEAR,
            'version' => '1.0',
            'config' => [
                'sheets' => [
                    [
                        'sheet_name' => self::SHEET,
                        'section_code' => self::SHEET,
                        'is_required' => true,
                        'structure' => [
                            'header_row' => 3,
                            'data_start_row' => 11,
                            'concept_column' => 'A',
                            'professional_column' => null,
                            'total_column' => null,
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Concepto'],
                            ['letter' => 'B', 'header' => 'SubConcepto'],
                            ['letter' => 'C', 'header' => 'Data1'],
                            ['letter' => 'D', 'header' => 'Data2'],
                        ],
                        'validation_rules' => [
                            'data_type' => 'integer',
                            'min' => 0,
                            'max' => null,
                            'allow_null' => true,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string,array{start:int,end:int}> $sections
     */
    private function createActiveStructure(array $sections): void
    {
        $secciones = [];
        foreach ($sections as $codigo => $rango) {
            $secciones[] = ['codigo' => $codigo, 'filaInicioDatos' => $rango['start'], 'filaFinDatos' => $rango['end'], 'fields' => $this->fields()];
        }

        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-embedded-backward-subtotal'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => ['forms' => [['sheetName' => self::SHEET, 'sections' => $secciones]]],
        ]);
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $valorBruto = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);

        // A32F2: patron real -- concepto de bloque fusionado A11:A20 (A20
        // vacia), "TOTAL" en B20, agrega C11:C19/D11:D19, seguida de un
        // SEGUNDO bloque real (21-30) antes del TOTAL final (31, ya
        // excluido por el mecanismo #8/isTrailingTotalRow).
        $celdas = [
            'A11' => $this->cell(false, true, false, null, 'Grupo Medico'),
            'B11' => $this->cell(false, true, false, null, 'Médico/a'),
            'C11' => $this->cell(true, false),
            'D11' => $this->cell(true, false),
            'B12' => $this->cell(false, true, false, null, 'Psicólogo/a'),
            'C12' => $this->cell(true, false),
            'D12' => $this->cell(true, false),
            // fila 20: subtotal embebido -- A vacia (fusion), "TOTAL" en B
            'B20' => $this->cell(false, true, false, null, 'TOTAL'),
            'C20' => $this->cell(false, true, true, '=SUM(C11:C19)'),
            'D20' => $this->cell(false, true, true, '=SUM(D11:D19)'),
            // segundo bloque real, despues del subtotal embebido
            'A21' => $this->cell(false, true, false, null, 'Grupo Videollamada'),
            'B21' => $this->cell(false, true, false, null, 'Médico/a'),
            'C21' => $this->cell(true, false),
            'D21' => $this->cell(true, false),
            'B22' => $this->cell(false, true, false, null, 'Psicólogo/a'),
            'C22' => $this->cell(true, false),
            'D22' => $this->cell(true, false),
            // TOTAL final (mecanismo #8), fila 30
            'A30' => $this->cell(false, true, false, null, 'TOTAL'),
            'C30' => $this->cell(false, true, true, '=SUM(C21:C22)'),
            'D30' => $this->cell(false, true, true, '=SUM(D21:D22)'),
        ];
        $svc->saveCellData(self::SHEET, 'A32F2', $celdas);

        // A26A1: subtotal embebido con concepto en columna A ("TOTAL"), no
        // fusionada -- patron real de A26/A.1 fila 41.
        $svc->saveCellData(self::SHEET, 'A26A1', [
            'A40' => $this->cell(false, true, false, null, 'Item 1'),
            'C40' => $this->cell(true, false),
            'A41' => $this->cell(false, true, false, null, 'TOTAL'),
            'C41' => $this->cell(false, true, true, '=SUM(C40:C40)'),
            'A42' => $this->cell(false, true, false, null, 'Item 2 (bloque nuevo)'),
            'C42' => $this->cell(true, false),
        ]);

        // A26B: subtotal embebido con formula compuesta (referencia otra
        // fila anterior FUERA del bloque inmediato, pero dentro de la
        // seccion) -- patron real de A26/B fila 59 ("=SUM(C54:C58)+C50").
        $svc->saveCellData(self::SHEET, 'A26B', [
            'A49' => $this->cell(false, true, false, null, 'Item base'),
            'C49' => $this->cell(true, false),
            'A50' => $this->cell(false, true, false, null, 'Item 1'),
            'C50' => $this->cell(true, false),
            'A51' => $this->cell(false, true, false, null, 'TOTAL'),
            'C51' => $this->cell(false, true, true, '=SUM(C49:C50)'),
            'A52' => $this->cell(false, true, false, null, 'Item 2 (bloque nuevo)'),
            'C52' => $this->cell(true, false),
        ]);

        // A09I: caso negativo real -- "Altas administrativas" es un dato
        // derivado/calculado LEGITIMO (formulas hacia atras genuinas),
        // pero su etiqueta NO dice "TOTAL" -- nunca debe excluirse.
        $svc->saveCellData(self::SHEET, 'A09I', [
            'A60' => $this->cell(false, true, false, null, 'Ingreso a tratamiento'),
            'C60' => $this->cell(true, false),
            'A61' => $this->cell(false, true, false, null, 'Altas administrativas'),
            'C61' => $this->cell(false, true, true, '=SUM(C60:C60)'),
        ]);

        // NEG_REAL: fila final con formula, pero es dato real (subtotal de
        // su propia fila unicamente, sin referencia hacia atras).
        $svc->saveCellData(self::SHEET, 'NEGREAL', [
            'A70' => $this->cell(false, true, false, null, 'Item 1'),
            'C70' => $this->cell(false, true, true, '=SUM(C70:C70)'),
        ]);

        // NEG_SINTOTAL: subtotal real hacia atras pero SIN texto "TOTAL" en
        // ninguna columna -- no hay evidencia de etiqueta, no debe
        // excluirse (regla estricta: se requiere concepto TOTAL/equivalente).
        $svc->saveCellData(self::SHEET, 'NEGSINTOTAL', [
            'A80' => $this->cell(false, true, false, null, 'Item 1'),
            'C80' => $this->cell(true, false),
            'C81' => $this->cell(false, true, true, '=SUM(C80:C80)'),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // A32F2 (11-30)
        $sheet->setCellValue('A11', 'Grupo Medico');
        $sheet->setCellValue('B11', 'Médico/a');
        $sheet->setCellValue('C11', 5);
        $sheet->setCellValue('D11', 3);
        $sheet->setCellValue('B12', 'Psicólogo/a');
        $sheet->setCellValue('C12', 2);
        $sheet->setCellValue('D12', 1);
        $sheet->setCellValue('B20', 'TOTAL');
        $sheet->setCellValue('C20', '=SUM(C11:C19)');
        $sheet->setCellValue('D20', '=SUM(D11:D19)');
        $sheet->setCellValue('A21', 'Grupo Videollamada');
        $sheet->setCellValue('B21', 'Médico/a');
        $sheet->setCellValue('C21', 4);
        $sheet->setCellValue('D21', 2);
        $sheet->setCellValue('B22', 'Psicólogo/a');
        $sheet->setCellValue('C22', 1);
        $sheet->setCellValue('D22', 1);
        $sheet->setCellValue('A30', 'TOTAL');
        $sheet->setCellValue('C30', '=SUM(C21:C22)');
        $sheet->setCellValue('D30', '=SUM(D21:D22)');

        // A26A1 (40-42)
        $sheet->setCellValue('A40', 'Item 1');
        $sheet->setCellValue('C40', 6);
        $sheet->setCellValue('A41', 'TOTAL');
        $sheet->setCellValue('C41', '=SUM(C40:C40)');
        $sheet->setCellValue('A42', 'Item 2 (bloque nuevo)');
        $sheet->setCellValue('C42', 3);

        // A26B (49-52)
        $sheet->setCellValue('A49', 'Item base');
        $sheet->setCellValue('C49', 2);
        $sheet->setCellValue('A50', 'Item 1');
        $sheet->setCellValue('C50', 5);
        $sheet->setCellValue('A51', 'TOTAL');
        $sheet->setCellValue('C51', '=SUM(C49:C50)');
        $sheet->setCellValue('A52', 'Item 2 (bloque nuevo)');
        $sheet->setCellValue('C52', 7);

        // A09I (60-61)
        $sheet->setCellValue('A60', 'Ingreso a tratamiento');
        $sheet->setCellValue('C60', 9);
        $sheet->setCellValue('A61', 'Altas administrativas');
        $sheet->setCellValue('C61', '=SUM(C60:C60)');

        // NEGREAL (70)
        $sheet->setCellValue('A70', 'Item 1');
        $sheet->setCellValue('C70', '=SUM(C70:C70)');

        // NEGSINTOTAL (80-81)
        $sheet->setCellValue('A80', 'Item 1');
        $sheet->setCellValue('C80', 4);
        $sheet->setCellValue('C81', '=SUM(C80:C80)');

        $path = storage_path('app/rem-uploads/test_embedded_backward_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function parseUpload(): array
    {
        $this->createActiveStructure([
            'A32F2' => ['start' => 11, 'end' => 30],
            'A26A1' => ['start' => 40, 'end' => 42],
            'A26B' => ['start' => 49, 'end' => 52],
            'A09I' => ['start' => 60, 'end' => 61],
            'NEGREAL' => ['start' => 70, 'end' => 70],
            'NEGSINTOTAL' => ['start' => 80, 'end' => 81],
        ]);
        $this->seedCellData();
        $template = $this->createTemplate();
        $storedPath = $this->buildSpreadsheet();

        $upload = RemUpload::create([
            'rem_type' => self::REM_TYPE,
            'year' => self::YEAR,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Test Subtotal Embebido',
                'code_deis' => 'CTS' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_embedded_backward.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);

        $parser = app(RemParserService::class);
        $result = $parser->parse($upload);

        $byRow = [];
        foreach ($result->extractedData as $entry) {
            $byRow[$entry['rem_section_code']][$entry['row_number']] = $entry;
        }

        return [$upload, $result, $byRow];
    }

    public function test_a32_f2_pattern_embedded_subtotal_and_final_total_are_both_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(20, $byRow['A32F2'] ?? [], 'fila 20 (subtotal embebido, patron A32/F2 fila 140) no debe persistirse');
        $this->assertArrayNotHasKey(30, $byRow['A32F2'] ?? [], 'fila 30 (TOTAL final, mecanismo #8) no debe persistirse');
        $this->assertArrayHasKey(11, $byRow['A32F2'] ?? []);
        $this->assertArrayHasKey(12, $byRow['A32F2'] ?? []);
        $this->assertArrayHasKey(21, $byRow['A32F2'] ?? [], 'el segundo bloque (despues del subtotal embebido) debe seguir persistiendo');
        $this->assertArrayHasKey(22, $byRow['A32F2'] ?? []);
    }

    public function test_a26_a1_pattern_embedded_subtotal_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(41, $byRow['A26A1'] ?? [], 'fila 41 (patron A26/A.1) no debe persistirse');
        $this->assertArrayHasKey(40, $byRow['A26A1'] ?? []);
        $this->assertArrayHasKey(42, $byRow['A26A1'] ?? []);
    }

    public function test_a26_b_pattern_embedded_subtotal_with_compound_formula_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(51, $byRow['A26B'] ?? [], 'fila 51 (patron A26/B, formula compuesta =SUM(C49:C50)) no debe persistirse');
        $this->assertArrayHasKey(49, $byRow['A26B'] ?? []);
        $this->assertArrayHasKey(50, $byRow['A26B'] ?? []);
        $this->assertArrayHasKey(52, $byRow['A26B'] ?? []);
    }

    public function test_a09_i_style_derived_data_without_total_label_is_still_persisted(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(61, $byRow['A09I'] ?? [], '"Altas administrativas" es un dato real derivado, no un TOTAL -- debe persistirse pese a su formula hacia atras');
    }

    public function test_real_data_row_with_own_row_formula_only_is_still_persisted(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(70, $byRow['NEGREAL'] ?? [], 'fila con formula de su propia fila unicamente (sin referencia hacia atras) es dato real');
    }

    public function test_backward_formula_without_total_label_anywhere_is_not_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(81, $byRow['NEGSINTOTAL'] ?? [], 'formula hacia atras sin ninguna etiqueta TOTAL en la fila no es evidencia suficiente -- no debe excluirse');
    }
}
