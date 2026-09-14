<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Services\RemParserService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * BM-3.6 (2026-09-14): confirma con BD real (RefreshDatabase, nunca
 * esalud_dev) que, con el fix aplicado, buildSectionMaps() SI encuentra
 * la RemTemplateStructure activa de una serie de 2 letras -- antes del
 * fix, serieFromRemType('BM')='B' hacia que la consulta interna
 * (serie='B') nunca encontrara nada, dejando sectionMap vacio para
 * cualquier upload BM/BS, sin importar que tan bien calibrada estuviera
 * la estructura real.
 *
 * IMPORTANTE (corregido en el gate de commit BM-3.5/3.6, 2026-09-14):
 * las fixtures usan nombres de hoja deliberadamente FICTICIOS
 * ('ZZTEST18'/'ZZTESTBS01', nunca 'BM18'/'BS01' reales) -- CellDataStorageService
 * lee cell_data del FILESYSTEM local (storage/app/private/certificacion/cell-data/),
 * independiente de que BD este activa (esalud_testing aqui). Usar un
 * nombre de hoja REAL como fixture sintetica colisiona con cell_data
 * genuino si esa hoja ya fue escaneada alguna vez en esta maquina (ej.
 * BM18, escaneada en BM-4.2) -- el test terminaria leyendo datos reales
 * de la plantilla en vez de la fixture minima, con resultados
 * impredecibles. Mismo criterio ya usado por CrossSheetEqualsIntegrationTest
 * ('SheetA'/'SheetB').
 */
class RemParserServiceSerieFromRemTypeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createHealthCenter(): int
    {
        return HealthCenter::create([
            'name' => 'Test Center',
            'code_deis' => 'TC' . uniqid(),
            'type' => 'CESFAM',
        ])->id;
    }

    private function createUpload(string $remType): RemUpload
    {
        return RemUpload::create([
            'rem_type' => $remType,
            'year' => 2026,
            'month' => 5,
            'status' => 'pending',
            'health_center_id' => $this->createHealthCenter(),
            'user_id' => User::factory()->create()->id,
            'original_filename' => "test_{$remType}.xlsm",
            'stored_path' => "rem/2026/05/test_{$remType}.xlsm",
            'file_size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function estructuraConSeccion(string $sheetName): array
    {
        return [
            'forms' => [
                [
                    'sheetName' => $sheetName,
                    'sections' => [
                        [
                            'codigo' => 'A',
                            'filaHeader' => 8,
                            'filaInicioDatos' => 9,
                            'filaFinDatos' => 20,
                            'fields' => [
                                ['letra' => 'A', 'label' => 'CONCEPTO', 'esTotal' => false, 'esControlOculto' => false],
                                ['letra' => 'B', 'label' => 'TOTAL', 'esTotal' => true, 'esControlOculto' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildSectionMaps(RemUpload $upload): array
    {
        $service = new RemParserService();
        $method = new ReflectionMethod($service, 'buildSectionMaps');
        $method->setAccessible(true);

        return $method->invoke($service, $upload);
    }

    public function test_bm_upload_resolves_its_own_active_bm_structure(): void
    {
        RemTemplateStructure::create([
            'serie' => 'BM',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => $this->estructuraConSeccion('ZZTEST18'),
            'hash_estructura' => 'hash_bm36_' . uniqid(),
            'status' => 'active',
        ]);

        $upload = $this->createUpload('BM');

        $maps = $this->buildSectionMaps($upload);

        $this->assertArrayHasKey('ZZTEST18', $maps, 'buildSectionMaps() debe encontrar la estructura BM activa (antes del fix, siempre devolvia []).');
        $this->assertCount(1, $maps['ZZTEST18']);
        $this->assertSame('A', $maps['ZZTEST18'][0]['code']);
        $this->assertSame('A', $maps['ZZTEST18'][0]['concept_column']);
        $this->assertSame('B', $maps['ZZTEST18'][0]['total_column']);
    }

    public function test_bs_upload_resolves_its_own_active_bs_structure_not_bm(): void
    {
        RemTemplateStructure::create([
            'serie' => 'BM',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => $this->estructuraConSeccion('ZZTEST18'),
            'hash_estructura' => 'hash_bm36_bm_' . uniqid(),
            'status' => 'active',
        ]);
        RemTemplateStructure::create([
            'serie' => 'BS',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => $this->estructuraConSeccion('ZZTESTBS01'),
            'hash_estructura' => 'hash_bm36_bs_' . uniqid(),
            'status' => 'active',
        ]);

        $upload = $this->createUpload('BS');

        $maps = $this->buildSectionMaps($upload);

        $this->assertArrayHasKey('ZZTESTBS01', $maps, 'Un upload BS debe resolver la estructura BS, nunca la BM (antes del fix ambas colisionaban en serie=B).');
        $this->assertArrayNotHasKey('ZZTEST18', $maps);
    }

    public function test_bm_upload_without_active_bm_structure_still_returns_empty(): void
    {
        // Sin ninguna RemTemplateStructure BM -- debe seguir devolviendo []
        // (comportamiento correcto preexistente, no relacionado al bug).
        $upload = $this->createUpload('BM');

        $maps = $this->buildSectionMaps($upload);

        $this->assertSame([], $maps);
    }
}
