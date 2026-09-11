<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\Calibration\Models\Calibration;
use App\Domain\Calibration\Services\CalibrationService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\CellScanOrchestrator;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * REM BM -- FASE BM-2: GENERALIZACION MULTI-SERIE CONTROLADA (2026-09-11).
 *
 * Cubre exclusivamente el transporte/resolucion generica de `serie` en los
 * 4 servicios generalizados (CalibrationService, CellScanOrchestrator,
 * CertificationService, SectionCalibrationMatrixService) y en la capa
 * HTTP (routes/api.php + CatalogController/CalibrationViewController).
 *
 * NO prueba logica especifica de BM -- BM sigue, deliberadamente, sin
 * estructura/reglas/bindings reales (ver CLAUDE.md, "BM debe permanecer en
 * 0 estructuras"). Las fixtures "BM" de este archivo son estructuras
 * SINTETICAS creadas solo dentro de cada test (RefreshDatabase), nunca
 * datos persistentes ni parte de ninguna migracion/seeder.
 */
class MultiSerieTransportTest extends TestCase
{
    use RefreshDatabase;

    private function structureFor(string $serie, string $sheet, string $section = 'X'): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => $serie,
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => "hash-multiserie-{$serie}-" . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => $sheet,
                        'sections' => [
                            [
                                'codigo' => $section,
                                'titulo' => "SECCION {$section} DE PRUEBA",
                                'filaHeader' => 9,
                                'filaInicioDatos' => 10,
                                'filaFinDatos' => 11,
                                'fields' => [
                                    ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                                    ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);
    }

    private function authenticatedAdmin(): User
    {
        if (! Role::where('name', 'Superadmin')->exists()) {
            Role::create(['name' => 'Superadmin']);
        }

        $user = User::factory()->create();
        $user->assignRole('Superadmin');
        Sanctum::actingAs($user);

        return $user;
    }

    // ── TEST A: Serie A sin cambios ─────────────────────────────────────

    public function test_a_serie_a_request_resolves_structure_a_unchanged(): void
    {
        $structureA = $this->structureFor('A', 'BM2SHEETA');
        $user = User::factory()->create();

        $service = app(CalibrationService::class);

        $draftExplicit = $service->createDraft($user, 'A');
        $this->assertSame($structureA->id, $draftExplicit->structure_id);
        $this->assertSame('A', $draftExplicit->serie);

        // El default de compatibilidad historica (sin pasar serie) sigue
        // resolviendo exactamente lo mismo que antes de BM-2.
        $otherUser = User::factory()->create();
        $draftDefault = $service->createDraft($otherUser);
        $this->assertSame($structureA->id, $draftDefault->structure_id);
        $this->assertSame('A', $draftDefault->serie);
    }

    // ── TEST B: aislamiento entre A y BM ─────────────────────────────────

    public function test_b_isolation_between_series_a_and_bm(): void
    {
        $structureA = $this->structureFor('A', 'BM2SHEETA');
        $structureBM = $this->structureFor('BM', 'BM2SHEETBM');

        $service = app(CalibrationService::class);

        $draftA = $service->createDraft(User::factory()->create(), 'A');
        $draftBM = $service->createDraft(User::factory()->create(), 'BM');

        $this->assertSame($structureA->id, $draftA->structure_id);
        $this->assertSame($structureBM->id, $draftBM->structure_id);
        $this->assertNotSame($draftA->structure_id, $draftBM->structure_id);
        $this->assertSame('A', $draftA->serie);
        $this->assertSame('BM', $draftBM->serie);
    }

    // ── TEST C: prohibicion de fallback BM -> A ──────────────────────────

    public function test_c_bm_without_active_structure_never_falls_back_to_a(): void
    {
        // Solo Serie A tiene estructura activa -- BM no existe en absoluto.
        $this->structureFor('A', 'BM2SHEETA');

        $service = app(CalibrationService::class);

        try {
            $service->createDraft(User::factory()->create(), 'BM');
            $this->fail('Se esperaba DomainException al pedir serie BM sin estructura activa.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('serie BM', $e->getMessage());
        }

        // Ninguna calibracion quedo creada como efecto secundario del
        // intento fallido -- en particular, NUNCA se creo una calibracion
        // apuntando (por error) a la estructura de Serie A.
        $this->assertSame(0, Calibration::count());
    }

    // ── TEST D: SectionCalibrationMatrixService usa la serie pedida ─────

    public function test_d_section_calibration_matrix_service_uses_requested_serie(): void
    {
        Storage::fake('local');
        $this->structureFor('A', 'BM2SHEETA', 'X');
        $this->structureFor('BM', 'BM2SHEETBM', 'X');

        $matrixService = app(SectionCalibrationMatrixService::class);

        $matrixA = $matrixService->buildMatrix('BM2SHEETA', 'X', 'A');
        $this->assertSame('ok', $matrixA['section']['status']);
        $this->assertSame('X', $matrixA['section']['codigo']);

        $matrixBM = $matrixService->buildMatrix('BM2SHEETBM', 'X', 'BM');
        $this->assertSame('ok', $matrixBM['section']['status']);
        $this->assertSame('X', $matrixBM['section']['codigo']);

        // La hoja de Serie A pedida bajo serie=BM no debe resolverse --
        // cada resolucion usa EXCLUSIVAMENTE la estructura de la serie
        // pedida, nunca "la" unica estructura activa a secas.
        $crossSerie = $matrixService->buildMatrix('BM2SHEETA', 'X', 'BM');
        $this->assertSame('not_found', $crossSerie['section']['status']);
    }

    // ── TEST E: CellScanOrchestrator selecciona la estructura de la serie ─

    public function test_e_cell_scan_orchestrator_selects_structure_for_requested_serie(): void
    {
        $structureA = $this->structureFor('A', 'BM2SHEETA');
        $structureBM = $this->structureFor('BM', 'BM2SHEETBM');

        $orchestrator = app(CellScanOrchestrator::class);
        $reflection = new ReflectionClass($orchestrator);
        $method = $reflection->getMethod('findActiveStructureId');
        $method->setAccessible(true);

        $this->assertSame($structureA->id, $method->invoke($orchestrator, 'A'));
        $this->assertSame($structureBM->id, $method->invoke($orchestrator, 'BM'));

        // BS no tiene estructura activa -- debe fallar explicitamente, sin
        // devolver por error el id de A ni de BM.
        try {
            $method->invoke($orchestrator, 'BS');
            $this->fail('Se esperaba RuntimeException al pedir serie BS sin estructura activa.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Serie BS', $e->getMessage());
        }
    }

    // ── TEST F: CertificationService -- aislamiento equivalente ─────────

    public function test_f_certification_service_isolation_between_series(): void
    {
        $structureA = $this->structureFor('A', 'BM2SHEETA');
        $structureBM = $this->structureFor('BM', 'BM2SHEETBM');

        $certificationService = app(CertificationService::class);

        $cardA = $certificationService->getStructureForCard('A');
        $this->assertNotNull($cardA);
        $this->assertSame($structureA->version_number, $cardA['version']);
        $this->assertSame($structureA->hash_estructura, $cardA['hash']);
        $this->assertSame('A', $cardA['serie']);

        $cardBM = $certificationService->getStructureForCard('BM');
        $this->assertNotNull($cardBM);
        $this->assertSame('BM', $cardBM['serie']);

        $sheetsA = $certificationService->getAvailableSheets('A');
        $this->assertSame(['BM2SHEETA'], $sheetsA);

        $sheetsBM = $certificationService->getAvailableSheets('BM');
        $this->assertSame(['BM2SHEETBM'], $sheetsBM);

        // BS sin estructura activa -- respuesta controlada (null/[]), nunca
        // la tarjeta o las hojas de Serie A.
        $this->assertNull($certificationService->getStructureForCard('BS'));
        $this->assertSame([], $certificationService->getAvailableSheets('BS'));
    }

    // ── TEST G: API -- serie invalida => 4xx controlado ──────────────────

    public function test_g_invalid_serie_in_api_route_returns_controlled_4xx(): void
    {
        $this->authenticatedAdmin();

        // 'XX' no pertenece a MetadataExtractorService::TIPOS_REM
        // (A/BM/BS/D/P) -- el ->where() del grupo de rutas lo rechaza antes
        // de llegar a ningun controlador (misma convencion "ruta no
        // encontrada" ya usada en todo el proyecto).
        $response = $this->getJson('/api/v1/rule-engine/catalog/XX/calibration-summary');
        $response->assertStatus(404);
    }

    // ── TEST H (frontend) ────────────────────────────────────────────────
    // TypeScript obliga a transportar `serie` explicitamente en
    // calibrationService/certificationService/functionalRuleService (ver
    // frontend/src/features/rule-engine/services/*.ts) -- cualquier caller
    // que lo omita falla la compilacion. Validado en esta fase con
    // `tsc -b` (modo build, el mismo que usa `npm run build`) y
    // `vite build`, ambos limpios tras generalizar los 22 callers
    // afectados. No existe infraestructura de tests de componentes React
    // en este proyecto (sin Vitest/Testing Library configurado) -- se
    // documenta aqui en vez de introducir un framework de pruebas nuevo
    // fuera de alcance de BM-2.
}
