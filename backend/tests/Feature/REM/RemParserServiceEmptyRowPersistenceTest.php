<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Services\RemParserService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Reproduce, de forma sintetica y autocontenida (sin tocar datos reales de
 * calibracion), el patron de A01/H.1 fila 172 y A01/H.2 fila 199: filas
 * completamente vacias dentro de una seccion valida, con celdas editables
 * reales segun cell_data, que antes del fix se descartaban por completo del
 * parser y por lo tanto nunca podian ser evaluadas por el motor de reglas
 * funcionales.
 */
class RemParserServiceEmptyRowPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJAT';
    private const YEAR = 2099;
    private const REM_TYPE = 'T'; // -> serieFromRemType('T') = 'T'

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
                            'data_start_row' => 5,
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
     * Estructura activa con secciones: A (5-7, sin cell_data -> control),
     * HD1 (170-184, analoga a H.1) y HD2 (187-200, analoga a H.2 ya corregida).
     * Este test NO reproduce el bug de filaFinDatos=null; eso se demuestra
     * aparte contra el upload real. Aqui se prueba el mecanismo del parser
     * ya con rangos correctos.
     */
    private function createActiveStructure(): void
    {
        $fields = [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false],
            ['letra' => 'B', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'C', 'label' => 'Data1', 'esTotal' => false],
            ['letra' => 'D', 'label' => 'Data2', 'esTotal' => false],
        ];

        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            ['codigo' => 'A', 'filaInicioDatos' => 5, 'filaFinDatos' => 7, 'fields' => $fields],
                            ['codigo' => 'HD1', 'filaInicioDatos' => 170, 'filaFinDatos' => 184, 'fields' => $fields],
                            ['codigo' => 'HD2', 'filaInicioDatos' => 187, 'filaFinDatos' => 200, 'fields' => $fields],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function cell(?string $value, bool $editable, bool $blocked, bool $formula = false): array
    {
        return [
            'valor_bruto' => $value,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
        ];
    }

    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);

        // HD1: fila 172 = objetivo vacia con celdas editables reales (analoga a A01/H.1/172)
        //      fila 175 = fila con celdas bloqueadas (sin TOTAL/encabezado) -> no debe persistir
        //      fila 183 = fila TOTAL (bloqueada + formula) -> no debe persistir
        $svc->saveCellData(self::SHEET, 'HD1', [
            'A172' => $this->cell('30 a 34 años', false, true),
            'B172' => $this->cell(null, true, false),
            'C172' => $this->cell(null, true, false),
            'D172' => $this->cell(null, true, false),

            'A175' => $this->cell('Bloqueada', false, true),
            'B175' => $this->cell(null, false, true),
            'C175' => $this->cell(null, false, true),
            'D175' => $this->cell(null, false, true),

            'A183' => $this->cell('TOTAL', false, true),
            'B183' => $this->cell(null, false, true, true),
            'C183' => $this->cell(null, false, true, true),
            'D183' => $this->cell(null, false, true, true),
        ]);

        // HD2: fila 199 = objetivo vacia (analoga a A01/H.2/199)
        //      fila 200 = fila TOTAL
        $svc->saveCellData(self::SHEET, 'HD2', [
            'A199' => $this->cell('80 y más años', false, true),
            'B199' => $this->cell(null, true, false),
            'C199' => $this->cell(null, true, false),
            'D199' => $this->cell(null, true, false),

            'A200' => $this->cell('TOTAL', false, true),
            'B200' => $this->cell(null, false, true, true),
            'C200' => $this->cell(null, false, true, true),
            'D200' => $this->cell(null, false, true, true),
        ]);

        // Nota: la seccion 'A' deliberadamente NO tiene cell_data guardado,
        // para probar que sin evidencia real no se fuerza la persistencia.
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // Seccion A (5-7): filas normales con datos reales -> control de regresion
        $sheet->setCellValue('A5', 'Concepto uno');
        $sheet->setCellValue('B5', 8);
        $sheet->setCellValue('C5', 5);
        $sheet->setCellValue('D5', 3);

        $sheet->setCellValue('A6', 'Concepto uno');
        $sheet->setCellValue('B6', 0);
        $sheet->setCellValue('C6', 0);
        $sheet->setCellValue('D6', 0);

        // A7 se deja completamente vacia a proposito (sin cell_data -> no debe persistir)

        // Fila de encabezado "entre secciones" (fuera de cualquier rango de seccion).
        // Se le da ademas un valor de total (B168) a proposito: antes del fix,
        // total !== null bastaba para persistirla igual que un titulo de seccion
        // real (ej. A02 "SECCION B: ..."), aunque la fila no sea un dato real.
        $sheet->setCellValue('A168', 'RANGO ETARIO');
        $sheet->setCellValue('B168', 42);

        // HD1 (170-184)
        $sheet->setCellValue('A172', '30 a 34 años'); // objetivo: totalmente vacia salvo el rotulo
        $sheet->setCellValue('A175', 'Bloqueada');
        $sheet->setCellValue('A183', 'TOTAL');

        // HD2 (187-200)
        $sheet->setCellValue('A199', '80 y más años'); // objetivo
        $sheet->setCellValue('A200', 'TOTAL');

        $path = storage_path('app/rem-uploads/test_empty_row_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->tmpFiles[] = $path;

        return $path;
    }

    private function createUpload(RemTemplate $template, string $storedPath): RemUpload
    {
        return RemUpload::create([
            'rem_type' => self::REM_TYPE,
            'year' => self::YEAR,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Test',
                'code_deis' => 'CT' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);
    }

    private function parseUpload(): array
    {
        $this->createActiveStructure();
        $this->seedCellData();
        $template = $this->createTemplate();
        $storedPath = $this->buildSpreadsheet();
        $upload = $this->createUpload($template, $storedPath);

        $parser = app(RemParserService::class);
        $result = $parser->parse($upload);

        // Replica la persistencia real de ProcessRemUploadJob (RemParserService
        // solo devuelve el resultado en memoria, no escribe en rem_data).
        foreach ($result->extractedData as $entry) {
            RemData::create([
                'rem_upload_id' => $upload->id,
                'section' => $entry['section'] ?? 'unknown',
                'data' => $entry,
            ]);
        }

        $byRow = [];
        foreach ($result->extractedData as $entry) {
            $byRow[$entry['row_number']] = $entry;
        }

        return [$upload, $result, $byRow];
    }

    public function test_empty_row_in_valid_section_with_editable_cells_is_persisted_hd1(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(172, $byRow, 'La fila 172 (analoga a A01/H.1/172) debe persistirse aunque este vacia');
        $this->assertSame('HD1', $byRow[172]['rem_section_code']);
        $this->assertNull($byRow[172]['total']);
        foreach (['B', 'C', 'D'] as $col) {
            $this->assertArrayHasKey($col, $byRow[172]['values'], "Columna {$col} debe existir en values de la fila 172");
            $this->assertNull($byRow[172]['values'][$col], "Columna {$col} de la fila 172 debe quedar en null");
        }
    }

    public function test_empty_row_in_valid_section_with_editable_cells_is_persisted_hd2(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(199, $byRow, 'La fila 199 (analoga a A01/H.2/199) debe persistirse aunque este vacia');
        $this->assertSame('HD2', $byRow[199]['rem_section_code']);
        $this->assertNull($byRow[199]['total']);
    }

    public function test_persisted_rows_keep_correct_rem_section_code(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertSame('HD1', $byRow[172]['rem_section_code']);
        $this->assertSame('HD2', $byRow[199]['rem_section_code']);
    }

    public function test_header_row_between_sections_is_not_persisted(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(168, $byRow, 'La fila de encabezado entre secciones no debe persistirse');
    }

    public function test_row_outside_any_declared_section_is_never_persisted_even_with_total_value(): void
    {
        // Reproduce el patron detectado en A02: filas de titulo de seccion
        // ("SECCION B: ...") que caian fuera de cualquier rango declarado, pero
        // que antes se persistian igual porque tenian un valor no nulo en alguna
        // columna (aqui, total). La fila 168 del fixture ahora tiene B168=42.
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(
            168,
            $byRow,
            'Una fila fuera de toda seccion declarada no debe persistirse aunque tenga un valor de total'
        );
    }

    /**
     * Reproduce con precision el mecanismo exacto de A02: concept_column y
     * professional_column son la MISMA columna (A). Un titulo de seccion como
     * "SECCION B: ..." queda entonces tanto en concept como en professional,
     * y bajo la condicion antigua (professional !== '') se persistia como si
     * fuera una fila de datos real.
     */
    public function test_title_row_with_concept_equal_to_professional_column_is_not_persisted(): void
    {
        $fields = [
            ['letra' => 'A', 'label' => 'Profesional', 'esTotal' => false],
            ['letra' => 'B', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'C', 'label' => 'Data1', 'esTotal' => false],
        ];

        RemTemplateStructure::create([
            'anio' => 2098,
            'serie' => 'Z',
            'hash_estructura' => sha1('test-structure-a02-pattern'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'HOJAZ',
                        'sections' => [
                            ['codigo' => 'A', 'filaInicioDatos' => 12, 'filaFinDatos' => 17, 'fields' => $fields],
                            ['codigo' => 'B', 'filaInicioDatos' => 21, 'filaFinDatos' => 25, 'fields' => $fields],
                        ],
                    ],
                ],
            ],
        ]);

        $template = RemTemplate::create([
            'rem_type' => 'Z',
            'year' => 2098,
            'version' => '1.0',
            'config' => [
                'sheets' => [
                    [
                        'sheet_name' => 'HOJAZ',
                        'section_code' => 'HOJAZ',
                        'is_required' => true,
                        'structure' => [
                            'header_row' => 9,
                            'data_start_row' => 12,
                            'concept_column' => 'A',
                            'professional_column' => 'A', // misma columna que concept, como A02
                            'total_column' => 'B',
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Profesional'],
                            ['letter' => 'B', 'header' => 'Total'],
                            ['letter' => 'C', 'header' => 'Data1'],
                        ],
                        'validation_rules' => ['data_type' => 'integer', 'min' => 0, 'max' => null, 'allow_null' => true],
                    ],
                ],
            ],
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('HOJAZ');

        // Fila 12-13: seccion A con datos reales
        $sheet->setCellValue('A12', 'Médico/a');
        $sheet->setCellValue('B12', 5);
        $sheet->setCellValue('C12', 5);

        // Fila 18: titulo de seccion en el hueco entre A (fin=17) y B (inicio=21).
        // Misma columna concept=professional='A' => el titulo cae en ambos campos.
        $sheet->setCellValue('A18', 'SECCIÓN B: EMP SEGÚN RESULTADO DEL ESTADO NUTRICIONAL');

        // Fila 19: sub-encabezado, tambien en el hueco
        $sheet->setCellValue('A19', 'ESTADO NUTRICIONAL');

        // Fila 21-22: seccion B con datos reales
        $sheet->setCellValue('A21', 'TOTAL');
        $sheet->setCellValue('B21', 10);
        $sheet->setCellValue('C21', 10);
        $sheet->setCellValue('A22', 'Normal');
        $sheet->setCellValue('B22', 3);
        $sheet->setCellValue('C22', 3);

        $path = storage_path('app/rem-uploads/test_a02_pattern_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tmpFiles[] = $path;

        $upload = RemUpload::create([
            'rem_type' => 'Z',
            'year' => 2098,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Test Z',
                'code_deis' => 'CTZ' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'testz.xlsx',
            'stored_path' => basename($path),
            'file_size' => filesize($path),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);

        $parser = app(RemParserService::class);
        $result = $parser->parse($upload);

        $byRow = [];
        foreach ($result->extractedData as $entry) {
            $byRow[$entry['row_number']] = $entry;
        }

        $this->assertArrayNotHasKey(18, $byRow, 'El titulo "SECCION B: ..." no debe persistirse como fila de datos');
        $this->assertArrayNotHasKey(19, $byRow, 'El sub-encabezado "ESTADO NUTRICIONAL" no debe persistirse como fila de datos');
        $this->assertArrayHasKey(12, $byRow, 'La fila de datos real de la seccion A debe seguir persistiendo');
        $this->assertArrayHasKey(21, $byRow, 'La fila TOTAL real de la seccion B debe seguir persistiendo');
        $this->assertArrayHasKey(22, $byRow, 'La fila de datos real de la seccion B debe seguir persistiendo');
    }

    public function test_total_row_is_not_persisted_as_normal_functional_row(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(183, $byRow, 'La fila TOTAL de HD1 no debe persistirse como fila funcional normal');
        $this->assertArrayNotHasKey(200, $byRow, 'La fila TOTAL de HD2 no debe persistirse como fila funcional normal');
    }

    public function test_blocked_only_row_is_still_ignored(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(175, $byRow, 'Una fila con solo celdas bloqueadas (sin evidencia editable) no debe persistirse');
    }

    public function test_section_without_cell_data_does_not_force_persist_empty_rows(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(7, $byRow, 'Fila vacia en seccion sin cell_data escaneado no debe forzarse a persistir (evita persistencia indiscriminada)');
    }

    public function test_normal_filled_rows_still_behave_as_before(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(5, $byRow);
        $this->assertSame(8, $byRow[5]['total']);
        $this->assertSame(5, $byRow[5]['values']['C']);
        $this->assertSame(3, $byRow[5]['values']['D']);

        $this->assertArrayHasKey(6, $byRow, 'Fila con valores reales en cero debe seguir persistiendo como antes');
        $this->assertSame(0, $byRow[6]['total']);
    }

    public function test_empty_row_generates_functional_warning_when_rule_is_approved(): void
    {
        [$upload] = $this->parseUpload();

        $functionalRuleService = app(FunctionalRuleService::class);
        $functionalRuleService->saveFunctionalRuleByRow(self::SHEET, 'HD1', 172, [
            'empty_behavior' => 'debe_registrar_cero',
            'functional_condition' => 'Debe registrar 0 segun criterio de Estadistica',
            'informed_by' => 'Test',
            'updated_by' => 'Test',
            'status' => 'aprobada',
        ]);
        $functionalRuleService->saveFunctionalRuleByRow(self::SHEET, 'HD2', 199, [
            'empty_behavior' => 'debe_registrar_cero',
            'functional_condition' => 'Debe registrar 0 segun criterio de Estadistica',
            'informed_by' => 'Test',
            'updated_by' => 'Test',
            'status' => 'aprobada',
        ]);

        $job = new ValidateRemUploadJob($upload);
        app()->call([$job, 'handle']);

        $results = $upload->validationResults()->where('rule_type', 'functional_rule')->get();
        $byRow = [];
        foreach ($results as $r) {
            $byRow[$r->context['row_number']] = $r;
        }

        $this->assertArrayHasKey(172, $byRow, 'Debe generarse una advertencia funcional para la fila 172');
        $this->assertArrayHasKey(199, $byRow, 'Debe generarse una advertencia funcional para la fila 199');
    }
}
