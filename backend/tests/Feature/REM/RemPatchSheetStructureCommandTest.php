<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Tests\TestCase;

/**
 * Cubre rem:patch-sheet-structure con --sections=F,G: reemplaza UNICAMENTE
 * las secciones solicitadas dentro de la hoja indicada, dejando byte-
 * identico tanto el resto de esa hoja (A, B, C, D, E, F.1, F.2, G.1, H, I,
 * J, K) como las otras 26 hojas de la estructura.
 *
 * Caso real que motiva esta version del comando: reemplazar la hoja A09
 * completa (version anterior de este comando) tambien alteraba la Seccion
 * K (su filaFinDatos pasaba de 358 a 385) sin que filterAggregators()
 * tuviera relacion con eso -- K no tiene subseccion "K.1" en A09, por lo
 * que ese cambio no correspondia al bug corregido ni al alcance autorizado.
 */
class RemPatchSheetStructureCommandTest extends TestCase
{
    use RefreshDatabase;

    private function realSourcePath(): string
    {
        return storage_path('app/rem-uploads/2026/01/1/20260529190913_SA_26_V1.2-2.xlsm');
    }

    /** @return array<int, array{letra:string,label:string,esTotal:bool,esControlOculto:bool,reglaDetectada:null}> */
    private function dummyFields(int $count): array
    {
        $fields = [];
        for ($i = 1; $i <= $count; $i++) {
            $letra = Coordinate::stringFromColumnIndex($i);
            $fields[] = [
                'letra' => $letra,
                'label' => "Dummy {$letra}",
                'esTotal' => false,
                'esControlOculto' => false,
                'reglaDetectada' => null,
            ];
        }

        return $fields;
    }

    private function dummySection(string $codigo, int $filaHeader, int $filaInicioDatos, int $filaFinDatos, int $fieldCount): array
    {
        return [
            'codigo' => $codigo,
            'titulo' => "SECCION {$codigo} DE PRUEBA",
            'filaHeader' => $filaHeader,
            'filaInicioDatos' => $filaInicioDatos,
            'filaFinDatos' => $filaFinDatos,
            'fields' => $this->dummyFields($fieldCount),
        ];
    }

