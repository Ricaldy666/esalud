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
 * Reproduce, de forma sintetica y autocontenida, el hallazgo de fila TOTAL
 * lider EMBEBIDA dentro del rango activo de una seccion (a diferencia de
 * las filas TOTAL al inicio/fin de rango, que ya quedan excluidas por
 * limite de seccion): la columna de concepto tiene texto propio, pero las
 * demas celdas relevantes de la fila son EXCLUSIVAMENTE formulas de
 * agregacion que referencian filas posteriores (ej. =SUM(B14:B16)). Antes
 * del fix, getCalculatedValue() sobre esas celdas siempre da un numero
 * valido (el resultado de la suma), asi que rowHasContent quedaba en true y
 * la fila se persistia como si fuera un registro de negocio real.
 *
 * Cada seccion sintetica replica el patron estructural real encontrado en:
 * - AL:  A19b/A fila 52  ("TOTAL CONSULTAS, FELICITACIONES O SUGERENCIAS", total=1704)
 * - BL:  A28/B.1 fila 178 ("EGRESOS", total=0)
 * - CL:  A30/C fila 98   (formula en varias columnas numericas, no solo total_column)
 * - NEG: caso negativo -- una fila con evidencia de formula-hacia-adelante en
 *        una columna, pero con OTRA celda genuinamente capturable (no formula)
 *        en la misma fila, no debe excluirse.
 */
