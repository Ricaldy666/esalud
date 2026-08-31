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
 * CLAUDE.md punto 17.49 -- RemParserService::isLeadingFormulaBasedTotalBeyondBounds()
 * (mecanismo hermano de #6/isEmbeddedLeadingTotalRow, NUNCA lo modifica ni
 * exige etiqueta textual "TOTAL"). Patron real: regla 461 (A30/F, fila 123),
 * confirmado unico en el barrido exhaustivo de 381 secciones (17.46) que
 * necesita esta via -- las demas secciones candidatas (A30/A,C,D,E) SI
 * tienen etiqueta textual y ya las resuelve #6 (ver seccion 'LAB' de este
 * fixture, replicando exactamente ese patron).
 *
 * Fixture "LFB" replica fielmente el patron real de A30/F fila 123:
 * B123=SUM(B124:B129) (completo, contiguo, sin etiqueta, sin referencia
 * externa) -- misma estructura minima usada en RemParserServiceTechnicalSectionContextBeyondBoundsTest.php
 * (seccion D), aqui con variantes dedicadas para cada guard.
 */
class RemParserServiceLeadingFormulaBasedTotalTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJALFB';
    private const YEAR = 2100;
    private const REM_TYPE = 'H';

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
                            'data_start_row' => 20,
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
     * Layout, un caso por seccion:
     * - PRE   [5:10], seccion previa simple -- necesaria para que el loop
     *         principal (que arranca en min(data_start_row) de TODAS las
     *         secciones) alcance la fila 19 de LFB; en el patron real (A30),
     *         F nunca es la primera seccion de la hoja (E la precede),
     *         mismo motivo por el que aqui LFB tampoco debe serlo.
     * - LFB   [20:25], candidato leading=19, formula completa/contigua,
     *         SIN etiqueta -- patron 461 exacto -- debe capturarse.
     * - INC   [40:45], candidato=39, formula con HUECO (referencia 40-42 y
     *         44-45, salta 43) -- debe rechazarse.
     * - EXT   [55:60], candidato=54, formula que referencia una columna
     *         DISTINTA (C en vez de B) -- debe rechazarse.
     * - NORM  [70:75], candidato=69 es en realidad una fila de DATO real
     *         (editable, sin formula) -- debe rechazarse (nunca confundir
     *         una fila normal con un TOTAL).
     * - LAB   [90:95], candidato=89, CON etiqueta textual "TOTAL" y formula
     *         completa/contigua -- debe capturarse via mecanismo #6
     *         (isEmbeddedLeadingTotalRow), no via el mecanismo nuevo.
     */
    private function createActiveStructure(): void
    {
        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-leading-formula-based'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            ['codigo' => 'PRE', 'filaInicioDatos' => 5, 'filaFinDatos' => 10, 'fields' => $this->fields()],
                            ['codigo' => 'LFB', 'filaInicioDatos' => 20, 'filaFinDatos' => 25, 'fields' => $this->fields()],
                            ['codigo' => 'INC', 'filaInicioDatos' => 40, 'filaFinDatos' => 45, 'fields' => $this->fields()],
                            ['codigo' => 'EXT', 'filaInicioDatos' => 55, 'filaFinDatos' => 60, 'fields' => $this->fields()],
                            ['codigo' => 'NORM', 'filaInicioDatos' => 70, 'filaFinDatos' => 75, 'fields' => $this->fields()],
                            ['codigo' => 'LAB', 'filaInicioDatos' => 90, 'filaFinDatos' => 95, 'fields' => $this->fields()],
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

        // PRE: seccion simple previa, sin nada especial que verificar.
        $svc->saveCellData(self::SHEET, 'PRE', [
            'A5' => $this->cell('Item PRE', false, true),
            'B5' => $this->cell(null, true, false),
        ]);

        // LFB: fila 19, formula completa/contigua [20:25], SIN etiqueta.
        $svc->saveCellData(self::SHEET, 'LFB', [
            'A19' => $this->cell(null, false, true),
            'B19' => $this->cell(null, false, true, true, '=SUM(B20:B25)'),
            'C19' => $this->cell(null, false, true, true, '=SUM(C20:C25)'),
            'A20' => $this->cell('Item 1', false, true),
            'B20' => $this->cell(null, true, false),
            'C20' => $this->cell(null, true, false),
        ]);

        // INC: fila 39, formula con HUECO (salta la fila 43 dentro de [40:45]).
        $svc->saveCellData(self::SHEET, 'INC', [
            'A39' => $this->cell(null, false, true),
            'B39' => $this->cell(null, false, true, true, '=SUM(B40:B42)+SUM(B44:B45)'),
            'A40' => $this->cell('Item 1', false, true),
            'B40' => $this->cell(null, true, false),
        ]);

        // EXT: fila 54, formula que referencia columna C en vez de B (columna propia).
        $svc->saveCellData(self::SHEET, 'EXT', [
            'A54' => $this->cell(null, false, true),
            'B54' => $this->cell(null, false, true, true, '=SUM(C55:C60)'),
            'A55' => $this->cell('Item 1', false, true),
            'B55' => $this->cell(null, true, false),
            'C55' => $this->cell(null, true, false),
        ]);

        // NORM: fila 69 (candidato hipotetico) es en realidad un dato real
        // editable, sin formula -- nunca debe confundirse con un TOTAL.
        $svc->saveCellData(self::SHEET, 'NORM', [
            'A69' => $this->cell('Item 0 (dato real)', false, true),
            'B69' => $this->cell(null, true, false),
            'A70' => $this->cell('Item 1', false, true),
            'B70' => $this->cell(null, true, false),
        ]);

        // LAB: fila 89, CON etiqueta "TOTAL" y formula completa/contigua --
        // patron A30/A,C,D,E (mecanismo #6 ya lo resuelve).
        $svc->saveCellData(self::SHEET, 'LAB', [
            'A89' => $this->cell('TOTAL', false, true),
            'B89' => $this->cell(null, false, true, true, '=SUM(B90:B95)'),
            'C89' => $this->cell(null, false, true, true, '=SUM(C90:C95)'),
            'A90' => $this->cell('Item 1', false, true),
            'B90' => $this->cell(null, true, false),
            'C90' => $this->cell(null, true, false),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // PRE (5-10)
        $sheet->setCellValue('A5', 'Item PRE');
        $sheet->setCellValue('B5', 1);

        // LFB (19-25)
        $sheet->setCellValue('B19', '=SUM(B20:B25)');
        $sheet->setCellValue('C19', '=SUM(C20:C25)');
        $sheet->setCellValue('A20', 'Item 1');
        $sheet->setCellValue('B20', 5);
        $sheet->setCellValue('C20', 2);
        $sheet->setCellValue('A21', 'Item 2');
        $sheet->setCellValue('B21', 3);
        $sheet->setCellValue('C21', 1);

        // INC (39-45)
        $sheet->setCellValue('B39', '=SUM(B40:B42)+SUM(B44:B45)');
        $sheet->setCellValue('A40', 'Item 1');
        $sheet->setCellValue('B40', 4);

        // EXT (54-60)
        $sheet->setCellValue('B54', '=SUM(C55:C60)');
        $sheet->setCellValue('A55', 'Item 1');
        $sheet->setCellValue('B55', 7);
        $sheet->setCellValue('C55', 6);

        // NORM (69-75) -- fila 69 es dato real, no total.
        $sheet->setCellValue('A69', 'Item 0 (dato real)');
        $sheet->setCellValue('B69', 11);
        $sheet->setCellValue('A70', 'Item 1');
        $sheet->setCellValue('B70', 9);

        // LAB (89-95)
        $sheet->setCellValue('A89', 'TOTAL');
        $sheet->setCellValue('B89', '=SUM(B90:B95)');
        $sheet->setCellValue('C89', '=SUM(C90:C95)');
        $sheet->setCellValue('A90', 'Item 1');
        $sheet->setCellValue('B90', 8);
        $sheet->setCellValue('C90', 4);

        $path = storage_path('app/rem-uploads/test_leading_formula_based_' . uniqid() . '.xlsx');
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
                'name' => 'Centro Test LFB',
                'code_deis' => 'CLFB' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_leading_formula_based.xlsx',
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

    public function test_complete_contiguous_formula_without_label_is_captured(): void
    {
        [$upload] = $this->runUpload();

        $tt = RemTechnicalTotal::where('rem_upload_id', $upload->id)
            ->where('rem_section_code', 'LFB')
            ->where('row_number', 19)
            ->first();

        $this->assertNotNull($tt, 'la fila 19 (candidato inicio-1 de LFB, formula completa/contigua sin etiqueta) debe capturarse');
        $this->assertSame('leading_formula_total_beyond_bounds', $tt->exclusion_reason);
        $this->assertSame(8, $tt->values['B'], 'B19 = SUM(B20:B25) = 5+3 = 8');
        $this->assertSame(3, $tt->values['C'], 'C19 = SUM(C20:C25) = 2+1 = 3');
        $this->assertNull($tt->concept, 'sin etiqueta textual, concept debe quedar null');
    }

    public function test_never_persisted_in_rem_data(): void
    {
        [$upload] = $this->runUpload();

        $rows = RemData::where('rem_upload_id', $upload->id)
            ->where('section', self::SHEET)
            ->get()
            ->map(fn($rd) => $rd->data['row_number']);

        $this->assertNotContains(19, $rows->all());
        $this->assertContains(20, $rows->all());
        $this->assertContains(21, $rows->all());
    }

    public function test_incomplete_formula_with_gap_is_rejected(): void
    {
        [$upload] = $this->runUpload();

        $this->assertSame(
            0,
            RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('rem_section_code', 'INC')->count(),
            'formula con hueco (salta la fila 43) nunca debe confirmar el mecanismo -- no debe capturarse'
        );
        $rows = RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get()->map(fn($rd) => $rd->data['row_number']);
        $this->assertNotContains(39, $rows->all());
    }

    public function test_external_column_reference_is_rejected(): void
    {
        [$upload] = $this->runUpload();

        $this->assertSame(
            0,
            RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('rem_section_code', 'EXT')->count(),
            'formula que referencia una columna distinta (C en vez de B) nunca debe confirmar el mecanismo'
        );
        $rows = RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get()->map(fn($rd) => $rd->data['row_number']);
        $this->assertNotContains(54, $rows->all());
    }

    public function test_normal_data_row_is_never_misidentified_as_total(): void
    {
        [$upload] = $this->runUpload();

        // La fila 69 (candidato inicio-1 de NORM) es en realidad dato real
        // (editable, sin formula) -- el mecanismo debe rechazarla (0 en
        // rem_technical_totals). Al estar estructuralmente fuera de
        // [filaInicioDatos:filaFinDatos] de NORM (70:75), tampoco puede
        // persistir en rem_data -- mismo comportamiento que CUALQUIER fila
        // huerfana ya tenia antes de este cambio (nunca se inventa una
        // pertenencia de seccion que no existe); lo que este test certifica
        // es que NUNCA se malinterpreta como TOTAL tecnico.
        $this->assertSame(
            0,
            RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('rem_section_code', 'NORM')->count(),
            'una fila de dato real (editable, sin formula) en la posicion candidata nunca debe capturarse como TOTAL'
        );

        $rows = [];
        foreach (RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get() as $rd) {
            $rows[$rd->data['row_number']] = $rd->data;
        }
        $this->assertArrayNotHasKey(69, $rows, 'fila 69 esta fuera de [filaInicioDatos:filaFinDatos] de NORM -- no pertenece a ninguna seccion declarada');
        $this->assertArrayHasKey(70, $rows, 'fila 70 (dato real dentro de NORM) debe persistir normalmente, sin cambio');
        $this->assertSame(9, $rows[70]['values']['B']);
    }

    public function test_labeled_leading_beyond_bounds_still_uses_mechanism_6(): void
    {
        [$upload] = $this->runUpload();

        $tt = RemTechnicalTotal::where('rem_upload_id', $upload->id)
            ->where('rem_section_code', 'LAB')
            ->where('row_number', 89)
            ->first();

        $this->assertNotNull($tt, 'la fila 89 (CON etiqueta TOTAL) debe capturarse via mecanismo #6');
        $this->assertSame('embedded_leading_total_row', $tt->exclusion_reason, 'con etiqueta textual, la captura debe atribuirse a #6, no al mecanismo formula-based nuevo');
        $this->assertSame('TOTAL', $tt->concept);
        $this->assertSame(8, $tt->values['B']);
    }

    public function test_only_captured_in_technical_totals_never_in_rem_data_across_all_sections(): void
    {
        [$upload] = $this->runUpload();

        $capturedRows = RemTechnicalTotal::where('rem_upload_id', $upload->id)->pluck('row_number')->all();
        $this->assertEqualsCanonicalizing([19, 89], $capturedRows, 'solo LFB (19) y LAB (89) deben capturarse -- INC/EXT/NORM nunca');

        $remDataRows = RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get()->map(fn($rd) => $rd->data['row_number'])->all();
        foreach ([19, 89] as $capturedRow) {
            $this->assertNotContains($capturedRow, $remDataRows);
        }
    }

    public function test_existing_mechanisms_untouched(): void
    {
        $this->createActiveStructure();
        $this->seedCellData();
        $parser = app(RemParserService::class);

        // Mecanismo #6 real, invocado directamente contra la fila 89 (LAB) --
        // debe confirmar exactamente igual que antes de este cambio.
        $refMech6 = new \ReflectionMethod($parser, 'isEmbeddedLeadingTotalRow');
        $refMech6->setAccessible(true);
        $confirmed = $refMech6->invoke($parser, self::SHEET, 'LAB', 89, true, ['B', 'C']);
        $this->assertTrue($confirmed, 'mecanismo #6 debe seguir confirmando la fila 89 exactamente igual que siempre');
    }

    public function test_no_active_structure_is_modified(): void
    {
        $this->runUpload();

        $active = RemTemplateStructure::where('status', 'active')->first();
        $estructura = is_string($active->estructura) ? json_decode($active->estructura, true) : $active->estructura;
        $seccionLFB = collect($estructura['forms'][0]['sections'])->firstWhere('codigo', 'LFB');

        $this->assertSame(20, $seccionLFB['filaInicioDatos'], 'la estructura activa NO debe modificarse');
    }
}
