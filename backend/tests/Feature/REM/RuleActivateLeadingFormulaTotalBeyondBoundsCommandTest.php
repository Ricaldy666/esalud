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
 * CLAUDE.md punto 17.49. Cubre rule:activate-leading-formula-total-beyond-bounds
 * -- activa config.total_row EXCLUSIVAMENTE para el patron "TOTAL leading
 * tecnico excluido en filaInicioDatos-1, sin etiqueta textual" (patron real
 * de la regla 461, A30/F). Mirror exacto de
 * rule:activate-trailing-total-beyond-bounds (Fase 3C-1B) para la direccion
 * opuesta. Nunca toca row_range.
 */
class RuleActivateLeadingFormulaTotalBeyondBoundsCommandTest extends TestCase
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
            'hash_estructura' => 'hash-leading-bounds-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => $sheet, 'sections' => $sections],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function sectionDef(string $codigo, int $inicio, int $fin, array $fields): array
    {
        return ['codigo' => $codigo, 'titulo' => $codigo, 'filaHeader' => $inicio - 2, 'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin, 'fields' => $fields];
    }

    private function verticalRule(string $sheet, string $section, string $column, int $from, int $to, string $key = null): Rule
    {
        return Rule::create([
            'rule_key' => $key ?? ('lb_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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
            'rule_key' => $key ?? ('lb_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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

    private function seedLeadingTotal(string $sheet, string $section, string $column, int $totalRow, string $formulaText): void
    {
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData($sheet, $section, array_merge(
            $svc->loadCellData($sheet, $section),
            [
                // SIN etiqueta textual, bloqueada (patron 461 exacto: A123
                // real es es_editable=false/esta_bloqueada=true) -- editable
                // explicito en false, nunca el default (!$formula=true).
                "A{$totalRow}" => $this->cell(false, null, null, false),
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

    // ── Caso valido: leading, candidato = inicio-1 exacto, SIN etiqueta ──

    public function test_valid_leading_formula_based_pattern(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10:B11)'); // inicio(10)-1=9
        $this->seedRemData('P49', 'V', 10);
        $this->seedRemData('P49', 'V', 11);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
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
        $this->structure('P49', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P49', 'V', 10);
        $this->seedRemData('P49', 'V', 11);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', [
            'rule_id' => (string) $rule->id, '--reason' => 'patron 461, punto 17.46/17.49', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);
        $this->assertSame(0, $exit);

        $rule->refresh();
        $this->assertSame(9, $rule->config['total_row']);
        $this->assertSame(['from' => 10, 'to' => 11], $rule->config['row_range'], 'row_range NUNCA debe tocarse en este comando');
        $this->assertCount(6, $rule->config, 'config debe tener exactamente sheet/section/column/row_range/rule_logic/total_row = 6 claves');

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('total_row', $version->config);

        $activity = Activity::where('description', 'rule_leading_formula_total_beyond_bounds_activated')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame(9, $activity->properties['total_row_set']);
        $this->assertSame(10, $activity->properties['inicio']);
        $this->assertSame('patron 461, punto 17.46/17.49', $activity->properties['reason']);
        $this->assertSame('Administrador Esalud', $activity->properties['by']);
    }

    // ── Candidato distinto de inicio-1 rechazado ───────────────────────

    public function test_candidate_not_exactly_inicio_minus_one_rejected(): void
    {
        // Seccion mas ancha (5-11); la regla solo cubre [10:11], su total
        // real (9) NO coincide con filaInicioDatos(5)-1=4.
        $this->structure('P49', [$this->sectionDef('V', 5, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P49', 'V', 10);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('NO es exactamente filaInicioDatos-1', $output);
    }

    // ── Formula con huecos rechazada ────────────────────────────────────

    public function test_formula_with_gaps_rejected(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 14, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 14);
        // Suma filas especificas, no el rango completo [10:14].
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10,B12,B14)');
        $this->seedRemData('P49', 'V', 10);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertTrue(
            str_contains($output, 'no cubre de forma completa') || str_contains($output, 'no confirma la fila'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Referencia externa (otra columna) rechazada ────────────────────

    public function test_external_column_reference_rejected(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 12, [$this->field('A'), $this->field('B'), $this->field('C')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 12);
        // Formula de la propia columna B referencia columna C en vez de B.
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(C10:C12)');
        $this->seedRemData('P49', 'V', 10);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertTrue(
            str_contains($output, 'no cubre de forma completa') || str_contains($output, 'no confirma la fila'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Fila reclamada por otra seccion (precedente) ───────────────────

    public function test_row_claimed_by_preceding_section_rejected(): void
    {
        // Seccion W (5-9) seguida INMEDIATAMENTE por seccion V (10-11) --
        // la fila 9 (candidato leading de V) es en realidad la ULTIMA fila
        // real de W, no un TOTAL huerfano.
        $this->structure('P49', [
            $this->sectionDef('W', 5, 9, [$this->field('A'), $this->field('B')]),
            $this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')]),
        ]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P49', 'V', 10);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reclamada por la seccion', $output);
    }

    // ── Trailing / patron ya cerrado por Fase 3C-1B rechazado ──────────

    public function test_trailing_position_rejected(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('X')])]);
        $rule = $this->verticalRule('P49', 'V', 'X', 10, 11);
        // Total TRAILING despues del rango (fila 12) -- patron de Fase 3C-1B, no de este comando.
        app(CellDataStorageService::class)->saveCellData('P49', 'V', [
            'A12' => $this->cell(false, null, 'TOTAL'),
            'X12' => $this->cell(true, '=SUM(X10:X11)'),
        ]);
        $this->seedRemData('P49', 'V', 10);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("no 'leading'", $output);
    }

    // ── Categoria B/F (placeholder {0,0}) rechazada ────────────────────

    public function test_zero_zero_placeholder_rejected(): void
    {
        $this->structure('P49', [$this->sectionDef('I', 249, 336, [$this->field('A'), $this->field('AQ')])]);
        $rule = $this->zeroZeroRule('P49', 'I', 'AQ');

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no tiene row_range real', $output);
    }

    // ── no_utilizada fuera de alcance ───────────────────────────────────

    public function test_no_utilizada_sheet_rejected(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P49', 'V', 10);

        DB::table('rem_sheet_usage_status')->insert([
            'anio' => 2026, 'serie' => 'A', 'sheet_name' => 'P49',
            'status' => 'no_utilizada', 'reason' => 'test', 'decided_by' => 'Test',
            'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no_utilizada', $output);
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    // ── Fila normal (dato real editable) rechazada ─────────────────────

    public function test_normal_editable_row_rejected(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        app(CellDataStorageService::class)->saveCellData('P49', 'V', [
            'A9' => $this->cell(false, null, 'Concepto real'),
            'B9' => $this->cell(false, null, null, true), // editable real, no formula
        ]);
        $this->seedRemData('P49', 'V', 10);

        $exit = Artisan::call('rule:activate-leading-formula-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertTrue(
            str_contains($output, 'Fase 1 no encontro un candidato') || str_contains($output, 'no confirma la fila'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Preservacion de datos ajenos ────────────────────────────────────

    public function test_commit_preserves_bindings_history_and_unrelated_data(): void
    {
        $this->structure('P49', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P49', 'V', 'B', 10, 11);
        $this->seedLeadingTotal('P49', 'V', 'B', 9, '=SUM(B10:B11)');
        $this->seedRemData('P49', 'V', 10);
        $this->seedRemData('P49', 'V', 11);

        $otherRule = $this->verticalRule('P49', 'OTHER', 'C', 20, 21);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 999, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        $bindingsBefore = RuleBinding::count();
        $remDataBefore = RemData::orderBy('id')->get()->toArray();
        $otherConfigBefore = $otherRule->config;

        Artisan::call('rule:activate-leading-formula-total-beyond-bounds', [
            'rule_id' => (string) $rule->id, '--reason' => 'test', '--by' => 'Tester', '--commit' => true,
        ]);

        $this->assertSame($bindingsBefore, RuleBinding::count(), 'no debe crear/eliminar bindings');
        $otherRule->refresh();
        $this->assertEquals($otherConfigBefore, $otherRule->config, 'regla ajena intacta');
        $this->assertEquals($remDataBefore, RemData::orderBy('id')->get()->toArray(), 'rem_data byte-identico');
    }
}
