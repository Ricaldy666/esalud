<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RuleEngine\Services\FunctionalRuleService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la proteccion de metadata canonica v2 en
 * FunctionalRuleService::saveQuestions() -- hallazgo A01/G.-G.2
 * (2026-08-12): el flujo normal de guardado (savePatternQuestions/
 * saveQuestions, usado por QuickCalibrationPanel para decisiones
 * funcionales) usa array_merge($old, $incoming) y el frontend legacy
 * siempre manda pattern_fingerprint en formato v1 (row_fingerprint), sin
 * saber si la pregunta ya fue migrada/revalidada a v2 -- eso pisaba
 * silenciosamente fingerprint_version/pattern_fingerprint/pattern_rows/
 * revalidated_by/revalidated_at/revalidation_source_type ya escritos por
 * applyQuickRevalidation().
 *
 * Principio verificado aqui: una vez que una pregunta existente tiene
 * fingerprint_version=2, esos 6 campos son SERVER-OWNED -- ningun valor
 * entrante del navegador puede pisarlos, sin importar que los incluya el
 * payload. El resto de campos (decisiones funcionales legitimas) sigue
 * actualizandose con normalidad.
 */
class FunctionalRuleServiceSaveQuestionsV2ProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): FunctionalRuleService
    {
        return app(FunctionalRuleService::class);
    }

    private function seedSection(string $sheet, string $section, array $question): void
    {
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => ["{$sheet}_{$section}" => [$question]],
        ]));
    }

    private function loadQuestion(string $sheet, string $section, int $index = 0): array
    {
        $data = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        return $data['_questions']["{$sheet}_{$section}"][$index];
    }

    private function legacyQuestion(): array
    {
        return [
            'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
            'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
            'reviewed_by' => 'Administrador Esalud', 'reviewed_at' => '2026-07-22T15:06:12.221Z',
            'source_type' => null,
        ];
    }

    private function canonicalQuestion(): array
    {
        return array_merge($this->legacyQuestion(), [
            'fingerprint_version' => 2,
            'pattern_fingerprint' => 'fpv2_correct1234abcd',
            'pattern_rows' => [70, 71, 72, 73],
            'revalidated_by' => 'Francisco Arcos',
            'revalidated_at' => '2026-08-12T20:02:25+00:00',
            'revalidation_source_type' => 'manual_revalidation',
        ]);
    }

    public function test_legacy_question_normal_save_behaves_as_before(): void
    {
        $this->seedSection('TESTSHEET', 'X', $this->legacyQuestion());

        $this->service()->saveQuestions('TESTSHEET', 'X', [[
            'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
            'response' => 'puede_quedar_vacio', 'review_status' => 'reviewed',
            'reviewed_by' => 'Otro Usuario', 'reviewed_at' => '2026-08-12T21:00:00Z',
            'source_type' => 'manual',
        ]]);

        $saved = $this->loadQuestion('TESTSHEET', 'X');
        $this->assertSame('puede_quedar_vacio', $saved['response']);
        $this->assertSame('Otro Usuario', $saved['reviewed_by']);
        $this->assertArrayNotHasKey('fingerprint_version', $saved);
    }

    public function test_v2_question_normal_save_preserves_canonical_metadata(): void
    {
        $this->seedSection('TESTSHEET', 'X', $this->canonicalQuestion());

        // Payload del flujo normal, SIN los campos v2 (el frontend real ya
        // no los envia tras el fix, pero esto simula el caso base: la
        // pregunta v2 se guarda de nuevo sin que el payload los toque).
        $this->service()->saveQuestions('TESTSHEET', 'X', [[
            'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
            'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
            'reviewed_by' => 'Administrador Esalud', 'reviewed_at' => '2026-07-22T15:06:12.221Z',
        ]]);

        $saved = $this->loadQuestion('TESTSHEET', 'X');
        $this->assertSame(2, $saved['fingerprint_version']);
        $this->assertSame('fpv2_correct1234abcd', $saved['pattern_fingerprint']);
        $this->assertSame([70, 71, 72, 73], $saved['pattern_rows']);
        $this->assertSame('Francisco Arcos', $saved['revalidated_by']);
        $this->assertSame('2026-08-12T20:02:25+00:00', $saved['revalidated_at']);
        $this->assertSame('manual_revalidation', $saved['revalidation_source_type']);
    }

    public function test_v2_question_payload_attempting_legacy_fingerprint_is_ignored(): void
    {
        $this->seedSection('TESTSHEET', 'X', $this->canonicalQuestion());

        // Payload malicioso/legacy: intenta pisar los 6 campos protegidos
        // con valores v1 -- exactamente lo que QuickCalibrationPanel enviaba
        // antes del fix (pattern_fingerprint = row_fingerprint v1).
        $this->service()->saveQuestions('TESTSHEET', 'X', [[
            'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
            'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
            'reviewed_by' => 'Administrador Esalud', 'reviewed_at' => '2026-07-22T15:06:12.221Z',
            'fingerprint_version' => 1,
            'pattern_fingerprint' => 'rowset_evil0000abcd',
            'pattern_rows' => [999],
            'revalidated_by' => 'Usuario Falso',
            'revalidated_at' => '2000-01-01T00:00:00Z',
            'revalidation_source_type' => 'inventado',
        ]]);

        $saved = $this->loadQuestion('TESTSHEET', 'X');
        $this->assertSame(2, $saved['fingerprint_version']);
        $this->assertSame('fpv2_correct1234abcd', $saved['pattern_fingerprint']);
        $this->assertSame([70, 71, 72, 73], $saved['pattern_rows']);
        $this->assertSame('Francisco Arcos', $saved['revalidated_by']);
        $this->assertSame('2026-08-12T20:02:25+00:00', $saved['revalidated_at']);
        $this->assertSame('manual_revalidation', $saved['revalidation_source_type']);
    }

    public function test_legitimate_functional_decisions_still_update_when_v2(): void
    {
        $this->seedSection('TESTSHEET', 'X', $this->canonicalQuestion());

        $this->service()->saveQuestions('TESTSHEET', 'X', [[
            'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
            // Decision funcional legitima cambiada -- debe aplicarse.
            'response' => 'puede_quedar_vacio',
            'observation' => 'Cambio de criterio funcional revisado en sesion.',
            'review_status' => 'reviewed',
            'reviewed_by' => 'Nuevo Revisor',
            'reviewed_at' => '2026-08-13T09:00:00Z',
            'source_type' => 'manual',
        ]]);

        $saved = $this->loadQuestion('TESTSHEET', 'X');
        // Decisiones funcionales SI cambiaron.
        $this->assertSame('puede_quedar_vacio', $saved['response']);
        $this->assertSame('Cambio de criterio funcional revisado en sesion.', $saved['observation']);
        $this->assertSame('Nuevo Revisor', $saved['reviewed_by']);
        $this->assertSame('2026-08-13T09:00:00Z', $saved['reviewed_at']);
        // Metadata v2 protegida NO cambio.
        $this->assertSame(2, $saved['fingerprint_version']);
        $this->assertSame('fpv2_correct1234abcd', $saved['pattern_fingerprint']);
        $this->assertSame('Francisco Arcos', $saved['revalidated_by']);
        $this->assertSame('manual_revalidation', $saved['revalidation_source_type']);
    }
}
