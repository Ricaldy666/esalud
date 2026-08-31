<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 4 (CLAUDE.md punto 17.42). Cubre rule:expand-a09-i-aggregation --
 * generalizacion conservadora de rule:expand-b2-aggregation (17.38-17.40,
 * renombrado) para aceptar las 9 reglas origen periodicas de A09/I
 * (226,227,228,229,230,231,232,233,234 -- columnas AM/AN/AQ/AR/AS/AT/AU/
 * AV/AX), reutilizando exactamente el mismo mecanismo source_rows+total_row
 * ya validado, mas UN guard nuevo (coincidencia exacta con el patron
 * periodico completo) indispensable para rechazar los patrones parciales y
 * de residuo incorrecto de la regla 230/AS (ver auditoria 17.41). Fixtures
 * 100% sinteticas -- para los casos "validos" se replica el patron
 * periodico REAL completo (13 terminos, paso 6) para que el guard 11 nuevo
 * los acepte; para los casos "invalidos" se replican deliberadamente los
 * patrones parciales/de residuo incorrecto reales de 230/AS.
 */
class RuleExpandA09IAggregationCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD_STEP = 6;
    private const PERIOD_TERM_COUNT = 13;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function field(string $letra): array
    {
        return ['letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function structure(int $inicio, int $fin, array $extraColumns = []): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-a09i-expand-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => 'A09', 'sections' => [[
                    'codigo' => 'I', 'titulo' => 'I', 'filaHeader' => $inicio - 1,
                    'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin,
                    'fields' => array_map([$this, 'field'], array_merge(['A', 'AM', 'AN', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AX'], $extraColumns)),
                ]]],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function originRule(string $ruleKey, string $column): Rule
    {
        return Rule::create([
            'rule_key' => $ruleKey, 'rule_type' => 'sum_equals', 'source' => 'excel_formula',
            'name' => 'test', 'description' => 'test', 'category' => 'A09', 'severity' => 'error', 'scope' => 'per_row',
            'config' => [
                'sheet' => 'A09', 'section' => 'I', 'column' => $column,
                'row_range' => ['from' => 0, 'to' => 0],
                'rule_logic' => "Suma({$column}) = Columna {$column}",
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    private function cell(bool $formula, ?string $formulaText = null, ?string $valorBruto = null): array
    {
        return [
            'valor_bruto' => $valorBruto, 'es_editable' => !$formula, 'esta_bloqueada' => $formula,
            'es_formula' => $formula, 'formula' => $formulaText,
        ];
    }

    /** Filas del patron periodico COMPLETO real (13 terminos, paso 6) para un total_row dado. */
    private function periodicSourceRows(int $totalRow): array
    {
        $span = self::PERIOD_STEP * self::PERIOD_TERM_COUNT;

        return range($totalRow - $span, $totalRow - self::PERIOD_STEP, self::PERIOD_STEP);
    }

    private function periodicFormula(string $column, int $totalRow): string
    {
        $rows = $this->periodicSourceRows($totalRow);

        return '=SUM(' . implode('+', array_map(fn ($r) => "{$column}{$r}", $rows)) . ')';
    }

    /** Siembra el concepto TOTAL + la formula dada para una columna en una fila. Opcionalmente siembra ademas otras columnas en la misma fila (para simular contaminacion cruzada tipo AR337). */
    private function seedFormula(string $column, int $totalRow, string $formulaText, array $otherColumnsSameRow = []): void
    {
        $svc = app(CellDataStorageService::class);
        $updates = [
            "A{$totalRow}" => $this->cell(false, null, 'TOTAL'),
            "{$column}{$totalRow}" => $this->cell(true, $formulaText),
        ];
        foreach ($otherColumnsSameRow as $col => $formula) {
            $updates["{$col}{$totalRow}"] = $this->cell(true, $formula);
        }
        $svc->saveCellData('A09', 'I', array_merge($svc->loadCellData('A09', 'I'), $updates));
    }

    private function bindStructure(Rule $rule, int $structureId): RuleBinding
    {
        return RuleBinding::create([
            'rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => $structureId,
            'serie' => 'A', 'anio' => 2026, 'active' => true,
        ]);
    }

    // ── Regresion del comportamiento original (B2, ya cerrado en 17.40) ──

    public function test_valid_dry_run_reports_derived_source_rows_and_safe_classification(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AM', 331, $this->periodicFormula('AM', 331));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $output = Artisan::output();

        $expected = $this->periodicSourceRows(331);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('source_rows derivado: [' . implode(',', $expected) . ']', $output);
        $this->assertStringContainsString('rule_key propuesto: a09_i_am_row331_sum_equals', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $this->assertSame(0, Rule::where('rule_key', 'a09_i_am_row331_sum_equals')->count());
        $origin->refresh();
        $this->assertSame(['from' => 0, 'to' => 0], $origin->config['row_range']);
    }

    public function test_valid_commit_creates_new_rule_with_traceability_and_leaves_origin_untouched(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AM', 331, $this->periodicFormula('AM', 331));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '331',
            '--commit' => true, '--reason' => 'test', '--by' => 'Tester',
        ]);
        $this->assertSame(0, $exit);

        $new = Rule::where('rule_key', 'a09_i_am_row331_sum_equals')->first();
        $this->assertNotNull($new);
        $this->assertSame('active', $new->status);
        $this->assertSame($this->periodicSourceRows(331), $new->config['source_rows']);
        $this->assertSame(331, $new->config['total_row']);
        $this->assertSame($origin->id, $new->metadata['derived_from_rule_id']);
        $this->assertSame($origin->rule_key, $new->metadata['derived_from_rule_key']);
        $this->assertSame(331, $new->metadata['total_row']);
        $this->assertSame('a09_i_expansion', $new->source);

        $origin->refresh();
        $this->assertSame(['from' => 0, 'to' => 0], $origin->config['row_range']);
        $this->assertSame('active', $origin->status);

        $createdLog = Activity::where('description', 'rule_a09_i_aggregation_created')->first();
        $this->assertNotNull($createdLog);
        $this->assertSame($new->id, $createdLog->properties['rule_id']);

        $derivedLog = Activity::where('description', 'rule_a09_i_aggregation_derived')->first();
        $this->assertNotNull($derivedLog);
        $this->assertSame($origin->id, $derivedLog->properties['rule_id']);
        $this->assertSame($new->id, $derivedLog->properties['new_rule_id']);
    }

    // ── Nuevas columnas B3/CategoriaF: cada patron real reproducido ──

    public function test_b3_ar_clean_offset_is_accepted(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_ar_sum_equals', 'AR');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AR', 331, $this->periodicFormula('AR', 331));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '331',
            '--commit' => true, '--reason' => 'test', '--by' => 'Tester',
        ]);
        $this->assertSame(0, $exit);

        $new = Rule::where('rule_key', 'a09_i_ar_row331_sum_equals')->first();
        $this->assertNotNull($new);
        $this->assertSame($this->periodicSourceRows(331), $new->config['source_rows']);

        $svc = app(RuleBindingReconciliationService::class);
        $this->bindStructure($new, $structure->id);
        $all = $svc->classifyAllActiveRules($structure);
        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $new->id)['clasificacion']);
    }

    public function test_categoria_f_228_aq_real_offset_is_accepted(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_aq_sum_equals', 'AQ');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AQ', 332, $this->periodicFormula('AQ', 332));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '332',
            '--commit' => true, '--reason' => 'test', '--by' => 'Tester',
        ]);
        $this->assertSame(0, $exit);
        $this->assertNotNull(Rule::where('rule_key', 'a09_i_aq_row332_sum_equals')->first());
    }

    public function test_categoria_f_230_as_the_one_complete_offset_is_accepted(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_as_sum_equals', 'AS');
        $this->bindStructure($origin, $structure->id);
        // Patron real: unicamente la fila 335 (offset4) tiene la agregacion COMPLETA de 13 terminos.
        $this->seedFormula('AS', 335, $this->periodicFormula('AS', 335));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '335',
            '--commit' => true, '--reason' => 'test', '--by' => 'Tester',
        ]);
        $this->assertSame(0, $exit);
        $this->assertNotNull(Rule::where('rule_key', 'a09_i_as_row335_sum_equals')->first());
    }

    public function test_categoria_f_233_av_real_offset_is_accepted(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_av_sum_equals', 'AV');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AV', 334, $this->periodicFormula('AV', 334));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '334',
            '--commit' => true, '--reason' => 'test', '--by' => 'Tester',
        ]);
        $this->assertSame(0, $exit);
        $this->assertNotNull(Rule::where('rule_key', 'a09_i_av_row334_sum_equals')->first());
    }

    // ── Negativos exigidos: fila 333 sigue bloqueada por contaminacion cruzada ──

    public function test_total_row_333_still_rejected_by_cross_column_contamination(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        // AM333 limpia, pero AR333 (misma fila, otra columna) referencia una fila
        // fuera de la seccion (337 > filaFinDatos=340? usamos 341 para asegurar
        // que quede fuera de cualquier seccion declarada) -- replica exacta del
        // patron real AR337 que contamina toda la fila 333 para CUALQUIER columna.
        $this->seedFormula('AM', 333, $this->periodicFormula('AM', 333), [
            'AR' => '=SUM(AR341+AR255+AR261+AR267+AR273+AR279+AR285+AR291+AR297+AR303+AR309+AR315+AR321+AR327)',
        ]);

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '333']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('mecanismo #12', $output);
        $this->assertSame(0, Rule::where('rule_key', 'a09_i_am_row333_sum_equals')->count());
    }

    // ── Negativos exigidos: combinaciones inexistentes de 228/233 ──

    public function test_228_aq_nonexistent_offset_rejected_no_formula(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_aq_sum_equals', 'AQ');
        $this->bindStructure($origin, $structure->id);
        // Sin sembrar ninguna formula en AQ333 -- replica exacta del template real (nunca calculado).

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '333']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('no tiene formula real', $output);
        $this->assertSame(0, Rule::where('rule_key', 'a09_i_aq_row333_sum_equals')->count());
    }

    public function test_233_av_nonexistent_offsets_rejected_no_formula(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_av_sum_equals', 'AV');
        $this->bindStructure($origin, $structure->id);

        foreach ([331, 332, 333, 335, 336] as $tr) {
            $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => (string) $tr]);
            $this->assertNotSame(0, $exit, "total_row={$tr} deberia rechazarse (sin formula en el template real)");
        }
        $this->assertSame(0, Rule::where('metadata->derived_from_rule_id', $origin->id)->count());
    }

    // ── Negativos exigidos: patrones parciales/ambiguos y de residuo incorrecto de 230 ──

    public function test_230_as_partial_two_term_offset_rejected_by_periodic_match_guard(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_as_sum_equals', 'AS');
        $this->bindStructure($origin, $structure->id);
        // Patron real: AS331 solo suma 2 de los 13 terminos de su propio residuo.
        $rows = $this->periodicSourceRows(331);
        $this->seedFormula('AS', 331, '=SUM(AS' . $rows[2] . ',AS' . $rows[4] . ')');

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('no coincide exactamente', $output);
        $this->assertSame(0, Rule::where('rule_key', 'a09_i_as_row331_sum_equals')->count());
    }

    public function test_230_as_wrong_residue_offset_rejected_by_periodic_match_guard(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_as_sum_equals', 'AS');
        $this->bindStructure($origin, $structure->id);
        // Patron real: AS334 referencia 2 terminos del residuo de offset4 (fila 335),
        // no del suyo propio (offset3, fila 334).
        $wrongResidueRows = $this->periodicSourceRows(335);
        $this->seedFormula('AS', 334, '=SUM(AS' . $wrongResidueRows[1] . ',AS' . $wrongResidueRows[3] . ')');

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '334']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('no coincide exactamente', $output);
        $this->assertSame(0, Rule::where('rule_key', 'a09_i_as_row334_sum_equals')->count());
    }

    public function test_230_as_all_four_partial_offsets_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_as_sum_equals', 'AS');
        $this->bindStructure($origin, $structure->id);

        // Replica de los 4 patrones parciales reales (331,332,333,336 -- cada uno
        // solo 2 de 13 terminos de su propio residuo).
        foreach ([331, 332, 333, 336] as $tr) {
            $rows = $this->periodicSourceRows($tr);
            $this->seedFormula('AS', $tr, '=SUM(AS' . $rows[0] . ',AS' . $rows[6] . ')');
        }

        foreach ([331, 332, 333, 336] as $tr) {
            $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => (string) $tr]);
            $this->assertNotSame(0, $exit, "total_row={$tr} (parcial, 2 de 13 terminos) deberia rechazarse");
        }
        $this->assertSame(0, Rule::where('metadata->derived_from_rule_id', $origin->id)->count());
    }

    // ── Negativo exigido: ninguna regla fuera del universo auditado puede aprovechar la generalizacion ──

    public function test_rule_outside_the_9_origins_rejected_even_with_matching_placeholder(): void
    {
        $structure = $this->structure(250, 340, ['BA']);
        // Mismo sheet/section, columna BA (fuera de las 9), mismo placeholder {0,0}.
        $wrongOrigin = $this->originRule('a09_i_ba_sum_equals', 'BA');
        $this->bindStructure($wrongOrigin, $structure->id);
        $this->seedFormula('BA', 331, $this->periodicFormula('BA', 331));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $wrongOrigin->id, 'total_row' => '331']);
        $output = Artisan::output();

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('no es una de las 9 reglas origen periodicas', $output);
        $this->assertSame(0, Rule::where('source', 'a09_i_expansion')->count());
    }

    public function test_rule_with_matching_column_but_different_rule_key_rejected(): void
    {
        $structure = $this->structure(250, 340);
        // Columna AM valida, pero rule_key distinto al exigido exactamente.
        $wrongOrigin = $this->originRule('a09_i_am_sum_equals_OTRO', 'AM');
        $this->bindStructure($wrongOrigin, $structure->id);
        $this->seedFormula('AM', 331, $this->periodicFormula('AM', 331));

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $wrongOrigin->id, 'total_row' => '331']);

        $this->assertNotSame(0, $exit);
        $this->assertSame(0, Rule::where('metadata->derived_from_rule_id', $wrongOrigin->id)->count());
    }

    // ── Resto de guards heredados (regresion) ──

    public function test_invalid_total_row_argument_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '999']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('no es uno de los 6 valores reales validos', Artisan::output());
    }

    public function test_origin_already_modified_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $origin->update(['config' => array_merge($origin->config, ['row_range' => ['from' => 253, 'to' => 259], 'total_row' => 331])]);
        $this->bindStructure($origin, $structure->id);

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '332']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('ya no tiene row_range={0,0}', Artisan::output());
    }

    public function test_formula_referencing_other_column_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AM', 331, '=SUM(AM253+AN259)');

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('referencia otra columna', Artisan::output());
    }

    public function test_formula_referencing_forward_row_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AM', 331, '=SUM(AM253+AM335)');

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('no es anterior a total_row', Artisan::output());
    }

    public function test_self_reference_only_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AM', 331, '=SUM(AM253)');

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('menos de 2 filas', Artisan::output());
    }

    public function test_duplicate_rule_key_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        Rule::create([
            'rule_key' => 'a09_i_am_row331_sum_equals', 'rule_type' => 'sum_equals', 'source' => 'test',
            'name' => 'existing', 'description' => 'test', 'category' => 'A09', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A09', 'section' => 'I', 'column' => 'AM', 'row_range' => ['from' => 1, 'to' => 1]],
            'status' => 'inactive', 'version' => '1.0.0', 'metadata' => null,
        ]);

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Ya existe una regla', Artisan::output());
    }

    public function test_already_created_combination_rejected(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        Rule::create([
            'rule_key' => 'a09_i_am_row331_sum_equals_other', 'rule_type' => 'sum_equals', 'source' => 'test',
            'name' => 'existing', 'description' => 'test', 'category' => 'A09', 'severity' => 'error', 'scope' => 'row_range',
            'config' => ['sheet' => 'A09', 'section' => 'I', 'column' => 'AM', 'row_range' => ['from' => 253, 'to' => 259], 'total_row' => 331, 'source_rows' => [253, 259]],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);

        $exit = Artisan::call('rule:expand-a09-i-aggregation', ['origin_rule_id' => (string) $origin->id, 'total_row' => '331']);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('evita doble-creacion', Artisan::output());
    }

    public function test_two_offsets_for_same_origin_both_escape_duplicate_after_second_commit(): void
    {
        $structure = $this->structure(250, 340);
        $origin = $this->originRule('a09_i_am_sum_equals', 'AM');
        $this->bindStructure($origin, $structure->id);
        $this->seedFormula('AM', 331, $this->periodicFormula('AM', 331));
        $this->seedFormula('AM', 332, $this->periodicFormula('AM', 332));

        Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '331',
            '--commit' => true, '--reason' => 'r1', '--by' => 'Tester',
        ]);
        $exit2 = Artisan::call('rule:expand-a09-i-aggregation', [
            'origin_rule_id' => (string) $origin->id, 'total_row' => '332',
            '--commit' => true, '--reason' => 'r2', '--by' => 'Tester',
        ]);
        $this->assertSame(0, $exit2);

        $rule1 = Rule::where('rule_key', 'a09_i_am_row331_sum_equals')->first();
        $rule2 = Rule::where('rule_key', 'a09_i_am_row332_sum_equals')->first();
        $this->bindStructure($rule1, $structure->id);
        $this->bindStructure($rule2, $structure->id);

        $svc = app(RuleBindingReconciliationService::class);
        $all = $svc->classifyAllActiveRules($structure);

        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $rule1->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::SAFE_1_TO_1, $all->firstWhere('rule_id', $rule2->id)['clasificacion']);
        $this->assertSame(RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP, $all->firstWhere('rule_id', $origin->id)['clasificacion']);
    }
}
