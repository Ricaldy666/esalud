<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\MismatchResolutionAuditService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre el flujo de resolucion de patrones MISMATCH (2026-08-21):
 * GET .../patterns/{id}/mismatch-resolution y
 * POST .../patterns/{id}/mismatch-resolution/confirm.
 *
 * A diferencia de quick-revalidation (gatillado solo por categoria en vivo),
 * este flujo exige ADEMAS una etiqueta de auditoria previa
 * (MismatchResolutionAuditService::setTag) clasificando el patron como
 * safe_reconfirm / human_review / structural_review -- sin tag, se rechaza
 * por defecto. Solo safe_reconfirm puede escribir, y solo reutilizando
 * exactamente applyQuickRevalidation() (mismos 6 campos protegidos).
 *
 * Todas las fixtures son sinteticas -- ninguna de las 43 secciones MISMATCH
 * reales se toca en estos tests.
 */
class MismatchResolutionApiTest extends TestCase
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
            'hash_estructura' => 'hash_mismatch_resolution_active',
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
     * Semilla una respuesta YA en fingerprint v2, con un pattern_fingerprint
     * deliberadamente incorrecto -- esto es exactamente lo que hoy produce
     * MISMATCH en las 43 secciones reales (fingerprint v2 que ya no coincide
     * con el recalculo en vivo), sin que las filas hayan cambiado.
     */
    private function seedV2Mismatch(array $rows = [36, 37, 38, 39]): void
    {
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'revisada', 'review_status' => 'section_reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                    ],
                    [
                        'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Pregunta de prueba (Patrón 1)',
                        'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual',
                        'fingerprint_version' => 2,
                        'pattern_fingerprint' => 'fpv2_deliberadamenteIncorrecto',
                        'pattern_rows' => $rows,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function detailsEndpoint(int $patternId = 1): string
    {
        return "/api/v1/rule-engine/catalog/A01/sections/B/patterns/{$patternId}/mismatch-resolution";
    }

    private function confirmEndpoint(int $patternId = 1): string
    {
        return "/api/v1/rule-engine/catalog/A01/sections/B/patterns/{$patternId}/mismatch-resolution/confirm";
    }

    private function liveFingerprint(): string
    {
        $response = $this->getJson($this->detailsEndpoint());

        return $response->json('data.live_canonical_fingerprint');
    }

    public function test_untagged_mismatch_cannot_be_confirmed(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'not_audited');
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('revalidated_by', $stored['_questions']['A01_B'][1]);
    }

    public function test_safe_reconfirm_can_be_confirmed_via_quick_path(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Fix de motor confirmado contra Excel real, filas identicas.', 'Auditor Uno'
        );

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertOk();
        $response->assertJsonPath('message', 'MISMATCH resuelto (safe_reconfirm).');
    }

    public function test_human_review_cannot_be_confirmed_via_quick_path(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Formula cambio de forma no trivial, requiere lectura humana.', 'Auditor Uno'
        );

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'requires_full_review');
        $response->assertJsonPath('data.resolution_category', 'human_review');
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('revalidated_by', $stored['_questions']['A01_B'][1]);
    }

    public function test_structural_review_cannot_be_confirmed_via_quick_path(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_REVIEW,
            $fp, [36, 37, 38, 39], 'Cambio estructural real, requiere flujo de calibracion completa.', 'Auditor Uno'
        );

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'requires_full_review');
        $response->assertJsonPath('data.resolution_category', 'structural_review');
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('revalidated_by', $stored['_questions']['A01_B'][1]);
    }

    public function test_stale_audit_tag_rejected_with_409_and_zero_writes(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $active = $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        // Se etiqueta como segura contra el fingerprint/filas actuales...
        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Evidencia inicial.', 'Auditor Uno'
        );

        // ...pero la estructura vuelve a cambiar DESPUES de auditado (aqui:
        // la seccion pierde la fila 39) -- las filas vivas ya no coinciden
        // con lo que se audito como seguro, antes de que el usuario alcance
        // a confirmar. Debe rechazarse por obsolescencia, sin escribir nada,
        // en vez de confirmar a ciegas contra una auditoria que ya no aplica.
        $estructura = $active->estructura;
        $estructura['forms'][0]['sections'][0]['filaFinDatos'] = 38;
        $active->estructura = $estructura;
        $active->save();
        $beforeConfirm = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertStatus(409);
        $this->assertSame($beforeConfirm, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
    }

    public function test_protected_fields_remain_unchanged_for_safe_reconfirm(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Evidencia.', 'Auditor Uno'
        );

        $this->postJson($this->confirmEndpoint())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['A01_B'][1];

        $this->assertSame('debe_registrar_cero', $q['response']);
        $this->assertSame('Francisco Arcos', $q['reviewed_by']);
        $this->assertSame('2026-07-01T10:00:00.000Z', $q['reviewed_at']);
        $this->assertSame('reviewed', $q['review_status']);
    }

    public function test_fingerprint_and_revalidation_metadata_are_updated(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Evidencia.', 'Auditor Uno'
        );

        $this->postJson($this->confirmEndpoint())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['A01_B'][1];

        $this->assertSame(2, $q['fingerprint_version']);
        $this->assertSame($fp, $q['pattern_fingerprint']);
        $this->assertNotSame('fpv2_deliberadamenteIncorrecto', $q['pattern_fingerprint']);
        $this->assertSame([36, 37, 38, 39], $q['pattern_rows']);
        $this->assertSame('Funcionario Auditor', $q['revalidated_by']);
        $this->assertSame('manual_revalidation', $q['revalidation_source_type']);
        $this->assertArrayHasKey('revalidated_at', $q);
    }

    public function test_history_entry_is_recorded_for_safe_reconfirm(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Evidencia.', 'Auditor Uno'
        );

        $this->postJson($this->confirmEndpoint())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $history = $stored['_questions_history']['A01_B'] ?? [];
        $entry = collect($history)->firstWhere('type', 'pattern_revalidation');

        $this->assertNotNull($entry);
        $this->assertSame(1, $entry['pattern_id']);
        $this->assertSame('Funcionario Auditor', $entry['by']);
    }

    public function test_other_patterns_in_section_are_not_affected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'section_review', 'type' => 'section_review',
                        'response' => 'revisada', 'review_status' => 'section_reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                    ],
                    [
                        'id' => 'patron_1_empty', 'type' => 'pattern_question', 'pattern_id' => 1,
                        'question' => 'Pregunta de prueba (Patrón 1)',
                        'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual', 'fingerprint_version' => 2,
                        'pattern_fingerprint' => 'fpv2_deliberadamenteIncorrecto',
                        'pattern_rows' => [36, 37, 38, 39],
                    ],
                    [
                        'id' => 'patron_2_empty', 'type' => 'pattern_question', 'pattern_id' => 2,
                        'question' => 'Pregunta de prueba (Patrón 2)',
                        'response' => 'puede_quedar_vacio', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual', 'fingerprint_version' => 2,
                        'pattern_fingerprint' => 'fpv2_otroPatronIntacto',
                        'pattern_rows' => [99],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $fp = $this->liveFingerprint();

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [36, 37, 38, 39], 'Evidencia.', 'Auditor Uno'
        );

        $this->postJson($this->confirmEndpoint(1))->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $untouched = $stored['_questions']['A01_B'][2];

        $this->assertSame('fpv2_otroPatronIntacto', $untouched['pattern_fingerprint']);
        $this->assertSame([99], $untouched['pattern_rows']);
        $this->assertArrayNotHasKey('revalidated_by', $untouched);
    }

    public function test_details_endpoint_exposes_tag_and_live_state_without_writing(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();
        $fp = $this->liveFingerprint();
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        app(MismatchResolutionAuditService::class)->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [36, 37, 38, 39], 'Requiere lectura.', 'Auditor Uno'
        );

        $response = $this->getJson($this->detailsEndpoint());

        $response->assertOk();
        $response->assertJsonPath('data.live_category', 'MISMATCH');
        $response->assertJsonPath('data.resolution_tag.category', 'human_review');
        $this->assertSame($before, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));

        // Forma completa del contrato consumido por MismatchResolutionPanel
        // (frontend) -- todos los campos que el componente lee deben venir
        // presentes, no solo los dos verificados arriba.
        $data = $response->json('data');
        $this->assertSame([36, 37, 38, 39], $data['live_rows']);
        $this->assertStringStartsWith('fpv2_', $data['live_canonical_fingerprint']);
        $this->assertArrayHasKey('column_diff', $data);
        $tag = $data['resolution_tag'];
        $this->assertSame('A01', $tag['sheet']);
        $this->assertSame('B', $tag['section']);
        $this->assertSame(1, $tag['pattern_id']);
        $this->assertSame($fp, $tag['audited_fingerprint']);
        $this->assertSame([36, 37, 38, 39], $tag['audited_rows']);
        $this->assertSame('Requiere lectura.', $tag['reason']);
        $this->assertSame('Auditor Uno', $tag['audited_by']);
        $this->assertArrayHasKey('audited_at', $tag);
    }

    /**
     * Hallazgo de la verificacion UI (2026-08-21): PatternMigrationScanner
     * solo exponia historical_answer para el path LEGACY -- para el path
     * canonico v2 (el que produce practicamente todos los MISMATCH reales
     * de la campaña) el campo quedaba ausente, y el panel mostraba "Sin
     * decision registrada" pese a existir una respuesta guardada. Corregido
     * en PatternMigrationScanner::scanSection() reutilizando
     * summarizeHistoricalAnswer() tambien en el path v2 -- 100% lectura, no
     * participa de la clasificacion.
     */
    public function test_historical_answer_is_exposed_for_canonical_v2_mismatch(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();

        $response = $this->getJson($this->detailsEndpoint());

        $response->assertOk();
        $response->assertJsonPath('data.historical_answer.response', 'debe_registrar_cero');
        $response->assertJsonPath('data.historical_answer.reviewed_by', 'Francisco Arcos');
    }

    public function test_confirm_endpoint_requires_authentication(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $this->putCellData();
        $this->seedV2Mismatch();

        $response = $this->postJson($this->confirmEndpoint());

        $response->assertStatus(401);
    }
}
