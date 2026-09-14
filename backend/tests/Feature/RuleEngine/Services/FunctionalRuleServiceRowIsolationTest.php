<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RuleEngine\Services\FunctionalRuleService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BM-9.2 (2026-09-14): FunctionalRuleService::getFunctionalRulesByRow()
 * indexaba el resultado usando el sheet/section SOLICITADO, pero tomaba el
 * valor de CUALQUIER registro cuyo `row` coincidiera numericamente, sin
 * verificar que ese registro perteneciera realmente a ese sheet/section --
 * un registro de cualquier otra hoja/seccion/serie con el mismo numero de
 * fila se remapeaba silenciosamente a la clave solicitada. Detectado durante
 * la auditoria de calibracion funcional BM (BM-9.1): 36/160 filas de las 6
 * secciones BM mostraban evidencia heredada de secciones reales de Serie A
 * (A06/A08/A09/A11a/A19a) por pura coincidencia de numero de fila.
 *
 * getFunctionalRuleByRow() (el metodo hermano, singular) ya filtraba
 * correctamente por sheet+section+row -- ese es el precedente arquitectonico
 * que este fix replica en la version plural/bulk.
 */
class FunctionalRuleServiceRowIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): FunctionalRuleService
    {
        return app(FunctionalRuleService::class);
    }

    private function seedRaw(array $records): void
    {
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode($records));
    }

    public function test_same_row_same_sheet_same_section_returns_own_evidence(): void
    {
        $this->seedRaw([
            'ZZ_A_10' => ['sheet' => 'ZZ', 'section' => 'A', 'row' => 10, 'empty_behavior' => 'debe_registrar_cero'],
        ]);

        $result = $this->service()->getFunctionalRulesByRow('ZZ', 'A');

        $this->assertArrayHasKey('ZZ_A_10', $result);
        $this->assertSame('ZZ', $result['ZZ_A_10']['sheet']);
        $this->assertSame('A', $result['ZZ_A_10']['section']);
        $this->assertSame('debe_registrar_cero', $result['ZZ_A_10']['empty_behavior']);
    }

    public function test_same_row_different_sheet_returns_no_evidence(): void
    {
        $this->seedRaw([
            'YY_A_10' => ['sheet' => 'YY', 'section' => 'A', 'row' => 10, 'empty_behavior' => 'puede_quedar_vacio'],
        ]);

        $result = $this->service()->getFunctionalRulesByRow('ZZ', 'A');

        $this->assertSame([], $result);
    }

    public function test_same_row_same_sheet_different_section_returns_no_evidence(): void
    {
        $this->seedRaw([
            'ZZ_B_10' => ['sheet' => 'ZZ', 'section' => 'B', 'row' => 10, 'empty_behavior' => 'puede_quedar_vacio'],
        ]);

        $result = $this->service()->getFunctionalRulesByRow('ZZ', 'A');

        $this->assertSame([], $result);
    }

    public function test_same_row_across_multiple_sheets_and_sections_each_query_gets_only_its_own(): void
    {
        $this->seedRaw([
            'ZZ_A_10' => ['sheet' => 'ZZ', 'section' => 'A', 'row' => 10, 'empty_behavior' => 'debe_registrar_cero'],
            'YY_B_10' => ['sheet' => 'YY', 'section' => 'B', 'row' => 10, 'empty_behavior' => 'puede_quedar_vacio'],
            'XX_C_10' => ['sheet' => 'XX', 'section' => 'C', 'row' => 10, 'empty_behavior' => 'puede_quedar_vacio'],
        ]);

        $svc = $this->service();

        $resultZzA = $svc->getFunctionalRulesByRow('ZZ', 'A');
        $this->assertSame('ZZ', $resultZzA['ZZ_A_10']['sheet']);
        $this->assertSame('debe_registrar_cero', $resultZzA['ZZ_A_10']['empty_behavior']);

        $resultYyB = $svc->getFunctionalRulesByRow('YY', 'B');
        $this->assertSame('YY', $resultYyB['YY_B_10']['sheet']);

        $resultXxC = $svc->getFunctionalRulesByRow('XX', 'C');
        $this->assertSame('XX', $resultXxC['XX_C_10']['sheet']);
    }

    public function test_row_without_own_evidence_returns_empty_not_inherited(): void
    {
        $this->seedRaw([
            'YY_A_25' => ['sheet' => 'YY', 'section' => 'A', 'row' => 25, 'empty_behavior' => 'debe_registrar_cero'],
        ]);

        $result = $this->service()->getFunctionalRulesByRow('ZZ', 'A');

        $this->assertSame([], $result, 'ZZ/A no tiene evidencia propia en fila 25 -- no debe heredar la de YY/A.');
    }

    public function test_existing_correct_behavior_still_works_without_regression(): void
    {
        $this->seedRaw([
            'ZZ_A_10' => ['sheet' => 'ZZ', 'section' => 'A', 'row' => 10, 'empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            'ZZ_A_11' => ['sheet' => 'ZZ', 'section' => 'A', 'row' => 11, 'empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'],
        ]);

        $result = $this->service()->getFunctionalRulesByRow('ZZ', 'A');

        $this->assertCount(2, $result);
        $this->assertSame('debe_registrar_cero', $result['ZZ_A_10']['empty_behavior']);
        $this->assertSame('puede_quedar_vacio', $result['ZZ_A_11']['empty_behavior']);
    }

    public function test_case_insensitive_sheet_and_section_matching_preserved(): void
    {
        $this->seedRaw([
            'ZZ_a_10' => ['sheet' => 'zz', 'section' => 'a', 'row' => 10, 'empty_behavior' => 'debe_registrar_cero'],
        ]);

        $result = $this->service()->getFunctionalRulesByRow('ZZ', 'A');

        $this->assertArrayHasKey('ZZ_A_10', $result);
        $this->assertSame('zz', $result['ZZ_A_10']['sheet']);
    }

    public function test_getFunctionalRuleByRow_sibling_method_unaffected_by_this_fix(): void
    {
        $this->seedRaw([
            'ZZ_A_10' => ['sheet' => 'ZZ', 'section' => 'A', 'row' => 10, 'empty_behavior' => 'debe_registrar_cero'],
            'YY_B_10' => ['sheet' => 'YY', 'section' => 'B', 'row' => 10, 'empty_behavior' => 'puede_quedar_vacio'],
        ]);

        $svc = $this->service();

        $this->assertSame('ZZ', $svc->getFunctionalRuleByRow('ZZ', 'A', 10)['sheet']);
        $this->assertSame('YY', $svc->getFunctionalRuleByRow('YY', 'B', 10)['sheet']);
        $this->assertNull($svc->getFunctionalRuleByRow('ZZ', 'B', 10));
    }
}
