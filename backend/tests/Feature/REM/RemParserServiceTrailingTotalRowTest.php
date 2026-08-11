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
 * Cubre isTrailingTotalRow() en RemParserService -- proteccion de pipeline
 * (independiente del fix estructural de SectionDetectorService, que solo
 * aplica a hojas cuya estructura se vuelve a parchear, ej. A31). Esta
 * proteccion excluye la fila TOTAL final de rem_data incluso cuando la
 * estructura activa de la seccion TODAVIA la incluye dentro de su rango
 * declarado -- caso real de A01, A23, A26, A28, A29 (2026-08-10):
 * confirmado que sin este fix, 25 filas TOTAL final ya se persisten como
 * fantasma en cada carga real de esas 5 hojas, ya cerradas y sin reabrir.
 *
 * Cada seccion sintetica replica el patron estructural real encontrado en:
 * - A31A: fila 28  ("TOTAL", formula compuesta: subtotal propio + agregacion hacia atras)
 * - A31B: fila 46  ("TOTAL", SUM simple hacia atras)
 * - A01D: fila 74  ("TOTAL", SUM simple hacia atras, patron identico a A31B/C)
 * - A23L: fila 130 ("TOTAL", formula compuesta, patron identico a A31A/D)
 * - A26H: fila 120 ("TOTAL", SUM simple hacia atras)
 * - A28A3: fila 61 ("TOTAL", SUM simple hacia atras)
 * - A29E: fila 142 ("TOTAL", SUM simple hacia atras)
 * - NEG: caso negativo -- ultima fila con formula (subtotal de su propia
 *   fila) que SI es un dato real, sin ninguna referencia hacia atras.
 */
class RemParserServiceTrailingTotalRowTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJATOT';
    private const YEAR = 2096;
    private const REM_TYPE = 'F';

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

    /**
     * @param array<string,array{start:int,end:int}> $sections codigo => rango [filaInicioDatos, filaFinDatos]
     *   (filaFinDatos INCLUYE la fila TOTAL final -- estructura NO reabierta, igual que A01/A23/A26/A28/A29 reales)
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
            'hash_estructura' => sha1('test-structure-trailing-total'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => ['forms' => [['sheetName' => self::SHEET, 'sections' => $secciones]]],
        ]);
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null): array
    {
        return [
            'valor_bruto' => null,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);

        // A31A: fila 28, formula COMPUESTA (subtotal propio B=+C28+D28,
        // agregacion hacia atras C/D=SUM(...)) -- patron real A31/A y D
        $svc->saveCellData(self::SHEET, 'A31A', [
            'A26' => $this->cell(false, true),
            'B26' => $this->cell(false, true, true, '=+C26+D26'),
            'C26' => $this->cell(true, false),
            'D26' => $this->cell(true, false),
            'A27' => $this->cell(false, true),
            'B27' => $this->cell(false, true, true, '=+C27+D27'),
            'C27' => $this->cell(true, false),
            'D27' => $this->cell(true, false),
            'A28' => $this->cell(false, true),
            'B28' => $this->cell(false, true, true, '=+C28+D28'),
            'C28' => $this->cell(false, true, true, '=SUM(C26:C27)'),
            'D28' => $this->cell(false, true, true, '=SUM(D26:D27)'),
        ]);

        // A31B: fila 46, SUM simple hacia atras -- patron real A31/B y C
        $svc->saveCellData(self::SHEET, 'A31B', [
            'A44' => $this->cell(false, true),
            'B44' => $this->cell(true, false),
            'A45' => $this->cell(false, true),
            'B45' => $this->cell(true, false),
            'A46' => $this->cell(false, true),
            'B46' => $this->cell(false, true, true, '=SUM(B44:B45)'),
        ]);

        // A01D: mismo patron que A31B, seccion/hoja distinta
        $svc->saveCellData(self::SHEET, 'A01D', [
            'A72' => $this->cell(false, true),
            'B72' => $this->cell(true, false),
            'A73' => $this->cell(false, true),
            'B73' => $this->cell(true, false),
            'A74' => $this->cell(false, true),
            'B74' => $this->cell(false, true, true, '=SUM(B72:B73)'),
        ]);

        // A23L: mismo patron que A31A (formula compuesta)
        $svc->saveCellData(self::SHEET, 'A23L', [
            'A128' => $this->cell(false, true),
            'B128' => $this->cell(false, true, true, '=+C128+D128'),
            'C128' => $this->cell(true, false),
            'D128' => $this->cell(true, false),
            'A129' => $this->cell(false, true),
            'B129' => $this->cell(false, true, true, '=+C129+D129'),
            'C129' => $this->cell(true, false),
            'D129' => $this->cell(true, false),
            'A130' => $this->cell(false, true),
            'B130' => $this->cell(false, true, true, '=+C130+D130'),
            'C130' => $this->cell(false, true, true, '=SUM(C128:C129)'),
            'D130' => $this->cell(false, true, true, '=SUM(D128:D129)'),
        ]);

        // A26H: mismo patron que A31B (SUM simple)
        $svc->saveCellData(self::SHEET, 'A26H', [
            'A118' => $this->cell(false, true),
            'B118' => $this->cell(true, false),
            'A119' => $this->cell(false, true),
            'B119' => $this->cell(true, false),
            'A120' => $this->cell(false, true),
            'B120' => $this->cell(false, true, true, '=SUM(B118:B119)'),
        ]);

        // A28A3: mismo patron que A31B (SUM simple)
        $svc->saveCellData(self::SHEET, 'A28A3', [
            'A59' => $this->cell(false, true),
            'B59' => $this->cell(true, false),
            'A60' => $this->cell(false, true),
            'B60' => $this->cell(true, false),
            'A61' => $this->cell(false, true),
            'B61' => $this->cell(false, true, true, '=SUM(B59:B60)'),
        ]);

        // A29E: mismo patron que A31B (SUM simple)
        $svc->saveCellData(self::SHEET, 'A29E', [
            'A140' => $this->cell(false, true),
            'B140' => $this->cell(true, false),
            'A141' => $this->cell(false, true),
            'B141' => $this->cell(true, false),
            'A142' => $this->cell(false, true),
            'B142' => $this->cell(false, true, true, '=SUM(B140:B141)'),
        ]);

        // NEG: caso negativo -- ultima fila (13) SI es dato real: su unica
        // formula es un subtotal de su PROPIA fila, sin ninguna referencia
        // hacia atras. No debe excluirse.
        $svc->saveCellData(self::SHEET, 'NEG', [
            'A11' => $this->cell(false, true),
            'B11' => $this->cell(false, true, true, '=+C11+D11'),
            'C11' => $this->cell(true, false),
            'D11' => $this->cell(true, false),
            'A12' => $this->cell(false, true),
            'B12' => $this->cell(false, true, true, '=+C12+D12'),
            'C12' => $this->cell(true, false),
            'D12' => $this->cell(true, false),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // A31A (26-28)
        $sheet->setCellValue('A26', 'Item 1');
        $sheet->setCellValue('C26', 5);
        $sheet->setCellValue('D26', 3);
        $sheet->setCellValue('A27', 'Item 2');
        $sheet->setCellValue('C27', 2);
        $sheet->setCellValue('D27', 1);
        $sheet->setCellValue('A28', 'TOTAL');

        // A31B (44-46)
        $sheet->setCellValue('A44', 'Item 1');
        $sheet->setCellValue('B44', 8);
        $sheet->setCellValue('A45', 'Item 2');
        $sheet->setCellValue('B45', 4);
        $sheet->setCellValue('A46', 'TOTAL');

        // A01D (72-74)
        $sheet->setCellValue('A72', 'Item 1');
        $sheet->setCellValue('B72', 6);
        $sheet->setCellValue('A73', 'Item 2');
        $sheet->setCellValue('B73', 2);
        $sheet->setCellValue('A74', 'TOTAL');

        // A23L (128-130)
        $sheet->setCellValue('A128', 'Item 1');
        $sheet->setCellValue('C128', 5);
        $sheet->setCellValue('D128', 3);
        $sheet->setCellValue('A129', 'Item 2');
        $sheet->setCellValue('C129', 2);
        $sheet->setCellValue('D129', 1);
        $sheet->setCellValue('A130', 'TOTAL');

        // A26H (118-120)
        $sheet->setCellValue('A118', 'Item 1');
        $sheet->setCellValue('B118', 3);
        $sheet->setCellValue('A119', 'Item 2');
        $sheet->setCellValue('B119', 7);
        $sheet->setCellValue('A120', 'TOTAL');

        // A28A3 (59-61)
        $sheet->setCellValue('A59', 'Item 1');
        $sheet->setCellValue('B59', 1);
        $sheet->setCellValue('A60', 'Item 2');
        $sheet->setCellValue('B60', 9);
        $sheet->setCellValue('A61', 'TOTAL');

        // A29E (140-142)
        $sheet->setCellValue('A140', 'Item 1');
        $sheet->setCellValue('B140', 4);
        $sheet->setCellValue('A141', 'Item 2');
        $sheet->setCellValue('B141', 4);
        $sheet->setCellValue('A142', 'TOTAL');

        // NEG (11-12)
        $sheet->setCellValue('A11', 'Item 1');
        $sheet->setCellValue('C11', 5);
        $sheet->setCellValue('D11', 3);
        $sheet->setCellValue('A12', 'Item 2 (ultimo dato real)');
        $sheet->setCellValue('C12', 2);
        $sheet->setCellValue('D12', 1);

        $path = storage_path('app/rem-uploads/test_trailing_total_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function parseUpload(): array
    {
        $this->createActiveStructure([
            'A31A' => ['start' => 26, 'end' => 28],
            'A31B' => ['start' => 44, 'end' => 46],
            'A01D' => ['start' => 72, 'end' => 74],
            'A23L' => ['start' => 128, 'end' => 130],
            'A26H' => ['start' => 118, 'end' => 120],
            'A28A3' => ['start' => 59, 'end' => 61],
            'A29E' => ['start' => 140, 'end' => 142],
            'NEG' => ['start' => 11, 'end' => 12],
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
                'name' => 'Centro Test Total Final',
                'code_deis' => 'CTF' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_trailing.xlsx',
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

    public function test_a31_pattern_a_composite_formula_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(28, $byRow['A31A'] ?? [], 'A31A fila 28 (patron A31/A: formula compuesta) no debe persistirse');
        $this->assertArrayHasKey(26, $byRow['A31A'] ?? []);
        $this->assertArrayHasKey(27, $byRow['A31A'] ?? []);
    }

    public function test_a31_pattern_b_simple_sum_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(46, $byRow['A31B'] ?? [], 'A31B fila 46 (patron A31/B y C: SUM simple) no debe persistirse');
        $this->assertArrayHasKey(44, $byRow['A31B'] ?? []);
    }

    public function test_a01_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(74, $byRow['A01D'] ?? [], 'A01/D fila 74 no debe persistirse');
    }

    public function test_a23_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(130, $byRow['A23L'] ?? [], 'A23/L fila 130 no debe persistirse');
    }

    public function test_a26_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(120, $byRow['A26H'] ?? [], 'A26/H fila 120 no debe persistirse');
    }

    public function test_a28_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(61, $byRow['A28A3'] ?? [], 'A28/A.3 fila 61 no debe persistirse');
    }

    public function test_a29_trailing_total_is_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(142, $byRow['A29E'] ?? [], 'A29/E fila 142 no debe persistirse');
    }

    public function test_last_row_with_only_own_row_formula_is_still_persisted_as_real_data(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(12, $byRow['NEG'] ?? [], 'la ultima fila (12), sin ninguna referencia hacia atras, es dato real y debe persistirse');
        $this->assertArrayHasKey(11, $byRow['NEG'] ?? []);
    }

    public function test_no_active_structure_is_modified_by_this_fix(): void
    {
        // Esta proteccion vive unicamente en RemParserService::parseSheet()
        // -- no toca rem_template_structures. Verificacion explicita: la
        // estructura activa sigue declarando filaFinDatos=28/46/... (sin
        // reabrir), y aun asi las filas TOTAL no se persisten.
        $this->parseUpload();

        $active = RemTemplateStructure::where('status', 'active')->first();
        $estructura = is_string($active->estructura) ? json_decode($active->estructura, true) : $active->estructura;
        $seccionA31A = collect($estructura['forms'][0]['sections'])->firstWhere('codigo', 'A31A');

        $this->assertSame(28, $seccionA31A['filaFinDatos'], 'la estructura activa NO debe modificarse -- sigue incluyendo la fila TOTAL en su rango declarado');
    }
}
