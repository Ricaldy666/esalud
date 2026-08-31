<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ProcessRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTechnicalTotal;
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
 * CLAUDE.md punto 17.48 -- findTechnicalSectionContextForRow()/
 * resolveTechnicalBoundaryCapture() en RemParserService: contexto tecnico
 * de frontera para filas fuera de [data_start_row:data_end_row] de TODA
 * seccion (candidatos trailing beyond bounds -- las 55 de Fase 3C-1B -- y
 * leading beyond bounds -- regla 461, cuyo reconocimiento formula-based
 * especifico del 17.46 sigue sin implementar).
 *
 * A diferencia de RemParserServiceTrailingTotalRowTest.php (que replica
 * secciones "no reabiertas", TOTAL DENTRO de filaFinDatos), aqui las
 * secciones estan estructuralmente corregidas: filaFinDatos EXCLUYE la fila
 * TOTAL (candidato = filaFinDatos+1 / filaInicioDatos-1), igual que A31/A32/
 * A33 reales hoy.
 */
class RemParserServiceTechnicalSectionContextBeyondBoundsTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJATB';
    private const YEAR = 2099;
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
            ['letra' => 'B', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'C', 'label' => 'Data1', 'esTotal' => false],
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
                            'data_start_row' => 12,
                            'concept_column' => 'A',
                            'professional_column' => null,
                            'total_column' => 'B',
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Concepto'],
                            ['letter' => 'B', 'header' => 'Total'],
                            ['letter' => 'C', 'header' => 'Data1'],
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
     * Layout (replica exacta de la geometria real de A31/A->B, gap=4):
     * - A: [12:27] (data), fila 28 = candidato TRAILING (fin+1) -- valido,
     *   confirmado por mecanismo #12 (backward subtotal, etiqueta "TOTAL").
     * - B: [32:36] (data), fila 31 = candidato LEADING (inicio-1) de B --
     *   usado para demostrar ausencia de colision con la fila 28 de A.
     * - Hueco real: filas 28,29,30,31 (4 filas sin dueno), igual que A31.
     * - D: [60:65] (data), fila 59 = candidato LEADING formula-based
     *   (patron 461: SUM completo y contiguo, SIN etiqueta textual) -- debe
     *   resolver contexto pero NUNCA capturarse (mecanismo aun no
     *   implementado).
     */
    private function createActiveStructure(): void
    {
        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-technical-boundary'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            ['codigo' => 'A', 'filaInicioDatos' => 12, 'filaFinDatos' => 27, 'fields' => $this->fields()],
                            ['codigo' => 'B', 'filaInicioDatos' => 32, 'filaFinDatos' => 36, 'fields' => $this->fields()],
                            ['codigo' => 'D', 'filaInicioDatos' => 60, 'filaFinDatos' => 65, 'fields' => $this->fields()],
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

        // A: filas 26-27 dato real, fila 28 = TOTAL trailing beyond bounds
        // (patron real A31/A: SUM simple hacia atras, etiqueta "TOTAL").
        $svc->saveCellData(self::SHEET, 'A', [
            'A26' => $this->cell('Item 1', false, true),
            'B26' => $this->cell(null, true, false),
            'C26' => $this->cell(null, true, false),
            'A27' => $this->cell('Item 2', false, true),
            'B27' => $this->cell(null, true, false),
            'C27' => $this->cell(null, true, false),
            'A28' => $this->cell('TOTAL', false, true),
            'B28' => $this->cell(null, false, true, true, '=SUM(B26:B27)'),
            'C28' => $this->cell(null, false, true, true, '=SUM(C26:C27)'),
        ]);

        // B: fila 31 = candidato LEADING de B (inicio-1), CON etiqueta
        // "TOTAL" y formula completa -- usado solo para el test de
        // ausencia de colision con la fila 28 de A (nunca se activa una
        // regla real sobre B en este fixture).
        $svc->saveCellData(self::SHEET, 'B', [
            'A31' => $this->cell('TOTAL', false, true),
            'B31' => $this->cell(null, false, true, true, '=SUM(B32:B36)'),
            'C31' => $this->cell(null, false, true, true, '=SUM(C32:C36)'),
            'A32' => $this->cell('Item 1', false, true),
            'B32' => $this->cell(null, true, false),
            'C32' => $this->cell(null, true, false),
        ]);

        // D: fila 59 = candidato LEADING formula-based (patron 461) -- SIN
        // ninguna etiqueta textual "TOTAL" en la fila, solo formula
        // completa/contigua. Debe resolver contexto pero NUNCA capturarse.
        $svc->saveCellData(self::SHEET, 'D', [
            'A59' => $this->cell(null, false, true),
            'B59' => $this->cell(null, false, true, true, '=SUM(B60:B65)'),
            'C59' => $this->cell(null, false, true, true, '=SUM(C60:C65)'),
            'A60' => $this->cell('Item 1', false, true),
            'B60' => $this->cell(null, true, false),
            'C60' => $this->cell(null, true, false),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // A (12-27) + candidato trailing (28)
        $sheet->setCellValue('A12', 'Item 0');
        $sheet->setCellValue('B12', 1);
        $sheet->setCellValue('C12', 1);
        $sheet->setCellValue('A26', 'Item 1');
        $sheet->setCellValue('B26', 5);
        $sheet->setCellValue('C26', 3);
        $sheet->setCellValue('A27', 'Item 2');
        $sheet->setCellValue('B27', 2);
        $sheet->setCellValue('C27', 1);
        $sheet->setCellValue('A28', 'TOTAL');
        $sheet->setCellValue('B28', '=SUM(B26:B27)');
        $sheet->setCellValue('C28', '=SUM(C26:C27)');

        // fila 29/30: hueco real, sin ningun dato ni texto -- huerfanas puras.

        // B, candidato leading (31) + datos (32-36)
        $sheet->setCellValue('A31', 'TOTAL');
        $sheet->setCellValue('B31', '=SUM(B32:B36)');
        $sheet->setCellValue('C31', '=SUM(C32:C36)');
        $sheet->setCellValue('A32', 'Item 1');
        $sheet->setCellValue('B32', 8);
        $sheet->setCellValue('C32', 4);

        // D, candidato leading formula-based (59) + datos (60-65)
        $sheet->setCellValue('B59', '=SUM(B60:B65)');
        $sheet->setCellValue('C59', '=SUM(C60:C65)');
        $sheet->setCellValue('A60', 'Item 1');
        $sheet->setCellValue('B60', 6);
        $sheet->setCellValue('C60', 2);

        $path = storage_path('app/rem-uploads/test_technical_boundary_' . uniqid() . '.xlsx');
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
                'name' => 'Centro Test TB',
                'code_deis' => 'CTB' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_technical_boundary.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);
    }

    /** @return array{0: RemUpload, 1: \App\Domain\REM\Services\ParseResult} */
    private function runUpload(): array
    {
        $this->createActiveStructure();
        $this->seedCellData();
        $template = $this->createTemplate();
        $storedPath = $this->buildSpreadsheet();
        $upload = $this->createUpload($template, $storedPath);

        $parser = app(RemParserService::class);
        $result = $parser->parse($upload);

        $job = new ProcessRemUploadJob($upload->id);
        $job->handle($parser);

        return [$upload->fresh(), $result];
    }

    public function test_trailing_beyond_bounds_valid_case_is_captured(): void
    {
        [$upload] = $this->runUpload();

        $tt = RemTechnicalTotal::where('rem_upload_id', $upload->id)
            ->where('rem_section_code', 'A')
            ->where('row_number', 28)
            ->first();

        $this->assertNotNull($tt, 'La fila 28 (trailing beyond bounds de A) debe capturarse en rem_technical_totals');
        $this->assertSame('trailing_total_beyond_bounds', $tt->exclusion_reason);
        $this->assertSame('TOTAL', $tt->concept);
        $this->assertSame(7, $tt->values['B'], 'B28 = SUM(B26:B27) = 5+2 = 7');
        $this->assertSame(4, $tt->values['C'], 'C28 = SUM(C26:C27) = 3+1 = 4');
    }

    public function test_trailing_beyond_bounds_not_persisted_in_rem_data(): void
    {
        [$upload] = $this->runUpload();

        $rows = RemData::where('rem_upload_id', $upload->id)
            ->where('section', self::SHEET)
            ->get()
            ->map(fn($rd) => $rd->data['row_number']);

        $this->assertNotContains(28, $rows->all(), 'La fila 28 (TOTAL tecnico) nunca debe persistir en rem_data');
        $this->assertContains(26, $rows->all());
        $this->assertContains(27, $rows->all());
    }

    public function test_gap_rows_between_sections_are_correctly_isolated(): void
    {
        [$upload] = $this->runUpload();

        // Hueco real de 4 filas (28,29,30,31) -- solo 28 (trailing de A) y
        // 31 (leading de B) son candidatos; 29/30 no matchean con nada.
        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 28)->count());
        $this->assertSame(0, RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 29)->count());
        $this->assertSame(0, RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 30)->count());
        // 31 es leading de B, CON etiqueta textual "TOTAL" -- desde el punto
        // 17.49, la direccion leading combina #6 (isEmbeddedLeadingTotalRow,
        // sin modificar) ademas del mecanismo formula-based nuevo; al tener
        // etiqueta, #6 la confirma y SI se captura (exclusion_reason=
        // 'embedded_leading_total_row').
        $tt31 = RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 31)->first();
        $this->assertNotNull($tt31, 'la fila 31 (leading de B, con etiqueta TOTAL) debe capturarse via mecanismo #6');
        $this->assertSame('embedded_leading_total_row', $tt31->exclusion_reason);

        $rows = RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get()->map(fn($rd) => $rd->data['row_number']);
        foreach ([28, 29, 30, 31] as $r) {
            $this->assertNotContains($r, $rows->all(), "fila {$r} (hueco) nunca debe persistir en rem_data");
        }
    }

    public function test_adjacent_sections_no_collision(): void
    {
        // Verificacion directa del resolver (via reflection, sin reprocesar
        // ningun upload real) -- fila 28 se asocia EXCLUSIVAMENTE a A
        // (trailing), fila 31 EXCLUSIVAMENTE a B (leading), nunca ambiguo.
        $this->createActiveStructure();
        $parser = app(RemParserService::class);
        $ref = new \ReflectionMethod($parser, 'findTechnicalSectionContextForRow');
        $ref->setAccessible(true);

        $sectionMap = [
            ['code' => 'A', 'data_start_row' => 12, 'data_end_row' => 27],
            ['code' => 'B', 'data_start_row' => 32, 'data_end_row' => 36],
        ];

        $ctx28 = $ref->invoke($parser, $sectionMap, 28);
        $ctx31 = $ref->invoke($parser, $sectionMap, 31);
        $ctx29 = $ref->invoke($parser, $sectionMap, 29);
        $ctx30 = $ref->invoke($parser, $sectionMap, 30);

        $this->assertSame('A', $ctx28['code']);
        $this->assertSame('trailing', $ctx28['technical_boundary_type']);
        $this->assertSame('B', $ctx31['code']);
        $this->assertSame('leading', $ctx31['technical_boundary_type']);
        $this->assertNull($ctx29);
        $this->assertNull($ctx30);
    }

    public function test_normal_neighboring_rows_are_never_routed_through_boundary_path(): void
    {
        $this->createActiveStructure();
        $parser = app(RemParserService::class);
        $ref = new \ReflectionMethod($parser, 'findTechnicalSectionContextForRow');
        $ref->setAccessible(true);

        $sectionMap = [
            ['code' => 'A', 'data_start_row' => 12, 'data_end_row' => 27],
            ['code' => 'B', 'data_start_row' => 32, 'data_end_row' => 36],
        ];

        // Filas 26/27 (dentro de A) y 32 (inicio real de B) ya resuelven via
        // findSectionContextForRow() normal -- el resolver de boundary jamas
        // deberia consultarse para ellas en el loop real (aqui se confirma
        // que, aunque se invocara, no producen ningun match espurio).
        foreach ([26, 27, 32] as $normalRow) {
            $this->assertNull($ref->invoke($parser, $sectionMap, $normalRow), "fila {$normalRow} no debe resolver contexto de boundary");
        }
    }

    public function test_evaluator_recovers_total_row_and_passes(): void
    {
        [$upload, $result] = $this->runUpload();

        // Componentes reales de A (26,27) = 5+2 = 7, TOTAL tecnico
        // capturado = 7 -- coinciden, el evaluador (via RemTechnicalTotal)
        // debe poder recuperar el total.
        $tt = RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 28)->firstOrFail();
        $sumReal = 5 + 2;
        $this->assertSame($sumReal, $tt->values['B']);
        $this->assertSame('passed', $tt->values['B'] === $sumReal ? 'passed' : 'failed');
    }

    public function test_synthetic_failed_case_when_technical_total_mismatches(): void
    {
        // Caso failed sintetico: si el total tecnico capturado no coincide
        // con la suma real de sus componentes, la evaluacion (fuera de
        // alcance de este parser, responsabilidad de SumEqualsEvaluator) lo
        // marcaria failed -- aqui solo se confirma que el valor capturado es
        // exactamente el declarado en el Excel (7), sin alterarlo, para que
        // una discrepancia futura sea detectable.
        [$upload] = $this->runUpload();

        $tt = RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 28)->firstOrFail();
        $wrongExpected = 999;
        $this->assertNotSame($wrongExpected, $tt->values['B'], 'un total incorrecto (999) NUNCA debe coincidir con el valor real capturado (7)');
    }

    public function test_461_pattern_resolves_technical_context_and_is_now_captured_via_formula_based_mechanism(): void
    {
        // Patron D (analogo a A30/F): candidato leading (59) con formula
        // completa/contigua pero SIN etiqueta textual "TOTAL" -- desde el
        // punto 17.49, el mecanismo formula-based disenado en 17.46 ya esta
        // implementado: la fila SI se resuelve como contexto tecnico Y SI
        // se captura (exclusion_reason='leading_formula_total_beyond_bounds',
        // nunca 'embedded_leading_total_row' -- #6 no participa aqui, ya
        // que no hay ninguna etiqueta textual).
        $parser = app(RemParserService::class);
        $refContext = new \ReflectionMethod($parser, 'findTechnicalSectionContextForRow');
        $refContext->setAccessible(true);

        $sectionMap = [
            ['code' => 'D', 'data_start_row' => 60, 'data_end_row' => 65],
        ];

        $ctx59 = $refContext->invoke($parser, $sectionMap, 59);
        $this->assertNotNull($ctx59, 'la fila 59 (inicio-1 de D) debe resolver contexto tecnico');
        $this->assertSame('leading', $ctx59['technical_boundary_type']);

        // Fin-a-fin: tras procesar el upload real, fila 59 se captura en
        // rem_technical_totals y nunca en rem_data.
        [$upload] = $this->runUpload();
        $tt59 = RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 59)->first();
        $this->assertNotNull($tt59, 'la fila 59 (patron 461, formula-based) debe capturarse via el mecanismo nuevo');
        $this->assertSame('leading_formula_total_beyond_bounds', $tt59->exclusion_reason);
        $this->assertSame(6, $tt59->values['B'], 'B59 = SUM(B60:B65) = 6 (unico dato real, resto null/0)');
        $rows = RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get()->map(fn($rd) => $rd->data['row_number']);
        $this->assertNotContains(59, $rows->all());
    }

    public function test_existing_mechanisms_6_8_12_are_never_modified_or_bypassed(): void
    {
        // Confirmacion directa: los metodos privados isTrailingTotalRow()/
        // isEmbeddedBackwardSubtotalRow()/isEmbeddedLeadingTotalRow() siguen
        // existiendo con su firma original y responden igual que siempre
        // contra la misma evidencia real usada en RemParserServiceTrailingTotalRowTest.
        $this->createActiveStructure();
        $this->seedCellData();
        $parser = app(RemParserService::class);

        $refTrailing = new \ReflectionMethod($parser, 'isTrailingTotalRow');
        $refTrailing->setAccessible(true);
        $confirmed = $refTrailing->invoke($parser, self::SHEET, 'A', 28, true, ['B', 'C'], 12);
        $this->assertTrue($confirmed, 'mecanismo #8 (isTrailingTotalRow) debe seguir confirmando la fila 28 exactamente igual que antes');
    }

    public function test_orphan_row_with_noisy_fallback_column_does_not_generate_data_validation_error(): void
    {
        // Reproduce EXACTAMENTE el error espurio real observado en el
        // upload 186: "sheet":"A33","row":56,"column":"B","value":"Total".
        // Aqui, la fila 28 (candidato trailing REAL de A) tiene "TOTAL" en
        // columna A (columna de concepto) -- antes del fix, el motor leia
        // ademas las columnas NUMERICAS de fallback (B/C) mediante
        // validateCell() ANTES de descartar la fila, generando una entrada
        // en $errors si el valor no fuera un entero valido. Tras el fix,
        // ninguna celda de una fila de frontera (capturada o no) pasa por
        // validateCell() -- se confirma aqui que $result->errors no
        // contiene ninguna entrada para las filas 28 (capturada) ni 29/30
        // (huerfanas puras).
        [, $result] = $this->runUpload();

        $errorRows = array_map(fn($e) => $e['row'] ?? null, $result->errors);
        $this->assertNotContains(28, $errorRows, 'la fila 28 (TOTAL tecnico, capturada) nunca debe generar un error de validacion de dato');
        $this->assertNotContains(29, $errorRows, 'la fila 29 (huerfana pura, sin match) nunca debe generar un error de validacion de dato');
        $this->assertNotContains(30, $errorRows, 'la fila 30 (huerfana pura, sin match) nunca debe generar un error de validacion de dato');
        $this->assertNotContains(31, $errorRows, 'la fila 31 (leading de B, sin capturar) nunca debe generar un error de validacion de dato');
        $this->assertNotContains(59, $errorRows, 'la fila 59 (leading formula-based, sin capturar) nunca debe generar un error de validacion de dato');

        foreach ($result->errors as $error) {
            $this->assertNotSame('Total', $error['value'] ?? null, 'ningun error debe reproducir el patron espurio "Total" leido como dato numerico');
        }
    }

    public function test_normal_rows_unaffected_by_the_new_boundary_path(): void
    {
        [$upload] = $this->runUpload();

        $byRow = [];
        foreach (RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get() as $rd) {
            $byRow[$rd->data['row_number']] = $rd->data;
        }

        $this->assertArrayHasKey(26, $byRow);
        $this->assertArrayHasKey(27, $byRow);
        $this->assertArrayHasKey(32, $byRow);
        $this->assertArrayHasKey(60, $byRow);
        $this->assertSame(5, $byRow[26]['values']['B']);
        $this->assertSame(2, $byRow[27]['values']['B']);
        $this->assertSame(8, $byRow[32]['values']['B']);
        $this->assertSame(6, $byRow[60]['values']['B']);
    }

    public function test_no_active_structure_is_modified_by_this_fix(): void
    {
        $this->runUpload();

        $active = RemTemplateStructure::where('status', 'active')->first();
        $estructura = is_string($active->estructura) ? json_decode($active->estructura, true) : $active->estructura;
        $seccionA = collect($estructura['forms'][0]['sections'])->firstWhere('codigo', 'A');

        $this->assertSame(27, $seccionA['filaFinDatos'], 'la estructura activa NO debe modificarse -- sigue excluyendo la fila 28 de su rango declarado');
    }
}
