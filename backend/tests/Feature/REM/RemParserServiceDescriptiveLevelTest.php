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
 * Nivel descriptivo adicional despues de detail_column (detail_extra_columns).
 *
 * Seccion X (40-45) reproduce la forma real de A25/A.3: A=concepto,
 * B=subcategoria, C=detalle, D=cuarto nivel que solo tiene texto propio en
 * 2 filas (41/42) y en el resto esta fusionada con C, E=total, F=dato.
 * Antes de este cambio D41/D42 se validaban como enteros y generaban
 * "No es un numero entero valido" (upload real 47, A25 D43/D44).
 *
 * Seccion Y (60-65) es el control de la proteccion original del umbral de
 * 3 filas: D es una columna de CAPTURA real (editable en las filas de datos)
 * con 2 filas de texto tipo encabezado filtradas en el rango. No debe
 * reclasificarse como nivel descriptivo.
 */
class RemParserServiceDescriptiveLevelTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'HOJADL';
    private const YEAR = 2096;
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

    private function label(?string $value, ?string $range = null): array
    {
        return [
            'valor_bruto' => $value,
            'es_editable' => false,
            'esta_bloqueada' => true,
            'es_formula' => false,
            'es_combinada' => $range !== null,
            'rango_combinado' => $range,
            'tipo_celda' => 'etiqueta',
            'zona' => 'zona_etiquetas',
        ];
    }

    private function total(): array
    {
        return [
            'valor_bruto' => null,
            'es_editable' => false,
            'esta_bloqueada' => true,
            'es_formula' => true,
            'es_combinada' => false,
            'rango_combinado' => null,
            'tipo_celda' => 'formula_total',
            'zona' => 'zona_total',
        ];
    }

    private function data(): array
    {
        return [
            'valor_bruto' => null,
            'es_editable' => true,
            'esta_bloqueada' => false,
            'es_formula' => false,
            'es_combinada' => false,
            'rango_combinado' => null,
            'tipo_celda' => 'vacio',
            'zona' => 'zona_captura',
        ];
    }

    private function fields(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Clasificacion', 'esTotal' => false],
            ['letra' => 'B', 'label' => 'Tipo', 'esTotal' => false],
            ['letra' => 'C', 'label' => 'Subtipo', 'esTotal' => false],
            ['letra' => 'D', 'label' => 'Subtipo', 'esTotal' => false],
            ['letra' => 'E', 'label' => 'Total', 'esTotal' => true],
            ['letra' => 'F', 'label' => 'Dato', 'esTotal' => false],
        ];
    }

    private function createActiveStructure(): void
    {
        RemTemplateStructure::create([
            'anio' => self::YEAR,
            'serie' => self::REM_TYPE,
            'hash_estructura' => sha1('test-structure-descriptive-level'),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => [
                            ['codigo' => 'X', 'filaInicioDatos' => 40, 'filaFinDatos' => 45, 'fields' => $this->fields()],
                            ['codigo' => 'Y', 'filaInicioDatos' => 60, 'filaFinDatos' => 65, 'fields' => $this->fields()],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function seedCellData(): void
    {
        $svc = app(CellDataStorageService::class);

        // Seccion X: C fusionada con D en 40/43/44/45; C41:C42 fusion vertical
        // propia y D41/D42 independientes con texto (el cuarto nivel real).
        $x = [];
        $bLabels = [40 => 'Tipo uno', 41 => 'Tipo dos', 42 => 'Tipo tres', 43 => 'Tipo cuatro', 44 => 'Tipo cinco', 45 => 'Tipo seis'];
        $cLabels = [40 => ['Sin perdida', 'C40:D40'], 41 => ['Con perdida', 'C41:C42'], 42 => [null, 'C41:C42'], 43 => ['Con lesion', 'C43:D43'], 44 => ['Otra', 'C44:D44'], 45 => ['Mas', 'C45:D45']];
        for ($r = 40; $r <= 45; $r++) {
            $x["A{$r}"] = $this->label($r === 40 ? 'Clasificacion X' : null, 'A40:A45');
            $x["B{$r}"] = $this->label($bLabels[$r]);
            $x["C{$r}"] = $this->label($cLabels[$r][0], $cLabels[$r][1]);
            $x["E{$r}"] = $this->total();
            $x["F{$r}"] = $this->data();
        }
        $x['D40'] = $this->label(null, 'C40:D40');
        $x['D41'] = $this->label('<60 segundos, sin complicaciones');
        $x['D42'] = $this->label('>= 60 segundos y/o convulsiones');
        $x['D43'] = $this->label(null, 'C43:D43');
        $x['D44'] = $this->label(null, 'C44:D44');
        $x['D45'] = $this->label(null, 'C45:D45');
        $svc->saveCellData(self::SHEET, 'X', $x);

        // Seccion Y: misma jerarquia B/C, pero D es columna de captura real.
        // D60/D61 son texto de encabezado filtrado (bloqueado), D62-D65
        // editables. Tambien fusionada con C en una fila, para que la unica
        // diferencia con X sea la presencia de celdas de captura.
        $y = [];
        for ($r = 60; $r <= 65; $r++) {
            $y["A{$r}"] = $this->label($r === 60 ? 'Clasificacion Y' : null, 'A60:A65');
            $y["B{$r}"] = $this->label('Tipo Y ' . $r);
            $y["C{$r}"] = $this->label('Subtipo Y ' . $r);
            $y["D{$r}"] = $this->data();
            $y["E{$r}"] = $this->total();
            $y["F{$r}"] = $this->data();
        }
        $y['C63'] = $this->label('Subtipo Y 63', 'C63:D63');
        $y['D60'] = $this->label('Menos de 4 anos');
        $y['D61'] = $this->label('Hombres');
        $y['D63'] = $this->label(null, 'C63:D63');
        $svc->saveCellData(self::SHEET, 'Y', $y);
    }

    private function buildSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET);

        $sheet->setCellValue('A40', 'Clasificacion X');
        foreach ([40 => 'Tipo uno', 41 => 'Tipo dos', 42 => 'Tipo tres', 43 => 'Tipo cuatro', 44 => 'Tipo cinco', 45 => 'Tipo seis'] as $r => $v) {
            $sheet->setCellValue("B{$r}", $v);
        }
        foreach ([40 => 'Sin perdida', 41 => 'Con perdida', 43 => 'Con lesion', 44 => 'Otra', 45 => 'Mas'] as $r => $v) {
            $sheet->setCellValue("C{$r}", $v);
        }
        $sheet->setCellValue('D41', '<60 segundos, sin complicaciones');
        $sheet->setCellValue('D42', '>= 60 segundos y/o convulsiones');
        for ($r = 40; $r <= 45; $r++) {
            $sheet->setCellValue("E{$r}", 0);
            $sheet->setCellValue("F{$r}", $r - 38);
        }

        $sheet->setCellValue('A60', 'Clasificacion Y');
        for ($r = 60; $r <= 65; $r++) {
            $sheet->setCellValue("B{$r}", 'Tipo Y ' . $r);
            $sheet->setCellValue("C{$r}", 'Subtipo Y ' . $r);
            $sheet->setCellValue("E{$r}", 0);
            $sheet->setCellValue("F{$r}", 1);
        }
        $sheet->setCellValue('D60', 'Menos de 4 anos');
        $sheet->setCellValue('D61', 'Hombres');
        $sheet->setCellValue('D62', 5);
        $sheet->setCellValue('D64', 7);

        $path = storage_path('app/rem-uploads/test_descriptive_level_' . uniqid() . '.xlsx');
        (new Xlsx($spreadsheet))->save($path);
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
                            'header_row' => 30,
                            'data_start_row' => 40,
                            'concept_column' => 'A',
                            'professional_column' => null,
                            'total_column' => 'E',
                        ],
                        'columns' => array_map(fn ($l) => ['letter' => $l, 'header' => $l], ['A', 'B', 'C', 'D', 'E', 'F']),
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
                'name' => 'Centro Test DL',
                'code_deis' => 'CTDL' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'testdl.xlsx',
            'stored_path' => basename($storedPath),
            'file_size' => filesize($storedPath),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'rem_template_id' => $template->id,
        ]);

        $result = app(RemParserService::class)->parse($upload);

        $byRow = [];
        foreach ($result->extractedData as $entry) {
            $byRow[$entry['row_number']] = $entry;
        }

        return [$result, $byRow];
    }

    private function dataErrors($result, string $column, array $rows): array
    {
        return array_values(array_filter(
            $result->errors['errors'] ?? [],
            fn ($e) => ($e['column'] ?? null) === $column && in_array($e['row'] ?? null, $rows, true)
        ));
    }

    public function test_fourth_descriptive_level_is_not_validated_as_integer(): void
    {
        [$result] = $this->parseUpload();

        $this->assertCount(0, $this->dataErrors($result, 'D', [40, 41, 42, 43, 44, 45]), 'D41/D42 son etiquetas descriptivas, no datos');
    }

    public function test_fourth_descriptive_level_text_is_appended_to_detail(): void
    {
        [, $byRow] = $this->parseUpload();

        $this->assertSame('Con perdida / <60 segundos, sin complicaciones', $byRow[41]['detail']);
        $this->assertSame('Con perdida / >= 60 segundos y/o convulsiones', $byRow[42]['detail']);
        $this->assertSame('Sin perdida', $byRow[40]['detail'], 'D40 fusionada con C40 no agrega nivel propio');
        $this->assertSame('Con lesion', $byRow[43]['detail']);
        foreach ([40, 41, 42, 43, 44, 45] as $r) {
            $this->assertArrayNotHasKey('D', $byRow[$r]['values'], "D no debe aparecer en values de la fila {$r}");
        }
    }

    public function test_existing_levels_and_numeric_data_are_unchanged(): void
    {
        [, $byRow] = $this->parseUpload();

        $this->assertSame('Tipo dos', $byRow[41]['subcategory']);
        $this->assertSame(3, $byRow[41]['values']['F']);
        $this->assertSame(7, $byRow[45]['values']['F']);
    }

    public function test_capture_column_with_leaked_header_rows_keeps_original_protection(): void
    {
        [$result, $byRow] = $this->parseUpload();

        // D es una columna de captura real en la seccion Y: no se reclasifica.
        // Sus 2 filas de texto siguen generando el mismo error que antes del
        // cambio, y sus datos numericos siguen parseandose.
        $this->assertCount(2, $this->dataErrors($result, 'D', [60, 61]));
        $this->assertSame(5, $byRow[62]['values']['D']);
        $this->assertSame(7, $byRow[64]['values']['D']);
        $this->assertSame('Subtipo Y 62', $byRow[62]['detail']);
    }
}
