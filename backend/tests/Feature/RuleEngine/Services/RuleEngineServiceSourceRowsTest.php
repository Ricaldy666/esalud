<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemValidationResult;
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

/**
 * Fase 3C-3A/3C-3B (CLAUDE.md punto 17.21/17.22). Cubre el soporte de
 * 'source_rows' en la CAPA DE PREFILTRO de RuleEngineService::execute() --
 * complementa SumEqualsEvaluatorSourceRowsTest.php (que cubre el evaluador
 * de forma aislada). Aqui se prueba especificamente:
 *  - que el prefiltro deja pasar filas referenciadas por source_rows aunque
 *    caigan FUERA de [row_from:row_to] (patron B4, fila 50);
 *  - que '_section_bounds' se resuelve contra la estructura real y se
 *    inyecta en config para que el evaluador pueda aplicar el guard de
 *    limites;
 *  - que source_rows nunca afecta reglas horizontales ni reglas vecinas sin
 *    source_rows, incluso ejecutando juntas en el mismo lote real.
 *
 * NINGUNA de las 12 reglas reales (208,214,393-402) se toca -- fixtures
 * 100% sinteticos, replicando los patrones reales ya auditados.
 */
class RuleEngineServiceSourceRowsTest extends TestCase
{
    use RefreshDatabase;

    private RuleEngineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $functionalMock = $this->createMock(FunctionalRuleService::class);
        $functionalMock->method('getFunctionalRulesForEngine')->willReturn([]);

