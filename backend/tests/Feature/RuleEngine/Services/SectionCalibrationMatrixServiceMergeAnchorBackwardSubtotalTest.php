<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la extension de mecanismo #12
 * (SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()) para
 * resolver la etiqueta de concepto desde la celda ANCLA de una fusion
 * vertical (Opcion C, puntos 17.26-17.29 de CLAUDE.md, implementada
 * 2026-08-28) -- caso real: A09/I filas 331-336 (A331:A336 fusionada,
 * "TOTAL" solo en la fila ancla 331; 332,334,335,336 son subordinadas con
 * formula real hacia atras que antes NUNCA se detectaban; 333 tiene una
 * referencia hacia adelante tipo AR337 y sigue sin excluirse).
 *
 * Fixtures 100% sinteticas. Complementa (no reemplaza)
 * SectionCalibrationMatrixServiceEmbeddedBackwardSubtotalRowTest.php (casos
 * sin fusion, sin tocar).
 */
class SectionCalibrationMatrixServiceMergeAnchorBackwardSubtotalTest extends TestCase
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

    private function isEmbedded(string $sheet, string $section, int $row, array $sectionData): bool
    {
        return $this->service()->isEmbeddedBackwardSubtotalRow($sheet, $section, $row, $sectionData);
    }

    private function fields(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Concepto', 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Data1', 'esControlOculto' => false],
        ];
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $valorBruto = null, bool $combinada = false, ?string $rangoCombinado = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
            'es_combinada' => $combinada,
            'rango_combinado' => $rangoCombinado,
        ];
    }

    // ── Caso 1: merge vertical TOTAL con subordinadas validas -- deben clasificar total ──

    public function test_merge_vertical_total_with_valid_backward_formula_subordinates_are_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        // Replica el patron real A331:A336 -- ancla 100 "TOTAL", subordinadas
        // 101,102 con formula real hacia atras (componentes en 90,91).
        $svc->saveCellData('HOJAMERGE', 'SEC', [
            'A90' => $this->cell(false, true, false, null, 'Item base 1'),
            'C90' => $this->cell(true, false),
            'A91' => $this->cell(false, true, false, null, 'Item base 2'),
            'C91' => $this->cell(true, false),
            'A100' => $this->cell(false, true, false, null, 'TOTAL', true, 'A100:A102'),
            'C100' => $this->cell(false, true, true, '=SUM(C90:C91)'),
            'A101' => $this->cell(false, true, false, null, null, true, 'A100:A102'),
            'C101' => $this->cell(false, true, true, '=SUM(C90:C91)'),
            'A102' => $this->cell(false, true, false, null, null, true, 'A100:A102'),
            'C102' => $this->cell(false, true, true, '=SUM(C90:C91)'),
        ]);

        $sectionData = ['filaInicioDatos' => 90, 'fields' => $this->fields()];

        $this->assertTrue($this->isEmbedded('HOJAMERGE', 'SEC', 100, $sectionData), 'fila ancla (100, "TOTAL" propio) debe seguir clasificando total, comportamiento sin cambios');
        $this->assertTrue($this->isEmbedded('HOJAMERGE', 'SEC', 101, $sectionData), 'fila subordinada 101 (merge vertical, formula real hacia atras) debe clasificar total via resolucion de ancla');
        $this->assertTrue($this->isEmbedded('HOJAMERGE', 'SEC', 102, $sectionData), 'fila subordinada 102 (merge vertical, formula real hacia atras) debe clasificar total via resolucion de ancla');
    }

    // ── Caso 2: subordinada SIN formula -- no debe generar falsa exclusion ──

    public function test_merge_subordinate_without_any_formula_is_not_falsely_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJAMERGE', 'SECVACIA', [
            'A90' => $this->cell(false, true, false, null, 'Item base'),
            'C90' => $this->cell(true, false),
            'A100' => $this->cell(false, true, false, null, 'TOTAL', true, 'A100:A101'),
            'C100' => $this->cell(false, true, true, '=SUM(C90:C90)'),
            // fila subordinada 101: SIN ninguna formula, celda C vacia/no capturable real tampoco.
            'A101' => $this->cell(false, true, false, null, null, true, 'A100:A101'),
        ]);

        $sectionData = ['filaInicioDatos' => 90, 'fields' => $this->fields()];

        $this->assertFalse(
            $this->isEmbedded('HOJAMERGE', 'SECVACIA', 101, $sectionData),
            'fila subordinada sin ninguna formula (sin evidencia de agregacion) nunca debe clasificarse como total, aunque pertenezca al merge TOTAL'
        );
    }

    // ── Caso 3: merge COSMETICO (ninguna subordinada tiene formula) -- sin efecto ──

    public function test_cosmetic_merge_without_formulas_in_subordinates_has_no_effect(): void
    {
        $svc = app(CellDataStorageService::class);
        // Patron real de los 122 merges "seguros" auditados en el punto
        // 17.26: la etiqueta TOTAL se fusiona por diseño visual sobre varias
        // filas, pero NINGUNA fila subordinada tiene formula ni dato alguno.
        $svc->saveCellData('HOJAMERGE', 'SECCOSMETICA', [
            'A90' => $this->cell(false, true, false, null, 'Item base'),
            'C90' => $this->cell(true, false),
            'A100' => $this->cell(false, true, false, null, 'TOTAL', true, 'A100:A102'),
            'C100' => $this->cell(false, true, true, '=SUM(C90:C90)'),
            'A101' => $this->cell(false, true, false, null, null, true, 'A100:A102'),
            'A102' => $this->cell(false, true, false, null, null, true, 'A100:A102'),
        ]);

        $sectionData = ['filaInicioDatos' => 90, 'fields' => $this->fields()];

        $this->assertTrue($this->isEmbedded('HOJAMERGE', 'SECCOSMETICA', 100, $sectionData), 'la fila ancla real SI debe seguir excluyendose (sin cambios)');
        $this->assertFalse($this->isEmbedded('HOJAMERGE', 'SECCOSMETICA', 101, $sectionData), 'fila de relleno visual del merge (sin formula) nunca debe clasificarse como total');
        $this->assertFalse($this->isEmbedded('HOJAMERGE', 'SECCOSMETICA', 102, $sectionData), 'fila de relleno visual del merge (sin formula) nunca debe clasificarse como total');
    }

    // ── Caso 4: fila tipo AR337 (referencia hacia adelante) -- NO debe excluirse ──

    public function test_row_with_forward_reference_like_ar337_within_merge_is_not_classified_as_total(): void
    {
        $svc = app(CellDataStorageService::class);
        // Replica el patron real exacto de la regla 229 (fila 333): formula
        // mezcla referencias hacia atras (90,91) + una referencia hacia una
        // fila POSTERIOR (110, fuera de la seccion, patron AR337).
        $svc->saveCellData('HOJAMERGE', 'SECAR337', [
            'A90' => $this->cell(false, true, false, null, 'Item base 1'),
            'C90' => $this->cell(true, false),
            'A91' => $this->cell(false, true, false, null, 'Item base 2'),
            'C91' => $this->cell(true, false),
            'A100' => $this->cell(false, true, false, null, 'TOTAL', true, 'A100:A101'),
            'C100' => $this->cell(false, true, true, '=SUM(C90:C91)'),
            'A101' => $this->cell(false, true, false, null, null, true, 'A100:A101'),
            'C101' => $this->cell(false, true, true, '=SUM(C110+C90:C91)'),
        ]);

        $sectionData = ['filaInicioDatos' => 90, 'fields' => $this->fields()];

        $this->assertTrue($this->isEmbedded('HOJAMERGE', 'SECAR337', 100, $sectionData), 'la fila ancla, sin referencia hacia adelante, si debe excluirse');
        $this->assertFalse(
            $this->isEmbedded('HOJAMERGE', 'SECAR337', 101, $sectionData),
            'fila subordinada con referencia hacia adelante (patron AR337, regla 229 real) nunca debe clasificarse como total, pese a que su etiqueta se resuelve correctamente via el merge'
        );
    }

    // ── Caso 5: fila normal, sin ningun merge -- comportamiento intacto ──

    public function test_normal_row_without_any_merge_is_unaffected(): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('HOJAMERGE', 'SECNORMAL', [
            'A90' => $this->cell(false, true, false, null, 'Item real'),
            'C90' => $this->cell(true, false),
        ]);

        $sectionData = ['filaInicioDatos' => 90, 'fields' => $this->fields()];

        $this->assertFalse($this->isEmbedded('HOJAMERGE', 'SECNORMAL', 90, $sectionData), 'una fila de dato real sin ningun merge nunca debe clasificarse como total');
    }
}
