<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Tests\TestCase;

/**
 * Cubre la nueva capacidad de rem:patch-sheet-structure para INSERTAR una
 * seccion ausente en la estructura base pero detectada por el parser
 * corregido -- hallazgo real de A26/A: la Seccion A (real, con datos
 * propios, no agregadora) fue omitida por completo en builds anteriores,
 * quedando la estructura activa con solo [A.1, B, C, D, E, F, G, H].
 *
 * Complementa RemPatchSheetStructureCommandTest.php (que cubre el
 * reemplazo de secciones existentes) probando el flujo end-to-end contra
 * el Excel real de A26.
 */
class RemPatchSheetStructureInsertionTest extends TestCase
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
     * Estructura base que imita el estado real de A26 en la estructura
     * activa hoy: Seccion A completamente ausente, el resto presente con
     * valores dummy (el contenido exacto de A.1-H no importa para estos
     * tests -- lo que se prueba es el mecanismo de insercion/aislamiento,
     * no los valores reales de esas secciones).
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
                        'sheetName' => 'A26',
                        'sections' => [
                            // 'A' deliberadamente ausente -- ese es el bug real.
                            $this->dummySection('A.1', 37, 38, 47, 14),
                            $this->dummySection('B', 49, 50, 60, 13),
                            $this->dummySection('C', 62, 63, 69, 21),
                            $this->dummySection('D', 71, 72, 82, 9),
                            $this->dummySection('E', 84, 85, 87, 7),
                            $this->dummySection('F', 89, 90, 90, 3),
                            $this->dummySection('G', 92, 93, 110, 21),
                            $this->dummySection('H', 112, 113, 119, 19),
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
            '--sheet' => 'A26',
            '--sections' => 'A',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ], $overrides);
    }

    // --- Insercion pura de A (sin combinar con reemplazos) ---

    public function test_dry_run_reports_a_as_inserted_with_correct_real_values(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base));
        $output = Artisan::output();

        $this->assertEquals(0, $exit);
        $this->assertStringContainsString('CAMBIADA (autorizada)', $output);
        $this->assertStringContainsString('DRY-RUN', $output);
    }

    public function test_inserted_a_matches_real_parser_values(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a26 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A26');
        $a = collect($a26['sections'])->firstWhere('codigo', 'A');

        $this->assertNotNull($a, 'A debe existir en la estructura parcheada');
        $this->assertSame(9, $a['filaHeader']);
        $this->assertSame(10, $a['filaInicioDatos']);
        $this->assertSame(35, $a['filaFinDatos']);
        $this->assertCount(19, $a['fields']);
    }

    public function test_final_order_is_a_then_a1_then_the_rest(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a26 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A26');
        $codes = collect($a26['sections'])->pluck('codigo')->all();

        $this->assertSame(['A', 'A.1', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], $codes);
    }

    public function test_a1_through_h_remain_byte_identical_when_only_inserting_a(): void
    {
        $base = $this->createBaseStructure();
        $originalByCode = collect($base->estructura['forms'][1]['sections'])->keyBy('codigo');

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newByCode = collect(collect($new->estructura['forms'])->firstWhere('sheetName', 'A26')['sections'])->keyBy('codigo');

        foreach (['A.1', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $codigo) {
            $this->assertSame(
                json_encode($originalByCode[$codigo]),
                json_encode($newByCode[$codigo]),
                "La seccion {$codigo} debe quedar byte-identica al insertar solo A"
            );
        }
    }

    public function test_other_sheets_remain_byte_identical_when_inserting_a(): void
    {
        $base = $this->createBaseStructure();
        $originalA01 = $base->estructura['forms'][0];

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--dry-run' => false]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $newA01 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A01');

        $this->assertSame(json_encode($originalA01), json_encode($newA01), 'A01 debe quedar byte-identica');
    }

    public function test_dry_run_does_not_persist_the_insertion(): void
    {
        $base = $this->createBaseStructure();
        $countBefore = RemTemplateStructure::count();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base));

        $this->assertEquals(0, $exit);
        $this->assertEquals($countBefore, RemTemplateStructure::count(), 'dry-run no debe crear ninguna fila nueva');

        $base->refresh();
        $a26 = collect($base->estructura['forms'])->firstWhere('sheetName', 'A26');
        $codes = collect($a26['sections'])->pluck('codigo')->all();
        $this->assertNotContains('A', $codes, 'dry-run no debe insertar A en la estructura base');
    }

    // --- Insercion combinada con reemplazo (escenario real de A26) ---

    public function test_combined_insertion_and_replacement_produces_correct_final_order(): void
    {
        $base = $this->createBaseStructure();

        Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, [
            '--sections' => 'A,C,D,E,G,H',
            '--dry-run' => false,
        ]));

        $new = RemTemplateStructure::where('id', '!=', $base->id)->firstOrFail();
        $a26 = collect($new->estructura['forms'])->firstWhere('sheetName', 'A26');
        $codes = collect($a26['sections'])->pluck('codigo')->all();

        $this->assertSame(['A', 'A.1', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], $codes);

        $h = collect($a26['sections'])->firstWhere('codigo', 'H');
        // 119, no 120: hallazgo de A31 (2026-08-10) -- fila 120 es un TOTAL
        // final inmediato (concepto "TOTAL", formulas que agregan filas
        // anteriores de la misma seccion), excluido por
        // SectionDetectorService::excludeTrailingTotalRows(). Confirmado
        // empiricamente contra el Excel real.
        $this->assertSame(119, $h['filaFinDatos'], 'H debe corregirse a 119 (antes null/ausente; excluye la fila TOTAL final 120)');

        $a1 = collect($a26['sections'])->firstWhere('codigo', 'A.1');
        $f = collect($a26['sections'])->firstWhere('codigo', 'F');
        $this->assertCount(14, $a1['fields'], 'A.1 no fue solicitada -- debe quedar intacta');
        $this->assertCount(3, $f['fields'], 'F no fue solicitada -- debe quedar intacta');
    }

    // --- Validaciones de aborto especificas de insercion ---

    public function test_aborts_when_requested_section_does_not_exist_in_base_nor_in_parser(): void
    {
        $base = $this->createBaseStructure();

        $exit = Artisan::call('rem:patch-sheet-structure', $this->baseArgs($base, ['--sections' => 'ZZZ']));
        $output = Artisan::output();

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('ZZZ', $output);
        $this->assertStringContainsString('NI fue detectada', $output);
    }
}
