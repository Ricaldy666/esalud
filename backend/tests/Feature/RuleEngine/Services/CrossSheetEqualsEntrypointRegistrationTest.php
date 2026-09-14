<?php

namespace Tests\Feature\RuleEngine\Services;

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
use Tests\TestCase;

/**
 * REM BM -- FASE BM-2.6B, cierre (2026-09-14): confirma que
 * CrossSheetEqualsEvaluator quedo REALMENTE registrado en los 4
 * entrypoints reales del motor (no solo instanciable a mano dentro de un
 * test aislado, como hacia CrossSheetEqualsIntegrationTest). Cada test
 * invoca el entrypoint de produccion tal cual se usa en el flujo real
 * (job::handle(), Artisan::call(), ComparisonReport::generateReport()) sin
 * registrar el evaluador manualmente desde el test -- si algun entrypoint
 * perdiera su registro, exactamente estos tests fallarian.
 *
 * 100% fixtures sinteticas en la BD de testing (RefreshDatabase, nunca
 * esalud_dev/produccion). Serie generica 'A' (mismo criterio que
 * CrossSheetEqualsIntegrationTest) -- el mecanismo es generico para
 * cualquier serie, no se crea ninguna estructura/regla/binding de serie BM.
 */
class CrossSheetEqualsEntrypointRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function createHealthCenter(): int
    {
        return HealthCenter::create([
            'name' => 'Test Center',
            'code_deis' => 'TC' . uniqid(),
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
            'user_id' => User::factory()->create()->id,
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
            'hash_estructura' => 'hash_entrypoint_test_' . uniqid(),
            'status' => 'active',
        ], $overrides));
    }

    private function createCrossSheetRule(array $config, string $ruleKey = 'test_cross_sheet_rule'): Rule
    {
        return Rule::create([
            'rule_key' => $ruleKey,
            'rule_type' => 'cross_sheet_equals',
            'source' => 'excel_formula',
            'name' => 'Test Cross-Sheet Rule',
            'description' => 'Test cross_sheet_equals rule',
            'severity' => 'error',
            'scope' => 'single',
            'config' => $config,
            'status' => 'active',
            'version' => '1.0.0',
        ]);
    }

    private function bind(Rule $rule, RemTemplateStructure $structure): void
    {
        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => $structure->serie,
            'anio' => $structure->anio,
            'active' => true,
        ]);
    }

    // ── ValidateWithEngineJob ───────────────────────────────────────────

    public function test_validate_with_engine_job_registers_cross_sheet_evaluator(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'true']);

        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C3'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 10], 'row_number' => 3, 'concept' => 'Destino'],
        ]);

        $job = new ValidateWithEngineJob($upload->id);
        $job->handle(
            app(RuleEngineService::class),
            app(StructureResolverService::class),
            app(FeatureFlagService::class),
        );

        $log = RuleExecutionLog::where('rule_key', 'test_cross_sheet_rule')->first();

        $this->assertNotNull($log, 'ValidateWithEngineJob debe reconocer y ejecutar la regla cross_sheet_equals');
        $this->assertSame('passed', $log->status);
        $this->assertSame('job', $log->triggered_by);
    }

    // ── RuleValidateCommand (rule:validate) ─────────────────────────────

    public function test_rule_validate_command_registers_cross_sheet_evaluator(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C3'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 10], 'row_number' => 3, 'concept' => 'Destino'],
        ]);

        $this->artisan('rule:validate', [
            'upload_id' => $upload->id,
            'structure_id' => $structure->id,
            '--write' => true,
        ])->assertExitCode(0);

        $log = RuleExecutionLog::where('rule_key', 'test_cross_sheet_rule')->first();

        $this->assertNotNull($log, 'rule:validate debe reconocer y ejecutar la regla cross_sheet_equals');
        $this->assertSame('passed', $log->status);
    }

    // ── ComparisonReport (respalda /rule-engine/comparison) ─────────────

    public function test_comparison_report_registers_cross_sheet_evaluator(): void
    {
        // reglaDetectada con 'tipo' => null: el builder legacy
        // (RemFormulaRuleBuilder) no reconoce relaciones cross-hoja -- lanza
        // TypeError al construir ValidationRuleDTO (ruleType es string
        // no-nullable), exactamente el escenario real ("Builder legacy no
        // soporta esta estructura") que activa la rama engine_only de
        // ComparisonReport::generateReport(), la unica que expone
        // total_engine_rules/engine_summary crudos.
        $structure = $this->createStructure([
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'SheetA',
                        'sections' => [
                            [
                                'codigo' => 'A',
                                'fields' => [
                                    [
                                        'letra' => 'B',
                                        'label' => 'Origen',
                                        'reglaDetectada' => ['tipo' => null, 'columnasOrigen' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C3'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 10], 'row_number' => 3, 'concept' => 'Destino'],
        ]);

        $report = app(ComparisonReport::class);
        $result = $report->generateReport($structure->id, $upload->id);

        $this->assertTrue($result['engine_only'] ?? false, 'Se esperaba la rama engine_only (builder legacy sin soporte)');
        $this->assertSame(1, $result['total_engine_rules']);
        $this->assertSame(1, $result['engine_summary']['passed']);
        $this->assertSame(0, $result['engine_summary']['failed']);
    }

    // ── TempValidateFlowCommand (temp:validate-flow) ────────────────────

    public function test_temp_validate_flow_command_registers_cross_sheet_evaluator(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C3'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 10], 'row_number' => 3, 'concept' => 'Destino'],
        ]);

        $this->artisan('temp:validate-flow', ['upload_id' => $upload->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('test_cross_sheet_rule: status=passed');
    }
}
