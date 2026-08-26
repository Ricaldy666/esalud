<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RuleEngine\Services\MismatchResolutionAuditService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la identidad de clave de MismatchResolutionAuditService (2026-08-24,
 * hallazgo A09/G P3 -- ver docblock de la clase para la causa raiz
 * completa): una clave de auditoria NUNCA puede depender solo de
 * "{sheet}_{section}_{pattern_id}" porque pattern_id es posicional e
 * inestable (mismo problema, otro subsistema, que
 * PatternMigrationScanner::matchLivePatternsToHistorical() ya resuelve para
 * el emparejamiento de preguntas historicas).
 *
 * 100% pure a nivel de logica -- solo usa Storage::fake('local'), sin DB.
 */
class MismatchResolutionAuditServiceIdentityTest extends TestCase
{
    private function service(): MismatchResolutionAuditService
    {
        return app(MismatchResolutionAuditService::class);
    }

    private function seedLegacyEntry(string $sheet, string $section, int $patternId, array $rows, string $category = MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM): void
    {
        $all = [];
        if (Storage::disk('local')->exists('certificacion/mismatch-resolution-audit.json')) {
            $all = json_decode(Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json'), true) ?? [];
        }
        $sorted = $rows;
        sort($sorted, SORT_NUMERIC);
        $all["{$sheet}_{$section}_{$patternId}"] = [
            'sheet' => $sheet, 'section' => $section, 'pattern_id' => $patternId,
            'category' => $category, 'audited_fingerprint' => 'fpv2_legacy_' . implode('', $sorted),
            'audited_rows' => $sorted, 'reason' => 'legacy seed', 'audited_by' => 'Administrador Esalud',
            'audited_at' => '2026-08-24T14:38:09+00:00',
        ];
        Storage::disk('local')->put('certificacion/mismatch-resolution-audit.json', json_encode($all, JSON_PRETTY_PRINT));
    }

    // ── 1: tag legacy normal (sin ningun corrimiento) sigue siendo legible ──

    public function test_legacy_tag_without_drift_is_still_readable(): void
    {
        Storage::fake('local');
        $this->seedLegacyEntry('A09', 'G', 1, [179, 180, 181, 182]);

        $found = $this->service()->getTag('A09', 'G', 1, [179, 180, 181, 182]);

        $this->assertNotNull($found);
        $this->assertSame([179, 180, 181, 182], $found['audited_rows']);
        $this->assertSame('legacy seed', $found['reason']);
    }

    // ── 2: A09_G_3 legacy [184-189] y nuevo [190,191] coexisten ──

    public function test_legacy_and_new_stable_tags_coexist_for_shifted_pattern_id(): void
    {
        Storage::fake('local');
        // Legado real: pattern_id=3 significaba [184-189] cuando se audito.
        $this->seedLegacyEntry('A09', 'G', 3, [184, 185, 186, 187, 188, 189]);

        // Hoy, pattern_id=3 (vivo) significa [190,191] (remanente de P2 tras
        // excluir el TOTAL lider 183) -- se tagea con la clave ESTABLE.
        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_3f84a7496c12bad0', [190, 191], 'Exclusion TOTAL lider fila 183.', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $all = json_decode(Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json'), true);

        // La clave legacy original SIGUE existiendo, intacta, con su contenido original.
        $this->assertArrayHasKey('A09_G_3', $all);
        $this->assertSame([184, 185, 186, 187, 188, 189], $all['A09_G_3']['audited_rows']);
        $this->assertSame('safe_reconfirm', $all['A09_G_3']['category']);

