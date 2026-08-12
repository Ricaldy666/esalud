<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre RuleBindingReconciliationService -- clasificacion de reglas
 * activas segun si un binding structure->N seria seguro. Ver auditoria
 * Fase 3 / reconciliacion Fase 3b (2026-08-11): SAFE_1_TO_1,
 * REQUIRES_REMAP, DUPLICATE, ORPHAN, BLOCKED_BY_ENGINE_GAP,
 * ALREADY_STRUCTURE_AGNOSTIC.
 */
class RuleBindingReconciliationServiceTest extends TestCase
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

    public function test_identical_sheet_section_columns_and_rows_is_safe_1_to_1(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        $rule = $this->rule('a01_a_c_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'A', 'column' => 'C',
            'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C',
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $candidates = $svc->findSafeCandidatesForStructure($target);

        $this->assertCount(1, $candidates);
        $this->assertSame($rule->id, $candidates->first()['rule_id']);
        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $candidates->first()['clasificacion']);
    }

    public function test_missing_section_with_split_candidates_is_requires_remap(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A32', 'sections' => [
                ['codigo' => 'F1', 'titulo' => 'F1', 'filaHeader' => 1, 'filaInicioDatos' => 2, 'filaFinDatos' => 10, 'fields' => [$this->dummyField('B')]],
                ['codigo' => 'F2', 'titulo' => 'F2', 'filaHeader' => 11, 'filaInicioDatos' => 12, 'filaFinDatos' => 20, 'fields' => [$this->dummyField('B')]],
            ]],
        ]);

        $rule = $this->rule('a32_f_b_sum_equals', 'sum_equals', [
            'sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 2, 'to' => 2], 'rule_logic' => 'Suma(C) = Columna B',
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);
        $row = $all->firstWhere('rule_id', $rule->id);

        $this->assertSame(RuleBindingReconciliationService::REQUIRES_REMAP, $row['clasificacion']);
        $this->assertStringContainsString('F1', $row['destino']);
        $this->assertStringContainsString('F2', $row['destino']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    public function test_missing_column_is_requires_remap(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A09', 'sections' => [[
                'codigo' => 'K', 'titulo' => 'K', 'filaHeader' => 1, 'filaInicioDatos' => 12, 'filaFinDatos' => 345,
                'fields' => [$this->dummyField('A'), $this->dummyField('B')],
            ]]],
        ]);

        $rule = $this->rule('a09_k_b_sum_equals', 'sum_equals', [
            'sheet' => 'A09', 'section' => 'K', 'column' => 'B',
            'row_range' => ['from' => 12, 'to' => 345], 'rule_logic' => 'Suma(CO + CP) = Columna B',
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $row = $svc->classifyAllActiveRules($target)->firstWhere('rule_id', $rule->id);

        $this->assertSame(RuleBindingReconciliationService::REQUIRES_REMAP, $row['clasificacion']);
        $this->assertStringContainsString('CO', $row['motivo']);
    }

    public function test_zero_zero_row_range_placeholder_is_requires_remap(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A04', 'sections' => [[
                'codigo' => 'C', 'titulo' => 'C', 'filaHeader' => 40, 'filaInicioDatos' => 51, 'filaFinDatos' => 54,
                'fields' => [$this->dummyField('C'), $this->dummyField('E'), $this->dummyField('G')],
            ]]],
        ]);

        $rule = $this->rule('a04_c_c_sum_equals', 'sum_equals', [
            'sheet' => 'A04', 'section' => 'C', 'column' => 'C',
            'row_range' => ['from' => 0, 'to' => 0], 'rule_logic' => 'Suma(E + G) = Columna C',
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $row = $svc->classifyAllActiveRules($target)->firstWhere('rule_id', $rule->id);

        $this->assertSame(RuleBindingReconciliationService::REQUIRES_REMAP, $row['clasificacion']);
    }

    public function test_vertical_sum_without_total_row_is_blocked_by_engine_gap(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A06', 'sections' => [[
                'codigo' => 'A.1', 'titulo' => 'A.1', 'filaHeader' => 11, 'filaInicioDatos' => 12, 'filaFinDatos' => 22,
                'fields' => [$this->dummyField('F')],
            ]]],
        ]);

        $rule = $this->rule('a06_a1_f_sum_equals', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'A.1', 'column' => 'F',
            'row_range' => ['from' => 12, 'to' => 21], 'rule_logic' => 'Suma(F) = Columna F',
            'source_letters' => ['F'],
            // sin total_row -- este es exactamente el hallazgo de la auditoria (331 reglas).
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $row = $svc->classifyAllActiveRules($target)->firstWhere('rule_id', $rule->id);

        $this->assertSame(RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP, $row['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    public function test_total_row_outside_current_range_is_blocked_by_engine_gap(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A06', 'sections' => [[
                'codigo' => 'A.1', 'titulo' => 'A.1', 'filaHeader' => 11, 'filaInicioDatos' => 12, 'filaFinDatos' => 21,
                'fields' => [$this->dummyField('F')],
            ]]],
        ]);

        $rule = $this->rule('a06_a1_f_sum_equals', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'A.1', 'column' => 'F',
            'row_range' => ['from' => 12, 'to' => 21], 'rule_logic' => 'Suma(F) = Columna F',
            'source_letters' => ['F'], 'total_row' => 22,
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $row = $svc->classifyAllActiveRules($target)->firstWhere('rule_id', $rule->id);

        $this->assertSame(RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP, $row['clasificacion']);
    }

    public function test_duplicate_group_is_never_safe(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        $config = ['sheet' => 'A01', 'section' => 'A', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C'];
        $ruleA = $this->rule('a01_a_c_sum_equals_v1', 'sum_equals', $config);
        $ruleB = $this->rule('a01_a_c_sum_equals_v2', 'sum_equals', $config);
        $this->bindStructure($ruleA, 19);
        $this->bindStructure($ruleB, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);

        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleA->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::DUPLICATE, $all->firstWhere('rule_id', $ruleB->id)['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    public function test_rule_with_serie_binding_is_already_structure_agnostic(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A06', 'sections' => [[
                'codigo' => 'B.2', 'titulo' => 'B.2', 'filaHeader' => 1, 'filaInicioDatos' => 2, 'filaFinDatos' => 5,
                'fields' => [$this->dummyField('B'), $this->dummyField('C')],
            ]]],
        ]);

        $rule = $this->rule('a06_b2_b_sum_equals', 'sum_equals', [
            'sheet' => 'A06', 'section' => 'B.2', 'column' => 'B', 'row_range' => ['from' => 2, 'to' => 2], 'rule_logic' => 'Suma(C) = Columna B',
        ]);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'serie', 'bindable_id' => null, 'serie' => 'A', 'anio' => 2026, 'active' => true]);

        $svc = app(RuleBindingReconciliationService::class);
        $row = $svc->classifyAllActiveRules($target)->firstWhere('rule_id', $rule->id);

        $this->assertSame(RuleBindingReconciliationService::ALREADY_STRUCTURE_AGNOSTIC, $row['clasificacion']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
    }

    public function test_safe_rule_in_no_utilizada_sheet_is_excluded_from_candidates(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A21', 'sections' => [[
                'codigo' => 'B', 'titulo' => 'B', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        $rule = $this->rule('a21_b_c_sum_equals', 'sum_equals', [
            'sheet' => 'A21', 'section' => 'B', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C',
        ]);
        $this->bindStructure($rule, 19);

        app(RemSheetUsageStatusService::class)->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', null);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($target);
        $row = $all->firstWhere('rule_id', $rule->id);

        // Estructuralmente SAFE_1_TO_1 -- pero excluido de candidatos por la hoja no_utilizada.
        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $row['clasificacion']);
        $this->assertTrue($row['hoja_no_utilizada']);
        $this->assertCount(0, $svc->findSafeCandidatesForStructure($target));
        $this->assertFalse($svc->isStillSafe($rule, $target));
    }

    public function test_is_still_safe_reflects_live_state(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A01', 'sections' => [[
                'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);

        $rule = $this->rule('a01_a_c_sum_equals', 'sum_equals', [
            'sheet' => 'A01', 'section' => 'A', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C',
        ]);
        $this->bindStructure($rule, 19);

        $svc = app(RuleBindingReconciliationService::class);
        $this->assertTrue($svc->isStillSafe($rule, $target));

        $rule->update(['config' => array_merge($rule->config, ['column' => 'Z'])]);
        $this->assertFalse($svc->isStillSafe($rule, $target));
    }
}
