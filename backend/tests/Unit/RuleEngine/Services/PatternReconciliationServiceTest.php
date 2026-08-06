<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\RuleEngine\Services\PatternReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre PatternReconciliationService::reconcile() -- las reglas de
 * migracion aprobadas en el diseño de Fase 2 (2026-08-06), y el caso real
 * que motivo todo esto: en A09/F, "patron 6" significaba la fila 118 antes
 * de incorporar AO-AX, y pasa a significar un grupo de 5 filas completamente
 * distinto despues -- la reconciliacion por fingerprint debe impedir que la
 * respuesta vieja de "patron 6" quede pegada al "patron 6" nuevo.
 */
class PatternReconciliationServiceTest extends TestCase
{
    private function service(): PatternReconciliationService
    {
        return new PatternReconciliationService();
    }

    private function pattern(int $id, string $fingerprint, array $filas): array
    {
        return ['id' => $id, 'row_fingerprint' => $fingerprint, 'filas' => $filas];
    }

    private function reviewedQuestion(string $id, int $patternId, string $response = 'debe_registrar_cero'): array
    {
        return [
            'id' => $id,
            'pattern_id' => $patternId,
            'pattern_key' => "pattern_{$patternId}",
            'response' => $response,
            'review_status' => 'reviewed',
            'reviewed_by' => 'Francisco Arcos',
            'reviewed_at' => '2026-08-04T16:25:43.506Z',
            'technical_signature' => 'sig_123',
            'structure_version' => '36',
            'updated_at' => '2026-08-04T16:27:30+00:00',
        ];
    }

