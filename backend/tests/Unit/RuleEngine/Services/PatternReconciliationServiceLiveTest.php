<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\RuleEngine\Services\PatternReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre PatternReconciliationService::reconcileLive() -- la reconciliacion
 * que corre en cada carga de SectionCalibrationMatrixService::buildPatternMatrix(),
 * para cualquier hoja-seccion (no solo A09/F-G). A diferencia de reconcile()
 * (que compara un "antes" y un "despues" simulados para la migracion
 * puntual), esta variante evalua los patrones vigentes contra las respuestas
 * ya guardadas sin necesitar un snapshot "antes" separado.
 */
class PatternReconciliationServiceLiveTest extends TestCase
{
    private function service(): PatternReconciliationService
    {
        return new PatternReconciliationService();
    }

    private function pattern(int $id, string $fingerprint, array $filas): array
    {
        return ['id' => $id, 'row_fingerprint' => $fingerprint, 'filas' => $filas];
    }

    private function legacyQuestion(int $patternId, string $reviewStatus = 'reviewed'): array
    {
        return [
            'id' => "patron_{$patternId}_empty",
            'pattern_id' => $patternId,
            'response' => 'debe_registrar_cero',
            'review_status' => $reviewStatus,
        ];
    }

    public function test_pattern_without_any_saved_question_is_pending(): void
    {
        $patterns = [$this->pattern(1, 'fp_a', [91, 92])];

        $result = $this->service()->reconcileLive($patterns, []);

        $this->assertSame(PatternReconciliationService::STATUS_PENDING, $result[1]['reconciliation_status']);
        $this->assertNull($result[1]['backfill_status']);
    }

    public function test_legacy_answer_without_fingerprint_or_rows_is_trusted_by_pattern_id(): void
    {
        // Caso de casi todas las respuestas existentes hoy: sin structural
        // change desde que se respondieron, el emparejamiento por pattern_id
        // sigue siendo valido -- no debe degradarse a requiere_revalidacion.
        $patterns = [$this->pattern(2, 'fp_b', [104, 122, 123])];
        $questions = [2 => [$this->legacyQuestion(2)]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[2]['reconciliation_status']);
        $this->assertSame('legacy_unmigrated', $result[2]['backfill_status']);
        $this->assertNull($result[2]['derived_from_fingerprint']);
    }

    public function test_legacy_answer_not_yet_reviewed_is_pending_not_reviewed(): void
    {
        $patterns = [$this->pattern(2, 'fp_b', [104])];
        $questions = [2 => [$this->legacyQuestion(2, 'pending')]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_PENDING, $result[2]['reconciliation_status']);
    }

    public function test_matching_fingerprint_is_trusted_as_reviewed(): void
    {
        $patterns = [$this->pattern(7, 'fp_118', [118])];
        $question = $this->legacyQuestion(7);
        $question['pattern_fingerprint'] = 'fp_118';
        $question['pattern_rows'] = [118];
        $questions = [7 => [$question]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[7]['reconciliation_status']);
        $this->assertSame('fingerprint_native', $result[7]['backfill_status']);
    }

    public function test_mismatched_fingerprint_forces_revalidation_even_if_pattern_id_collides(): void
    {
        // El caso exacto del bug: "patron 6" antes = fila 118. Si una futura
        // migracion escribio pattern_fingerprint=fp_118 en la respuesta
        // guardada, y el patron 6 vigente ahora tiene un fingerprint
        // distinto (mismas filas nuevas), NUNCA debe leerse como reviewed.
        $patterns = [$this->pattern(6, 'fp_fragmento_nuevo', [116, 138, 139, 141, 142])];
        $question = $this->legacyQuestion(6);
        $question['pattern_fingerprint'] = 'fp_118';
        $questions = [6 => [$question]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[6]['reconciliation_status']);
        $this->assertSame(['fp_118'], $result[6]['derived_from_fingerprint']);
        $this->assertSame('debe_registrar_cero', $result[6]['historical_reference']['response']);
        $this->assertSame('fp_118', $result[6]['historical_reference']['pattern_fingerprint']);
    }

    public function test_mismatched_pattern_rows_without_fingerprint_forces_revalidation(): void
    {
        $patterns = [$this->pattern(1, 'fp_x', [91])];
        $question = $this->legacyQuestion(1);
        $question['pattern_rows'] = [91, 116, 138, 139, 141, 142];
        $questions = [1 => [$question]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[1]['reconciliation_status']);
        $this->assertSame('legacy_partial', $result[1]['backfill_status']);
        $this->assertNull($result[1]['derived_from_fingerprint']);
        $this->assertNotNull($result[1]['historical_reference']);
    }

    public function test_matching_pattern_rows_without_fingerprint_is_trusted(): void
    {
        $patterns = [$this->pattern(1, 'fp_x', [91, 92, 93])];
        $question = $this->legacyQuestion(1);
        $question['pattern_rows'] = [93, 91, 92]; // orden distinto, mismo conjunto
        $questions = [1 => [$question]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[1]['reconciliation_status']);
        $this->assertSame('legacy_partial', $result[1]['backfill_status']);
    }

    public function test_effective_section_reviewed_reuses_same_computation_as_migration_reconcile(): void
    {
        $svc = $this->service();
        $patterns = [
            $this->pattern(1, 'fp_a', [91]),
            $this->pattern(2, 'fp_b', [92]),
        ];
        $questions = [
            1 => [$this->legacyQuestion(1)],
            2 => [$this->legacyQuestion(2)],
        ];

        $result = $svc->reconcileLive($patterns, $questions);

        $this->assertTrue($svc->computeEffectiveSectionReviewed($result));

        $questions[2][0]['review_status'] = 'pending';
        $resultWithPending = $svc->reconcileLive($patterns, $questions);
        $this->assertFalse($svc->computeEffectiveSectionReviewed($resultWithPending));
    }
}
