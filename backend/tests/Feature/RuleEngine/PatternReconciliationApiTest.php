<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cubre que los campos de reconciliacion (pattern_rows, row_fingerprint,
 * reconciliation_status, backfill_status, derived_from_fingerprint,
 * historical_reference, reconciliation.effective_section_reviewed) viajan
 * correctamente por la API GET .../patterns y POST .../pattern-questions,
 * y que la reconciliacion en vivo (SectionCalibrationMatrixService::buildPatternMatrix())
 * nunca aplica automaticamente una respuesta cuyo pattern_fingerprint
 * guardado no coincide con el row_fingerprint vigente del patron.
 *
 * Usa el mismo fixture (Seccion B, dinamica por cell_data) ya probado en
 * CalibrationApiTest -- 1 solo patron, filas 36-39, source=cell_data.
 */
class PatternReconciliationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Superadmin');
    }

    private function createStructure(): RemTemplateStructure
    {
        $fieldsB = [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'B', 'label' => 'Profesional', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'C', 'label' => 'Ambos sexos', 'esTotal' => true, 'esControlOculto' => false],
            ['letra' => 'D', 'label' => 'Hombres', 'esTotal' => false, 'esControlOculto' => false],
            ['letra' => 'E', 'label' => 'Mujeres', 'esTotal' => false, 'esControlOculto' => false],
        ];

        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_reconciliation_api_test',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'A01',
                        'sections' => [
                            [
                                'codigo' => 'B',
                                'titulo' => 'CONTROLES DE SALUD SEGUN CICLO VITAL',
                                'filaInicioDatos' => 35,
                                'filaFinDatos' => 39,
                                'filaHeader' => 34,
                                'fields' => $fieldsB,
                            ],
                        ],
                    ],
                ],
            ],
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
                'valor_bruto' => null,
                'formula' => "=SUM(D{$row}:E{$row})",
                'es_formula' => true,
                'dependencias' => ["D{$row}", "E{$row}"],
                'esta_bloqueada' => true,
                'color_fondo' => ['rgb' => 'FFFFFFFF', 'nombre_inferido' => 'blanco'],
            ];
            $cells["D{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
            $cells["E{$row}"] = ['valor_bruto' => null, 'es_formula' => false, 'esta_bloqueada' => false, 'color_fondo' => ['rgb' => 'FFFFFFCC', 'nombre_inferido' => 'crema']];
        }

        Storage::disk('local')->put('certificacion/cell-data/A01-B.json', json_encode($cells));
    }

    public function test_patterns_endpoint_returns_reconciliation_fields_for_unanswered_pattern(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createStructure();
        $this->putCellData();

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.patterns.0.pattern_rows', [36, 37, 38, 39]);
        $response->assertJsonPath('data.patterns.0.reconciliation_status', 'pending');
        $response->assertJsonPath('data.patterns.0.backfill_status', null);
        $response->assertJsonPath('data.patterns.0.derived_from_fingerprint', null);
        $response->assertJsonPath('data.reconciliation.effective_section_reviewed', false);
        $response->assertJsonPath('data.reconciliation.historical_section_reviewed', false);
        $this->assertStringStartsWith('rowset_', $response->json('data.patterns.0.row_fingerprint'));
    }

    public function test_saving_with_matching_pattern_fingerprint_is_reconciled_as_reviewed(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createStructure();
        $this->putCellData();

        // Se obtiene el fingerprint "vigente" resolviendo el servicio
        // directamente (una sola vez) en lugar de un GET HTTP previo: dentro
        // de un mismo proceso de test, Laravel cachea la instancia del
        // controlador por objeto Route, por lo que un segundo GET a la misma
        // ruta reutilizaria el mismo FunctionalRuleService con su cache
        // interna ya poblada -- un artefacto exclusivo de tests con multiples
        // llamadas a la misma ruta en un solo metodo, que nunca ocurre en
        // produccion real (cada request HTTP es un proceso PHP-FPM nuevo).
        $fingerprint = app(\App\Domain\RuleEngine\Services\SectionCalibrationMatrixService::class)
            ->buildPatternMatrix('A01', 'B')['patterns'][0]['row_fingerprint'];

        $save = $this->postJson('/api/v1/rule-engine/catalog/A01/sections/B/pattern-questions', [
            'questions' => [[
                'id' => 'patron_1_empty',
                'type' => 'pattern_question',
                'question' => 'Si no existen datos, ¿debe registrarse 0 o puede quedar vacío?',
                'response' => 'debe_registrar_cero',
                'pattern_id' => 1,
                'pattern_key' => 'pattern_1',
                'pattern_fingerprint' => $fingerprint,
                'pattern_rows' => [36, 37, 38, 39],
                'review_status' => 'reviewed',
                'reviewed_by' => 'Estadística APS',
            ]],
        ]);
        $save->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertSame($fingerprint, $stored['_questions']['A01_B'][0]['pattern_fingerprint']);
        $this->assertSame([36, 37, 38, 39], $stored['_questions']['A01_B'][0]['pattern_rows']);

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');
        $response->assertJsonPath('data.patterns.0.reconciliation_status', 'reviewed');
        $response->assertJsonPath('data.patterns.0.backfill_status', 'fingerprint_native');
    }

    public function test_stale_pattern_fingerprint_forces_revalidation_and_is_never_auto_applied(): void
    {
        // Simula el caso exacto del bug: una respuesta ya guardada trae un
        // pattern_fingerprint que corresponde a un conjunto de filas
        // distinto al que hoy tiene el patron con ese mismo pattern_id (la
        // estructura cambio bajo el mismo id). Nunca debe leerse como
        // reviewed ni aplicarse automaticamente.
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createStructure();
        $this->putCellData();

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [[
                    'id' => 'patron_1_empty',
                    'type' => 'pattern_question',
                    'pattern_id' => 1,
                    'response' => 'puede_quedar_vacio',
                    'review_status' => 'reviewed',
                    'reviewed_by' => 'Usuario anterior',
                    'pattern_fingerprint' => 'rowset_0000000000000000',
                ]],
            ],
        ]));

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');

        $response->assertOk();
        $response->assertJsonPath('data.patterns.0.reconciliation_status', 'requiere_revalidacion');
        $response->assertJsonPath('data.patterns.0.derived_from_fingerprint.0', 'rowset_0000000000000000');
        $response->assertJsonPath('data.patterns.0.historical_reference.response', 'puede_quedar_vacio');
        $response->assertJsonPath('data.patterns.0.historical_reference.reviewed_by', 'Usuario anterior');
        $response->assertJsonPath('data.reconciliation.effective_section_reviewed', false);
    }

    public function test_legacy_answer_without_fingerprint_keeps_working_as_reviewed(): void
    {
        // Regresion: la inmensa mayoria de respuestas guardadas hoy no
        // tienen pattern_fingerprint (se crearon antes de este cambio). No
        // deben degradarse a requiere_revalidacion solo por carecer del campo.
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createStructure();
        $this->putCellData();

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [[
                    'id' => 'patron_1_empty',
                    'type' => 'pattern_question',
                    'pattern_id' => 1,
                    'response' => 'debe_registrar_cero',
                    'review_status' => 'reviewed',
                ]],
            ],
        ]));

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');

        $response->assertJsonPath('data.patterns.0.reconciliation_status', 'reviewed');
        $response->assertJsonPath('data.patterns.0.backfill_status', 'legacy_unmigrated');
        $response->assertJsonPath('data.reconciliation.effective_section_reviewed', true);
    }

    public function test_section_review_marker_is_preserved_as_historical_even_when_effective_state_is_invalidated(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createStructure();
        $this->putCellData();

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => [
                'A01_B' => [
                    [
                        'id' => 'patron_1_empty',
                        'type' => 'pattern_question',
                        'pattern_id' => 1,
                        'response' => 'puede_quedar_vacio',
                        'review_status' => 'reviewed',
                        'pattern_fingerprint' => 'rowset_0000000000000000',
                    ],
                    [
                        'id' => 'section_review',
                        'type' => 'section_review',
                        'response' => 'revisada',
                        'review_status' => 'section_reviewed',
                    ],
                ],
            ],
        ]));

        $response = $this->getJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns');

        // La marca historica se preserva tal cual...
        $response->assertJsonPath('data.reconciliation.historical_section_reviewed', true);
        // ...pero deja de contar como certificacion vigente porque el unico
        // patron de la seccion quedo requiere_revalidacion.
        $response->assertJsonPath('data.reconciliation.effective_section_reviewed', false);
        $response->assertJsonPath('data.patterns.0.reconciliation_status', 'requiere_revalidacion');
    }
}
