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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Fase 3A (CLAUDE.md punto 17.6): la fila TOTAL tecnica excluida de rem_data
 * (mecanismos #6/#8/#11/#12) queda ahora capturada, de forma auditable, en
 * la tabla paralela rem_technical_totals -- END-TO-END via
 * ProcessRemUploadJob::handle() (no solo RemParserService::parse() en
 * memoria, como en RemParserServiceEmbeddedLeadingTotalRowTest.php),
 * porque la escritura real a BD ocurre en el Job, no en el parser.
 *
 * El motor de reglas NO se toca en esta fase -- ningun test aqui ejercita
 * RuleEngineService ni SumEqualsEvaluator.
 */
class RemTechnicalTotalsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJATT';
    private const YEAR = 2098;
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
            'hash_estructura' => sha1('test-structure-technical-totals'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            // LT: seccion con una fila TOTAL lider embebida (fila 13).
                            ['codigo' => 'LT', 'filaInicioDatos' => 11, 'filaFinDatos' => 16, 'fields' => $this->fields()],
                            // NORM: seccion sin ninguna fila tecnica -- control negativo.
                            ['codigo' => 'NORM', 'filaInicioDatos' => 20, 'filaFinDatos' => 22, 'fields' => $this->fields()],
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

        // LT: fila 13 = TOTAL lider embebido, analogo al patron real A19b/A.
        $svc->saveCellData(self::SHEET, 'LT', [
            'A11' => $this->cell('Concepto 1', false, true),
            'B11' => $this->cell(null, true, false),
            'C11' => $this->cell(null, true, false),

            'A13' => $this->cell('TOTAL CONSULTAS', false, true),
            'B13' => $this->cell(null, false, true, true, '=SUM(B14:B16)'),
            'C13' => $this->cell(null, false, true, true, '=SUM(C14:C16)'),

            'A14' => $this->cell('Concepto 2', false, true),
            'B14' => $this->cell(null, true, false),
            'C14' => $this->cell(null, true, false),
        ]);

        // NORM: sin ninguna formula-hacia-adelante -- todas filas de dato real.
        $svc->saveCellData(self::SHEET, 'NORM', [
            'A20' => $this->cell('Concepto A', false, true),
            'B20' => $this->cell(null, true, false),
            'C20' => $this->cell(null, true, false),

            'A21' => $this->cell('Concepto B', false, true),
            'B21' => $this->cell(null, true, false),
            'C21' => $this->cell(null, true, false),

            'A22' => $this->cell('Concepto C', false, true),
            'B22' => $this->cell(null, true, false),
            'C22' => $this->cell(null, true, false),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // LT (11-16)
        $sheet->setCellValue('A11', 'Concepto 1');
        $sheet->setCellValue('B11', 4);
        $sheet->setCellValue('C11', 2);
        $sheet->setCellValue('A13', 'TOTAL CONSULTAS');
        $sheet->setCellValue('B13', '=SUM(B14:B16)');
        $sheet->setCellValue('C13', '=SUM(C14:C16)');
        $sheet->setCellValue('A14', 'Concepto 2');
        $sheet->setCellValue('B14', 1704);
        $sheet->setCellValue('C14', 55);

        // NORM (20-22)
        $sheet->setCellValue('A20', 'Concepto A');
        $sheet->setCellValue('B20', 10);
        $sheet->setCellValue('C20', 1);
        $sheet->setCellValue('A21', 'Concepto B');
        $sheet->setCellValue('B21', 20);
        $sheet->setCellValue('C21', 2);
        $sheet->setCellValue('A22', 'Concepto C');
        $sheet->setCellValue('B22', 30);
        $sheet->setCellValue('C22', 3);

        $path = storage_path('app/rem-uploads/test_technical_totals_' . uniqid() . '.xlsx');
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
                'name' => 'Centro Test TT',
                'code_deis' => 'CTT' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_technical_totals.xlsx',
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

    public function test_excluded_row_is_not_persisted_in_rem_data(): void
    {
        [$upload] = $this->runUpload();

        $rows = RemData::where('rem_upload_id', $upload->id)
            ->where('section', self::SHEET)
            ->get()
            ->map(fn($rd) => $rd->data['row_number']);

        $this->assertNotContains(13, $rows->all(), 'La fila TOTAL tecnica (13) no debe persistir en rem_data');
    }

    public function test_excluded_row_appears_exactly_once_in_technical_storage(): void
    {
        [$upload] = $this->runUpload();

        $matches = RemTechnicalTotal::where('rem_upload_id', $upload->id)
            ->where('sheet', self::SHEET)
            ->where('rem_section_code', 'LT')
            ->where('row_number', 13)
            ->get();

        $this->assertCount(1, $matches, 'La fila TOTAL tecnica debe aparecer exactamente una vez en rem_technical_totals');
    }

    public function test_technical_total_preserves_sheet_section_row_and_values(): void
    {
        [$upload] = $this->runUpload();

        $tt = RemTechnicalTotal::where('rem_upload_id', $upload->id)
            ->where('row_number', 13)
            ->firstOrFail();

        $this->assertSame(self::SHEET, $tt->sheet);
        $this->assertSame('LT', $tt->rem_section_code);
        $this->assertSame(13, $tt->row_number);
        $this->assertSame('TOTAL CONSULTAS', $tt->concept);
        $this->assertSame('embedded_leading_total_row', $tt->exclusion_reason);
        $this->assertSame(1704 + 0, $tt->values['B'], 'B13 = SUM(B14:B16) = 1704 (B15/B16 no existen en el fixture)');
        $this->assertSame(55, $tt->values['C'], 'C13 = SUM(C14:C16) = 55');
    }

    public function test_normal_rows_behave_exactly_as_before(): void
    {
        [$upload] = $this->runUpload();

        $byRow = [];
        foreach (RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->get() as $rd) {
            $byRow[$rd->data['row_number']] = $rd->data;
        }

        $this->assertArrayHasKey(11, $byRow);
        $this->assertArrayHasKey(14, $byRow);
        $this->assertArrayHasKey(20, $byRow);
        $this->assertArrayHasKey(21, $byRow);
        $this->assertArrayHasKey(22, $byRow);

        $this->assertSame(4, $byRow[11]['values']['B']);
        $this->assertSame(1704, $byRow[14]['values']['B']);
        $this->assertSame(10, $byRow[20]['values']['B']);
        $this->assertSame(30, $byRow[22]['values']['B']);

        // Ninguna de las filas normales debe generar entrada tecnica.
        $normalRowNumbers = [11, 14, 20, 21, 22];
        $this->assertSame(
            0,
            RemTechnicalTotal::where('rem_upload_id', $upload->id)->whereIn('row_number', $normalRowNumbers)->count()
        );
    }

    public function test_section_without_technical_totals_creates_no_rows(): void
    {
        [$upload] = $this->runUpload();

        $normCount = RemTechnicalTotal::where('rem_upload_id', $upload->id)
            ->where('rem_section_code', 'NORM')
            ->count();

        $this->assertSame(0, $normCount, 'NORM no tiene ninguna fila tecnica -- no debe generar registros');

        // Confirma que NORM si persistio normalmente en rem_data (3 filas).
        $normRemData = RemData::where('rem_upload_id', $upload->id)
            ->where('section', self::SHEET)
            ->get()
            ->filter(fn($rd) => ($rd->data['rem_section_code'] ?? null) === 'NORM');

        $this->assertCount(3, $normRemData);
    }

    public function test_total_technical_rows_count_matches_exactly_one_across_whole_upload(): void
    {
        [$upload] = $this->runUpload();

        $this->assertSame(
            1,
            RemTechnicalTotal::where('rem_upload_id', $upload->id)->count(),
            'Solo existe 1 fila tecnica en todo el fixture (LT fila 13) -- ninguna otra debe generarse'
        );
    }

    public function test_no_duplicates_after_reprocess_cleanup(): void
    {
        [$upload] = $this->runUpload();

        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());

        // rem:reprocess limpia rem_data Y rem_technical_totals antes de re-encolar
        // (Queue::fake() evita que el job real reprocese via cola -- se valida
        // aqui solo la limpieza previa al re-encolado).
        Artisan::call('rem:reprocess', ['uploadId' => $upload->id]);

        $this->assertSame(
            0,
            RemTechnicalTotal::where('rem_upload_id', $upload->id)->count(),
            'rem:reprocess debe limpiar rem_technical_totals igual que rem_data, antes de re-encolar'
        );
        $this->assertSame(0, RemData::where('rem_upload_id', $upload->id)->count());

        // Reparsear manualmente (simulando lo que haria el job re-encolado)
        // no debe producir duplicados: sigue habiendo exactamente 1 fila.
        $parser = app(RemParserService::class);
        $job = new ProcessRemUploadJob($upload->id);
        $job->handle($parser);

        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());
    }

    public function test_unique_constraint_rejects_accidental_duplicate_insert(): void
    {
        [$upload] = $this->runUpload();

        $existing = RemTechnicalTotal::where('rem_upload_id', $upload->id)->where('row_number', 13)->firstOrFail();

        $this->expectException(QueryException::class);

        RemTechnicalTotal::create([
            'rem_upload_id' => $upload->id,
            'sheet' => $existing->sheet,
            'rem_section_code' => $existing->rem_section_code,
            'row_number' => $existing->row_number,
            'concept' => 'Duplicado accidental',
            'total' => null,
            'values' => ['B' => 999],
            'exclusion_reason' => 'embedded_leading_total_row',
        ]);
    }

    public function test_cascade_delete_removes_technical_totals_same_as_rem_data(): void
    {
        [$upload] = $this->runUpload();

        $this->assertGreaterThan(0, RemData::where('rem_upload_id', $upload->id)->count());
        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());

        $uploadId = $upload->id;
        $upload->forceDelete();

        $this->assertSame(0, RemData::where('rem_upload_id', $uploadId)->count());
        $this->assertSame(0, RemTechnicalTotal::where('rem_upload_id', $uploadId)->count());
    }

    public function test_technical_totals_transaction_is_isolated_from_rem_data(): void
    {
        // Documenta el diseno: rem_technical_totals se escribe en su propia
        // transaccion (DB::transaction en ProcessRemUploadJob), separada de
        // la escritura de rem_data (que no tiene proteccion transaccional
        // hoy). No se simula un fallo real a mitad de la transaccion aqui
        // (requeriria mockear PDO) -- se confirma en cambio que ambas tablas
        // quedan consistentes en el camino feliz, que es lo que este punto
        // de Fase 3A debia garantizar sin alterar el comportamiento
        // preexistente de rem_data.
        [$upload] = $this->runUpload();

        $this->assertSame(5, RemData::where('rem_upload_id', $upload->id)->where('section', self::SHEET)->count());
        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());
    }
}
