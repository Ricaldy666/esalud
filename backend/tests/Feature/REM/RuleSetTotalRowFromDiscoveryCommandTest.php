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
use Tests\TestCase;

/**
 * Cubre rule:set-total-row -- completa config.total_row EXCLUSIVAMENTE a
 * partir del candidato descubierto por la Fase 1 de auto-discovery. Dry-run
 * por defecto, --commit exigido para persistir. Fase 2, punto 16.13-16.14.
 * Caso de control central: replica el hallazgo real de la regla 461
 * (A30/F) -- candidato leading fuera de [filaInicioDatos:filaFinDatos] debe
 * rechazarse por el guard 7, sin ninguna excepcion especial.
 */
class RuleSetTotalRowFromDiscoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function dummyField(string $letra): array
    {
        return ['letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function structureWithSection(string $sheet, string $section, int $inicio, int $fin, array $fields): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-total-row-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => $sheet, 'sections' => [[
                    'codigo' => $section, 'titulo' => $section, 'filaHeader' => $inicio - 1,
                    'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin, 'fields' => $fields,
                ]]],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function verticalRule(string $sheet, string $section, string $column, int $from, int $to, ?string $status = 'active', string $key = null): Rule
    {
        return Rule::create([
            'rule_key' => $key ?? ('test_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_' . uniqid()),
            'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'test', 'description' => 'test',
            'category' => $sheet, 'severity' => 'error', 'scope' => 'per_row',
            'config' => [
                'sheet' => $sheet, 'section' => $section, 'column' => $column,
                'row_range' => ['from' => $from, 'to' => $to],
                'rule_logic' => "Suma({$column}) = Columna {$column}",
            ],
            'status' => $status, 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    private function cell(bool $formula, ?string $formulaText = null, ?string $valorBruto = null): array
    {
        return ['valor_bruto' => $valorBruto, 'es_editable' => !$formula, 'esta_bloqueada' => $formula, 'es_formula' => $formula, 'formula' => $formulaText];
    }

    private function seedLeadingTotal(string $sheet, string $section, string $column, int $leadingRow, int $from, int $to, string $conceptLabel = 'TOTAL'): void
    {
        app(CellDataStorageService::class)->saveCellData($sheet, $section, [
            "A{$leadingRow}" => $this->cell(false, null, $conceptLabel),
            "{$column}{$leadingRow}" => $this->cell(true, "=SUM({$column}{$from}:{$column}{$to})"),
        ]);
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

    public function test_valid_case_dry_run_and_commit(): void
    {
        $this->structureWithSection('TST1', 'A', 9, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST1', 'A', 'X', 10, 11);
        $this->seedLeadingTotal('TST1', 'A', 'X', 9, 10, 11);
        $this->seedRemData('TST1', 'A', 9);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('total_row propuesto: 9', $output);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config, 'dry-run no debe escribir');

        $exit2 = Artisan::call('rule:set-total-row', [
            'rule_id' => (string) $rule->id, '--reason' => 'candidato Fase 1 confirmado', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);
        $this->assertSame(0, $exit2);
        $rule->refresh();
        $this->assertSame(9, $rule->config['total_row']);
    }

    public function test_461_style_candidate_outside_section_range_rejected_by_guard_7(): void
    {
        // Replica exacta del hallazgo real: filaInicioDatos=124, candidato
        // leading=123 (fuera de rango), formula real y evidencia real
        // presentes -- debe rechazarse SOLO por el guard 7, sin excepcion.
        $this->structureWithSection('TST2', 'F', 124, 129, [$this->dummyField('A'), $this->dummyField('B')]);
        $rule = $this->verticalRule('TST2', 'F', 'B', 124, 129);
        $this->seedLeadingTotal('TST2', 'F', 'B', 123, 124, 129, 'Telecomite de especialidad');
        $this->seedRemData('TST2', 'F', 123);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('fuera del rango vivo de la seccion', Artisan::output());
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }

    public function test_candidate_excluded_rejected(): void
    {
        $this->structureWithSection('TST3', 'A', 19, 25, [$this->dummyField('A'), $this->dummyField('Y')]);
        $rule = $this->verticalRule('TST3', 'A', 'Y', 20, 25);
        // "TOTAL" literal -> mecanismo #6 SI la detecta -> excluded=true.
        $this->seedLeadingTotal('TST3', 'A', 'Y', 19, 20, 25, 'TOTAL');
        $this->seedRemData('TST3', 'A', 19);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('excluido de rem_data', Artisan::output());
    }

    public function test_trailing_position_rejected(): void
    {
        $this->structureWithSection('TST4', 'A', 10, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST4', 'A', 'X', 10, 11);
        // Formula trailing (row_to+1=12), no leading.
        app(CellDataStorageService::class)->saveCellData('TST4', 'A', [
            'C12' => $this->cell(false, null, 'TOTAL'),
            'X12' => $this->cell(true, '=SUM(X10+X11)'),
        ]);
        $this->seedRemData('TST4', 'A', 12);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("'trailing', no 'leading'", Artisan::output());
    }

    public function test_no_candidate_rejected(): void
    {
        $this->structureWithSection('TST5', 'A', 100, 106, [$this->dummyField('A'), $this->dummyField('B')]);
        $rule = $this->verticalRule('TST5', 'A', 'B', 100, 105);
        // Sin cell-data en absoluto.

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no encontro un candidato unico', Artisan::output());
    }

    public function test_total_row_already_present_rejected(): void
    {
        $this->structureWithSection('TST6', 'A', 10, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST6', 'A', 'X', 10, 11);
        // total_row presente pero FUERA de [10:12] -- la regla sigue
        // BLOCKED_BY_ENGINE_GAP (guard 2 pasa), permitiendo verificar que
        // el guard 3 ("ya tiene total_row") es el que realmente rechaza,
        // no una clasificacion distinta.
        $rule->update(['config' => array_merge($rule->config, ['total_row' => 999])]);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('ya tiene total_row', Artisan::output());
    }

    public function test_inactive_rule_rejected(): void
    {
        $this->structureWithSection('TST7', 'A', 10, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST7', 'A', 'X', 10, 11, status: 'inactive');

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no esta activa', Artisan::output());
    }

    public function test_wrong_classification_rejected(): void
    {
        // Regla horizontal (columna origen != destino) -- clasifica
        // SAFE_1_TO_1 directamente, nunca BLOCKED_BY_ENGINE_GAP.
        $this->structureWithSection('TST8', 'A', 10, 12, [$this->dummyField('C'), $this->dummyField('D'), $this->dummyField('E')]);
        $rule = Rule::create([
            'rule_key' => 'test_horizontal', 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'test', 'description' => 'test',
            'category' => 'TST8', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'TST8', 'section' => 'A', 'column' => 'C', 'row_range' => ['from' => 10, 'to' => 10], 'rule_logic' => 'Suma(D+E) = Columna C'],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no BLOCKED_BY_ENGINE_GAP', Artisan::output());
    }

    public function test_missing_persisted_evidence_rejected(): void
    {
        $this->structureWithSection('TST9', 'A', 9, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST9', 'A', 'X', 10, 11);
        $this->seedLeadingTotal('TST9', 'A', 'X', 9, 10, 11);
        // Sin seedRemData -- sin evidencia real.

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No existe evidencia real en rem_data', Artisan::output());
    }

    /**
     * Guard 10 (colision funcional) es estructuralmente redundante con el
     * guard 2 en la practica: si YA existe otra regla activa con la misma
     * clave sheet+seccion+columna+tipo, classifySingleRule() la clasifica
     * DUPLICATE desde el principio -- nunca llega a BLOCKED_BY_ENGINE_GAP,
     * asi que se rechaza en el guard 2, no en el 10. Este test documenta y
     * verifica ese comportamiento real (defensa en profundidad), en vez de
     * fabricar un escenario que no ocurre en la practica.
     */
    public function test_collision_with_another_active_rule_rejected_via_duplicate_classification(): void
    {
        $this->structureWithSection('TST10', 'A', 10, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST10', 'A', 'X', 10, 11, key: 'test_tst10_a_x_one');
        $this->verticalRule('TST10', 'A', 'X', 10, 11, key: 'test_tst10_a_x_two');
        $this->seedLeadingTotal('TST10', 'A', 'X', 9, 10, 11);
        $this->seedRemData('TST10', 'A', 9);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("'DUPLICATE'", Artisan::output());
    }

    public function test_commit_changes_only_total_row_and_preserves_everything_else(): void
    {
        $this->structureWithSection('TST11', 'A', 9, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST11', 'A', 'X', 10, 11);
        $this->seedLeadingTotal('TST11', 'A', 'X', 9, 10, 11);
        $this->seedRemData('TST11', 'A', 9);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 19, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        $originalConfig = $rule->config;
        $bindingCountBefore = RuleBinding::where('rule_id', $rule->id)->count();
        $remDataCountBefore = RemData::count();

        $exit = Artisan::call('rule:set-total-row', [
            'rule_id' => (string) $rule->id, '--reason' => 'motivo', '--by' => 'Administrador Esalud', '--commit' => true,
        ]);

        $this->assertSame(0, $exit);
        $rule->refresh();

        $expectedConfig = $originalConfig;
        $expectedConfig['total_row'] = 9;
        $this->assertEquals($expectedConfig, $rule->config, 'solo total_row debe agregarse, el resto byte-identico');
        $this->assertSame('active', $rule->status);
        $this->assertSame('sum_equals', $rule->rule_type);

        $this->assertSame($bindingCountBefore, RuleBinding::where('rule_id', $rule->id)->count(), 'bindings intactos');
        $this->assertSame($remDataCountBefore, RemData::count(), 'rem_data intacto (ninguna fila nueva/borrada)');

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayNotHasKey('total_row', $version->config, 'el snapshot debe guardar el config ANTERIOR, sin total_row');

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'rule_total_row_set')->first();
        $this->assertNotNull($activity);
        $this->assertSame(9, $activity->properties['total_row_set']);
        $this->assertSame('leading', $activity->properties['total_row_position']);
        $this->assertSame('motivo', $activity->properties['reason']);
        $this->assertSame('Administrador Esalud', $activity->properties['by']);
    }

    public function test_commit_requires_reason_and_by(): void
    {
        $this->structureWithSection('TST12', 'A', 9, 12, [$this->dummyField('C'), $this->dummyField('X')]);
        $rule = $this->verticalRule('TST12', 'A', 'X', 10, 11);
        $this->seedLeadingTotal('TST12', 'A', 'X', 9, 10, 11);
        $this->seedRemData('TST12', 'A', 9);

        $exit = Artisan::call('rule:set-total-row', ['rule_id' => (string) $rule->id, '--commit' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('obligatorios', Artisan::output());
        $rule->refresh();
        $this->assertArrayNotHasKey('total_row', $rule->config);
    }
}
