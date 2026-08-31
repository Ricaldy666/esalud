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
 * Fase 3C-3A/3C-3B (CLAUDE.md punto 17.21/17.22/17.23). Cubre
 * rule:activate-source-rows -- activa config.source_rows + config.total_row
 * (+config.row_range solo en la Rama B1) derivados EXCLUSIVAMENTE de la
 * formula Excel real, para las 12 reglas reales de Categoria B1
 * (`A09/F.1`, 208/214) y B4 (`A26/B`, 393-402). Fixtures 100% sinteticas,
 * replicando exactamente los patrones reales ya auditados (puntos
 * 17.20/17.21) a escala reducida.
 */
class RuleActivateSourceRowsCommandTest extends TestCase
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
            'hash_estructura' => 'hash-source-rows-cmd-' . uniqid(),
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
            'rule_key' => $key ?? ('sr_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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
            'rule_key' => $key ?? ('sr_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
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

    private function seedRemData(string $sheet, string $section, int $rowNumber, string $column, ?int $value, int $uploadId): void
    {
        RemData::create([
            'rem_upload_id' => $uploadId, 'section' => $sheet,
            'data' => ['concept' => 'test', 'row_number' => $rowNumber, 'section' => $sheet, 'rem_section_code' => $section, 'total_column' => $column, 'values' => [$column => $value]],
        ]);
    }

    private function createUpload(): RemUpload
    {
        $healthCenter = HealthCenter::create(['name' => 'Test CESFAM SR', 'code_deis' => 'SR-' . uniqid(), 'type' => 'CESFAM', 'is_active' => true]);
        $user = User::factory()->create();

        return RemUpload::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'health_center_id' => $healthCenter->id, 'user_id' => $user->id,
            'rem_template_id' => null, 'year' => 2026, 'month' => 1, 'rem_type' => 'A', 'original_filename' => 'test.xlsm',
            'stored_path' => 'test/test.xlsm', 'file_size' => 100, 'mime_type' => 'application/vnd.ms-excel', 'status' => 'completed',
        ]);
    }

    // ── B1 valido (row_range={0,0} -- lista dispersa) ──────────────────

    public function test_b1_valid_dry_run(): void
    {
        $this->structure('PSRC', [$this->sectionDef('F1', 8, 16, [$this->field('A'), $this->field('B')])]);
        $rule = $this->zeroZeroRule('PSRC', 'F1', 'B');
        // Formula real: candidato en fila 16, referencia solo 9,10,13 (lista dispersa, no contigua).
        $this->seedBackwardTotal('PSRC', 'F1', 'B', 16, '=SUM(B9,B10,B13)');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC', 'F1', 9, 'B', 4, $upload->id);
        $this->seedRemData('PSRC', 'F1', 10, 'B', 9, $upload->id);
        $this->seedRemData('PSRC', 'F1', 13, 'B', 12, $upload->id);
        $this->seedRemData('PSRC', 'F1', 16, 'B', 25, $upload->id); // TOTAL real = 4+9+12

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('source_rows derivado: [9,10,13]', $output);
        $this->assertStringContainsString('row_range propuesto: [9:13]', $output);
        $this->assertStringContainsString('total_row propuesto: 16', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $rule->refresh();
        $this->assertArrayNotHasKey('source_rows', $rule->config, 'dry-run no debe escribir');
    }

    public function test_b1_valid_commit_writes_only_authorized_keys(): void
    {
        $this->structure('PSRC', [$this->sectionDef('F1', 8, 16, [$this->field('A'), $this->field('B')])]);
        $rule = $this->zeroZeroRule('PSRC', 'F1', 'B');
        $this->seedBackwardTotal('PSRC', 'F1', 'B', 16, '=SUM(B9,B10,B13)');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC', 'F1', 9, 'B', 4, $upload->id);
        $this->seedRemData('PSRC', 'F1', 10, 'B', 9, $upload->id);
        $this->seedRemData('PSRC', 'F1', 13, 'B', 12, $upload->id);
        $this->seedRemData('PSRC', 'F1', 16, 'B', 25, $upload->id);

        $exit = Artisan::call('rule:activate-source-rows', [
            'rule_id' => (string) $rule->id, '--reason' => 'test B1', '--by' => 'Tester', '--commit' => true,
        ]);
        $this->assertSame(0, $exit);

        $rule->refresh();
        $this->assertSame([9, 10, 13], $rule->config['source_rows']);
        $this->assertSame(['from' => 9, 'to' => 13], $rule->config['row_range']);
        $this->assertSame(16, $rule->config['total_row']);
        $this->assertSame('PSRC', $rule->config['sheet']);
        $this->assertSame('F1', $rule->config['section']);
        $this->assertSame('B', $rule->config['column']);
        $this->assertCount(7, $rule->config, 'config debe tener exactamente sheet/section/column/row_range/rule_logic/total_row/source_rows = 7 claves');

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('source_rows', $version->config);
        $this->assertArrayNotHasKey('total_row', $version->config);
        $this->assertSame(['from' => 0, 'to' => 0], $version->config['row_range']);

        $activity = Activity::where('description', 'rule_source_rows_activated')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame([9, 10, 13], $activity->properties['source_rows_set']);
        $this->assertSame(16, $activity->properties['total_row_set']);
        $this->assertTrue($activity->properties['range_changed']);
        $this->assertSame('test B1', $activity->properties['reason']);
        $this->assertSame('Tester', $activity->properties['by']);
    }

    // ── B4 valido (row_range real -- rango + termino externo) ──────────

    public function test_b4_valid_dry_run(): void
    {
        $this->structure('PSRC26', [$this->sectionDef('B', 8, 15, [$this->field('A'), $this->field('D')])]);
        $rule = $this->verticalRule('PSRC26', 'B', 'D', 10, 12, 'sr_b4_pass');
        // Formula real: candidato = to+1 = 13, cubre [10:12] completo + termino externo 9.
        $this->seedBackwardTotal('PSRC26', 'B', 'D', 13, '=SUM(D10:D12)+D9');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC26', 'B', 9, 'D', 3, $upload->id);
        $this->seedRemData('PSRC26', 'B', 10, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 11, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 12, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 13, 'D', 3, $upload->id); // TOTAL real = 3 (solo el termino externo aporta)

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('source_rows derivado: [9,10,11,12]', $output);
        $this->assertStringContainsString('row_range propuesto: [10:12] (sin cambio)', $output);
        $this->assertStringContainsString('total_row propuesto: 13', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    public function test_b4_valid_commit_row_range_unchanged(): void
    {
        $this->structure('PSRC26', [$this->sectionDef('B', 8, 15, [$this->field('A'), $this->field('D')])]);
        $rule = $this->verticalRule('PSRC26', 'B', 'D', 10, 12, 'sr_b4_commit');
        $this->seedBackwardTotal('PSRC26', 'B', 'D', 13, '=SUM(D10:D12)+D9');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC26', 'B', 9, 'D', 3, $upload->id);
        $this->seedRemData('PSRC26', 'B', 10, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 11, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 12, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 13, 'D', 3, $upload->id);

        $exit = Artisan::call('rule:activate-source-rows', [
            'rule_id' => (string) $rule->id, '--reason' => 'test B4', '--by' => 'Tester', '--commit' => true,
        ]);
        $this->assertSame(0, $exit);

        $rule->refresh();
        $this->assertSame([9, 10, 11, 12], $rule->config['source_rows']);
        $this->assertSame(['from' => 10, 'to' => 12], $rule->config['row_range'], 'row_range NUNCA debe tocarse en la Rama B4');
        $this->assertSame(13, $rule->config['total_row']);

        $activity = Activity::where('description', 'rule_source_rows_activated')->latest('id')->first();
        $this->assertFalse($activity->properties['range_changed']);
    }

    // ── Formula con hueco inesperado (B4, falta fila 11) ────────────────

    public function test_formula_with_unexpected_internal_gap_rejected(): void
    {
        $this->structure('PSRC26', [$this->sectionDef('B', 8, 15, [$this->field('A'), $this->field('D')])]);
        $rule = $this->verticalRule('PSRC26', 'B', 'D', 10, 12, 'sr_gap');
        // Salta la fila 11 -- hueco interno inesperado dentro del row_range declarado.
        $this->seedBackwardTotal('PSRC26', 'B', 'D', 13, '=SUM(D10)+D12+D9');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC26', 'B', 9, 'D', 3, $upload->id);
        $this->seedRemData('PSRC26', 'B', 10, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 12, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 13, 'D', 3, $upload->id);

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('hueco interno inesperado', $output);
        $this->assertStringContainsString('faltan las filas: 11', $output);
    }

    // ── Termino externo sin evidencia real (nunca capturado) ────────────

    public function test_external_term_without_any_evidence_rejected(): void
    {
        $this->structure('PSRC26', [$this->sectionDef('B', 8, 15, [$this->field('A'), $this->field('D')])]);
        $rule = $this->verticalRule('PSRC26', 'B', 'D', 10, 12, 'sr_no_evidence');
        $this->seedBackwardTotal('PSRC26', 'B', 'D', 13, '=SUM(D10:D12)+D9');
        $upload = $this->createUpload();
        // Fila 9 (termino externo) deliberadamente SIN ningun rem_data -- nunca capturada.
        $this->seedRemData('PSRC26', 'B', 10, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 11, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 12, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 13, 'D', 0, $upload->id);

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No existe evidencia real en rem_data para las filas 9', $output);
    }

    // ── source_rows mal derivado (candidato ambiguo, B1) ─────────────────

    public function test_ambiguous_candidate_in_b1_branch_rejected(): void
    {
        $this->structure('PSRC', [$this->sectionDef('F1', 8, 20, [$this->field('A'), $this->field('B')])]);
        $rule = $this->zeroZeroRule('PSRC', 'F1', 'B');
        // DOS filas candidatas validas -- ambiguedad, ninguna se elige.
        $this->seedBackwardTotal('PSRC', 'F1', 'B', 14, '=SUM(B9,B10)');
        app(CellDataStorageService::class)->saveCellData('PSRC', 'F1', array_merge(
            app(CellDataStorageService::class)->loadCellData('PSRC', 'F1'),
            ['A18' => $this->cell(false, null, 'TOTAL'), 'B18' => $this->cell(true, '=SUM(B11,B12)')]
        ));

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato UNICO', $output);
    }

    // ── Patron periodico (A09/I, Categoria B2/B3) rechazado en la Rama B1 ──

    public function test_periodic_pattern_like_a09_i_rejected_in_b1_branch(): void
    {
        // Replica reducida del hallazgo real (A09/I): comparte el
        // placeholder row_range={0,0} con B1, pero tiene VARIAS filas TOTAL
        // periodicas en la misma columna (aqui: 2, en vez de las 6 reales)
        // -- solo la primera tiene concepto "TOTAL" en columna A, la
        // segunda no tiene concepto propio pero SI tiene formula
        // continuando el mismo patron. Sin el guard anti-periodicidad, el
        // descubrimiento habria aceptado la primera como si fuera un
        // candidato B1 valido.
        $this->structure('PSRC09I', [$this->sectionDef('I', 8, 20, [$this->field('A'), $this->field('AM')])]);
        $rule = $this->zeroZeroRule('PSRC09I', 'I', 'AM');
        $svc = app(CellDataStorageService::class);
        $svc->saveCellData('PSRC09I', 'I', array_merge($svc->loadCellData('PSRC09I', 'I'), [
            'A15' => $this->cell(false, null, 'TOTAL'),
            'AM15' => $this->cell(true, '=AM9+AM11+AM13'),
            // fila 16: SIN concepto propio (A16 vacio), pero CON formula
            // continuando el mismo patron periodico -- exactamente el
            // patron real de A09/I filas 332-336.
            'AM16' => $this->cell(true, '=AM10+AM12+AM14'),
        ]));

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no se encontro un candidato UNICO', $output);
    }

    // ── total_row incorrecto (candidato fuera de limites, patron 461) ───

    public function test_candidate_outside_live_section_bounds_rejected(): void
    {
        $this->structure('PSRC26', [$this->sectionDef('B', 8, 12, [$this->field('A'), $this->field('D')])]);
        $rule = $this->verticalRule('PSRC26', 'B', 'D', 10, 12, 'sr_out_of_bounds');
        // Candidato = 13, pero filaFinDatos=12 -- fuera de limites (patron 461).
        // La formula/cell_data existe igual (residuo tecnico, mismo patron real).
        $this->seedBackwardTotal('PSRC26', 'B', 'D', 13, '=SUM(D10:D12)+D9');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC26', 'B', 9, 'D', 3, $upload->id);
        $this->seedRemData('PSRC26', 'B', 10, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 11, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 12, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 13, 'D', 3, $upload->id);

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cae fuera del rango vivo de la seccion', $output);
    }

    // ── Clasificacion post-cambio no SAFE (indices divergentes) ─────────

    public function test_post_change_classification_not_safe_rejected(): void
    {
        // Dos secciones 'B' con el MISMO codigo bajo la misma hoja (dato
        // corrupto deliberado) -- findRawSectionData() (usado por este
        // comando, y por su propio guard de limites) toma la PRIMERA
        // coincidencia; RuleBindingReconciliationService::buildSectionIndex()
        // (usado por classifySingleRule() al simular) toma la ULTIMA --
        // diseno real de ambos metodos, no un mock. Esto crea una
        // divergencia genuina entre lo que el guard de limites de ESTE
        // comando aprueba y lo que el clasificador simulado ve, ejercitando
        // el guard 11 de forma organica (nunca simulado manualmente).
        $structure = $this->structure('PSRCDUP', [
            $this->sectionDef('B', 54, 60, [$this->field('A'), $this->field('D')]), // primera -- la ve este comando (guard de limites propio)
            $this->sectionDef('B', 54, 58, [$this->field('A'), $this->field('D')]), // ultima -- la ve el clasificador al simular (fin=58, NO invalida rowsOk ya que to=58 no es mayor, pero SI deja total_row=59 fuera de bounds)
        ]);
        $rule = $this->verticalRule('PSRCDUP', 'B', 'D', 54, 58, 'sr_divergent');
        $this->seedBackwardTotal('PSRCDUP', 'B', 'D', 59, '=SUM(D54:D58)+D50');
        $upload = $this->createUpload();
        foreach ([54, 55, 56, 57, 58] as $r) {
            $this->seedRemData('PSRCDUP', 'B', $r, 'D', 0, $upload->id);
        }
        $this->seedRemData('PSRCDUP', 'B', 50, 'D', 3, $upload->id);
        $this->seedRemData('PSRCDUP', 'B', 59, 'D', 3, $upload->id);

        $exit = Artisan::call('rule:activate-source-rows', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no SAFE_1_TO_1', $output);
    }

    // ── Preservacion de datos ajenos ──────────────────────────────────

    public function test_commit_preserves_bindings_history_calibrations_and_unrelated_data(): void
    {
        $this->structure('PSRC26', [$this->sectionDef('B', 8, 15, [$this->field('A'), $this->field('D')])]);
        $rule = $this->verticalRule('PSRC26', 'B', 'D', 10, 12, 'sr_preserve');
        $this->seedBackwardTotal('PSRC26', 'B', 'D', 13, '=SUM(D10:D12)+D9');
        $upload = $this->createUpload();
        $this->seedRemData('PSRC26', 'B', 9, 'D', 3, $upload->id);
        $this->seedRemData('PSRC26', 'B', 10, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 11, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 12, 'D', 0, $upload->id);
        $this->seedRemData('PSRC26', 'B', 13, 'D', 3, $upload->id);

        $otherRule = $this->verticalRule('PSRC26', 'OTHER', 'C', 20, 21);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 999, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        $bindingsBefore = RuleBinding::count();
        $remDataBefore = RemData::orderBy('id')->get()->toArray();
        $otherConfigBefore = $otherRule->config;

        Artisan::call('rule:activate-source-rows', [
            'rule_id' => (string) $rule->id, '--reason' => 'test', '--by' => 'Tester', '--commit' => true,
        ]);

        $this->assertSame($bindingsBefore, RuleBinding::count(), 'no debe crear/eliminar bindings');
        $otherRule->refresh();
        $this->assertEquals($otherConfigBefore, $otherRule->config, 'regla ajena intacta');
        $this->assertEquals($remDataBefore, RemData::orderBy('id')->get()->toArray(), 'rem_data byte-identico');
    }
}
