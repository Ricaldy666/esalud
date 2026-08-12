<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\PatternMigrationScanner;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regresion end-to-end del hallazgo A01/G.-G.2 (2026-08-12): confirma que
 * una quick-revalidation exitosa, seguida del flujo NORMAL de guardado
 * (pattern-questions.save, el mismo endpoint que usa
 * QuickCalibrationPanel para decisiones funcionales), no degrada la
 * seccion de vuelta a un estado inconsistente. Antes del fix, el segundo
 * guardado pisaba pattern_fingerprint con el formato v1 legacy pese a
 * fingerprint_version=2, y migration-plan clasificaba la seccion como
 * MISMATCH en vez de AUTO_MIGRATE.
 */
class QuickRevalidationThenNormalSaveRegressionTest extends TestCase
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
            'hash_estructura' => 'hash_regression_active',
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
            'hash_estructura' => 'hash_regression_historica',
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

    private function seedLegacyAnswer(int $histStructureId): void
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
                        'question' => 'Pregunta de prueba (Patrón 1: 36, 37, 38, 39)',
                        'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                        'source_type' => 'manual', 'structure_version' => (string) $histStructureId,
                    ],
                    [
                        'id' => 'patron_1_confirm', 'type' => 'pattern_confirmation', 'pattern_id' => 1,
                        'question' => 'Confirmación (Patrón 1: 36, 37, 38, 39)',
                        'response' => 'confirmed', 'review_status' => 'reviewed',
                        'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:05:00.000Z',
                        'source_type' => 'manual', 'structure_version' => (string) $histStructureId,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_normal_save_after_quick_revalidation_does_not_degrade_section(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure();
        $historical = $this->createHistoricalStructure();
        $this->putCellData();
        $this->seedLegacyAnswer($historical->id);

        // 1) Quick-revalidation real, exitosa.
        $this->postJson('/api/v1/rule-engine/catalog/A01/sections/B/patterns/1/quick-revalidation')
            ->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $afterQuick = $stored['_questions']['A01_B'][1];
        $this->assertSame(2, $afterQuick['fingerprint_version']);
        $this->assertStringStartsWith('fpv2_', $afterQuick['pattern_fingerprint']);

        // 2) Flujo NORMAL de guardado sobre la MISMA seccion -- exactamente
        // el payload que enviaba QuickCalibrationPanel antes del fix:
        // pattern_fingerprint en formato v1 (row_fingerprint), sin saber
        // que la pregunta ya es v2. Se reenvian las mismas 3 preguntas.
        $legacyRowFingerprint = 'rowset_' . substr(hash('sha256', '36,37,38,39'), 0, 16);
        $normalPayload = [
            'questions' => [
                [
                    'id' => 'section_review', 'row' => null, 'type' => 'section_review',
                    'question' => 'Sección B revisada funcionalmente', 'response' => 'revisada',
                    'review_status' => 'section_reviewed', 'reviewed_at' => now()->toIso8601String(),
                    'reviewed_by' => 'Funcionario Revalidador', 'source_type' => 'manual',
                ],
                [
                    'id' => 'patron_1_empty', 'row' => null, 'type' => 'pattern_question',
                    'pattern_id' => 1, 'pattern_key' => 'pattern_1',
                    'question' => 'Si no existen datos... (Patrón 1: 36, 37, 38, 39)',
                    'response' => 'debe_registrar_cero', 'review_status' => 'reviewed',
                    'reviewed_at' => now()->toIso8601String(), 'reviewed_by' => 'Funcionario Revalidador',
                    'source_type' => 'manual',
                    'pattern_fingerprint' => $legacyRowFingerprint,
                    'pattern_rows' => [36, 37, 38, 39],
                ],
                [
                    'id' => 'patron_1_confirm', 'row' => null, 'type' => 'pattern_confirmation',
                    'pattern_id' => 1, 'pattern_key' => 'pattern_1',
                    'question' => 'Confirmación de lectura técnica desde el XLSM',
                    'response' => 'confirmed', 'review_status' => 'reviewed',
                    'reviewed_at' => now()->toIso8601String(), 'reviewed_by' => 'Funcionario Revalidador',
                    'source_type' => 'manual',
                    'pattern_fingerprint' => $legacyRowFingerprint,
                    'pattern_rows' => [36, 37, 38, 39],
                ],
            ],
        ];

        $this->postJson('/api/v1/rule-engine/catalog/A01/sections/B/pattern-questions', $normalPayload)
            ->assertOk();

        // 3) La metadata v2 protegida NO debe haber cambiado.
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $afterNormalSave = $stored['_questions']['A01_B'][1];
        $this->assertSame(2, $afterNormalSave['fingerprint_version']);
        $this->assertSame($afterQuick['pattern_fingerprint'], $afterNormalSave['pattern_fingerprint']);
        $this->assertNotSame($legacyRowFingerprint, $afterNormalSave['pattern_fingerprint']);
        $this->assertSame($afterQuick['pattern_rows'], $afterNormalSave['pattern_rows']);
        $this->assertSame($afterQuick['revalidated_by'], $afterNormalSave['revalidated_by']);
        $this->assertSame($afterQuick['revalidated_at'], $afterNormalSave['revalidated_at']);
        $this->assertSame('manual_revalidation', $afterNormalSave['revalidation_source_type']);

        // 4) migration-plan sigue AUTO_MIGRATE, no MISMATCH.
        $scanner = app(PatternMigrationScanner::class);
        $structure = RemTemplateStructure::where('status', 'active')->first();
        $estructura = $structure->estructura;
        $sectionDecl = $estructura['forms'][0]['sections'][0];
        $plan = $scanner->scanSection($structure, 'A01', 'B', $sectionDecl);
        $this->assertSame(PatternReconciliationService::MIGRATION_AUTO_MIGRATE, $plan['category']);
        $this->assertTrue($plan['patterns'][0]['already_v2_matching']);

        // 5) reconcileLive() (v1, produccion) sigue reviewed.
        $matrixService = app(SectionCalibrationMatrixService::class);
        $matrix = $matrixService->buildPatternMatrix('A01', 'B');
        $this->assertTrue($matrix['reconciliation']['effective_section_reviewed']);
        $this->assertSame('reviewed', $matrix['patterns'][0]['reconciliation_status']);

        // 6) reconcileLiveCanonical() (todavia sin activar) tambien reviewed.
        $reconciler = app(PatternReconciliationService::class);
        $canonicalPatterns = array_map(
            fn ($p) => ['id' => $p['id'], 'canonical_fingerprint' => $p['canonical_fingerprint'], 'filas' => $p['filas']],
            $matrix['patterns']
        );
        $byPatternId = [1 => [$afterNormalSave, $stored['_questions']['A01_B'][2]]];
        $canonicalResult = $reconciler->reconcileLiveCanonical($canonicalPatterns, $byPatternId);
        $this->assertSame('reviewed', $canonicalResult[1]['reconciliation_status']);
    }
}
