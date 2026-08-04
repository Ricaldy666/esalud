<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Services\RemParserService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cubre la deteccion generica, basada en cell_data, de una columna de
 * sub-categoria (etiqueta bloqueada, no editable, no formula, dentro de la
 * zona de etiquetas) que antes se intentaba parsear como numerica -- patron
 * real detectado en A03 (columna B de 12 secciones, 90 errores de validacion).
 * El texto se preserva en data.subcategory en vez de intentarse como dato.
 */
class RemParserServiceSubcategoryColumnTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJASC';
    private const YEAR = 2097;
    private const REM_TYPE = 'S';

    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function cell(?string $value, bool $editable, bool $blocked, bool $formula = false, ?string $tipoCelda = null, ?string $zona = null): array
    {
        return [
            'valor_bruto' => $value,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'tipo_celda' => $tipoCelda,
            'zona' => $zona,
        ];
    }

    private function labelCell(?string $value): array
    {
        return $this->cell($value, false, true, false, 'etiqueta', 'zona_etiquetas');
    }

    private function dataCell(?string $value): array
    {
        return $this->cell($value, true, false, false, 'vacio', 'zona_captura');
    }

    /**
     * Seccion A (12-15): A=concepto, B=profesional, C=total(formula), D=columna
     * de sub-categoria (etiqueta consistente en las 4 filas), E=dato numerico real.
     * Seccion B (20-22): sin ninguna columna de etiqueta -- control de regresion
     * para "seccion sin subcategoria no debe tener la clave".
     * Seccion C (30-32): columna D con evidencia INCONSISTENTE entre filas.
     */
    private function createActiveStructure(): void
    {
        $fieldsA = [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false],
            ['letra' => 'B', 'label' => 'Profesional', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'D', 'label' => 'Resultado', 'esTotal' => false],
            ['letra' => 'E', 'label' => 'Dato', 'esTotal' => false],
        ];
        $fieldsB = [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'E', 'label' => 'Dato', 'esTotal' => false],
        ];
        $fieldsC = [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'D', 'label' => 'Resultado', 'esTotal' => false],
            ['letra' => 'E', 'label' => 'Dato', 'esTotal' => false],
        ];

        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-subcategory'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            ['codigo' => 'A', 'filaInicioDatos' => 12, 'filaFinDatos' => 15, 'fields' => $fieldsA],
                            ['codigo' => 'B', 'filaInicioDatos' => 20, 'filaFinDatos' => 22, 'fields' => $fieldsB],
                            ['codigo' => 'C', 'filaInicioDatos' => 30, 'filaFinDatos' => 32, 'fields' => $fieldsC],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);

        // Seccion A: D es etiqueta consistente en las 4 filas (12-15). A, B y C
        // TAMBIEN se marcan como si fueran "etiqueta" para probar que igual se
        // excluyen por ser concept/professional/total, no por su cell_data.
        $svc->saveCellData(self::SHEET, 'A', [
            'A12' => $this->labelCell('Concepto uno'),
            'B12' => $this->labelCell('Enfermera/o'),
            'C12' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D12' => $this->labelCell('Riesgo Bajo'),
            'E12' => $this->dataCell(null),

            'A13' => $this->labelCell('Concepto uno'),
            'B13' => $this->labelCell('Enfermera/o'),
            'C13' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D13' => $this->labelCell('Riesgo Medio'),
            'E13' => $this->dataCell(null),

            'A14' => $this->labelCell('Concepto uno'),
            'B14' => $this->labelCell('Enfermera/o'),
            'C14' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D14' => $this->labelCell('Riesgo Alto'),
            'E14' => $this->dataCell(null),

            // fila 15: D queda con texto vacio a proposito (celda etiqueta real,
            // pero sin contenido en este upload) -> no debe agregarse la clave.
            'A15' => $this->labelCell('Concepto uno'),
            'B15' => $this->labelCell('Enfermera/o'),
            'C15' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D15' => $this->labelCell(null),
            'E15' => $this->dataCell(null),
        ]);

        // Seccion B: sin ninguna columna de etiqueta -- todo es dato o control.
        $svc->saveCellData(self::SHEET, 'B', [
            'A20' => $this->labelCell('Concepto B'),
            'C20' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'E20' => $this->dataCell(null),
        ]);

        // Seccion C: D es etiqueta en la fila 30 pero editable/dato en la fila 31
        // -> evidencia inconsistente, no debe clasificarse como subcategory. E es
        // una columna editable real en las 3 filas, para que las filas persistan
        // independientemente de lo que pase con D.
        $svc->saveCellData(self::SHEET, 'C', [
            'A30' => $this->labelCell('Concepto C'),
            'C30' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D30' => $this->labelCell('Bajo'),
            'E30' => $this->dataCell(null),

            'A31' => $this->labelCell('Concepto C'),
            'C31' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D31' => $this->dataCell(5),
            'E31' => $this->dataCell(null),

            'A32' => $this->labelCell('Concepto C'),
            'C32' => $this->cell(null, false, true, true, 'formula_total', 'zona_total'),
            'D32' => $this->labelCell('Alto'),
            'E32' => $this->dataCell(null),
        ]);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        // Seccion A (12-15)
        $sheet->setCellValue('A12', 'Concepto uno');
        $sheet->setCellValue('B12', 'Enfermera/o');
        $sheet->setCellValue('D12', 'Riesgo Bajo');
        $sheet->setCellValue('E12', 3);

        $sheet->setCellValue('A13', 'Concepto uno');
        $sheet->setCellValue('B13', 'Enfermera/o');
        $sheet->setCellValue('D13', 'Riesgo Medio');
        $sheet->setCellValue('E13', 2);

        $sheet->setCellValue('A14', 'Concepto uno');
        $sheet->setCellValue('B14', 'Enfermera/o');
        $sheet->setCellValue('D14', 'Riesgo Alto');
        $sheet->setCellValue('E14', 0);

        $sheet->setCellValue('A15', 'Concepto uno');
        $sheet->setCellValue('B15', 'Enfermera/o');
        // D15 se deja vacio a proposito
        $sheet->setCellValue('E15', 1);

        // Seccion B (20-22): sin columna de etiqueta
        $sheet->setCellValue('A20', 'Concepto B');
        $sheet->setCellValue('E20', 7);
        $sheet->setCellValue('A21', 'Concepto B');
        $sheet->setCellValue('E21', 8);
        $sheet->setCellValue('A22', 'Concepto B');
        $sheet->setCellValue('E22', 9);

        // Seccion C (30-32): D inconsistente (etiqueta, numero, etiqueta); E real;
        // C (total) con valor real -- los errores solo se reportan cuando la fila
        // tiene contenido Y total no nulo (logica preexistente del parser).
        $sheet->setCellValue('A30', 'Concepto C');
        $sheet->setCellValue('C30', 11);
        $sheet->setCellValue('D30', 'Bajo');
        $sheet->setCellValue('E30', 11);
        $sheet->setCellValue('A31', 'Concepto C');
        $sheet->setCellValue('C31', 17);
        $sheet->setCellValue('D31', 5);
        $sheet->setCellValue('E31', 12);
        $sheet->setCellValue('A32', 'Concepto C');
        $sheet->setCellValue('C32', 13);
        $sheet->setCellValue('D32', 'Alto');
        $sheet->setCellValue('E32', 13);

        $path = storage_path('app/rem-uploads/test_subcategory_' . uniqid() . '.xlsx');
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

        $template = RemTemplate::create([
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
                            'header_row' => 9,
                            'data_start_row' => 12,
                            'concept_column' => 'A',
                            'professional_column' => null,
                            'total_column' => 'C',
                        ],
                        'columns' => [
                            ['letter' => 'A', 'header' => 'Concepto'],
                            ['letter' => 'B', 'header' => 'Profesional'],
                            ['letter' => 'C', 'header' => 'Total'],
                            ['letter' => 'D', 'header' => 'Resultado'],
                            ['letter' => 'E', 'header' => 'Dato'],
                        ],
                        'validation_rules' => ['data_type' => 'integer', 'min' => 0, 'max' => null, 'allow_null' => true],
                    ],
                ],
            ],
        ]);

        $storedPath = $this->buildSpreadsheet();

        $upload = RemUpload::create([
            'rem_type' => self::REM_TYPE,
            'year' => self::YEAR,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Test SC',
                'code_deis' => 'CTSC' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'testsc.xlsx',
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

        return [$result, $byRow];
    }

    public function test_consistent_label_column_is_excluded_from_values_and_not_validated_as_numeric(): void
    {
        [$result, $byRow] = $this->parseUpload();

        foreach ([12, 13, 14, 15] as $row) {
            $this->assertArrayNotHasKey('D', $byRow[$row]['values'], "D no debe aparecer en values de la fila {$row} (seccion A), es la columna de sub-categoria");
        }

        // Filtrado por fila 12-15 (seccion A): la seccion C del mismo fixture
        // usa la misma letra de columna D con otro rol (evidencia inconsistente,
        // sigue siendo numerica ahi a proposito -- ver test de inconsistencia).
        $dataErrorsSeccionA = array_filter(
            $result->errors['errors'] ?? [],
            fn($e) => $e['column'] === 'D' && in_array($e['row'], [12, 13, 14, 15], true)
        );
        $this->assertCount(0, $dataErrorsSeccionA, 'No debe generarse ningun error de validacion numerica para la columna D en la seccion A');
    }

    public function test_consistent_label_column_text_is_captured_as_subcategory(): void
    {
        [, $byRow] = $this->parseUpload();

        $this->assertSame('Riesgo Bajo', $byRow[12]['subcategory']);
        $this->assertSame('Riesgo Medio', $byRow[13]['subcategory']);
        $this->assertSame('Riesgo Alto', $byRow[14]['subcategory']);
    }

    public function test_section_without_label_column_has_no_subcategory_key(): void
    {
        [, $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey('subcategory', $byRow[20], 'Seccion B no tiene columna de sub-categoria, no debe agregarse la clave');
        $this->assertArrayNotHasKey('subcategory', $byRow[21]);
        $this->assertArrayNotHasKey('subcategory', $byRow[22]);
    }

    public function test_inconsistent_evidence_across_rows_is_not_auto_classified(): void
    {
        [$result, $byRow] = $this->parseUpload();

        // D no debe clasificarse como subcategory en la seccion C -> sigue
        // intentandose parsear como numerica (y por lo tanto generando error
        // de validacion en las filas donde el valor real es texto).
        $this->assertArrayNotHasKey('subcategory', $byRow[30], 'Evidencia inconsistente no debe auto-clasificarse');
        $this->assertArrayNotHasKey('subcategory', $byRow[31]);
        $this->assertArrayNotHasKey('subcategory', $byRow[32]);

        $dataErrorsD = array_values(array_filter($result->errors['errors'] ?? [], fn($e) => $e['column'] === 'D' && in_array($e['row'], [30, 32], true)));
        $this->assertCount(2, $dataErrorsD, 'Sin clasificacion automatica, D30/D32 siguen generando el mismo error de validacion que antes del fix');
        $this->assertSame(5, $byRow[31]['values']['D'], 'D31 es un numero real, debe seguir parseando correctamente');
    }

    public function test_concept_professional_and_total_columns_are_never_selected_as_subcategory(): void
    {
        [, $byRow] = $this->parseUpload();

        // A, B y C tienen cell_data de "etiqueta" identica a D, pero son
        // concept/professional/total -- nunca deben terminar como subcategory,
        // ni tampoco confundir la deteccion e impedir que D sea seleccionada.
        $this->assertSame('Concepto uno', $byRow[12]['concept']);
        $this->assertSame('Enfermera/o', $byRow[12]['professional']);
        $this->assertArrayNotHasKey('A', $byRow[12]['values'], 'A es concept_column, nunca entra en values');
        $this->assertArrayNotHasKey('B', $byRow[12]['values'], 'B es professional_column, nunca entra en values');
        // C es la columna de total: SIEMPRE se agrega a values con el total ya
        // calculado (comportamiento preexistente, sin relacion con este fix).
        $this->assertArrayHasKey('C', $byRow[12]['values']);
        $this->assertSame($byRow[12]['total'], $byRow[12]['values']['C']);
        $this->assertSame('Riesgo Bajo', $byRow[12]['subcategory'], 'D debe seguir siendo detectada aunque A/B/C tambien parezcan etiqueta');
    }

    public function test_empty_label_cell_does_not_add_subcategory_key(): void
    {
        [, $byRow] = $this->parseUpload();

        $this->assertArrayNotHasKey('subcategory', $byRow[15], 'D15 esta vacia en el Excel real; no debe agregarse la clave aunque la columna si sea subcategory_column');
    }

    public function test_normal_data_column_e_is_unaffected(): void
    {
        [, $byRow] = $this->parseUpload();

        $this->assertSame(3, $byRow[12]['values']['E']);
        $this->assertSame(2, $byRow[13]['values']['E']);
        $this->assertSame(0, $byRow[14]['values']['E']);
    }
}
