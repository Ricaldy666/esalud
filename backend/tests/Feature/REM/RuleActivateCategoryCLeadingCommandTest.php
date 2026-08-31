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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 3C-2 (CLAUDE.md punto 17.17/17.18). Cubre rule:activate-category-c-leading
 * -- activa config.total_row EXCLUSIVAMENTE para el patron "TOTAL leading
 * tecnico excluido, candidato YA dentro de [filaInicioDatos:filaFinDatos]"
 * (las 29 reales de Categoria C). Nunca toca row_range. Casos de control
 * replican los patrones ya auditados: candidato fuera de bounds (patron
 * exacto de la regla 461, punto 16.13), trailing, excluded=false, formula
 * con huecos, termino externo, no_utilizada, fila reclamada por otra
 * seccion, candidato ambiguo, placeholder {0,0} (Categoria B/F).
 */
class RuleActivateCategoryCLeadingCommandTest extends TestCase
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

    private function structure(string $sheet, array $sections): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-category-c-leading-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => $sheet, 'sections' => $sections],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function sectionDef(string $codigo, int $inicio, int $fin, array $fields): array
    {
        return ['codigo' => $codigo, 'titulo' => $codigo, 'filaHeader' => $inicio - 1, 'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin, 'fields' => $fields];
    }

    private function verticalRule(string $sheet, string $section, string $column, int $from, int $to, string $key = null): Rule
    {
        return Rule::create([
            'rule_key' => $key ?? ('cc_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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

    private function zeroZeroRule(string $sheet, string $section, string $column, string $key = null): Rule
    {
        return Rule::create([
            'rule_key' => $key ?? ('cc_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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

    private function cell(bool $formula, ?string $formulaText = null, ?string $valorBruto = null, ?bool $editable = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable ?? !$formula,
            'esta_bloqueada' => $formula,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function seedLeadingTotal(string $sheet, string $section, string $column, int $totalRow, string $formulaText, string $concept = 'TOTAL'): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData($sheet, $section, array_merge(
            $svc->loadCellData($sheet, $section),
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

    // ── Caso valido: leading, candidato DENTRO de [filaInicioDatos:filaFinDatos] ──

    public function test_valid_leading_within_bounds_pattern(): void
    {
        // Seccion mas ancha (8-15) que el row_range de la regla (10-11) --
        // el candidato leading (9) cae dentro de la seccion, a diferencia
        // de la regla 461 (candidato = filaInicioDatos-1, fuera).
        $this->structure('P3C2', [$this->sectionDef('V', 8, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B11)'); // from(10)-1=9
        $this->seedRemData('P3C2', 'V', 10);
        $this->seedRemData('P3C2', 'V', 11);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('total_row propuesto: 9', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config, 'dry-run no debe escribir');
    }

    public function test_valid_pattern_commit_writes_only_total_row(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P3C2', 'V', 10);
        $this->seedRemData('P3C2', 'V', 11);

        $exit = Artisan::call('rule:activate-category-c-leading', [
            'rule_id' => (string) $rule->id, '--reason' => 'patron certificado 17.17', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);
        $this->assertSame(0, $exit);

        $rule->refresh();
        $this->assertSame(9, $rule->config['total_row']);
        $this->assertSame(['from' => 10, 'to' => 11], $rule->config['row_range'], 'row_range NUNCA debe tocarse en este comando');
        $this->assertSame('P3C2', $rule->config['sheet']);
        $this->assertSame('V', $rule->config['section']);
        $this->assertSame('B', $rule->config['column']);
        $this->assertCount(6, $rule->config, 'config debe tener exactamente sheet/section/column/row_range/rule_logic/total_row = 6 claves');

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('total_row', $version->config);
        $this->assertSame(['from' => 10, 'to' => 11], $version->config['row_range']);

        $activity = Activity::where('description', 'rule_category_c_leading_activated')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame(9, $activity->properties['total_row_set']);
        $this->assertSame('patron certificado 17.17', $activity->properties['reason']);
        $this->assertSame('Administrador Esalud', $activity->properties['by']);
    }

    // ── Candidato fuera de bounds -- patron EXACTO de la regla 461 ──────

    public function test_candidate_out_of_bounds_rejected_like_461(): void
    {
        // filaInicioDatos == row_range.from -- el candidato leading
        // (from-1) cae FUERA de la seccion, exactamente como 461
        // (filaInicioDatos=124, candidato=123).
        $this->structure('P3C2', [$this->sectionDef('V', 10, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P3C2', 'V', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cae fuera del rango vivo de la seccion', $output);
    }

    // ── excluded=false (o sin candidato) rechazado ─────────────────────

    public function test_excluded_false_rejected(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        app(CellDataStorageService::class)->saveCellData('P3C2', 'V', [
            'A9' => $this->cell(false, null, 'Concepto real'),
            'B9' => $this->cell(false, null, null, true), // editable real, no formula
        ]);
        $this->seedRemData('P3C2', 'V', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertTrue(
            str_contains($output, 'Fase 1 no encontro un candidato') || str_contains($output, 'no confirma la fila'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Trailing rechazado ──────────────────────────────────────────────

    public function test_trailing_position_rejected(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        // Total TRAILING (fin+1=12), sin candidato leading valido.
        app(CellDataStorageService::class)->saveCellData('P3C2', 'V', [
            'A12' => $this->cell(false, null, 'TOTAL'),
            'B12' => $this->cell(true, '=SUM(B10:B11)'),
        ]);
        $this->seedRemData('P3C2', 'V', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("no 'leading'", $output);
    }

    // ── Formula con huecos ───────────────────────────────────────────────

    public function test_formula_with_gaps_rejected(): void
    {
        // Candidato leading (9) toca ambos extremos [10:12] pero omite la
        // fila 11 -- registra como candidato en Fase 1 (touchesFrom &&
        // touchesTo), mecanismo #6 lo confirma (sin referencias hacia
        // atras), pero la verificacion independiente de cobertura completa
        // debe rechazarlo.
        $this->structure('P3C2', [$this->sectionDef('V', 8, 20, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 12);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10,B12)');
        $this->seedRemData('P3C2', 'V', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no cubre de forma completa', $output);
    }

    // ── Termino externo ──────────────────────────────────────────────────

    public function test_external_term_rejected(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 20, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 12);
        // Rango contiguo [10:12] MAS un termino externo (B18).
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B12)+B18');
        $this->seedRemData('P3C2', 'V', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertTrue(
            str_contains($output, 'no cubre de forma completa')
                || str_contains($output, 'no confirma la fila')
                || str_contains($output, 'Fase 1 no encontro un candidato'),
            "Salida inesperada: {$output}"
        );
    }

    // ── no_utilizada fuera de alcance ───────────────────────────────────

    public function test_no_utilizada_sheet_rejected(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P3C2', 'V', 10);

        DB::table('rem_sheet_usage_status')->insert([
            'anio' => 2026, 'serie' => 'A', 'sheet_name' => 'P3C2',
            'status' => 'no_utilizada', 'reason' => 'test', 'decided_by' => 'Test',
            'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no_utilizada', $output);
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    // ── Candidato ambiguo (leading y trailing validos a la vez) ─────────

    public function test_ambiguous_candidate_rejected(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 20, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B11)'); // from-1
        // Trailing tambien valido (to+1=12) -- ambos matches, Fase 1 no resuelve.
        app(CellDataStorageService::class)->saveCellData('P3C2', 'V', array_merge(
            app(CellDataStorageService::class)->loadCellData('P3C2', 'V'),
            [
                'A12' => $this->cell(false, null, 'TOTAL'),
                'B12' => $this->cell(true, '=SUM(B10:B11)'),
            ]
        ));
        $this->seedRemData('P3C2', 'V', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Fase 1 no encontro un candidato', $output);
    }

    // ── Fila reclamada por otra seccion ──────────────────────────────────

    public function test_row_claimed_by_other_section_rejected(): void
    {
        // Seccion V (5-9) y seccion W (9-15, la regla) se SOLAPAN en la
        // fila 9 (dato de estructura corrupto/anomalo) -- el candidato
        // leading de W (9) cae dentro de los limites propios de W (guard 7
        // pasa), pero la fila esta reclamada por MAS de una seccion, asi
        // que el guard 8 (unico dueno) debe rechazarla.
        $this->structure('P3C2', [
            $this->sectionDef('V', 5, 9, [$this->field('A'), $this->field('B')]),
            $this->sectionDef('W', 9, 15, [$this->field('A'), $this->field('B')]),
        ]);
        $rule = $this->verticalRule('P3C2', 'W', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'W', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P3C2', 'W', 10);

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reclamada por la seccion', $output);
    }

    // ── Categoria B/F (placeholder {0,0}) rechazada ─────────────────────

    public function test_category_b_zero_zero_placeholder_rejected(): void
    {
        $this->structure('P3C2', [$this->sectionDef('F1', 10, 20, [$this->field('A'), $this->field('F')])]);
        $rule = $this->zeroZeroRule('P3C2', 'F1', 'F');

        $exit = Artisan::call('rule:activate-category-c-leading', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no tiene row_range real', $output);
    }

    // ── Preservacion de datos ajenos ──────────────────────────────────

    public function test_commit_preserves_bindings_history_and_unrelated_data(): void
    {
        $this->structure('P3C2', [$this->sectionDef('V', 8, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C2', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P3C2', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P3C2', 'V', 10);
        $this->seedRemData('P3C2', 'V', 11);

        $otherRule = $this->verticalRule('P3C2', 'OTHER', 'C', 20, 21);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 999, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        $bindingsBefore = RuleBinding::count();
        $remDataBefore = RemData::orderBy('id')->get()->toArray();
        $otherConfigBefore = $otherRule->config;

        Artisan::call('rule:activate-category-c-leading', [
            'rule_id' => (string) $rule->id, '--reason' => 'test', '--by' => 'Tester', '--commit' => true,
        ]);

        $this->assertSame($bindingsBefore, RuleBinding::count(), 'no debe crear/eliminar bindings');
        $otherRule->refresh();
        $this->assertEquals($otherConfigBefore, $otherRule->config, 'regla ajena intacta');
        $this->assertEquals($remDataBefore, RemData::orderBy('id')->get()->toArray(), 'rem_data byte-identico');
    }
}
