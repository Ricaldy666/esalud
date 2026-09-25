<?php

namespace Tests\Feature\Calibration;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Recalibracion funcional de un patron ya revisado, por el endpoint REAL de
 * guardado de preguntas de patron (POST .../pattern-questions), con el mismo
 * payload que arma FunctionalQuestionsPanel al "Guardar recalibracion":
 * la nueva decision y la revision actual del patron; section_review y los
 * demas patrones tal como estaban.
 *
 * Aislado: Storage::fake('local') + esalud_testing (RefreshDatabase).
 */
class PatternRecalibrationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/rule-engine/catalog/BM/TEST18/sections/Z/pattern-questions';
    private const KEY = 'TEST18_Z';
    private const OLD = '2026-09-15T20:24:28.170Z';
    private const NOW = '2026-09-25T18:00:00.000Z';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Role::create(['name' => 'Superadmin']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Superadmin');
        Sanctum::actingAs($admin);
    }

    public function test_saving_a_recalibration_persists_the_new_decision_and_current_review(): void
    {
        $this->seedReviewedSection();
        $structure = $this->createStructure();
        $structureBefore = $structure->fresh()->getAttributes();

        $payload = $this->storedQuestions();
        // Lo que envia el panel tras recalibrar el patron 1.
        foreach ($payload as &$q) {
            if (($q['pattern_id'] ?? null) === 1) {
                $q['reviewed_at'] = self::NOW;
                $q['reviewed_by'] = 'Francisco Arcos';
                if ($q['id'] === 'patron_1_empty') {
                    $q['response'] = FunctionalRuleService::FORBIDDEN_DATA_ENTRY;
                }
            }
        }
        unset($q);

        $this->postJson(self::URL, ['questions' => $payload])->assertOk();

        $byId = $this->storedById();

        // Nueva decision persistida y revision actual del patron.
        $this->assertSame(FunctionalRuleService::FORBIDDEN_DATA_ENTRY, $byId['patron_1_empty']['response']);
        foreach (['patron_1_empty', 'patron_1_inconsistency', 'patron_1_formula_confirmation'] as $id) {
            $this->assertSame('reviewed', $byId[$id]['review_status'], $id);
            $this->assertSame(self::NOW, $byId[$id]['reviewed_at'], $id);
            $this->assertSame('Francisco Arcos', $byId[$id]['reviewed_by'], $id);
        }

        // La seccion permanece confirmada, sin cambiar su revision.
        $this->assertSame('section_reviewed', $byId['section_review']['review_status']);
        $this->assertSame(self::OLD, $byId['section_review']['reviewed_at']);

        // Otros patrones no cambian.
        $this->assertSame('debe_registrar_cero', $byId['patron_2_empty']['response']);
        $this->assertSame(self::OLD, $byId['patron_2_empty']['reviewed_at']);
        $this->assertSame('Administrador Esalud', $byId['patron_2_empty']['reviewed_by']);

        // Historial con valor anterior y nuevo.
        $history = app(FunctionalRuleService::class)->getQuestionsHistory('TEST18', 'Z');
        $entry = collect($history)->firstWhere('question_id', 'patron_1_empty');
        $this->assertSame('debe_registrar_cero', $entry['previous']);
        $this->assertSame(FunctionalRuleService::FORBIDDEN_DATA_ENTRY, $entry['new']);

        // La decision recalibrada es la que usa el motor para el patron.
        $rules = app(FunctionalRuleService::class)->getPatternFunctionalRulesForRows('TEST18', 'Z', [
            ['id' => 1, 'key' => 'pattern_1', 'rows' => [['fila' => 10], ['fila' => 11]]],
        ]);
        $this->assertSame(FunctionalRuleService::FORBIDDEN_DATA_ENTRY, $rules[10]['empty_behavior']);

        // Certificacion / estructura tecnica intacta.
        $this->assertSame($structureBefore, $structure->fresh()->getAttributes());
        $this->assertSame(1, RemTemplateStructure::count());
    }

    private function seedReviewedSection(): void
    {
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [self::KEY => $this->storedQuestions()],
        ]));
    }

    private function storedQuestions(): array
    {
        $question = fn (string $id, int $patternId, string $response, string $type = 'pattern_question') => [
            'id' => $id,
            'type' => $type,
            'question' => $id,
            'response' => $response,
            'pattern_id' => $patternId,
            'pattern_key' => "pattern_{$patternId}",
            'review_status' => 'reviewed',
            'reviewed_at' => self::OLD,
            'reviewed_by' => 'Administrador Esalud',
            'status' => 'answered',
        ];

        return [
            $question('patron_1_empty', 1, 'debe_registrar_cero'),
            $question('patron_1_inconsistency', 1, 'error'),
            $question('patron_1_formula_confirmation', 1, 'confirmed', 'pattern_confirmation'),
            $question('patron_2_empty', 2, 'debe_registrar_cero'),
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'question' => 'Seccion Z revisada funcionalmente',
                'response' => 'revisada',
                'review_status' => 'section_reviewed',
                'reviewed_at' => self::OLD,
                'reviewed_by' => 'Administrador Esalud',
                'status' => 'answered',
            ],
        ];
    }

    private function storedById(): array
    {
        $data = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        return collect($data['_questions'][self::KEY])->keyBy('id')->all();
    }

    private function createStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'BM',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'hash_estructura' => 'hash_recalibration_' . uniqid(),
            'status' => 'active',
        ]);
    }
}
