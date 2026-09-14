<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
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
 * BM-5.3 (2026-09-14) -- hallazgo real BM18A/B fila 186 (ver tambien A06/L
 * fila 181 y A33/E fila 74, mismo patron real preexistente en Serie A,
 * documentado en BM-5.2/BM-5.3): una fila TOTAL genuina (etiqueta propia +
 * formulas de agregacion hacia atras en TODAS sus columnas de valor)
 * dejaba de reconocerse como tecnica si alguna de esas columnas era
 * genuinamente editable/desbloqueada pero GENUINAMENTE VACIA (nunca usada
 * para captura real en esa fila especifica) -- inconsistencia real del
 * template de origen (una columna de captura que la hoja no usa para la
 * fila de cierre), no un patron de datos. Cubre isTrailingTotalRow() (#8) e
 * isEmbeddedBackwardSubtotalRow() (#12), ambas afectadas por el mismo
 * chequeo `esCapturableReal`.
 *
 * IMPORTANTE (misma leccion de BM-3.6): el nombre de hoja es 100% ficticio
 * ('HOJAVACIA') para no colisionar con cell_data real ya escaneada en el
 * filesystem de este entorno (BM18/BM18A).
 */
class RemParserServiceEmbeddedTotalRowGenuineEmptyEditableColumnTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJAVACIA';
    private const YEAR = 2093;
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
            ['letra' => 'B', 'label' => 'Dato1', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Dato2', 'esTotal' => false],
            ['letra' => 'D', 'label' => 'ExtraInactiva', 'esTotal' => false],
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
                            'data_start_row' => 10,
                            'concept_column' => 'A',
                            'professional_column' => null,
                            'total_column' => null,
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Concepto'],
                            ['letter' => 'B', 'header' => 'Dato1'],
                            ['letter' => 'C', 'header' => 'Dato2'],
                            ['letter' => 'D', 'header' => 'ExtraInactiva'],
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
            'hash_estructura' => sha1('test-structure-empty-editable-total'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => ['forms' => [['sheetName' => self::SHEET, 'sections' => $secciones]]],
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

        // TOTC (10-12): patron REAL de BM18A/B fila 186 -- B/C son formulas
        // de agregacion hacia atras en la fila de cierre, D es la columna
        // "anomala": editable/desbloqueada pero GENUINAMENTE VACIA solo en
        // la fila de cierre (bloqueada en las filas de dato). Debe
        // reconocerse como TOTAL tecnico.
        $svc->saveCellData(self::SHEET, 'TOTC', [
            'A10' => $this->cell('Item 1', false, true),
            'B10' => $this->cell('5', true, false),
            'C10' => $this->cell('2', true, false),
            'D10' => $this->cell(null, false, true),
            'A11' => $this->cell('Item 2', false, true),
            'B11' => $this->cell('3', true, false),
            'C11' => $this->cell('1', true, false),
            'D11' => $this->cell(null, false, true),
            'A12' => $this->cell('TOTAL', false, true),
            'B12' => $this->cell(null, false, true, true, '=SUM(B10:B11)'),
            'C12' => $this->cell(null, false, true, true, '=SUM(C10:C11)'),
            'D12' => $this->cell(null, true, false), // editable, desbloqueada, VACIA
        ]);

        // TOTD (20-22): identico patron, EXCEPTO que D en la fila de
        // cierre SI tiene un valor real capturado (D22='7'). Debe
        // permanecer como dato ordinario -- la presencia de un valor real
        // sigue descalificando, el fix no relaja esa evidencia.
        $svc->saveCellData(self::SHEET, 'TOTD', [
            'A20' => $this->cell('Item 1', false, true),
            'B20' => $this->cell('5', true, false),
            'C20' => $this->cell('2', true, false),
            'D20' => $this->cell(null, false, true),
            'A21' => $this->cell('Item 2', false, true),
            'B21' => $this->cell('3', true, false),
            'C21' => $this->cell('1', true, false),
            'D21' => $this->cell(null, false, true),
            'A22' => $this->cell('TOTAL', false, true),
            'B22' => $this->cell(null, false, true, true, '=SUM(B20:B21)'),
            'C22' => $this->cell(null, false, true, true, '=SUM(C20:C21)'),
            'D22' => $this->cell('7', true, false), // editable, desbloqueada, CON VALOR REAL
        ]);

        // ORDX (30-31): fila ordinaria (sin etiqueta TOTAL), con columna D
        // editable/vacia -- debe permanecer ordinaria sin importar D.
        $svc->saveCellData(self::SHEET, 'ORDX', [
            'A30' => $this->cell('Item Suelto', false, true),
            'B30' => $this->cell('9', true, false),
            'C30' => $this->cell('4', true, false),
            'D30' => $this->cell(null, true, false),
            'A31' => $this->cell('Item Suelto 2', false, true),
            'B31' => $this->cell('1', true, false),
            'C31' => $this->cell('1', true, false),
            'D31' => $this->cell(null, true, false),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        // TOTC (10-12)
        $sheet->setCellValue('A10', 'Item 1');
        $sheet->setCellValue('B10', 5);
        $sheet->setCellValue('C10', 2);
        $sheet->setCellValue('A11', 'Item 2');
        $sheet->setCellValue('B11', 3);
        $sheet->setCellValue('C11', 1);
        $sheet->setCellValue('A12', 'TOTAL');
        $sheet->setCellValue('B12', '=SUM(B10:B11)');
        $sheet->setCellValue('C12', '=SUM(C10:C11)');
        // D12 deliberadamente sin valor (vacia)

        // TOTD (20-22)
        $sheet->setCellValue('A20', 'Item 1');
        $sheet->setCellValue('B20', 5);
        $sheet->setCellValue('C20', 2);
        $sheet->setCellValue('A21', 'Item 2');
        $sheet->setCellValue('B21', 3);
        $sheet->setCellValue('C21', 1);
        $sheet->setCellValue('A22', 'TOTAL');
        $sheet->setCellValue('B22', '=SUM(B20:B21)');
        $sheet->setCellValue('C22', '=SUM(C20:C21)');
        $sheet->setCellValue('D22', 7); // valor real capturado

        // ORDX (30-31)
        $sheet->setCellValue('A30', 'Item Suelto');
        $sheet->setCellValue('B30', 9);
        $sheet->setCellValue('C30', 4);
        $sheet->setCellValue('A31', 'Item Suelto 2');
        $sheet->setCellValue('B31', 1);
        $sheet->setCellValue('C31', 1);

        $path = storage_path('app/rem-uploads/test_empty_editable_total_' . uniqid() . '.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function parseUpload(): array
    {
        $this->createActiveStructure([
            'TOTC' => ['start' => 10, 'end' => 12],
            'TOTD' => ['start' => 20, 'end' => 22],
            'ORDX' => ['start' => 30, 'end' => 31],
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
                'name' => 'Centro Test Editable Vacia',
                'code_deis' => 'CEV' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_empty_editable_total.xlsx',
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
        $technicalByRow = [];
        foreach ($result->technicalTotals as $entry) {
            $technicalByRow[$entry['rem_section_code']][$entry['row_number']] = $entry;
        }

        return [$upload, $result, $byRow, $technicalByRow];
    }

    /** Caso A: TOTAL + formulas backward + celda editable VACIA => technical. */
    public function test_genuine_total_row_with_empty_editable_column_is_classified_as_technical(): void
    {
        [, , $byRow, $technicalByRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(12, $byRow['TOTC'] ?? [], 'TOTC fila 12 no debe persistir en rem_data');
        $this->assertArrayHasKey(12, $technicalByRow['TOTC'] ?? [], 'TOTC fila 12 debe capturarse en rem_technical_totals');
        $this->assertSame('embedded_trailing_total_row', $technicalByRow['TOTC'][12]['exclusion_reason']);
        $this->assertSame(8, $technicalByRow['TOTC'][12]['values']['B'], 'B12 = SUM(B10:B11) = 5+3 = 8');
        $this->assertSame(3, $technicalByRow['TOTC'][12]['values']['C'], 'C12 = SUM(C10:C11) = 2+1 = 3');

        // Las filas de dato reales de TOTC deben persistir normalmente.
        $this->assertArrayHasKey(10, $byRow['TOTC'] ?? []);
        $this->assertArrayHasKey(11, $byRow['TOTC'] ?? []);
    }

    /**
     * Caso B (negativo, critico): TOTAL + formulas backward + celda
     * editable CON VALOR REAL => el fix NO debe ocultar esa captura real.
     * La fila permanece ordinaria (rem_data), tal como antes del fix.
     */
    public function test_total_row_with_real_captured_value_in_editable_column_stays_as_ordinary_data(): void
    {
        [, , $byRow, $technicalByRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(22, $technicalByRow['TOTD'] ?? [], 'TOTD fila 22 NO debe clasificarse como technical -- D22 tiene un valor real capturado');
        $this->assertArrayHasKey(22, $byRow['TOTD'] ?? [], 'TOTD fila 22 debe persistir como dato ordinario, preservando el valor real de D22');
        $this->assertSame(7, $byRow['TOTD'][22]['values']['D']);
    }

    /** Caso C: fila ordinaria (sin etiqueta TOTAL) + celda editable vacia => sigue siendo fila ordinaria. */
    public function test_ordinary_row_with_empty_editable_column_and_no_total_label_stays_ordinary(): void
    {
        [, , $byRow, $technicalByRow] = $this->parseUpload();

        $this->assertArrayHasKey(30, $byRow['ORDX'] ?? []);
        $this->assertArrayHasKey(31, $byRow['ORDX'] ?? []);
        $this->assertArrayNotHasKey(30, $technicalByRow['ORDX'] ?? []);
        $this->assertArrayNotHasKey(31, $technicalByRow['ORDX'] ?? []);
    }

    public function test_no_active_structure_is_modified_by_this_fix(): void
    {
        $this->parseUpload();

        $active = RemTemplateStructure::where('status', 'active')->first();
        $estructura = is_string($active->estructura) ? json_decode($active->estructura, true) : $active->estructura;
        $seccionTOTC = collect($estructura['forms'][0]['sections'])->firstWhere('codigo', 'TOTC');

        $this->assertSame(12, $seccionTOTC['filaFinDatos'], 'la estructura activa no debe modificarse por este fix');
    }
}
