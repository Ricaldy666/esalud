<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\RequiredAndLeParentEvaluator;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    private RuleEngineService $service;
    private FunctionalRuleService $functionalRuleMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->functionalRuleMock = $this->createMock(FunctionalRuleService::class);
        $this->functionalRuleMock->method('getFunctionalRulesForEngine')
            ->willReturn([]);

        $this->service = new RuleEngineService($this->functionalRuleMock);
        $this->service->registerEvaluator(new SumEqualsEvaluator);
        $this->service->registerEvaluator(new RequiredAndLeParentEvaluator);
    }

    private function createHealthCenter(): int
    {
        return HealthCenter::create([
            'name' => 'Test Center',
            'code_deis' => 'TC001',
            'type' => 'CESFAM',
        ])->id;
    }

    private function createUser(): int
    {
        return User::factory()->create()->id;
    }

    private function createUpload(array $overrides = []): RemUpload
    {
        return RemUpload::create(array_merge([
            'rem_type' => 'A',
            'year' => 2026,
            'month' => 7,
            'status' => 'pending',
            'health_center_id' => $this->createHealthCenter(),
            'user_id' => $this->createUser(),
            'original_filename' => 'test.xlsx',
            'stored_path' => 'rem/2026/07/test.xlsx',
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], $overrides));
    }

    private function createStructure(array $overrides = []): RemTemplateStructure
    {
        return RemTemplateStructure::create(array_merge([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'hash_estructura' => 'hash_test',
            'status' => 'active',
        ], $overrides));
    }

    private function createRule(array $overrides = []): Rule
    {
        return Rule::create(array_merge([
            'rule_key' => 'test_rule_sum',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Test Rule Sum',
            'description' => 'Test sum equals rule',
            'severity' => 'error',
            'scope' => 'per_row',
            'config' => [
                'source_letters' => ['A', 'B'],
                'target_column' => 'C',
            ],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => [
                'sheet' => 'Hoja1',
                'source_structure_id' => 1,
            ],
        ], $overrides));
    }

    public function test_resolve_rules_returns_active_rules_for_structure(): void
    {
        $structure = $this->createStructure();

        $rule = $this->createRule();

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        $rules = $this->service->resolveRules($structure->id);

        $this->assertCount(1, $rules);
        $this->assertSame($rule->id, $rules->first()->id);
    }

    public function test_resolve_rules_returns_only_active_rules(): void
    {
        $structure = $this->createStructure();

        $activeRule = $this->createRule(['rule_key' => 'active_rule']);
        $inactiveRule = $this->createRule([
            'rule_key' => 'inactive_rule',
            'status' => 'inactive',
        ]);

        RuleBinding::create([
            'rule_id' => $activeRule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        RuleBinding::create([
            'rule_id' => $inactiveRule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        $rules = $this->service->resolveRules($structure->id);

        $this->assertCount(1, $rules);
        $this->assertSame($activeRule->id, $rules->first()->id);
    }

    public function test_resolve_rules_returns_empty_when_no_bindings(): void
    {
        $structure = $this->createStructure();

        $rules = $this->service->resolveRules($structure->id);

        $this->assertCount(0, $rules);
    }

    public function test_resolve_rules_matches_by_serie_and_anio(): void
    {
        $structure = $this->createStructure(['serie' => 'B', 'hash_estructura' => 'hash_b']);

        $rule = $this->createRule(['rule_key' => 'serie_rule']);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'serie',
            'bindable_id' => null,
            'serie' => 'B',
            'anio' => 2026,
            'active' => true,
        ]);

        $rules = $this->service->resolveRules($structure->id, $structure);

        $this->assertCount(1, $rules);
        $this->assertSame($rule->id, $rules->first()->id);
    }

    public function test_resolve_rules_matches_global_binding(): void
    {
        $structure = $this->createStructure();

        $rule = $this->createRule(['rule_key' => 'global_rule']);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'global',
            'bindable_id' => null,
            'active' => true,
        ]);

        $rules = $this->service->resolveRules($structure->id, $structure);

        $this->assertCount(1, $rules);
        $this->assertSame($rule->id, $rules->first()->id);
    }

    public function test_execute_dry_run_returns_results_without_persisting(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'config' => [
                'source_letters' => ['A', 'B'],
                'target_column' => 'C',
            ],
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

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 10, 'B' => 5, 'C' => 15],
                'row_number' => 1,
                'concept' => 'Test',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame($upload->id, $result['upload_id']);
        $this->assertSame($structure->id, $result['structure_id']);
        $this->assertSame(1, $result['total_rules']);
        $this->assertSame(1, $result['executed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(0, $result['failed']);

        $this->assertSame(0, RuleExecutionLog::count());
        $this->assertSame(0, RemValidationResult::count());
    }

    public function test_execute_with_write_persists_logs_and_results(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'config' => [
                'source_letters' => ['A', 'B'],
                'target_column' => 'C',
            ],
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

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 10, 'B' => 5, 'C' => 15],
                'row_number' => 1,
                'concept' => 'Test',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: true, triggeredBy: 'cli');

        $this->assertSame(1, $result['passed']);

        $this->assertSame(1, RuleExecutionLog::count());
        $log = RuleExecutionLog::first();
        $this->assertSame($rule->id, $log->rule_id);
        $this->assertSame($upload->id, $log->rem_upload_id);
        $this->assertSame('passed', $log->status);
        $this->assertSame(1, $log->total_rows);
        $this->assertSame(1, $log->passed_rows);
        $this->assertSame(0, $log->failed_rows);
        $this->assertSame('cli', $log->triggered_by);
        $this->assertNotNull($log->execution_id);

        $this->assertSame(1, RemValidationResult::count());
        $vr = RemValidationResult::first();
        $this->assertSame($rule->id, $vr->rule_id);
        $this->assertSame($upload->id, $vr->rem_upload_id);
        $this->assertSame($rule->rule_key, $vr->rule_key);
        $this->assertTrue($vr->passed);
    }

    public function test_execute_skipped_when_no_data(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'metadata' => ['sheet' => 'Hoja2'],
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => ['values' => [], 'row_number' => 1],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $this->assertSame(1, $result['total_rules']);
        $this->assertSame(0, $result['executed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('skipped', $result['details'][0]['status']);
        $this->assertSame('Sin datos', $result['details'][0]['reason']);

        $this->assertSame(1, RuleExecutionLog::count());
        $this->assertSame('skipped', RuleExecutionLog::first()->status);
    }

    public function test_execute_triggered_by_cli(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'config' => [
                'source_letters' => ['A'],
                'target_column' => 'B',
            ],
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

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 5, 'B' => 5],
                'row_number' => 1,
            ],
        ]);

        $this->service->execute($upload->id, $structure->id, write: true, triggeredBy: 'cli');

        $this->assertSame('cli', RuleExecutionLog::first()->triggered_by);
    }

    public function test_execute_triggered_by_job(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'config' => [
                'source_letters' => ['A'],
                'target_column' => 'B',
            ],
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

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 5, 'B' => 5],
                'row_number' => 1,
            ],
        ]);

        $this->service->execute($upload->id, $structure->id, write: true, triggeredBy: 'job');

        $this->assertSame('job', RuleExecutionLog::first()->triggered_by);
    }

    public function test_execute_does_not_duplicate_results_for_same_rule(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'config' => [
                'source_letters' => ['A'],
                'target_column' => 'B',
            ],
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

        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'serie',
            'bindable_id' => null,
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 3, 'B' => 3],
                'row_number' => 1,
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $this->assertSame(1, $result['total_rules']);
        $this->assertSame(1, RuleExecutionLog::count());
        $this->assertSame(1, RemValidationResult::count());
    }

    public function test_execute_reports_failed_rules(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createRule([
            'config' => [
                'source_letters' => ['A', 'B'],
                'target_column' => 'C',
            ],
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

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'Hoja1',
            'data' => [
                'values' => ['A' => 10, 'B' => 5, 'C' => 20],
                'row_number' => 1,
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $this->assertSame(0, $result['passed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', $result['details'][0]['status']);

        $this->assertSame('failed', RuleExecutionLog::first()->status);

        $this->assertFalse(RemValidationResult::first()->passed);
    }
}
