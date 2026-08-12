<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre rem:migrate-auto-fingerprints (Fase 8A, deuda tecnica #1,
 * 2026-08-12): migracion segura de pattern_fingerprint/fingerprint_version/
 * pattern_rows a v2, exclusivamente para secciones AUTO_MIGRATE
 * reclasificadas en vivo.
 *
 * Storage::fake('local') + --target apuntando al path real que ese disco
 * fake resuelve (Storage::disk('local')->path(...)) -- asi el scanner (via
 * FunctionalRuleService, que lee a traves de Storage::disk('local')) y la
 * escritura del comando (via --target, ruta de filesystem explicita) leen
 * y escriben exactamente el mismo archivo, igual que en produccion real
 * (donde el disco 'local' resuelve a storage_path('app/private')).
 */
class RemMigrateAutoFingerprintsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const REGLAS_PATH = 'certificacion/reglas-funcionales.json';

    private function targetPath(): string
    {
        return Storage::disk('local')->path(self::REGLAS_PATH);
    }

    private function dummySection(string $codigo, int $filaHeader, int $filaInicioDatos, int $filaFinDatos): array
    {
        return [
            'codigo' => $codigo,
            'titulo' => "SECCION {$codigo} DE PRUEBA",
            'filaHeader' => $filaHeader,
            'filaInicioDatos' => $filaInicioDatos,
            'filaFinDatos' => $filaFinDatos,
            'fields' => [
                ['letra' => 'B', 'label' => 'Origen', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                ['letra' => 'C', 'label' => 'Total', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
            ],
        ];
    }

    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 1,
            'hash_estructura' => 'hash-migracion-auto-test',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Y01',
                        'sections' => [
                            $this->dummySection('AUTO', 9, 10, 12),   // candidato AUTO_MIGRATE
                            $this->dummySection('QUICK', 20, 21, 23), // estructura cambio -> QUICK_CONFIRMATION
                        ],
                    ],
                ],
            ],
            'metadata' => null, 'source_filename' => 'dummy.xlsm', 'status' => 'active',
        ]);
    }

    /** Estructura historica DISTINTA (Y01/QUICK declarada distinto) para forzar QUICK_CONFIRMATION. */
    private function createHistoricalStructureForQuick(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 0,
            'hash_estructura' => 'hash-historica-quick',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Y01',
                        'sections' => [
                            $this->dummySection('QUICK', 19, 20, 23), // filaHeader distinto -> declaracion distinta
                        ],
                    ],
                ],
            ],
            'metadata' => null, 'source_filename' => 'dummy.xlsm', 'status' => 'superseded',
        ]);
    }

    private function seedReglasFuncionales(int $activeStructureId, int $historicalStructureIdForQuick): void
    {
        $content = [
            '_questions' => [
                'Y01_AUTO' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'revisada', 'review_status' => 'section_reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual',
                    ],
                    [
                        'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Pregunta de prueba (Patrón 1: 10, 11, 12)',
                        'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual', 'structure_version' => (string) $activeStructureId,
                    ],
                    [
                        'id' => 'patron_1_confirm', 'type' => 'pattern_confirmation', 'pattern_id' => 1,
                        'question' => 'Confirmación (Patrón 1: 10, 11, 12)',
                        'response' => 'confirmed', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:05:00.000Z',
                        'source_type' => 'manual', 'structure_version' => (string) $activeStructureId,
                    ],
                ],
                'Y01_QUICK' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'revisada', 'review_status' => 'section_reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                    ],
                    [
                        'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Pregunta de prueba (Patrón 1: 21, 22, 23)',
                        'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'structure_version' => (string) $historicalStructureIdForQuick,
                    ],
                ],
            ],
        ];

        Storage::disk('local')->put(self::REGLAS_PATH, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_dry_run_reports_auto_migrate_candidate_without_writing(): void
    {
        Storage::fake('local');
        $active = $this->createActiveStructure();
        $historical = $this->createHistoricalStructureForQuick();
        $this->seedReglasFuncionales($active->id, $historical->id);
        $before = Storage::disk('local')->get(self::REGLAS_PATH);

        $exit = Artisan::call('rem:migrate-auto-fingerprints', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Y01_AUTO', Artisan::output());
        $this->assertStringNotContainsString('Y01_QUICK', Artisan::output());
        $this->assertSame($before, Storage::disk('local')->get(self::REGLAS_PATH), 'dry-run no debe modificar el archivo');
        $this->assertFalse(Storage::disk('local')->exists(self::REGLAS_PATH.'.bak-'), 'dry-run no debe crear backup');
    }

    public function test_commit_without_confirm_is_rejected(): void
    {
        Storage::fake('local');
        $active = $this->createActiveStructure();
        $historical = $this->createHistoricalStructureForQuick();
        $this->seedReglasFuncionales($active->id, $historical->id);

        $exit = Artisan::call('rem:migrate-auto-fingerprints', ['--commit' => true, '--target' => $this->targetPath()]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('CONFIRMAR-MIGRACION-AUTO-V2', Artisan::output());
    }

    public function test_commit_migrates_only_auto_migrate_section_and_preserves_authorship(): void
    {
        Storage::fake('local');
        $active = $this->createActiveStructure();
        $historical = $this->createHistoricalStructureForQuick();
        $this->seedReglasFuncionales($active->id, $historical->id);

        $exit = Artisan::call('rem:migrate-auto-fingerprints', [
            '--commit' => true, '--confirm' => 'CONFIRMAR-MIGRACION-AUTO-V2', '--target' => $this->targetPath(),
        ]);

        $this->assertSame(0, $exit, Artisan::output());

        $result = json_decode(Storage::disk('local')->get(self::REGLAS_PATH), true);

        // AUTO: migrado -- pattern_question y pattern_confirmation ambas con v2.
        foreach ($result['_questions']['Y01_AUTO'] as $q) {
            if (! in_array($q['type'], ['pattern_question', 'pattern_confirmation'], true)) {
                continue;
            }
            $this->assertSame(2, $q['fingerprint_version']);
            $this->assertStringStartsWith('fpv2_', $q['pattern_fingerprint']);
            $this->assertSame([10, 11, 12], $q['pattern_rows']);
            $this->assertArrayHasKey('fingerprint_migrated_at', $q);
            $this->assertSame('auto_migrate_v2', $q['fingerprint_migration_source']);
            $this->assertSame('Francisco Arcos', $q['reviewed_by']);
        }
        $this->assertSame('2026-07-01T10:00:00.000Z', $result['_questions']['Y01_AUTO'][1]['reviewed_at']);
        $this->assertSame('manual', $result['_questions']['Y01_AUTO'][1]['source_type']);
        $this->assertSame('debe_registrar_cero', $result['_questions']['Y01_AUTO'][1]['response']);

        // section_review de AUTO: intacto, nunca tocado (no es pattern_question/pattern_confirmation).
        $this->assertArrayNotHasKey('fingerprint_version', $result['_questions']['Y01_AUTO'][0]);
        $this->assertSame('Francisco Arcos', $result['_questions']['Y01_AUTO'][0]['reviewed_by']);

        // QUICK: completamente sin tocar (no es AUTO_MIGRATE).
        foreach ($result['_questions']['Y01_QUICK'] as $q) {
            $this->assertArrayNotHasKey('fingerprint_version', $q);
            $this->assertArrayNotHasKey('pattern_fingerprint', $q);
        }

        // Backup creado.
        $this->assertNotEmpty(array_filter(
            Storage::disk('local')->files('certificacion'),
            fn ($f) => str_contains($f, 'reglas-funcionales.json.bak-')
        ));
    }

    public function test_second_commit_run_is_idempotent(): void
    {
        Storage::fake('local');
        $active = $this->createActiveStructure();
        $historical = $this->createHistoricalStructureForQuick();
        $this->seedReglasFuncionales($active->id, $historical->id);

        Artisan::call('rem:migrate-auto-fingerprints', [
            '--commit' => true, '--confirm' => 'CONFIRMAR-MIGRACION-AUTO-V2', '--target' => $this->targetPath(),
        ]);
        $afterFirstRun = Storage::disk('local')->get(self::REGLAS_PATH);

        Artisan::call('rem:migrate-auto-fingerprints', [
            '--commit' => true, '--confirm' => 'CONFIRMAR-MIGRACION-AUTO-V2', '--target' => $this->targetPath(),
        ]);
        $output = Artisan::output();
        $afterSecondRun = Storage::disk('local')->get(self::REGLAS_PATH);

        $this->assertStringContainsString('0 secciones, 0 patrones, 0 preguntas', $output);
        $this->assertSame($afterFirstRun, $afterSecondRun, 'la segunda corrida no debe cambiar el archivo');
    }

    public function test_dry_run_after_commit_shows_already_migrated_with_no_pending_changes(): void
    {
        Storage::fake('local');
        $active = $this->createActiveStructure();
        $historical = $this->createHistoricalStructureForQuick();
        $this->seedReglasFuncionales($active->id, $historical->id);

        Artisan::call('rem:migrate-auto-fingerprints', [
            '--commit' => true, '--confirm' => 'CONFIRMAR-MIGRACION-AUTO-V2', '--target' => $this->targetPath(),
        ]);

        Artisan::call('rem:migrate-auto-fingerprints', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('ya migrado (sin cambio)', $output);
    }
}
