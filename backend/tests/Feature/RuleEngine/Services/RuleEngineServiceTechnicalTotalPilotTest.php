<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTechnicalTotal;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 3B -- PILOTO CONTROLADO (CLAUDE.md punto 17.8). Conecta
 * RuleEngineService con rem_technical_totals (Fase 3A) EXCLUSIVAMENTE para
 * reglas sum_equals verticales cuyo total_row esta configurado y cuya fila
 * TOTAL no llego a persistirse en rem_data para esa carga especifica.
 *
 * Todos los fixtures son 100% sinteticos (nunca produccion historica).
 *
 * - "Patron 56" (A03/D.7 real): rango componente CONTIGUO [206:207], TOTAL
 *   en 208 = SUM(206:207) -- caso SEGURO, el evaluador vertical generico ya
 *   soporta este patron correctamente si row_range/total_row son reales.
 * - "Patron 208/214" (A09/F.1 real): la formula real de la fila TOTAL
 *   (F158=SUM(F149,F150,F153,F155,F157)) NO es un rango contiguo -- suma 5
 *   filas especificas, saltando otras dentro de [146:157]. Auditoria de
 *   Fase 3B (READ-ONLY, antes de escribir codigo) confirmo que esto es una
 *   agregacion irregular/periodica, MISMA clase de problema que A09/I
 *   (congelada, CLAUDE.md punto 10). Por eso NO se propuso ni simulo ningun
 *   cambio de config para 208/214 -- permanecen con su config real
 *   (row_range={0,0}), y estos tests confirman que el mecanismo nuevo de
 *   Fase 3B es INERTE para ellas exactamente como lo era antes (scope
 *   nunca es 'row_range' con {0,0}, asi que $totalRow nunca deja de ser
 *   null y rem_technical_totals nunca se consulta para estas 2 reglas).
 */
