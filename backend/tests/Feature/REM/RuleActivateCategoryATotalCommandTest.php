<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 3C-1 (CLAUDE.md punto 17.11). Cubre rule:activate-category-a --
 * activa row_range/total_row para reglas de Categoria A (contigua, TOTAL
 * excluido de rem_data, mismo mecanismo que la regla 56 -- Fase 3B). Casos
 * de control replican exactamente los patrones ya auditados como NO aptos
 * (208/214 huecos, A26/B termino externo, A09/I periodicidad, 461/las 55
 * fuera de limites) para confirmar que el comando los rechaza sin caso
 * especial.
 */
class RuleActivateCategoryATotalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function field(string $letra): array
    {
        return ['letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function structure(string $sheet, string $section, int $inicio, int $fin, array $fields): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-cat-a-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => $sheet, 'sections' => [[
                    'codigo' => $section, 'titulo' => $section, 'filaHeader' => $inicio - 1,
                    'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin, 'fields' => $fields,
                ]]],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function zeroZeroRule(string $sheet, string $section, string $column, string $key = null): Rule
    {
        return Rule::create([
            'rule_key' => $key ?? ('cat_a_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
            'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'test', 'description' => 'test',
            'category' => $sheet, 'severity' => 'error', 'scope' => 'per_row',
            'config' => [
                'sheet' => $sheet, 'section' => $section, 'column' => $column,
                'row_range' => ['from' => 0, 'to' => 0],
                'rule_logic' => "Suma({$column}) = Columna {$column}",
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    private function verticalRule(string $sheet, string $section, string $column, int $from, int $to, string $key = null): Rule
    {
        return Rule::create([
            'rule_key' => $key ?? ('cat_a_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
            'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'test', 'description' => 'test',
            'category' => $sheet, 'severity' => 'error', 'scope' => 'per_row',
            'config' => [
                'sheet' => $sheet, 'section' => $section, 'column' => $column,
                'row_range' => ['from' => $from, 'to' => $to],
                'rule_logic' => "Suma({$column}) = Columna {$column}",
            ],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    private function cell(bool $formula, ?string $formulaText = null, ?string $valorBruto = null, bool $editable = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable ?? !$formula,
            'esta_bloqueada' => $formula,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function seedBackwardTotal(string $sheet, string $section, string $column, int $totalRow, string $formulaText, string $concept = 'TOTAL'): void
    {
        app(CellDataStorageService::class)->saveCellData($sheet, $section, array_merge(
            app(CellDataStorageService::class)->loadCellData($sheet, $section),
            [
                "A{$totalRow}" => $this->cell(false, null, $concept),
                "{$column}{$totalRow}" => $this->cell(true, $formulaText),
            ]
        ));
    }

    private function seedRemData(string $sheet, string $section, int $rowNumber): void
    {
        $healthCenter = HealthCenter::create(['name' => 'Test CESFAM', 'code_deis' => 'TEST-' . uniqid(), 'type' => 'CESFAM', 'is_active' => true]);
        $user = User::factory()->create();
        $upload = RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'health_center_id' => $healthCenter->id, 'user_id' => $user->id,
            'rem_template_id' => null, 'year' => 2026, 'month' => 1, 'rem_type' => 'A', 'original_filename' => 'test.xlsm',
            'stored_path' => 'test/test.xlsm', 'file_size' => 100, 'mime_type' => 'application/vnd.ms-excel', 'status' => 'completed',
        ]);
        RemData::create([
            'rem_upload_id' => $upload->id, 'section' => $sheet,
            'data' => ['concept' => 'test', 'row_number' => $rowNumber, 'section' => $sheet, 'rem_section_code' => $section, 'total_column' => 'A', 'values' => []],
        ]);
    }

    // ── Caso compatible: replica exacta de la regla 56 ────────────────

    public function test_v56_pattern_discovers_row_range_and_total_row(): void
    {
        $this->structure('P3C1', 'V56', 10, 12, [$this->field('A'), $this->field('B')]);
        $rule = $this->zeroZeroRule('P3C1', 'V56', 'B');
        $this->seedBackwardTotal('P3C1', 'V56', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1', 'V56', 10);
        $this->seedRemData('P3C1', 'V56', 11);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[10:11]', $output);
        $this->assertStringContainsString('total_row propuesto: 12', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $rule->refresh();
        $this->assertSame(['from' => 0, 'to' => 0], $rule->config['row_range'], 'dry-run no debe escribir');
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    public function test_v56_pattern_commit_writes_only_row_range_and_total_row(): void
    {
        $this->structure('P3C1', 'V56', 10, 12, [$this->field('A'), $this->field('B')]);
        $rule = $this->verticalRuleZeroZeroWithExtraKey();

        $exit = Artisan::call('rule:activate-category-a', [
            'rule_id' => (string) $rule->id, '--reason' => 'Categoria A, patron 56', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);
        $this->assertSame(0, $exit);

        $rule->refresh();
        $this->assertSame(['from' => 10, 'to' => 11], $rule->config['row_range']);
        $this->assertSame(12, $rule->config['total_row']);
        // resto de config intacto (mismas claves que antes, mas total_row)
        $this->assertSame('P3C1', $rule->config['sheet']);
        $this->assertSame('V56', $rule->config['section']);
        $this->assertSame('B', $rule->config['column']);
        $this->assertSame('Suma(B) = Columna B', $rule->config['rule_logic']);
        $this->assertCount(6, $rule->config, 'config debe tener exactamente sheet/section/column/row_range/rule_logic/total_row = 6 claves');

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('total_row', $version->config, 'RuleVersion debe guardar el config ANTERIOR (sin total_row)');
        $this->assertSame(['from' => 0, 'to' => 0], $version->config['row_range']);

        $activity = Activity::where('description', 'rule_category_a_activated')->latest('id')->first();
        $this->assertNotNull($activity, 'debe existir un activity log');
        $this->assertSame('rule_category_a_activated', $activity->description);
        $this->assertSame(12, $activity->properties['total_row_set']);
        $this->assertSame(['from' => 10, 'to' => 11], $activity->properties['row_range_set']);
        $this->assertTrue($activity->properties['range_changed']);
    }

    private function verticalRuleZeroZeroWithExtraKey(): Rule
    {
        $rule = $this->zeroZeroRule('P3C1', 'V56', 'B');
        $this->seedBackwardTotal('P3C1', 'V56', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1', 'V56', 10);
        $this->seedRemData('P3C1', 'V56', 11);

        return $rule;
    }

    // ── Fórmula con huecos (patrón 208/214) ────────────────────────────

    public function test_formula_with_gaps_like_a09f1_is_rejected(): void
    {
        $this->structure('P3C1', 'GAP', 10, 15, [$this->field('A'), $this->field('B')]);
        $rule = $this->zeroZeroRule('P3C1', 'GAP', 'B');
        // Replica el hueco real de 208/214: suma filas especificas, no el rango completo.
        $this->seedBackwardTotal('P3C1', 'GAP', 'B', 15, '=SUM(B11,B13)');
        $this->seedRemData('P3C1', 'GAP', 11);
        $this->seedRemData('P3C1', 'GAP', 13);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato unico', Artisan::output());
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    // ── Fórmula con término externo (patrón A26/B) ─────────────────────

    public function test_formula_with_external_term_like_a26b_is_rejected(): void
    {
        $this->structure('P3C1', 'EXT', 10, 13, [$this->field('A'), $this->field('B')]);
        $rule = $this->zeroZeroRule('P3C1', 'EXT', 'B');
        // Replica A26/B: rango contiguo [10:12] MAS un termino externo (B5).
        $this->seedBackwardTotal('P3C1', 'EXT', 'B', 13, '=SUM(B10:B12)+B5');
        $this->seedRemData('P3C1', 'EXT', 10);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato unico', Artisan::output());
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    // ── Patrón periódico (A09/I) ────────────────────────────────────────

    public function test_periodic_pattern_like_a09i_is_rejected(): void
    {
        $this->structure('P3C1', 'PERIOD', 10, 20, [$this->field('A'), $this->field('B')]);
        $rule = $this->zeroZeroRule('P3C1', 'PERIOD', 'B');
        // Salta de 3 en 3, nunca cubre el rango completo [10:19].
        $this->seedBackwardTotal('P3C1', 'PERIOD', 'B', 20, '=B10+B13+B16+B19');
        $this->seedRemData('P3C1', 'PERIOD', 10);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato unico', Artisan::output());
    }

    // ── Fórmula incompleta ──────────────────────────────────────────────

    public function test_incomplete_formula_is_rejected(): void
    {
        $this->structure('P3C1', 'INC', 10, 11, [$this->field('A'), $this->field('B')]);
        $rule = $this->zeroZeroRule('P3C1', 'INC', 'B');
        // es_formula=true pero sin texto de formula real.
        app(CellDataStorageService::class)->saveCellData('P3C1', 'INC', [
            'A11' => $this->cell(false, null, 'TOTAL'),
            'B11' => $this->cell(true, ''),
        ]);
        $this->seedRemData('P3C1', 'INC', 10);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato unico', Artisan::output());
    }

    // ── Candidato ambiguo ─────────────────────────────────────────────

    public function test_ambiguous_candidate_is_rejected(): void
    {
        $this->structure('P3C1', 'AMBIG', 10, 15, [$this->field('A'), $this->field('B')]);
        $rule = $this->zeroZeroRule('P3C1', 'AMBIG', 'B');
        // Dos filas distintas, cada una un subtotal hacia atras completo y
        // valido por si sola: fila 12 cubre [10:11], fila 14 cubre [10:13].
        $this->seedBackwardTotal('P3C1', 'AMBIG', 'B', 12, '=SUM(B10+B11)', 'TOTAL PARCIAL');
        app(CellDataStorageService::class)->saveCellData('P3C1', 'AMBIG', array_merge(
            app(CellDataStorageService::class)->loadCellData('P3C1', 'AMBIG'),
            [
                'A14' => $this->cell(false, null, 'TOTAL GENERAL'),
                'B14' => $this->cell(true, '=SUM(B10:B13)'),
            ]
        ));
        $this->seedRemData('P3C1', 'AMBIG', 10);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato unico', Artisan::output());
    }

    // ── Leading (fuera de alcance de Fase 3C-1) ────────────────────────

    public function test_leading_position_is_rejected_out_of_scope(): void
    {
        $this->structure('P3C1', 'LEAD', 10, 11, [$this->field('A'), $this->field('X')]);
        $rule = $this->verticalRule('P3C1', 'LEAD', 'X', 10, 11);
        // Total lider ANTES del rango (fila 9), patron leading real.
        app(CellDataStorageService::class)->saveCellData('P3C1', 'LEAD', [
            'A9' => $this->cell(false, null, 'TOTAL'),
            'X9' => $this->cell(true, '=SUM(X10:X11)'),
        ]);
        $this->seedRemData('P3C1', 'LEAD', 9);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("no 'trailing'", Artisan::output());
    }

    // ── Caso "225-style": row_range ya real, trailing, excluded=true ──

    public function test_real_row_range_trailing_excluded_pattern_like_the_225(): void
    {
        $this->structure('P3C1', 'REAL', 10, 12, [$this->field('A'), $this->field('B')]);
        $rule = $this->verticalRule('P3C1', 'REAL', 'B', 10, 11);
        // Total TRAILING (fila 12, row_to+1), formula hacia atras.
        $this->seedBackwardTotal('P3C1', 'REAL', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1', 'REAL', 10);
        $this->seedRemData('P3C1', 'REAL', 11);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('total_row propuesto: 12', $output);
        $this->assertStringContainsString('row_range sin cambio', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
    }

    // ── Excepción de límites (patrón 461 / las 55 de A31-A33) ──────────

    public function test_candidate_outside_structural_bounds_is_rejected_like_461_and_the_55(): void
    {
        // filaFinDatos=11 (el TOTAL real, fila 12, queda fuera del limite
        // estructural declarado) -- misma causa raiz que las 55 reglas de
        // A31/A32/A33 y que la regla 461 (leading, caso ya congelado).
        $this->structure('P3C1', 'BOUNDS', 10, 11, [$this->field('A'), $this->field('B')]);
        $rule = $this->verticalRule('P3C1', 'BOUNDS', 'B', 10, 11);
        $this->seedBackwardTotal('P3C1', 'BOUNDS', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1', 'BOUNDS', 10);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('fuera del rango vivo de la seccion', $output);
        $this->assertStringContainsString('regla 461', $output);
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    // ── Regla no sum_equals ─────────────────────────────────────────────

    public function test_non_sum_equals_rule_is_rejected(): void
    {
        $this->structure('P3C1', 'OTHER', 10, 11, [$this->field('A')]);
        $rule = Rule::create([
            'rule_key' => 'not_sum_equals', 'rule_type' => 'required_and_le_parent', 'source' => 'test',
            'name' => 'test', 'description' => 'test', 'category' => 'P3C1', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'P3C1', 'section' => 'OTHER', 'column' => 'A', 'row_range' => ['from' => 0, 'to' => 0]],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);

        $exit = Artisan::call('rule:activate-category-a', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no es rule_type=sum_equals', Artisan::output());
    }

    // ── Preservación de datos ajenos ────────────────────────────────────

    public function test_commit_preserves_bindings_history_and_unrelated_rem_data(): void
    {
        $this->structure('P3C1', 'V56', 10, 12, [$this->field('A'), $this->field('B')]);
        $rule = $this->verticalRuleZeroZeroWithExtraKey();

        $otherRule = $this->verticalRule('P3C1', 'OTHERSEC', 'C', 20, 21);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 999, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        $bindingsBefore = RuleBinding::count();
        $remDataBefore = RemData::orderBy('id')->get()->toArray();
        $otherConfigBefore = $otherRule->config;

        Artisan::call('rule:activate-category-a', [
            'rule_id' => (string) $rule->id, '--reason' => 'test', '--by' => 'Tester', '--commit' => true,
        ]);

        $this->assertSame($bindingsBefore, RuleBinding::count(), 'no debe crear/eliminar bindings');
        $otherRule->refresh();
        $this->assertEquals($otherConfigBefore, $otherRule->config, 'regla ajena intacta');
        $this->assertEquals($remDataBefore, RemData::orderBy('id')->get()->toArray(), 'rem_data byte-identico');
    }
}
