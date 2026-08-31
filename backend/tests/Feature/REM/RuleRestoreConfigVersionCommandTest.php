<?php

namespace Tests\Feature\REM;

use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Cubre rule:restore-config-version -- deshace una escritura de config
 * puntual usando un snapshot de rem_rule_versions. Ver restore 529
 * (F1 -> F), 2026-08-27.
 */
class RuleRestoreConfigVersionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function ruleWithConfig(array $config, string $ruleKey = 'a32_f_b_sum_equals'): Rule
    {
        return Rule::create([
            'rule_key' => $ruleKey, 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'TOTAL ACCIONES',
            'description' => 'test', 'category' => 'A32', 'severity' => 'error', 'scope' => 'per_row',
            'config' => $config, 'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    public function test_dry_run_never_writes(): void
    {
        $rule = $this->ruleWithConfig(['sheet' => 'A32', 'section' => 'F1', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x']);
        $snapshot = RuleVersion::create(['rule_id' => $rule->id, 'version' => '1.0.0', 'config' => ['sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x'], 'changelog' => 'x', 'created_by' => null]);

        $exit = Artisan::call('rule:restore-config-version', ['rule_id' => (string) $rule->id, 'version_id' => (string) $snapshot->id]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DRY-RUN', Artisan::output());
        $rule->refresh();
        $this->assertSame('F1', $rule->config['section'], 'dry-run no debe escribir nada');
        $this->assertSame(1, RuleVersion::where('rule_id', $rule->id)->count(), 'solo debe existir el snapshot original, ninguno nuevo');
    }

    public function test_version_belonging_to_another_rule_fails(): void
    {
        $rule = $this->ruleWithConfig(['sheet' => 'A32', 'section' => 'F1', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x']);
        $otherRule = $this->ruleWithConfig(['sheet' => 'A09', 'section' => 'X', 'column' => 'C', 'row_range' => ['from' => 1, 'to' => 1], 'rule_logic' => 'y'], 'a09_x_c_sum_equals');
        $snapshotOfOther = RuleVersion::create(['rule_id' => $otherRule->id, 'version' => '1.0.0', 'config' => ['sheet' => 'A09', 'section' => 'Y', 'column' => 'C', 'row_range' => ['from' => 1, 'to' => 1], 'rule_logic' => 'y'], 'changelog' => 'x', 'created_by' => null]);

        $exit = Artisan::call('rule:restore-config-version', ['rule_id' => (string) $rule->id, 'version_id' => (string) $snapshotOfOther->id, '--reason' => 'x', '--by' => 'y', '--commit' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('pertenece a la regla', Artisan::output());
        $rule->refresh();
        $this->assertSame('F1', $rule->config['section'], 'no debe restaurar un snapshot de otra regla');
    }

    public function test_commit_restores_config_and_snapshots_previous_state(): void
    {
        $rule = $this->ruleWithConfig(['sheet' => 'A32', 'section' => 'F1', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x']);
        $preRestoreConfig = $rule->config;
        $snapshot = RuleVersion::create(['rule_id' => $rule->id, 'version' => '1.0.0', 'config' => ['sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x'], 'changelog' => 'x', 'created_by' => null]);

        $exit = Artisan::call('rule:restore-config-version', [
            'rule_id' => (string) $rule->id, 'version_id' => (string) $snapshot->id, '--reason' => 'deshacer remap redundante', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);

        $this->assertSame(0, $exit);
        $rule->refresh();
        $this->assertSame('F', $rule->config['section']);
        $this->assertEquals($snapshot->config, $rule->config, 'el config debe quedar identico al snapshot restaurado');

        $newSnapshot = RuleVersion::where('rule_id', $rule->id)->where('id', '!=', $snapshot->id)->first();
        $this->assertNotNull($newSnapshot, 'debe crearse un snapshot del estado pre-restore');
        $this->assertEquals($preRestoreConfig, $newSnapshot->config);

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'rule_config_restore_version')->first();
        $this->assertNotNull($activity);
        $this->assertSame($snapshot->id, $activity->properties['restored_from_version_id']);
        $this->assertSame('deshacer remap redundante', $activity->properties['reason']);
    }

    public function test_identical_config_has_nothing_to_restore(): void
    {
        $rule = $this->ruleWithConfig(['sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 123, 'to' => 123], 'rule_logic' => 'x']);
        $snapshot = RuleVersion::create(['rule_id' => $rule->id, 'version' => '1.0.0', 'config' => $rule->config, 'changelog' => 'x', 'created_by' => null]);

        $exit = Artisan::call('rule:restore-config-version', ['rule_id' => (string) $rule->id, 'version_id' => (string) $snapshot->id, '--reason' => 'x', '--by' => 'y', '--commit' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('nada que restaurar', Artisan::output());
    }
}