class RuleEngineServiceTechnicalTotalPilotTest extends TestCase
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
                'name' => 'Centro Piloto 3B',
                'code_deis' => 'P3B' . uniqid(),
                'type' => 'CESFAM',
            ])->id,
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'piloto_3b.xlsx',
            'stored_path' => 'rem/2098/01/piloto_3b.xlsx',
            'file_size' => 1000,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function createStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2098,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'hash_estructura' => 'hash_piloto_3b_' . uniqid(),
            'status' => 'active',
        ]);
    }

    /** Regla vertical con la MISMA forma de config que las reglas reales (column/rule_logic/row_range/total_row), no source_letters/target_column directos -- necesario para que normalizeConfig() derive scope='row_range'. */
    private function createVerticalRule(string $sheet, string $section, string $column, int $rowFrom, int $rowTo, ?int $totalRow, string $key): Rule
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

        return Rule::create([
            'rule_key' => $key,
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => $key,
            'description' => 'Piloto Fase 3B',
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

    private function seedTechnicalTotal(RemUpload $upload, string $sheet, string $section, int $row, string $column, int $value, string $reason = 'embedded_backward_subtotal_row'): void
    {
        RemTechnicalTotal::create([
            'rem_upload_id' => $upload->id,
            'sheet' => $sheet,
            'rem_section_code' => $section,
            'row_number' => $row,
            'concept' => 'TOTAL',
            'total' => (string) $value,
            'values' => [$column => $value],
            'exclusion_reason' => $reason,
        ]);
    }

    // ── Patron 56 (contiguo, seguro) ──────────────────────────────────

    public function test_rule_56_pattern_produces_correct_result_matching_excel_formula(): void
    {
        // Replica exacta del patron real de la regla 56 (A03/D.7 AH,
        // formula real =SUM(AH206+AH207) en AH208): 2 filas componente
        // contiguas + 1 fila TOTAL, valor tecnico = suma real de Excel.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_regla_56');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        $this->seedRemData($upload, 'P3B56', 'V', 207, 'B', 9);
        // Excel real: AH208 = SUM(AH206+AH207) = 13.
        $this->seedTechnicalTotal($upload, 'P3B56', 'V', 208, 'B', 13);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_regla_56');
        $this->assertSame('passed', $detail['status']);
        $this->assertSame(0, $detail['failed_rows']);
        $this->assertSame('', $detail['reason']);
    }

    public function test_rule_56_pattern_fails_when_technical_total_does_not_match_components(): void
    {
        // Prueba de correctitud negativa: si el valor tecnico capturado NO
        // coincide con la suma real de los componentes, la regla debe
        // FALLAR -- demuestra que el mecanismo no "inventa" un pase.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_regla_56_mismatch');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        $this->seedRemData($upload, 'P3B56', 'V', 207, 'B', 9);
        $this->seedTechnicalTotal($upload, 'P3B56', 'V', 208, 'B', 999); // no coincide con 4+9=13

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_regla_56_mismatch');
        $this->assertSame('failed', $detail['status']);
        $this->assertSame(1, $detail['failed_rows']);
        $this->assertSame('failed', $detail['reason']);

        $validation = RemValidationResult::where('rule_key', 'piloto_regla_56_mismatch')->firstOrFail();
        $this->assertSame('vertical_sum_mismatch', $validation->context['details'][0]['reason']);
        $this->assertEquals(13, $validation->context['details'][0]['calculated_sum']);
        $this->assertEquals(999, $validation->context['details'][0]['declared_value']);
    }

    public function test_technical_value_absent_falls_back_to_existing_missing_total_row_behavior(): void
    {
        // Sin fila en rem_technical_totals (ej. carga anterior a Fase 3A):
        // debe comportarse EXACTAMENTE como antes de Fase 3B -- skip
        // explicito con motivo missing_total_row, sin fallback silencioso.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_sin_tecnico');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        $this->seedRemData($upload, 'P3B56', 'V', 207, 'B', 9);
        // Sin seedTechnicalTotal() -- deliberado.

        $this->assertSame(0, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_sin_tecnico');
        $this->assertSame('skipped', $detail['status']);
        $this->assertSame('missing_total_row', $detail['reason']);
    }

    public function test_normal_component_rows_still_come_from_rem_data(): void
    {
        // Si un componente falta en rem_data, el motor NO debe rellenarlo
        // desde rem_technical_totals (esa tabla solo aporta la fila TOTAL,
        // nunca filas de componentes normales) -- la suma calculada debe
        // reflejar exclusivamente lo que hay en rem_data.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_componente_parcial');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        // Fila 207 deliberadamente ausente de rem_data.
        $this->seedTechnicalTotal($upload, 'P3B56', 'V', 208, 'B', 4); // coincide solo con el componente real (206)

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_componente_parcial');
        $this->assertSame('passed', $detail['status'], 'La suma debe calcularse solo con los componentes realmente presentes en rem_data (206=4), sin inventar el 207 faltante');
    }

    public function test_technical_total_row_remains_absent_from_rem_data_after_evaluation(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_sin_writeback');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        $this->seedRemData($upload, 'P3B56', 'V', 207, 'B', 9);
        $this->seedTechnicalTotal($upload, 'P3B56', 'V', 208, 'B', 13);

        $this->service->execute($upload->id, $structure->id, write: true);

        $this->assertSame(
            0,
            RemData::where('rem_upload_id', $upload->id)->where('section', 'P3B56')
                ->get()->filter(fn($rd) => $rd->data['row_number'] === 208)->count(),
            'La fila TOTAL tecnica no debe escribirse jamas en rem_data como efecto de la evaluacion'
        );
        // rem_technical_totals tampoco se modifica (RuleEngineService solo lee).
        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());
    }

    // ── Patron 208/214 (irregular, NO corregido -- mecanismo inerte) ──

    public function test_rule_208_pattern_unaffected_because_row_range_is_still_the_zero_zero_placeholder(): void
    {
        // Replica EXACTA de la config real de la regla 208 (A09/F.1,
        // columna F, row_range={0,0}, sin total_row). Con {0,0},
        // normalizeConfig() deja scope='per_row' (from===to), por lo que
        // isVerticalSumEqualsRule() da false y $totalRow nunca deja de ser
        // null -- el bloque nuevo de Fase 3B jamas se activa. No se
        // propuso corregir row_range para esta regla (ver docblock de la
        // clase): su formula real suma filas NO contiguas
        // (F149,F150,F153,F155,F157), y el evaluador vertical generico
        // asume un rango contiguo -- forzar row_range=[146:157] produciria
        // una suma incorrecta (incluiria filas que la formula real
        // ignora). Este test documenta que, sin ese cambio, el
        // comportamiento sigue siendo exactamente el de antes.
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B208', 'F1', 'F', 0, 0, null, 'piloto_regla_208_real');
        $this->bind($rule, $structure);

        // Aunque exista una fila tecnica "candidata" en la tabla nueva
        // (simulando que alguien la hubiera poblado igual), la regla no
        // debe consultarla -- porque $totalRow nunca se resuelve.
        $this->seedTechnicalTotal($upload, 'P3B208', 'F1', 158, 'F', 999);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_regla_208_real');
        $this->assertSame('skipped', $detail['status']);
        $this->assertSame('Sin datos', $detail['reason'], 'row_range={0,0} produce scope=per_row -- $rows queda vacio, exactamente igual que antes de Fase 3B');
    }

    public function test_rule_214_pattern_unaffected_same_as_208(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B208', 'F1', 'L', 0, 0, null, 'piloto_regla_214_real');
        $this->bind($rule, $structure);
        $this->seedTechnicalTotal($upload, 'P3B208', 'F1', 158, 'L', 999);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detail = collect($result['details'])->firstWhere('rule_key', 'piloto_regla_214_real');
        $this->assertSame('skipped', $detail['status']);
        $this->assertSame('Sin datos', $detail['reason']);
    }

    // ── Reglas vecinas / duplicidad / historico ───────────────────────

    public function test_neighbor_rule_on_same_sheet_is_not_affected_by_technical_total_injection(): void
    {
        // "No afecta reglas vecinas": 2 reglas verticales distintas en la
        // MISMA hoja (comparten el mismo grouped->get($sheet) antes del
        // filtro por rango) -- una recibe inyeccion tecnica, la otra no
        // debe ver ninguna fila ajena ni ver alterado su propio conteo.
        $structure = $this->createStructure();
        $upload = $this->createUpload();

        $ruleA = $this->createVerticalRule('P3BSHARED', 'V1', 'B', 10, 11, 12, 'piloto_vecina_a');
        $ruleB = $this->createVerticalRule('P3BSHARED', 'V2', 'C', 20, 21, 22, 'piloto_vecina_b');
        $this->bind($ruleA, $structure);
        $this->bind($ruleB, $structure);

        // Regla A: TOTAL excluido, se inyecta desde rem_technical_totals.
        $this->seedRemData($upload, 'P3BSHARED', 'V1', 10, 'B', 5);
        $this->seedRemData($upload, 'P3BSHARED', 'V1', 11, 'B', 7);
        $this->seedTechnicalTotal($upload, 'P3BSHARED', 'V1', 12, 'B', 12);

        // Regla B: caso normal, TOTAL SI esta en rem_data (sin tecnico).
        $this->seedRemData($upload, 'P3BSHARED', 'V2', 20, 'C', 3);
        $this->seedRemData($upload, 'P3BSHARED', 'V2', 21, 'C', 6);
        $this->seedRemData($upload, 'P3BSHARED', 'V2', 22, 'C', 9);

        $result = $this->service->execute($upload->id, $structure->id, write: true);

        $detailA = collect($result['details'])->firstWhere('rule_key', 'piloto_vecina_a');
        $detailB = collect($result['details'])->firstWhere('rule_key', 'piloto_vecina_b');

        $this->assertSame('passed', $detailA['status']);
        $this->assertSame('passed', $detailB['status'], 'La regla vecina (sin necesidad de tecnico) debe pasar normalmente, sin contaminacion de la fila inyectada para la otra regla');
        // 3 = 2 componentes (20,21) + la fila TOTAL 22, que en la regla B
        // YA viene de rem_data (sin inyeccion tecnica) -- total_rows cuenta
        // todas las filas evaluadas, no solo componentes.
        $this->assertSame(3, $detailB['total_rows']);
    }

    public function test_no_duplicate_execution_logs_on_repeated_execute_calls(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_repetido');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        $this->seedRemData($upload, 'P3B56', 'V', 207, 'B', 9);
        $this->seedTechnicalTotal($upload, 'P3B56', 'V', 208, 'B', 13);

        $first = $this->service->execute($upload->id, $structure->id, write: true);
        $second = $this->service->execute($upload->id, $structure->id, write: true);

        $detail1 = collect($first['details'])->firstWhere('rule_key', 'piloto_repetido');
        $detail2 = collect($second['details'])->firstWhere('rule_key', 'piloto_repetido');
        $this->assertSame($detail1['status'], $detail2['status']);
        $this->assertSame($detail1['failed_rows'], $detail2['failed_rows']);

        // rem_technical_totals nunca se escribe desde RuleEngineService --
        // sigue habiendo exactamente 1 fila tras 2 ejecuciones.
        $this->assertSame(1, RemTechnicalTotal::where('rem_upload_id', $upload->id)->count());

        // Cada execute(write:true) crea su propio log (versionado por
        // ejecucion, comportamiento preexistente sin cambios) -- se
        // confirma que son exactamente 2, no mas (sin duplicacion dentro
        // de una misma llamada).
        $this->assertSame(
            2,
            RuleExecutionLog::where('rule_id', $rule->id)->where('rem_upload_id', $upload->id)->count()
        );
    }

    public function test_historical_rem_data_is_never_modified_by_execute(): void
    {
        $structure = $this->createStructure();
        $upload = $this->createUpload();
        $rule = $this->createVerticalRule('P3B56', 'V', 'B', 206, 207, 208, 'piloto_historico');
        $this->bind($rule, $structure);

        $this->seedRemData($upload, 'P3B56', 'V', 206, 'B', 4);
        $this->seedRemData($upload, 'P3B56', 'V', 207, 'B', 9);
        $this->seedTechnicalTotal($upload, 'P3B56', 'V', 208, 'B', 13);

        $before = RemData::where('rem_upload_id', $upload->id)->orderBy('id')->get()->toArray();

        $this->service->execute($upload->id, $structure->id, write: true);

        $after = RemData::where('rem_upload_id', $upload->id)->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'rem_data debe permanecer byte-idéntica antes/despues de execute()');
    }
}