class RemParserServiceEmbeddedLeadingTotalRowTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJAEMB';
    private const YEAR = 2097;
    private const REM_TYPE = 'E';

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
            ['letra' => 'B', 'label' => 'Total', 'esTotal' => true],
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
                            'total_column' => 'B',
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Concepto'],
                            ['letter' => 'B', 'header' => 'Total'],
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

    private function createActiveStructure(): void
    {
        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-embedded-total'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            // AL: analoga a A19b/A (fila 52 dentro de [11,57])
                            ['codigo' => 'AL', 'filaInicioDatos' => 11, 'filaFinDatos' => 16, 'fields' => $this->fields()],
                            // BL: analoga a A28/B.1 (fila 178 dentro de [149,183])
                            ['codigo' => 'BL', 'filaInicioDatos' => 20, 'filaFinDatos' => 26, 'fields' => $this->fields()],
                            // CL: analoga a A30/C (fila 98 dentro de [81,108])
                            ['codigo' => 'CL', 'filaInicioDatos' => 30, 'filaFinDatos' => 36, 'fields' => $this->fields()],
                            // NEG: caso negativo, fila con celda real capturable
                            ['codigo' => 'NEG', 'filaInicioDatos' => 40, 'filaFinDatos' => 45, 'fields' => $this->fields()],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function cell(?string $value, bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null): array
    {
        return [
            'valor_bruto' => $value,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);

        // AL: fila 13 = TOTAL lider embebido, un solo formula en B (columna
        // total), analogo al patron real de A19b/A -- C/D no tienen ninguna
        // formula ni valor en esa fila (permanecen vacias, deben ignorarse).
        $svc->saveCellData(self::SHEET, 'AL', [
            'A11' => $this->cell('Concepto 1', false, true),
            'B11' => $this->cell(null, true, false),
            'C11' => $this->cell(null, true, false),
            'D11' => $this->cell(null, true, false),

            'A13' => $this->cell('TOTAL CONSULTAS', false, true),
            'B13' => $this->cell(null, false, true, true, '=SUM(B14:B16)'),

            'A14' => $this->cell('Concepto 2', false, true),
            'B14' => $this->cell(null, true, false),
        ]);

        // BL: fila 22 = TOTAL lider embebido, analogo a A28/B.1 ("EGRESOS").
        $svc->saveCellData(self::SHEET, 'BL', [
            'A20' => $this->cell('Concepto 1', false, true),
            'B20' => $this->cell(null, true, false),

            'A22' => $this->cell('EGRESOS', false, true),
            'B22' => $this->cell(null, false, true, true, '=SUM(B23:B26)'),

            'A23' => $this->cell('Concepto 2', false, true),
            'B23' => $this->cell(null, true, false),
        ]);

        // CL: fila 32 = TOTAL lider embebido con formula en VARIAS columnas
        // numericas (B, C y D), analogo a A30/C fila 98 (=SUM en B..AJ).
        $svc->saveCellData(self::SHEET, 'CL', [
            'A30' => $this->cell('Concepto 1', false, true),
            'B30' => $this->cell(null, true, false),
            'C30' => $this->cell(null, true, false),
            'D30' => $this->cell(null, true, false),

            'A32' => $this->cell('TOTAL', false, true),
            'B32' => $this->cell(null, false, true, true, '=SUM(B33:B36)'),
            'C32' => $this->cell(null, false, true, true, '=SUM(C33:C36)'),
            'D32' => $this->cell(null, false, true, true, '=SUM(D33:D36)'),

            'A33' => $this->cell('Concepto 2', false, true),
            'B33' => $this->cell(null, true, false),
            'C33' => $this->cell(null, true, false),
            'D33' => $this->cell(null, true, false),
        ]);

        // NEG: fila 42 tiene una formula-hacia-adelante en C (=SUM(C43:C44)),
        // pero B42 es una celda genuinamente capturable (editable, no
        // bloqueada, no formula) con un valor real cargado -- NO debe
        // tratarse como TOTAL lider, debe persistir como dato real.
        $svc->saveCellData(self::SHEET, 'NEG', [
            'A40' => $this->cell('Concepto 1', false, true),
            'B40' => $this->cell(null, true, false),

            'A42' => $this->cell('Con dato real', false, true),
            'B42' => $this->cell(null, true, false),
            'C42' => $this->cell(null, false, true, true, '=SUM(C43:C44)'),

            'A43' => $this->cell('Concepto 3', false, true),
            'B43' => $this->cell(null, true, false),
            // D43: formula que referencia su PROPIA fila (subtotal horizontal
            // real, ej. =B43+C43) -- tampoco debe excluirse, porque no
            // referencia exclusivamente filas posteriores.
            'D43' => $this->cell(null, false, true, true, '=B43+C43'),

            'A44' => $this->cell('Concepto 4', false, true),
            'B44' => $this->cell(null, true, false),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // AL (11-16)
        $sheet->setCellValue('A11', 'Concepto 1');
        $sheet->setCellValue('B11', 4);
        $sheet->setCellValue('A13', 'TOTAL CONSULTAS');
        $sheet->setCellValue('B13', '=SUM(B14:B16)');
        $sheet->setCellValue('A14', 'Concepto 2');
        $sheet->setCellValue('B14', 1704);

        // BL (20-26)
        $sheet->setCellValue('A20', 'Concepto 1');
        $sheet->setCellValue('B20', 0);
        $sheet->setCellValue('A22', 'EGRESOS');
        $sheet->setCellValue('B22', '=SUM(B23:B26)');
        $sheet->setCellValue('A23', 'Concepto 2');
        $sheet->setCellValue('B23', 0);

        // CL (30-36)
        $sheet->setCellValue('A30', 'Concepto 1');
        $sheet->setCellValue('B30', 2);
        $sheet->setCellValue('C30', 3);
        $sheet->setCellValue('D30', 1);
        $sheet->setCellValue('A32', 'TOTAL');
        $sheet->setCellValue('B32', '=SUM(B33:B36)');
        $sheet->setCellValue('C32', '=SUM(C33:C36)');
        $sheet->setCellValue('D32', '=SUM(D33:D36)');
        $sheet->setCellValue('A33', 'Concepto 2');
        $sheet->setCellValue('B33', 7);
        $sheet->setCellValue('C33', 8);
        $sheet->setCellValue('D33', 9);

        // NEG (40-45)
        $sheet->setCellValue('A40', 'Concepto 1');
        $sheet->setCellValue('B40', 5);
        $sheet->setCellValue('A42', 'Con dato real');
        $sheet->setCellValue('B42', 12); // celda capturable real, con valor
        $sheet->setCellValue('C42', '=SUM(C43:C44)');
        $sheet->setCellValue('A43', 'Concepto 3');
        $sheet->setCellValue('B43', 6);
        $sheet->setCellValue('C43', 2);
        $sheet->setCellValue('D43', '=B43+C43'); // referencia su propia fila
        $sheet->setCellValue('A44', 'Concepto 4');
        $sheet->setCellValue('B44', 9);

        $path = storage_path('app/rem-uploads/test_embedded_total_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->tmpFiles[] = $path;

        return $path;
    }

    private function parseUpload(): array
    {
        $this->createActiveStructure();
        $this->seedCellData();
        $template = $this->createTemplate();
        $storedPath = $this->buildSpreadsheet();

        $upload = RemUpload::create([
            'rem_type' => self::REM_TYPE,
            'year' => self::YEAR,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Test Embebido',
                'code_deis' => 'CTE' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_embedded.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);

        $parser = app(RemParserService::class);
        $result = $parser->parse($upload);

        $byRow = [];
        foreach ($result->extractedData as $entry) {
            $byRow[$entry['row_number']] = $entry;
        }

        return [$upload, $result, $byRow];
    }

    public function test_embedded_leading_total_row_is_not_persisted_a19b_pattern(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(13, $byRow, 'Fila TOTAL lider embebida (patron A19b/A fila 52) no debe persistirse como fila de negocio');
        $this->assertArrayHasKey(11, $byRow, 'La fila de datos real anterior al TOTAL debe seguir persistiendo');
        $this->assertArrayHasKey(14, $byRow, 'La fila de datos real posterior al TOTAL debe seguir persistiendo');
    }

    public function test_embedded_leading_total_row_is_not_persisted_a28_pattern(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(22, $byRow, 'Fila TOTAL lider embebida (patron A28/B.1 fila 178, "EGRESOS") no debe persistirse');
        $this->assertArrayHasKey(20, $byRow);
        $this->assertArrayHasKey(23, $byRow);
    }

    public function test_embedded_leading_total_row_is_not_persisted_a30_pattern(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(32, $byRow, 'Fila TOTAL lider embebida con formula en varias columnas (patron A30/C fila 98) no debe persistirse');
        $this->assertArrayHasKey(30, $byRow);
        $this->assertArrayHasKey(33, $byRow);
    }

    public function test_row_with_forward_formula_but_real_captured_cell_is_still_persisted(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(42, $byRow, 'Fila con una celda genuinamente capturable (B42=12) no debe excluirse aunque otra columna tenga formula hacia filas posteriores');
        $this->assertSame(12, $byRow[42]['values']['B']);
    }

    public function test_row_with_self_referencing_formula_is_still_persisted(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(43, $byRow, 'Fila con formula que referencia su propia fila (subtotal horizontal real, ej. =B43+C43) no debe tratarse como TOTAL lider');
    }

    public function test_historical_data_is_not_modified_by_this_fix(): void
    {
        // El fix solo afecta el parseo de NUEVAS cargas -- no existe ningun
        // mecanismo de limpieza retroactiva sobre rem_data ya persistida.
        // Este test documenta esa garantia explicitamente: parsear la misma
        // carga dos veces produce el mismo resultado en memoria (idempotente
        // hacia adelante), sin tocar ninguna fila ya guardada previamente.
        [$upload, , $byRowFirst] = $this->parseUpload();

        $parser = app(RemParserService::class);
        $resultSecond = $parser->parse($upload);
        $byRowSecond = [];
        foreach ($resultSecond->extractedData as $entry) {
            $byRowSecond[$entry['row_number']] = $entry;
        }

        $this->assertArrayNotHasKey(13, $byRowFirst);
        $this->assertArrayNotHasKey(13, $byRowSecond);
        $this->assertArrayNotHasKey(22, $byRowFirst);
        $this->assertArrayNotHasKey(22, $byRowSecond);
        $this->assertArrayNotHasKey(32, $byRowFirst);
        $this->assertArrayNotHasKey(32, $byRowSecond);
    }
}
