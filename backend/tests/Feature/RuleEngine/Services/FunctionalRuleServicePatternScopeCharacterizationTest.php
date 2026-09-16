<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RuleEngine\Services\FunctionalRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BM-11.5/BM-11.6 -- ENGINE_UI_REDUNDANCY (BM-11.3/BM-11.4).
 *
 * BM-11.5 (caracterizacion) fijo el comportamiento LEGADO de
 * FunctionalRuleService::patternQuestionsToFunctionalRule() antes de
 * cualquier cambio. BM-11.6 implemento resolveScope() (contrato aprobado en
 * BM-11.4 §1/§4: Casos A-E) -- este archivo se actualizo para reflejarlo:
 *
 * - Los tests marcados test_legacy_* siguen verdes SIN CAMBIOS de
 *   comportamiento (Serie A, 620+624 respuestas reales) -- es la condicion
 *   no negociable de BM-11.6 §2.
 * - Los tests que documentaban el GAP de "exceptions='si' bloquea todo el
 *   patron incondicionalmente" y "all_est no tiene ningun efecto" fueron
 *   renombrados/reescritos: ya no son *_pre_fix (el gap se corrigio), pasan
 *   a documentar el contrato nuevo (Casos B/C/D/E), con un comentario que
 *   explica exactamente que gap quedo cerrado.
 * - El gap de ValidateRemUploadJob (alcance por establecimiento no
 *   aplicado durante la validacion de una carga) NO se toco en BM-11.6 --
 *   corresponde a BM-11.7. Ver
 *   ValidateRemUploadJobEstablishmentScopeCharacterizationTest.php y
 *   Bm18cSyntheticCharacterizationTest.php, que siguen verdes e intactos.
 *
 * 100% aislado: Storage::fake('local'), sin tocar reglas-funcionales.json
 * real ni ninguna respuesta de Serie A/BM ya guardada. Sheet/section
 * sinteticos (TEST18/Z), sin relacion con ninguna hoja REM real.
 */
class FunctionalRuleServicePatternScopeCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'TEST18';
    private const SECTION = 'Z';

    private function saveAndResolve(array $questions, array $patterns): array
    {
        $service = new FunctionalRuleService();
        $service->saveQuestions(self::SHEET, self::SECTION, $questions);

        return $service->getPatternFunctionalRulesForRows(self::SHEET, self::SECTION, $patterns);
    }

    private function pattern(int $id, array $rows): array
    {
        return [
            'id' => $id,
            'key' => "pattern_{$id}",
            'rows' => array_map(fn ($fila) => ['fila' => $fila], $rows),
        ];
    }

    private function baseQuestions(
        int $patternId,
        string $emptyResponse,
        string $allEstResponse,
        string $exceptionsResponse,
        ?string $observation = null,
        ?array $scope = null,
    ): array {
        $exceptionsQuestion = [
            'id' => "patron_{$patternId}_exceptions",
            'type' => 'pattern_question',
            'pattern_id' => $patternId,
            'pattern_key' => "pattern_{$patternId}",
            'response' => $exceptionsResponse,
            'review_status' => 'reviewed',
            'status' => 'answered',
            'observation' => $observation ?? '',
        ];

        // scope solo se agrega cuando el llamador lo pasa explicitamente --
        // un caso omitido (null) simula un registro legado real, que nunca
        // tuvo este campo.
        if ($scope !== null) {
            $exceptionsQuestion['scope'] = $scope;
        }

        return [
            [
                'id' => "patron_{$patternId}_empty",
                'type' => 'pattern_question',
                'pattern_id' => $patternId,
                'pattern_key' => "pattern_{$patternId}",
                'response' => $emptyResponse,
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => "patron_{$patternId}_all_est",
                'type' => 'pattern_question',
                'pattern_id' => $patternId,
                'pattern_key' => "pattern_{$patternId}",
                'response' => $allEstResponse,
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            $exceptionsQuestion,
        ];
    }

    // ── Caso 1 / A (BM-11.4 §3, escenario legado real de Serie A) ──────────
    // all_est=si + exceptions=no, sin scope -> genera regla heredable
    // normalmente, included=[]/excluded=[]. DEBE permanecer verde sin
    // modificacion (BM-11.6 §2): es el molde exacto de los 620/624
    // registros reales de Serie A.

    public function test_legacy_all_est_si_exceptions_no_generates_inheritable_rule(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(1, 'debe_registrar_cero', 'si', 'no');
        $result = $this->saveAndResolve($questions, [$this->pattern(1, [57])]);

        $this->assertArrayHasKey(57, $result);
        $this->assertSame('debe_registrar_cero', $result[57]['empty_behavior']);
        $this->assertSame([], $result[57]['included_health_centers']);
        $this->assertSame([], $result[57]['excluded_health_centers']);
        $this->assertSame('aprobada', $result[57]['status']);
    }

    // ── Regresion adicional de Serie A: registro real A01/C patron_2 ──────
    // Unica anomalia real encontrada en BM-11.3 (all_est='no', un valor que
    // ya no es opcion valida en la UI actual). BM-11.6 trata cualquier
    // all_est != 'depende' como equivalente a 'si' -- este test replica el
    // registro EXACTO (verificado read-only contra reglas-funcionales.json
    // real antes de implementar) para probar que sigue generando la misma
    // regla que generaba el codigo anterior.

    public function test_legacy_real_a01_c_pattern_2_anomalous_all_est_no_still_generates_rule(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(2, 'debe_registrar_cero', 'no', 'no');
        $result = $this->saveAndResolve($questions, [$this->pattern(2, [40])]);

        $this->assertArrayHasKey(40, $result);
        $this->assertSame('debe_registrar_cero', $result[40]['empty_behavior']);
        $this->assertSame([], $result[40]['included_health_centers']);
        $this->assertSame([], $result[40]['excluded_health_centers']);
    }

    // ── Caso 2 / B (BM-11.4 §3) ──────────────────────────────────────────
    // all_est=si + exceptions=si + scope.excluded=[Centro A]
    // -> aplica a todos EXCEPTO Centro A. GAP CERRADO: antes exceptions='si'
    // bloqueaba el patron entero sin importar el scope.

    public function test_all_est_si_exceptions_si_with_excluded_scope_generates_rule_excluding_center(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            3,
            'debe_registrar_cero',
            'si',
            'si',
            'No aplica a Posta Caleta San Marcos.',
            ['excluded_health_centers' => ['Posta Caleta San Marcos']],
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(3, [58])]);

        $this->assertArrayHasKey(58, $result, 'GAP CERRADO: exceptions=si con scope valido ya no bloquea el patron entero.');
        $this->assertSame([], $result[58]['included_health_centers']);
        $this->assertSame(['Posta Caleta San Marcos'], $result[58]['excluded_health_centers']);
    }

    // ── Caso 3 / C (BM-11.4 §3) ──────────────────────────────────────────
    // all_est=depende + exceptions=si + scope.included=[Centro A]
    // -> aplica UNICAMENTE a Centro A. GAP CERRADO: antes all_est no tenia
    // ningun efecto sobre el resultado.

    public function test_all_est_depende_exceptions_si_with_included_scope_generates_rule_restricted_to_center(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            4,
            'debe_registrar_cero',
            'depende',
            'si',
            'Aplica solo a Posta Caleta Chanavayita.',
            ['included_health_centers' => ['Posta Caleta Chanavayita']],
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(4, [59])]);

        $this->assertArrayHasKey(59, $result, 'GAP CERRADO: all_est=depende con scope valido ahora si tiene efecto real.');
        $this->assertSame(['Posta Caleta Chanavayita'], $result[59]['included_health_centers']);
        $this->assertSame([], $result[59]['excluded_health_centers']);
    }

    // ── Caso 4 / D (BM-11.4 §1 y §3) ─────────────────────────────────────
    // all_est=depende + exceptions=no -> combinacion invalida por diseno
    // (no hay universo por defecto ni lista explicita): no genera regla,
    // requiere revision. Reemplaza al test pre_fix de BM-11.5
    // ("all_est_depende_alone_has_no_effect") -- ANTES ambos (depende+no y
    // si+no) producian el MISMO resultado (el gap); AHORA producen
    // resultados distintos a proposito: si+no genera regla (Caso A),
    // depende+no NO genera regla (Caso D, bloqueo intencional).

    public function test_all_est_depende_exceptions_no_is_invalid_and_blocks_inheritance(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(5, 'debe_registrar_cero', 'depende', 'no');
        $result = $this->saveAndResolve($questions, [$this->pattern(5, [60])]);

        $this->assertArrayNotHasKey(60, $result, 'Caso D: depende+no es una combinacion invalida por diseno (BM-11.4 §1) -- requiere revision.');
    }

    // ── Caso 5 / E, variante "sin scope" (BM-11.4 §3, ex-pre_fix) ──────────
    // all_est=si + exceptions=si SIN scope -> invalida, no genera regla.
    // Reemplaza al test pre_fix "exceptions_si_blocks_pattern_rule_entirely"
    // de BM-11.5: la conclusion (null) es la MISMA, pero el motivo cambio --
    // antes exceptions='si' bloqueaba SIEMPRE (sin importar el scope);
    // ahora bloquea especificamente porque falta un scope valido (fail-safe
    // de BM-11.6 §4), no por diseno binario.

    public function test_exceptions_si_without_scope_is_invalid_and_requires_manual_review(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            6,
            'debe_registrar_cero',
            'si',
            'si',
            'No aplica a Posta Caleta San Marcos.',
            null, // sin campo scope
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(6, [61])]);

        $this->assertArrayNotHasKey(61, $result, 'Caso E: exceptions=si sin scope estructurado sigue sin generar regla -- fail-safe, requiere revision.');
    }

    // ── Caso 6 (BM-11.6 §7.6) ────────────────────────────────────────────
    // all_est=depende + exceptions=si + scope.included=[] (vacio)
    // -> invalida, no genera regla.

    public function test_all_est_depende_exceptions_si_with_empty_scope_list_is_invalid(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            7,
            'debe_registrar_cero',
            'depende',
            'si',
            null,
            ['included_health_centers' => []],
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(7, [62])]);

        $this->assertArrayNotHasKey(62, $result, 'Caso C con lista included vacia sigue siendo invalido -- no hay a quien aplicar.');
    }

    // ── Caso 7 (BM-11.6 §5/§7.7) ─────────────────────────────────────────
    // observation contradictoria con scope -> manda scope, nunca el texto.

    public function test_observation_contradicting_scope_never_overrides_structured_scope(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            8,
            'debe_registrar_cero',
            'si',
            'si',
            // Observation dice lo CONTRARIO de lo que declara scope --
            // dice "no aplica excepcion alguna", pero scope SI declara una.
            'No existen excepciones reales para esta fila.',
            ['excluded_health_centers' => ['Posta Caleta San Marcos']],
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(8, [63])]);

        $this->assertArrayHasKey(63, $result);
        // El resultado sigue el scope estructurado, ignorando por completo
        // el contenido semantico de observation.
        $this->assertSame(['Posta Caleta San Marcos'], $result[63]['excluded_health_centers']);
        $this->assertSame([], $result[63]['included_health_centers']);
    }

    public function test_observation_text_never_produces_scope_by_itself(): void
    {
        Storage::fake('local');

        // El texto de observation simula lo que hoy produce
        // buildScopeObservation() en QuickCalibrationPanel.tsx a partir de
        // centerModes -- pero sigue siendo SOLO texto libre: sin un campo
        // scope explicito, no genera included/excluded_health_centers,
        // aunque mencione un establecimiento real por nombre.
        $questions = $this->baseQuestions(
            9,
            'debe_registrar_cero',
            'si',
            'no',
            'Excepcion real: no aplica a Posta Caleta San Marcos (SAPU/SAR/SUR sin turno).',
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(9, [64])]);

        $this->assertArrayHasKey(64, $result);
        $this->assertSame([], $result[64]['included_health_centers']);
        $this->assertSame([], $result[64]['excluded_health_centers']);
    }

    // ── Caso 8 (BM-11.6 §4/§7.8) ─────────────────────────────────────────
    // Registro legado real: exceptions=si SIN el campo scope (porque nunca
    // existio antes de BM-11.6) -> conserva el comportamiento SEGURO
    // anterior (no genera regla), nunca se reinterpreta silenciosamente
    // como "aplica a todos".

    public function test_legacy_record_exceptions_si_without_scope_field_preserves_safe_prior_behavior(): void
    {
        Storage::fake('local');

        // Forma exacta de un registro legado: sin la clave 'scope' en
        // absoluto (no solo vacia) -- asi es como se veian todas las
        // respuestas reales antes de BM-11.6.
        $legacyQuestions = [
            [
                'id' => 'patron_10_empty',
                'type' => 'pattern_question',
                'pattern_id' => 10,
                'pattern_key' => 'pattern_10',
                'response' => 'debe_registrar_cero',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_10_all_est',
                'type' => 'pattern_question',
                'pattern_id' => 10,
                'pattern_key' => 'pattern_10',
                'response' => 'si',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_10_exceptions',
                'type' => 'pattern_question',
                'pattern_id' => 10,
                'pattern_key' => 'pattern_10',
                'response' => 'si',
                'review_status' => 'reviewed',
                'status' => 'answered',
                // sin 'scope' -- registro legado real
            ],
        ];

        $result = $this->saveAndResolve($legacyQuestions, [$this->pattern(10, [65])]);

        $this->assertArrayNotHasKey(65, $result, 'Registro legado exceptions=si sin scope preserva el comportamiento seguro anterior: no genera regla.');
    }

    // ── Caso 9 (BM-11.6 §7.9) ────────────────────────────────────────────
    // scope con included Y excluded simultaneamente -> el contrato no lo
    // admite (cada caso valido usa una sola lista) -> invalida.

    public function test_scope_with_both_included_and_excluded_simultaneously_is_invalid(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            11,
            'debe_registrar_cero',
            'si',
            'si',
            null,
            [
                'included_health_centers' => ['Posta Caleta Chanavayita'],
                'excluded_health_centers' => ['Posta Caleta San Marcos'],
            ],
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(11, [66])]);

        $this->assertArrayNotHasKey(66, $result, 'included y excluded simultaneos no estan contemplados por el contrato -- fail-safe, requiere revision.');
    }

    // ── Caso 10 (BM-11.6 §7.10) ───────────────────────────────────────────
    // Estructura de scope invalida (valores no-string, tipo incorrecto) ->
    // normaliza a lista vacia -> invalida -- nunca amplia aplicacion
    // silenciosamente a partir de datos malformados.

    public function test_malformed_scope_values_fail_safe_instead_of_expanding_application(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(
            12,
            'debe_registrar_cero',
            'si',
            'si',
            null,
            ['excluded_health_centers' => 'Posta Caleta San Marcos'], // string, no array
        );
        $result = $this->saveAndResolve($questions, [$this->pattern(12, [67])]);

        $this->assertArrayNotHasKey(67, $result, 'scope.excluded_health_centers como string (no array) se normaliza a [] -- invalido, fail-safe.');

        Storage::fake('local');
        $questionsWithGarbageEntries = $this->baseQuestions(
            13,
            'debe_registrar_cero',
            'depende',
            'si',
            null,
            ['included_health_centers' => [123, null, '', '  ']], // sin nombres reales utilizables
        );
        $resultGarbage = $this->saveAndResolve($questionsWithGarbageEntries, [$this->pattern(13, [68])]);

        $this->assertArrayNotHasKey(68, $resultGarbage, 'scope.included_health_centers sin ningun nombre utilizable se normaliza a [] -- invalido, fail-safe.');
    }

    // ── BM-11.48 (NOT_APPLICABLE_GENERIC_FIX, diseño BM-11.47) ────────────
    // empty='no_aplica' es una tercera respuesta terminal valida, ademas de
    // debe_registrar_cero/puede_quedar_vacio -- señala que el patron no
    // corresponde a un concepto REM reportable. El frontend (BM-11.48,
    // FunctionalQuestionsPanel.tsx::questionsForPattern()) ya no envia
    // all_est/exceptions/inconsistency para un patron en este estado -- los
    // siguientes tests replican exactamente esa forma (solo la pregunta
    // 'empty' presente), sin all_est/exceptions en absoluto.

    public function test_empty_no_aplica_without_dependent_questions_generates_rule_with_no_aplica_behavior(): void
    {
        Storage::fake('local');

        $questions = [
            [
                'id' => 'patron_14_empty',
                'type' => 'pattern_question',
                'pattern_id' => 14,
                'pattern_key' => 'pattern_14',
                'response' => 'no_aplica',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
        ];

        $result = $this->saveAndResolve($questions, [$this->pattern(14, [178])]);

        $this->assertArrayHasKey(178, $result, 'no_aplica debe generar una regla real, no descartarse silenciosamente.');
        $this->assertSame('no_aplica', $result[178]['empty_behavior']);
        $this->assertSame([], $result[178]['included_health_centers'], 'sin all_est/exceptions presentes, resolveScope() cae en su Caso A existente -- sin scope inventado.');
        $this->assertSame([], $result[178]['excluded_health_centers']);
        $this->assertSame('aprobada', $result[178]['status']);
        // severity nunca se inventa como 'no_aplica' -- cae al default seguro
        // ya existente ('warning'), inerte porque RuleEngineService/
        // ValidateRemUploadJob (no tocados) ignoran severity por completo
        // cuando empty_behavior==='no_aplica' (BM-11.47 punto D).
        $this->assertSame('warning', $result[178]['severity']);
    }

    public function test_empty_no_aplica_with_logic_correct_present_keeps_no_aplica_behavior(): void
    {
        Storage::fake('local');

        // logic_correct es una señal independiente (BM-11.47 punto B) --
        // su presencia/valor no debe alterar el empty_behavior resuelto.
        $questions = [
            [
                'id' => 'patron_15_logic_correct',
                'type' => 'pattern_question',
                'pattern_id' => 15,
                'pattern_key' => 'pattern_15',
                'response' => 'no',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_15_empty',
                'type' => 'pattern_question',
                'pattern_id' => 15,
                'pattern_key' => 'pattern_15',
                'response' => 'no_aplica',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
        ];

        $result = $this->saveAndResolve($questions, [$this->pattern(15, [179])]);

        $this->assertArrayHasKey(179, $result);
        $this->assertSame('no_aplica', $result[179]['empty_behavior']);
    }

    public function test_empty_puede_quedar_vacio_still_generates_rule_unaffected_by_no_aplica_support(): void
    {
        Storage::fake('local');

        $questions = $this->baseQuestions(16, 'puede_quedar_vacio', 'si', 'no');
        $result = $this->saveAndResolve($questions, [$this->pattern(16, [180])]);

        $this->assertArrayHasKey(180, $result);
        $this->assertSame('puede_quedar_vacio', $result[180]['empty_behavior']);
        $this->assertSame([], $result[180]['included_health_centers']);
        $this->assertSame([], $result[180]['excluded_health_centers']);
    }

    public function test_empty_response_outside_allowed_set_still_returns_null(): void
    {
        Storage::fake('local');

        // Fail-safe: cualquier valor fuera de las 3 respuestas terminales
        // reconocidas (ej. 'depende_del_establecimiento', legado o futuro)
        // sigue sin generar ninguna regla -- no se ampliό el allow-list mas
        // alla de lo pedido explicitamente.
        $questions = [
            [
                'id' => 'patron_17_empty',
                'type' => 'pattern_question',
                'pattern_id' => 17,
                'pattern_key' => 'pattern_17',
                'response' => 'depende_del_establecimiento',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
        ];

        $result = $this->saveAndResolve($questions, [$this->pattern(17, [181])]);

        $this->assertArrayNotHasKey(181, $result);
    }
}
