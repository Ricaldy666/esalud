<?php

namespace Tests\Feature\Rem;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemPromoteCertifiedStructureCommandTest extends TestCase
{
    use RefreshDatabase;

    private function writePackage(array $overrides = []): string
    {
        $base = [
            'package_type' => 'certified_structure_promotion',
            'generated_at' => now()->toIso8601String(),
            'source_structure' => ['anio' => 2026, 'serie' => 'A', 'version_number' => 35],
            'counts' => ['rules' => 1, 'rule_versions' => 0, 'bindings' => 1, 'excluded_bindings' => 0],
            'structure' => [
                'anio' => 2026, 'serie' => 'A', 'version_number' => 35,
                'hash_estructura' => 'hash-cmd-test', 'estructura' => ['x' => 1], 'metadata' => null,
                'source_filename' => null, 'notes' => null, 'approved_at' => null, 'approved_by_email' => null,
            ],
            'rules' => [
                ['rule_key' => 'r1', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog', 'name' => 'r1', 'description' => null, 'category' => 'A01', 'severity' => 'error', 'scope' => 'per_row', 'config' => [], 'status' => 'active', 'version' => '1.0.0', 'metadata' => null, 'created_by_email' => null, 'updated_by_email' => null],
            ],
            'rule_versions' => [],
            'bindings' => [
                ['rule_key' => 'r1', 'bindable_type' => 'serie', 'bindable_target' => null, 'serie' => 'A', 'anio' => 2026, 'conditions' => null, 'active' => true],
            ],
        ];

        $package = array_replace_recursive($base, $overrides);
        $path = storage_path('framework/testing/promote-cmd-test-'.uniqid().'.json');
        file_put_contents($path, json_encode($package));

        return $path;
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $path = $this->writePackage();

        $this->artisan('rem:promote-certified-structure', ['--package' => $path])
            ->assertExitCode(0);

        $this->assertSame(0, RemTemplateStructure::count());
        $this->assertSame(0, Rule::count());

        @unlink($path);
    }

    public function test_commit_without_approved_by_fails(): void
    {
        $path = $this->writePackage();

        $this->artisan('rem:promote-certified-structure', ['--package' => $path, '--commit' => true])
            ->assertExitCode(1);

        $this->assertSame(0, RemTemplateStructure::count());

        @unlink($path);
    }

    public function test_commit_with_approved_by_persists(): void
    {
        $approver = User::factory()->create();
        $path = $this->writePackage();

        $this->artisan('rem:promote-certified-structure', [
            '--package' => $path,
            '--commit' => true,
            '--approved-by' => $approver->email,
        ])->assertExitCode(0);

        $this->assertSame(1, RemTemplateStructure::count());
        $this->assertSame('active', RemTemplateStructure::first()->status);
        $this->assertSame(1, Rule::count());
        $this->assertSame(1, RuleBinding::count());

        @unlink($path);
    }

    public function test_missing_package_file_fails_cleanly(): void
    {
        $this->artisan('rem:promote-certified-structure', ['--package' => storage_path('framework/testing/no-existe.json')])
            ->assertExitCode(1);
    }
}
