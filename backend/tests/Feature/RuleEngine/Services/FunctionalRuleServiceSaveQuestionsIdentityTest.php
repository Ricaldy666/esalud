<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RuleEngine\Services\FunctionalRuleService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regresion del emparejamiento por posicion detectado en la prueba real BM
 * del 2026-09-25: saveQuestions() fusionaba cada pregunta entrante con la que
 * ocupaba su misma posicion ($existing[$i]). Cuando el payload cambiaba de
 * orden o de largo (un patron no_aplica deja de enviar all_est/exceptions/
 * inconsistency), las preguntas heredaban metadatos ajenos (pattern_key,
 * fingerprint, estado de reconciliacion) y el historial registraba pares
 * "anterior -> nuevo" de preguntas distintas.
 *
 * Principio verificado: el emparejamiento es por 'id'. Todo contra
 * Storage::fake(), nunca contra el reglas-funcionales.json real.
 */
class FunctionalRuleServiceSaveQuestionsIdentityTest extends TestCase
{
    private const SHEET = 'BM18SIM';
    private const SECTION = 'A';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_reordered_payload_keeps_each_answer_and_fingerprint_with_its_question(): void
    {
        $this->seedQuestions([
            $this->question('patron_1_empty', 1, 'puede_quedar_vacio', 'rowset_p1'),
            $this->question('patron_2_empty', 2, 'puede_quedar_vacio', 'rowset_p2'),
        ]);

        $this->service()->saveQuestions(self::SHEET, self::SECTION, [
            ['id' => 'patron_2_empty', 'question' => 'q2', 'response' => 'no_aplica'],
            ['id' => 'patron_1_empty', 'question' => 'q1', 'response' => 'debe_registrar_cero'],
        ], 'BM');

        $byId = $this->storedById();
        $this->assertCount(2, $byId);
        $this->assertSame('debe_registrar_cero', $byId['patron_1_empty']['response']);
        $this->assertSame('rowset_p1', $byId['patron_1_empty']['pattern_fingerprint']);
        $this->assertSame(1, $byId['patron_1_empty']['pattern_id']);
        $this->assertSame('no_aplica', $byId['patron_2_empty']['response']);
        $this->assertSame('rowset_p2', $byId['patron_2_empty']['pattern_fingerprint']);
        $this->assertSame(2, $byId['patron_2_empty']['pattern_id']);
    }

    public function test_dependent_questions_dropped_by_no_aplica_do_not_shift_metadata(): void
    {
        // Mismo shape que BM18A/A: al pasar el patron 2 a no_aplica, la UI deja
        // de enviar patron_2_all_est/patron_2_exceptions y section_review sube
        // de posicion en el payload.
        $this->seedQuestions([
            $this->question('patron_1_empty', 1, 'debe_registrar_cero', 'rowset_p1'),
            $this->question('patron_2_empty', 2, 'puede_quedar_vacio', 'rowset_p2'),
            $this->question('patron_2_all_est', 2, 'si', 'rowset_p2'),
            $this->question('patron_2_exceptions', 2, 'no', 'rowset_p2'),
            ['id' => 'section_review', 'type' => 'section_review', 'response' => 'pendiente', 'review_status' => 'pending'],
        ]);

        $this->service()->saveQuestions(self::SHEET, self::SECTION, [
            ['id' => 'patron_1_empty', 'question' => 'q', 'response' => 'debe_registrar_cero'],
            ['id' => 'patron_2_empty', 'question' => 'q', 'response' => 'no_aplica'],
            ['id' => 'section_review', 'type' => 'section_review', 'question' => 'Seccion A revisada', 'response' => 'revisada', 'review_status' => 'section_reviewed'],
        ], 'BM');

        $byId = $this->storedById();

        $this->assertCount(5, $byId, 'Las preguntas no enviadas se conservan, no se eliminan.');
        $this->assertSame('revisada', $byId['section_review']['response']);
        $this->assertArrayNotHasKey('pattern_key', $byId['section_review'], 'section_review no debe heredar el pattern_key de otra pregunta.');
        $this->assertArrayNotHasKey('pattern_fingerprint', $byId['section_review']);
        $this->assertSame('no_aplica', $byId['patron_2_empty']['response']);
        $this->assertSame('si', $byId['patron_2_all_est']['response'], 'La pregunta dependiente no enviada queda intacta.');
        $this->assertSame('no', $byId['patron_2_exceptions']['response']);
        $this->assertArrayNotHasKey('updated_at', $byId['patron_2_all_est'], 'Una pregunta no enviada no se reescribe.');
    }

