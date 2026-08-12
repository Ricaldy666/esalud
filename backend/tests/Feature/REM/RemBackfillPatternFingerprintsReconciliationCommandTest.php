<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Tests\TestCase;

/**
 * Cubre el modo reconciliacion (--sheet + --sections) de
 * rem:backfill-pattern-fingerprints: debe limitarse estrictamente a las
 * secciones solicitadas, no debe dejar rastro en la base de datos
 * (transaccion con ROLLBACK garantizado) ni en reglas-funcionales.json en
 * modo --dry-run, y las validaciones de flags deben rechazar combinaciones
 * incompletas antes de tocar nada.
 */
class RemBackfillPatternFingerprintsReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function realSourcePath(): string
    {
        return storage_path('app/rem-uploads/2026/01/1/20260529190913_SA_26_V1.2-2.xlsm');
    }

    /** @return array<int, array<string,mixed>> */
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

    private function createBaseStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-base-de-prueba-reconciliacion',
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
                            $this->dummySection('F', 88, 91, 92, 40),
                            $this->dummySection('F.1', 145, 146, 158, 5),
                            $this->dummySection('F.2', 160, 161, 174, 6),
                            $this->dummySection('G', 176, 177, 180, 42),
                            $this->dummySection('G.1', 229, 230, 237, 4),
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

    private function seedReglasFuncionales(): string
    {
        $content = json_encode([
            '_questions' => [
                'A09_F' => [
                    ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1, 'response' => 'debe_registrar_cero', 'review_status' => 'reviewed'],
                ],
                'A09_G' => [
                    ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1, 'response' => 'puede_quedar_vacio', 'review_status' => 'reviewed'],
                ],
                'A09_K' => [
                    ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1, 'response' => 'no_debe_tocarse', 'review_status' => 'reviewed'],
                ],
                'A01_A' => [
                    ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1, 'response' => 'tampoco_tocar', 'review_status' => 'reviewed'],
                ],
            ],
        ], JSON_PRETTY_PRINT);

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', $content);

        return $content;
    }

    public function test_dry_run_reconciliation_does_not_persist_any_structure_row(): void
    {
        Storage::fake('local');
        $base = $this->createBaseStructure();
        $this->seedReglasFuncionales();

        $countBefore = RemTemplateStructure::count();

        $exitCode = Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($countBefore, RemTemplateStructure::count());
        $this->assertSame('active', $base->fresh()->status);
        $this->assertFalse(RemTemplateStructure::where('version_number', 250)->exists());
    }

    public function test_dry_run_reconciliation_does_not_modify_reglas_funcionales_json(): void
    {
        Storage::fake('local');
        $this->createBaseStructure();
        $originalContent = $this->seedReglasFuncionales();

        Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ]);

        $this->assertSame(
            $originalContent,
            Storage::disk('local')->get('certificacion/reglas-funcionales.json'),
            'reglas-funcionales.json debe quedar byte-identico tras un --dry-run, incluyendo las entradas de A09/K y A01/A ajenas al alcance solicitado'
        );
    }

    public function test_reconciliation_output_only_reports_requested_sections(): void
    {
        Storage::fake('local');
        $this->createBaseStructure();
        $this->seedReglasFuncionales();

        Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('A09/F', $output);
        $this->assertStringContainsString('A09/G', $output);
        $this->assertStringNotContainsString('A09/K', $output);
        $this->assertStringNotContainsString('A01/A', $output);
    }

    public function test_sections_option_requires_sheet_option(): void
    {
        $exitCode = Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--sections requiere --sheet', Artisan::output());
    }

    public function test_reconciliation_mode_requires_source_file(): void
    {
        $this->createBaseStructure();

        $exitCode = Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--source', Artisan::output());
    }

    public function test_dry_run_and_write_are_mutually_exclusive(): void
    {
        $this->createBaseStructure();

        $exitCode = Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
            '--write' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mutuamente excluyentes', Artisan::output());
    }

    public function test_dry_run_reconciliation_executes_zero_write_queries(): void
    {
        // Prueba explicita del rediseno Fase 4 (2026-08-12): a diferencia del
        // diseno anterior (transaccion con INSERT + UPDATE + ROLLBACK
        // garantizado), la simulacion en memoria no debe emitir NINGUNA
        // consulta de escritura -- ni siquiera dentro de una transaccion
        // revertida despues.
        Storage::fake('local');
        $this->createBaseStructure();
        $this->seedReglasFuncionales();

        $writeQueries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writeQueries) {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeQueries[] = $query->sql;
            }
        });

        Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--dry-run' => true,
        ]);

        $this->assertSame([], $writeQueries, 'no debe ejecutarse ninguna consulta INSERT/UPDATE/DELETE durante la simulacion en memoria');
    }

    public function test_write_without_confirm_is_rejected_and_never_touches_target(): void
    {
        Storage::fake('local');
        $this->createBaseStructure();
        $originalContent = $this->seedReglasFuncionales();
        $target = storage_path('app/private/certificacion/reglas-funcionales.json');

        $exitCode = Artisan::call('rem:backfill-pattern-fingerprints', [
            '--sheet' => 'A09',
            '--sections' => 'F,G',
            '--source' => $this->realSourcePath(),
            '--write' => true,
            '--target' => $target,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CONFIRMAR-MIGRACION-A09', Artisan::output());
        $this->assertSame($originalContent, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
    }
}
