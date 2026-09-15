<?php

namespace Tests\Feature\Calibration;

use App\Domain\RuleEngine\Services\FunctionalRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BM-11.12 (ENGINE_UI_REDUNDANCY -- Hallazgo A de BM-11.11): certifica que
 * el endpoint REAL usado por QuickCalibrationPanel
 * (POST .../sections/{section}/pattern-questions ->
 * CalibrationViewController::saveQuestions()) ya no descarta
 * silenciosamente `scope` -- antes de este fix, Illuminate\Http\Request::
 * validate() lo eliminaba del payload porque no existia ninguna regla para
 * 'questions.*.scope'/sus subcampos, y el scope estructurado certificado en
 * BM-11.6/BM-11.8 nunca llegaba a persistirse realmente.
 *
 * 100% aislado: Storage::fake('local'), RefreshDatabase (esalud_testing),
 * sin tocar reglas-funcionales.json real ni el guardado real de BM18/C
 * (BM-11.11). Sheet/section sinteticos.
 */
class CalibrationPatternQuestionsScopePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'TEST18';
    private const SECTION = 'Z';

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Superadmin');
        Sanctum::actingAs($admin);
    }

    private function baseQuestions(
        int $patternId,
        string $allEst,
        string $exceptions,
        ?array $scope = null,
    ): array {
        $exceptionsQuestion = [
            'id' => "patron_{$patternId}_exceptions",
            'type' => 'pattern_question',
            'question' => 'Excepciones por establecimiento o tipo de establecimiento',
            'response' => $exceptions,
            'observation' => '',
            'pattern_id' => $patternId,
            'pattern_key' => "pattern_{$patternId}",
            'review_status' => 'reviewed',
            'status' => 'answered',
        ];
        if ($scope !== null) {
            $exceptionsQuestion['scope'] = $scope;
        }

        return [
            [
                'id' => "patron_{$patternId}_empty",
                'type' => 'pattern_question',
                'question' => 'Sin datos',
                'response' => 'debe_registrar_cero',
                'observation' => '',
                'pattern_id' => $patternId,
                'pattern_key' => "pattern_{$patternId}",
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => "patron_{$patternId}_all_est",
                'type' => 'pattern_question',
                'question' => 'Aplicabilidad a establecimientos',
                'response' => $allEst,
                'observation' => '',
                'pattern_id' => $patternId,
                'pattern_key' => "pattern_{$patternId}",
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            $exceptionsQuestion,
        ];
    }

    private function resolveRow(int $patternId, int $row): ?array
    {
        $service = app(FunctionalRuleService::class);
        $patterns = [['id' => $patternId, 'key' => "pattern_{$patternId}", 'rows' => [['fila' => $row]]]];

        return $service->getPatternFunctionalRulesForRows(self::SHEET, self::SECTION, $patterns)[$row] ?? null;
    }

    // ── Caso A: scope ausente -> comportamiento legado exacto ────────────

    public function test_caso_a_todos_sin_excepciones_scope_ausente_preserves_legacy_behavior(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            '/api/v1/rule-engine/catalog/A/' . self::SHEET . '/sections/' . self::SECTION . '/pattern-questions',
            ['questions' => $this->baseQuestions(1, 'si', 'no')],
        );

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $exceptionsQuestion = $stored['_questions'][self::SHEET . '_' . self::SECTION][2];
        $this->assertArrayNotHasKey('scope', $exceptionsQuestion);

        $resolved = $this->resolveRow(1, 60);
        $this->assertNotNull($resolved);
        $this->assertSame([], $resolved['included_health_centers']);
        $this->assertSame([], $resolved['excluded_health_centers']);
    }

    // ── Caso B: scope.mode=excluded -> llega intacto y se resuelve ───────

    public function test_caso_b_todos_con_excepciones_scope_excluded_persists_intact_and_resolves(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            '/api/v1/rule-engine/catalog/A/' . self::SHEET . '/sections/' . self::SECTION . '/pattern-questions',
            ['questions' => $this->baseQuestions(2, 'si', 'si', [
                'mode' => 'excluded',
                'included_health_centers' => [],
                'excluded_health_centers' => ['Posta Caleta San Marcos'],
            ])],
        );

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $exceptionsQuestion = $stored['_questions'][self::SHEET . '_' . self::SECTION][2];
        $this->assertSame('excluded', $exceptionsQuestion['scope']['mode']);
        $this->assertSame(['Posta Caleta San Marcos'], $exceptionsQuestion['scope']['excluded_health_centers']);
        $this->assertSame([], $exceptionsQuestion['scope']['included_health_centers']);

        $resolved = $this->resolveRow(2, 61);
        $this->assertNotNull($resolved, 'GAP CERRADO: el scope llega intacto y FunctionalRuleService lo resuelve.');
        $this->assertSame([], $resolved['included_health_centers']);
        $this->assertSame(['Posta Caleta San Marcos'], $resolved['excluded_health_centers']);
    }

    // ── Caso C: scope.mode=included -> llega intacto y se resuelve ───────

    public function test_caso_c_elegir_excepciones_scope_included_persists_intact_and_resolves(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            '/api/v1/rule-engine/catalog/A/' . self::SHEET . '/sections/' . self::SECTION . '/pattern-questions',
            ['questions' => $this->baseQuestions(3, 'depende', 'si', [
                'mode' => 'included',
                'included_health_centers' => ['Posta Caleta Chanavayita'],
                'excluded_health_centers' => [],
            ])],
        );

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $exceptionsQuestion = $stored['_questions'][self::SHEET . '_' . self::SECTION][2];
        $this->assertSame('included', $exceptionsQuestion['scope']['mode']);
        $this->assertSame(['Posta Caleta Chanavayita'], $exceptionsQuestion['scope']['included_health_centers']);

        $resolved = $this->resolveRow(3, 62);
        $this->assertNotNull($resolved, 'GAP CERRADO: all_est=depende con scope valido ahora persiste y se resuelve.');
        $this->assertSame(['Posta Caleta Chanavayita'], $resolved['included_health_centers']);
        $this->assertSame([], $resolved['excluded_health_centers']);
    }

    // ── Caso D: scope malformado -> rechazado, nada se persiste ──────────

    public function test_caso_d_scope_mode_invalido_is_rejected_by_validation_and_persists_nothing(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            '/api/v1/rule-engine/catalog/A/' . self::SHEET . '/sections/' . self::SECTION . '/pattern-questions',
            ['questions' => $this->baseQuestions(4, 'si', 'si', [
                'mode' => 'ambos_a_la_vez', // valor fuera de in:all,included,excluded
                'included_health_centers' => [],
                'excluded_health_centers' => ['X'],
            ])],
        );

        $response->assertStatus(422);
        $this->assertFalse(Storage::disk('local')->exists('certificacion/reglas-funcionales.json'), 'Nada debe persistirse cuando la validacion rechaza el payload.');
    }

    public function test_caso_d_scope_included_health_centers_as_string_is_rejected_by_validation(): void
    {
        Storage::fake('local');

        $response = $this->postJson(
            '/api/v1/rule-engine/catalog/A/' . self::SHEET . '/sections/' . self::SECTION . '/pattern-questions',
            ['questions' => $this->baseQuestions(5, 'depende', 'si', [
                'mode' => 'included',
                'included_health_centers' => 'Posta Caleta Chanavayita', // string, no array
                'excluded_health_centers' => [],
            ])],
        );

        $response->assertStatus(422);
        $this->assertFalse(Storage::disk('local')->exists('certificacion/reglas-funcionales.json'));
    }

    // ── Regresion explicita: campos no aprobados dentro de scope se descartan,
    // igual que el resto del endpoint (test_pattern_questions_do_not_persist_
    // unapproved_client_fields ya cubre el nivel de pregunta) ──────────────

    public function test_scope_does_not_persist_unapproved_nested_fields(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(6, 'si', 'si', [
            'mode' => 'excluded',
            'included_health_centers' => [],
            'excluded_health_centers' => ['X'],
        ]);
        $questions[2]['scope']['unapproved_nested_field'] = 'no_debe_guardarse';

        $response = $this->postJson(
            '/api/v1/rule-engine/catalog/A/' . self::SHEET . '/sections/' . self::SECTION . '/pattern-questions',
            ['questions' => $questions],
        );

        $response->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $scope = $stored['_questions'][self::SHEET . '_' . self::SECTION][2]['scope'];
        $this->assertArrayNotHasKey('unapproved_nested_field', $scope);
    }
}