    public function test_identical_fingerprint_preserves_response_and_reviewed_status(): void
    {
        $before = [$this->pattern(2, 'fp_A', [104, 122, 123])];
        $questions = [2 => [$this->reviewedQuestion('patron_2_empty', 2)]];
        $after = [$this->pattern(2, 'fp_A', [104, 122, 123])];

        $result = $this->service()->reconcile($before, $questions, $after);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[2]['reconciliation_status']);
        $this->assertSame('debe_registrar_cero', $result[2]['carried_over_answers'][0]['response']);
        $this->assertSame('reviewed', $result[2]['carried_over_answers'][0]['review_status']);
        $this->assertSame('Francisco Arcos', $result[2]['carried_over_answers'][0]['reviewed_by']);
    }

    public function test_same_rows_different_id_is_remapped_automatically(): void
    {
        // Caso real: F patron 6 (fila 118) -> patron 7 tras el fix, mismas filas.
        $before = [$this->pattern(6, 'fp_118', [118])];
        $questions = [6 => [$this->reviewedQuestion('patron_6_empty', 6)]];
        $after = [$this->pattern(7, 'fp_118', [118])];

        $result = $this->service()->reconcile($before, $questions, $after);

        $this->assertSame(PatternReconciliationService::STATUS_REVIEWED, $result[7]['reconciliation_status']);
        $this->assertSame('patron_7_empty', $result[7]['carried_over_answers'][0]['id']);
        $this->assertSame(7, $result[7]['carried_over_answers'][0]['pattern_id']);
        $this->assertSame('debe_registrar_cero', $result[7]['carried_over_answers'][0]['response']);
    }

    public function test_pattern_6_old_answer_for_row_118_is_never_applied_to_new_pattern_6_with_different_rows(): void
    {
        // El caso exacto encontrado en la auditoria: "patron 6" antes = fila
        // 118. Tras el fix, "patron 6" pasa a ser un grupo nuevo de filas
        // (116,138,139,141,142) desprendido del antiguo patron 1. El id 6
        // colisiona, pero el fingerprint no.
        $before = [
            $this->pattern(1, 'fp_grupo_grande', [91, 116, 138, 139, 141, 142]),
            $this->pattern(6, 'fp_118', [118]),
        ];
        $questions = [
            1 => [$this->reviewedQuestion('patron_1_empty', 1, 'debe_registrar_cero')],
            6 => [$this->reviewedQuestion('patron_6_empty', 6, 'puede_quedar_vacio')],
        ];
        // Tras el fix: el fragmento desprendido ocupa el id 6, con filas
        // completamente distintas a las que ese id significaba antes.
        $after = [
            $this->pattern(1, 'fp_resto', [91]),
            $this->pattern(6, 'fp_fragmento', [116, 138, 139, 141, 142]),
        ];

        $result = $this->service()->reconcile($before, $questions, $after);

        // El nuevo "patron 6" NUNCA debe heredar la respuesta de fila 118.
        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[6]['reconciliation_status']);
        $this->assertNull($result[6]['carried_over_answers'], 'No debe haber respuesta activa aplicada automaticamente');
        // La respuesta vieja de fila 118 tampoco debe aparecer como historica
        // aqui -- el nuevo patron 6 no comparte ninguna fila con el viejo patron 6.
        $sourceFingerprints = array_column($result[6]['historical_answers'] ?? [], 'source_fingerprint');
        $this->assertNotContains('fp_118', $sourceFingerprints);
    }

    public function test_split_pattern_marks_fragments_as_requiere_revalidacion_with_historical_reference(): void
    {
        $before = [$this->pattern(1, 'fp_original', [91, 92, 93, 94, 95])];
        $questions = [1 => [$this->reviewedQuestion('patron_1_empty', 1, 'debe_registrar_cero')]];
        $after = [
            $this->pattern(1, 'fp_fragmento_a', [91, 92, 93]),
            $this->pattern(2, 'fp_fragmento_b', [94, 95]),
        ];

        $result = $this->service()->reconcile($before, $questions, $after);

        foreach ([1, 2] as $id) {
            $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[$id]['reconciliation_status']);
            $this->assertNull($result[$id]['carried_over_answers']);
            $this->assertContains('fp_original', $result[$id]['derived_from_fingerprint']);
            $this->assertSame('debe_registrar_cero', $result[$id]['historical_answers'][0]['questions'][0]['response']);
        }
    }

    public function test_merged_patterns_are_marked_as_requiere_revalidacion_with_both_sources(): void
    {
        $before = [
            $this->pattern(1, 'fp_a', [91, 92]),
            $this->pattern(2, 'fp_b', [93, 94]),
        ];
        $questions = [
            1 => [$this->reviewedQuestion('patron_1_empty', 1, 'debe_registrar_cero')],
            2 => [$this->reviewedQuestion('patron_2_empty', 2, 'puede_quedar_vacio')],
        ];
        $after = [$this->pattern(1, 'fp_fusionado', [91, 92, 93, 94])];

        $result = $this->service()->reconcile($before, $questions, $after);

        $this->assertSame(PatternReconciliationService::STATUS_REQUIERE_REVALIDACION, $result[1]['reconciliation_status']);
        $this->assertNull($result[1]['carried_over_answers']);
        $this->assertCount(2, $result[1]['derived_from_fingerprint']);
        $this->assertContains('fp_a', $result[1]['derived_from_fingerprint']);
        $this->assertContains('fp_b', $result[1]['derived_from_fingerprint']);
        $this->assertCount(2, $result[1]['historical_answers']);
    }

    public function test_new_pattern_without_any_predecessor_is_pending(): void
    {
        $before = [$this->pattern(1, 'fp_a', [91, 92])];
        $questions = [1 => [$this->reviewedQuestion('patron_1_empty', 1)]];
        $after = [
            $this->pattern(1, 'fp_a', [91, 92]),
            $this->pattern(2, 'fp_nueva', [500, 501]),
        ];

        $result = $this->service()->reconcile($before, $questions, $after);

        $this->assertSame(PatternReconciliationService::STATUS_PENDING, $result[2]['reconciliation_status']);
        $this->assertNull($result[2]['carried_over_answers']);
        $this->assertNull($result[2]['historical_answers']);
        $this->assertNull($result[2]['derived_from_fingerprint']);
    }

    public function test_section_reviewed_is_invalidated_when_any_pattern_is_not_reviewed(): void
    {
        $svc = $this->service();

        $allReviewed = [
            1 => ['reconciliation_status' => PatternReconciliationService::STATUS_REVIEWED],
            2 => ['reconciliation_status' => PatternReconciliationService::STATUS_REVIEWED],
        ];
        $this->assertTrue($svc->computeEffectiveSectionReviewed($allReviewed));

        $oneRequiresRevalidation = [
            1 => ['reconciliation_status' => PatternReconciliationService::STATUS_REVIEWED],
            2 => ['reconciliation_status' => PatternReconciliationService::STATUS_REQUIERE_REVALIDACION],
        ];
        $this->assertFalse($svc->computeEffectiveSectionReviewed($oneRequiresRevalidation));

        $onePending = [
            1 => ['reconciliation_status' => PatternReconciliationService::STATUS_REVIEWED],
            2 => ['reconciliation_status' => PatternReconciliationService::STATUS_PENDING],
        ];
        $this->assertFalse($svc->computeEffectiveSectionReviewed($onePending));

        $oneUnresolved = [
            1 => ['reconciliation_status' => PatternReconciliationService::STATUS_REVIEWED],
            2 => ['reconciliation_status' => PatternReconciliationService::STATUS_UNRESOLVED],
        ];
        $this->assertFalse($svc->computeEffectiveSectionReviewed($oneUnresolved));

        $this->assertFalse($svc->computeEffectiveSectionReviewed([]), 'seccion sin patrones nunca es reviewed');
    }

    public function test_migration_preserves_all_original_answer_fields(): void
    {
        $before = [$this->pattern(6, 'fp_118', [118])];
        $original = $this->reviewedQuestion('patron_6_empty', 6, 'puede_quedar_vacio');
        $original['observation'] = 'observacion original';
        $questions = [6 => [$original]];
        $after = [$this->pattern(9, 'fp_118', [118])];

        $result = $this->service()->reconcile($before, $questions, $after);
        $carried = $result[9]['carried_over_answers'][0];

        foreach (['response', 'review_status', 'reviewed_by', 'reviewed_at', 'technical_signature', 'structure_version', 'updated_at', 'observation'] as $field) {
            $this->assertSame($original[$field], $carried[$field], "el campo {$field} debe conservarse identico");
        }
        // Solo estos 2 cambian, porque el id numerico del patron cambio:
        $this->assertSame(9, $carried['pattern_id']);
        $this->assertSame('patron_9_empty', $carried['id']);
    }
}
