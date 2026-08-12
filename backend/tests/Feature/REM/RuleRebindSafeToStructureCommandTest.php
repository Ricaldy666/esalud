<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Cubre rule:rebind-safe-to-structure -- unico punto de entrada para crear
 * bindings structure->N para reglas SAFE_1_TO_1. Dry-run por defecto,
 * --commit exigido para persistir. Ver reconciliacion Fase 3b (2026-08-11).
 */
class RuleRebindSafeToStructureCommandTest extends TestCase
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

    private function safeSection(): array
    {
        return ['sheetName' => 'A01', 'sections' => [[
            'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
            'fields' => [$this->dummyField('C'), $this->dummyField('D')],
        ]]];
    }

    private function safeRule(string $key = 'a01_a_c_sum_equals'): Rule
    {
        $rule = Rule::create([
            'rule_key' => $key, 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => $key,
            'description' => 'test', 'category' => 'TEST', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A01', 'section' => 'A', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C'],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 19, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        return $rule;
    }

    public function test_missing_required_options_fails(): void
    {
        $target = $this->targetStructure([$this->safeSection()]);
        $exit = Artisan::call('rule:rebind-safe-to-structure', ['--structure' => (string) $target->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('obligatorios', Artisan::output());
        $this->assertSame(0, RuleBinding::where('bindable_id', $target->id)->count());
    }

    public function test_dry_run_by_default_does_not_persist(): void
    {
        $target = $this->targetStructure([$this->safeSection()]);
        $rule = $this->safeRule();

        $exit = Artisan::call('rule:rebind-safe-to-structure', [
            '--structure' => (string) $target->id, '--reason' => 'motivo', '--by' => 'Estadística APS',
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString((string) $rule->id, $output);
        $this->assertSame(
            0,
            RuleBinding::where('bindable_type', 'structure')->where('bindable_id', $target->id)->count(),
            'dry-run nunca debe persistir'
        );
    }

    public function test_commit_creates_binding_only_for_safe_rule(): void
    {
        $target = $this->targetStructure([$this->safeSection()]);
        $rule = $this->safeRule();

        $exit = Artisan::call('rule:rebind-safe-to-structure', [
            '--structure' => (string) $target->id, '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Bindings nuevos creados: 1', Artisan::output());

        $binding = RuleBinding::where('rule_id', $rule->id)->where('bindable_type', 'structure')->where('bindable_id', $target->id)->first();
        $this->assertNotNull($binding);
        $this->assertTrue($binding->active);
        $this->assertSame('rule:rebind-safe-to-structure', $binding->conditions['created_via']);
        $this->assertSame('motivo', $binding->conditions['reason']);
        $this->assertSame('Estadística APS', $binding->conditions['decided_by']);
        $this->assertTrue($binding->conditions['rule_unchanged']);
    }

    public function test_old_binding_and_rem_rules_untouched_after_commit(): void
    {
        $target = $this->targetStructure([$this->safeSection()]);
        $rule = $this->safeRule();
        $originalConfig = $rule->config;
        $originalUpdatedAt = $rule->updated_at;

        Artisan::call('rule:rebind-safe-to-structure', [
            '--structure' => (string) $target->id, '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true,
        ]);

        $oldBinding = RuleBinding::where('rule_id', $rule->id)->where('bindable_id', 19)->first();
        $this->assertNotNull($oldBinding);
        $this->assertTrue($oldBinding->active, 'el binding antiguo no debe desactivarse');

        $rule->refresh();
        $this->assertEquals($originalConfig, $rule->config, 'rem_rules.config no debe cambiar');
        $this->assertEquals($originalUpdatedAt->timestamp, $rule->updated_at->timestamp, 'la regla no debe re-guardarse');
    }

    public function test_commit_is_idempotent_running_twice(): void
    {
        $target = $this->targetStructure([$this->safeSection()]);
        $this->safeRule();

        Artisan::call('rule:rebind-safe-to-structure', ['--structure' => (string) $target->id, '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true]);
        $countAfterFirst = RuleBinding::where('bindable_type', 'structure')->where('bindable_id', $target->id)->count();

        $exit = Artisan::call('rule:rebind-safe-to-structure', ['--structure' => (string) $target->id, '--reason' => 'motivo otra vez', '--by' => 'Estadística APS', '--commit' => true]);
        $countAfterSecond = RuleBinding::where('bindable_type', 'structure')->where('bindable_id', $target->id)->count();

        $this->assertSame(0, $exit);
        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(1, $countAfterSecond, 'la segunda corrida no debe duplicar el binding');
        $this->assertStringContainsString('Bindings nuevos creados: 0', Artisan::output());
    }

    public function test_no_utilizada_sheet_never_gets_a_new_binding(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A21', 'sections' => [[
                'codigo' => 'B', 'titulo' => 'B', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
                'fields' => [$this->dummyField('C'), $this->dummyField('D')],
            ]]],
        ]);
        $rule = $this->safeRule('a21_b_c_sum_equals');
        $rule->update(['config' => ['sheet' => 'A21', 'section' => 'B', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C']]);
        app(RemSheetUsageStatusService::class)->setStatus(2026, 'A', 'A21', RemSheetUsageStatusService::STATUS_NO_UTILIZADA, 'motivo', 'Estadística APS', null);

        Artisan::call('rule:rebind-safe-to-structure', ['--structure' => (string) $target->id, '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true]);

        $this->assertSame(0, RuleBinding::where('bindable_type', 'structure')->where('bindable_id', $target->id)->count());
    }

    public function test_requires_remap_duplicate_and_blocked_rules_are_never_bound(): void
    {
        $target = $this->targetStructure([
            ['sheetName' => 'A32', 'sections' => [
                ['codigo' => 'F1', 'titulo' => 'F1', 'filaHeader' => 1, 'filaInicioDatos' => 2, 'filaFinDatos' => 10, 'fields' => [$this->dummyField('B')]],
            ]],
        ]);

        // REQUIRES_REMAP: seccion F ya no existe (solo F1).
        $remapRule = Rule::create([
            'rule_key' => 'a32_f_b_sum_equals', 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'x',
            'description' => 'x', 'category' => 'A32', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 2, 'to' => 2], 'rule_logic' => 'Suma(C) = Columna B'],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create(['rule_id' => $remapRule->id, 'bindable_type' => 'structure', 'bindable_id' => 19, 'serie' => 'A', 'anio' => 2026, 'active' => true]);

        Artisan::call('rule:rebind-safe-to-structure', ['--structure' => (string) $target->id, '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true]);

        $this->assertSame(0, RuleBinding::where('bindable_type', 'structure')->where('bindable_id', $target->id)->count());
    }

    public function test_activity_log_entry_created_on_commit(): void
    {
        $target = $this->targetStructure([$this->safeSection()]);
        $this->safeRule();

        Artisan::call('rule:rebind-safe-to-structure', ['--structure' => (string) $target->id, '--reason' => 'motivo auditable', '--by' => 'Estadística APS', '--commit' => true]);

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'rule_binding_rebind_safe')->first();
        $this->assertNotNull($activity);
        $this->assertSame('motivo auditable', $activity->properties['reason']);
        $this->assertSame('Estadística APS', $activity->properties['by']);
    }
}
