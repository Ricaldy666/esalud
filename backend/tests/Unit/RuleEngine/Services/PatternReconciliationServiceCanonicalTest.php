<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\RuleEngine\Services\PatternReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre el mecanismo NUEVO de reconciliacion estricta (Fase 3, deuda
 * tecnica #1, 2026-08-12): PatternReconciliationService::reconcileLiveCanonical(),
 * resolveFingerprintVersion() y classifyLegacyPatternForMigration().
 *
 * IMPORTANTE: reconcileLiveCanonical() NO esta conectada a
 * SectionCalibrationMatrixService::buildPatternMatrix() todavia -- ver
 * PatternReconciliationServiceLiveTest.php, que sigue cubriendo
 * reconcileLive() (v1) sin ningun cambio, confirmando la compatibilidad
 * transitoria: implementar este mecanismo no altera el comportamiento
 * real en produccion hasta que se autorice la activacion.
 */
class PatternReconciliationServiceCanonicalTest extends TestCase
{
    private function service(): PatternReconciliationService
    {
        return new PatternReconciliationService();
    }

    private function pattern(int $id, string $canonicalFingerprint, array $filas): array
    {
        return ['id' => $id, 'canonical_fingerprint' => $canonicalFingerprint, 'filas' => $filas];
    }

    // ── resolveFingerprintVersion() ──────────────────────────────────────

    public function test_question_without_fingerprint_version_field_is_always_legacy(): void
    {
        $svc = $this->service();

        // Aunque ya traiga pattern_fingerprint (formato viejo "rowset_..."),
        // sin el campo fingerprint_version explicito nunca se interpreta
        // como v2 -- principio central de la Fase 2.
        $question = ['pattern_fingerprint' => 'rowset_abc123'];

        $this->assertSame(PatternReconciliationService::FINGERPRINT_VERSION_LEGACY, $svc->resolveFingerprintVersion($question));
    }

    public function test_question_with_explicit_fingerprint_version_2_is_canonical(): void
    {
        $svc = $this->service();

        $question = ['pattern_fingerprint' => 'fpv2_abc123', 'fingerprint_version' => 2];

        $this->assertSame(PatternReconciliationService::FINGERPRINT_VERSION_CANONICAL, $svc->resolveFingerprintVersion($question));
    }

    public function test_question_with_explicit_fingerprint_version_1_is_legacy(): void
    {
        $svc = $this->service();

        $question = ['pattern_fingerprint' => 'rowset_abc123', 'fingerprint_version' => 1];

        $this->assertSame(PatternReconciliationService::FINGERPRINT_VERSION_LEGACY, $svc->resolveFingerprintVersion($question));
    }

    // ── reconcileLiveCanonical(): v2 ─────────────────────────────────────

    public function test_v2_fingerprint_match_is_reviewed(): void
    {
        $svc = $this->service();
        $patterns = [$this->pattern(1, 'fpv2_xyz', [12, 13])];
        $questions = [1 => [[
            'pattern_fingerprint' => 'fpv2_xyz',
            'fingerprint_version' => 2,
            'review_status' => 'reviewed',
        ]]];

        $result = $svc->reconcileLiveCanonical($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[1]['reconciliation_status']);
    }

    public function test_v2_fingerprint_mismatch_requires_revalidation(): void
    {
        $svc = $this->service();
        $patterns = [$this->pattern(1, 'fpv2_nuevo', [12, 13])];
        $questions = [1 => [[
            'pattern_fingerprint' => 'fpv2_viejo',
            'fingerprint_version' => 2,
            'review_status' => 'reviewed',
        ]]];

        $result = $svc->reconcileLiveCanonical($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[1]['reconciliation_status']);
        $this->assertNotNull($result[1]['mismatch_detail']);
        $this->assertSame('fpv2_viejo', $result[1]['mismatch_detail']['stored']);
        $this->assertSame('fpv2_nuevo', $result[1]['mismatch_detail']['live']);
    }

    // ── reconcileLiveCanonical(): legacy nunca 'reviewed' por pattern_id ──

    public function test_legacy_answer_without_any_evidence_never_trusted_by_pattern_id(): void
    {
        // Mismo escenario que antes validaba 'reviewed' via legacy_unmigrated
        // en reconcileLive() (v1) -- bajo el mecanismo estricto nunca debe
        // otorgar 'reviewed' solo porque el pattern_id coincide.
        $svc = $this->service();
        $patterns = [$this->pattern(2, 'fpv2_abc', [104, 122, 123])];
        $questions = [2 => [[
            'pattern_id' => 2,
            'response' => 'debe_registrar_cero',
            'review_status' => 'reviewed',
            // sin pattern_fingerprint, sin fingerprint_version, sin pattern_rows
        ]]];

        $result = $svc->reconcileLiveCanonical($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[2]['reconciliation_status']);
    }

    public function test_legacy_answer_with_old_v1_pattern_fingerprint_still_never_trusted(): void
    {
        // Trae pattern_fingerprint (formato viejo, "rowset_..."), pero SIN
        // fingerprint_version -- debe tratarse como legacy, nunca como v2.
        $svc = $this->service();
        $patterns = [$this->pattern(1, 'fpv2_abc', [12, 13])];
        $questions = [1 => [[
            'pattern_fingerprint' => 'rowset_deadbeef',
            'review_status' => 'reviewed',
        ]]];

        $result = $svc->reconcileLiveCanonical($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[1]['reconciliation_status']);
    }

    public function test_pattern_without_any_saved_question_is_pending(): void
    {
        $svc = $this->service();
        $patterns = [$this->pattern(1, 'fpv2_abc', [12, 13])];

        $result = $svc->reconcileLiveCanonical($patterns, []);

        $this->assertSame(PatternReconciliationService::STATUS_PENDING, $result[1]['reconciliation_status']);
    }

    public function test_compatibility_reconcile_live_v1_behavior_is_completely_unchanged(): void
    {
        // Prueba de compatibilidad transitoria: el metodo viejo sigue
        // otorgando 'reviewed' por pattern_id (comportamiento de produccion
        // sin cambios) aunque el nuevo mecanismo estricto exista en la
        // misma clase.
        $svc = $this->service();
        $patterns = [['id' => 2, 'row_fingerprint' => 'fp_b', 'filas' => [104, 122, 123]]];
        $questions = [2 => [[
            'pattern_id' => 2,
            'response' => 'debe_registrar_cero',
            'review_status' => 'reviewed',
        ]]];

        $result = $svc->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[2]['reconciliation_status']);
        $this->assertSame('legacy_unmigrated', $result[2]['backfill_status']);
    }

    // ── classifyLegacyPatternForMigration() ──────────────────────────────

    public function test_rows_match_and_structure_identical_is_auto_migrate(): void
    {
        $svc = $this->service();

        $category = $svc->classifyLegacyPatternForMigration([12, 13, 14], [14, 12, 13], true);

        $this->assertSame(PatternReconciliationService::MIGRATION_AUTO_MIGRATE, $category);
    }

    public function test_rows_match_but_structure_changed_is_quick_confirmation(): void
    {
        $svc = $this->service();

        $category = $svc->classifyLegacyPatternForMigration([12, 13, 14], [12, 13, 14], false);

        $this->assertSame(PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION, $category);
    }

    public function test_rows_match_but_structure_unknown_is_quick_confirmation(): void
    {
        $svc = $this->service();

        $category = $svc->classifyLegacyPatternForMigration([12, 13, 14], [12, 13, 14], null);

        $this->assertSame(PatternReconciliationService::MIGRATION_QUICK_CONFIRMATION, $category);
    }

    public function test_rows_explicitly_different_is_mismatch(): void
    {
        $svc = $this->service();

        $category = $svc->classifyLegacyPatternForMigration([12, 13, 14], [12, 13, 15], true);

        $this->assertSame(PatternReconciliationService::MIGRATION_MISMATCH, $category);
    }

    public function test_no_historical_evidence_at_all_is_full_revalidation(): void
    {
        $svc = $this->service();

        $category = $svc->classifyLegacyPatternForMigration(null, [12, 13, 14], true);

        $this->assertSame(PatternReconciliationService::MIGRATION_FULL_REVALIDATION, $category);
    }
}
