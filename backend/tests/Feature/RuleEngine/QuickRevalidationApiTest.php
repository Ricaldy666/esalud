<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre el endpoint POST .../patterns/{patternId}/quick-revalidation (Fase
 * 1, flujo QUICK_CONFIRMATION, 2026-08-12): confirmacion rapida de una
 * revision funcional anterior cuando la estructura cambio pero las filas
 * siguen coincidiendo. Nunca acepta response/reviewed_by/reviewed_at desde
 * el body -- todo lo protegido se preserva byte a byte, y el usuario que
 * revalida se obtiene exclusivamente de la sesion autenticada.
 */
class QuickRevalidationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Superadmin']);
        $this->admin = User::factory()->create(['name' => 'Funcionario Revalidador']);
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

    private function createActiveStructure(int $filaInicioDatos = 35): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 2,
            'hash_estructura' => 'hash_quick_revalidation_active',
            'estructura' => ['forms' => [[
                'sheetName' => 'A01',
                'sections' => [[
                    'codigo' => 'B', 'titulo' => 'CONTROLES DE SALUD SEGUN CICLO VITAL',
                    'filaInicioDatos' => $filaInicioDatos, 'filaFinDatos' => 39, 'filaHeader' => 34,
                    'fields' => $this->fieldsB(),
                ]],
            ]]],
            'status' => 'active',
        ]);
    }

    /** Estructura historica -- filaInicioDatos DISTINTO (36) para forzar QUICK_CONFIRMATION. */
    private function createHistoricalStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 1,
            'hash_estructura' => 'hash_quick_revalidation_historica',
            'estructura' => ['forms' => [[
                'sheetName' => 'A01',
                'sections' => [[
                    'codigo' => 'B', 'titulo' => 'CONTROLES DE SALUD SEGUN CICLO VITAL',
                    'filaInicioDatos' => 36, 'filaFinDatos' => 39, 'filaHeader' => 34,
                    'fields' => $this->fieldsB(),
                ]],
            ]]],
            'status' => 'superseded',
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

    private function seedLegacyAnswer(int $histStructureId, ?array $rowsOverride = null): void
    {
        $rowsText = implode(', ', $rowsOverride ?? [36, 37, 38, 39]);
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
                        'question' => "Pregunta de prueba (Patrón 1: {$rowsText})",
                        'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual', 'structure_version' => (string) $histStructureId,
                    ],
                    [
                        'id' => 'patron_1_confirm', 'type' => 'pattern_confirmation', 'pattern_id' => 1,
                        'question' => "Confirmación (Patrón 1: {$rowsText})",
                        'response' => 'confirmed', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:05:00.000Z',
                        'source_type' => 'manual', 'structure_version' => (string) $histStructureId,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function endpoint(int $patternId = 1): string
    {
        return "/api/v1/rule-engine/catalog/A01/sections/B/patterns/{$patternId}/quick-revalidation";
    }

    public function test_quick_confirmation_valid_case_saves_v2_and_returns_ok(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $response = $this->postJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonPath('message', 'Revalidación confirmada.');
    }

    public function test_protected_fields_remain_byte_identical(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $this->postJson($this->endpoint())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $expectedReviewedAt = [
            'pattern_question' => '2026-07-01T10:00:00.000Z',
            'pattern_confirmation' => '2026-07-01T10:05:00.000Z',
        ];
        foreach ($stored['_questions']['A01_B'] as $q) {
            if (! in_array($q['type'], ['pattern_question', 'pattern_confirmation'], true)) {
                continue;
            }
            $this->assertSame('Francisco Arcos', $q['reviewed_by']);
            $this->assertSame($expectedReviewedAt[$q['type']], $q['reviewed_at']);
            $this->assertSame('manual', $q['source_type']);
            $this->assertContains($q['response'], ['debe_registrar_cero', 'confirmed']);
        }
        $this->assertSame('debe_registrar_cero', $stored['_questions']['A01_B'][1]['response']);
        $this->assertSame('confirmed', $stored['_questions']['A01_B'][2]['response']);
    }

    public function test_revalidated_by_comes_from_authenticated_user_not_request_body(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        // El body intenta falsificar la autoria -- debe ser ignorado por completo.
        $this->postJson($this->endpoint(), [
            'revalidated_by' => 'Usuario Falso',
            'revalidated_at' => '2000-01-01T00:00:00Z',
            'response' => 'puede_quedar_vacio',
            'reviewed_by' => 'Otro Falso',
        ])->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['A01_B'][1];

        $this->assertSame('Funcionario Revalidador', $q['revalidated_by']);
        $this->assertNotSame('Usuario Falso', $q['revalidated_by']);
        $this->assertNotSame('2000-01-01T00:00:00Z', $q['revalidated_at']);
        $this->assertSame('Francisco Arcos', $q['reviewed_by']);
        $this->assertSame('debe_registrar_cero', $q['response']);
    }

    public function test_writes_expected_v2_fields_and_revalidation_metadata(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $this->postJson($this->endpoint())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['A01_B'][1];

        $this->assertSame(2, $q['fingerprint_version']);
        $this->assertStringStartsWith('fpv2_', $q['pattern_fingerprint']);
        $this->assertSame([36, 37, 38, 39], $q['pattern_rows']);
        $this->assertSame('manual_revalidation', $q['revalidation_source_type']);
        $this->assertArrayHasKey('revalidated_at', $q);
    }

    public function test_history_entry_is_recorded(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $this->postJson($this->endpoint())->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $history = $stored['_questions_history']['A01_B'] ?? [];
        $entry = collect($history)->firstWhere('type', 'pattern_revalidation');

        $this->assertNotNull($entry);
        $this->assertSame(1, $entry['pattern_id']);
        $this->assertSame('Funcionario Revalidador', $entry['by']);
    }

    public function test_mismatch_section_cannot_use_quick_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        // Filas historicas NO coinciden con las vigentes -> MISMATCH.
        $this->seedLegacyAnswer($historical->id, rowsOverride: [1, 2, 3]);

        $response = $this->postJson($this->endpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'no_longer_quick_confirmation');
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('fingerprint_version', $stored['_questions']['A01_B'][1]);
    }

    public function test_new_section_cannot_use_quick_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode(['_questions' => []]));

        $response = $this->postJson($this->endpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('data.category', 'NEW_SECTION');
    }

    public function test_auto_migrate_section_cannot_use_quick_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $active = $this->createActiveStructure();
        $this->putCellData();
        // structure_version = la MISMA activa -> structure_identical=true -> AUTO_MIGRATE, no QUICK_CONFIRMATION.
        $this->seedLegacyAnswer($active->id, rowsOverride: [36, 37, 38, 39]);

        $response = $this->postJson($this->endpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('data.category', 'AUTO_MIGRATE');
    }

    public function test_not_calibratable_section_cannot_use_quick_endpoint(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [[
                    'id' => 'section_review', 'type' => 'section_review',
                    'response' => 'no_calibrable', 'review_status' => 'section_reviewed',
                    'closure_reason' => 'no_calibratable_data',
                ]],
            ],
        ]));

        $response = $this->postJson($this->endpoint());

        $response->assertStatus(409);
        $response->assertJsonPath('data.category', 'NOT_CALIBRATABLE');
    }

    public function test_state_change_between_load_and_confirmation_aborts_with_zero_writes(): void
    {
        // Simula el caso TOCTOU: el patron ya no es QUICK_CONFIRMATION al
        // momento de confirmar (aqui: ya fue migrado a v2 por otro proceso
        // -- mismo efecto que cualquier cambio de estado intermedio).
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        // Migra el patron a v2 "externamente" antes del intento de quick-revalidation.
        app(\App\Domain\RuleEngine\Services\FunctionalRuleService::class)->applyQuickRevalidation(
            'A01', 'B', 1, 'fpv2_yaMigrado', [36, 37, 38, 39], 'Otro Proceso'
        );
        $afterExternalMigration = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $response = $this->postJson($this->endpoint());

        $response->assertStatus(409);
        $this->assertSame(
            $afterExternalMigration,
            Storage::disk('local')->get('certificacion/reglas-funcionales.json'),
            'el intento rechazado no debe escribir nada mas'
        );
        $this->assertNotSame($before, $afterExternalMigration);
    }

    public function test_second_confirmation_of_already_migrated_pattern_is_rejected_not_corrupted(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $this->postJson($this->endpoint())->assertOk();
        $afterFirst = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        // El patron ya quedo v2-matching -> ahora clasifica AUTO_MIGRATE, no QUICK_CONFIRMATION.
        $second = $this->postJson($this->endpoint());

        $second->assertStatus(409);
        $this->assertSame($afterFirst, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertSame('Francisco Arcos', $stored['_questions']['A01_B'][1]['reviewed_by']);
    }

    /**
     * Diagnostico minimo agregado tras la investigacion de A01/D
     * (2026-08-12, "POST 200 sin persistencia") -- verifica que persistAll()
     * deja evidencia completa de la escritura sin cambiar el resultado
     * funcional del endpoint (sigue devolviendo 200 y persistiendo igual
     * que antes).
     */
    public function test_persist_diagnostic_log_captures_put_result_and_context(): void
    {
        Storage::fake('local');
        Log::spy();
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $this->postJson($this->endpoint())->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                if ($message !== '[FunctionalRuleService][persistAll-diagnostic] Escritura de reglas-funcionales.json') {
                    return false;
                }

                return $context['put_result'] === true
                    && str_ends_with($context['absolute_path'], 'certificacion/reglas-funcionales.json')
                    && $context['content_size_bytes'] > 0
                    && isset($context['timestamp'])
                    && $context['context'] === [
                        'source' => 'applyQuickRevalidation',
                        'sheet' => 'A01',
                        'section' => 'B',
                        'pattern_id' => 1,
                    ]
                    && $context['before']['exists'] === true
                    && array_key_exists('mtime', $context['before'])
                    && array_key_exists('size', $context['before'])
                    && $context['after']['exists'] === true
                    && array_key_exists('mtime', $context['after'])
                    && array_key_exists('size', $context['after']);
            });
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $response = $this->postJson($this->endpoint());

        $response->assertStatus(401);
    }
}
