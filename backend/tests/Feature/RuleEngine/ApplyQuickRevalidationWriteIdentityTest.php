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
 * Cubre el fix de identidad en la ESCRITURA (2026-08-24, hallazgo de
 * corrupcion real en A09/G P3 -- ver docblock extenso en
 * FunctionalRuleService::applyQuickRevalidation()): antes de este fix, el
 * endpoint de confirmacion pasaba el pattern_id VIVO/posicional como
 * argumento de escritura; cuando un patron se desplaza de posicion (ej. tras
 * excluir una fila TOTAL lider), ese numero podia coincidir por casualidad
 * con el pattern_id CRUDO de un patron historico DISTINTO -- la escritura
 * terminaba sobrescribiendo las preguntas equivocadas.
 *
 * Fixture sintetico que replica la TOPOLOGIA EXACTA de A09/G real:
 *  - P1 (rows=[10,11]): patron normal, sin corrimiento.
 *  - P2 (rows=[12,19,20]): fila 12 = TOTAL lider (excluida por el mecanismo
 *    #6), remanente [19,20] -- equivalente a A09/G P2=[183,190,191].
 *  - P3 (rows=[13,14]): patron YA RESUELTO, completamente ajeno, que ocupa
 *    live position 2 despues de excluir la fila 12 -- equivalente a A09/G
 *    P3=[184-189], que paso a ocupar la posicion que antes ocupaba P2.
 *
 * Tras excluir la fila 12: live1=[10,11]->P1 (sin corrimiento), live2=[13,14]
 * (coincide EXACTO con P3 -- pero en POSICION 2, no 3), live3=[19,20]
 * (subconjunto de P2 original). El patron vivo "posicion 2" HOY corresponde
 * al historico P3 (id crudo 3) -- si algo escribe usando el id posicional
 * (2) en vez del identity-matched (3), corrompe datos de un patron ajeno ya
 * resuelto -- exactamente el bug real.
 */
class ApplyQuickRevalidationWriteIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Superadmin']);
        $this->admin = User::factory()->create(['name' => 'Funcionario Auditor']);
        $this->admin->assignRole('Superadmin');
        Sanctum::actingAs($this->admin);
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

    /** P1-style: formula-mode, C=SUM(D:E). */
    private function formulaModeRow(int $row): array
    {
        return [
            "A{$row}" => $this->cell(false, true, false, null, "Item {$row}"),
            "C{$row}" => $this->cell(false, true, true, "=SUM(D{$row}:E{$row})", null, ["D{$row}", "E{$row}"]),
            "D{$row}" => $this->cell(true, false),
            "E{$row}" => $this->cell(true, false),
        ];
    }

    /** TOTAL lider real (mecanismo #6): concepto en B, marcador TOTAL en C, formula hacia adelante en D. */
    private function leadingTotalRow(int $row, int $forwardFrom, int $forwardTo): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, 'Concepto real'),
            "C{$row}" => $this->cell(false, true, false, null, 'TOTAL'),
            "D{$row}" => $this->cell(false, true, true, "=SUM(D{$forwardFrom}:D{$forwardTo})", null, array_map(fn ($r) => "D{$r}", range($forwardFrom, $forwardTo))),
            "E{$row}" => $this->cell(false, true),
        ];
    }

    /** P3-style: direct-input via columna D unicamente. */
    private function directInputDRow(int $row): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, "P3 fila {$row}"),
            "D{$row}" => $this->cell(true, false),
        ];
    }

    /** Remanente de P2 (tras excluir el TOTAL): direct-input via columna E unicamente -- firma distinta de directInputDRow(). */
    private function directInputERow(int $row): array
    {
        return [
            "B{$row}" => $this->cell(false, true, false, null, "P2 remanente fila {$row}"),
            "E{$row}" => $this->cell(true, false),
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
                'revalidated_by' => $data['revalidated_by'] ?? null,
                'revalidated_at' => $data['revalidated_at'] ?? null,
            ];
        }

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            '_questions' => ["{$sheet}_{$section}" => $questions],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Fixture completo A09/G-like: P1=[10,11], P2=[12,19,20] (12=TOTAL), P3=[13,14] (ya resuelto, ajeno). */
    private function setupA09GStyleFixture(string $sheet, string $section): void
    {
        $this->createActiveStructure($sheet, $section, 10, 20);
        $cells = $this->formulaModeRow(10) + $this->formulaModeRow(11)
            + $this->leadingTotalRow(12, 19, 20)
            + $this->directInputDRow(13) + $this->directInputDRow(14)
            + $this->directInputERow(19) + $this->directInputERow(20);
        $this->putCellData($sheet, $section, $cells);

        $this->seedQuestions($sheet, $section, [
            1 => ['rows' => [10, 11], 'fingerprint' => 'fpv2_p1'],
            2 => ['rows' => [12, 19, 20], 'fingerprint' => 'fpv2_p2_con_total'],
            3 => ['rows' => [13, 14], 'fingerprint' => 'fpv2_p3_ajeno_YA_RESUELTO', 'revalidated_by' => 'Administrador Esalud', 'revalidated_at' => '2026-08-24T14:41:42+00:00'],
        ]);
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

    // ── 1: patron SIN corrimiento -- escribe sobre su propio pattern_id (comportamiento base intacto) ──

    public function test_pattern_without_shift_writes_to_its_own_raw_pattern_id(): void
    {
        Storage::fake('local');
        $this->setupA09GStyleFixture('WA', 'X');
        $fp = $this->liveFingerprint('WA', 'X', 1); // live pos 1 = P1, sin corrimiento

        app(MismatchResolutionAuditService::class)->setTag(
            'WA', 'X', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [10, 11], 'test', 'Auditor Uno',
        );

        $this->postJson($this->confirmEndpoint('WA', 'X', 1))->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);
        $q = $stored['_questions']['WA_X'][1]; // indice 1 = patron_1_empty
        $this->assertSame($fp, $q['pattern_fingerprint']);
        $this->assertSame([10, 11], $q['pattern_rows']);
    }

    // ── 2: shift -- vivo posicion 2 corresponde al historico P3 (id crudo 3), NO al id crudo 2 ──

    public function test_shifted_pattern_writes_to_correct_historical_pattern_id_not_positional(): void
    {
        Storage::fake('local');
        $this->setupA09GStyleFixture('WB', 'X');
        // live pos 2 = [13,14], coincide EXACTO con historico P3 (id crudo 3).
        $fp = $this->liveFingerprint('WB', 'X', 2);

        app(MismatchResolutionAuditService::class)->setTag(
            'WB', 'X', 2, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            $fp, [13, 14], 'test', 'Auditor Uno',
        );

        $this->postJson($this->confirmEndpoint('WB', 'X', 2))->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        // indice 2 = patron_2_empty (id crudo 2, historicamente [12,19,20]) -- NO debe tocarse.
        $p2 = $stored['_questions']['WB_X'][2];
        $this->assertSame('fpv2_p2_con_total', $p2['pattern_fingerprint'], 'el registro crudo pattern_id=2 (ajeno a este patron vivo) NUNCA debe tocarse');
        $this->assertSame([12, 19, 20], $p2['pattern_rows']);
        $this->assertNull($p2['revalidated_by']);

        // indice 3 = patron_3_empty (id crudo 3, historicamente [13,14]) -- este SI debe actualizarse.
        $p3 = $stored['_questions']['WB_X'][3];
        $this->assertSame($fp, $p3['pattern_fingerprint']);
        $this->assertSame([13, 14], $p3['pattern_rows']);
        $this->assertSame('Funcionario Auditor', $p3['revalidated_by']);
    }

    // ── 3: caso A09/G real -- vivo P3=[19,20] corresponde al historico [12,19,20] (id crudo 2) ──

    public function test_a09_g_style_remainder_writes_to_correct_historical_pattern_id(): void
    {
        Storage::fake('local');
        $this->setupA09GStyleFixture('WC', 'X');
        // live pos 3 = [19,20] (remanente de P2 tras excluir 12).
        $fp = $this->liveFingerprint('WC', 'X', 3);

        app(MismatchResolutionAuditService::class)->setTag(
            'WC', 'X', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp, [19, 20], 'test', 'Auditor Uno',
            historicalRows: [12, 19, 20], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $this->postJson($this->confirmEndpoint('WC', 'X', 3))->assertOk();

        $stored = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true);

        // indice 2 = patron_2_empty (id crudo 2, [12,19,20]) -- este SI debe actualizarse.
        $p2 = $stored['_questions']['WC_X'][2];
        $this->assertSame($fp, $p2['pattern_fingerprint']);
        $this->assertSame([19, 20], $p2['pattern_rows']);
        $this->assertSame('structural_row_exclusion', $p2['revalidation_source_type']);

        // indice 3 = patron_3_empty (id crudo 3, [13,14], AJENO Y YA RESUELTO) -- NO debe tocarse.
        $p3 = $stored['_questions']['WC_X'][3];
        $this->assertSame('fpv2_p3_ajeno_YA_RESUELTO', $p3['pattern_fingerprint'], 'el patron ajeno [13,14], ya resuelto en una tanda anterior, NUNCA debe tocarse por esta escritura');
        $this->assertSame([13, 14], $p3['pattern_rows']);
        $this->assertSame('Administrador Esalud', $p3['revalidated_by']);
        $this->assertSame('2026-08-24T14:41:42+00:00', $p3['revalidated_at'], 'la revalidacion ORIGINAL de una tanda anterior debe permanecer intacta, no pisada por esta escritura');
    }

    // ── 4: verificacion explicita adicional -- [13,14] (equivalente a A09/G [184-189]) permanece byte a byte igual ──

    public function test_unrelated_already_resolved_pattern_is_never_modified(): void
    {
        Storage::fake('local');
        $this->setupA09GStyleFixture('WD', 'X');
        $before = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true)['_questions']['WD_X'][3];

        $fp3 = $this->liveFingerprint('WD', 'X', 3);
        app(MismatchResolutionAuditService::class)->setTag(
            'WD', 'X', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            $fp3, [19, 20], 'test', 'Auditor Uno',
            historicalRows: [12, 19, 20], excludedTotalRows: [12],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );
        $this->postJson($this->confirmEndpoint('WD', 'X', 3))->assertOk();

        $after = json_decode(Storage::disk('local')->get('certificacion/reglas-funcionales.json'), true)['_questions']['WD_X'][3];

        $this->assertSame($before, $after, 'el patron ajeno debe permanecer BYTE A BYTE identico tras confirmar un patron vivo distinto');
    }

    // ── 5/6 ya cubiertas arriba: structural_row_exclusion (test 3) y safe_reconfirm (test 2) ──
    // ── 7: split/merge ambiguo -- rechaza SIN escribir nada ──

    public function test_ambiguous_split_rejects_without_writing(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('WE', 'X', 50, 53);
        $this->putCellData('WE', 'X', $this->formulaModeRow(50) + $this->formulaModeRow(51) + $this->formulaModeRow(52) + $this->formulaModeRow(53));
        $this->seedQuestions('WE', 'X', [
            1 => ['rows' => [50, 51, 52, 53], 'fingerprint' => 'fpv2_unico'],
        ]);

        // Esto no deberia ni siquiera dejar auditar (categoria no-MISMATCH),
        // pero probamos el endpoint de confirmacion directamente con un tag
        // manual para verificar que el gate de escritura tambien protege.
        app(MismatchResolutionAuditService::class)->setTag(
            'WE', 'X', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            'fpv2_cualquiera', [50, 51], 'test', 'Auditor Uno',
        );

        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');
        $response = $this->postJson($this->confirmEndpoint('WE', 'X', 1));
        $after = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $response->assertStatus(409);
        $this->assertSame($before, $after, 'ninguna escritura debe ocurrir cuando la identidad no se resuelve de forma inequivoca');
    }

    // ── 8: patron nuevo sin historico -- rechaza SIN escribir nada ──

    public function test_new_pattern_without_history_rejects_without_writing(): void
    {
        Storage::fake('local');
        $this->createActiveStructure('WF', 'X', 40, 41);
        $this->putCellData('WF', 'X', $this->formulaModeRow(40) + $this->formulaModeRow(41));
        $this->seedQuestions('WF', 'X', [
            99 => ['rows' => [100, 101], 'fingerprint' => 'fpv2_no_relacionado'],
        ]);

        app(MismatchResolutionAuditService::class)->setTag(
            'WF', 'X', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            'fpv2_cualquiera', [40, 41], 'test', 'Auditor Uno',
        );

        $before = Storage::disk('local')->get('certificacion/reglas-funcionales.json');
        $response = $this->postJson($this->confirmEndpoint('WF', 'X', 1));
        $after = Storage::disk('local')->get('certificacion/reglas-funcionales.json');

        $response->assertStatus(409);
        $this->assertSame($before, $after);
    }
}
