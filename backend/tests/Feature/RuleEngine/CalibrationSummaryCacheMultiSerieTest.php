<?php

namespace Tests\Feature\RuleEngine;

use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BM-11.12 (ENGINE_UI_REDUNDANCY -- Hallazgo B de BM-11.11): certifica que
 * la invalidacion del cache de resumen de calibracion (`rem:calibration_
 * summary[:SERIE]`) es realmente multiserie -- antes de este fix, los 5
 * puntos reales de invalidacion (FunctionalRuleService::saveQuestions()/
 * applyQuickRevalidation()/resolveHumanReviewPattern(),
 * StructureApprovalService::activate(), RemSheetUsageStatusService::
 * setStatus()) reconstruian siempre la clave BASE de Serie A
 * (CALIBRATION_SUMMARY_CACHE_KEY, sin sufijo), sin importar la serie
 * realmente modificada -- BM (o cualquier otra serie no-A) nunca invalidaba
 * su propia clave. Ademas, saveFunctionalRuleByRow()/clearFunctionalRuleByRow()
 * no invalidaban NADA (gap distinto, tambien cerrado aqui).
 *
 * Genérico, sin hardcodes de "BM" en el codigo productivo -- estos tests
 * usan 'BM' solo como ejemplo de "una serie distinta de A", exactamente
 * como lo haria 'BS'/'D'/'P'.
 *
 * 100% aislado: RefreshDatabase (esalud_testing), Storage::fake('local'),
 * cache de testing -- nunca se toca el cache real de esalud_dev ni
 * reglas-funcionales.json real.
 */
class CalibrationSummaryCacheMultiSerieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function primeSentinelCache(string $serie, $sentinelValue = 'SENTINEL_STALE'): string
    {
        $key = SectionCalibrationMatrixService::calibrationSummaryCacheKey($serie);
        Cache::put($key, $sentinelValue, 3600);

        return $key;
    }

    // ── Diseño canonico de la key: A sin sufijo, cualquier otra serie con
    // sufijo -- nunca colisionan ────────────────────────────────────────

    public function test_calibration_summary_cache_key_is_unsuffixed_for_serie_a_and_suffixed_for_others(): void
    {
        $this->assertSame(
            'rem:calibration_summary',
            SectionCalibrationMatrixService::calibrationSummaryCacheKey('A'),
        );
        $this->assertSame(
            'rem:calibration_summary:BM',
            SectionCalibrationMatrixService::calibrationSummaryCacheKey('BM'),
        );
        $this->assertSame(
            'rem:calibration_summary:BS',
            SectionCalibrationMatrixService::calibrationSummaryCacheKey('BS'),
        );
    }

    public function test_forget_calibration_summary_cache_invalidates_only_the_targeted_serie(): void
    {
        $keyA = $this->primeSentinelCache('A', 'stale_A');
        $keyBm = $this->primeSentinelCache('BM', 'stale_BM');

        SectionCalibrationMatrixService::forgetCalibrationSummaryCache('BM');

        $this->assertFalse(Cache::has($keyBm), 'BM debe quedar invalidada.');
        $this->assertTrue(Cache::has($keyA), 'A NO debe verse afectada por invalidar BM.');
        $this->assertSame('stale_A', Cache::get($keyA));
    }

    // ── saveQuestions() invalida SOLO la serie indicada ───────────────────

    public function test_save_questions_for_serie_a_invalidates_only_a_cache(): void
    {
        Storage::fake('local');
        $keyA = $this->primeSentinelCache('A', 'stale_A');
        $keyBm = $this->primeSentinelCache('BM', 'stale_BM');

        $service = app(FunctionalRuleService::class);
        $service->saveQuestions('TEST18', 'Z', [
            ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'question' => 'Sin datos', 'response' => 'debe_registrar_cero', 'pattern_id' => 1],
        ], 'A');

        $this->assertFalse(Cache::has($keyA), 'GAP CERRADO: guardar Serie A invalida su propia clave.');
        $this->assertTrue(Cache::has($keyBm), 'BM no debe verse afectada por un guardado de Serie A.');
        $this->assertSame('stale_BM', Cache::get($keyBm));
    }

    public function test_save_questions_for_serie_bm_invalidates_only_bm_cache(): void
    {
        Storage::fake('local');
        $keyA = $this->primeSentinelCache('A', 'stale_A');
        $keyBm = $this->primeSentinelCache('BM', 'stale_BM');

        $service = app(FunctionalRuleService::class);
        $service->saveQuestions('BM18SIM', 'C', [
            ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'question' => 'Sin datos', 'response' => 'puede_quedar_vacio', 'pattern_id' => 1],
        ], 'BM');

        $this->assertFalse(Cache::has($keyBm), 'GAP CERRADO: guardar BM invalida su propia clave -- ya NO depende de invalidar la de Serie A.');
        $this->assertTrue(Cache::has($keyA), 'Serie A no debe verse afectada por un guardado de BM.');
        $this->assertSame('stale_A', Cache::get($keyA));
    }

    public function test_save_questions_default_serie_parameter_preserves_legacy_a_behavior(): void
    {
        // Llamada posicional SIN el 4to parametro -- exactamente como la
        // invocan todos los tests/llamadores preexistentes a esta fase.
        // Confirma que el default string $serie='A' preserva el
        // comportamiento legado sin exigir que ningun caller antiguo se
        // actualice.
        Storage::fake('local');
        $keyA = $this->primeSentinelCache('A', 'stale_A');

        $service = app(FunctionalRuleService::class);
        $service->saveQuestions('TEST18', 'Z', [
            ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'question' => 'Sin datos', 'response' => 'debe_registrar_cero', 'pattern_id' => 1],
        ]);

        $this->assertFalse(Cache::has($keyA));
    }

    // ── saveFunctionalRuleByRow()/clearFunctionalRuleByRow(): antes no
    // invalidaban NADA (gap distinto al de saveQuestions) ─────────────────

    public function test_save_functional_rule_by_row_invalidates_the_targeted_serie(): void
    {
        Storage::fake('local');
        $keyBm = $this->primeSentinelCache('BM', 'stale_BM');

        $service = app(FunctionalRuleService::class);
        $service->saveFunctionalRuleByRow('BM18SIM', 'C', 57, ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'], 'BM');

        $this->assertFalse(Cache::has($keyBm), 'GAP CERRADO: saveFunctionalRuleByRow() antes no invalidaba ningun cache.');
    }

    public function test_clear_functional_rule_by_row_invalidates_the_targeted_serie(): void
    {
        Storage::fake('local');
        $service = app(FunctionalRuleService::class);
        $service->saveFunctionalRuleByRow('BM18SIM', 'C', 57, ['empty_behavior' => 'puede_quedar_vacio', 'status' => 'aprobada'], 'BM');

        $keyBm = $this->primeSentinelCache('BM', 'stale_BM_2');

        $service->clearFunctionalRuleByRow('BM18SIM', 'C', 57, ['updated_by' => 'Test'], 'BM');

        $this->assertFalse(Cache::has($keyBm), 'GAP CERRADO: clearFunctionalRuleByRow() antes no invalidaba ningun cache.');
    }

    // ── Regresion concreta de BM-11.11: tras un guardado BM, la lectura
    // normal (buildStructureCalibrationSummary) ya NO devuelve un valor
    // cacheado obsoleto -- fuerza un recalculo real. ──────────────────────

    public function test_after_bm_save_summary_read_is_recomputed_not_stale_cached_value(): void
    {
        Storage::fake('local');

        $matrixService = app(SectionCalibrationMatrixService::class);
        $sentinel = ['totals' => ['sections_completed' => 999999], '_marker' => 'STALE_SENTINEL'];
        Cache::put(SectionCalibrationMatrixService::calibrationSummaryCacheKey('BM'), $sentinel, 3600);

        // Confirma que, ANTES del guardado, la lectura normal devuelve el
        // sentinel (para no falsear el test si Cache::put() no aplicara).
        $beforeSave = $matrixService->buildStructureCalibrationSummary('BM');
        $this->assertSame('STALE_SENTINEL', $beforeSave['_marker'] ?? null);

        $functionalRuleService = app(FunctionalRuleService::class);
        $functionalRuleService->saveQuestions('BM18SIM', 'C', [
            ['id' => 'patron_1_empty', 'type' => 'pattern_question', 'question' => 'Sin datos', 'response' => 'puede_quedar_vacio', 'pattern_id' => 1],
        ], 'BM');

        $afterSave = $matrixService->buildStructureCalibrationSummary('BM');
        $this->assertArrayNotHasKey('_marker', $afterSave, 'GAP CERRADO: tras el guardado BM, el resumen ya NO es el valor cacheado obsoleto -- fue recalculado.');
    }
}