        // Y existe una clave NUEVA, distinta, para el patron vivo real de hoy.
        $newKeys = array_filter(array_keys($all), fn ($k) => $k !== 'A09_G_3' && str_starts_with($k, 'A09_G_'));
        $this->assertCount(1, $newKeys, 'debe existir exactamente una clave nueva ademas de la legacy A09_G_3');
        $newKey = array_values($newKeys)[0];
        $this->assertStringContainsString('rowset_', $newKey, 'la clave nueva debe tener formato estable');
        $this->assertSame([190, 191], $all[$newKey]['audited_rows']);
        $this->assertSame('structural_row_exclusion', $all[$newKey]['category']);
    }

    // ── 3: lookup del patron vivo [190,191] devuelve SOLO su tag correcto ──

    public function test_lookup_for_shifted_live_pattern_returns_only_its_own_tag(): void
    {
        Storage::fake('local');
        $this->seedLegacyEntry('A09', 'G', 3, [184, 185, 186, 187, 188, 189]);
        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_3f84a7496c12bad0', [190, 191], 'test', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $found = $this->service()->getTag('A09', 'G', 3, [190, 191]);

        $this->assertNotNull($found);
        $this->assertSame([190, 191], $found['audited_rows']);
        $this->assertSame('structural_row_exclusion', $found['category']);
    }

    // ── 4: lookup historico [184-189] conserva el tag viejo ──

    public function test_lookup_for_historical_rows_still_finds_legacy_tag(): void
    {
        Storage::fake('local');
        $this->seedLegacyEntry('A09', 'G', 3, [184, 185, 186, 187, 188, 189]);
        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_3f84a7496c12bad0', [190, 191], 'test', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        // Si alguien busca por las filas HISTORICAS [184-189] (ej. otra
        // seccion que hoy por casualidad tambien tiene pattern_id=3 en esa
        // posicion, o una herramienta de auditoria retrospectiva), el tag
        // legacy sigue siendo encontrable -- no se perdio ni se corrompio.
        $found = $this->service()->getTag('A09', 'G', 3, [184, 185, 186, 187, 188, 189]);

        $this->assertNotNull($found);
        $this->assertSame('safe_reconfirm', $found['category']);
        $this->assertSame([184, 185, 186, 187, 188, 189], $found['audited_rows']);
    }

    // ── 5: pattern_id desplazado no provoca colision (setTag nunca pisa la entrada legacy) ──

    public function test_shifted_pattern_id_never_collides_on_write(): void
    {
        Storage::fake('local');
        $this->seedLegacyEntry('A09', 'G', 3, [184, 185, 186, 187, 188, 189]);
        $beforeLegacy = json_decode(Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json'), true)['A09_G_3'];

        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_3f84a7496c12bad0', [190, 191], 'test', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $afterLegacy = json_decode(Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json'), true)['A09_G_3'];

        $this->assertSame($beforeLegacy, $afterLegacy, 'la entrada legacy A09_G_3 debe permanecer byte a byte identica tras taguear el patron vivo distinto que hoy ocupa esa posicion');
    }

    // ── 6: safe_reconfirm y structural_row_exclusion coexisten en la misma seccion ──

    public function test_safe_reconfirm_and_structural_row_exclusion_coexist(): void
    {
        Storage::fake('local');
        $this->service()->setTag(
            'A09', 'G', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            'fpv2_p1', [179, 180, 181, 182], 'test', 'Administrador Esalud',
        );
        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_p3', [190, 191], 'test', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $tag1 = $this->service()->getTag('A09', 'G', 1, [179, 180, 181, 182]);
        $tag3 = $this->service()->getTag('A09', 'G', 3, [190, 191]);

        $this->assertSame('safe_reconfirm', $tag1['category']);
        $this->assertSame('structural_row_exclusion', $tag3['category']);
    }

    // ── 7: ninguna de las entradas preexistentes se pierde al agregar una nueva ──

    public function test_no_preexisting_entries_are_lost_when_adding_new_tag(): void
    {
        Storage::fake('local');
        $this->seedLegacyEntry('A05', 'C', 1, [35]);
        $this->seedLegacyEntry('A05', 'C', 2, [36, 37, 38]);
        $this->seedLegacyEntry('A19b', 'A', 3, [53, 54, 55, 56, 57]);
        $this->seedLegacyEntry('A09', 'G', 3, [184, 185, 186, 187, 188, 189]);

        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_p3', [190, 191], 'test', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $all = json_decode(Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json'), true);

        $this->assertArrayHasKey('A05_C_1', $all);
        $this->assertArrayHasKey('A05_C_2', $all);
        $this->assertArrayHasKey('A19b_A_3', $all);
        $this->assertArrayHasKey('A09_G_3', $all);
        $this->assertCount(5, $all, '4 legacy + 1 nueva = 5, ninguna perdida');
    }

    // ── 8: colision real (dos legacy con identico contenido) -- auditKeys() la detecta, nunca se sobreescribe automaticamente ──

    public function test_auditKeys_detects_real_collision_without_writing_anything(): void
    {
        Storage::fake('local');
        // Caso sintetico deliberado: dos pattern_id distintos que en algun
        // momento auditaron EXACTAMENTE el mismo conjunto de filas (raro,
        // pero posible si un patron se re-audito bajo un pattern_id
        // distinto sin que el conjunto de filas cambiara).
        $this->seedLegacyEntry('A99', 'Z', 1, [10, 11]);
        $this->seedLegacyEntry('A99', 'Z', 2, [10, 11]);

        $before = Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json');
        $report = $this->service()->auditKeys();
        $after = Storage::disk('local')->get('certificacion/mismatch-resolution-audit.json');

        $this->assertSame($before, $after, 'auditKeys() debe ser 100% de solo lectura -- el archivo no cambia');
        $this->assertCount(1, $report['collisions']);
        $this->assertCount(2, $report['collisions'][0]['legacy_keys']);
        $this->assertContains('A99_Z_1', $report['collisions'][0]['legacy_keys']);
        $this->assertContains('A99_Z_2', $report['collisions'][0]['legacy_keys']);
    }

    public function test_auditKeys_classifies_legacy_vs_stable_correctly(): void
    {
        Storage::fake('local');
        $this->seedLegacyEntry('A05', 'C', 1, [35]);
        $this->service()->setTag(
            'A09', 'G', 3, MismatchResolutionAuditService::CATEGORY_STRUCTURAL_ROW_EXCLUSION,
            'fpv2_p3', [190, 191], 'test', 'Administrador Esalud',
            historicalRows: [183, 190, 191], excludedTotalRows: [183],
            exclusionMechanism: MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL,
        );

        $report = $this->service()->auditKeys();

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['legacy_count']);
        $this->assertSame(1, $report['stable_count']);
        $this->assertSame([], $report['collisions']);
    }

    // ── 9: regresion -- flujo existente sigue funcionando (safe_reconfirm sin ningun corrimiento) ──

    public function test_regression_safe_reconfirm_lookup_unaffected(): void
    {
        Storage::fake('local');
        $this->service()->setTag(
            'A01', 'B', 1, MismatchResolutionAuditService::CATEGORY_SAFE_RECONFIRM,
            'fpv2_ok', [36, 37, 38, 39], 'Evidencia.', 'Auditor Uno',
        );

        $found = $this->service()->getTag('A01', 'B', 1, [36, 37, 38, 39]);

        $this->assertNotNull($found);
        $this->assertSame('safe_reconfirm', $found['category']);
        $this->assertSame([36, 37, 38, 39], $found['audited_rows']);

        // Filas distintas (patron realmente diferente) no deben encontrar este tag.
        $this->assertNull($this->service()->getTag('A01', 'B', 1, [40, 41]));
    }
}
