<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow() --
 * Hallazgo 4 (A32/F2 fila 140, 2026-08-10, Opcion C aprobada): una fila
 * subtotal embebida que agrega hacia atras debe clasificarse como 'total'
 * (no 'data') en classifyRow(), lo que la excluye automaticamente de la
 * construccion de patrones (ver usos de row_type === 'data' en
 * SectionCalibrationMatrixService) sin necesidad de un mecanismo aparte.
 *
 * Misma logica que RemParserService::isEmbeddedBackwardSubtotalRow(),
 * duplicada aqui de forma independiente sobre cell_data.
 */
class SectionCalibrationMatrixServiceEmbeddedBackwardSubtotalRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): SectionCalibrationMatrixService
    {
        return new SectionCalibrationMatrixService(
            new CertificationService(),
            new FunctionalRuleService(),
            new CellDataStorageService(),
            new ColumnRoleResolverService(),
        );
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $valorBruto = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function isEmbedded(string $sheet, string $section, int $row, array $sectionData): bool
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'isEmbeddedBackwardSubtotalRow');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $sheet, $section, $row, $sectionData);
    }

    private function fields(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Concepto', 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'SubConcepto', 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Data1', 'esControlOculto' => false],
        ];
    }

    /**
     * Patron real de A32/F2 fila 140: concepto de bloque fusionado (A
     * vacia para esta fila), "TOTAL" vive en B, agrega hacia atras.
     */
    public function test_a32_f2_pattern_is_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJATEST', 'SEC', [
            'B20' => $this->cell(false, true, false, null, 'TOTAL'),
            'C20' => $this->cell(false, true, true, '=SUM(C11:C19)'),
        ]);

        $result = $this->isEmbedded('HOJATEST', 'SEC', 20, ['filaInicioDatos' => 11, 'fields' => $this->fields()]);

        $this->assertTrue($result, 'fila con "TOTAL" en columna B (concepto fusionado, A vacia) y formula hacia atras debe clasificarse como total');
    }

    /**
     * Patron real de A26/A.1 fila 41: concepto "TOTAL" en columna A
     * (no fusionada), formula simple hacia atras.
     */
    public function test_a26_a1_pattern_is_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJATEST', 'SEC2', [
            'A41' => $this->cell(false, true, false, null, 'TOTAL'),
            'C41' => $this->cell(false, true, true, '=SUM(C38:C40)'),
        ]);

        $result = $this->isEmbedded('HOJATEST', 'SEC2', 41, ['filaInicioDatos' => 38, 'fields' => $this->fields()]);

        $this->assertTrue($result);
    }

    /**
     * Patron real de A26/B fila 59: formula COMPUESTA que referencia mas
     * de un rango anterior ("=SUM(C54:C58)+C50").
     */
    public function test_a26_b_pattern_with_compound_formula_is_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJATEST', 'SEC3', [
            'A59' => $this->cell(false, true, false, null, 'TOTAL'),
            'C59' => $this->cell(false, true, true, '=SUM(C54:C58)+C50'),
        ]);

        $result = $this->isEmbedded('HOJATEST', 'SEC3', 59, ['filaInicioDatos' => 51, 'fields' => $this->fields()]);

        $this->assertTrue($result);
    }

    /**
     * Caso negativo real: A09/I fila 336 "Altas administrativas" -- dato
     * derivado LEGITIMO con formula hacia atras, pero sin ninguna etiqueta
     * "TOTAL". Nunca debe clasificarse como total.
     */
    public function test_a09_i_style_derived_data_without_total_label_is_not_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJATEST', 'SEC4', [
            'A61' => $this->cell(false, true, false, null, 'Altas administrativas'),
            'C61' => $this->cell(false, true, true, '=SUM(C60:C60)'),
        ]);

        $result = $this->isEmbedded('HOJATEST', 'SEC4', 61, ['filaInicioDatos' => 60, 'fields' => $this->fields()]);

        $this->assertFalse($result, '"Altas administrativas" es un dato real, no debe clasificarse como total pese a su formula hacia atras');
    }

    /**
     * Caso negativo: fila real con formula de su propia fila unicamente
     * (subtotal horizontal, sin ninguna referencia hacia atras).
     */
    public function test_real_data_row_with_own_row_formula_only_is_not_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJATEST', 'SEC5', [
            'A70' => $this->cell(false, true, false, null, 'Item 1'),
            'C70' => $this->cell(false, true, true, '=SUM(C70:C70)'),
        ]);

        $result = $this->isEmbedded('HOJATEST', 'SEC5', 70, ['filaInicioDatos' => 70, 'fields' => $this->fields()]);

        $this->assertFalse($result);
    }

    /**
     * Caso negativo: formula hacia atras real, pero SIN ninguna celda con
     * texto "TOTAL" (ninguna columna tiene etiqueta) -- sin evidencia de
     * concepto, no se clasifica como total.
     */
    public function test_backward_formula_without_any_total_label_is_not_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJATEST', 'SEC6', [
            'C81' => $this->cell(false, true, true, '=SUM(C80:C80)'),
        ]);

        $result = $this->isEmbedded('HOJATEST', 'SEC6', 81, ['filaInicioDatos' => 80, 'fields' => $this->fields()]);

        $this->assertFalse($result);
    }
}
