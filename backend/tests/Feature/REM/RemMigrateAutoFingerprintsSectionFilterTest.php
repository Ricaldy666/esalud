<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * rem:migrate-auto-fingerprints --section=Hoja_Seccion: el reporte y la
 * escritura quedan limitados a una unica seccion AUTO_MIGRATE; sin la opcion
 * el comando se comporta igual que siempre.
 *
 * Fixture (hoja sintetica Y03 -- A01/A tiene patrones legacy fijos en
 * SectionCalibrationMatrixService y no sirve como fixture generico): Y03_A y Y03_B son AUTO_MIGRATE (preguntas legacy, misma
 * estructura), Y03_Q es QUICK_CONFIRMATION (declaracion historica distinta).
 */
class RemMigrateAutoFingerprintsSectionFilterTest extends TestCase
{
    use RefreshDatabase;

    private const REGLAS_PATH = 'certificacion/reglas-funcionales.json';

    private const DECISION_FIELDS = [
        'response', 'reviewed_by', 'reviewed_at', 'review_status', 'source_type',
        'observation', 'severity', 'closure_reason',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function targetPath(): string
    {
        return Storage::disk('local')->path(self::REGLAS_PATH);
    }

    private function section(string $codigo, int $filaHeader, int $inicio, int $fin): array
    {
        return [
            'codigo' => $codigo, 'titulo' => "SECCION {$codigo}",
            'filaHeader' => $filaHeader, 'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin,
            'fields' => [
                ['letra' => 'B', 'label' => 'Origen', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                ['letra' => 'C', 'label' => 'Total', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
            ],
        ];
    }

    private function questions(int $structureId, string $rows): array
    {
        $base = [
            'review_status' => 'reviewed', 'reviewed_by' => 'Estadistica APS',
            'reviewed_at' => '2026-09-04T20:23:00+00:00', 'source_type' => 'manual',
            'observation' => 'Obs', 'severity' => 'advertencia', 'closure_reason' => null,
            'structure_version' => (string) $structureId,
        ];

        return [
            ['id' => 'section_review', 'type' => 'section_review', 'response' => 'revisada', 'review_status' => 'section_reviewed',
                'reviewed_by' => 'Estadistica APS', 'reviewed_at' => '2026-09-04T20:23:00+00:00', 'source_type' => 'manual'],
            $base + ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
                'question' => "Pregunta (Patrón 1: {$rows})", 'response' => 'puede_quedar_vacio'],
            $base + ['id' => 'patron_1_confirm', 'type' => 'pattern_confirmation', 'pattern_id' => 1,
                'question' => "Confirmación (Patrón 1: {$rows})", 'response' => 'confirmed'],
        ];
    }

    private function setUpFixture(): void
    {
        $active = RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 1,
            'hash_estructura' => 'hash-section-filter-test',
            'estructura' => ['forms' => [[
                'sheetName' => 'Y03',
                'sections' => [
                    $this->section('A', 9, 10, 12),
                    $this->section('B', 19, 20, 22),
                    $this->section('Q', 29, 30, 32),
                ],
            ]]],
            'metadata' => null, 'source_filename' => 'dummy.xlsm', 'status' => 'active',
        ]);
        $historical = RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 0,
            'hash_estructura' => 'hash-section-filter-historica',
            'estructura' => ['forms' => [[
                'sheetName' => 'Y03',
                'sections' => [$this->section('Q', 28, 30, 32)],
            ]]],
            'metadata' => null, 'source_filename' => 'dummy.xlsm', 'status' => 'superseded',
        ]);

        Storage::disk('local')->put(self::REGLAS_PATH, json_encode(['_questions' => [
            'Y03_A' => $this->questions($active->id, '10, 11, 12'),
            'Y03_B' => $this->questions($active->id, '20, 21, 22'),
            'Y03_Q' => $this->questions($historical->id, '30, 31, 32'),
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function stored(): array
    {
        return json_decode(Storage::disk('local')->get(self::REGLAS_PATH), true);
    }

    private function raw(): string
    {
        return Storage::disk('local')->get(self::REGLAS_PATH);
    }

    private function backups(): array
    {
        return array_values(array_filter(
            Storage::disk('local')->files('certificacion'),
            fn ($f) => str_contains($f, 'reglas-funcionales.json.bak-')
        ));
    }

    private function commit(array $extra = []): int
    {
        return Artisan::call('rem:migrate-auto-fingerprints', array_merge([
            '--commit' => true, '--confirm' => 'CONFIRMAR-MIGRACION-AUTO-V2', '--target' => $this->targetPath(),
        ], $extra));
    }

    private function assertMigrated(array $questions): void
    {
        foreach ($questions as $q) {
            if (! in_array($q['type'], ['pattern_question', 'pattern_confirmation'], true)) {
                continue;
            }
            $this->assertSame(2, $q['fingerprint_version'] ?? null, $q['id']);
            $this->assertStringStartsWith('fpv2_', $q['pattern_fingerprint'] ?? '', $q['id']);
            $this->assertSame('auto_migrate_v2', $q['fingerprint_migration_source'] ?? null, $q['id']);
        }
    }

    public function test_filtered_dry_run_shows_only_requested_section_and_writes_nothing(): void
    {
        $this->setUpFixture();
        $before = $this->raw();

        $exit = Artisan::call('rem:migrate-auto-fingerprints', ['--dry-run' => true, '--section' => 'Y03_A']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Filtro de seccion activo: solo Y03_A.', $output);
        $this->assertStringContainsString('| Y03_A ', $output);
        $this->assertStringNotContainsString('Y03_B', $output);
        $this->assertStringNotContainsString('Y03_Q', $output);
        $this->assertStringContainsString('DRY-RUN: 1 secciones AUTO_MIGRATE', $output);
        $this->assertSame($before, $this->raw());
        $this->assertSame([], $this->backups());
    }

    public function test_filtered_commit_migrates_only_requested_section_and_preserves_decisions(): void
    {
        $this->setUpFixture();
        $before = $this->stored();

        $exit = $this->commit(['--section' => 'Y03_A']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('1 secciones, 1 patrones, 2 preguntas', $output);

        $after = $this->stored();
        $this->assertMigrated($after['_questions']['Y03_A']);

        // La otra seccion AUTO_MIGRATE y la QUICK quedan byte-equivalentes.
        $this->assertSame(json_encode($before['_questions']['Y03_B']), json_encode($after['_questions']['Y03_B']));
        $this->assertSame(json_encode($before['_questions']['Y03_Q']), json_encode($after['_questions']['Y03_Q']));

        // Decisiones funcionales de Y03_A intactas; solo metadata tecnica nueva.
        $technical = ['pattern_fingerprint', 'fingerprint_version', 'pattern_rows', 'fingerprint_migrated_at', 'fingerprint_migration_source'];
        foreach ($after['_questions']['Y03_A'] as $i => $q) {
            foreach (self::DECISION_FIELDS as $field) {
                $this->assertSame($before['_questions']['Y03_A'][$i][$field] ?? null, $q[$field] ?? null, "{$q['id']}.{$field}");
            }
            $this->assertSame(
                array_diff_key($before['_questions']['Y03_A'][$i], array_flip($technical)),
                array_diff_key($q, array_flip($technical)),
                "{$q['id']}: solo pueden cambiar campos tecnicos"
            );
        }
        $this->assertCount(1, $this->backups());
    }

    public function test_second_filtered_commit_is_idempotent(): void
    {
        $this->setUpFixture();
        $this->commit(['--section' => 'Y03_A']);
        $afterFirst = $this->raw();

        $exit = $this->commit(['--section' => 'Y03_A']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('0 secciones, 0 patrones, 0 preguntas', $output);
        $this->assertSame($afterFirst, $this->raw());
    }

    public function test_nonexistent_section_aborts_without_writing(): void
    {
        $this->setUpFixture();
        $before = $this->raw();

        $exit = $this->commit(['--section' => 'A99_Z']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no existe', Artisan::output());
        $this->assertSame($before, $this->raw());
        $this->assertSame([], $this->backups());
    }

    public function test_non_auto_migrate_section_aborts_without_writing(): void
    {
        $this->setUpFixture();
        $before = $this->raw();

        $exit = $this->commit(['--section' => 'Y03_Q']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no es AUTO_MIGRATE', Artisan::output());
        $this->assertSame($before, $this->raw());
        $this->assertSame([], $this->backups());
    }

    public function test_empty_section_value_aborts_without_writing(): void
    {
        $this->setUpFixture();
        $before = $this->raw();

        $exit = $this->commit(['--section' => '']);

        $this->assertSame(1, $exit);
        $this->assertSame($before, $this->raw());
        $this->assertSame([], $this->backups());
    }

    public function test_section_that_stops_being_auto_migrate_during_reverification_aborts_without_writing(): void
    {
        $this->setUpFixture();
        $before = $this->raw();

        $auto = ['sheet' => 'Y03', 'code' => 'A', 'category' => PatternReconciliationService::MIGRATION_AUTO_MIGRATE, 'patterns' => []];
        $quick = ['sheet' => 'Y03', 'code' => 'A', 'category' => PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION, 'patterns' => []];
        $scanner = Mockery::mock(PatternMigrationScanner::class);
        $scanner->shouldReceive('scanAllSections')->twice()->andReturn(['Y03_A' => $auto], ['Y03_A' => $quick]);
        $scanner->shouldNotReceive('questionTechnicalState');
        $this->app->instance(PatternMigrationScanner::class, $scanner);

        $exit = $this->commit(['--section' => 'Y03_A']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Y03_A dejo de ser AUTO_MIGRATE', Artisan::output());
        $this->assertSame($before, $this->raw());
        $this->assertSame([], $this->backups());
    }

    public function test_without_section_behavior_is_unchanged(): void
    {
        $this->setUpFixture();

        Artisan::call('rem:migrate-auto-fingerprints', ['--dry-run' => true]);
        $dry = Artisan::output();
        $this->assertStringNotContainsString('Filtro de seccion activo', $dry);
        $this->assertStringContainsString('| Y03_A ', $dry);
        $this->assertStringContainsString('| Y03_B ', $dry);
        $this->assertStringNotContainsString('Y03_Q', $dry);
        $this->assertStringContainsString('DRY-RUN: 2 secciones AUTO_MIGRATE', $dry);

        $before = $this->stored();
        $exit = $this->commit();
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('2 secciones, 2 patrones, 4 preguntas', $output);
        $after = $this->stored();
        $this->assertMigrated($after['_questions']['Y03_A']);
        $this->assertMigrated($after['_questions']['Y03_B']);
        $this->assertSame(json_encode($before['_questions']['Y03_Q']), json_encode($after['_questions']['Y03_Q']));
    }
}