    public function test_protected_v2_metadata_never_migrates_to_another_question(): void
    {
        $this->seedQuestions([
            array_merge($this->question('patron_1_empty', 1, 'debe_registrar_cero', 'fpv2_p1_canonical'), [
                'fingerprint_version' => 2,
                'pattern_rows' => [13, 14],
                'revalidated_by' => 'Administrador Esalud',
            ]),
            $this->question('patron_2_empty', 2, 'no_aplica', 'rowset_p2'),
        ]);

        $this->service()->saveQuestions(self::SHEET, self::SECTION, [
            ['id' => 'patron_2_empty', 'question' => 'q2', 'response' => 'no_aplica', 'pattern_fingerprint' => 'rowset_p2'],
            ['id' => 'patron_1_empty', 'question' => 'q1', 'response' => 'debe_registrar_cero', 'pattern_fingerprint' => 'rowset_legacy_p1'],
        ], 'BM');

        $byId = $this->storedById();

        $this->assertSame('fpv2_p1_canonical', $byId['patron_1_empty']['pattern_fingerprint'], 'El fingerprint v2 protegido sigue en su propia pregunta.');
        $this->assertSame(2, $byId['patron_1_empty']['fingerprint_version']);
        $this->assertSame('rowset_p2', $byId['patron_2_empty']['pattern_fingerprint'], 'El fingerprint v2 de otra pregunta no migra aqui.');
        $this->assertArrayNotHasKey('fingerprint_version', $byId['patron_2_empty']);
        $this->assertArrayNotHasKey('revalidated_by', $byId['patron_2_empty']);
    }

    public function test_new_question_is_appended_without_overwriting_an_existing_one(): void
    {
        $this->seedQuestions([
            $this->question('patron_1_empty', 1, 'debe_registrar_cero', 'rowset_p1'),
        ]);

        $this->service()->saveQuestions(self::SHEET, self::SECTION, [
            ['id' => 'patron_4_empty', 'question' => 'q4', 'response' => 'debe_registrar_cero', 'pattern_id' => 4],
        ], 'BM');

        $byId = $this->storedById();
        $this->assertCount(2, $byId);
        $this->assertSame('rowset_p1', $byId['patron_1_empty']['pattern_fingerprint']);
        $this->assertSame(4, $byId['patron_4_empty']['pattern_id']);
        $this->assertArrayNotHasKey('pattern_fingerprint', $byId['patron_4_empty']);
    }

    public function test_history_records_the_real_previous_answer_of_each_question(): void
    {
        $this->seedQuestions([
            ['id' => 'patron_1_formula_confirmation', 'type' => 'pattern_confirmation', 'response' => 'confirmed', 'pattern_id' => 1],
            $this->question('patron_1_empty', 1, 'puede_quedar_vacio', 'rowset_p1'),
        ]);

        $this->service()->saveQuestions(self::SHEET, self::SECTION, [
            ['id' => 'patron_1_empty', 'question' => 'q', 'response' => 'debe_registrar_cero'],
            ['id' => 'patron_1_formula_confirmation', 'question' => 'q', 'response' => 'confirmed'],
        ], 'BM');

        $history = $this->service()->getQuestionsHistory(self::SHEET, self::SECTION);

        $this->assertCount(1, $history, 'Solo cambio una respuesta real.');
        $this->assertSame('patron_1_empty', $history[0]['question_id']);
        $this->assertSame('puede_quedar_vacio', $history[0]['previous']);
        $this->assertSame('debe_registrar_cero', $history[0]['new']);
    }

    public function test_legacy_payload_without_id_never_overwrites_an_identified_question(): void
    {
        $this->seedQuestions([
            $this->question('patron_1_empty', 1, 'debe_registrar_cero', 'rowset_p1'),
            ['type' => 'aggregate_pattern', 'question' => 'Pregunta legada', 'response' => 'antes'],
        ]);

        $this->service()->saveQuestions(self::SHEET, self::SECTION, [
            ['question' => 'Anonima en posicion 0', 'response' => 'otra'],
            ['question' => 'Pregunta legada', 'response' => 'despues'],
        ], 'BM');

        $stored = $this->stored();

        $this->assertCount(3, $stored);
        $this->assertSame('patron_1_empty', $stored[0]['id']);
        $this->assertSame('debe_registrar_cero', $stored[0]['response'], 'Una pregunta anonima no pisa una identificada.');
        $this->assertSame('despues', $stored[1]['response'], 'Una pregunta legada sin id sigue emparejando por posicion.');
        $this->assertSame('otra', $stored[2]['response']);
    }

    private function service(): FunctionalRuleService
    {
        return app(FunctionalRuleService::class);
    }

    private function question(string $id, int $patternId, string $response, string $fingerprint): array
    {
        return [
            'id' => $id,
            'type' => 'pattern_question',
            'response' => $response,
            'pattern_id' => $patternId,
            'pattern_key' => 'pattern_' . $patternId,
            'pattern_fingerprint' => $fingerprint,
            'review_status' => 'reviewed',
            'status' => 'answered',
        ];
    }

    private function seedQuestions(array $questions): void
    {
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [self::SHEET . '_' . self::SECTION => $questions],
        ]));
    }

    private function stored(): array
    {
        $data = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        return $data['_questions'][self::SHEET . '_' . self::SECTION];
    }

    private function storedById(): array
    {
        $byId = [];
        foreach ($this->stored() as $question) {
            $byId[$question['id']] = $question;
        }

        return $byId;
    }
}
