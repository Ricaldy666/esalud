<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\MismatchResolutionAuditService;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre el flujo de resolucion FORMAL de patrones MISMATCH etiquetados
 * human_review (2026-09-11, diseño auditado por el usuario, implementado en
 * esta sesion): POST .../patterns/{id}/mismatch-resolution/full-review.
 *
 * A diferencia de confirmMismatchResolution() (safe_reconfirm/
 * structural_row_exclusion, que NUNCA tocan response/reviewed_by/
 * reviewed_at -- ver MismatchResolutionApiTest), este endpoint exige una
 * revision funcional COMPLETA nueva (todas las preguntas del patron, sin
 * excepcion) y persiste, junto con la respuesta, el fingerprint/filas/
 * version de estructura ACTUALES -- nunca los que enviaria el cliente.
 *
 * Todas las fixtures son sinteticas (hoja A01, seccion B) -- A30/C y
 * cualquier otra seccion real de Serie A no se tocan en estos tests. Mismo
 * patron de fixtures que MismatchResolutionApiTest, extendido con el
 * conjunto completo de 5 preguntas de patron_1 (empty/all_est/exceptions/
 * inconsistency/formula_confirmation) que este flujo exige responder todas
 * a la vez.
 */
class HumanReviewResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Superadmin']);
        $this->admin = User::factory()->create(['name' => 'Funcionario Auditor']);
        $this->admin->assignRole('Superadmin');
    }

    private function fieldsB(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'Profesional', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Ambos sexos', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Hombres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'E', 'label' => 'Mujeres', 'esTotal' => false, 'esControlOculto' => false],
        ];
    }

    private function createActiveStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 2,
            'hash_estructura' => 'hash_human_review_resolution_active',
            'estructura' => ['forms' => [[
                'sheetName' => 'A01',
                'sections' => [[
                    'codigo' => 'B', 'titulo' => 'CONTROLES DE SALUD SEGUN CICLO VITAL',
                    'filaInicioDatos' => 35, 'filaFinDatos' => 39, 'filaHeader' => 34,
                    'fields' => $this->fieldsB(),
                ]],
            ]]],
            'status' => 'active',
        ]);
    }

    private function putCellData(): void
    {
        $cells = [
            'C35' => ['valor_bruto' => 'Ambos Sexos', 'esta_bloqueada' => true],
            'D35' => ['valor_bruto' => 'Hombres', 'esta_bloqueada' => true],
            'E35' => ['valor_bruto' => 'Mujeres', 'esta_bloqueada' => true],
        ];

        foreach ([
            36 => ['De salud ', 'Médico/a'],
            37 => ['', 'Enfermera/o'],
            38 => ['', 'Matrona/ón'],
            39 => ['', 'Técnico en Enfermería'],
        ] as $row => [$concept, $professional]) {
            $cells["A{$row}"] = ['valor_bruto' => $concept, 'esta_bloqueada' => true];
            $cells["B{$row}"] = ['valor_bruto' => $professional, 'esta_bloqueada' => true];
            $cells["C{$row}"] = [
                'valor_bruto' => null, 'formula' => "=SUM(D{$row}:E{$row})", 'es_formula' => true,
                'dependencias' => ["D{$row}", "E{$row}"], 'esta_bloqueada' => true,
                'color_fondo' => ['rgb' => 'FFFFFFFF', 'nombre_inferido' => 'blanco'],
            ];
            $cells["D{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
            $cells["E{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
        }

        Storage::disk('local')->put('certificacion/cell-data/A01-B.json', json_encode($cells));
    }

    /**
     * Semilla el conjunto COMPLETO de 5 preguntas de patron_1 (mas
     * section_review y un patron_2 "sentinela" que nunca debe tocarse), con
     * un pattern_fingerprint/structure_version deliberadamente incorrectos
     * -- exactamente el escenario real de un MISMATCH+human_review.
     */
    private function seedV2MismatchFullPattern(array $rows = [36, 37, 38, 39]): void
    {
        $base = [
            'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
            'source_type' => 'manual', 'fingerprint_version' => 2,
            'pattern_fingerprint' => 'fpv2_deliberadamenteIncorrecto',
            'pattern_rows' => $rows, 'structure_version' => '1', 'status' => 'answered',
            'review_status' => 'reviewed', 'observation' => null,
        ];

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'revisada', 'review_status' => 'section_reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                    ],
                    array_merge($base, [
                        'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Si no existen datos, debe registrarse 0 o puede quedar vacío (Patrón 1)',
                        'response' => 'puede_quedar_vacio',
                    ]),
                    array_merge($base, [
                        'id' => 'patron_1_all_est', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Aplicabilidad a establecimientos que reportan la hoja (Patrón 1)',
                        'response' => 'si',
                    ]),
                    array_merge($base, [
                        'id' => 'patron_1_exceptions', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Excepciones por establecimiento o tipo de establecimiento (Patrón 1)',
                        'response' => 'no',
                    ]),
                    array_merge($base, [
                        'id' => 'patron_1_inconsistency', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Clasificación funcional de la inconsistencia (Patrón 1)',
                        'response' => 'error',
                    ]),
                    array_merge($base, [
                        'id' => 'patron_1_formula_confirmation', 'type' => 'pattern_confirmation', 'pattern_id' => 1,
                        'question' => 'Confirmación de lectura técnica desde el XLSM',
                        'response' => 'confirmed',
                    ]),
                    // Patron 2 "sentinela": debe quedar byte-identico tras
                    // resolver el patron 1.
                    array_merge($base, [
                        'id' => 'patron_2_empty', 'type' => 'pattern_question', 'pattern_id' => 2,
                        'question' => 'Pregunta de prueba (Patrón 2)',
                        'response' => 'puede_quedar_vacio',
                        'pattern_fingerprint' => 'fpv2_otroPatronIntacto',
                        'pattern_rows' => [99],
                    ]),
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function fullReviewEndpoint(int $patternId = 1): string
    {
        return "/api/v1/rule-engine/catalog/A/A01/sections/B/patterns/{$patternId}/mismatch-resolution/full-review";
    }

    private function confirmEndpoint(int $patternId = 1): string
    {
        return "/api/v1/rule-engine/catalog/A/A01/sections/B/patterns/{$patternId}/mismatch-resolution/confirm";
    }

    private function detailsEndpoint(int $patternId = 1): string
    {
        return "/api/v1/rule-engine/catalog/A/A01/sections/B/patterns/{$patternId}/mismatch-resolution";
    }

    private function liveFingerprint(): string
    {
        $response = $this->getJson($this->detailsEndpoint());

        return $response->json('data.live_canonical_fingerprint');
    }

    /** @return array{questions: array<int, array{id:string, response:string, observation?:string}>} */
    private function fullReviewPayload(): array
    {
        return ['questions' => [
            ['id' => 'patron_1_empty', 'response' => 'debe_registrar_cero', 'observation' => 'Baseline autorizado por el usuario.'],
            ['id' => 'patron_1_all_est', 'response' => 'si'],
            ['id' => 'patron_1_exceptions', 'response' => 'no'],
            ['id' => 'patron_1_inconsistency', 'response' => 'error'],
            ['id' => 'patron_1_formula_confirmation', 'response' => 'confirmed'],
        ]];
    }

    // ── Caso feliz: human_review se resuelve, MISMATCH -> AUTO_MIGRATE ──

    public function test_human_review_pattern_is_resolved_via_full_review(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura funcional completa.', 'Auditor Uno'
        );

        $response = $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload());

        $response->assertOk();
        $response->assertJsonPath('message', 'MISMATCH resuelto (revisión funcional completa).');
    }

    public function test_pattern_reclassifies_from_mismatch_to_auto_migrate_only_after_resolution(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $active = $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura funcional completa.', 'Auditor Uno'
        );

        $sectionDecl = $active->estructura['forms'][0]['sections'][0];

        // Instancia NUEVA de PatternMigrationScanner para cada lectura --
        // igual que en produccion, donde cada request HTTP resuelve sus
        // propias dependencias desde cero (ninguna de las clases de esta
        // cadena esta ligada como singleton). Reutilizar una misma
        // instancia entre el "antes" y el "despues" dentro de un solo
        // metodo de test no refleja ese comportamiento real (mismo tipo de
        // hallazgo de metodologia ya documentado en el proyecto para el
        // guard de sesion) y puede arrastrar cache de instancia intermedio
        // (CellDataStorageService) que un ciclo de request real nunca
        // comparte entre el GET previo y el POST de resolucion.
        $before = app(PatternMigrationScanner::class)->scanSection($active, 'A01', 'B', $sectionDecl);
        $beforePattern = collect($before['patterns'])->firstWhere('pattern_id', 1);
        $this->assertSame('MISMATCH', $beforePattern['category']);
        $this->assertFalse($beforePattern['already_v2_matching']);

        $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload())->assertOk();

        $active->refresh();
        $after = app(PatternMigrationScanner::class)->scanSection($active, 'A01', 'B', $sectionDecl);
        $afterPattern = collect($after['patterns'])->firstWhere('pattern_id', 1);
        $this->assertSame('AUTO_MIGRATE', $afterPattern['category']);
        $this->assertTrue($afterPattern['already_v2_matching']);
        $this->assertSame('debe_registrar_cero', $afterPattern['historical_answer']['response']);
    }

    // ── Categorias que NO deben resolverse por esta via ──────────────

    public function test_safe_reconfirm_is_rejected_by_full_review_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Evidencia.', 'Auditor Uno'
        );

        $response = $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'not_human_review');
        $response->assertJsonPath('data.resolution_category', 'safe_reconfirm');
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertSame('puede_quedar_vacio', $stored['_questions']['A01_B'][1]['response']);
    }

    public function test_structural_review_is_rejected_by_full_review_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_REVIEW,
            $fp, [36, 37, 38, 39], 'Cambio estructural real.', 'Auditor Uno'
        );

        $response = $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'not_human_review');
        $response->assertJsonPath('data.resolution_category', 'structural_review');
    }

    public function test_untagged_mismatch_is_rejected_by_full_review_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();

        $response = $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'not_audited');
    }

    public function test_human_review_pattern_still_cannot_be_confirmed_via_quick_path(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura funcional completa.', 'Auditor Uno'
        );

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'requires_full_review');
    }

    // ── Contenido de la escritura ─────────────────────────────────────

    public function test_new_functional_response_is_recorded(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );

        $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['A01_B'][1];

        $this->assertSame('debe_registrar_cero', $q['response']);
        $this->assertSame('Baseline autorizado por el usuario.', $q['observation']);
        $this->assertSame('reviewed', $q['review_status']);
        $this->assertSame('answered', $q['status']);
        $this->assertSame('Funcionario Auditor', $q['reviewed_by']);
        $this->assertArrayHasKey('reviewed_at', $q);
    }

    public function test_fingerprint_rows_and_structure_version_are_updated_to_live_values(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $active = $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );

        $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        foreach (['patron_1_empty', 'patron_1_all_est', 'patron_1_exceptions', 'patron_1_inconsistency', 'patron_1_formula_confirmation'] as $id) {
            $q = collect($stored['_questions']['A01_B'])->firstWhere('id', $id);
            $this->assertSame(2, $q['fingerprint_version'], "{$id}: fingerprint_version");
            $this->assertSame($fp, $q['pattern_fingerprint'], "{$id}: pattern_fingerprint debe ser el canonical vivo");
            $this->assertNotSame('fpv2_deliberadamenteIncorrecto', $q['pattern_fingerprint']);
            $this->assertSame([36, 37, 38, 39], $q['pattern_rows'], "{$id}: pattern_rows");
            $this->assertSame((string) $active->version_number, $q['structure_version'], "{$id}: structure_version");
            $this->assertNotSame('1', $q['structure_version']);
        }
    }

    public function test_previous_decision_is_preserved_in_history(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );

        $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $history = $stored['_questions_history']['A01_B'] ?? [];

        // 1) La respuesta anterior ('puede_quedar_vacio') queda registrada
        // como transicion, no solo perdida.
        $responseChange = collect($history)->first(fn ($h) => ($h['previous'] ?? null) === 'puede_quedar_vacio' && ($h['new'] ?? null) === 'debe_registrar_cero');
        $this->assertNotNull($responseChange, 'La transicion de respuesta anterior->nueva debe quedar en el historial.');

        // 2) La entrada agregada 'human_review_resolution' conserva
        // fingerprint/structure_version antes Y despues.
        $resolutionEntry = collect($history)->firstWhere('type', 'human_review_resolution');
        $this->assertNotNull($resolutionEntry);
        $this->assertSame(1, $resolutionEntry['pattern_id']);
        $this->assertSame('fpv2_deliberadamenteIncorrecto', $resolutionEntry['fingerprint_before']);
        $this->assertSame($fp, $resolutionEntry['fingerprint_after']);
        $this->assertSame('1', $resolutionEntry['structure_version_before']);
        $this->assertSame('2', $resolutionEntry['structure_version_after']);
        $this->assertSame('Funcionario Auditor', $resolutionEntry['by']);
        $this->assertArrayHasKey('at', $resolutionEntry);
    }

    public function test_mismatch_resolution_audit_json_is_never_touched(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );
        $auditBefore = Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json');

        $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload())->assertOk();

        $auditAfter = Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json');
        $this->assertSame($auditBefore, $auditAfter);
    }

    // ── Rechazos sin escritura ──────────────────────────────────────

    public function test_nonexistent_pattern_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();

        $response = $this->postJson($this->fullReviewEndpoint(99), $this->fullReviewPayload());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'no_longer_mismatch');
    }

    public function test_incomplete_question_set_is_rejected_without_writing(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $incomplete = $this->fullReviewPayload();
        array_pop($incomplete['questions']); // falta 'patron_1_formula_confirmation'

        $response = $this->postJson($this->fullReviewEndpoint(), $incomplete);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0', 'incomplete_question_set');
        $this->assertSame($before, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
    }

    public function test_extra_question_not_belonging_to_pattern_is_rejected_without_writing(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $withExtra = $this->fullReviewPayload();
        $withExtra['questions'][] = ['id' => 'patron_2_empty', 'response' => 'debe_registrar_cero'];

        $response = $this->postJson($this->fullReviewEndpoint(), $withExtra);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0', 'incomplete_question_set');
        $this->assertSame($before, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
    }

    public function test_full_review_requires_authentication(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();

        $response = $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload());

        $response->assertStatus(401);
    }

    // ── Aislamiento: otro pattern_id de la misma seccion, atomicidad ──

    public function test_other_pattern_id_in_same_section_remains_byte_identical(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $pattern2Before = collect($stored['_questions']['A01_B'])->firstWhere('id', 'patron_2_empty');

        $this->postJson($this->fullReviewEndpoint(), $this->fullReviewPayload())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $pattern2After = collect($stored['_questions']['A01_B'])->firstWhere('id', 'patron_2_empty');

        $this->assertSame($pattern2Before, $pattern2After);
    }

    public function test_resolve_human_review_pattern_rejects_incomplete_answer_set_without_partial_write(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2MismatchFullPattern();
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $service = app(FunctionalRuleService::class);

        // Llamada directa al servicio (bypass del controlador), con un
        // conjunto de respuestas INCOMPLETO -- el propio servicio debe
        // abortar (defensivo, no solo confiar en la validacion previa del
        // controlador) sin persistir ningun cambio parcial.
        $this->expectException(\RuntimeException::class);

        try {
            $service->resolveHumanReviewPattern(
                'A01', 'B', 1,
                [
                    ['id' => 'patron_1_empty', 'response' => 'debe_registrar_cero'],
                    // Faltan deliberadamente las otras 4 preguntas del patron.
                ],
                canonicalFingerprint: 'fpv2_nuevoCorrecto',
                patternRows: [36, 37, 38, 39],
                structureVersion: '2',
                reviewedBy: 'Funcionario Auditor',
            );
        } finally {
            $this->assertSame($before, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
        }
    }
}
