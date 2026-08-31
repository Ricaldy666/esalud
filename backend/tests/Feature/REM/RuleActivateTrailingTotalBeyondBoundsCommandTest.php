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
 * Fase 3C-1B (CLAUDE.md punto 17.14/17.15). Cubre rule:activate-trailing-total-beyond-bounds
 * -- activa config.total_row EXCLUSIVAMENTE para el patron certificado
 * "TOTAL trailing tecnico excluido en filaFinDatos+1" (las 55 reales de
 * A31/A32/A33). Nunca toca row_range. Casos de control replican los
 * patrones ya auditados como NO aptos para confirmar rechazo sin caso
 * especial: huecos (208/214), termino externo (A26/B), leading (461),
 * placeholder {0,0} (Categoria B/F), no_utilizada, fila reclamada por
 * otra seccion, distancia distinta de fin+1.
 */
class RuleActivateTrailingTotalBeyondBoundsCommandTest extends TestCase
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
            'hash_estructura' => 'hash-trailing-bounds-' . uniqid(),
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
            'rule_key' => $key ?? ('tb_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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
            'rule_key' => $key ?? ('tb_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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

    private function seedBackwardTotal(string $sheet, string $section, string $column, int $totalRow, string $formulaText, string $concept = 'TOTAL'): void
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

    // ── Caso valido: trailing, candidato = fin+1 exacto ────────────────

    public function test_valid_trailing_fin_plus_one_pattern(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 12, '=SUM(B10+B11)'); // fin(11)+1=12
        $this->seedRemData('P3C1B', 'V', 10);
        $this->seedRemData('P3C1B', 'V', 11);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('total_row propuesto: 12', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config, 'dry-run no debe escribir');
    }

    public function test_valid_pattern_commit_writes_only_total_row(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1B', 'V', 10);
        $this->seedRemData('P3C1B', 'V', 11);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', [
            'rule_id' => (string) $rule->id, '--reason' => 'patron certificado 17.14', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);
        $this->assertSame(0, $exit);

        $rule->refresh();
        $this->assertSame(12, $rule->config['total_row']);
        $this->assertSame(['from' => 10, 'to' => 11], $rule->config['row_range'], 'row_range NUNCA debe tocarse en este comando');
        $this->assertSame('P3C1B', $rule->config['sheet']);
        $this->assertSame('V', $rule->config['section']);
        $this->assertSame('B', $rule->config['column']);
        $this->assertCount(6, $rule->config, 'config debe tener exactamente sheet/section/column/row_range/rule_logic/total_row = 6 claves');

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('total_row', $version->config);
        $this->assertSame(['from' => 10, 'to' => 11], $version->config['row_range']);

        $activity = Activity::where('description', 'rule_trailing_total_beyond_bounds_activated')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame(12, $activity->properties['total_row_set']);
        $this->assertSame(11, $activity->properties['fin']);
        $this->assertSame('patron certificado 17.14', $activity->properties['reason']);
        $this->assertSame('Administrador Esalud', $activity->properties['by']);
    }

    // ── Candidato distinto de fin+1 (aqui: fin es 15, candidato real = 12) ──

    public function test_candidate_not_exactly_fin_plus_one_rejected(): void
    {
        // Seccion mas ancha (10-15); la regla solo cubre [10:11], su
        // subtotal real (12) NO coincide con filaFinDatos(15)+1=16.
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 15, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1B', 'V', 10);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('NO es exactamente filaFinDatos+1', $output);
    }

    // ── excluded=false rechazado ────────────────────────────────────────

    public function test_excluded_false_rejected(): void
    {
        // La fila candidata (fin+1) es una celda genuinamente capturable
        // (editable, no bloqueada) -- isEmbeddedBackwardSubtotalRow()
        // debe devolver false.
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        app(CellDataStorageService::class)->saveCellData('P3C1B', 'V', [
            'A12' => $this->cell(false, null, 'Concepto real'),
            'B12' => $this->cell(false, null, null, true), // editable real, no formula
        ]);
        $this->seedRemData('P3C1B', 'V', 10);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        // Sin candidato descubierto por Fase 1 (no hay formula en absoluto) o excluded=false explicito.
        $this->assertTrue(
            str_contains($output, 'Fase 1 no encontro un candidato') || str_contains($output, 'NO esta excluido de rem_data'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Formula con huecos (patron 208/214) ────────────────────────────

    public function test_formula_with_gaps_rejected(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 14, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 14);
        // Suma filas especificas, no el rango completo [10:14].
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 15, '=SUM(B10,B12,B14)');
        $this->seedRemData('P3C1B', 'V', 10);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertTrue(
            str_contains($output, 'no cubre de forma completa') || str_contains($output, 'no confirma la fila'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Termino externo (patron A26/B) ─────────────────────────────────

    public function test_external_term_rejected(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 12, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 12);
        // Rango contiguo [10:12] MAS un termino externo (B5).
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 13, '=SUM(B10:B12)+B5');
        $this->seedRemData('P3C1B', 'V', 10);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        // La referencia externa (B5) hace que ni siquiera la Fase 1
        // (discoverTotalRowCandidate) encuentre un candidato -- rechazado
        // en el mismo punto que A26/B en el sistema real (ver punto 17.9).
        $this->assertTrue(
            str_contains($output, 'no cubre de forma completa')
                || str_contains($output, 'no confirma la fila')
                || str_contains($output, 'Fase 1 no encontro un candidato'),
            "Salida inesperada: {$output}"
        );
    }

    // ── Fila reclamada por otra seccion ──────────────────────────────────

    public function test_row_claimed_by_next_section_rejected(): void
    {
        // Seccion V (10-11) seguida INMEDIATAMENTE por seccion W (12-15) --
        // la fila 12 (candidato trailing de V) es en realidad la PRIMERA
        // fila real de W, no un TOTAL huerfano.
        $this->structure('P3C1B', [
            $this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')]),
            $this->sectionDef('W', 12, 15, [$this->field('A'), $this->field('B')]),
        ]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        // Aunque exista una formula "backward" en 12, la fila pertenece a W.
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1B', 'V', 10);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reclamada por la seccion', $output);
    }

    // ── Leading / regla estilo 461 rechazada ───────────────────────────

    public function test_leading_position_rejected_like_461(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('X')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'X', 10, 11);
        // Total LIDER antes del rango (fila 9).
        app(CellDataStorageService::class)->saveCellData('P3C1B', 'V', [
            'A9' => $this->cell(false, null, 'TOTAL'),
            'X9' => $this->cell(true, '=SUM(X10:X11)'),
        ]);
        $this->seedRemData('P3C1B', 'V', 9);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("no 'trailing'", $output);
    }

    // ── Categoria B (placeholder {0,0}, ej. 208/214) rechazada ─────────

    public function test_category_b_zero_zero_placeholder_rejected(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('F1', 10, 20, [$this->field('A'), $this->field('F')])]);
        $rule = $this->zeroZeroRule('P3C1B', 'F1', 'F');

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no tiene row_range real', $output);
    }

    // ── Categoria F (mismo placeholder, ej. A09/I con anomalias) rechazada ──

    public function test_category_f_zero_zero_placeholder_rejected(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('I', 249, 336, [$this->field('A'), $this->field('AQ')])]);
        $rule = $this->zeroZeroRule('P3C1B', 'I', 'AQ');

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no tiene row_range real', $output);
    }

    // ── no_utilizada fuera de alcance ───────────────────────────────────

    public function test_no_utilizada_sheet_rejected(): void
    {
        $structure = $this->structure('P3C1B', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1B', 'V', 10);

        DB::table('rem_sheet_usage_status')->insert([
            'anio' => 2026, 'serie' => 'A', 'sheet_name' => 'P3C1B',
            'status' => 'no_utilizada', 'reason' => 'test', 'decided_by' => 'Test',
            'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $exit = Artisan::call('rule:activate-trailing-total-beyond-bounds', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("no_utilizada", $output);
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    // ── Preservacion de datos ajenos ──────────────────────────────────

    public function test_commit_preserves_bindings_history_and_unrelated_data(): void
    {
        $this->structure('P3C1B', [$this->sectionDef('V', 10, 11, [$this->field('A'), $this->field('B')])]);
        $rule = $this->verticalRule('P3C1B', 'V', 'B', 10, 11);
        $this->seedBackwardTotal('P3C1B', 'V', 'B', 12, '=SUM(B10+B11)');
        $this->seedRemData('P3C1B', 'V', 10);
        $this->seedRemData('P3C1B', 'V', 11);

        $otherRule = $this->verticalRule('P3C1B', 'OTHER', 'C', 20, 21);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 999, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        $bindingsBefore = RuleBinding::count();
        $remDataBefore = RemData::orderBy('id')->get()->toArray();
        $otherConfigBefore = $otherRule->config;

        Artisan::call('rule:activate-trailing-total-beyond-bounds', [
            'rule_id' => (string) $rule->id, '--reason' => 'test', '--by' => 'Tester', '--commit' => true,
        ]);

        $this->assertSame($bindingsBefore, RuleBinding::count(), 'no debe crear/eliminar bindings');
        $otherRule->refresh();
        $this->assertEquals($otherConfigBefore, $otherRule->config, 'regla ajena intacta');
        $this->assertEquals($remDataBefore, RemData::orderBy('id')->get()->toArray(), 'rem_data byte-identico');
    }
}
