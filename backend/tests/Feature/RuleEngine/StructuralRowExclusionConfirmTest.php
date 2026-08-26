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
 * Cubre CalibrationViewController::confirmMismatchResolution() para la
 * categoria structural_row_exclusion (2026-08-24, hallazgo A09/G P2/P4) --
 * el gate mecanico END-TO-END via el endpoint HTTP real, no solo el
 * comando de tag (ya cubierto en
 * RuleTagMismatchResolutionCommandStructuralExclusionTest).
 *
 * safe_reconfirm NO se modifica -- ver los tests de regresion al final,
 * mas la suite completa de MismatchResolutionApiTest (no duplicada aqui).
 *
 * Fixtures 100% sinteticas -- ninguna seccion real se toca.
 */
class StructuralRowExclusionConfirmTest extends TestCase
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

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $val = null, array $deps = []): array
    {
        return [
            'valor_bruto' => $val, 'es_editable' => $editable, 'esta_bloqueada' => $blocked,
            'es_formula' => $formula, 'formula' => $formulaText, 'dependencias' => $deps,
        ];
    }

    private function createActiveStructure(string $sheet, string $section, int $filaInicio, int $filaFin): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A', 'anio' => 2026, 'version_number' => 2,
            'hash_estructura' => 'hash_' . $sheet . '_' . $section,
            'estructura' => ['forms' => [[
                'sheetName' => $sheet,
                'sections' => [[
                    'codigo' => $section, 'titulo' => 'Seccion de prueba',
                    'filaInicioDatos' => $filaInicio, 'filaFinDatos' => $filaFin, 'filaHeader' => $filaInicio - 1,
                    'fields' => [
                        ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false],
                        ['letra' => 'B', 'label' => 'Concepto2', 'esTotal' => false, 'esControlOculto' => false],
                        ['letra' => 'C', 'label' => 'Total', 'esTotal' => true, 'esControlOculto' => false],
                        ['letra' => 'D', 'label' => 'Dato1', 'esTotal' => false, 'esControlOculto' => false],
                        ['letra' => 'E', 'label' => 'Dato2', 'esTotal' => false, 'esControlOculto' => false],
                    ],
                ]],
            ]]],
            'status' => 'active',
        ]);
    }

    private function dataRowCells(int $row): array
    {
        return [
            "A{$row}" => $this->cell(false, true, false, null, "Item {$row}"),
            "C{$row}" => $this->cell(false, true, true, "=SUM(D{$row}:E{$row})", null, ["D{$row}", "E{$row}"]),
            "D{$row}" => $this->cell(true, false),
            "E{$row}" => $this->cell(true, false),
        ];
    }

    private function leadingTotalRowCells(int $row, int $forwardFrom, int $forwardTo): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, 'Concepto real'),
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$forwardFrom}:D{$forwardTo})", null, array_map(fn ($r) => "D{$r}", range($forwardFrom, $forwardTo))),
            "E{$row}" => $this->cell(false, true),
        ];
    }

    private function remainderRowCells(int $row): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, "Remanente {$row}"),
            "D{$row}" => $this->cell(true, false),
        ];
    }

    private function putCellData(string $sheet, string $section, array $cells): void
    {
        Storage::disk('local')->put("certificacion/cell-data/{$sheet}-{$section}.json", json_encode($cells));
    }

    private function seedQuestions(string $sheet, string $section, array $patternsById): void
    {
        $questions = [[
            'id' => 'section_review', 'type' => 'section_review',
            'response' => 'revisada', 'review_status' => 'section_reviewed',
            'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
        ]];
        foreach ($patternsById as $pid => $data) {
            $questions[] = [
                'id' => "patron_{$pid}_empty", 'type' => 'pattern_question', 'pattern_id' => $pid,
                'question' => "Pregunta de prueba (Patrón {$pid}: " . implode(',', $data['rows']) . ')',
                'response' => $data['response'] ?? 'debe_registrar_cero', 'review_status' => 'reviewed',
                'reviewed_by' => 'Francisco Arcos', 'reviewed_at' => '2026-07-01T10:00:00.000Z',
                'source_type' => 'manual', 'fingerprint_version' => 2,
                'pattern_fingerprint' => $data['fingerprint'],
                'pattern_rows' => $data['rows'],
            ];
        }

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => ["{$sheet}_{$section}" => $questions],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function detailsEndpoint(string $sheet, string $section, int $patternId): string
    {
        return "/api/v1/rule-engine/catalog/{$sheet}/sections/{$section}/patterns/{$patternId}/mismatch-resolution";
    }

    private function confirmEndpoint(string $sheet, string $section, int $patternId): string
    {
        return "/api/v1/rule-engine/catalog/{$sheet}/sections/{$section}/patterns/{$patternId}/mismatch-resolution/confirm";
    }

    private function liveFingerprint(string $sheet, string $section, int $patternId): string
    {
        $response = $this->getJson($this->detailsEndpoint($sheet, $section, $patternId));

        return $response->json('data.live_canonical_fingerprint');
    }

    /** Fixture base: P1=[10,11] real, P2=[12,13,14] (12=TOTAL lider, 13/14=remanente). Vivo tras el fix: id1=[10,11], id2=[13,14]. */
    private function setupBaseFixture(string $sheet, string $section): void
    {
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure($sheet, $section, 10, 14);
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->leadingTotalRowCells(12, 13, 14)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData($sheet, $section, $cells);
        $this->seedQuestions($sheet, $section, [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);
    }

    // ── Caso 1: tag valido + endpoint -- confirma correctamente ──

    public function test_valid_structural_exclusion_tag_is_confirmed(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TA', 'X');
        $fp = $this->liveFingerprint('TA', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TA', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'Exclusion de TOTAL lider fila 12, verificado.', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TA', 'X', 2));

        $response->assertOk();
        $response->assertJsonPath('message', 'MISMATCH resuelto (exclusión estructural de fila TOTAL líder, mecanismo #6).');

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['TA_X'][2];
        $this->assertSame($fp, $q['pattern_fingerprint']);
        $this->assertSame([13, 14], $q['pattern_rows']);
        $this->assertSame('Funcionario Auditor', $q['revalidated_by']);
        $this->assertSame('structural_row_exclusion', $q['revalidation_source_type']);
        // Historico intacto -- nunca se toca.
        $this->assertSame('debe_registrar_cero', $q['response']);
        $this->assertSame('Francisco Arcos', $q['reviewed_by']);
        $this->assertSame('reviewed', $q['review_status']);

        $history = $stored['_questions_history']['TA_X'] ?? [];
        $entry = collect($history)->firstWhere('type', 'pattern_revalidation');
        $this->assertNotNull($entry);
        $this->assertSame([12, 13, 14], $entry['historical_rows_before_exclusion']);
        $this->assertSame([12], $entry['excluded_total_rows']);
        $this->assertSame(MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL, $entry['exclusion_mechanism']);
        $this->assertSame('structural_row_exclusion', $entry['revalidation_source_type']);
    }

    // ── Caso 2: audited_fingerprint desactualizado -- rechaza ──

    public function test_stale_audited_fingerprint_is_rejected(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TB', 'X');

        app(MismatchResolutionAuditService::class)->setTag(
            'TB', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_FINGERPRINT_DESACTUALIZADO', [13, 14], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TB', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'audit_stale');
        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('revalidated_by', $stored['_questions']['TB_X'][2]);
    }

    // ── Caso 3: audited_rows distintas -- rechaza ──

    public function test_stale_audited_rows_is_rejected(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TC', 'X');
        $fp = $this->liveFingerprint('TC', 'X', 2);

        // audited_rows=[13] (incompleto, deberia ser [13,14]) -- con clave
        // ESTABLE (2026-08-24), setTag() guarda esto bajo la clave derivada
        // de [13], NO de [13,14]. Como nunca coincide con el conjunto de
        // filas vivo real, getTag() no lo encuentra en absoluto (ni por
        // clave estable ni por fallback legacy, que tampoco se escribe mas)
        // -- el resultado correcto es 'not_audited' (para el patron vivo
        // real, sencillamente no existe ningun tag valido), no 'audit_stale'.
        // La deteccion de "tag correcto que se volvio obsoleto DESPUES de
        // auditarse" sigue funcionando exactamente igual -- ver
        // test_stale_audited_fingerprint_is_rejected, que audita con las
        // filas CORRECTAS y solo desactualiza el fingerprint.
        app(MismatchResolutionAuditService::class)->setTag(
            'TC', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TC', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'not_audited');
    }

    // ── Caso 4: historical_rows incorrectas (no coincide con lo re-resuelto por identidad) -- rechaza ──

    public function test_incorrect_historical_rows_is_rejected(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TD', 'X');
        $fp = $this->liveFingerprint('TD', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TD', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14, 99], // 99 nunca existio en el historico real
            excludedTotalRows: [12, 99],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TD', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'audit_stale');
    }

    // ── Caso 5: excluded_total_rows incorrectas (no reconstruyen el historico via union) -- rechaza ──

    public function test_incorrect_excluded_total_rows_is_rejected(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TE', 'X');
        $fp = $this->liveFingerprint('TE', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TE', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [99], // deberia ser [12]
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TE', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'structural_exclusion_mismatch');
    }

    // ── Caso 6: fila excluida ya no cumple el mecanismo #6 -- rechaza ──

    public function test_excluded_row_no_longer_matching_mechanism_6_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure('TF', 'X', 10, 14);

        // Fila 12 SIN ningun dato -- ausente del vivo por falta de datos, no
        // por ser TOTAL lider real. Vivo: id1=[10,11], id2=[13,14].
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('TF', 'X', $cells);
        $this->seedQuestions('TF', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_supuesto_total'],
        ]);
        $fp = $this->liveFingerprint('TF', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TF', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TF', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'structural_exclusion_mismatch');
        $response->assertSee('fila 12', false);
    }

    // ── Caso 7: diferencia ADICIONAL de filas (fila viva no explicada) -- rechaza ──

    public function test_extra_unexplained_row_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure('TG', 'X', 10, 15);

        // Igual que la base, pero agrega fila 15 (misma firma que 13/14) --
        // vivo id2 ahora = [13,14,15], una fila mas que el historico nunca tuvo.
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->leadingTotalRowCells(12, 13, 14)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14) + $this->remainderRowCells(15);
        $this->putCellData('TG', 'X', $cells);
        $this->seedQuestions('TG', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_total'],
        ]);
        $fp = $this->liveFingerprint('TG', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TG', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14, 15], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TG', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'structural_exclusion_mismatch');
    }

    // ── Caso 8: split/merge ambiguo -- rechaza (ni siquiera llega MISMATCH) ──

    public function test_ambiguous_merge_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure('TH', 'X', 50, 53);
        $this->putCellData('TH', 'X', $this->dataRowCells(50) + $this->dataRowCells(51) + $this->dataRowCells(52) + $this->dataRowCells(53));
        $this->seedQuestions('TH', 'X', [
            1 => ['rows' => [50, 51], 'fingerprint' => 'fpv2_hist_a'],
            2 => ['rows' => [52, 53], 'fingerprint' => 'fpv2_hist_b'],
        ]);

        app(MismatchResolutionAuditService::class)->setTag(
            'TH', 'X', 1, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_cualquiera', [50, 51, 52, 53], 'test', 'Auditor Uno',
            historicalRows: [50, 51], excludedTotalRows: [99],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('TH', 'X', 1));

        $response->assertStatus(409);
        $this->assertContains($response->json('errors.0'), ['no_longer_mismatch', 'pattern_not_found']);
    }

    // ── Caso 9: regresion -- safe_reconfirm sigue funcionando exactamente igual ──

    public function test_safe_reconfirm_regression_unaffected(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TI', 'X');
        $fp = $this->liveFingerprint('TI', 'X', 1);

        app(MismatchResolutionAuditService::class)->setTag(
            'TI', 'X', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [10, 11], 'Evidencia.', 'Auditor Uno',
        );

        $response = $this->postJson($this->confirmEndpoint('TI', 'X', 1));

        $response->assertOk();
        $response->assertJsonPath('message', 'MISMATCH resuelto (safe_reconfirm).');

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['TI_X'][1];
        $this->assertSame('manual_revalidation', $q['revalidation_source_type']);
        $this->assertArrayNotHasKey('excluded_total_rows', $q);
    }

    // ── Caso 10: human_review/structural_review siguen sin ser confirmables ──

    public function test_human_review_still_not_confirmable(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TJ', 'X');
        $fp = $this->liveFingerprint('TJ', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TJ', 'X', 2, MismatchResolutionAuditService::CATEGORY_HUMAN_REVIEW,
            $fp, [13, 14], 'Requiere revision humana.', 'Auditor Uno',
        );

        $response = $this->postJson($this->confirmEndpoint('TJ', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'requires_full_review');
        $response->assertJsonPath('data.resolution_category', 'human_review');
    }

    public function test_structural_review_still_not_confirmable(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('TK', 'X');
        $fp = $this->liveFingerprint('TK', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'TK', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_REVIEW,
            $fp, [13, 14], 'Cambio estructural real.', 'Auditor Uno',
        );

        $response = $this->postJson($this->confirmEndpoint('TK', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'requires_full_review');
        $response->assertJsonPath('data.resolution_category', 'structural_review');
    }
}
