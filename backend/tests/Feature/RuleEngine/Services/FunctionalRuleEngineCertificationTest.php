<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FunctionalRuleEngineCertificationTest extends TestCase
{
    use RefreshDatabase;

    private RuleEngineService $service;
    private FunctionalRuleService $functionalRuleMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->functionalRuleMock = $this->createMock(FunctionalRuleService::class);
        $this->service = new RuleEngineService($this->functionalRuleMock);
        $this->service->registerEvaluator(new SumEqualsEvaluator);
    }

    private function createHealthCenter(string $name = 'CESFAM Prueba', string $type = 'CESFAM'): HealthCenter
    {
        return HealthCenter::create([
            'name' => $name,
            'code_deis' => 'HC_' . uniqid(),
            'type' => $type,
        ]);
    }

    private function createUser(): int
    {
        return User::factory()->create()->id;
    }

    private function createUpload(int $healthCenterId): RemUpload
    {
        return RemUpload::create([
            'rem_type' => 'A',
            'year' => 2026,
            'month' => 7,
            'status' => 'pending',
            'health_center_id' => $healthCenterId,
            'user_id' => $this->createUser(),
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
            'hash_estructura' => 'hash_cert',
            'status' => 'active',
        ]);
    }

    private function createRule(array $overrides = []): Rule
    {
        return Rule::create(array_merge([
            'rule_key' => 'cert_test_sum',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'Certification Test Rule',
            'description' => 'Sum equals rule for certification',
            'severity' => 'error',
            'scope' => 'per_row',
            'config' => [
                'source_letters' => ['A', 'B'],
                'target_column' => 'C',
            ],
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => [
                'sheet' => 'A01',
                'source_structure_id' => 1,
            ],
        ], $overrides));
    }

    private function bindRule(Rule $rule, RemTemplateStructure $structure): void
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

    private function addRow(RemUpload $upload, int $rowNumber, array $values, string $section = 'A01', string $concept = 'Test'): void
    {
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => $section,
            'data' => [
                'values' => $values,
                'row_number' => $rowNumber,
                'concept' => $concept,
            ],
        ]);
    }

    private function expectFunctionalRules(array $rulesByRow): void
    {
        $this->functionalRuleMock
            ->method('getFunctionalRulesForEngine')
            ->willReturn($rulesByRow);
    }

    private function rowResult(array $details, int $rowNumber): ?array
    {
        foreach ($details as $d) {
            if (($d['row_number'] ?? null) === $rowNumber) {
                return $d;
            }
        }
        return null;
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2. CERTIFY: puede_quedar_vacio
    // ═════════════════════════════════════════════════════════════════════

    public function test_puede_quedar_vacio_fila_vacia_aprobado_skips_row(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Fila 12 puede quedar vacia',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['total_rules']);
        $this->assertSame(0, $result['executed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['passed']);
        $this->assertSame(0, $result['failed']);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    public function test_puede_quedar_vacio_con_datos_correctos_pasa(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 15]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Fila 12 puede quedar vacia',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['passed']);
        $detail = $result['details'][0];
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
    }

    public function test_puede_quedar_vacio_con_datos_incorrectos_falla(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 20]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Fila 12 puede quedar vacia',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['failed']);
        $detail = $result['details'][0];
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame(1, $detail['failed_rows']);
    }

    public function test_puede_quedar_vacio_con_texto_no_numerico_falla(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 'texto', 'B' => 5, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['failed']);
    }

    public function test_puede_quedar_vacio_total_vacio_pero_componentes_con_datos_falla(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['failed']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3. CERTIFY: debe_registrar_cero
    // ═════════════════════════════════════════════════════════════════════

    public function test_debe_registrar_cero_todo_cero_pasa(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 5, ['A' => 0, 'B' => 0, 'C' => 0]);

        $this->expectFunctionalRules([
            5 => [
                'empty_behavior' => 'debe_registrar_cero',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Debe registrar cero',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['passed']);
        $detail = $result['details'][0];
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
    }

    public function test_debe_registrar_cero_todo_vacio_se_considera_cero_y_pasa(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 5, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            5 => [
                'empty_behavior' => 'debe_registrar_cero',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['passed']);
    }

    public function test_debe_registrar_cero_con_datos_no_cero_valida_normalmente(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 5, ['A' => 10, 'B' => 5, 'C' => 15]);

        $this->expectFunctionalRules([
            5 => [
                'empty_behavior' => 'debe_registrar_cero',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['passed']);
    }

    public function test_debe_registrar_cero_con_componentes_cero_total_incorrecto_falla(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 5, ['A' => 0, 'B' => 0, 'C' => 100]);

        $this->expectFunctionalRules([
            5 => [
                'empty_behavior' => 'debe_registrar_cero',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['failed']);
    }

    public function test_debe_registrar_cero_con_texto_no_numerico_falla(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 5, ['A' => 'ABC', 'B' => 0, 'C' => 0]);

        $this->expectFunctionalRules([
            5 => [
                'empty_behavior' => 'debe_registrar_cero',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['failed']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1. STATE MATRIX: Which states affect the engine
    // ═════════════════════════════════════════════════════════════════════

    public function test_estado_pendiente_no_afecta_motor(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'pendiente',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
        // pendiente no activa el puede_quedar_vacio, fila vacia se maneja normalmente
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_estado_propuesta_no_afecta_motor(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'propuesta',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_rechazada_no_omite_validacion(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 15]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'rechazada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        // rechazada should NOT skip validation — row is validated normally
        $detail = $result['details'][0];
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
        $this->assertSame('passed', $detail['status']);
    }

    public function test_no_aplica_aprobado_excluye_fila(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 15]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'no_aplica',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'No aplica aprobado',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
        $this->assertSame('filtered_by_functional_rule', $detail['reason']);
    }

    public function test_no_aplica_pendiente_no_excluye_fila(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 15]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'no_aplica',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'pendiente',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(1, $detail['total_rows']);
        $this->assertSame(1, $result['passed']);
    }

    public function test_estado_inactiva_no_afecta_motor(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'inactiva',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_estado_validada_por_estadistica_si_afecta_motor(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'validada por Estadística',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 4. ESTABLISHMENT FILTERING
    // ═════════════════════════════════════════════════════════════════════

    public function test_applies_to_types_cesfam_solo_afecta_cesfam(): void
    {
        $hc = $this->createHealthCenter('CESFAM Norte', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => ['CESFAM'],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Solo CESFAM',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    public function test_applies_to_types_sapu_no_afecta_cesfam(): void
    {
        $hc = $this->createHealthCenter('CESFAM Norte', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => ['SAPU'],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Solo SAPU',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        // SAPU-only rule should NOT apply to CESFAM -> row validated normally, empty -> skip
        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(0, $detail['failed_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_included_health_centers_solo_afecta_ese_establecimiento(): void
    {
        $hc = $this->createHealthCenter('CESFAM Chanavayita', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => ['CESFAM Chanavayita'],
                'excluded_health_centers' => [],
                'functional_condition' => 'Solo Chanavayita',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    public function test_included_health_centers_no_afecta_otro_establecimiento(): void
    {
        $hc = $this->createHealthCenter('CESFAM Los Lagos', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => ['CESFAM Chanavayita'],
                'excluded_health_centers' => [],
                'functional_condition' => 'Solo Chanavayita',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_excluded_health_centers_chanavayita_excluida(): void
    {
        $hc = $this->createHealthCenter('CESFAM Chanavayita', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => ['CESFAM Chanavayita'],
                'functional_condition' => 'Chanavayita excluida',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_excluded_health_centers_chanavayita_no_afecta_otro_cesfam(): void
    {
        $hc = $this->createHealthCenter('CESFAM Los Lagos', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => ['CESFAM Chanavayita'],
                'functional_condition' => 'Chanavayita excluida',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    public function test_upload_sin_establecimiento_no_aplica_filtro_por_establecimiento(): void
    {
        $hc = $this->createHealthCenter('CESFAM Chanavayita', 'CESFAM');
        $hcId = $hc->id;
        $hc->delete();

        $upload = $this->createUpload($hcId);

        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => ['CESFAM'],
                'included_health_centers' => ['CESFAM Chanavayita'],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 7. CONTROLLED TEST: Row 12 with various scenarios
    // ═════════════════════════════════════════════════════════════════════

    public function test_fila_12_sin_decision_funcional_usa_regla_tecnica(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 15]);

        $this->expectFunctionalRules([]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['passed']);
    }

    public function test_fila_12_propuesta_puede_quedar_vacio_no_afecta_motor(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'propuesta',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_fila_12_aprobada_puede_quedar_vacio_fila_vacia_omitida(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Fila 12 puede quedar vacia',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    public function test_fila_12_aprobada_puede_quedar_vacio_con_datos_incorrectos_falla(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => 10, 'B' => 5, 'C' => 25]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Fila 12 puede quedar vacia',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $this->assertSame(1, $result['failed']);
    }

    public function test_fila_12_exclusion_chanavayita_aprobada_solo_chanavayita(): void
    {
        $hc = $this->createHealthCenter('CESFAM Chanavayita', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => ['CESFAM Chanavayita'],
                'functional_condition' => 'Chanavayita excluida de puede_quedar_vacio',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        // For Chanavayita, the rule is excluded -> row validated normally -> empty -> skipped
        $detail = $result['details'][0];
        $this->assertSame('empty_row', $detail['reason']);
    }

    public function test_fila_12_exclusion_chanavayita_otro_cesfam_validacion_normal(): void
    {
        $hc = $this->createHealthCenter('CESFAM Los Lagos', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => ['CESFAM Chanavayita'],
                'functional_condition' => 'Chanavayita excluida de puede_quedar_vacio',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        // For Los Lagos (not Chanavayita), the rule applies -> empty -> skipped by functional rule
        $detail = $result['details'][0];
        $this->assertSame(0, $detail['total_rows']);
        $this->assertSame(1, $detail['skipped_rows']);
        $this->assertSame('skipped', $detail['status']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 8. TRACEABILITY
    // ═════════════════════════════════════════════════════════════════════

    public function test_traceability_incluye_decision_funcional_en_resultado(): void
    {
        $hc = $this->createHealthCenter('CESFAM Prueba', 'CESFAM');
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => 'Fila 12 puede quedar vacia, aprobado por Estadistica',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id);

        $detail = $result['details'][0];
        $this->assertArrayHasKey('functional_decisions', $detail);
        $this->assertArrayHasKey('health_center', $detail);
        $this->assertArrayHasKey('health_center_type', $detail);
        $this->assertSame('CESFAM Prueba', $detail['health_center']);
        $this->assertSame('CESFAM', $detail['health_center_type']);
    }

    public function test_traceability_en_resultado_con_write_incluye_contexto(): void
    {
        $hc = $this->createHealthCenter();
        $upload = $this->createUpload($hc->id);
        $structure = $this->createStructure();
        $rule = $this->createRule();
        $this->bindRule($rule, $structure);

        $this->addRow($upload, 12, ['A' => null, 'B' => null, 'C' => null]);

        $this->expectFunctionalRules([
            12 => [
                'empty_behavior' => 'puede_quedar_vacio',
                'applies_to_types' => [],
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'functional_condition' => '',
                'status' => 'aprobada',
            ],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $this->assertSame(1, $result['total_rules']);
        $detail = $result['details'][0];
        $this->assertArrayHasKey('functional_decisions', $detail);
    }
}
