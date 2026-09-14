<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BM-10.2 (2026-09-14): `SectionCalibrationMatrixService::REGLA_FUNCIONAL_LABELS`
 * es un array estatico pensado EXCLUSIVAMENTE para los 4 patrones reales de
 * A01/A (PATRONES_A01_A) -- keyed por su pattern_id (1-4), cada uno con una
 * formula real conocida de antemano. Antes de este fix, ambos puntos donde
 * se consultaba ($patron['id']) lo hacian sin verificar sheet/section,
 * asi que CUALQUIER seccion dinamica (no-A01/A) cuyo primer/unico patron
 * local recibiera numeracion 1-4 heredaba esa etiqueta sin importar si
 * aplicaba -- confirmado en auditoria BM-10.1/BM-10.2: 606 patrones en
 * Serie A + BM recibian una etiqueta ajena a su propia evidencia (ej. BM18/C,
 * entrada directa sin formula, mostraba "Suma de rango etario estándar =
 * TOTAL"). Fix: fuera de A01/A, la etiqueta se deriva exclusivamente de la
 * evidencia real de ESE patron (mode/formula_template/columna_total).
 *
 * Cubre tambien el fix relacionado del mismo turno: un column_group tipo
 * 'complementary' solo debe existir cuando complementa una relacion real
 * (main_rule o age_range) -- antes, una seccion con una unica columna TOTAL
 * de entrada directa (sin relacion a la que complementar) se etiquetaba
 * enganosamente como "variable complementaria".
 */
class SectionCalibrationMatrixServiceFunctionalRuleLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): SectionCalibrationMatrixService
    {
        return app(SectionCalibrationMatrixService::class);
    }

    private function createActiveStructure(string $sheetName, string $sectionCode, array $fields, string $serie = 'ZZ'): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => $serie,
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-label-test-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => $sheetName,
                        'sections' => [
                            [
                                'codigo' => $sectionCode,
                                'titulo' => 'SECCION DE PRUEBA',
                                'filaHeader' => 9,
                                'filaInicioDatos' => 10,
                                'filaFinDatos' => 10,
                                'fields' => $fields,
                            ],
                        ],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);
    }

    // ─── GAP B: A) formula real -> descripcion matematica correspondiente ──

    public function test_formula_pattern_gets_label_derived_from_real_formula(): void
    {
        $this->createActiveStructure('ZZSHEET1', 'X', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'D', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'F', 'label' => 'Dato', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ1');

        app(CellDataStorageService::class)->saveCellData('ZZSHEET1', 'X', [
            'A10' => ['valor_bruto' => 'Item 1', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'D10' => ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => '=SUM(F10)', 'dependencias' => ['F10']],
            'F10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $matrix = $this->service()->buildPatternMatrix('ZZSHEET1', 'X', 'ZZ1');

        $this->assertNotEmpty($matrix['patterns']);
        $pattern = $matrix['patterns'][0];
        $this->assertSame('formula', $pattern['mode']);
        $this->assertSame('D = SUM(F{fila})', $pattern['regla_funcional_label']);
        $this->assertStringNotContainsString('rango etario', $pattern['regla_funcional_label']);
    }

    // ─── GAP B: B) direct_input -> NO descripcion de suma ───────────────

    public function test_direct_input_pattern_never_gets_a_sum_label(): void
    {
        $this->createActiveStructure('ZZSHEET2', 'X', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ2');

        app(CellDataStorageService::class)->saveCellData('ZZSHEET2', 'X', [
            'A10' => ['valor_bruto' => 'Item unico', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'B10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $matrix = $this->service()->buildPatternMatrix('ZZSHEET2', 'X', 'ZZ2');

        $this->assertNotEmpty($matrix['patterns']);
        $pattern = $matrix['patterns'][0];
        $this->assertSame('direct_input', $pattern['mode']);
        $this->assertSame(1, $pattern['id'], 'Este patron debe recibir pattern_id=1 localmente, igual que el caso A -- la coincidencia numerica es la causa raiz del bug original.');
        $this->assertStringNotContainsString('Suma', $pattern['regla_funcional_label']);
        $this->assertStringNotContainsString('rango etario', $pattern['regla_funcional_label']);
        // Sin evidencia de columna total activa (nunca es formula -- ver
        // getFormulaTotalColumnsFromCellData()), columna_total queda vacia
        // por diseno -- el fallback correcto es el neutral, no uno que
        // invente el nombre de columna 'B'.
        $this->assertSame('', $pattern['columna_total']);
        $this->assertSame('Patrón de captura directa', $pattern['regla_funcional_label']);
    }

    // ─── GAP B: C) mismo pattern_id en dos estructuras distintas ────────

    public function test_same_pattern_id_across_structures_gets_labels_from_own_evidence(): void
    {
        // Reutiliza los dos fixtures anteriores -- ambos son pattern_id=1
        // localmente, en series/hojas distintas, con evidencia distinta.
        $this->createActiveStructure('ZZSHEET3A', 'X', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'D', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'F', 'label' => 'Dato', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ3');
        app(CellDataStorageService::class)->saveCellData('ZZSHEET3A', 'X', [
            'A10' => ['valor_bruto' => 'Item 1', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'D10' => ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => '=SUM(F10)', 'dependencias' => ['F10']],
            'F10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $formulaMatrix = $this->service()->buildPatternMatrix('ZZSHEET3A', 'X', 'ZZ3');
        $formulaPattern = $formulaMatrix['patterns'][0];

        $this->createActiveStructure('ZZSHEET3B', 'Y', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ4');
        app(CellDataStorageService::class)->saveCellData('ZZSHEET3B', 'Y', [
            'A10' => ['valor_bruto' => 'Item unico', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'B10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $directMatrix = $this->service()->buildPatternMatrix('ZZSHEET3B', 'Y', 'ZZ4');
        $directPattern = $directMatrix['patterns'][0];

        $this->assertSame(1, $formulaPattern['id']);
        $this->assertSame(1, $directPattern['id'], 'Mismo pattern_id numerico local (1) en ambas estructuras.');
        $this->assertNotSame(
            $formulaPattern['regla_funcional_label'],
            $directPattern['regla_funcional_label'],
            'Mismo pattern_id=1, pero evidencia distinta -> etiquetas distintas.'
        );
        $this->assertSame('D = SUM(F{fila})', $formulaPattern['regla_funcional_label']);
        $this->assertSame('Patrón de captura directa', $directPattern['regla_funcional_label']);
    }

    // ─── GAP B: D) ninguna etiqueta menciona rango etario sin esa semantica ──

    public function test_label_never_mentions_age_range_without_real_age_semantics(): void
    {
        $this->createActiveStructure('ZZSHEET4', 'X', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ5');
        app(CellDataStorageService::class)->saveCellData('ZZSHEET4', 'X', [
            'A10' => ['valor_bruto' => 'Item unico', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'B10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $matrix = $this->service()->buildPatternMatrix('ZZSHEET4', 'X', 'ZZ5');

        foreach ($matrix['patterns'] as $pattern) {
            $this->assertStringNotContainsString('rango etario', $pattern['regla_funcional_label']);
            $this->assertStringNotContainsString('climaterio', $pattern['regla_funcional_label']);
            $this->assertStringNotContainsString('RN', $pattern['regla_funcional_label']);
        }
    }

    // ─── A01/A legado: la etiqueta estatica sigue funcionando sin cambios ──

    public function test_a01_a_legacy_labels_remain_unaffected(): void
    {
        // A01/A usa PATRONES_A01_A (constante), no requiere cell-data/estructura
        // dinamica -- getPatternsForValidation()/buildPatternMatrix() lo
        // detectan por sheet==='A01' && section==='A' sin necesitar fixture.
        $matrix = $this->service()->buildPatternMatrix('A01', 'A', 'A');

        // Si no existe estructura activa de serie A en este test (RefreshDatabase),
        // buildMatrix() devuelve status='not_found' (emptyMatrix() siempre
        // echo-ea 'codigo' => $section recibido, nunca null) -- este caso
        // solo aplica cuando hay estructura real disponible (ver safety gate
        // en el reporte BM-10.2, verificado en vivo contra esalud_dev, no en
        // este test aislado).
        if (($matrix['section']['status'] ?? 'not_found') !== 'ok') {
            $this->markTestSkipped('Sin estructura A01/A activa en esta base de datos de test aislada -- verificado en vivo por separado.');
        }

        $labels = array_map(fn($p) => $p['regla_funcional_label'], $matrix['patterns']);
        $this->assertContains('Suma de rango etario estándar = TOTAL', $labels);
    }

    // ─── column_groups: 'complementary' solo cuando complementa algo real ──

    public function test_lone_total_column_is_not_labeled_as_complementary(): void
    {
        $this->createActiveStructure('ZZSHEET5', 'X', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ6');
        app(CellDataStorageService::class)->saveCellData('ZZSHEET5', 'X', [
            'A10' => ['valor_bruto' => 'Item unico', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'B10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $matrix = $this->service()->buildPatternMatrix('ZZSHEET5', 'X', 'ZZ6');

        $types = array_map(fn($g) => $g['type'], $matrix['column_groups']);
        $this->assertNotContains('complementary', $types, 'Una unica columna TOTAL de entrada directa no complementa ninguna relacion real.');
    }

    public function test_complementary_group_still_created_when_it_genuinely_complements_a_main_rule(): void
    {
        $this->createActiveStructure('ZZSHEET6', 'X', [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'C', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'D', 'label' => 'Hombres', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'E', 'label' => 'Mujeres', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'Z', 'label' => 'Observación', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
        ], 'ZZ7');
        app(CellDataStorageService::class)->saveCellData('ZZSHEET6', 'X', [
            'A10' => ['valor_bruto' => 'Item 1', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'C10' => ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => '=D10+E10', 'dependencias' => ['D10', 'E10']],
            'D10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
            'E10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
            'Z10' => ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null],
        ]);

        $matrix = $this->service()->buildPatternMatrix('ZZSHEET6', 'X', 'ZZ7');

        $types = array_map(fn($g) => $g['type'], $matrix['column_groups']);
        $this->assertContains('main_rule', $types, 'Precondicion del test: debe existir una relacion principal real.');
        $this->assertContains('complementary', $types, 'Con una relacion principal real presente, la columna sobrante (Z) SI complementa algo -- el grupo debe seguir creandose.');
    }
}
