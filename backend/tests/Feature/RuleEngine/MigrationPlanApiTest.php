<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre el endpoint GET .../sections/{section}/migration-plan (Fase 3,
 * 2026-08-12): unica fuente de datos que el frontend usa para decidir si
 * debe mostrar QuickRevalidationPanel en vez del flujo normal de
 * calibracion. 100% lectura -- reutiliza PatternMigrationScanner::scanSection(),
 * nunca reconcileLive()/applyPatternReconciliation(), no activa el
 * mecanismo v2 en produccion, no escribe nada.
 */
class MigrationPlanApiTest extends TestCase
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
            'hash_estructura' => 'hash_migration_plan_active',
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

    private function createHistoricalStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 1,
            'hash_estructura' => 'hash_migration_plan_historica',
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

    private function endpoint(string $sheet = 'A01', string $section = 'B'): string
    {
        return "/api/v1/rule-engine/catalog/{$sheet}/sections/{$section}/migration-plan";
    }

    public function test_quick_confirmation_section_returns_full_plan(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonPath('data.sheet', 'A01');
        $response->assertJsonPath('data.code', 'B');
        $response->assertJsonPath('data.category', 'QUICK_CONFIRMATION');
        $response->assertJsonPath('data.patterns.0.pattern_id', 1);
        $response->assertJsonPath('data.patterns.0.historical_answer.response', 'debe_registrar_cero');
        $response->assertJsonPath('data.patterns.0.historical_answer.reviewed_by', 'Francisco Arcos');
        $response->assertJsonPath('data.patterns.0.historical_rows', [36, 37, 38, 39]);
        $response->assertJsonStructure(['data' => ['column_diff' => ['added', 'removed', 'unknown']]]);
    }

    public function test_new_section_returns_new_section_category(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $this->putCellData();
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode(['_questions' => []]));

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonPath('data.category', 'NEW_SECTION');
    }

    public function test_not_calibratable_section_returns_that_category(): void
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

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonPath('data.category', 'NOT_CALIBRATABLE');
    }

    public function test_auto_migrate_section_returns_that_category(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $active = $this->createActiveStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($active->id, rowsOverride: [36, 37, 38, 39]);

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $response->assertJsonPath('data.category', 'AUTO_MIGRATE');
    }

    public function test_no_active_structure_returns_422(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $response = $this->getJson($this->endpoint());

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0', 'no_active_structure');
    }

    public function test_unknown_section_returns_404(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();

        $response = $this->getJson($this->endpoint('A01', 'Z'));

        $response->assertStatus(404);
        $response->assertJsonPath('errors.0', 'section_not_found');
    }

    public function test_endpoint_is_fully_read_only(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);
        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $writeQueries = [];
        DB::listen(function ($query) use (&$writeQueries) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writeQueries[] = $query->sql;
            }
        });

        $this->getJson($this->endpoint())->assertOk();

        $this->assertSame([], $writeQueries, 'el endpoint de solo lectura no debe ejecutar ninguna escritura SQL');
        $this->assertSame($before, Storage::disk('local')->get('certificacion/reglas-funcionales.json'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        Storage::fake('local');
        $this->createActiveStructure();

        $response = $this->getJson($this->endpoint());

        $response->assertStatus(401);
    }
}
