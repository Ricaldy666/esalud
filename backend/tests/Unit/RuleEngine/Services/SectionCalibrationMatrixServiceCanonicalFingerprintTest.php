<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::computeCanonicalPatternFingerprint()
 * -- la firma canonica v2 (deuda tecnica #1, Fase 1, 2026-08-12) que
 * incorpora filas + columnas funcionales + formulas normalizadas +
 * editabilidad + mode, a diferencia de computeRowFingerprint() (v1, solo
 * filas). Prueba pura (sin DB, sin disco).
 */
class SectionCalibrationMatrixServiceCanonicalFingerprintTest extends TestCase
{
    private function service(): SectionCalibrationMatrixService
    {
        return new SectionCalibrationMatrixService(
            new CertificationService(),
            new FunctionalRuleService(),
            new CellDataStorageService(),
            new ColumnRoleResolverService(),
        );
    }

    private function baseline(SectionCalibrationMatrixService $svc): string
    {
        return $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );
    }

    public function test_identical_rows_columns_formulas_editability_produce_identical_fingerprint(): void
    {
        $svc = $this->service();

        $a = $this->baseline($svc);
        $b = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );

        $this->assertSame($a, $b);
    }

    public function test_added_column_produces_different_fingerprint(): void
    {
        $svc = $this->service();

        $base = $this->baseline($svc);
        $conColumnaNueva = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E', 'F'],
            formulaTemplates: ['C' => '=SUM(D{fila}:F{fila})'],
            editabilitySignature: 'D:E|E:E|F:E|C:B',
            mode: 'formula',
        );

        $this->assertNotSame($base, $conColumnaNueva);
    }

    public function test_removed_column_produces_different_fingerprint(): void
    {
        $svc = $this->service();

        $base = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E', 'F'],
            formulaTemplates: ['C' => '=SUM(D{fila}:F{fila})'],
            editabilitySignature: 'D:E|E:E|F:E|C:B',
            mode: 'formula',
        );
        $sinColumna = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );

        $this->assertNotSame($base, $sinColumna);
    }

    public function test_changed_editability_produces_different_fingerprint(): void
    {
        $svc = $this->service();

        $base = $this->baseline($svc);
        $conEditabilidadDistinta = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:B|E:E|C:B', // D paso de editable a bloqueada
            mode: 'formula',
        );

        $this->assertNotSame($base, $conEditabilidadDistinta);
    }

    public function test_changed_formula_produces_different_fingerprint(): void
    {
        $svc = $this->service();

        $base = $this->baseline($svc);
        $conFormulaDistinta = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=D{fila}+E{fila}'], // misma columnas, formula distinta
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );

        $this->assertNotSame($base, $conFormulaDistinta);
    }

    public function test_changed_rows_produce_different_fingerprint(): void
    {
        $svc = $this->service();

        $base = $this->baseline($svc);
        $conFilasDistintas = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 15], // 14 -> 15
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );

        $this->assertNotSame($base, $conFilasDistintas);
    }

    public function test_changed_mode_produces_different_fingerprint(): void
    {
        $svc = $this->service();

        $base = $this->baseline($svc);
        $conModoDistinto = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'direct_input',
        );

        $this->assertNotSame($base, $conModoDistinto);
    }

    public function test_non_semantic_array_order_never_changes_the_fingerprint(): void
    {
        $svc = $this->service();

        $a = $svc->computeCanonicalPatternFingerprint(
            filas: [14, 12, 13],
            totalColumns: ['C', 'B'],
            originColumns: ['E', 'D'],
            formulaTemplates: ['B' => '=D{fila}', 'C' => '=E{fila}'],
            editabilitySignature: 'D:E|E:E|B:B|C:B',
            mode: 'formula',
        );
        $b = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13, 14],
            totalColumns: ['B', 'C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=E{fila}', 'B' => '=D{fila}'],
            editabilitySignature: 'D:E|E:E|B:B|C:B',
            mode: 'formula',
        );

        $this->assertSame($a, $b);
    }

    public function test_column_letter_casing_never_changes_the_fingerprint(): void
    {
        $svc = $this->service();

        $mayusculas = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );
        $minusculas = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 13],
            totalColumns: ['c'],
            originColumns: ['d', 'e'],
            formulaTemplates: ['c' => '=sum(d{fila}:e{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'FORMULA',
        );

        $this->assertSame($mayusculas, $minusculas);
    }

    public function test_duplicated_rows_do_not_change_the_fingerprint(): void
    {
        $svc = $this->service();

        $sinDuplicados = $this->baseline($svc);
        $conDuplicados = $svc->computeCanonicalPatternFingerprint(
            filas: [12, 12, 13, 14, 14, 14],
            totalColumns: ['C'],
            originColumns: ['D', 'E'],
            formulaTemplates: ['C' => '=SUM(D{fila}:E{fila})'],
            editabilitySignature: 'D:E|E:E|C:B',
            mode: 'formula',
        );

        $this->assertSame($sinDuplicados, $conDuplicados);
    }

    public function test_empty_row_set_returns_reserved_fingerprint(): void
    {
        $svc = $this->service();

        $vacio = $svc->computeCanonicalPatternFingerprint(
            filas: [],
            totalColumns: ['C'],
            originColumns: ['D'],
            formulaTemplates: [],
            editabilitySignature: '',
            mode: 'formula',
        );

        $this->assertSame('fpv2_empty', $vacio);
    }

    public function test_fingerprint_format_is_stable_and_prefixed_differently_from_v1(): void
    {
        $svc = $this->service();

        $fp = $this->baseline($svc);

        $this->assertStringStartsWith('fpv2_', $fp);
        $this->assertSame(5 + 16, strlen($fp), 'prefijo "fpv2_" (5) + 16 hex chars de sha256 truncado');

        // v1 y v2 nunca deben poder confundirse por prefijo compartido.
        $v1 = $svc->computeRowFingerprint([12, 13, 14]);
        $this->assertStringStartsWith('rowset_', $v1);
        $this->assertNotSame($fp, $v1);
    }

    public function test_fingerprint_is_deterministic_across_calls(): void
    {
        $svc = $this->service();

        $first = $this->baseline($svc);
        $second = $this->baseline($svc);

        $this->assertSame($first, $second);
    }
}
