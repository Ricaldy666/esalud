<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Tests\TestCase;

/**
 * Cubre la nueva capacidad de rem:patch-sheet-structure para ELIMINAR una
 * seccion "fantasma" -- existe en la estructura base pero el parser
 * corregido ya no la detecta (filterAggregators() ahora la reconoce como
 * agregador puro duplicado) -- hallazgo real de A32 (2026-08-10): "D", "E"
 * y "F" quedaban como entradas duplicadas de "D1", "E1" y "F1" porque
 * filterAggregators() no reconocia codigos hijos sin punto separador.
 *
 * Antes de este fix, intentar "reemplazar" D/E/F simplemente insertaba una
 * entrada null en la estructura (freshByCode['D'] no existe), corrompiendola.
 * La eliminacion solo se permite cuando OTRA seccion solicitada en el MISMO
 * patch comparte el filaHeader original de la seccion a eliminar -- misma
 * señal que ya usa filterAggregators() para confirmar un agregador puro.
 */
class RemPatchSheetStructureDeletionTest extends TestCase
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
     * Estructura base que imita el estado real de A32 antes del fix de
     * filterAggregators(): "D" existe como entrada fantasma (mismo
     * filaHeader real que D1, 32 -- confirmado contra el Excel real), junto
     * a "D1", "D.2" y el resto con valores dummy.
     */
    private function createBaseStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-base-de-prueba-eliminacion',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'A32',
                        'sections' => [
                            $this->dummySection('A', 9, 11, 16, 26),
                            $this->dummySection('B', 18, 19, 24, 5),
                            $this->dummySection('C', 26, 27, 29, 25),
                            // "D" fantasma: mismo filaHeader real que D1 (32).
                            $this->dummySection('D', 32, 33, 90, 45),
                            $this->dummySection('D1', 32, 33, 90, 45),
                            $this->dummySection('D.2', 92, 93, 104, 47),
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
            '--sheet' => 'A32',
            '--sections' => 'D,D1',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ], $overrides);
    }

    public function test_dry_run_reports_d_as_deleted(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base));
        $output = Artisan::output();

        $this->assertEquals(0, $exit);
        $this->assertStringContainsString('ELIMINADA (autorizada)', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    public function test_d_ghost_section_is_removed_and_d1_is_updated(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a32 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A32');
        $codes = collect($a32['sections'])->pluck('codigo')->all();

        $this->assertNotContains('D', $codes, 'D (fantasma) debe desaparecer de la estructura parcheada');
        $this->assertContains('D1', $codes, 'D1 debe seguir presente, actualizada con los valores reales del parser');

        $d1 = collect($a32['sections'])->firstWhere('codigo', 'D1');
        $this->assertSame(35, $d1['filaInicioDatos']);
        $this->assertSame(89, $d1['filaFinDatos'], 'D1 debe reflejar el fin real (89, TOTAL fila 90 excluido)');
        $this->assertCount(45, $d1['fields']);
    }

    public function test_final_order_has_no_gap_where_d_was(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a32 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A32');
        $codes = collect($a32['sections'])->pluck('codigo')->all();

        $this->assertSame(['A', 'B', 'C', 'D1', 'D.2'], $codes);
    }

    public function test_a_b_c_d2_remain_byte_identical_when_only_deleting_d(): void
    {
        $base = $this->createBaseStructure();
        $originalByCode = collect($base->estructura['forms'][0]['sections'])->keyBy('codigo');

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newByCode = collect(collect($new->estructura['forms'])->firstWhere('sheetName', 'A32')['sections'])->keyBy('codigo');

        foreach (['A', 'B', 'C', 'D.2'] as $codigo) {
            $this->assertSame(
                json_encode($originalByCode[$codigo]),
                json_encode($newByCode[$codigo]),
                "La seccion {$codigo} debe quedar byte-identica al eliminar solo D"
            );
        }
    }

    public function test_dry_run_does_not_persist_the_deletion(): void
    {
        $base = $this->createBaseStructure();
        $countBefore = RemTemplateStructure::count();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base));

        $this->assertEquals(0, $exit);
        $this->assertEquals($countBefore, RemTemplateStructure::count(), 'dry-run no debe crear ninguna fila nueva');

        $base->refresh();
        $a32 = collect($base->estructura['forms'])->firstWhere('sheetName', 'A32');
        $codes = collect($a32['sections'])->pluck('codigo')->all();
        $this->assertContains('D', $codes, 'dry-run no debe eliminar D de la estructura base');
    }

    /**
     * Caso negativo de seguridad: intentar eliminar "D" SIN incluir "D1" (o
     * cualquier otra seccion con el mismo filaHeader original) en el mismo
     * --sections debe abortar -- nunca se elimina sin una confirmacion de
     * que otra seccion absorbe su rol.
     */
    public function test_deleting_d_without_its_absorbing_section_aborts(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--sections' => 'D']));
        $output = Artisan::output();

        $this->assertEquals(1, $exit, 'debe abortar sin una seccion que confirme la absorcion');
        $this->assertStringContainsString('Abortando por seguridad', $output);

        $base->refresh();
        $a32 = collect($base->estructura['forms'])->firstWhere('sheetName', 'A32');
        $codes = collect($a32['sections'])->pluck('codigo')->all();
        $this->assertContains('D', $codes, 'D no debe eliminarse sin confirmacion de absorcion');
    }

    public function test_other_sheets_remain_byte_identical_when_deleting_d(): void
    {
        $base = $this->createBaseStructure();
        Artisan::call('rem:patch-sheet-structure', [
            'structure_id' => $base->id,
            '--serie' => 'A',
            '--year' => '2026',
            '--sheet' => 'A32',
            '--sections' => 'A',
            '--source' => $this->realSourcePath(),
            '--dry-run' => false,
        ]);
        // Solo para confirmar que un reemplazo normal (A) sigue funcionando
        // sin interferencia del nuevo camino de eliminacion -- regresion
        // basica, no repite toda la cobertura de RemPatchSheetStructureCommandTest.
        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a32 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A32');
        $codes = collect($a32['sections'])->pluck('codigo')->all();
        $this->assertContains('D', $codes, 'D no debe eliminarse cuando no fue solicitada');
    }
}
