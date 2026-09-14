<?php

namespace Tests\Feature\REM;

use App\Domain\REM\Models\RemTemplate;
use App\Domain\RemParser\Exceptions\RemTemplateConfigGenerationException;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemTemplateConfigGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BM-3.5 (2026-09-14): cubre RemTemplateConfigGeneratorService::generateAndPersist()
 * -- las partes que SI requieren BD real (idempotencia, enlace
 * rem_template_id, atomicidad). RefreshDatabase, BD de testing, nunca
 * esalud_dev. Los casos puros de construccion del config viven en
 * RemTemplateConfigGeneratorServiceTest (Unit, sin BD).
 */
class RemTemplateConfigGeneratorServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RemTemplateConfigGeneratorService
    {
        return new RemTemplateConfigGeneratorService();
    }

    private function validEstructura(): array
    {
        return [
            'forms' => [
                [
                    'sheetName' => 'BS01',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                ['letra' => 'A', 'label' => 'PROCEDIMIENTO', 'esTotal' => false, 'esControlOculto' => false],
                                ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createStructure(array $overrides = []): RemTemplateStructure
    {
        return RemTemplateStructure::create(array_merge([
            'serie' => 'BS',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => $this->validEstructura(),
            'hash_estructura' => 'hash_config_gen_test_' . uniqid(),
            'status' => 'active',
        ], $overrides));
    }

    // ── G) Enlace + creacion ──────────────────────────────────────────────

    public function test_creates_new_template_and_links_structure(): void
    {
        $structure = $this->createStructure();

        $template = $this->service()->generateAndPersist($structure);

        $this->assertSame(2026, $template->year);
        $this->assertSame('BS', $template->rem_type);
        $this->assertCount(1, $template->config['sheets']);
        $this->assertSame('BS01', $template->config['sheets'][0]['sheet_name']);

        $structure->refresh();
        $this->assertSame($template->id, $structure->rem_template_id);
    }

    // ── F) Idempotencia ───────────────────────────────────────────────────

    public function test_running_twice_does_not_duplicate_template(): void
    {
        $structure = $this->createStructure();

        $first = $this->service()->generateAndPersist($structure);
        $second = $this->service()->generateAndPersist($structure->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RemTemplate::where('year', 2026)->where('rem_type', 'BS')->count());
        $this->assertEquals($first->config, $second->config);
    }

    public function test_existing_template_only_config_is_updated_other_fields_preserved(): void
    {
        $existing = RemTemplate::create([
            'year' => 2026,
            'rem_type' => 'BS',
            'version' => 'V1.1',
            'is_active' => true,
            'config' => ['description' => 'REM BS - Salud Bucal', 'sheets' => []],
        ]);

        $structure = $this->createStructure();

        $template = $this->service()->generateAndPersist($structure);

        $this->assertSame($existing->id, $template->id);
        $this->assertSame('V1.1', $template->version, 'version no debe tocarse al regenerar config');
        $this->assertTrue((bool) $template->is_active);
        $this->assertCount(1, $template->config['sheets'], 'config si debe actualizarse');
    }

    // ── H) Atomicidad ─────────────────────────────────────────────────────

    public function test_failure_during_generation_leaves_no_partial_write(): void
    {
        $estructuraSinTotal = [
            'forms' => [
                [
                    'sheetName' => 'BS02',
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                ['letra' => 'A', 'label' => 'SIN TOTAL', 'esTotal' => false, 'esControlOculto' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $structure = $this->createStructure(['estructura' => $estructuraSinTotal]);

        try {
            $this->service()->generateAndPersist($structure);
            $this->fail('Se esperaba RemTemplateConfigGenerationException');
        } catch (RemTemplateConfigGenerationException) {
            // esperado
        }

        $this->assertSame(0, RemTemplate::where('year', 2026)->where('rem_type', 'BS')->count());
        $structure->refresh();
        $this->assertNull($structure->rem_template_id);
    }

    public function test_superseded_structure_is_rejected_before_any_write(): void
    {
        $structure = $this->createStructure(['status' => 'superseded']);

        try {
            $this->service()->generateAndPersist($structure);
            $this->fail('Se esperaba RemTemplateConfigGenerationException');
        } catch (RemTemplateConfigGenerationException) {
            // esperado
        }

        $this->assertSame(0, RemTemplate::where('year', 2026)->where('rem_type', 'BS')->count());
    }

    // ── Comando (delegacion, no duplica bateria del Service) ─────────────

    public function test_command_delegates_to_service_and_succeeds(): void
    {
        $structure = $this->createStructure();

        $this->artisan('rem:generate-template-config', ['structure_id' => $structure->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('BS01');

        $template = RemTemplate::where('year', 2026)->where('rem_type', 'BS')->first();
        $this->assertNotNull($template);
        $this->assertCount(1, $template->config['sheets']);

        $structure->refresh();
        $this->assertSame($template->id, $structure->rem_template_id);
    }

    public function test_command_reports_failure_without_throwing_for_invalid_structure(): void
    {
        $structure = $this->createStructure(['status' => 'superseded']);

        $this->artisan('rem:generate-template-config', ['structure_id' => $structure->id])
            ->assertExitCode(1);

        $this->assertSame(0, RemTemplate::where('year', 2026)->where('rem_type', 'BS')->count());
    }

    public function test_command_reports_error_for_missing_structure(): void
    {
        $this->artisan('rem:generate-template-config', ['structure_id' => 999999])
            ->assertExitCode(1);
    }
}
