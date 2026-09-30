<?php

namespace Tests\Feature\REM;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regresion 2026-09-30 (produccion, A01/A P1-P4): un patron no puede darse
 * por "ya migrado" a v2 si alguna de sus preguntas sigue incompleta. Caso
 * real: patron_N_special con pattern_fingerprint "fpv2_..." pero sin
 * fingerprint_version, junto a otras preguntas del mismo patron ya en v2.
 * PatternMigrationScanner miraba solo la primera pregunta v2, el migrador lo
 * saltaba, y reconcileLive() comparaba despues fpv2 contra rowset ->
 * requiere_revalidacion.
 */
class RemMigrateAutoFingerprintsIncompleteV2Test extends TestCase
{
    use RefreshDatabase;

    private const REGLAS_PATH = 'certificacion/reglas-funcionales.json';

    /** Campos de decision que la migracion nunca puede alterar. */
    private const DECISION_FIELDS = [
        'response', 'reviewed_by', 'reviewed_at', 'review_status', 'source_type',
        'observation', 'severity', 'closure_reason',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 1,
            'hash_estructura' => 'hash-incomplete-v2-test',
            'estructura' => [
                'forms' => [[
                    'sheetName' => 'Y02',
                    'sections' => [[
                        'codigo' => 'P',
                        'titulo' => 'SECCION P DE PRUEBA',
                        'filaHeader' => 9, 'filaInicioDatos' => 10, 'filaFinDatos' => 12,
                        'fields' => [
                            ['letra' => 'B', 'label' => 'Origen', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
                            ['letra' => 'C', 'label' => 'Total', 'esTotal' => true, 'esControlOculto' => false, 'reglaDetectada' => null],
                        ],
                    ]],
                ]],
            ],
            'metadata' => null, 'source_filename' => 'dummy.xlsm', 'status' => 'active',
        ]);
    }

    /** Fingerprint canonico y filas VIVAS del unico patron de Y02/P. */
    private function livePattern(): array
    {
        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('Y02', 'P');
        app(SectionCalibrationMatrixService::class)->forgetSectionCache('Y02', 'P');
        $pattern = $matrix['patterns'][0];

        return [
            'fingerprint' => $pattern['canonical_fingerprint'],
            'rows' => $pattern['filas'],
            'row_fingerprint' => $pattern['row_fingerprint'],
        ];
    }

    private function question(string $id, string $type, string $response, array $technical): array
    {
        return array_merge([
            'id' => $id, 'type' => $type, 'pattern_id' => 1, 'pattern_key' => 'pattern_1',
            'question' => "Pregunta {$id} (Patrón 1: 10, 11, 12)",
            'response' => $response, 'observation' => "Obs {$id}", 'severity' => 'advertencia',
            'review_status' => 'reviewed', 'reviewed_by' => 'Estadistica APS',
            'reviewed_at' => '2026-09-04T20:23:00+00:00', 'source_type' => 'manual',
            'closure_reason' => null, 'status' => 'answered',
        ], $technical);
    }

    private function seedQuestions(array $patternQuestions): void
    {
        $content = ['_questions' => ['Y02_P' => array_merge([[
            'id' => 'section_review', 'type' => 'section_review', 'response' => 'revisada',
            'review_status' => 'section_reviewed', 'reviewed_by' => 'Estadistica APS',
            'reviewed_at' => '2026-09-04T20:23:00+00:00', 'source_type' => 'manual',
        ]], $patternQuestions)]];

        Storage::disk('local')->put(self::REGLAS_PATH, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function v2(array $live): array
    {
        return ['pattern_fingerprint' => $live['fingerprint'], 'fingerprint_version' => 2, 'pattern_rows' => $live['rows']];
    }

    /** Patron correcto + patron_1_special con fpv2 pero SIN fingerprint_version (caso real A01/A). */
    private function seedIncompleteSpecial(array $live): void
    {
        $this->seedQuestions([
            $this->question('patron_1_empty', 'pattern_question', 'debe_registrar_cero', $this->v2($live)),
            $this->question('patron_1_special', 'pattern_question', 'si', [
                'pattern_fingerprint' => $live['fingerprint'], 'pattern_rows' => $live['rows'],
            ]),
            $this->question('patron_1_formula_confirmation', 'pattern_confirmation', 'confirmed', $this->v2($live)),
        ]);
    }

    private function scanPattern(RemTemplateStructure $structure): array
    {
        $est = $structure->estructura;
        $plan = app(PatternMigrationScanner::class)->scanSection($structure, 'Y02', 'P', $est['forms'][0]['sections'][0]);

        return [$plan, $plan['patterns'][0]];
    }

    private function storedPatternQuestions(): array
    {
        $all = json_decode(Storage::disk('local')->get(self::REGLAS_PATH), true);

        return array_values(array_filter(
            $all['_questions']['Y02_P'],
            fn ($q) => in_array($q['type'], ['pattern_question', 'pattern_confirmation'], true)
        ));
    }

    private function commit(): string
    {
        Artisan::call('rem:migrate-auto-fingerprints', [
            '--commit' => true, '--confirm' => 'CONFIRMAR-MIGRACION-AUTO-V2',
            '--target' => Storage::disk('local')->path(self::REGLAS_PATH),
        ]);

        return Artisan::output();
    }

    public function test_pattern_with_all_questions_correct_v2_is_already_migrated(): void
    {
        $structure = $this->createActiveStructure();
        $live = $this->livePattern();
        $this->seedQuestions([
            $this->question('patron_1_empty', 'pattern_question', 'debe_registrar_cero', $this->v2($live)),
            $this->question('patron_1_special', 'pattern_question', 'si', $this->v2($live)),
        ]);

        [$plan, $pattern] = $this->scanPattern($structure);

        $this->assertSame(PatternReconciliationService::MIGRATION_AUTO_MIGRATE, $plan['category']);
        $this->assertTrue($pattern['already_v2_matching']);
        $this->assertSame([], $pattern['incomplete_question_ids']);
        $this->assertSame([], $pattern['pending_technical_fields']);
    }

    public function test_pattern_with_fpv2_question_missing_fingerprint_version_is_not_already_migrated(): void
    {
        $structure = $this->createActiveStructure();
        $live = $this->livePattern();
        $this->seedIncompleteSpecial($live);

        [$plan, $pattern] = $this->scanPattern($structure);

        $this->assertSame(PatternReconciliationService::MIGRATION_AUTO_MIGRATE, $plan['category'], 'sigue siendo candidato AUTO_MIGRATE');
        $this->assertFalse($pattern['already_v2_matching']);
        $this->assertSame(['patron_1_special'], $pattern['incomplete_question_ids']);
        $this->assertSame(['fingerprint_version'], $pattern['pending_technical_fields']);
        $this->assertSame([], $pattern['conflicting_v2_question_ids']);
    }

    public function test_dry_run_reports_only_missing_technical_fields_and_writes_nothing(): void
    {
        $this->createActiveStructure();
        $live = $this->livePattern();
        $this->seedIncompleteSpecial($live);
        $before = Storage::disk('local')->get(self::REGLAS_PATH);

        $exit = Artisan::call('rem:migrate-auto-fingerprints', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('fingerprint_version en 1 pregunta(s): patron_1_special', $output);
        $this->assertStringNotContainsString('ya migrado (sin cambio)', $output);
        $this->assertSame($before, Storage::disk('local')->get(self::REGLAS_PATH));
    }

    public function test_commit_completes_all_questions_and_pattern_reconciles_as_reviewed_without_touching_decisions(): void
    {
        $structure = $this->createActiveStructure();
        $live = $this->livePattern();
        $this->seedIncompleteSpecial($live);
        $before = $this->storedPatternQuestions();

        $reconciler = app(PatternReconciliationService::class);
        $current = [['id' => 1, 'row_fingerprint' => $live['row_fingerprint'], 'filas' => $live['rows']]];

        // Antes: reconcileLive() trata patron_1_special como legacy y compara
        // fpv2 contra rowset -> requiere_revalidacion (el bug observado).
        $this->assertSame(
            PatternReconciliationService::STATUS_REQUIERE_REVALIDACION,
            $reconciler->reconcileLive($current, [1 => $before])[1]['reconciliation_status']
        );

        $output = $this->commit();
        $this->assertStringContainsString('1 secciones, 1 patrones, 1 preguntas', $output);

        $after = $this->storedPatternQuestions();
        foreach ($after as $i => $q) {
            $this->assertSame(2, $q['fingerprint_version'], $q['id']);
            $this->assertSame($live['fingerprint'], $q['pattern_fingerprint'], $q['id']);
            $this->assertSame($live['rows'], $q['pattern_rows'], $q['id']);
            foreach (self::DECISION_FIELDS as $field) {
                $this->assertSame($before[$i][$field] ?? null, $q[$field] ?? null, "{$q['id']}.{$field} no debe cambiar");
            }
        }

        // Solo la pregunta incompleta recibe la marca de migracion; las que ya
        // estaban completas conservan su metadata intacta.
        $byId = array_column($after, null, 'id');
        $this->assertSame('auto_migrate_v2', $byId['patron_1_special']['fingerprint_migration_source']);
        $this->assertArrayNotHasKey('fingerprint_migrated_at', $byId['patron_1_empty']);
        $this->assertArrayNotHasKey('fingerprint_migrated_at', $byId['patron_1_formula_confirmation']);

        $this->assertSame(
            PatternReconciliationService::STATUS_REVIEWED,
            $reconciler->reconcileLive($current, [1 => $after])[1]['reconciliation_status']
        );

        [, $pattern] = $this->scanPattern($structure);
        $this->assertTrue($pattern['already_v2_matching']);
    }

    public function test_second_commit_is_idempotent_after_completing_metadata(): void
    {
        $this->createActiveStructure();
        $live = $this->livePattern();
        $this->seedIncompleteSpecial($live);

        $this->commit();
        $afterFirst = Storage::disk('local')->get(self::REGLAS_PATH);

        $output = $this->commit();

        $this->assertStringContainsString('0 secciones, 0 patrones, 0 preguntas', $output);
        $this->assertSame($afterFirst, Storage::disk('local')->get(self::REGLAS_PATH));
    }

    public function test_conflicting_v2_fingerprint_is_reported_and_never_overwritten(): void
    {
        $structure = $this->createActiveStructure();
        $live = $this->livePattern();
        $this->seedQuestions([
            $this->question('patron_1_empty', 'pattern_question', 'debe_registrar_cero', $this->v2($live)),
            $this->question('patron_1_special', 'pattern_question', 'si', [
                'pattern_fingerprint' => 'fpv2_otrahuellaajena0', 'fingerprint_version' => 2, 'pattern_rows' => $live['rows'],
            ]),
        ]);
        $before = $this->storedPatternQuestions();

        [, $pattern] = $this->scanPattern($structure);
        $this->assertFalse($pattern['already_v2_matching']);
        $this->assertSame(['patron_1_special'], $pattern['conflicting_v2_question_ids']);

        $output = $this->commit();

        $this->assertStringContainsString('1 patrones con conflicto de fingerprint v2', $output);
        $this->assertSame($before, $this->storedPatternQuestions(), 'un conflicto v2 nunca se sobrescribe');
    }
}
