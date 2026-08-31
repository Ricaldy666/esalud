<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cubre rule:set-rule-status -- unico punto de entrada para cambiar
 * rem_rules.status dentro de la lista blanca ('active'/'inactive'). Dry-run
 * por defecto, --commit exigido para persistir. Ver auditoria 529 vs 530 y
 * status=obsolete, 2026-08-27.
 */
class RuleSetRuleStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private function dummyField(string $letra): array
    {
        return ['letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function activeStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-status-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => 'A01', 'sections' => [[
                    'codigo' => 'A', 'titulo' => 'A', 'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 20,
                    'fields' => [$this->dummyField('C'), $this->dummyField('D')],
                ]]],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function statusRule(string $status = 'active'): Rule
    {
        $rule = Rule::create([
            'rule_key' => 'a32_f_b_sum_equals', 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'TOTAL ACCIONES',
            'description' => 'test', 'category' => 'A32', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x'],
            'status' => $status, 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 19, 'serie' => 'A', 'anio' => 2026, 'active' => true]);

        return $rule;
    }

    public function test_valid_active_to_inactive_dry_run(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');

        $exit = Artisan::call('rule:set-rule-status', ['rule_id' => (string) $rule->id, 'new_status' => 'inactive']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('active -> inactive', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    public function test_disallowed_status_rejected(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');

        $exit = Artisan::call('rule:set-rule-status', ['rule_id' => (string) $rule->id, 'new_status' => 'obsolete']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no permitido', Artisan::output());
        $rule->refresh();
        $this->assertSame('active', $rule->status);
    }

    public function test_no_op_rejected(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');

        $exit = Artisan::call('rule:set-rule-status', ['rule_id' => (string) $rule->id, 'new_status' => 'active']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('nada que cambiar', Artisan::output());
    }

    public function test_dry_run_never_writes(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');
        $originalUpdatedAt = $rule->updated_at;

        Artisan::call('rule:set-rule-status', ['rule_id' => (string) $rule->id, 'new_status' => 'inactive', '--reason' => 'motivo', '--by' => 'Estadística APS']);

        $rule->refresh();
        $this->assertSame('active', $rule->status);
        $this->assertEquals($originalUpdatedAt->timestamp, $rule->updated_at->timestamp);
        $this->assertSame(0, \Spatie\Activitylog\Models\Activity::where('description', 'rule_status_change')->count());
    }

    public function test_commit_changes_only_status(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');
        $originalConfig = $rule->config;

        $exit = Artisan::call('rule:set-rule-status', [
            'rule_id' => (string) $rule->id, 'new_status' => 'inactive', '--reason' => 'redundante con otra regla', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);

        $this->assertSame(0, $exit);
        $rule->refresh();
        $this->assertSame('inactive', $rule->status);
        $this->assertEquals($originalConfig, $rule->config, 'config no debe cambiar');
        $this->assertSame('a32_f_b_sum_equals', $rule->rule_key);
        $this->assertSame('TOTAL ACCIONES', $rule->name);
        $this->assertSame('sum_equals', $rule->rule_type);
    }

    public function test_binding_and_history_untouched_after_commit(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');

        $healthCenter = HealthCenter::create(['name' => 'Test CESFAM', 'code_deis' => 'TEST-' . uniqid(), 'type' => 'CESFAM', 'is_active' => true]);
        $user = User::factory()->create();
        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'health_center_id' => $healthCenter->id, 'user_id' => $user->id,
            'rem_template_id' => null, 'year' => 2026, 'month' => 1, 'rem_type' => 'A', 'original_filename' => 'test.xlsm',
            'stored_path' => 'test/test.xlsm', 'file_size' => 100, 'mime_type' => 'application/vnd.ms-excel', 'status' => 'completed',
        ]);

        RuleExecutionLog::create([
            'rule_id' => $rule->id, 'rule_key' => $rule->rule_key, 'rem_upload_id' => $upload->id, 'rem_template_structure_id' => null,
            'execution_id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'passed', 'total_rows' => 1, 'passed_rows' => 1, 'failed_rows' => 0,
            'execution_ms' => 0, 'triggered_by' => 'job', 'context' => ['details' => []],
        ]);
        DB::table('rem_validation_results')->insert([
            'rule_id' => $rule->id, 'rem_upload_id' => $upload->id, 'rule_key' => $rule->rule_key, 'rule_type' => $rule->rule_type,
            'severity' => 'error', 'passed' => true, 'message' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        RuleVersion::create(['rule_id' => $rule->id, 'version' => '1.0.0', 'config' => $rule->config, 'changelog' => 'x', 'created_by' => null]);

        $execCountBefore = RuleExecutionLog::where('rule_id', $rule->id)->count();
        $validationCountBefore = DB::table('rem_validation_results')->where('rule_id', $rule->id)->count();
        $versionCountBefore = RuleVersion::where('rule_id', $rule->id)->count();
        $binding = RuleBinding::where('rule_id', $rule->id)->first();

        Artisan::call('rule:set-rule-status', [
            'rule_id' => (string) $rule->id, 'new_status' => 'inactive', '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true,
        ]);

        $this->assertSame($execCountBefore, RuleExecutionLog::where('rule_id', $rule->id)->count());
        $this->assertSame($validationCountBefore, DB::table('rem_validation_results')->where('rule_id', $rule->id)->count());
        $this->assertSame($versionCountBefore, RuleVersion::where('rule_id', $rule->id)->count(), 'este comando no crea RuleVersion');

        $binding->refresh();
        $this->assertTrue($binding->active, 'el binding debe permanecer activo e intacto');
    }

    public function test_activity_log_contains_required_fields(): void
    {
        $this->activeStructure();
        $rule = $this->statusRule('active');

        Artisan::call('rule:set-rule-status', [
            'rule_id' => (string) $rule->id, 'new_status' => 'inactive', '--reason' => 'evidencia auditoria 529 vs 530', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'rule_status_change')->first();
        $this->assertNotNull($activity);
        $this->assertSame($rule->id, $activity->properties['rule_id']);
        $this->assertSame('active', $activity->properties['old_status']);
        $this->assertSame('inactive', $activity->properties['new_status']);
        $this->assertSame('evidencia auditoria 529 vs 530', $activity->properties['reason']);
        $this->assertSame('Administrador Esalud', $activity->properties['by']);
        $this->assertNotNull($activity->created_at);
    }

    public function test_simulated_classification_matches_real_after_commit(): void
    {
        $structure = $this->activeStructure();
        $rule = $this->statusRule('active');
        $rule->update(['config' => ['sheet' => 'A01', 'section' => 'A', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D) = Columna C']]);

        $svc = app(\App\Domain\RuleEngine\Services\RuleBindingReconciliationService::class);
        $beforeReal = $svc->classifyAllActiveRules($structure)->countBy('clasificacion');

        Artisan::call('rule:set-rule-status', [
            'rule_id' => (string) $rule->id, 'new_status' => 'inactive', '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true,
        ]);

        $afterReal = $svc->classifyAllActiveRules($structure)->countBy('clasificacion');

        $this->assertSame(($beforeReal['SAFE_1_TO_1'] ?? 0) - 1, $afterReal['SAFE_1_TO_1'] ?? 0, 'la regla desactivada debe salir del universo activo');
    }
}
