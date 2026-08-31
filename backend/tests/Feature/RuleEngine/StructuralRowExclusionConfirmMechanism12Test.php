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
 * Espejo de StructuralRowExclusionConfirmTest, pero para mecanismo #12
 * (SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()) --
 * extension 2026-08-28, SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12.
 *
 * Fixtures 100% sinteticas (hojas T12*), ninguna seccion real (A09/I ni
 * ninguna otra) se toca. Ningun --commit/confirm real contra A09/I en
 * ningun test de este archivo -- todo corre contra datos aislados
 * (RefreshDatabase + Storage::fake), exactamente el mismo patron ya usado
 * por el archivo original de mecanismo #6.
 */
class StructuralRowExclusionConfirmMechanism12Test extends TestCase
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

    /** Subtotal embebido hacia atras -- ver nota de orden de columnas en RuleTagMismatchResolutionCommandMechanism12Test. */
    private function backwardSubtotalRowCells(int $row, int $backFrom, int $backTo): array
    {
        return [
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$backFrom}:D{$backTo})", null, array_map(fn ($r) => "D{$r}", range($backFrom, $backTo))),
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

    /** Fixture base: P1=[10,11] real, P2=[12,13,14] (12=subtotal embebido hacia atras, 13/14=remanente). Vivo tras el fix: id1=[10,11], id2=[13,14]. */
    private function setupBaseFixture(string $sheet, string $section): void
    {
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure($sheet, $section, 10, 14);
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->backwardSubtotalRowCells(12, 10, 11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData($sheet, $section, $cells);
        $this->seedQuestions($sheet, $section, [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_con_subtotal'],
        ]);
    }

    // ── Caso 1: tag valido (#12) + endpoint -- confirma correctamente, mecanismo persistido/auditado ──

    public function test_valid_structural_exclusion_tag_mechanism_12_is_confirmed(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('T12A', 'X');
        $fp = $this->liveFingerprint('T12A', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'T12A', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'Exclusion de subtotal embebido hacia atras fila 12, verificado.', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('T12A', 'X', 2));

        $response->assertOk();
        $response->assertJsonPath('message', 'MISMATCH resuelto (exclusión estructural de subtotal embebido hacia atrás, mecanismo #12).');

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['T12A_X'][2];
        $this->assertSame($fp, $q['pattern_fingerprint']);
        $this->assertSame([13, 14], $q['pattern_rows']);
        $this->assertSame('Funcionario Auditor', $q['revalidated_by']);
        $this->assertSame('structural_row_exclusion', $q['revalidation_source_type']);
        // Historico intacto -- nunca se toca, mismo requisito que mecanismo #6.
        $this->assertSame('debe_registrar_cero', $q['response']);
        $this->assertSame('Francisco Arcos', $q['reviewed_by']);
        $this->assertSame('reviewed', $q['review_status']);

        $history = $stored['_questions_history']['T12A_X'] ?? [];
        $entry = collect($history)->firstWhere('type', 'pattern_revalidation');
        $this->assertNotNull($entry);
        $this->assertSame([12, 13, 14], $entry['historical_rows_before_exclusion']);
        $this->assertSame([12], $entry['excluded_total_rows']);
        $this->assertSame(MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL, $entry['exclusion_mechanism'], 'el mecanismo #12 debe persistirse en el historico auditable con su propio valor, distinto del de #6');
        $this->assertSame('structural_row_exclusion', $entry['revalidation_source_type']);
    }

    // ── Caso 2: fila excluida ya no cumple el mecanismo #12 -- rechaza ──

    public function test_excluded_row_no_longer_matching_mechanism_12_is_rejected(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);
        $this->createActiveStructure('T12B', 'X', 10, 14);

        // Fila 12 SIN ningun dato -- ausente del vivo por falta de datos, no
        // por ser subtotal embebido real. Vivo: id1=[10,11], id2=[13,14].
        $cells = $this->dataRowCells(10) + $this->dataRowCells(11)
            + $this->remainderRowCells(13) + $this->remainderRowCells(14);
        $this->putCellData('T12B', 'X', $cells);
        $this->seedQuestions('T12B', 'X', [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_hist_p1'],
            2 => ['rows' => [12, 13, 14], 'fingerprint' => 'fpv2_hist_p2_supuesto_subtotal'],
        ]);
        $fp = $this->liveFingerprint('T12B', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'T12B', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL,
        );

        $response = $this->postJson($this->confirmEndpoint('T12B', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'structural_exclusion_mismatch');
        $response->assertSee('fila 12', false);

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('revalidated_by', $stored['_questions']['T12B_X'][2], 'un rechazo nunca debe escribir nada en reglas-funcionales.json');
    }

    // ── Caso 3: mecanismo desconocido en el tag -- rechaza ──

    public function test_unknown_exclusion_mechanism_is_rejected(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('T12C', 'X');
        $fp = $this->liveFingerprint('T12C', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'T12C', 'X', 2, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [13, 14], 'test', 'Auditor Uno',
            historicalRows: [12, 13, 14], excludedTotalRows: [12],
            exclusionMechanism: 'mecanismo_inventado_no_soportado',
        );

        $response = $this->postJson($this->confirmEndpoint('T12C', 'X', 2));

        $response->assertStatus(409);
        $response->assertJsonPath('errors.0', 'incomplete_structural_exclusion_tag');

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $this->assertArrayNotHasKey('revalidated_by', $stored['_questions']['T12C_X'][2], 'un mecanismo desconocido nunca debe llegar a escribir nada');
    }

    // ── Caso 4: regresion -- safe_reconfirm sigue funcionando exactamente igual con la extension de #12 presente ──

    public function test_safe_reconfirm_regression_unaffected_by_mechanism_12_extension(): void
    {
        Storage::fake('local');
        $this->setupBaseFixture('T12D', 'X');
        $fp = $this->liveFingerprint('T12D', 'X', 1);

        app(MismatchResolutionAuditService::class)->setTag(
            'T12D', 'X', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [10, 11], 'Evidencia.', 'Auditor Uno',
        );

        $response = $this->postJson($this->confirmEndpoint('T12D', 'X', 1));

        $response->assertOk();
        $response->assertJsonPath('message', 'MISMATCH resuelto (safe_reconfirm).');
    }
}