        $this->service = new RuleEngineService($functionalMock);
        $this->service->registerEvaluator(new SumEqualsEvaluator);
    }

    private function createUpload(): RemUpload
    {
        return RemUpload::create([
            'rem_type' => 'A',
            'year' => 2098,
            'month' => 1,
            'status' => 'pending',
            'health_center_id' => HealthCenter::create([
                'name' => 'Centro Source Rows',
                'code_deis' => 'PSR' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'source_rows.xlsx',
            'stored_path' => 'rem/2098/01/source_rows.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Estructura CON secciones reales (filaInicioDatos/filaFinDatos) -- necesaria para el guard de '_section_bounds'. */
    private function createStructure(array $sections = []): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2098,
            'version_number' => 1,
            'estructura' => ['forms' => $sections],
            'hash_estructura' => 'hash_source_rows_' . uniqid(),
            'status' => 'active',
        ]);
    }

    private function sectionDef(string $codigo, int $inicio, int $fin): array
    {
        return ['codigo' => $codigo, 'titulo' => $codigo, 'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin, 'fields' => []];
    }

    private function createVerticalRule(string $sheet, string $section, string $column, int $rowFrom, int $rowTo, ?int $totalRow, ?array $sourceRows, string $key): Rule
    {
        $config = [
            'sheet' => $sheet,
            'section' => $section,
            'column' => $column,
            'row_range' => ['from' => $rowFrom, 'to' => $rowTo],
            'rule_logic' => "Suma({$column}) = Columna {$column}",
        ];
        if ($totalRow !== null) {
            $config['total_row'] = $totalRow;
        }
        if ($sourceRows !== null) {
            $config['source_rows'] = $sourceRows;
        }

        return Rule::create([
            'rule_key' => $key,
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => $key,
            'description' => 'Fase 3C-3A/3C-3B',
            'severity' => 'error',
            'scope' => 'row_range',
            'config' => $config,
            'status' => 'active',
            'version' => '1.0.0',
            'metadata' => ['sheet' => $sheet],
        ]);
    }

    private function bind(Rule $rule, RemTemplateStructure $structure): void
    {
        RuleBinding::create([
            'rule_id' => $rule->id,
            'bindable_type' => 'structure',
            'bindable_id' => $structure->id,
            'serie' => 'A',
            'anio' => 2098,
            'active' => true,
        ]);
    }

    private function seedRemData(RemUpload $upload, string $sheet, string $section, int $row, string $column, int $value): void
    {
        RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => $sheet,
            'data' => [
                'row_number' => $row,
                'concept' => "Concepto {$row}",
                'total' => null,
                'values' => [$column => $value],
                'rem_section_code' => $section,
            ],
        ]);
    }

    // ── B4: el prefiltro debe cargar la fila externa (50) pese a estar fuera de [54:58] ──

    public function test_b4_prefilter_loads_external_row_outside_row_range(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSR26', 'sections' => [$this->sectionDef('B', 50, 60)]],
        ]);
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('PSR26', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 50], 'psr_b4_pass');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'PSR26', 'B', 50, 'D', 3); // termino externo, FUERA de [54:58]
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSR26', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSR26', 'B', 59, 'D', 3); // TOTAL real = 3 (solo la fila 50 aporta)

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'psr_b4_pass');
        $this->assertSame('passed', $detail['status'], 'debe pasar: sin el prefiltro extendido, la fila 50 nunca llegaria al evaluador y la suma seria 0 != 3');
        $this->assertSame(0, $detail['failed_rows']);
    }

    public function test_b4_prefilter_detects_mismatch_when_external_row_altered(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSR26', 'sections' => [$this->sectionDef('B', 50, 60)]],
        ]);
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('PSR26', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 50], 'psr_b4_fail');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'PSR26', 'B', 50, 'D', 3);
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSR26', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSR26', 'B', 59, 'D', 999); // TOTAL declarado incorrecto

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'psr_b4_fail');
        $this->assertSame('failed', $detail['status']);
        $this->assertSame(1, $detail['failed_rows']);

        $validation = RemValidationResult::where('rule_key', 'psr_b4_fail')->firstOrFail();
        $this->assertEquals(3, $validation->context['details'][0]['calculated_sum']);
        $this->assertEquals(999, $validation->context['details'][0]['declared_value']);
    }

    public function test_without_source_rows_external_row_50_would_never_reach_evaluator(): void
    {
        // Prueba de control: la MISMA regla, sin source_rows -- confirma
        // que sin el mecanismo nuevo, la fila 50 queda fuera del prefiltro
        // (comportamiento legacy) y el motor "ve" la suma como 0 (solo
        // 54-58), fallando contra el TOTAL real (3).
        $structure = $this->createStructure([
            ['sheetName' => 'PSR26', 'sections' => [$this->sectionDef('B', 50, 60)]],
        ]);
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('PSR26', 'B', 'D', 54, 58, 59, null, 'psr_b4_legacy');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'PSR26', 'B', 50, 'D', 3);
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSR26', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSR26', 'B', 59, 'D', 3);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'psr_b4_legacy');
        $this->assertSame('failed', $detail['status'], 'sin source_rows, el prefiltro legacy descarta la fila 50 y la suma calculada (0) no coincide con el TOTAL real (3)');
    }

    // ── Guard de limites: '_section_bounds' resuelto desde la estructura real ──

    public function test_source_row_outside_live_structure_bounds_rejected(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSR26', 'sections' => [$this->sectionDef('B', 50, 60)]],
        ]);
        $upload = $this->createUpload();
        // 999 esta fuera de la seccion viva [50:60].
        $rule = $this->createVerticalRule('PSR26', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 999], 'psr_b4_out_of_bounds');
        $this->bind($rule, $structure);

        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSR26', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSR26', 'B', 59, 'D', 0);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'psr_b4_out_of_bounds');
        $this->assertSame('skipped', $detail['status']);
        $this->assertSame('invalid_source_rows_configuration', $detail['reason']);
    }

    public function test_section_bounds_not_resolvable_when_section_missing_from_structure(): void
    {
        // Estructura activa SIN la seccion de la regla -- findSectionBounds()
        // debe devolver null, y el guard de limites simplemente no se aplica
        // (el resto de guards de source_rows si).
        $structure = $this->createStructure([]); // sin secciones
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('PSR26', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 50], 'psr_no_bounds');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'PSR26', 'B', 50, 'D', 3);
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSR26', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSR26', 'B', 59, 'D', 3);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'psr_no_bounds');
        $this->assertSame('passed', $detail['status'], 'sin bounds resolubles, el guard de limites no bloquea -- el resto de guards (array/enteros/duplicados) siguen aplicando');
    }

    // ── B1 -- pipeline completo (huecos con valores no-triviales) ──

    public function test_b1_full_pipeline_ignores_gaps_within_row_range(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSR09F1', 'sections' => [$this->sectionDef('F1', 146, 158)]],
        ]);
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('PSR09F1', 'F1', 'F', 149, 157, 158, [149, 150, 153, 155, 157], 'psr_b1_pass');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'PSR09F1', 'F1', 149, 'F', 4);
        $this->seedRemData($upload, 'PSR09F1', 'F1', 150, 'F', 9);
        $this->seedRemData($upload, 'PSR09F1', 'F1', 151, 'F', 100); // hueco, NO debe sumarse
        $this->seedRemData($upload, 'PSR09F1', 'F1', 152, 'F', 200); // hueco, NO debe sumarse
        $this->seedRemData($upload, 'PSR09F1', 'F1', 153, 'F', 12);
        $this->seedRemData($upload, 'PSR09F1', 'F1', 154, 'F', 300); // hueco, NO debe sumarse
        $this->seedRemData($upload, 'PSR09F1', 'F1', 155, 'F', 11);
        $this->seedRemData($upload, 'PSR09F1', 'F1', 156, 'F', 400); // hueco, NO debe sumarse
        $this->seedRemData($upload, 'PSR09F1', 'F1', 157, 'F', 7);
        $this->seedRemData($upload, 'PSR09F1', 'F1', 158, 'F', 43); // TOTAL real = 4+9+12+11+7

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'psr_b1_pass');
        $this->assertSame('passed', $detail['status']);
    }

    // ── Reglas horizontales y vecinas, mismo lote real ────────────────

    public function test_horizontal_rule_unaffected_by_source_rows_in_neighbor_vertical_rule(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSRSHARED', 'sections' => [$this->sectionDef('B', 50, 60), $this->sectionDef('H', 5, 5)]],
        ]);
        $upload = $this->createUpload();

        $verticalRule = $this->createVerticalRule('PSRSHARED', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 50], 'psr_vertical');
        $this->bind($verticalRule, $structure);

        $horizontalRule = Rule::create([
            'rule_key' => 'psr_horizontal',
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => 'psr_horizontal',
            'description' => 'control horizontal',
            'severity' => 'error',
            'scope' => 'per_row',
            'config' => [
                'sheet' => 'PSRSHARED', 'section' => 'H',
                'source_letters' => ['A', 'B'], 'target_column' => 'C',
                'row_range' => ['from' => 5, 'to' => 5],
                'rule_logic' => 'Suma(A + B) = Columna C',
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        $this->bind($horizontalRule, $structure);

        $this->seedRemData($upload, 'PSRSHARED', 'B', 50, 'D', 3);
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSRSHARED', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSRSHARED', 'B', 59, 'D', 3);

        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'PSRSHARED',
            'data' => ['row_number' => 5, 'concept' => 'fila horizontal', 'total' => null, 'values' => ['A' => 5, 'B' => 3, 'C' => 8], 'rem_section_code' => 'H'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detailVertical = collect($result['details'])->firstWhere('rule_key', 'psr_vertical');
        $detailHorizontal = collect($result['details'])->firstWhere('rule_key', 'psr_horizontal');

        $this->assertSame('passed', $detailVertical['status']);
        $this->assertSame('passed', $detailHorizontal['status'], 'la regla horizontal no debe verse afectada por source_rows de la regla vertical vecina');
        $this->assertSame(0, $detailHorizontal['failed_rows']);
    }

    public function test_neighbor_vertical_rule_without_source_rows_unaffected(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSRSHARED2', 'sections' => [$this->sectionDef('B', 50, 60), $this->sectionDef('V2', 20, 22)]],
        ]);
        $upload = $this->createUpload();

        $ruleWithSourceRows = $this->createVerticalRule('PSRSHARED2', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 50], 'psr_con_source_rows');
        $ruleWithout = $this->createVerticalRule('PSRSHARED2', 'V2', 'C', 20, 21, 22, null, 'psr_sin_source_rows');
        $this->bind($ruleWithSourceRows, $structure);
        $this->bind($ruleWithout, $structure);

        $this->seedRemData($upload, 'PSRSHARED2', 'B', 50, 'D', 3);
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSRSHARED2', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSRSHARED2', 'B', 59, 'D', 3);

        $this->seedRemData($upload, 'PSRSHARED2', 'V2', 20, 'C', 3);
        $this->seedRemData($upload, 'PSRSHARED2', 'V2', 21, 'C', 6);
        $this->seedRemData($upload, 'PSRSHARED2', 'V2', 22, 'C', 9);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detailA = collect($result['details'])->firstWhere('rule_key', 'psr_con_source_rows');
        $detailB = collect($result['details'])->firstWhere('rule_key', 'psr_sin_source_rows');

        $this->assertSame('passed', $detailA['status']);
        $this->assertSame('passed', $detailB['status']);
        $this->assertSame(3, $detailB['total_rows'], 'la regla vecina sin source_rows debe contar exactamente sus 3 filas propias (20,21,22), sin contaminacion de la fila 50 de la otra regla');
    }

    public function test_historical_rem_data_never_modified_by_source_rows_evaluation(): void
    {
        $structure = $this->createStructure([
            ['sheetName' => 'PSR26', 'sections' => [$this->sectionDef('B', 50, 60)]],
        ]);
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('PSR26', 'B', 'D', 54, 58, 59, [54, 55, 56, 57, 58, 50], 'psr_no_mutation');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'PSR26', 'B', 50, 'D', 3);
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData($upload, 'PSR26', 'B', $r, 'D', 0);
        }
        $this->seedRemData($upload, 'PSR26', 'B', 59, 'D', 3);

        $before = RemData::where('rem_upload_id', $upload->id)->orderBy('id')->get()->toArray();

        $this->service->execute($upload->id, $structure->id, write: true);

        $after = RemData::where('rem_upload_id', $upload->id)->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'rem_data debe permanecer byte-identica antes/despues de execute()');
    }
}
