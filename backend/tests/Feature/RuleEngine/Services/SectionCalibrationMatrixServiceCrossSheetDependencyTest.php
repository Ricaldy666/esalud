<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * REM BM -- FASE BM-2.6B (2026-09-11): confirma que
 * SectionCalibrationMatrixService::buildMatrix() reconoce explicitamente
 * una celda cuya UNICA fuente de valor es una dependencia cross-hoja (ver
 * BM-2.6A) como 'cross_sheet_dependency' -- nunca como columna local
 * fantasma ni como patron local incoherente. Estructura 100% sintetica
 * (RefreshDatabase, esalud_testing), Storage::fake('local') -- ningun
 * cell-data real persistido, ninguna estructura BM creada.
 */
class SectionCalibrationMatrixServiceCrossSheetDependencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function lockedFormulaCell(string $formula, array $crossSheetDeps = [], array $sameSheetDeps = []): array
    {
        return [
            'valor_bruto' => null,
            'es_editable' => false,
            'esta_bloqueada' => true,
            'es_formula' => true,
            'formula' => $formula,
            'dependencias' => $sameSheetDeps,
            'dependencias_cross_hoja' => $crossSheetDeps,
        ];
    }

    private function editableCell(?int $valor = null): array
    {
        return ['valor_bruto' => $valor, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
    }

    private function lockedTextCell(?string $valor = null): array
    {
        return ['valor_bruto' => $valor, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
    }

    public function test_row_with_only_cross_sheet_formula_is_classified_as_cross_sheet_dependency(): void
    {
        RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-cross-sheet-test-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'HOJATEST',
                        'sections' => [[
                            'codigo' => 'X',
                            'titulo' => 'SECCION X DE PRUEBA',
                            'filaHeader' => 9,
                            'filaInicioDatos' => 10,
                            'filaFinDatos' => 10,
                            'fields' => [
                                ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                                ['letra' => 'E', 'label' => 'Valor', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
                            ],
                        ]],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);

        $cellData = app(CellDataStorageService::class);
        $cellData->saveCellData('HOJATEST', 'X', [
            'A10' => $this->lockedTextCell('Item 1'),
            // E10 = "=OTRAHOJA!D20" -- misma forma real que BM18!E14=BM18A!D20
            // (BM-2.5), pero con nombres de hoja genericos para no hardcodear
            // BM en este test tampoco.
            'E10' => $this->lockedFormulaCell(
                '=OTRAHOJA!D20',
                crossSheetDeps: [['hoja' => 'OTRAHOJA', 'tipo' => 'celda', 'celda' => 'D20', 'celda_inicio' => null, 'celda_fin' => null]],
                sameSheetDeps: [],
            ),
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildMatrix('HOJATEST', 'X');

        $row = collect($matrix['rows'])->firstWhere('row', 10);
        $this->assertNotNull($row);

        $this->assertSame(
            SectionCalibrationMatrixService::CROSS_SHEET_DEPENDENCY_REASON,
            $row['cobertura'],
            'una celda cuya unica fuente es cross-hoja debe clasificarse como cross_sheet_dependency, nunca como patron local'
        );
        $this->assertSame('cross_sheet_dependency', $row['cross_sheet_dependency']);

        // Nunca debe aparecer una columna local fantasma (ej. "OTRAHOJA"
        // truncada a una coordenada) entre las columnas origen detectadas.
        $this->assertEmpty($row['origen_columnas'], 'no debe generarse ninguna columna local fantasma a partir de la dependencia cross-hoja');
    }

    public function test_row_with_local_formula_is_unaffected_by_cross_sheet_classification(): void
    {
        RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-cross-sheet-control-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'HOJATEST2',
                        'sections' => [[
                            'codigo' => 'X',
                            'titulo' => 'SECCION X DE PRUEBA',
                            'filaHeader' => 9,
                            'filaInicioDatos' => 10,
                            'filaFinDatos' => 10,
                            'fields' => [
                                ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                                ['letra' => 'B', 'label' => 'Origen', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                                ['letra' => 'C', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
                            ],
                        ]],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);

        $cellData = app(CellDataStorageService::class);
        $cellData->saveCellData('HOJATEST2', 'X', [
            'A10' => $this->lockedTextCell('Item 1'),
            'B10' => $this->editableCell(5),
            'C10' => $this->lockedFormulaCell('=B10', crossSheetDeps: [], sameSheetDeps: ['B10']),
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildMatrix('HOJATEST2', 'X');

        $row = collect($matrix['rows'])->firstWhere('row', 10);
        $this->assertNotNull($row);

        $this->assertNotSame(SectionCalibrationMatrixService::CROSS_SHEET_DEPENDENCY_REASON, $row['cobertura']);
        $this->assertNull($row['cross_sheet_dependency']);
    }
}
