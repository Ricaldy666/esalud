<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4 (2026-08-28, ver CLAUDE.md punto 17.37) -- gate de identidad
 * full-signature con verificacion de compatibilidad/ambiguedad. Cubre los
 * 8 escenarios exigidos, reproduciendo fielmente los patrones reales
 * auditados en 17.36 (Grupos 4/5 rescatables, 3 duplicado exacto, 2/6/7/11
 * subset/supersede, 1 solape real, 15/16 full-signature identica con
 * rule_logic distinto, regla autorreferencial rota, B2 con multiples
 * agregaciones via source_rows) -- nunca contra las 717 reglas reales,
 * siempre fixtures sinteticas aisladas.
 */
class RuleBindingReconciliationServiceIdentityGateTest extends TestCase
{
    use RefreshDatabase;

    private function dummyField(string $letra): array
    {
        return ['letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function targetStructure(array $forms): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 63,
            'hash_estructura' => 'hash-target-' . uniqid(),
            'estructura' => ['forms' => $forms],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function rule(string $key, string $type, array $config, string $status = 'active'): Rule
    {
        return Rule::create([
            'rule_key' => $key, 'rule_type' => $type, 'source' => 'test', 'name' => $key,
            'description' => 'test', 'category' => 'TEST', 'severity' => 'error', 'scope' => $config['scope'] ?? 'per_row',
            'config' => $config, 'status' => $status, 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    private function bindStructure(Rule $rule, int $structureId, bool $active = true): RuleBinding
    {
        return RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structureId,
            'serie' => 'A', 'anio' => 2026, 'active' => $active,
        ]);
    }

    /** 1. Grupos 4/5 rescatables -- rangos disjuntos, formula distinta por banda. */
    public function test_disjoint_row_ranges_with_distinct_formulas_escape_duplicate(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'C', 'titulo' => 'C', 'filaHeader' => 43, 'filaInicioDatos' => 44, 'filaFinDatos' => 66,
                'fields' => [$this->dummyField('D'), $this->dummyField('L'), $this->dummyField('N'), $this->dummyField('F'), $this->dummyField('H'), $this->dummyField('J')],
            ]]],
        ]);

        $ruleA = $this->rule('a01_d_44_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'C', 'column' => 'D',
            'row_range' => ['from' => 44, 'to' => 47], 'source_letters' => ['L', 'N'], 'target_column' => 'D', 'scope' => 'row_range',
        ]);
        $ruleB = $this->rule('a01_d_63_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'C', 'column' => 'D',
            'row_range' => ['from' => 63, 'to' => 66], 'source_letters' => ['F', 'H', 'J'], 'target_column' => 'D', 'scope' => 'row_range',
        ]);
        $this->bindStructure($ruleA, 19);
        $this->bindStructure($ruleB, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $ruleA->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $ruleB->id)['clasificacion']);
        $this->assertCount(2, $svc->findSafeCandidatesForStructure($target));
    }

    /** 2. Duplicado exacto -- misma full-signature y misma rule_logic. */
    public function test_exact_duplicate_stays_duplicate(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'B', 'titulo' => 'B', 'filaHeader' => 35, 'filaInicioDatos' => 36, 'filaFinDatos' => 39,
                'fields' => [$this->dummyField('C'), $this->dummyField('D'), $this->dummyField('E')],
            ]]],
        ]);

        $config = [
            'sheet' => 'A01', 'section' => 'B', 'column' => 'C',
            'row_range' => ['from' => 36, 'to' => 39], 'source_letters' => ['D', 'E'], 'target_column' => 'C', 'scope' => 'row_range',
        ];
        $ruleA = $this->rule('a01_c_36_sum_equals', 'sum_equals', $config);
        $ruleB = $this->rule('a01_de_36_sum_equals', 'sum_equals', $config);
        $this->bindStructure($ruleA, 19);
        $this->bindStructure($ruleB, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleA->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleB->id)['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    /** 3. Subset/supersede -- misma formula, un rango contenido dentro del otro. */
    public function test_subset_row_range_with_same_formula_stays_duplicate(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'E', 'titulo' => 'E', 'filaHeader' => 77, 'filaInicioDatos' => 78, 'filaFinDatos' => 82,
                'fields' => [$this->dummyField('B'), $this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        // Legacy: cubre solo la fila 78 (subconjunto).
        $ruleLegacy = $this->rule('a01_e_b_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'E', 'column' => 'B',
            'row_range' => ['from' => 78, 'to' => 78], 'rule_logic' => 'Suma(C + D) = Columna B',
        ]);
        // Vetted: cubre 78-82 (superset), misma formula exacta.
        $ruleVetted = $this->rule('a01_b_78_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'E', 'column' => 'B',
            'row_range' => ['from' => 78, 'to' => 82], 'source_letters' => ['C', 'D'], 'target_column' => 'B', 'scope' => 'row_range',
        ]);
        $this->bindStructure($ruleLegacy, 19);
        $this->bindStructure($ruleVetted, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleLegacy->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleVetted->id)['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    /** 4. Solape real -- rangos que se intersectan parcialmente, ninguno subconjunto del otro. */
    public function test_genuine_partial_overlap_stays_duplicate(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'X', 'titulo' => 'X', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 25,
                'fields' => [$this->dummyField('C'), $this->dummyField('D'), $this->dummyField('E')],
            ]]],
        ]);

        // [10:20] y [15:25] se solapan en 15-20, ninguno contiene al otro.
        $ruleA = $this->rule('a01_x_c_10_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'X', 'column' => 'C',
            'row_range' => ['from' => 10, 'to' => 20], 'source_letters' => ['D'], 'target_column' => 'C', 'scope' => 'row_range',
        ]);
        $ruleB = $this->rule('a01_x_c_15_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'X', 'column' => 'C',
            'row_range' => ['from' => 15, 'to' => 25], 'source_letters' => ['E'], 'target_column' => 'C', 'scope' => 'row_range',
        ]);
        $this->bindStructure($ruleA, 19);
        $this->bindStructure($ruleB, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleA->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleB->id)['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    /** 5. Full-signature identica + rule_logic distinto (una regla autorreferencial/rota). */
    public function test_identical_full_signature_with_different_rule_logic_stays_duplicate(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A06', 'sections' => [[
                'codigo' => 'C.2', 'titulo' => 'C.2', 'filaHeader' => 96, 'filaInicioDatos' => 97, 'filaFinDatos' => 101,
                'fields' => [$this->dummyField('B'), $this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        // Real, funcional: Suma(B+C) = Columna D.
        $ruleReal = $this->rule('a06_c2_d_sum_equals_manual', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'C.2', 'column' => 'D',
            'row_range' => ['from' => 97, 'to' => 101], 'source_letters' => ['B', 'C'], 'target_column' => 'D', 'scope' => 'row_range',
        ]);
        // Autorreferencial/roto: Suma(D) = Columna D -- mismo row_range/total_row/source_rows exactos.
        $ruleBroken = $this->rule('a06_c2_d_sum_equals', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'C.2', 'column' => 'D',
            'row_range' => ['from' => 97, 'to' => 101], 'rule_logic' => 'Suma(D) = Columna D',
        ]);
        $this->bindStructure($ruleReal, 19);
        $this->bindStructure($ruleBroken, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleReal->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleBroken->id)['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    /** 6. B2 con multiples agregaciones -- mismo source_letters=target (vertical self-sum), source_rows disjuntos. */
    public function test_b2_style_multiple_aggregations_via_source_rows_escape_duplicate(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A09', 'sections' => [[
                'codigo' => 'I', 'titulo' => 'I', 'filaHeader' => 250, 'filaInicioDatos' => 253, 'filaFinDatos' => 336,
                'fields' => [$this->dummyField('AM')],
            ]]],
        ]);

        // Offset 0: fila TOTAL 331, componentes [253,259,...,325].
        $offset0 = range(253, 325, 6);
        $ruleOffset0 = $this->rule('a09_i_am_row331_sum_equals', 'sum_equals', [
            'sheet' => 'A09', 'section' => 'I', 'column' => 'AM',
            'row_range' => ['from' => 253, 'to' => 325], 'source_letters' => ['AM'], 'target_column' => 'AM', 'scope' => 'row_range',
            'total_row' => 331, 'source_rows' => $offset0,
        ]);
        // Offset 1: fila TOTAL 332, componentes [254,260,...,326] -- disjunto del offset 0.
        $offset1 = range(254, 326, 6);
        $ruleOffset1 = $this->rule('a09_i_am_row332_sum_equals', 'sum_equals', [
            'sheet' => 'A09', 'section' => 'I', 'column' => 'AM',
            'row_range' => ['from' => 254, 'to' => 326], 'source_letters' => ['AM'], 'target_column' => 'AM', 'scope' => 'row_range',
            'total_row' => 332, 'source_rows' => $offset1,
        ]);
        // La regla original (placeholder {0,0}) sigue existiendo, sin tocar.
        $ruleOriginal = $this->rule('a09_i_am_sum_equals', 'sum_equals', [
            'sheet' => 'A09', 'section' => 'I', 'column' => 'AM',
            'row_range' => ['from' => 0, 'to' => 0], 'rule_logic' => 'Suma(AM) = Columna AM',
        ]);
        $this->bindStructure($ruleOffset0, 19);
        $this->bindStructure($ruleOffset1, 19);
        $this->bindStructure($ruleOriginal, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $ruleOffset0->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $ruleOffset1->id)['clasificacion']);
        // La original con placeholder {0,0} nunca alcanza el evaluador vertical -- BLOCKED_BY_ENGINE_GAP, sin cambio de comportamiento.
        $this->assertSame(RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP, $all->firstWhere('rule_id', $ruleOriginal->id)['clasificacion']);
        $this->assertCount(2, $svc->findSafeCandidatesForStructure($target));
    }

    /** 7. Regla normal sin colision -- sola en su grupo de identidad, sin cambio de comportamiento. */
    public function test_rule_without_any_collision_is_unaffected(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A02', 'sections' => [[
                'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 15,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        $rule = $this->rule('a02_a_c_sum_equals', 'sum_equals', [
            'sheet' => 'A02', 'section' => 'A', 'column' => 'C',
            'row_range' => ['from' => 10, 'to' => 15], 'source_letters' => ['D'], 'target_column' => 'C', 'scope' => 'row_range',
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $rule->id)['clasificacion']);
        $this->assertCount(1, $svc->findSafeCandidatesForStructure($target));
    }

    /** 8. Regla SAFE existente no cambia inesperadamente cuando coexiste con un grupo de colision ajeno. */
    public function test_unrelated_safe_rule_is_never_affected_by_a_colliding_group_elsewhere(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A02', 'sections' => [[
                'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 15,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'B', 'titulo' => 'B', 'filaHeader' => 35, 'filaInicioDatos' => 36, 'filaFinDatos' => 39,
                'fields' => [$this->dummyField('C'), $this->dummyField('D'), $this->dummyField('E')],
            ]]],
        ]);

        $unrelatedSafe = $this->rule('a02_a_c_sum_equals', 'sum_equals', [
            'sheet' => 'A02', 'section' => 'A', 'column' => 'C',
            'row_range' => ['from' => 10, 'to' => 15], 'source_letters' => ['D'], 'target_column' => 'C', 'scope' => 'row_range',
        ]);
        $this->bindStructure($unrelatedSafe, 19);

        $dupConfig = [
            'sheet' => 'A01', 'section' => 'B', 'column' => 'C',
            'row_range' => ['from' => 36, 'to' => 39], 'source_letters' => ['D', 'E'], 'target_column' => 'C', 'scope' => 'row_range',
        ];
        $dupA = $this->rule('a01_c_36_sum_equals', 'sum_equals', $dupConfig);
        $dupB = $this->rule('a01_de_36_sum_equals', 'sum_equals', $dupConfig);
        $this->bindStructure($dupA, 19);
        $this->bindStructure($dupB, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $unrelatedSafe->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $dupA->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $dupB->id)['clasificacion']);
    }

    /** 9. Grupo mixto (patron real Grupo 7/11: subset + miembro B independiente) permanece bloqueado en su totalidad. */
    public function test_mixed_group_with_subset_pair_plus_independent_member_stays_fully_blocked(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A06', 'sections' => [[
                'codigo' => 'A.2', 'titulo' => 'A.2', 'filaHeader' => 29, 'filaInicioDatos' => 30, 'filaFinDatos' => 32,
                'fields' => [$this->dummyField('B'), $this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        // Legacy (fila 30 sola).
        $ruleLegacy = $this->rule('a06_a2_b_legacy', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'A.2', 'column' => 'B',
            'row_range' => ['from' => 30, 'to' => 30], 'rule_logic' => 'Suma(C + D) = Columna B',
        ]);
        // Vetted horizontal (filas 30-31, superset de legacy, misma formula).
        $ruleVetted = $this->rule('a06_a2_b_vetted', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'A.2', 'column' => 'B',
            'row_range' => ['from' => 30, 'to' => 31], 'source_letters' => ['C', 'D'], 'target_column' => 'B', 'scope' => 'row_range',
        ]);
        // Vertical (self-sum, mismas filas 30-31, total_row=32, formula distinta) -- independiente y legitima frente a vetted, pero comparte grupo con legacy (subset).
        $ruleVertical = $this->rule('a06_a2_b_vert', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'A.2', 'column' => 'B',
            'row_range' => ['from' => 30, 'to' => 31], 'rule_logic' => 'Suma(B) = Columna B', 'total_row' => 32,
        ]);
        $this->bindStructure($ruleLegacy, 19);
        $this->bindStructure($ruleVetted, 19);
        $this->bindStructure($ruleVertical, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        // Ninguna de las 3 escapa -- ruleVertical tiene una relacion subset (no legitima)
        // con ruleLegacy, asi que el grupo completo permanece bloqueado en esta fase,
        // exactamente como exige la instruccion de no liberar selectivamente un
        // miembro de un grupo mixto.
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleLegacy->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleVetted->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleVertical->id)['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }
}
