<?php

namespace Tests\Feature\Rem;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportCertifiedPromotionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedCertifiedLikeState(): array
    {
        $old = RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'version_number' => 1,
            'hash_estructura' => 'hash-vieja', 'estructura' => ['x' => 1], 'status' => 'superseded',
        ]);
        $active = RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'version_number' => 2,
            'hash_estructura' => 'hash-certificada', 'estructura' => ['x' => 2], 'status' => 'active',
        ]);

        $r1 = Rule::create(['rule_key' => 'r1', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog', 'name' => 'r1', 'category' => 'A01', 'severity' => 'error', 'scope' => 'per_row', 'config' => [], 'status' => 'active', 'version' => '1.0.0']);
        $r2 = Rule::create(['rule_key' => 'r2', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog', 'name' => 'r2', 'category' => 'A01', 'severity' => 'error', 'scope' => 'per_row', 'config' => [], 'status' => 'active', 'version' => '1.0.0']);

        RuleVersion::create(['rule_id' => $r1->id, 'version' => '1.0.0', 'config' => []]);

        // 2 bindings a la estructura ACTIVA (deben quedar en el paquete)
        RuleBinding::create(['rule_id' => $r1->id, 'bindable_type' => 'structure', 'bindable_id' => $active->id, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        RuleBinding::create(['rule_id' => $r2->id, 'bindable_type' => 'structure', 'bindable_id' => $active->id, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        // 1 binding estructura-agnostico (debe quedar en el paquete)
        RuleBinding::create(['rule_id' => $r1->id, 'bindable_type' => 'serie', 'bindable_id' => null, 'serie' => 'A', 'anio' => 2026, 'active' => true]);
        // 1 binding a la estructura VIEJA (debe EXCLUIRSE del paquete)
        RuleBinding::create(['rule_id' => $r2->id, 'bindable_type' => 'structure', 'bindable_id' => $old->id, 'serie' => 'A', 'anio' => 2026, 'active' => true]);

        return compact('old', 'active', 'r1', 'r2');
    }

    public function test_export_produces_package_with_expected_shape_and_counts(): void
    {
        $this->seedCertifiedLikeState();
        $out = storage_path('framework/testing/certified-promotion-test.json');

        $this->artisan('rem:export-certified-promotion', ['--out' => $out])
            ->assertExitCode(0);

        $this->assertFileExists($out);
        $package = json_decode(file_get_contents($out), true);

        $this->assertSame('certified_structure_promotion', $package['package_type']);
        $this->assertSame(2, $package['counts']['rules']);
        $this->assertSame(1, $package['counts']['rule_versions']);
        $this->assertSame(3, $package['counts']['bindings']); // 2 structure + 1 serie, la de la vieja excluida
        $this->assertSame(1, $package['counts']['excluded_bindings']);
        $this->assertSame('hash-certificada', $package['structure']['hash_estructura']);

        // Ningun binding debe llevar un ID/clave local de la estructura --
        // solo el marcador que se resuelve en destino.
        foreach ($package['bindings'] as $b) {
            $this->assertArrayNotHasKey('bindable_id', $b);
            $this->assertArrayNotHasKey('bindable_structure', $b);
            if ($b['bindable_type'] === 'structure') {
                $this->assertSame('certified_structure', $b['bindable_target']);
            } else {
                $this->assertNull($b['bindable_target']);
            }
        }

        @unlink($out);
    }

    public function test_export_aborts_when_no_active_structure_exists(): void
    {
        $this->artisan('rem:export-certified-promotion', ['--out' => storage_path('framework/testing/should-not-exist.json')])
            ->assertExitCode(1);

        $this->assertFileDoesNotExist(storage_path('framework/testing/should-not-exist.json'));
    }
}