    /**
     * Estructura base minima que imita el estado historico real de A09
     * (id 36): F truncada a 40 campos, G truncada a 42 campos, F.1/F.2/G.1
     * presentes, y K con su filaFinDatos real reportado como afectado por
     * el bug del comando anterior (348-358). A01 es un control ajeno que
     * nunca debe tocarse.
     */
    private function createBaseStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-base-de-prueba',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'A01',
                        'sections' => [
                            $this->dummySection('A', 9, 10, 12, 3),
                        ],
                    ],
                    [
                        'sheetName' => 'A09',
                        'sections' => [
                            $this->dummySection('A', 9, 10, 17, 5),
                            $this->dummySection('D', 69, 70, 81, 5),
                            $this->dummySection('E', 83, 84, 86, 3),
                            $this->dummySection('F', 88, 91, 92, 40),
                            $this->dummySection('F.1', 145, 146, 158, 5),
                            $this->dummySection('F.2', 160, 161, 174, 6),
                            $this->dummySection('G', 176, 177, 180, 42),
                            $this->dummySection('G.1', 229, 230, 237, 4),
                            $this->dummySection('H', 239, 240, 246, 3),
                            $this->dummySection('K', 347, 348, 358, 5),
                        ],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => basename($this->realSourcePath()),
            'status' => 'active',
        ]);
    }

    private function baseArgs(RemTemplateStructure $base, array $overrides = []): array
    {
        return array_merge([
            'structure_id' => $base->id,
            '--serie' => 'A',
            '--year' => '2026',
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ], $overrides);
    }

    // --- Validaciones de aborto ---

    public function test_aborts_when_sections_option_is_missing(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--sections' => null]));

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('--sections', Artisan::output());
    }

    public function test_aborts_when_requested_section_does_not_exist_in_base_structure(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--sections' => 'F,Z']));

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString("'Z'", Artisan::output());
    }

    public function test_aborts_when_base_structure_not_found(): void
    {
        $exit = Artisan::call('rem:patch-sheet-structure', [
            'structure_id' => 999999,
            '--serie' => 'A',
            '--year' => '2026',
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('no encontrada', Artisan::output());
    }

    public function test_aborts_when_serie_or_year_mismatch_with_base_structure(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--year' => '2099']));

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('no coincide', Artisan::output());
    }

    public function test_aborts_when_source_file_not_found(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, [
            '--source' => storage_path('app/rem-uploads/no-existe-este-archivo.xlsm'),
        ]));

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('no encontrado', Artisan::output());
    }

    public function test_aborts_when_sheet_not_found_in_source_file(): void
    {
        $base = $this->createBaseStructure();

        $baseEst = $base->estructura;
        $baseEst['forms'][] = [
            'sheetName' => 'A09-INEXISTENTE',
            'sections' => [$this->dummySection('F', 1, 2, 3, 1), $this->dummySection('G', 5, 6, 7, 1)],
        ];
        $base->estructura = $baseEst;
        $base->save();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--sheet' => 'A09-INEXISTENTE']));

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('no encontrada en el archivo fuente', Artisan::output());
    }

    public function test_aborts_when_sheet_not_found_in_base_structure(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--sheet' => 'A08', '--sections' => 'A']));

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('no existe en la estructura base', Artisan::output());
    }

    // --- Comportamiento dry-run ---

    public function test_dry_run_does_not_persist_anything(): void
    {
        $base = $this->createBaseStructure();
        $countBefore = RemTemplateStructure::count();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base));

        $this->assertEquals(0, $exit);
        $this->assertEquals($countBefore, RemTemplateStructure::count(), 'dry-run no debe crear ninguna fila nueva');

        $base->refresh();
        $this->assertEquals('hash-base-de-prueba', $base->hash_estructura, 'dry-run no debe modificar la estructura base existente');
        $fSection = collect($base->estructura['forms'][1]['sections'])->firstWhere('codigo', 'F');
        $this->assertCount(40, $fSection['fields'], 'dry-run no debe modificar el contenido de F en la estructura base');
    }

    // --- Correctitud del contenido parcheado ---

    public function test_f_changes_from_40_to_50_fields_and_reaches_ax(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a09 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A09');
        $f = collect($a09['sections'])->firstWhere('codigo', 'F');

        $this->assertCount(50, $f['fields'], 'F debe pasar de 40 a 50 campos');
        $this->assertSame('AX', end($f['fields'])['letra'], 'F debe llegar hasta AX');
    }

    public function test_g_changes_from_42_to_50_fields_and_reaches_ax(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a09 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A09');
        $g = collect($a09['sections'])->firstWhere('codigo', 'G');

        $this->assertCount(50, $g['fields'], 'G debe pasar de 42 a 50 campos');
        $this->assertSame('AX', end($g['fields'])['letra'], 'G debe llegar hasta AX');
    }

    public function test_f1_f2_g1_remain_identical(): void
    {
        $base = $this->createBaseStructure();
        $originalA09 = $base->estructura['forms'][1];
        $originalByCode = collect($originalA09['sections'])->keyBy('codigo');

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newByCode = collect(collect($new->estructura['forms'])->firstWhere('sheetName', 'A09')['sections'])->keyBy('codigo');

        foreach (['F.1', 'F.2', 'G.1'] as $codigo) {
            $this->assertSame(
                json_encode($originalByCode[$codigo]),
                json_encode($newByCode[$codigo]),
                "La seccion {$codigo} debe quedar byte-identica"
            );
        }
    }

    public function test_k_remains_identical_including_fila_inicio_and_fin_datos(): void
    {
        $base = $this->createBaseStructure();
        $originalK = collect($base->estructura['forms'][1]['sections'])->firstWhere('codigo', 'K');

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newK = collect(collect($new->estructura['forms'])->firstWhere('sheetName', 'A09')['sections'])->firstWhere('codigo', 'K');

        $this->assertSame(348, $newK['filaInicioDatos']);
        $this->assertSame(358, $newK['filaFinDatos'], 'K no debe pasar a 385 -- ese cambio no corresponde al alcance autorizado (solo F y G)');
        $this->assertSame(json_encode($originalK), json_encode($newK), 'K debe quedar completamente byte-identica');
    }

    public function test_all_other_a09_sections_remain_byte_identical(): void
    {
        $base = $this->createBaseStructure();
        $originalA09Sections = collect($base->estructura['forms'][1]['sections'])->keyBy('codigo');

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newA09Sections = collect(collect($new->estructura['forms'])->firstWhere('sheetName', 'A09')['sections'])->keyBy('codigo');

        foreach (['A', 'D', 'E', 'F.1', 'F.2', 'G.1', 'H', 'K'] as $codigo) {
            $this->assertSame(
                json_encode($originalA09Sections[$codigo]),
                json_encode($newA09Sections[$codigo]),
                "La seccion {$codigo} de A09 (fuera de --sections=F,G) debe quedar byte-identica"
            );
        }

        // Ninguna seccion se agrega ni se elimina; el orden se preserva.
        $this->assertSame($originalA09Sections->keys()->all(), $newA09Sections->keys()->all());
    }

    public function test_other_26_sheets_remain_byte_identical(): void
    {
        $base = $this->createBaseStructure();
        $originalA01 = $base->estructura['forms'][0];

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newA01 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A01');

        $this->assertSame(json_encode($originalA01), json_encode($newA01), 'La hoja A01 debe quedar byte-identica');

        $base->refresh();
        $this->assertSame(json_encode($originalA01), json_encode($base->estructura['forms'][0]), 'La estructura base original tampoco debe alterarse');
    }

    public function test_persisted_patch_creates_new_draft_version_incrementing_number(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();

        $this->assertSame(2, $new->version_number);
        $this->assertSame('draft', $new->status);
        $this->assertNotSame($base->hash_estructura, $new->hash_estructura);
        $this->assertStringContainsString('F, G', $new->notes ?? '');
    }
}
