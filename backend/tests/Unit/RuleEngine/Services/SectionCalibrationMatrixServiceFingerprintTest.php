<?php

namespace Tests\Unit\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use PHPUnit\Framework\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::computeRowFingerprint() -- la
 * huella estable por conjunto de filas que reemplaza a pattern_id como
 * identidad de un patron. Prueba pura (sin DB, sin disco), ya que la
 * funcion no toca ninguna dependencia inyectada.
 */
class SectionCalibrationMatrixServiceFingerprintTest extends TestCase
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

    public function test_same_rows_produce_same_fingerprint_regardless_of_input_order(): void
    {
        $svc = $this->service();

        $a = $svc->computeRowFingerprint([91, 92, 93, 142]);
        $b = $svc->computeRowFingerprint([142, 91, 93, 92]);

        $this->assertSame($a, $b);
    }

    public function test_different_rows_produce_different_fingerprint(): void
    {
        $svc = $this->service();

        $a = $svc->computeRowFingerprint([91, 92, 93]);
        $b = $svc->computeRowFingerprint([91, 92, 94]);

        $this->assertNotSame($a, $b);
    }

    public function test_subset_of_rows_produces_different_fingerprint_than_full_set(): void
    {
        // Caso real: patron dividido -- el fragmento no debe compartir huella con el original.
        $svc = $this->service();

        $original = $svc->computeRowFingerprint([91, 92, 93, 94, 95, 116, 138, 139, 141, 142]);
        $fragmento = $svc->computeRowFingerprint([116, 138, 139, 141, 142]);

        $this->assertNotSame($original, $fragmento);
    }

    public function test_duplicated_rows_do_not_change_the_fingerprint(): void
    {
        $svc = $this->service();

        $sinDuplicados = $svc->computeRowFingerprint([91, 92, 93]);
        $conDuplicados = $svc->computeRowFingerprint([91, 92, 92, 93, 93, 93]);

        $this->assertSame($sinDuplicados, $conDuplicados);
    }

    public function test_invalid_or_zero_or_negative_rows_are_filtered_out(): void
    {
        $svc = $this->service();

        $limpio = $svc->computeRowFingerprint([91, 92, 93]);
        $conBasura = $svc->computeRowFingerprint([0, -5, 91, 92, 93]);

        $this->assertSame($limpio, $conBasura);
    }

    public function test_empty_row_set_returns_reserved_fingerprint(): void
    {
        $svc = $this->service();

        $this->assertSame('fp_empty', $svc->computeRowFingerprint([]));
        $this->assertSame('fp_empty', $svc->computeRowFingerprint([0, -1, -99]));
    }

    public function test_two_distinct_empty_patterns_never_count_as_matching_via_normal_comparison(): void
    {
        // fp_empty es identico entre si por diseno (mismo string), pero el
        // llamador debe tratarlo como "sin huella real" -- documentado aqui
        // para que quede explicito que la funcion NO distingue "vacio A" de
        // "vacio B"; esa responsabilidad es de quien la consume (nunca
        // reconciliar automaticamente cuando la huella es fp_empty).
        $svc = $this->service();

        $vacioA = $svc->computeRowFingerprint([]);
        $vacioB = $svc->computeRowFingerprint([0]);

        $this->assertSame($vacioA, $vacioB);
        $this->assertSame('fp_empty', $vacioA);
    }

    public function test_fingerprint_format_is_stable_and_prefixed(): void
    {
        $svc = $this->service();

        $fp = $svc->computeRowFingerprint([91, 92, 93]);

        $this->assertStringStartsWith('rowset_', $fp);
        $this->assertSame(7 + 16, strlen($fp), 'prefijo "rowset_" (7) + 16 hex chars de sha256 truncado');
    }

    public function test_fingerprint_is_deterministic_across_calls(): void
    {
        $svc = $this->service();

        $first = $svc->computeRowFingerprint([91, 106, 142]);
        $second = $svc->computeRowFingerprint([91, 106, 142]);

        $this->assertSame($first, $second);
    }

    public function test_string_numeric_rows_are_cast_and_matched_same_as_int_rows(): void
    {
        $svc = $this->service();

        $conInts = $svc->computeRowFingerprint([91, 92, 93]);
        $conStrings = $svc->computeRowFingerprint(['91', '92', '93']);

        $this->assertSame($conInts, $conStrings);
    }
}
