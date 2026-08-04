<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class ValidateRemUploadFunctionalRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_debe_registrar_cero_warns_for_missing_cell_even_when_row_has_positive_total(): void
    {
        // Decision de negocio confirmada: debe_registrar_cero exige que cada celda
        // editable aplicable quede en 0 explicito, incluso si la fila ya registro
        // otras prestaciones (total > 0). Ya no existe un corte por total positivo.
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 18, ['C' => 20, 'U' => null, 'V' => 20]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                18 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                18 => ['C' => ['formula' => '=SUM(U18:V18)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
            ]),
        );

        $this->assertCount(1, $results);
        $result = $results->first();
        $this->assertSame('f_A01_18', $result['rule_key']);
        $this->assertSame('warning', $result['severity']);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('U18', $result['message']);
        $context = json_decode($result['context'], true);
        $this->assertSame(['U18'], array_column($context['pending_cells'], 'coordinate'));
    }

    public function test_debe_registrar_cero_passes_when_row_has_positive_total_and_all_applicable_cells_filled(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 18, ['C' => 20, 'U' => 5, 'V' => 15]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                18 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                18 => ['C' => ['formula' => '=SUM(U18:V18)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_debe_registrar_cero_passes_when_row_has_positive_total_and_cells_explicitly_zero(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 18, ['C' => 20, 'U' => 0, 'V' => 20]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                18 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                18 => ['C' => ['formula' => '=SUM(U18:V18)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_inherited_debe_registrar_cero_warns_when_equivalent_row_has_editable_empty_cell(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 25, ['C' => 0, 'U' => null, 'V' => 0]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true], 'U' => [], 'V' => []],
                25 => ['C' => ['formula' => '=SUM(U25:V25)', 'blocked' => true], 'U' => [], 'V' => []],
            ]),
        );

        $this->assertCount(1, $results);
        $this->assertSame('f_A01_25', $results->first()['rule_key']);
        $this->assertStringContainsString('U25', $results->first()['message']);
    }

    public function test_debe_registrar_cero_inherits_by_section_scope_when_editability_signature_differs(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 31, ['C' => 0, 'D' => null, 'E' => null, 'F' => null, 'G' => 0, 'U' => 0, 'V' => 0]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => [
                    'C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true, 'formula_cell' => true],
                    'U' => [],
                    'V' => [],
                ],
                31 => [
                    'C' => ['formula' => '=SUM(F31:G31)', 'blocked' => true, 'formula_cell' => true],
                    'D' => ['blocked' => true],
                    'E' => ['blocked' => true],
                    'F' => [],
                    'G' => [],
                    'U' => [],
                    'V' => [],
                ],
            ]),
        );

        $this->assertCount(1, $results);
        $this->assertSame('f_A01_31', $results->first()['rule_key']);
        $this->assertStringContainsString('F31', $results->first()['message']);
    }

    public function test_section_scope_inheritance_does_not_leak_to_other_rem_sections_in_same_sheet(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 39, ['C' => 0, 'D' => null, 'E' => null], ['rem_section_code' => 'B']);

        $functionalRuleService = Mockery::mock(FunctionalRuleService::class);
        $functionalRuleService->shouldReceive('getFunctionalRulesForEngine')
            ->with('A01', 'A')
            ->once()
            ->andReturn([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]);
        $functionalRuleService->shouldReceive('getFunctionalRulesForEngine')
            ->with('A01', 'B')
            ->once()
            ->andReturn([]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $functionalRuleService,
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
                39 => ['C' => ['formula' => '=SUM(D39:E39)', 'blocked' => true, 'formula_cell' => true], 'D' => [], 'E' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_reviewed_pattern_question_generates_grouped_warning_for_a01_b_row_39(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 38, ['C' => 0, 'D' => 0, 'E' => 0, 'F' => null], ['rem_section_code' => 'B']);
        $this->createRemDataRow($upload, 39, [
            'C' => 0,
            'D' => null,
            'E' => null,
            'F' => null,
            'N' => null,
            'S' => null,
            'AJ' => null,
            'AN' => null,
        ], ['rem_section_code' => 'B']);
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'pattern_3_empty',
                        'type' => 'pattern_question',
                        'response' => 'debe_registrar_cero',
                        'pattern_id' => 3,
                        'pattern_key' => 'pattern_3',
                        'review_status' => 'reviewed',
                        'reviewed_by' => 'Administrador Esalud',
                        'reviewed_at' => '2026-07-22T13:01:37.282Z',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'pattern_3_exceptions',
                        'type' => 'pattern_question',
                        'response' => 'no',
                        'pattern_id' => 3,
                        'pattern_key' => 'pattern_3',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'pattern_3_severity',
                        'type' => 'pattern_question',
                        'response' => 'advertencia',
                        'pattern_id' => 3,
                        'pattern_key' => 'pattern_3',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                ],
            ],
        ]));

        $matrixService = Mockery::mock(SectionCalibrationMatrixService::class);
        $matrixService->shouldReceive('getPatternsForValidation')
            ->with('A01', 'B')
            ->once()
            ->andReturn([
                [
                    'id' => 3,
                    'key' => 'pattern_3',
                    'rows' => [
                        ['fila' => 39, 'row_type' => 'data'],
                    ],
                ],
            ]);
        $matrixService->shouldReceive('forgetSectionCache')
            ->with('A01', 'B')
            ->once();

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            new FunctionalRuleService(),
            $this->cellData([
                38 => ['C' => ['formula' => '=SUM(D38:E38)', 'blocked' => true, 'formula_cell' => true], 'D' => [], 'E' => [], 'F' => ['blocked' => true]],
                39 => [
                    'C' => ['formula' => '=SUM(D39:E39)', 'blocked' => true, 'formula_cell' => true],
                    'D' => [],
                    'E' => [],
                    'F' => ['blocked' => true],
                    'N' => [],
                    'S' => [],
                    'AJ' => [],
                    'AN' => [],
                ],
            ]),
            $matrixService,
        );

        $this->assertCount(1, $results);
        $result = $results->first();
        $context = json_decode($result['context'], true);

        $this->assertSame('f_A01_39', $result['rule_key']);
        $this->assertSame('warning', $result['severity']);
        $this->assertSame(6, $context['pending_cells_count']);
        $this->assertSame(['D39', 'E39', 'N39', 'S39', 'AJ39', 'AN39'], array_column($context['pending_cells'], 'coordinate'));
        $this->assertStringNotContainsString('F39', $result['message']);
    }

    public function test_unreviewed_pattern_question_is_not_inherited(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 39, ['C' => 0, 'D' => null, 'E' => null], ['rem_section_code' => 'B']);
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [[
                    'id' => 'pattern_3_empty',
                    'type' => 'pattern_question',
                    'response' => 'debe_registrar_cero',
                    'pattern_id' => 3,
                    'pattern_key' => 'pattern_3',
                    'review_status' => 'pending',
                    'status' => 'pending',
                ]],
            ],
        ]));

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            new FunctionalRuleService(),
            $this->cellData([
                39 => ['C' => ['formula' => '=SUM(D39:E39)', 'blocked' => true, 'formula_cell' => true], 'D' => [], 'E' => []],
            ]),
            $this->patternMatrixForBRow39(),
        );

        $this->assertCount(0, $results);
    }

    public function test_pattern_question_with_exceptions_is_not_inherited_automatically(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 39, ['C' => 0, 'D' => null, 'E' => null], ['rem_section_code' => 'B']);
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'pattern_3_empty',
                        'type' => 'pattern_question',
                        'response' => 'debe_registrar_cero',
                        'pattern_id' => 3,
                        'pattern_key' => 'pattern_3',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'pattern_3_exceptions',
                        'type' => 'pattern_question',
                        'response' => 'si',
                        'pattern_id' => 3,
                        'pattern_key' => 'pattern_3',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                ],
            ],
        ]));

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            new FunctionalRuleService(),
            $this->cellData([
                39 => ['C' => ['formula' => '=SUM(D39:E39)', 'blocked' => true, 'formula_cell' => true], 'D' => [], 'E' => []],
            ]),
            $this->patternMatrixForBRow39(),
        );

        $this->assertCount(0, $results);
    }

    public function test_explicit_row_decision_prevails_over_reviewed_pattern_question(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 39, ['C' => 0, 'D' => null, 'E' => null], ['rem_section_code' => 'B']);
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            'A01_B_39' => [
                'sheet' => 'A01',
                'section' => 'B',
                'row' => 39,
                'empty_behavior' => 'puede_quedar_vacio',
                'status' => 'aprobada',
            ],
            '_questions' => [
                'A01_B' => [[
                    'id' => 'pattern_3_empty',
                    'type' => 'pattern_question',
                    'response' => 'debe_registrar_cero',
                    'pattern_id' => 3,
                    'pattern_key' => 'pattern_3',
                    'review_status' => 'reviewed',
                    'status' => 'answered',
                ]],
            ],
        ]));

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            new FunctionalRuleService(),
            $this->cellData([
                39 => ['C' => ['formula' => '=SUM(D39:E39)', 'blocked' => true, 'formula_cell' => true], 'D' => [], 'E' => []],
            ]),
            $this->patternMatrixForBRow39(),
        );

        $this->assertCount(0, $results);
    }


    public function test_inherited_debe_registrar_cero_passes_when_equivalent_empty_row_uses_zero(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 25, ['C' => 0, 'U' => 0, 'V' => 0]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true], 'U' => [], 'V' => []],
                25 => ['C' => ['formula' => '=SUM(U25:V25)', 'blocked' => true], 'U' => [], 'V' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_inherited_debe_registrar_cero_ignores_blocked_empty_cells(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => null, 'V' => 0]);
        $this->createRemDataRow($upload, 25, ['C' => 0, 'U' => null, 'V' => 0]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true], 'U' => ['blocked' => true], 'V' => []],
                25 => ['C' => ['formula' => '=SUM(U25:V25)', 'blocked' => true], 'U' => ['blocked' => true], 'V' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_explicit_row_decision_prevails_over_inherited_pattern_decision(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 25, ['C' => 0, 'U' => null, 'V' => 0]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
                25 => ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true], 'U' => [], 'V' => []],
                25 => ['C' => ['formula' => '=SUM(U25:V25)', 'blocked' => true], 'U' => [], 'V' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_inherited_debe_registrar_cero_warns_for_missing_cell_even_when_row_has_positive_total(): void
    {
        // Caso real A01/B fila 37: la fila hereda debe_registrar_cero de fila 23 y
        // tiene total > 0 (34), pero F31/G31 quedan en blanco -> debe advertir.
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 31, ['C' => 1, 'F' => null, 'G' => 1]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
                31 => ['C' => ['formula' => '=SUM(F31:G31)', 'blocked' => true, 'formula_cell' => true], 'F' => [], 'G' => []],
            ]),
        );

        $this->assertCount(1, $results);
        $result = $results->first();
        $this->assertSame('f_A01_31', $result['rule_key']);
        $this->assertSame('warning', $result['severity']);
        $this->assertStringContainsString('F31', $result['message']);
        $context = json_decode($result['context'], true);
        $this->assertSame(['F31'], array_column($context['pending_cells'], 'coordinate'));
    }

    public function test_special_row_does_not_inherit_debe_registrar_cero(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 31, ['C' => 0, 'F' => null], ['type' => 'special']);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
                31 => ['C' => ['blocked' => true], 'F' => []],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_row_without_real_editable_cells_does_not_inherit_debe_registrar_cero(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 23, ['C' => 0, 'U' => 0, 'V' => 0]);
        $this->createRemDataRow($upload, 31, ['C' => 0, 'F' => null]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                23 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                23 => ['C' => ['formula' => '=SUM(U23:V23)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
                31 => ['C' => ['blocked' => true], 'F' => ['blocked' => true]],
            ]),
        );

        $this->assertCount(0, $results);
    }

    public function test_puede_quedar_vacio_empty_row_generates_passed_traceable_result(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 47, ['C' => null, 'D' => null, 'E' => null]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => [
                    'empty_behavior' => 'puede_quedar_vacio',
                    'status' => 'aprobada',
                    'functional_condition' => 'Puede quedar vacia segun criterio de Estadistica',
                    'updated_by' => 'Administrador Esalud',
                    'updated_at' => '2026-07-24T14:55:57+00:00',
                ],
            ]),
            new CellDataStorageService(),
        );

        $this->assertCount(1, $results);
        $result = $results->first();
        $this->assertTrue($result['passed']);
        $this->assertSame('functional_rule', $result['rule_type']);
        $this->assertSame('info', $result['severity']);
        $this->assertSame('f_A01_47', $result['rule_key']);

        $context = json_decode($result['context'], true);
        $this->assertSame('A01', $context['sheet']);
        $this->assertSame('A', $context['rem_section_code']);
        $this->assertSame(47, $context['row_number']);
        $this->assertSame('puede_quedar_vacio', $context['empty_behavior']);
        $this->assertSame('fila', $context['origen_decision']);
        $this->assertSame('Administrador Esalud', $context['reviewed_by']);
        $this->assertSame('2026-07-24T14:55:57+00:00', $context['reviewed_at']);
    }

    public function test_puede_quedar_vacio_row_with_data_generates_no_functional_result(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 47, ['C' => 5, 'D' => 3, 'E' => 2]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'],
            ]),
            new CellDataStorageService(),
        );

        $this->assertCount(0, $results);
    }

    public function test_puede_quedar_vacio_partially_empty_row_does_not_generate_functional_warning(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 47, ['C' => 5, 'D' => null, 'E' => null]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'],
            ]),
            new CellDataStorageService(),
        );

        $this->assertCount(0, $results);
    }

    public function test_changing_decision_from_puede_quedar_vacio_to_debe_registrar_cero_generates_warning_on_next_validation(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 47, ['C' => null, 'D' => null, 'E' => null]);

        // Primera validacion: decision vigente es puede_quedar_vacio -> passed, sin advertencia.
        $firstRun = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'],
            ]),
            new CellDataStorageService(),
        );
        $this->assertCount(1, $firstRun);
        $this->assertTrue($firstRun->first()['passed']);

        // Estadistica cambia la decision a debe_registrar_cero -> la siguiente validacion
        // (proxima corrida / reproceso) debe advertir, porque el calibrador es la fuente
        // de verdad y el motor no debe seguir aplicando la decision anterior.
        $secondRun = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => ['empty_behavior' => 'debe_registrar_cero', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                47 => ['C' => [], 'D' => [], 'E' => []],
            ]),
        );
        $this->assertCount(1, $secondRun);
        $this->assertFalse($secondRun->first()['passed']);
        $this->assertSame('error', $secondRun->first()['severity']);
    }

    public function test_puede_quedar_vacio_ignores_cell_blocked_state_when_row_is_empty(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 47, ['C' => null, 'D' => null, 'E' => null]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'],
            ]),
            $this->cellData([
                47 => ['C' => ['blocked' => true], 'D' => ['blocked' => true], 'E' => ['blocked' => true]],
            ]),
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()['passed']);
    }

    public function test_no_aplica_still_generates_no_result(): void
    {
        $upload = $this->createUpload();
        $this->createRemDataRow($upload, 47, ['C' => null, 'D' => null, 'E' => null]);

        $results = $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $this->functionalRules([
                47 => ['empty_behavior' => 'no_aplica', 'status' => 'aprobada'],
            ]),
            new CellDataStorageService(),
        );

        $this->assertCount(0, $results);
    }

    public function test_functional_rule_result_with_severity_info_and_passed_true_persists_correctly(): void
    {
        $upload = $this->createUpload();

        $record = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_A01_47',
            'rule_type' => 'functional_rule',
            'severity' => 'info',
            'passed' => true,
            'message' => 'La fila 47 quedó vacía y fue aceptada según decisión funcional.',
            'context' => [
                'sheet' => 'A01',
                'rem_section_code' => 'C',
                'row_number' => 47,
                'empty_behavior' => 'puede_quedar_vacio',
            ],
        ]);

        $record->refresh();

        $this->assertSame('info', $record->severity);
        $this->assertTrue($record->passed);
        $this->assertSame('functional_rule', $record->rule_type);
        $this->assertDatabaseHas('rem_validation_results', [
            'id' => $record->id,
            'rem_upload_id' => $upload->id,
            'severity' => 'info',
            'passed' => true,
        ]);
    }

    public function test_severity_error_and_warning_still_persist_correctly(): void
    {
        $upload = $this->createUpload();

        $error = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_A01_11',
            'rule_type' => 'functional_rule',
            'severity' => 'error',
            'passed' => false,
            'message' => 'Fila debe registrar 0.',
            'context' => ['row_number' => 11],
        ]);

        $warning = RemValidationResult::create([
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_A01_39',
            'rule_type' => 'functional_rule',
            'severity' => 'warning',
            'passed' => false,
            'message' => 'Fila con celdas pendientes.',
            'context' => ['row_number' => 39],
        ]);

        $this->assertDatabaseHas('rem_validation_results', ['id' => $error->id, 'severity' => 'error', 'passed' => false]);
        $this->assertDatabaseHas('rem_validation_results', ['id' => $warning->id, 'severity' => 'warning', 'passed' => false]);
    }

    private function invokeEvaluateFunctionalRules(
        ValidateRemUploadJob $job,
        RemUpload $upload,
        FunctionalRuleService $functionalRuleService,
        CellDataStorageService $cellDataStorage,
        ?SectionCalibrationMatrixService $matrixService = null,
    ) {
        $method = (new ReflectionClass($job))->getMethod('evaluateFunctionalRules');
        $method->setAccessible(true);

        return $method->invoke($job, $upload, $functionalRuleService, $cellDataStorage, $matrixService);
    }

    private function createUpload(): RemUpload
    {
        $center = HealthCenter::create([
            'name' => 'CESFAM Prueba',
            'code_deis' => 'TEST01',
            'type' => 'CESFAM',
            'commune' => 'Iquique',
            'is_active' => true,
        ]);

        return RemUpload::create([
            'health_center_id' => $center->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 1,
            'rem_type' => 'A',
            'original_filename' => 'a01.xlsm',
            'stored_path' => 'test/a01.xlsm',
            'file_size' => 1,
            'mime_type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'status' => 'processed',
        ]);
    }

    private function createRemDataRow(RemUpload $upload, int $row, array $values, array $extraData = []): RemData
    {
        return RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'A01',
            'data' => array_merge([
                'concept' => 'Fila ' . $row,
                'professional' => 'Matrona/on',
                'total' => $values['C'] ?? null,
                'values' => $values,
                'row_number' => $row,
                'section' => 'A01',
                'rem_section_code' => 'A',
                'total_column' => 'C',
            ], $extraData),
        ]);
    }

    private function functionalRules(array $rules): FunctionalRuleService
    {
        $service = Mockery::mock(FunctionalRuleService::class);
        $service->shouldReceive('getFunctionalRulesForEngine')
            ->with('A01', 'A')
            ->once()
            ->andReturn($rules);

        return $service;
    }

    private function patternMatrixForBRow39(): SectionCalibrationMatrixService
    {
        $matrixService = Mockery::mock(SectionCalibrationMatrixService::class);
        $matrixService->shouldReceive('getPatternsForValidation')
            ->with('A01', 'B')
            ->once()
            ->andReturn([
                [
                    'id' => 3,
                    'key' => 'pattern_3',
                    'rows' => [
                        ['fila' => 39, 'row_type' => 'data'],
                    ],
                ],
            ]);
        $matrixService->shouldReceive('forgetSectionCache')
            ->with('A01', 'B')
            ->once();

        return $matrixService;
    }

    private function cellData(array $rows): CellDataStorageService
    {
        return new class($rows) extends CellDataStorageService {
            public function __construct(private array $rows)
            {
            }

            public function getCellForCoordinate(string $sheet, string $section, string $coordinate): ?array
            {
                if (!preg_match('/^([A-Z]+)(\d+)$/', $coordinate, $matches)) {
                    return null;
                }

                return $this->makeCell((int) $matches[2], $matches[1]);
            }

            public function getCellsForRow(string $sheet, string $section, int $row): array
            {
                $cells = [];
                foreach (array_keys($this->rows[$row] ?? []) as $column) {
                    $cells[$column . $row] = $this->makeCell($row, $column);
                }

                return $cells;
            }

            private function makeCell(int $row, string $column): ?array
            {
                if (!array_key_exists($row, $this->rows) || !array_key_exists($column, $this->rows[$row])) {
                    return null;
                }

                $definition = $this->rows[$row][$column];
                $blocked = (bool) ($definition['blocked'] ?? false);

                return [
                    'fila' => $row,
                    'columna' => $column,
                    'coordenada' => $column . $row,
                    'formula' => $definition['formula'] ?? null,
                    'es_formula' => (bool) ($definition['formula_cell'] ?? false),
                    'es_editable' => !$blocked,
                    'esta_bloqueada' => $blocked,
                ];
            }
        };
    }
}
