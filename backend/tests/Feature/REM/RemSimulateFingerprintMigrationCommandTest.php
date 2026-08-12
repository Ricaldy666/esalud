<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Tests\TestCase;

/**
 * Cubre rem:simulate-fingerprint-migration (Fase 5, deuda tecnica #1,
 * 2026-08-12): recorre TODAS las secciones de la estructura activa
 * (generico, sin hoja/seccion hardcodeada) y clasifica cada una en
 * NEW_SECTION / NOT_CALIBRATABLE / NO_UTILIZADA / AUTO_MIGRATE /
 * QUICK_CONFIRMATION / FULL_REVALIDATION / MISMATCH -- 100% lectura.
 */
class RemSimulateFingerprintMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string,mixed>> */
    private function dummyFields(int $count, bool $withTotal = true): array
    {
        $fields = [];
        for ($i = 1; $i <= $count; $i++) {
            $letra = Coordinate::stringFromColumnIndex($i);
            $fields[] = [
                'letra' => $letra,
                'label' => "Dummy {$letra}",
                'esTotal' => $withTotal && $i === $count,
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

    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-simulacion-migracion',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Z01',
                        'sections' => [
                            $this->dummySection('NUEVA', 9, 10, 12, 3),   // sin historial -> NEW_SECTION
                            $this->dummySection('NOCALIB', 20, 21, 22, 2), // response=no_calibrable -> NOT_CALIBRATABLE
                        ],
                    ],
                    [
                        'sheetName' => 'Z02',
                        'sections' => [
                            $this->dummySection('A', 9, 10, 12, 3), // hoja marcada no_utilizada
                        ],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'dummy.xlsm',
            'status' => 'active',
        ]);
    }

    private function seedReglasFuncionales(): void
    {
        $content = json_encode([
            '_questions' => [
                'Z01_NOCALIB' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'no_calibrable', 'review_status' => 'section_reviewed',
                        'reviewed_by' => 'Test', 'reviewed_at' => now()->toIso8601String(),
                        'closure_reason' => 'no_calibratable_data',
                    ],
                ],
                'Z02_A' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'revisada', 'review_status' => 'section_reviewed',
                    ],
                    ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1, 'response' => 'si', 'review_status' => 'reviewed'],
                ],
                // 'Z01_NUEVA' deliberadamente ausente -- nunca revisada.
            ],
        ], JSON_PRETTY_PRINT);

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', $content);
    }

    public function test_section_without_any_history_is_new_section(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->seedReglasFuncionales();

        Artisan::call('rem:simulate-fingerprint-migration', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Z01_NUEVA', $output);
        $this->assertStringContainsString('NEW_SECTION', $output);
    }

    public function test_no_calibrable_closure_is_not_calibratable(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->seedReglasFuncionales();

        Artisan::call('rem:simulate-fingerprint-migration', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('NOT_CALIBRATABLE', $output);
    }

    public function test_no_utilizada_sheet_is_excluded_from_normal_categories(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->seedReglasFuncionales();

        DB::table('rem_sheet_usage_status')->insert([
            'anio' => 2026, 'serie' => 'A', 'sheet_name' => 'Z02',
            'status' => 'no_utilizada', 'reason' => 'test', 'decided_by' => 'Test',
            'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('rem:simulate-fingerprint-migration', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('NO_UTILIZADA', $output);
        // Z02/A tiene un patron con respuesta reviewed -- si NO se excluyera
        // primero por no_utilizada, calificaria como AUTO_MIGRATE/QUICK_CONFIRMATION
        // y apareceria en la tabla de "requieren algo de atención" o entre los
        // AUTO_MIGRATE; en cambio debe quedar contado exclusivamente como NO_UTILIZADA.
        $this->assertMatchesRegularExpression('/NO_UTILIZADA\s*\|\s*1/', $output);
    }

    public function test_dry_run_flag_is_required(): void
    {
        $exitCode = Artisan::call('rem:simulate-fingerprint-migration', []);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--dry-run', Artisan::output());
    }

    public function test_execution_performs_zero_write_queries(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->seedReglasFuncionales();

        $writeQueries = [];
        DB::listen(function ($query) use (&$writeQueries) {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeQueries[] = $query->sql;
            }
        });

        Artisan::call('rem:simulate-fingerprint-migration', ['--dry-run' => true]);

        $this->assertSame([], $writeQueries);
    }

    public function test_does_not_modify_reglas_funcionales_json(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->seedReglasFuncionales();
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        Artisan::call('rem:simulate-fingerprint-migration', ['--dry-run' => true]);

        $this->assertSame($before, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
    }
}
