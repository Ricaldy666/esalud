<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\RequiredAndLeParentEvaluator;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la Alternativa A de la Fase C (2026-08-11): el prefiltro
 * row_from/row_to de RuleEngineService::execute() excluia SIEMPRE la fila
 * total_row (convencion row_to+1, fuera de [row_from:row_to] por diseno)
 * antes de que SumEqualsEvaluator pudiera verla -- confirmado empiricamente
 * contra datos reales (upload 99, A06/A.2, fila 32 con TOTAL=2 presente en
 * rem_data, 64/64 ejecuciones historicas siempre skipped). El fix agrega
 * total_row como excepcion explicita al filtro, solo cuando el config lo
 * declara -- confirmado que las 135 reglas con total_row en produccion son
 * TODAS de patron vertical, ninguna horizontal lo usa.
 *
 * Resuelve SOLO la Causa C (config correcto, fila existe, prefiltro la
 * descarta). Deliberadamente NO resuelve la Causa D (fila TOTAL/subtotal
 * excluida a proposito de rem_data por los mecanismos #6/#8/#12,
 * deuda tecnica #5) -- esos casos deben seguir 'skipped'.
 */
class RuleEngineServiceTotalRowFilterTest extends TestCase
{
    use RefreshDatabase;

    private RuleEngineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $functionalRuleMock = $this->createMock(FunctionalRuleService::class);
        $functionalRuleMock->method('getFunctionalRulesForEngine')->willReturn([]);

        $this->service = new RuleEngineService($functionalRuleMock);
        $this->service->registerEvaluator(new SumEqualsEvaluator);
        $this->service->registerEvaluator(new RequiredAndLeParentEvaluator);
    }

    private function createUpload(): RemUpload
    {
        $healthCenterId = HealthCenter::create([
            'name' => 'Test Center', 'code_deis' => 'TC001', 'type' => 'CESFAM',
        ])->id;
        $userId = User::factory()->create()->id;

        return RemUpload::create([
            'rem_type' => 'A', 'year' => 2026, 'month' => 7, 'status' => 'pending',
            'health_center_id' => $healthCenterId, 'user_id' => $userId,
            'original_filename' => 'test.xlsx', 'stored_path' => 'rem/2026/07/test.xlsx',
            'file_size' => 1234, 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function createStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 1,
            'estructura' => ['forms' => []], 'hash_estructura' => 'hash-' . uniqid(), 'status' => 'active',
        ]);
    }

    /** Regla vertical: Suma(F) filas [row_from:row_to] = Columna F fila total_row. */
    private function createVerticalRule(RemTemplateStructure $structure, int $rowFrom, int $rowTo, int $totalRow, string $ruleKey = 'test_vertical'): Rule
    {
        $rule = Rule::create([
            'rule_key' => $ruleKey, 'rule_type' => 'sum_equals', 'source' => 'excel_formula',
            'name' => 'Test vertical', 'description' => 'test', 'severity' => 'error', 'scope' => 'row_range',
            'config' => [
                'sheet' => 'A06', 'section' => 'A.1', 'column' => 'F',
                'source_letters' => ['F'], 'target_column' => 'F',
                'row_range' => ['from' => $rowFrom, 'to' => $rowTo],
                'row_from' => $rowFrom, 'row_to' => $rowTo, 'scope' => 'row_range',
                'total_row' => $totalRow, 'rule_logic' => 'Suma(F) = Columna F',
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);

        RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structure->id,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);

        return $rule;
    }

    private function seedRow(RemUpload $upload, int $rowNumber, ?float $value, string $concept = 'Fila'): void
    {
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['F' => $value], 'row_number' => $rowNumber, 'concept' => $concept],
        ]);
    }

    // ── 1. Caso vertical valido: total_row correcto presente -> passed ──
    public function test_vertical_rule_with_correct_total_row_present_passes(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $this->createVerticalRule($structure, rowFrom: 12, rowTo: 13, totalRow: 14);

        $this->seedRow($upload, 12, 3);
        $this->seedRow($upload, 13, 4);
        $this->seedRow($upload, 14, 7, 'TOTAL'); // 3+4=7, correcto

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['passed'], 'debe pasar: la suma de componentes coincide con la fila total');
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
    }

    // ── 2. Caso vertical inconsistente: total_row presente pero suma incorrecta -> failed, NO skipped ──
    public function test_vertical_rule_with_wrong_total_row_value_fails_not_skipped(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $this->createVerticalRule($structure, rowFrom: 12, rowTo: 13, totalRow: 14);

        $this->seedRow($upload, 12, 3);
        $this->seedRow($upload, 13, 4);
        $this->seedRow($upload, 14, 99, 'TOTAL'); // deberia ser 7, no 99

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['passed']);
        $this->assertSame(1, $result['failed'], 'debe fallar (no quedar skipped) cuando la fila total existe pero el valor es incorrecto');
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('failed', $result['details'][0]['status']);
        $this->assertSame('failed', $result['details'][0]['reason']);
    }

    // ── 3. Caso missing real: total_row configurado pero la fila NO existe -> sigue skipped/missing_total_row ──
    public function test_vertical_rule_with_genuinely_absent_total_row_stays_skipped(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $this->createVerticalRule($structure, rowFrom: 12, rowTo: 13, totalRow: 14);

        $this->seedRow($upload, 12, 3);
        $this->seedRow($upload, 13, 4);
        // fila 14 NUNCA se persiste -- simula Causa D (mecanismo #6/#8/#12) o dato genuinamente ausente.

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['skipped'], 'debe seguir skipped -- el fix no debe inventar una fila que no existe');
        $this->assertSame('missing_total_row', $result['details'][0]['reason']);
    }

    // ── 4. Regla horizontal sana (sin total_row) -> comportamiento identico al actual ──
    public function test_horizontal_rule_without_total_row_is_unaffected(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = Rule::create([
            'rule_key' => 'test_horizontal', 'rule_type' => 'sum_equals', 'source' => 'excel_formula',
            'name' => 'Test horizontal', 'description' => 'test', 'severity' => 'error', 'scope' => 'per_row',
            'config' => [
                'sheet' => 'A06', 'section' => 'G', 'column' => 'B',
                'source_letters' => ['C', 'D'], 'target_column' => 'B',
                'row_range' => ['from' => 5, 'to' => 5], 'row_from' => 5, 'row_to' => 5, 'scope' => 'per_row',
                'rule_logic' => 'Suma(C + D) = Columna B',
                // sin total_row -- las reglas horizontales nunca lo tienen.
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structure->id,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['C' => 2, 'D' => 3, 'B' => 5], 'row_number' => 5, 'concept' => 'Fila 5'],
        ]);
        // Filas fuera de [5:5] -- no deben colarse ahora que existe la excepcion de total_row.
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['C' => 100, 'D' => 100, 'B' => 1], 'row_number' => 6, 'concept' => 'Fila 6 -- no deberia evaluarse'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['passed']);
        $this->assertSame(1, $result['details'][0]['total_rows'], 'solo la fila 5 debe evaluarse -- la fila 6 sigue excluida igual que antes');
    }

    // ── 5. Caso A06/A.1 real reproducido: total_row existe en rem_data -> deja de dar missing_total_row ──
    public function test_a06_a1_real_case_reproduction_stops_being_missing_total_row(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        // Reproduce exactamente el caso real: row_range [12:21], total_row=22.
        $this->createVerticalRule($structure, rowFrom: 12, rowTo: 21, totalRow: 22, ruleKey: 'a06_a1_f_sum_equals');

        for ($row = 12; $row <= 21; $row++) {
            $this->seedRow($upload, $row, 1);
        }
        $this->seedRow($upload, 22, 10, 'TOTAL'); // 10 filas x 1 = 10, correcto

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame('passed', $result['details'][0]['status'], 'debe dejar de ser skipped/missing_total_row');
        $this->assertSame(1, $result['passed'], 'el caso real A06/A.1 debe pasar a passed una vez que ve la fila 22');
    }

    // ── 6. Caso structure-agnostic: una regla con binding serie/global deja de skipped cuando la fila existe ──
    public function test_structure_agnostic_rule_stops_being_skipped_when_row_exists(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = Rule::create([
            'rule_key' => 'test_vertical_agnostic', 'rule_type' => 'sum_equals', 'source' => 'excel_formula',
            'name' => 'Test vertical agnostica', 'description' => 'test', 'severity' => 'error', 'scope' => 'row_range',
            'config' => [
                'sheet' => 'A06', 'section' => 'A.1', 'column' => 'F',
                'source_letters' => ['F'], 'target_column' => 'F',
                'row_range' => ['from' => 12, 'to' => 13], 'row_from' => 12, 'row_to' => 13, 'scope' => 'row_range',
                'total_row' => 14, 'rule_logic' => 'Suma(F) = Columna F',
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        // Binding serie (agnostico) -- NO structure -- igual que las 28 reglas reales encontradas en Fase C.
        RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'serie', 'bindable_id' => null,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);

        $this->seedRow($upload, 12, 3);
        $this->seedRow($upload, 13, 4);
        $this->seedRow($upload, 14, 7, 'TOTAL');

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['passed'], 'la regla agnostica tambien debe beneficiarse del fix -- el prefiltro no distingue tipo de binding');
    }

    // ── 7a. Causa D -- A31 estilo TOTAL final excluido deliberadamente de rem_data -- debe seguir skipped ──
    public function test_a31_style_deliberately_excluded_total_final_stays_skipped(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        // Simula A31/A: row_range [12:27], total_row implicito 28 -- pero el mecanismo #8
        // excluye deliberadamente esa fila de rem_data (confirmado en la auditoria real
        // contra 3 uploads reales -- fila 28 nunca persiste).
        $this->createVerticalRule($structure, rowFrom: 12, rowTo: 27, totalRow: 28, ruleKey: 'a31_style_total_final');

        for ($row = 12; $row <= 27; $row++) {
            $this->seedRow($upload, $row, 1);
        }
        // fila 28 deliberadamente NO se persiste -- no se debe inventar una fuente alternativa.

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['passed']);
        $this->assertSame(1, $result['skipped'], 'Causa D debe seguir skipped -- este fix no resuelve la deuda tecnica #5');
        $this->assertSame('missing_total_row', $result['details'][0]['reason']);
    }

    // ── 7b. Causa D -- A32/F2 estilo subtotal embebido excluido de rem_data -- debe seguir skipped ──
    public function test_a32_f2_style_embedded_subtotal_excluded_stays_skipped(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        // Simula A32/F2: row_range [130:139], total_row implicito 140 (subtotal
        // tecnico embebido, excluido de rem_data por el mecanismo #12 Opcion C).
        $this->createVerticalRule($structure, rowFrom: 130, rowTo: 139, totalRow: 140, ruleKey: 'a32_f2_style_subtotal');

        for ($row = 130; $row <= 139; $row++) {
            $this->seedRow($upload, $row, 2);
        }
        // fila 140 deliberadamente NO se persiste (mecanismo #12).

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame('missing_total_row', $result['details'][0]['reason']);
    }

    /**
     * ── 8. ENDURECIMIENTO (Fase 3C, 2026-08-12): la excepcion de total_row
     * ahora exige rule_type==='sum_equals' && scope==='row_range' && patron
     * vertical estricto (RuleEngineService::isVerticalSumEqualsRule()). Este
     * test reproduce exactamente el mismo escenario que antes demostraba la
     * fuga (required_and_le_parent con total_row presente en config por
     * error/legado) y confirma que la fuga YA NO ocurre: la fila 14 queda
     * excluida del set evaluado, igual que cualquier fila fuera de
     * [row_from:row_to] para un evaluador que no reconoce total_row.
     */
    public function test_total_row_on_non_vertical_evaluator_no_longer_leaks_after_hardening(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        // required_and_le_parent nunca usa total_row en la practica --
        // aqui se agrega deliberadamente para demostrar el comportamiento.
        $rule = Rule::create([
            'rule_key' => 'test_required_le_parent_with_total_row', 'rule_type' => 'required_and_le_parent',
            'source' => 'excel_formula', 'name' => 'Test riesgo', 'description' => 'test', 'severity' => 'error',
            'config' => [
                'sheet' => 'A06', 'section' => 'A.1', 'column' => 'G',
                'source_letters' => ['F'], 'target_column' => 'G',
                'row_range' => ['from' => 12, 'to' => 13], 'row_from' => 12, 'row_to' => 13,
                'total_row' => 14, // presente por error/legado -- no deberia estar aqui.
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structure->id,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['F' => 5, 'G' => 5], 'row_number' => 12, 'concept' => 'Fila 12'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['F' => 3, 'G' => 3], 'row_number' => 13, 'concept' => 'Fila 13'],
        ]);
        // Fila 14 es la fila TOTAL real (bloqueada, formula en el Excel real) --
        // aqui se persiste como un valor cualquiera para probar que ya NO se cuela.
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['F' => 8, 'G' => 8], 'row_number' => 14, 'concept' => 'TOTAL'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        // Tras el endurecimiento, required_and_le_parent nunca ve total_row --
        // solo se evaluan las 2 filas dentro de [12:13], la fila 14 queda fuera.
        $this->assertSame(2, $result['details'][0]['total_rows'], 'la fila TOTAL ya no se cuela: solo se evaluan las filas dentro de row_range');
    }

    /**
     * ── 10. ENDURECIMIENTO: sum_equals + scope row_range pero SIN patron
     * vertical estricto (multiples source_letters, igual que la regla real
     * de produccion id=655 "Suma(D + E) = Columna C") -- aunque total_row
     * este presente en config por error, esta forma de sum_equals nunca pasa
     * por evaluateVerticalAggregation() (va a evaluatePerRow()), asi que no
     * debe recibir la excepcion.
     */
    public function test_sum_equals_row_range_without_strict_vertical_pattern_does_not_leak_total_row(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = Rule::create([
            'rule_key' => 'test_sum_equals_multi_source_row_range', 'rule_type' => 'sum_equals',
            'source' => 'excel_formula', 'name' => 'Test multi-source row_range', 'description' => 'test',
            'severity' => 'error', 'scope' => 'row_range',
            'config' => [
                'sheet' => 'A06', 'section' => 'A.1', 'column' => 'C',
                'source_letters' => ['D', 'E'], 'target_column' => 'C',
                'row_range' => ['from' => 12, 'to' => 13], 'row_from' => 12, 'row_to' => 13, 'scope' => 'row_range',
                'total_row' => 14, // presente por error -- esta forma de sum_equals no es vertical estricta.
                'rule_logic' => 'Suma(D + E) = Columna C',
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structure->id,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['D' => 2, 'E' => 3, 'C' => 5], 'row_number' => 12, 'concept' => 'Fila 12'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['D' => 1, 'E' => 1, 'C' => 2], 'row_number' => 13, 'concept' => 'Fila 13'],
        ]);
        // Fila 14 "TOTAL" no deberia colarse en evaluatePerRow().
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['D' => 999, 'E' => 999, 'C' => 1], 'row_number' => 14, 'concept' => 'TOTAL'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(2, $result['details'][0]['total_rows'], 'solo las 2 filas de row_range se evaluan -- la fila 14 no se cuela pese a total_row en config');
        $this->assertSame(0, $result['failed'], 'las 2 filas reales suman correctamente; la fila 14 (que rompe la suma si se incluyera) no debe afectar el resultado');
    }

    /**
     * ── 11. ENDURECIMIENTO: sum_equals + row_range + patron vertical
     * estricto pero SIN total_row en config -- comportamiento previo a
     * cualquier fix (invalid_row_range_configuration / skipped), intacto.
     */
    public function test_sum_equals_row_range_vertical_without_total_row_behaves_as_before(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $rule = Rule::create([
            'rule_key' => 'test_vertical_no_total_row', 'rule_type' => 'sum_equals', 'source' => 'excel_formula',
            'name' => 'Test vertical sin total_row', 'description' => 'test', 'severity' => 'error', 'scope' => 'row_range',
            'config' => [
                'sheet' => 'A06', 'section' => 'A.1', 'column' => 'AN',
                'source_letters' => ['AN'], 'target_column' => 'AN',
                'row_range' => ['from' => 12, 'to' => 13], 'row_from' => 12, 'row_to' => 13, 'scope' => 'row_range',
                'rule_logic' => 'Suma(AN) = Columna AN',
                // sin total_row -- igual que la regla real id=113 en produccion.
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structure->id,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);

        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['AN' => 1], 'row_number' => 12, 'concept' => 'Fila 12'],
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => 'A06',
            'data' => ['values' => ['AN' => 1], 'row_number' => 13, 'concept' => 'Fila 13'],
        ]);

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        $this->assertSame(0, $result['passed']);
        $this->assertSame(1, $result['skipped'], 'sin total_row en config, sigue skipped/invalid_row_range_configuration -- comportamiento identico al previo al fix');
        $this->assertSame('invalid_row_range_configuration', $result['details'][0]['reason']);
    }

    // ── 9. total_row coincide con una fila YA dentro de [row_from:row_to] (nunca observado en produccion) ──
    public function test_total_row_coinciding_with_row_inside_range_is_not_duplicated(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        // Caso artificial: total_row=13 cae DENTRO de [12:13] en vez de ser row_to+1.
        // La convencion real (verificada contra las 135 reglas de produccion) es
        // siempre total_row = row_to + 1; esto prueba que el filtro no se rompe
        // ni duplica la fila si algun dia esa convencion no se cumple.
        $this->createVerticalRule($structure, rowFrom: 12, rowTo: 13, totalRow: 13, ruleKey: 'test_total_row_inside_range');

        $this->seedRow($upload, 12, 3);
        $this->seedRow($upload, 13, 10, 'TOTAL');

        $result = $this->service->execute($upload->id, $structure->id, write: false);

        // La fila 13 no se duplica (el filtro es un unico paso sobre la coleccion):
        // se evaluan exactamente 2 filas, y evaluateVerticalAggregation() la toma
        // como fila total (no como componente), dejando solo la fila 12 como
        // componente -- 3 != 10, falla, pero sin duplicacion ni crash.
        $this->assertSame(2, $result['details'][0]['total_rows'], 'sin duplicacion: la fila 13 aparece una sola vez en el set evaluado');
        $this->assertSame('failed', $result['details'][0]['status']);
    }
}
