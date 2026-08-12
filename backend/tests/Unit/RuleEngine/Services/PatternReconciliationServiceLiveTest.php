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

    // ─── Compatibilidad transitoria v1/v2 (hallazgo A01/A, 2026-08-12) ───

    private function canonicalQuestion(int $patternId, string $fingerprint, array $rows, string $reviewStatus = 'reviewed'): array
    {
        return [
            'id' => "patron_{$patternId}_empty",
            'pattern_id' => $patternId,
            'response' => 'debe_registrar_cero',
            'review_status' => $reviewStatus,
            'reviewed_by' => 'Administrador Esalud',
            'reviewed_at' => '2026-07-21T20:45:03.717Z',
            'fingerprint_version' => 2,
            'pattern_fingerprint' => $fingerprint,
            'pattern_rows' => $rows,
            'revalidated_by' => 'Francisco Arcos',
            'revalidated_at' => '2026-08-12T18:43:37+00:00',
            'revalidation_source_type' => 'manual_revalidation',
        ];
    }

    public function test_v2_fingerprint_present_does_not_force_v1_mismatch(): void
    {
        // El bug exacto de A01/A: el patron vigente tiene un row_fingerprint
        // v1 ("rowset_...") que NUNCA puede coincidir con un
        // pattern_fingerprint v2 ("fpv2_..."). Antes del fix, esto forzaba
        // requiere_revalidacion pese a que el patron esta correctamente
        // migrado. Ahora debe ignorar por completo el fingerprint v2 para
        // esta heuristica y caer al comportamiento legacy_unmigrated.
        $patterns = [$this->pattern(1, 'rowset_98290c643a2bba52', [11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22])];
        $question = $this->canonicalQuestion(1, 'fpv2_93841138a04af349', [11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22]);
        $questions = [1 => [$question]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[1]['reconciliation_status']);
        $this->assertSame('fingerprint_canonical_only', $result[1]['backfill_status']);
        $this->assertNull($result[1]['derived_from_fingerprint']);
    }

    public function test_v2_fingerprint_not_reviewed_yet_is_pending_not_mismatch(): void
    {
        $patterns = [$this->pattern(1, 'rowset_x', [11, 12])];
        $question = $this->canonicalQuestion(1, 'fpv2_x', [11, 12], reviewStatus: 'pending');
        $questions = [1 => [$question]];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_PENDING, $result[1]['reconciliation_status']);
    }

    public function test_section_partially_migrated_v1_and_v2_no_false_requires_revalidation(): void
    {
        // Una seccion con un patron ya migrado a v2 (revalidado) y otro
        // todavia legacy v1 -- cada uno debe evaluarse con su propia
        // heuristica, sin que uno contamine al otro.
        $patterns = [
            $this->pattern(1, 'rowset_98290c643a2bba52', [11, 12]),
            $this->pattern(2, 'fp_b', [23, 24, 25, 26]),
        ];
        $questions = [
            1 => [$this->canonicalQuestion(1, 'fpv2_93841138a04af349', [11, 12])],
            2 => [$this->legacyQuestion(2)],
        ];

        $result = $this->service()->reconcileLive($patterns, $questions);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[1]['reconciliation_status']);
        $this->assertSame('fingerprint_canonical_only', $result[1]['backfill_status']);
        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[2]['reconciliation_status']);
        $this->assertSame('legacy_unmigrated', $result[2]['backfill_status']);
    }

    public function test_a01_a_equivalent_four_v2_patterns_not_degraded_by_format(): void
    {
        // Reproduccion directa del caso real A01/A: 4 patrones, cada uno con
        // su propio fingerprint v2 correcto y filas distintas -- ninguno debe
        // degradarse a requiere_revalidacion por el choque de formato v1/v2.
        $patterns = [
            $this->pattern(1, 'rowset_98290c643a2bba52', [11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22]),
            $this->pattern(2, 'rowset_d71612199d65ff0b', [23, 24, 25, 26]),
            $this->pattern(3, 'rowset_39aa387a740e5d84', [27, 28, 31, 32]),
            $this->pattern(4, 'rowset_0e68a19768779352', [29, 30]),
        ];
        $questions = [
            1 => [$this->canonicalQuestion(1, 'fpv2_93841138a04af349', [11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22])],
            2 => [$this->canonicalQuestion(2, 'fpv2_0c882616ba60d8fd', [23, 24, 25, 26])],
            3 => [$this->canonicalQuestion(3, 'fpv2_80b746e03d9c40a6', [27, 28, 31, 32])],
            4 => [$this->canonicalQuestion(4, 'fpv2_f8b44225da5b5702', [29, 30])],
        ];

        $svc = $this->service();
        $result = $svc->reconcileLive($patterns, $questions);

        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame(
                PatternReconciliationService::STATUS_REVIEWED,
                $result[$id]['reconciliation_status'],
                "pattern {$id} no deberia degradarse a requiere_revalidacion"
            );
        }
        $this->assertTrue($svc->computeEffectiveSectionReviewed($result));
    }

    public function test_v2_pattern_still_reviewed_by_reconcile_live_canonical(): void
    {
        // El mismo patron v2 que reconcileLive() ahora trata como
        // 'fingerprint_canonical_only'/reviewed debe seguir siendo
        // reconocido como reviewed por reconcileLiveCanonical() (todavia sin
        // activar en produccion, pero debe quedar listo).
        $svc = $this->service();
        $pattern = ['id' => 1, 'canonical_fingerprint' => 'fpv2_93841138a04af349', 'filas' => [11, 12]];
        $question = $this->canonicalQuestion(1, 'fpv2_93841138a04af349', [11, 12]);

        $result = $svc->reconcileLiveCanonical([$pattern], [1 => [$question]]);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[1]['reconciliation_status']);
    }

    public function test_v2_compatibility_fix_does_not_touch_response_reviewed_by_reviewed_at(): void
    {
        $patterns = [$this->pattern(1, 'rowset_x', [11, 12])];
        $question = $this->canonicalQuestion(1, 'fpv2_x', [11, 12]);
        $questions = [1 => [$question]];

        $this->service()->reconcileLive($patterns, $questions);

        // reconcileLive() es puramente de lectura -- nunca muta el arreglo de
        // preguntas de entrada. Se verifica explicitamente que los campos
        // protegidos permanecen intactos despues de la llamada.
        $this->assertSame('debe_registrar_cero', $question['response']);
        $this->assertSame('Administrador Esalud', $question['reviewed_by']);
        $this->assertSame('2026-07-21T20:45:03.717Z', $question['reviewed_at']);
    }
}
