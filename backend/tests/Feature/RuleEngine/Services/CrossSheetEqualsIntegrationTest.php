<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\CrossSheetEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REM BM -- FASE BM-2.6B (2026-09-11): test de integracion END-TO-END del
 * pipeline completo (RuleEngineService::execute() -> resolucion de
 * _target_rows -> CrossSheetEqualsEvaluator) contra fixtures 100%
 * sinteticas (RefreshDatabase, base de datos de test aislada -- nunca
 * toca esalud_dev/produccion). NO crea ninguna estructura/regla/binding
 * de serie BM -- usa serie 'A' generica (mismo patron ya usado por
 * RuleEngineServiceTest), ya que el mecanismo es generico para cualquier
 * serie, no especifico de BM.
 */
class CrossSheetEqualsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private RuleEngineService $service;
    private FunctionalRuleService $functionalRuleMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->functionalRuleMock = $this->createMock(FunctionalRuleService::class);
        $this->functionalRuleMock->method('getFunctionalRulesForEngine')->willReturn([]);

        $this->service = new RuleEngineService($this->functionalRuleMock);
        $this->service->registerEvaluator(new CrossSheetEqualsEvaluator());
    }

    private function createHealthCenter(): int
    {
        return HealthCenter::create([
            'name' => 'Test Center',
            'code_deis' => 'TC' . uniqid(),
            'type' => 'CESFAM',
        ])->id;
    }

    private function createUpload(): RemUpload
    {
        return RemUpload::create([
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
        ]);
    }

    private function createStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'hash_estructura' => 'hash_cross_sheet_test_' . uniqid(),
            'status' => 'active',
        ]);
    }

    private function createCrossSheetRule(array $config): Rule
    {
        return Rule::create([
            'rule_key' => 'test_cross_sheet_rule',
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
            'serie' => 'A',
            'anio' => 2026,
            'active' => true,
        ]);
    }

    // ── SheetA!B2 == SheetB!C3 ────────────────────────────────────────

    public function test_cross_sheet_equal_values_pass(): void
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

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['total_rules']);
        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_cross_sheet_unequal_values_fail(): void
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
            'data' => ['values' => ['B' => 9], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 10], 'row_number' => 3, 'concept' => 'Destino'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(0, $result['passed']);
        $this->assertSame(1, $result['failed']);
    }

    // ── SheetA!B3 == SUM(SheetB!D1:D3) ─────────────────────────────────

    public function test_cross_sheet_sum_range_passes(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B3'],
            'target' => ['sheet' => 'SheetB', 'range' => 'D1:D3', 'aggregation' => 'sum'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 6], 'row_number' => 3, 'concept' => 'Origen'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['D' => 1], 'row_number' => 1, 'concept' => 'D1'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['D' => 2], 'row_number' => 2, 'concept' => 'D2'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['D' => 3], 'row_number' => 3, 'concept' => 'D3'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(0, $result['failed']);
    }

    // ── Nunca cruza uploads: mismo SheetB, otro upload, no debe mezclarse ──

    public function test_never_reads_target_rows_from_a_different_upload(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $otherUpload = $this->createUpload();

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
        // SheetB!C3 solo existe en el OTRO upload -- nunca debe leerse.
        RemData::create([
            'rem_upload_id' => $otherUpload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 10], 'row_number' => 3, 'concept' => 'Destino'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['executed'], 'target_cell_not_found no debe contar como ejecutada');
        $this->assertSame(0, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['skipped'], 'target_cell_not_found debe contar como skipped, nunca leer del otro upload');
    }
}
