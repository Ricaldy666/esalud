<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTechnicalTotal;
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

    // ── BM-8.2: target resuelto desde rem_technical_totals cuando esta ausente de rem_data ──

    private function createTechnicalTotal(int $uploadId, string $sheet, string $section, int $row, array $values): void
    {
        RemTechnicalTotal::create([
            'rem_upload_id' => $uploadId,
            'sheet' => $sheet,
            'rem_section_code' => $section,
            'row_number' => $row,
            'concept' => 'TOTAL',
            'total' => null,
            'values' => $values,
            'exclusion_reason' => 'embedded_trailing_total_row',
        ]);
    }

    public function test_cross_sheet_direct_target_resolves_from_technical_totals_when_absent_from_rem_data(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C10'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        // Fila 10 de SheetB NUNCA existe en rem_data -- solo en rem_technical_totals
        // (patron real: una fila TOTAL tecnica correctamente excluida por el parser).
        $this->createTechnicalTotal($upload->id, 'SheetB', 'B', 10, ['C' => 10]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_cross_sheet_target_absent_from_both_rem_data_and_technical_totals_is_skipped(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C99'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        // Fila 99 de SheetB no existe en ningun lado.

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['executed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['passed']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_cross_sheet_target_present_in_both_rem_data_and_technical_totals_prefers_rem_data(): void
    {
        // Comportamiento determinista ante coexistencia (caso defensivo, no
        // se ha observado en datos reales -- el parser nunca persiste la
        // misma fila en ambos origenes -- pero el metodo debe resolverlo
        // sin ambiguedad): rem_data SIEMPRE gana.
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C5'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 77], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        // Misma fila 5 en AMBOS origenes, con valores DISTINTOS -- rem_data
        // declara 77 (debe ganar), technical_totals declara 999 (debe ignorarse).
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetB',
            'data' => ['values' => ['C' => 77], 'row_number' => 5, 'concept' => 'Destino real'],
        ]);
        $this->createTechnicalTotal($upload->id, 'SheetB', 'B', 5, ['C' => 999]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, $result['passed'], 'debe comparar 77 (rem_data) contra 77 (source), ignorando el 999 de technical_totals');
        $this->assertSame(0, $result['failed']);
    }

    public function test_cross_sheet_technical_total_from_another_sheet_does_not_contaminate(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C10'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        // Fila 10 columna C SI existe como technical_total, pero en SheetC (otra hoja) -- no debe usarse para SheetB.
        $this->createTechnicalTotal($upload->id, 'SheetC', 'X', 10, ['C' => 10]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['executed']);
        $this->assertSame(1, $result['skipped'], 'la fila tecnica de SheetC nunca debe resolver un target declarado en SheetB');
    }

    public function test_cross_sheet_technical_total_from_another_upload_does_not_leak(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $otherUpload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B2'],
            'target' => ['sheet' => 'SheetB', 'cell' => 'C10'],
        ]);
        $this->bind($rule, $structure);

        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'SheetA',
            'data' => ['values' => ['B' => 10], 'row_number' => 2, 'concept' => 'Origen'],
        ]);
        // La fila tecnica SI existe, pero en el OTRO upload -- nunca debe cruzarse.
        $this->createTechnicalTotal($otherUpload->id, 'SheetB', 'B', 10, ['C' => 10]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['executed']);
        $this->assertSame(1, $result['skipped'], 'la fila tecnica del otro upload nunca debe leerse');
    }

    public function test_cross_sheet_sum_range_over_rem_data_still_works_after_technical_totals_merge(): void
    {
        // Confirma que agregar el merge de technical_totals no rompe el
        // camino SUM_RANGE existente (que sigue resolviendo unicamente
        // desde rem_data en este escenario, sin ninguna fila tecnica de por medio).
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = $this->createCrossSheetRule([
            'sheet' => 'SheetA',
            'section' => 'A',
            'source' => ['cell' => 'B3'],
            'target' => ['sheet' => 'SheetB', 'range' => 'D1:D3', 'aggregation' => 'sum'],
        ]);
        $this->bind($rule, $structure);

        RemData::create(['rem_upload_id' => $upload->id, 'section' => 'SheetA', 'data' => ['values' => ['B' => 6], 'row_number' => 3, 'concept' => 'Origen']]);
        RemData::create(['rem_upload_id' => $upload->id, 'section' => 'SheetB', 'data' => ['values' => ['D' => 1], 'row_number' => 1, 'concept' => 'D1']]);
        RemData::create(['rem_upload_id' => $upload->id, 'section' => 'SheetB', 'data' => ['values' => ['D' => 2], 'row_number' => 2, 'concept' => 'D2']]);
        RemData::create(['rem_upload_id' => $upload->id, 'section' => 'SheetB', 'data' => ['values' => ['D' => 3], 'row_number' => 3, 'concept' => 'D3']]);
        // Fila tecnica ajena en el mismo rango de filas pero en otra hoja -- no debe sumarse.
        $this->createTechnicalTotal($upload->id, 'SheetX', 'Z', 2, ['D' => 999]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['executed']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(0, $result['failed']);
    }
}
