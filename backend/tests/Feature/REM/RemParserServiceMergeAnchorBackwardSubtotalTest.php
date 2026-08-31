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
 * Cubre RemParserService::isEmbeddedBackwardSubtotalRow() end-to-end
 * (pipeline real parse() -> extractedData/technicalTotals) para la
 * extension de resolucion de ancla de merge vertical (Opcion C, puntos
 * 17.26-17.29 de CLAUDE.md, implementada 2026-08-28) -- replica el patron
 * real A09/I filas 331-336 (A331:A336 fusionada) a escala reducida.
 *
 * Complementa (no reemplaza) RemParserServiceEmbeddedBackwardSubtotalRowTest.php
 * (casos sin fusion, sin tocar).
 */
class RemParserServiceMergeAnchorBackwardSubtotalTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJAMRG';
    private const YEAR = 2096;
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
                'sheets' => [[
                    'sheet_name' => self::SHEET,
                    'section_code' => self::SHEET,
                    'is_required' => true,
                    'structure' => [
                        'header_row' => 3, 'data_start_row' => 90,
                        'concept_column' => 'A', 'professional_column' => null, 'total_column' => null,
                    ],
                    'columns' => [
                        ['letter' => 'A', 'header' => 'Concepto'],
                        ['letter' => 'C', 'header' => 'Data1'],
                    ],
                    'validation_rules' => ['data_type' => 'integer', 'min' => 0, 'max' => null, 'allow_null' => true],
                ]],
            ],
        ]);
    }

    private function createActiveStructure(): void
    {
        RemTemplateStructure::create([
            'anio' => self::YEAR, 'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-merge-anchor-backward-subtotal'),
            'version_number' => 1, 'status' => 'active',
            'estructura' => ['forms' => [['sheetName' => self::SHEET, 'sections' => [
                ['codigo' => 'MRGTOT', 'filaInicioDatos' => 90, 'filaFinDatos' => 102, 'fields' => $this->fields()],
            ]]]],
        ]);
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $valorBruto = null, bool $combinada = false, ?string $rangoCombinado = null): array
    {
        return [
            'valor_bruto' => $valorBruto, 'es_editable' => $editable, 'esta_bloqueada' => $blocked,
            'es_formula' => $formula, 'formula' => $formulaText,
            'es_combinada' => $combinada, 'rango_combinado' => $rangoCombinado,
        ];
    }

    /**
     * Replica A09/I filas 331-336 a escala reducida (filas 90-102):
     *  90,91 = componentes reales.
     *  100 = fila ANCLA del merge A100:A102, "TOTAL", formula real hacia atras (equivalente a la fila 331).
     *  101 = subordinada, formula real hacia atras (equivalente a 332/334/335/336).
     *  102 = subordinada, formula con referencia hacia ADELANTE (fila 110, fuera de seccion -- patron AR337, equivalente a la fila 333).
     */
    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData(self::SHEET, 'MRGTOT', [
            'A90' => $this->cell(false, true, false, null, 'Item base 1'),
            'C90' => $this->cell(true, false),
            'A91' => $this->cell(false, true, false, null, 'Item base 2'),
            'C91' => $this->cell(true, false),
            'A100' => $this->cell(false, true, false, null, 'TOTAL', true, 'A100:A102'),
            'C100' => $this->cell(false, true, true, '=SUM(C90:C91)'),
            'A101' => $this->cell(false, true, false, null, null, true, 'A100:A102'),
            'C101' => $this->cell(false, true, true, '=SUM(C90:C91)'),
            'A102' => $this->cell(false, true, false, null, null, true, 'A100:A102'),
            'C102' => $this->cell(false, true, true, '=SUM(C110+C90:C91)'),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);
        $sheet->setCellValue('A3', 'ENCABEZADO HOJA');

        $sheet->setCellValue('A90', 'Item base 1');
        $sheet->setCellValue('C90', 5);
        $sheet->setCellValue('A91', 'Item base 2');
        $sheet->setCellValue('C91', 3);

        // Fila ancla + fusion vertical real A100:A102 (solo A100 lleva texto,
        // exactamente como se comporta un merge real de Excel/PhpSpreadsheet).
        $sheet->setCellValue('A100', 'TOTAL');
        $sheet->mergeCells('A100:A102');
        $sheet->setCellValue('C100', '=SUM(C90:C91)');
        $sheet->setCellValue('C101', '=SUM(C90:C91)');
        $sheet->setCellValue('C102', '=SUM(C110+C90:C91)');

        $path = storage_path('app/rem-uploads/test_merge_anchor_backward_' . uniqid() . '.xlsx');
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
            'rem_type' => self::REM_TYPE, 'year' => self::YEAR, 'month' => 1, 'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Test Merge Anchor', 'code_deis' => 'CTM' . uniqid(), 'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'test_merge_anchor_backward.xlsx',
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

    public function test_anchor_row_100_is_excluded_from_rem_data(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(100, $byRow['MRGTOT'] ?? [], 'fila ancla 100 (equivalente a la fila 331 real) no debe persistirse en rem_data -- comportamiento sin cambios');
    }

    public function test_merge_subordinate_row_101_with_valid_backward_formula_is_excluded_from_rem_data(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(101, $byRow['MRGTOT'] ?? [], 'fila subordinada 101 (merge vertical, formula real hacia atras, equivalente a 332/334/335/336) no debe persistirse en rem_data tras el fix');
    }

    public function test_merge_subordinate_row_102_with_forward_reference_like_ar337_is_not_excluded(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(102, $byRow['MRGTOT'] ?? [], 'fila subordinada 102 (referencia hacia adelante, patron AR337/regla 229 real, equivalente a la fila 333) DEBE seguir persistida en rem_data -- el fix de merge no la excluye');
    }

    public function test_normal_component_rows_are_unaffected(): void
    {
        [, , $byRow] = $this->parseUpload();

        $this->assertArrayHasKey(90, $byRow['MRGTOT'] ?? [], 'fila de dato real 90 debe persistirse, sin cambios');
        $this->assertArrayHasKey(91, $byRow['MRGTOT'] ?? [], 'fila de dato real 91 debe persistirse, sin cambios');
    }

    public function test_anchor_and_valid_subordinate_rows_enter_rem_technical_totals(): void
    {
        [, , , $technicalByRow] = $this->parseUpload();

        // La fila ancla (100) tiene texto propio en la columna de concepto
        // FIJA de la seccion (A) -- igual que la fila 331 real de A09/I
        // (confirmado en el punto 17.24 contra el upload real 186:
        // exclusion_reason=embedded_trailing_total_row) -- el mecanismo #8
        // (isTrailingTotalRow, chequeo de columna fija) la alcanza primero,
        // antes de que el codigo llegue siquiera a evaluar el mecanismo #12.
        // Esto NO es un defecto del fix de merge: es el mismo orden de
        // prioridad de mecanismos ya existente, sin ningun cambio hoy.
        $this->assertArrayHasKey(100, $technicalByRow['MRGTOT'] ?? [], 'fila ancla 100 debe capturarse en rem_technical_totals');
        $this->assertSame('embedded_trailing_total_row', $technicalByRow['MRGTOT'][100]['exclusion_reason'], 'la fila ancla, con texto propio en la columna de concepto fija, es alcanzada por el mecanismo #8 antes que por el #12 -- igual que la fila 331 real de A09/I');

        // La fila subordinada (101) tiene la columna de concepto FIJA (A)
        // vacia (merge) -- el mecanismo #8 la descarta de inmediato
        // (conceptoTienePropio=false) y NUNCA la alcanza; solo el mecanismo
        // #12, con la extension de resolucion de ancla, la detecta.
        $this->assertArrayHasKey(101, $technicalByRow['MRGTOT'] ?? [], 'fila subordinada 101 (excluida por el fix de merge) debe capturarse en rem_technical_totals');
        $this->assertSame('embedded_backward_subtotal_row', $technicalByRow['MRGTOT'][101]['exclusion_reason'], 'la fila subordinada, sin texto propio en la columna de concepto fija, solo es alcanzada por el mecanismo #12 (con la extension de resolucion de ancla)');
    }

    public function test_row_102_with_forward_reference_never_enters_rem_technical_totals(): void
    {
        [, , , $technicalByRow] = $this->parseUpload();

        $this->assertArrayNotHasKey(102, $technicalByRow['MRGTOT'] ?? [], 'fila 102 (nunca excluida) no debe aparecer en rem_technical_totals -- solo se capturan filas realmente excluidas');
    }
}
