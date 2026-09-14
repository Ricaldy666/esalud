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
 * BM-5.3 (2026-09-14) -- hallazgo real BM18A/B fila 206 (ver tambien A06/L
 * fila 181 y A33/E fila 74, mismo patron real preexistente en Serie A,
 * documentado en BM-5.2/BM-5.3): el candidato trailing-beyond-bounds
 * (data_end_row+1) de la ULTIMA seccion de una hoja (la de mayor
 * filaFinDatos) nunca era evaluado por findTechnicalSectionContextForRow(),
 * porque $maxRow en RemParserService::parseSheet() se calculaba como el
 * MAYOR data_end_row entre todas las secciones de la hoja -- para
 * secciones intermedias, la seccion SIGUIENTE ya extendia $maxRow lo
 * suficiente (17.48, ya cubierto por RemParserServiceTechnicalSectionContextBeyondBoundsTest),
 * pero para la seccion final no existe ninguna seccion posterior que lo
 * haga. Fix: $maxRow se extiende en +1 (acotado siempre por $sheetMaxRow).
 *
 * Este archivo cubre especificamente la ULTIMA seccion de la hoja -- no
 * duplica la cobertura de huecos INTERMEDIOS entre secciones, ya validada
 * en RemParserServiceTechnicalSectionContextBeyondBoundsTest.php (que sigue
 * pasando sin cambios, ver regresion).
 *
 * IMPORTANTE (misma leccion de BM-3.6): nombre de hoja 100% ficticio
 * ('HOJAULT') para no colisionar con cell_data real de BM18/BM18A.
 */
