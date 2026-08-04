<?php

namespace Tests\Feature\RuleEngine\Integration;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Jobs\ValidateWithEngineJob;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleEngineSetting;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Domain\RuleEngine\Services\FeatureFlagService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Domain\RuleEngine\Services\StructureResolverService;
use App\Domain\RuleEngine\Testing\ComparisonReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RuleEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Administrador']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Administrador');
    }

    private function createHealthCenter(): int
    {
        return HealthCenter::create([
            'name' => 'HC',
            'code_deis' => 'HC001',
            'type' => 'CESFAM',
        ])->id;
    }

    private function createUpload(array $overrides = []): RemUpload
    {
        return RemUpload::create(array_merge([
            'rem_type' => 'A',
            'year' => 2026,
            'month' => 7,
            'status' => 'pending',
            'health_center_id' => $this->createHealthCenter(),
            'user_id' => $this->admin->id,
            'original_filename' => 'test.xlsx',
            'stored_path' => 'rem/2026/07/test.xlsx',
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], $overrides));
    }

    // ============ COMPARISON LEGACY VS RULE ENGINE ============

    public function test_compare_legacy_100_percent_match_controlled(): void
    {
        $structure = RemTemplateStructure::create([
            'serie' => 'Z',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_compare_100',
            'status' => 'active',
            'source_filename' => 'REM_Z.xlsx',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SEC1',
                                'fields' => [
                                    [
                                        'letra' => 'A',
                                        'label' => 'Total',
                                        'reglaDetectada' => [
                                            'tipo' => 'sum_equals',
                                            'columnasOrigen' => ['B1:B100'],
                                            'columnaDestino' => null,
                                            'rangoFilas' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $upload = $this->createUpload([
            'rem_type' => 'Z',
            'original_filename' => 'REM_Z_2026.xlsx',
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 5, 'B' => 5],
                'row_number' => 1,
                'concept' => 'Test row',
            ],
        ]);

        $rule = Rule::create([
            'rule_key' => 'hoja1_sec1_a_sum_equals',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Hoja1/SEC1 A: sum_equals',
            'severity' => 'error',
            'scope' => 'per_row',
            'config' => [
                'tipo' => 'sum_equals',
                'source_columns' => ['B1:B100'],
                'target_column' => 'A',
                'columna_destino' => null,
                'rango_filas' => null,
                'scope' => 'per_row',
                'source_letters' => ['B'],
            ],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => [
                'sheet' => 'Hoja1',
                'source_structure_id' => $structure->id,
            ],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'Z',
            'anio' => 2026,
            'active' => true,
        ]);

        $report = app(ComparisonReport::class);
        $result = $report->generateReport($structure->id, $upload->id);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(100.0, $result['summary']['match_percentage']);
        $this->assertSame(1, $result['summary']['total_rules_in_map']);
        $this->assertSame(1, $result['summary']['match_count']);
        $this->assertSame(0, $result['summary']['difference_count']);
    }

    public function test_compare_legacy_detects_differences_when_rule_altered(): void
    {
        $structure = RemTemplateStructure::create([
            'serie' => 'Y',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_compare_diff',
            'status' => 'active',
            'source_filename' => 'REM_Y.xlsx',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SEC1',
                                'fields' => [
                                    [
                                        'letra' => 'A',
                                        'label' => 'Total',
                                        'reglaDetectada' => [
                                            'tipo' => 'sum_equals',
                                            'columnasOrigen' => ['B1:B100'],
                                            'columnaDestino' => null,
                                            'rangoFilas' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $upload = $this->createUpload([
            'rem_type' => 'Y',
            'original_filename' => 'REM_Y_2026.xlsx',
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 5, 'B' => 5, 'C' => 3],
                'row_number' => 1,
                'concept' => 'Test row',
            ],
        ]);

        $rule = Rule::create([
            'rule_key' => 'hoja1_sec1_a_sum_equals',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Hoja1/SEC1 A: sum_equals',
            'severity' => 'error',
            'scope' => 'per_row',
            'config' => [
                'tipo' => 'sum_equals',
                'source_columns' => ['B1:B100'],
                'target_column' => 'A',
                'columna_destino' => null,
                'rango_filas' => null,
                'scope' => 'per_row',
                'source_letters' => ['B'],
            ],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => [
                'sheet' => 'Hoja1',
                'source_structure_id' => $structure->id,
            ],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'Y',
            'anio' => 2026,
            'active' => true,
        ]);

        $rule->update([
            'config' => [
                'tipo' => 'sum_equals',
                'source_columns' => ['B1:B100'],
                'target_column' => 'A',
                'columna_destino' => null,
                'rango_filas' => null,
                'scope' => 'per_row',
                'source_letters' => ['B', 'C'],
            ],
        ]);

        $report = app(ComparisonReport::class);
        $result = $report->generateReport($structure->id, $upload->id);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['summary']['total_rules_in_map']);
        $this->assertSame(1, $result['summary']['difference_count']);
        $this->assertSame(0, $result['summary']['match_count']);
        $this->assertSame(0.0, $result['summary']['match_percentage']);
    }

    public function test_compare_legacy_reports_differences_correctly(): void
    {
        $structure = RemTemplateStructure::create([
            'serie' => 'X',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_diff_report',
            'status' => 'active',
            'source_filename' => 'REM_X.xlsx',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SEC1',
                                'fields' => [
                                    [
                                        'letra' => 'A',
                                        'label' => 'Total',
                                        'reglaDetectada' => [
                                            'tipo' => 'sum_equals',
                                            'columnasOrigen' => ['B1:B100'],
                                            'columnaDestino' => null,
                                            'rangoFilas' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $upload = $this->createUpload([
            'rem_type' => 'X',
            'original_filename' => 'REM_X_2026.xlsx',
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 5, 'B' => 5, 'C' => 3],
                'row_number' => 1,
                'concept' => 'Test row',
            ],
        ]);

        $rule = Rule::create([
            'rule_key' => 'hoja1_sec1_a_sum_equals',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Hoja1/SEC1 A: sum_equals',
            'severity' => 'error',
            'scope' => 'per_row',
            'config' => [
                'tipo' => 'sum_equals',
                'source_columns' => ['B1:B100'],
                'target_column' => 'A',
                'columna_destino' => null,
                'rango_filas' => null,
                'scope' => 'per_row',
                'source_letters' => ['B', 'C'],
            ],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => [
                'sheet' => 'Hoja1',
                'source_structure_id' => $structure->id,
            ],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'X',
            'anio' => 2026,
            'active' => true,
        ]);

        $report = app(ComparisonReport::class);
        $result = $report->generateReport($structure->id, $upload->id);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertNotEmpty($result['differences']);

        $diff = $result['differences'][0];
        $this->assertArrayHasKey('comp_key', $diff);
        $this->assertArrayHasKey('new_key', $diff);
        $this->assertArrayHasKey('legacy', $diff);
        $this->assertArrayHasKey('engine', $diff);
        $this->assertArrayHasKey('status_match', $diff);
        $this->assertArrayHasKey('failed_match', $diff);
        $this->assertArrayHasKey('rows_match', $diff);
        $this->assertIsArray($diff['legacy']);
        $this->assertIsArray($diff['engine']);
    }

    // ============ FEATURE FLAG + JOB ============

    public function test_job_does_not_run_when_disabled(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'false']);

        $structure = RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_job_disabled',
            'status' => 'active',
            'estructura' => ['forms' => []],
        ]);

        $upload = $this->createUpload();

        $rule = Rule::create([
            'rule_key' => 'job_test_disabled',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Job Test Disabled',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        $job = new ValidateWithEngineJob($upload->id);
        $job->handle(
            app(RuleEngineService::class),
            app(StructureResolverService::class),
            app(FeatureFlagService::class),
        );

        $this->assertSame(0, RuleExecutionLog::count());
    }

    public function test_job_runs_when_enabled(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        $structure = RemTemplateStructure::create([
            'serie' => 'B',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_job_enabled',
            'status' => 'active',
            'estructura' => ['forms' => []],
        ]);

        $upload = $this->createUpload(['rem_type' => 'B']);

        $rule = Rule::create([
            'rule_key' => 'job_test_enabled',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Job Test Enabled',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'B',
            'anio' => 2026,
            'active' => true,
        ]);

        $job = new ValidateWithEngineJob($upload->id);
        $job->handle(
            app(RuleEngineService::class),
            app(StructureResolverService::class),
            app(FeatureFlagService::class),
        );

        $this->assertGreaterThan(0, RuleExecutionLog::count());
    }

    public function test_job_triggered_by_is_job(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        $structure = RemTemplateStructure::create([
            'serie' => 'C',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_job_trigger',
            'status' => 'active',
            'estructura' => ['forms' => []],
        ]);

        $upload = $this->createUpload(['rem_type' => 'C']);

        $rule = Rule::create([
            'rule_key' => 'job_test_trigger',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Job Trigger Test',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'C',
            'anio' => 2026,
            'active' => true,
        ]);

        $job = new ValidateWithEngineJob($upload->id);
        $job->handle(
            app(RuleEngineService::class),
            app(StructureResolverService::class),
            app(FeatureFlagService::class),
        );

        $logs = RuleExecutionLog::all();
        foreach ($logs as $log) {
            $this->assertSame('job', $log->triggered_by);
        }
    }

    public function test_job_fail_open_does_not_throw(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);
        RuleEngineSetting::create(['key' => 'fail_open', 'value' => 'true']);

        $upload = $this->createUpload();

        $job = new ValidateWithEngineJob($upload->id);

        $job->handle(
            app(RuleEngineService::class),
            app(StructureResolverService::class),
            app(FeatureFlagService::class),
        );

        $this->assertSame(0, RuleExecutionLog::count());
    }

    public function test_job_returns_early_when_upload_missing(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        $job = new ValidateWithEngineJob(999);

        $job->handle(
            app(RuleEngineService::class),
            app(StructureResolverService::class),
            app(FeatureFlagService::class),
        );

        $this->assertSame(0, RuleExecutionLog::count());
    }

    // ============ OBSERVABILITY COMMANDS ============

    public function test_rule_health_command_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $this->artisan('rule:health')
            ->assertExitCode(0);
    }

    public function test_rule_health_command_with_data(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        Rule::create([
            'rule_key' => 'health_cmd',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Health Cmd',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        $this->artisan('rule:health')
            ->assertExitCode(0);
    }

    public function test_rule_stats_command_succeeds(): void
    {
        $this->artisan('rule:stats')
            ->assertExitCode(0);
    }

    public function test_rule_stats_command_with_data(): void
    {
        $rule = Rule::create([
            'rule_key' => 'stats_cmd',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Stats Cmd',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'stats_cmd',
            'rem_upload_id' => $upload->id,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 10,
            'passed_rows' => 10,
            'failed_rows' => 0,
            'execution_ms' => 50,
            'triggered_by' => 'cli',
        ]);

        $this->artisan('rule:stats')
            ->assertExitCode(0)
            ->expectsOutputToContain('sum_equals');
    }

    public function test_rule_last_executions_command_succeeds(): void
    {
        $this->artisan('rule:last-executions')
            ->assertExitCode(0);
    }

    public function test_rule_last_executions_with_data(): void
    {
        $rule = Rule::create([
            'rule_key' => 'last_exec_cmd',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Last Exec',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'last_exec_cmd',
            'rem_upload_id' => $upload->id,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 5,
            'passed_rows' => 5,
            'failed_rows' => 0,
            'execution_ms' => 100,
            'triggered_by' => 'cli',
        ]);

        $this->artisan('rule:last-executions')
            ->assertExitCode(0)
            ->expectsOutputToContain('last_exec_cmd');
    }

    public function test_rule_last_executions_filters_by_status(): void
    {
        $rule = Rule::create([
            'rule_key' => 'last_exec_filter',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Last Exec Filter',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'last_exec_filter',
            'rem_upload_id' => $upload->id,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 5,
            'passed_rows' => 5,
            'failed_rows' => 0,
            'execution_ms' => 100,
            'triggered_by' => 'cli',
        ]);

        $this->artisan('rule:last-executions --status=passed')
            ->assertExitCode(0);

        $this->artisan('rule:last-executions --status=failed')
            ->expectsOutputToContain('No se encontraron')
            ->assertExitCode(0);
    }

    public function test_rule_diff_summary_command_succeeds(): void
    {
        $this->artisan('rule:diff-summary')
            ->assertExitCode(0);
    }

    public function test_rule_diff_summary_with_data(): void
    {
        $rule = Rule::create([
            'rule_key' => 'diff_cmd',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Diff Cmd',
            'config' => ['source_letters' => ['A'], 'target_column' => 'B'],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => 'Hoja1'],
        ]);

        $upload = $this->createUpload();

        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => 'diff_cmd',
            'rem_upload_id' => $upload->id,
            'execution_id' => Str::uuid()->toString(),
            'status' => 'passed',
            'total_rows' => 10,
            'passed_rows' => 10,
            'failed_rows' => 0,
            'execution_ms' => 50,
            'triggered_by' => 'cli',
        ]);

        $this->artisan('rule:diff-summary')
            ->assertExitCode(0)
            ->expectsOutputToContain('Passed');
    }
}
