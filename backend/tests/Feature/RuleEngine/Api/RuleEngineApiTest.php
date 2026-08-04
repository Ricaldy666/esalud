<?php

namespace Tests\Feature\RuleEngine\Api;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Domain\RuleEngine\Models\RuleEngineSetting;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RuleEngineApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Administrador']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Administrador');

        $this->user = User::factory()->create();
    }

    private function createUpload(): RemUpload
    {
        return RemUpload::create([
            'rem_type' => 'A',
            'year' => 2026,
            'month' => 7,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'HC',
                'code_deis' => 'HC001',
                'type' => 'CESFAM',
            ])->id,
            'user_id' => $this->user->id,
            'original_filename' => 'test.xlsx',
            'stored_path' => 'rem/2026/07/test.xlsx',
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function createRule(array $overrides = []): Rule
    {
        return Rule::create(array_merge([
            'rule_key' => 'test_rule_' . Str::random(6),
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Test Rule',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ], $overrides));
    }

    public function test_health_returns_engine_status(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/rule-engine/health');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'config_enabled',
                'config_mode',
                'config_fail_open',
                'config_log_mode',
                'total_rules_active',
                'total_bindings_active',
                'total_structures',
                'structures_with_rules',
                'structures_without_bindings',
                'total_uploads',
                'uploads_with_engine',
                'uploads_without_engine',
                'total_execution_logs',
                'error_logs',
                'last_error',
                'last_execution',
            ],
        ]);
    }

    public function test_health_reflects_db_counts(): void
    {
        Sanctum::actingAs($this->user);

        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);
        RuleEngineSetting::create(['key' => 'mode', 'value' => 'parallel']);

        $rule = $this->createRule(['rule_key' => 'health_test']);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => 999,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        $response = $this->getJson('/api/v1/rule-engine/health');

        $response->assertOk();
        $response->assertJsonPath('data.config_enabled', true);
        $response->assertJsonPath('data.config_mode', 'parallel');
        $response->assertJsonPath('data.total_rules_active', 1);
        $response->assertJsonPath('data.total_bindings_active', 1);
    }

    public function test_stats_returns_aggregated_data(): void
    {
        Sanctum::actingAs($this->user);

        $rule = $this->createRule(['rule_key' => 'stats_test']);
        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'stats_test',
            'rem_upload_id' => $upload->id,
            'rem_template_structure_id' => null,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 10,
            'passed_rows' => 10,
            'failed_rows' => 0,
            'execution_ms' => 50,
            'triggered_by' => 'cli',
        ]);

        $response = $this->getJson('/api/v1/rule-engine/stats');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'rules_by_type',
                'executions_by_status',
                'executions_by_trigger',
                'avg_execution_time_ms',
                'total_rows_processed',
                'total_rows_failed',
                'by_structure',
                'last_20_uploads',
                'top_10_slowest_rules',
            ],
        ]);
        $response->assertJsonPath('data.rules_by_type.sum_equals', 1);
        $response->assertJsonPath('data.executions_by_status.passed', 1);
    }

    public function test_stats_returns_empty_when_no_data(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/rule-engine/stats');

        $response->assertOk();
        $response->assertJsonPath('data.rules_by_type', []);
        $response->assertJsonPath('data.executions_by_status', []);
        $response->assertJsonPath('data.avg_execution_time_ms', 0);
    }

    public function test_rules_index_returns_paginated_rules(): void
    {
        Sanctum::actingAs($this->user);

        $this->createRule(['rule_key' => 'rule_a', 'name' => 'Rule A']);
        $this->createRule(['rule_key' => 'rule_b', 'rule_type' => 'required_and_le_parent', 'name' => 'Rule B']);

        $response = $this->getJson('/api/v1/rule-engine/rules');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                ['id', 'rule_key', 'rule_type', 'name', 'source', 'severity', 'status', 'version'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonCount(2, 'data');
    }

    public function test_rules_index_filters_by_type(): void
    {
        Sanctum::actingAs($this->user);

        $this->createRule(['rule_key' => 'sum_rule', 'rule_type' => 'sum_equals']);
        $this->createRule(['rule_key' => 'req_rule', 'rule_type' => 'required_and_le_parent']);

        $response = $this->getJson('/api/v1/rule-engine/rules?rule_type=sum_equals');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.rule_key', 'sum_rule');
    }

    public function test_rules_show_returns_rule_with_relations(): void
    {
        Sanctum::actingAs($this->user);

        $rule = $this->createRule([
            'rule_key' => 'show_test',
            'description' => 'Test rule for show endpoint',
            'severity' => 'error',
            'scope' => 'per_row',
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => 1,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        RuleVersion::create([
            'rule_id' => $rule->id,
            'version' => '1.0.0',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'changelog' => 'Initial version',
        ]);

        $response = $this->getJson("/api/v1/rule-engine/rules/{$rule->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $rule->id);
        $response->assertJsonPath('data.rule_key', 'show_test');
        $response->assertJsonPath('data.rule_type', 'sum_equals');
        $response->assertJsonPath('data.severity', 'error');

        $data = $response->json('data');
        $this->assertArrayHasKey('bindings', $data);
        $this->assertArrayHasKey('versions', $data);
        $this->assertArrayHasKey('execution_logs', $data);
    }

    public function test_rules_show_returns_404_for_missing(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/rule-engine/rules/999');

        $response->assertNotFound();
    }

    public function test_logs_index_returns_paginated_logs(): void
    {
        Sanctum::actingAs($this->user);

        $rule = $this->createRule(['rule_key' => 'log_test']);
        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'log_test',
            'rem_upload_id' => $upload->id,
            'rem_template_structure_id' => null,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 5,
            'passed_rows' => 5,
            'failed_rows' => 0,
            'execution_ms' => 100,
            'triggered_by' => 'cli',
        ]);

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'log_test',
            'rem_upload_id' => $upload->id,
            'rem_template_structure_id' => null,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'failed',
            'total_rows' => 3,
            'passed_rows' => 0,
            'failed_rows' => 3,
            'execution_ms' => 200,
            'triggered_by' => 'job',
        ]);

        $response = $this->getJson('/api/v1/rule-engine/logs');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                ['id', 'rule_key', 'status', 'total_rows', 'passed_rows', 'failed_rows', 'execution_ms', 'triggered_by'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_logs_index_filters_by_status(): void
    {
        Sanctum::actingAs($this->user);

        $rule = $this->createRule(['rule_key' => 'filter_test']);
        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'filter_test',
            'rem_upload_id' => $upload->id,
            'rem_template_structure_id' => null,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 5,
            'passed_rows' => 5,
            'triggered_by' => 'cli',
        ]);

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'filter_test',
            'rem_upload_id' => $upload->id,
            'rem_template_structure_id' => null,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'failed',
            'total_rows' => 3,
            'failed_rows' => 3,
            'triggered_by' => 'job',
        ]);

        $response = $this->getJson('/api/v1/rule-engine/logs?status=passed');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.status', 'passed');
    }

    public function test_logs_show_returns_log_with_detail(): void
    {
        Sanctum::actingAs($this->user);

        $rule = $this->createRule([
            'rule_key' => 'log_show_test',
            'severity' => 'error',
        ]);
        $upload = $this->createUpload();

        $log = RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'log_show_test',
            'rem_upload_id' => $upload->id,
            'rem_template_structure_id' => null,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 10,
            'passed_rows' => 10,
            'failed_rows' => 0,
            'execution_ms' => 150,
            'triggered_by' => 'cli',
            'context' => ['note' => 'test context'],
        ]);

        $response = $this->getJson("/api/v1/rule-engine/logs/{$log->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $log->id);
        $response->assertJsonPath('data.rule_key', 'log_show_test');
        $response->assertJsonPath('data.status', 'passed');
        $response->assertJsonPath('data.total_rows', 10);
        $response->assertJsonPath('data.triggered_by', 'cli');
        $response->assertJsonPath('data.context.note', 'test context');

        $data = $response->json('data');
        $this->assertArrayHasKey('rule', $data);
        $this->assertArrayHasKey('upload', $data);
    }

    public function test_logs_show_returns_404_for_missing(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/rule-engine/logs/999');

        $response->assertNotFound();
    }

    public function test_structures_index_returns_paginated_structures(): void
    {
        Sanctum::actingAs($this->user);

        RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_a1',
            'status' => 'active',
            'estructura' => ['forms' => []],
        ]);

        RemTemplateStructure::create([
            'serie' => 'B',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_b1',
            'status' => 'active',
            'estructura' => ['forms' => []],
        ]);

        $response = $this->getJson('/api/v1/rule-engine/structures');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                ['id', 'anio', 'serie', 'version_number', 'status', 'source_filename', 'stats', 'created_at'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_structures_show_returns_structure_with_forms_detail(): void
    {
        Sanctum::actingAs($this->user);

        $structure = RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_detail',
            'source_filename' => 'REM_A.xlsx',
            'status' => 'active',
            'notes' => 'Test structure',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SEC1',
                                'titulo' => 'Seccion 1',
                                'filaHeader' => 1,
                                'filaInicioDatos' => 2,
                                'filaFinDatos' => 100,
                                'fields' => [
                                    ['letra' => 'A', 'label' => 'Campo A', 'reglaDetectada' => 'sum_equals'],
                                    ['letra' => 'B', 'label' => 'Campo B'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/v1/rule-engine/structures/{$structure->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $structure->id);
        $response->assertJsonPath('data.anio', 2026);
        $response->assertJsonPath('data.serie', 'A');
        $response->assertJsonPath('data.version_number', 1);
        $response->assertJsonPath('data.notes', 'Test structure');

        $data = $response->json('data');
        $this->assertArrayHasKey('forms_detail', $data);
        $this->assertArrayHasKey('stats', $data);

        $response->assertJsonPath('data.stats.total_forms', 1);
        $response->assertJsonPath('data.stats.total_sections', 1);
        $response->assertJsonPath('data.stats.total_fields', 2);
        $response->assertJsonPath('data.stats.total_rules', 1);
        $response->assertJsonPath('data.stats.sum_equals', 1);
    }

    public function test_structures_show_returns_404_for_missing(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/rule-engine/structures/999');

        $response->assertNotFound();
    }

    public function test_config_show_returns_config_for_authenticated_user(): void
    {
        Sanctum::actingAs($this->user);

        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);
        RuleEngineSetting::create(['key' => 'mode', 'value' => 'parallel']);

        $response = $this->getJson('/api/v1/rule-engine/config');

        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.mode', 'parallel');
    }

    public function test_config_returns_defaults_when_no_settings_exist(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/rule-engine/config');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['enabled', 'mode', 'fail_open', 'log_mode'],
        ]);
    }

    public function test_config_update_as_admin_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/v1/rule-engine/config', [
            'enabled' => true,
            'mode' => 'parallel',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.mode', 'parallel');

        $this->assertDatabaseHas('rule_engine_settings', ['key' => 'enabled', 'value' => '1']);
        $this->assertDatabaseHas('rule_engine_settings', ['key' => 'mode', 'value' => 'parallel']);
    }

    public function test_config_update_as_admin_validates_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson('/api/v1/rule-engine/config', [
            'mode' => 'invalid_mode',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('mode');
    }

    public function test_config_update_as_non_admin_returns_forbidden(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/v1/rule-engine/config', [
            'enabled' => false,
        ]);

        $response->assertForbidden();
    }

    public function test_config_update_requires_authentication(): void
    {
        $response = $this->putJson('/api/v1/rule-engine/config', [
            'enabled' => true,
        ]);

        $response->assertUnauthorized();
    }
}
