<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Cubre rule:remap-section -- unico punto de entrada para cambiar
 * config.section de UNA regla individual hacia un destino verificado.
 * Dry-run por defecto, --commit exigido para persistir. Nunca crea
 * bindings (responsabilidad separada de rule:rebind-safe-to-structure).
 * Ver auditoria A32/F regla 529, 2026-08-27.
 */
class RuleRemapSectionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function dummyField(string $letra): array
    {
        return ['letra' => $letra, 'label' => "Campo {$letra}", 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function activeStructure(array $forms): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-remap-' . uniqid(),
            'estructura' => ['forms' => $forms],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function section(string $codigo, int $inicio, int $fin, array $letras): array
    {
        return [
            'codigo' => $codigo, 'titulo' => $codigo, 'filaHeader' => $inicio - 1,
            'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin,
            'fields' => array_map(fn ($l) => $this->dummyField($l), $letras),
        ];
    }

    private function remapRule(): Rule
    {
        $rule = Rule::create([
            'rule_key' => 'a32_f_b_sum_equals', 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'TOTAL ACCIONES',
            'description' => 'test', 'category' => 'A32', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A32', 'section' => 'F', 'column' => 'B', 'row_range' => ['from' => 6, 'to' => 6], 'rule_logic' => 'Suma(C + D) = Columna B'],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        RuleBinding::create(['rule_id' => $rule->id, 'bindable_type' => 'structure', 'bindable_id' => 19, 'serie' => 'A', 'anio' => 2026, 'active' => true]);

        return $rule;
    }

    public function test_valid_remap_dry_run_reports_safe_1_to_1(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();

        $exit = Artisan::call('rule:remap-section', ['rule_id' => (string) $rule->id, 'new_section' => 'F1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SAFE_1_TO_1', $output);
        $this->assertStringContainsString('A32/F1', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    public function test_nonexistent_destination_section_fails(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('G', 5, 8, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();

        $exit = Artisan::call('rule:remap-section', ['rule_id' => (string) $rule->id, 'new_section' => 'F1']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no existe en la estructura activa', Artisan::output());
        $rule->refresh();
        $this->assertSame('F', $rule->config['section']);
    }

    public function test_missing_required_column_in_destination_fails(): void
    {
        $this->activeStructure([
            // Falta la columna D, requerida por la regla (Suma(C + D) = B).
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C'])]],
        ]);
        $rule = $this->remapRule();

        $exit = Artisan::call('rule:remap-section', ['rule_id' => (string) $rule->id, 'new_section' => 'F1']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Columnas faltantes', $output);
        $this->assertStringContainsString('D', $output);
    }

    public function test_row_range_outside_destination_fails(): void
    {
        $this->activeStructure([
            // Columnas correctas, pero la fila 6 de la regla cae fuera de [20:25].
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 20, 25, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();

        $exit = Artisan::call('rule:remap-section', ['rule_id' => (string) $rule->id, 'new_section' => 'F1']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no cae dentro del rango vivo', Artisan::output());
    }

    public function test_ambiguous_destination_fails(): void
    {
        // 2 secciones que satisfacen ambas columnas+row_range -- ambiguedad
        // real, no solo la reportada contra la seccion historica "F" ausente.
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [
                $this->section('F1', 5, 8, ['B', 'C', 'D']),
                $this->section('F2', 3, 10, ['B', 'C', 'D']),
            ]],
        ]);
        $rule = $this->remapRule();

        $exit = Artisan::call('rule:remap-section', ['rule_id' => (string) $rule->id, 'new_section' => 'F1']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Ambiguedad real', Artisan::output());
        $rule->refresh();
        $this->assertSame('F', $rule->config['section'], 'no debe remapear ante ambiguedad real, aunque el destino solicitado sea uno de los candidatos');
    }

    public function test_nonexistent_rule_fails(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C', 'D'])]],
        ]);

        $exit = Artisan::call('rule:remap-section', ['rule_id' => '999999', 'new_section' => 'F1']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No existe ninguna regla', Artisan::output());
    }

    public function test_dry_run_never_writes(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();
        $originalConfig = $rule->config;
        $originalUpdatedAt = $rule->updated_at;

        Artisan::call('rule:remap-section', [
            'rule_id' => (string) $rule->id, 'new_section' => 'F1', '--reason' => 'motivo', '--by' => 'Estadística APS',
        ]);

        $rule->refresh();
        $this->assertEquals($originalConfig, $rule->config);
        $this->assertEquals($originalUpdatedAt->timestamp, $rule->updated_at->timestamp);
        $this->assertSame(0, RuleVersion::where('rule_id', $rule->id)->count());
        $this->assertSame(0, \Spatie\Activitylog\Models\Activity::where('description', 'rule_config_remap_section')->count());
    }

    public function test_commit_changes_only_config_section(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();
        $originalConfig = $rule->config;

        $exit = Artisan::call('rule:remap-section', [
            'rule_id' => (string) $rule->id, 'new_section' => 'F1', '--reason' => 'evidencia auditoria 2026-08-27', '--by' => 'Estadística APS', '--commit' => true,
        ]);

        $this->assertSame(0, $exit);
        $rule->refresh();

        $this->assertSame('F1', $rule->config['section']);
        $expectedConfig = $originalConfig;
        $expectedConfig['section'] = 'F1';
        $this->assertEquals($expectedConfig, $rule->config, 'ninguna otra clave de config debe cambiar');
        $this->assertSame('sum_equals', $rule->rule_type);
        $this->assertSame('active', $rule->status);
        $this->assertSame('TOTAL ACCIONES', $rule->name);

        $version = RuleVersion::where('rule_id', $rule->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertSame('F', $version->config['section'], 'el snapshot debe guardar el config ANTERIOR (F), no el nuevo');
        $this->assertStringContainsString('Estadística APS', $version->changelog, 'el responsable queda registrado en el changelog, no en created_by (foreignId hacia users)');

        $activity = \Spatie\Activitylog\Models\Activity::where('description', 'rule_config_remap_section')->first();
        $this->assertNotNull($activity);
        $this->assertSame('F', $activity->properties['old_section']);
        $this->assertSame('F1', $activity->properties['new_section']);
        $this->assertSame('evidencia auditoria 2026-08-27', $activity->properties['reason']);
        $this->assertSame('Estadística APS', $activity->properties['by']);
    }

    public function test_commit_requires_reason_and_by(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();

        $exit = Artisan::call('rule:remap-section', ['rule_id' => (string) $rule->id, 'new_section' => 'F1', '--commit' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('obligatorios', Artisan::output());
        $rule->refresh();
        $this->assertSame('F', $rule->config['section']);
    }

    public function test_no_binding_calibration_or_unrelated_rule_is_touched(): void
    {
        $this->activeStructure([
            ['sheetName' => 'A32', 'sections' => [$this->section('F1', 5, 8, ['B', 'C', 'D'])]],
        ]);
        $rule = $this->remapRule();

        $unrelated = Rule::create([
            'rule_key' => 'unrelated_rule', 'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'x',
            'description' => 'x', 'category' => 'A09', 'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A09', 'section' => 'K', 'column' => 'B', 'row_range' => ['from' => 1, 'to' => 1], 'rule_logic' => 'Suma(C) = Columna B'],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
        $unrelatedConfig = $unrelated->config;
        $unrelatedBinding = RuleBinding::create(['rule_id' => $unrelated->id, 'bindable_type' => 'structure', 'bindable_id' => 19, 'serie' => 'A', 'anio' => 2026, 'active' => true]);

        $bindingCountBefore = RuleBinding::count();

        Artisan::call('rule:remap-section', [
            'rule_id' => (string) $rule->id, 'new_section' => 'F1', '--reason' => 'motivo', '--by' => 'Estadística APS', '--commit' => true,
        ]);

        $unrelated->refresh();
        $this->assertEquals($unrelatedConfig, $unrelated->config, 'una regla ajena no debe modificarse');

        $unrelatedBinding->refresh();
        $this->assertTrue($unrelatedBinding->active, 'un binding ajeno no debe modificarse');

        $oldBindingOfRemapped = RuleBinding::where('rule_id', $rule->id)->where('bindable_id', 19)->first();
        $this->assertNotNull($oldBindingOfRemapped);
        $this->assertTrue($oldBindingOfRemapped->active, 'el binding antiguo (estructura 19) de la regla remapeada no debe tocarse');

        $this->assertSame($bindingCountBefore, RuleBinding::count(), 'rule:remap-section jamas crea/borra bindings');
    }
}