class RemParserServiceLastSectionTrailingBeyondBoundsTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJAULT';
    private const YEAR = 2092;
    private const REM_TYPE = 'I';

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
            ['letra' => 'B', 'label' => 'Dato1', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Dato2', 'esTotal' => false],
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
                            'total_column' => null,
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Concepto'],
                            ['letter' => 'B', 'header' => 'Dato1'],
                            ['letter' => 'C', 'header' => 'Dato2'],
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
     * Layout:
     * - A: [12:20] (data), NO seccion siguiente extiende su propio limite
     *   (existe C despues, con data_end_row MAYOR -- A no es la ultima).
     * - C: [25:30] (data) -- la ULTIMA seccion de la hoja (mayor
     *   filaFinDatos=30). Fila 31 = candidato trailing propio (30+1),
     *   patron TOTAL real (igual a BM18A/B fila 206 y A06/L fila 181).
     * - Fila 32 = footer/nota REAL (texto plano, sin formula, sin etiqueta
     *   TOTAL) -- NUNCA debe capturarse (data_end_row+2, fuera del +1).
     */
    private function createActiveStructure(): void
    {
        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-last-section-trailing'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            ['codigo' => 'A', 'filaInicioDatos' => 12, 'filaFinDatos' => 20, 'fields' => $this->fields()],
                            ['codigo' => 'C', 'filaInicioDatos' => 25, 'filaFinDatos' => 30, 'fields' => $this->fields()],
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

        // C: filas 25-30 (dato real, sin relevancia para este test) + fila
        // 31 = TOTAL trailing beyond bounds de la ULTIMA seccion (patron
        // real BM18A/B 206 / A06/L 181 / A33/E 74: SUM simple hacia atras,
        // etiqueta "TOTAL").
        $svc->saveCellData(self::SHEET, 'C', [
            'A28' => $this->cell('Item 1', false, true),
            'B28' => $this->cell('4', true, false),
            'C28' => $this->cell('2', true, false),
            'A29' => $this->cell('Item 2', false, true),
            'B29' => $this->cell('6', true, false),
            'C29' => $this->cell('3', true, false),
            'A30' => $this->cell('Item 3', false, true),
            'B30' => $this->cell('1', true, false),
            'C30' => $this->cell('1', true, false),
            'A31' => $this->cell('TOTAL', false, true),
            'B31' => $this->cell(null, false, true, true, '=SUM(B28:B30)'),
            'C31' => $this->cell(null, false, true, true, '=SUM(C28:C30)'),
            // Fila 32: footer/nota real, texto plano sin formula ni
            // etiqueta TOTAL -- NUNCA debe evaluarse ni capturarse.
            'A32' => $this->cell('Fuente: Ministerio de Salud', false, true),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // A (12-20): datos minimos, sin candidato trailing propio relevante
        // para este test (A no es la ultima seccion).
        $sheet->setCellValue('A12', 'Item A1');
        $sheet->setCellValue('B12', 1);
        $sheet->setCellValue('C12', 1);

        // C (25-30) + candidato trailing propio (31) + footer real (32)
        $sheet->setCellValue('A28', 'Item 1');
        $sheet->setCellValue('B28', 4);
        $sheet->setCellValue('C28', 2);
        $sheet->setCellValue('A29', 'Item 2');
        $sheet->setCellValue('B29', 6);
        $sheet->setCellValue('C29', 3);
        $sheet->setCellValue('A30', 'Item 3');
        $sheet->setCellValue('B30', 1);
        $sheet->setCellValue('C30', 1);
        $sheet->setCellValue('A31', 'TOTAL');
        $sheet->setCellValue('B31', '=SUM(B28:B30)');
        $sheet->setCellValue('C31', '=SUM(C28:C30)');
        $sheet->setCellValue('A32', 'Fuente: Ministerio de Salud');

        $path = storage_path('app/rem-uploads/test_last_section_trailing_' . uniqid() . '.xlsx');
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
                'name' => 'Centro Test Ultima Seccion',
                'code_deis' => 'CUS' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_last_section_trailing.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);
    }

    /** @return array{0: RemUpload, 1: \App\Domain\REM\Services\ParseResult} */
    private function runParse(): array
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

    public function test_last_section_own_trailing_total_is_now_captured(): void
    {
        [, $result] = $this->runParse();

        $technicalByRow = [];
        foreach ($result->technicalTotals as $entry) {
            $technicalByRow[$entry['rem_section_code']][$entry['row_number']] = $entry;
        }

        $this->assertArrayHasKey(31, $technicalByRow['C'] ?? [], 'fila 31 (trailing propio de la ULTIMA seccion C) debe capturarse tras el fix');
        $this->assertSame('trailing_total_beyond_bounds', $technicalByRow['C'][31]['exclusion_reason']);
        $this->assertSame(11, $technicalByRow['C'][31]['values']['B'], 'B31 = SUM(B28:B30) = 4+6+1 = 11');
        $this->assertSame(6, $technicalByRow['C'][31]['values']['C'], 'C31 = SUM(C28:C30) = 2+3+1 = 6');
    }

    public function test_last_section_trailing_total_never_persisted_as_rem_data(): void
    {
        [$upload] = $this->runParse();

        $rows = RemData::where('rem_upload_id', $upload->id)
            ->where('section', self::SHEET)
            ->get()
            ->map(fn($rd) => $rd->data['row_number']);

        $this->assertNotContains(31, $rows->all(), 'fila 31 nunca debe persistir en rem_data');
        $this->assertContains(28, $rows->all());
        $this->assertContains(29, $rows->all());
        $this->assertContains(30, $rows->all());
    }

    public function test_footer_row_two_past_data_end_is_never_captured(): void
    {
        [$upload, $result] = $this->runParse();

        $technicalByRow = [];
        foreach ($result->technicalTotals as $entry) {
            $technicalByRow[$entry['rem_section_code']][$entry['row_number']] = $entry;
        }

        $this->assertArrayNotHasKey(32, $technicalByRow['C'] ?? [], 'fila 32 (footer real, data_end_row+2) nunca debe capturarse -- el fix solo extiende +1');

        $rows = RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get()->map(fn($rd) => $rd->data['row_number']);
        $this->assertNotContains(32, $rows->all(), 'fila 32 tampoco debe persistir como dato ordinario');

        $errorRows = array_map(fn($e) => $e['row'] ?? null, $result->errors);
        $this->assertNotContains(32, $errorRows, 'fila 32 no debe generar ningun error de validacion');
    }

    public function test_normal_data_rows_of_last_section_unaffected(): void
    {
        [$upload] = $this->runParse();

        $byRow = [];
        foreach (RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get() as $rd) {
            $byRow[$rd->data['row_number']] = $rd->data;
        }

        $this->assertSame(4, $byRow[28]['values']['B']);
        $this->assertSame(6, $byRow[29]['values']['B']);
        $this->assertSame(1, $byRow[30]['values']['B']);
    }

    public function test_existing_boundary_resolver_still_matches_exactly_data_end_plus_one(): void
    {
        // Confirmacion directa (sin reprocesar upload) de que
        // findTechnicalSectionContextForRow() -- sin modificar -- sigue
        // exigiendo una coincidencia EXACTA de +1/-1, nunca una ventana.
        $this->createActiveStructure();
        $parser = app(RemParserService::class);
        $ref = new \ReflectionMethod($parser, 'findTechnicalSectionContextForRow');
        $ref->setAccessible(true);

        $sectionMap = [
            ['code' => 'A', 'data_start_row' => 12, 'data_end_row' => 20],
            ['code' => 'C', 'data_start_row' => 25, 'data_end_row' => 30],
        ];

        $ctx31 = $ref->invoke($parser, $sectionMap, 31);
        $ctx32 = $ref->invoke($parser, $sectionMap, 32);

        $this->assertNotNull($ctx31);
        $this->assertSame('C', $ctx31['code']);
        $this->assertSame('trailing', $ctx31['technical_boundary_type']);
        $this->assertNull($ctx32, 'fila 32 (data_end_row+2) nunca debe resolver contexto de frontera');
    }

    public function test_no_active_structure_is_modified_by_this_fix(): void
    {
        $this->runParse();

        $active = RemTemplateStructure::where('status', 'active')->first();
        $estructura = is_string($active->estructura) ? json_decode($active->estructura, true) : $active->estructura;
        $seccionC = collect($estructura['forms'][0]['sections'])->firstWhere('codigo', 'C');

        $this->assertSame(30, $seccionC['filaFinDatos'], 'la estructura activa no debe modificarse -- filaFinDatos sigue siendo 30, el fix opera solo en tiempo de parseo');
    }
}
