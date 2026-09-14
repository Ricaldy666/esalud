<?php

namespace Tests\Unit\RuleEngine\Evaluators;

use App\Domain\RemParser\Services\EnhancedCellScanner;
use App\Domain\RuleEngine\Evaluators\CrossSheetEqualsEvaluator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * REM BM -- FASE BM-2.6B (2026-09-11): confirma el pipeline completo
 * (parseo de dependencia cross-hoja -> representacion estructurada ->
 * config de regla -> evaluacion) contra las 2 relaciones reales BM18/BM18A
 * nombradas explicitamente en el encargo, tomadas de la inspeccion real
 * del XLSM ya realizada en BM-2.5 (102302BM05.xlsm, verificada identica en
 * 102412BM05.xlsm):
 *
 *   BM18!E14 = "=BM18A!D20"                     (referencia directa)
 *   BM18!E22 = "=SUM(BM18A!D92:D113)"            (rango)
 *
 * calc_value real confirmado en ambos XLSM para ambas celdas: 0 (formulario
 * de muestra sin datos cargados) -- se usa como valor esperado real, no
 * inventado.
 *
 * 100% en memoria: NO se lee ningun XLSM en este test (las formulas se
 * citan literalmente, ya verificadas reales en BM-2.5), NO se crea
 * estructura/regla/binding de serie BM, NO hay conexion a base de datos
 * (PHPUnit\Framework\TestCase puro, sin RefreshDatabase).
 *
 * "BM18"/"BM18A" aparecen aqui EXCLUSIVAMENTE como datos de prueba (los
 * nombres reales de las hojas) -- ni EnhancedCellScanner ni
 * CrossSheetEqualsEvaluator contienen ninguna referencia a BM en su
 * codigo (confirmado por lectura de ambas clases: son 100% genericas para
 * cualquier nombre de hoja/serie).
 */
class CrossSheetEqualsEvaluatorBmRealFormulasTest extends TestCase
{
    private function extractCrossSheetDependencies(string $formula): array
    {
        $scanner = new EnhancedCellScanner();
        $refl = new ReflectionClass($scanner);
        $method = $refl->getMethod('extractCrossSheetDependencies');
        $method->setAccessible(true);

        return $method->invoke($scanner, $formula);
    }

    private function makeRow(int $rowNumber, array $values): object
    {
        return (object) [
            'id' => $rowNumber,
            'data' => ['values' => $values, 'row_number' => $rowNumber, 'concept' => 'BM test'],
        ];
    }

    /**
     * Construye un config de cross_sheet_equals a partir de la
     * representacion estructurada ya producida por
     * EnhancedCellScanner::extractCrossSheetDependencies() -- exactamente
     * lo que una futura fase (fuera de alcance de BM-2.6B, ver "NO crear
     * reglas BM persistentes") haria para generar la regla real. Cero
     * hardcode de nombres de hoja: todo viene del array de dependencia ya
     * parseado.
     */
    private function buildConfigFromCrossSheetDependency(
        string $sourceSheet,
        string $sourceSection,
        string $sourceCell,
        array $crossSheetDependency,
    ): array {
        $target = ['sheet' => $crossSheetDependency['hoja']];

        if ($crossSheetDependency['tipo'] === 'celda') {
            $target['cell'] = $crossSheetDependency['celda'];
        } else {
            $target['range'] = $crossSheetDependency['celda_inicio'] . ':' . $crossSheetDependency['celda_fin'];
            $target['aggregation'] = 'sum';
        }

        return [
            '_rule_key' => "bm_real_{$sourceSheet}_{$sourceCell}",
            'sheet' => $sourceSheet,
            'section' => $sourceSection,
            'source' => ['cell' => $sourceCell],
            'target' => $target,
        ];
    }

    // ── BM18!E14 = BM18A!D20 (referencia directa) ────────────────────

    public function test_bm18_e14_equals_bm18a_d20_direct_reference(): void
    {
        $formula = '=BM18A!D20';
        $crossSheetDeps = $this->extractCrossSheetDependencies($formula);

        $this->assertCount(1, $crossSheetDeps, 'BM18!E14 debe producir exactamente 1 dependencia cross-hoja');
        $this->assertSame('BM18A', $crossSheetDeps[0]['hoja']);
        $this->assertSame('celda', $crossSheetDeps[0]['tipo']);
        $this->assertSame('D20', $crossSheetDeps[0]['celda']);

        $config = $this->buildConfigFromCrossSheetDependency('BM18', 'A', 'E14', $crossSheetDeps[0]);

        $this->assertSame([
            '_rule_key' => 'bm_real_BM18_E14',
            'sheet' => 'BM18',
            'section' => 'A',
            'source' => ['cell' => 'E14'],
            'target' => ['sheet' => 'BM18A', 'cell' => 'D20'],
        ], $config);

        // calc_value real confirmado en 102302BM05.xlsm y 102412BM05.xlsm
        // (BM-2.5): 0 en ambos archivos de muestra para esta celda.
        $sourceRows = new Collection([$this->makeRow(14, ['E' => 0])]);
        $config['_target_rows'] = new Collection([$this->makeRow(20, ['D' => 0])]);

        $result = (new CrossSheetEqualsEvaluator())->evaluate($config, $sourceRows);

        $this->assertSame(0, $result->failedRows, 'BM18!E14 (0) debe coincidir con BM18A!D20 (0), valores reales del XLSM');
        $this->assertSame(1, $result->totalRows);

        // Regresion explicita del hallazgo de BM-2.5: nunca "BM18" como
        // dependencia local.
        $this->assertNotContains('BM18', array_column($crossSheetDeps, 'hoja'), 'hoja debe ser BM18A completo, nunca truncado a BM18');
    }

    public function test_bm18_e14_mismatch_detected_if_values_diverge(): void
    {
        // Variante negativa (no observada en los XLSM reales, pero
        // requerida para probar que el evaluador realmente compara, no
        // solo que "pasa" con datos reales): si el valor capturado de
        // BM18!E14 divergiera del real BM18A!D20, debe fallar.
        $crossSheetDeps = $this->extractCrossSheetDependencies('=BM18A!D20');
        $config = $this->buildConfigFromCrossSheetDependency('BM18', 'A', 'E14', $crossSheetDeps[0]);

        $sourceRows = new Collection([$this->makeRow(14, ['E' => 5])]);
        $config['_target_rows'] = new Collection([$this->makeRow(20, ['D' => 0])]);

        $result = (new CrossSheetEqualsEvaluator())->evaluate($config, $sourceRows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame('cross_sheet_mismatch', $result->details[0]['reason']);
    }

    // ── BM18!E22 = SUM(BM18A!D92:D113) (rango) ───────────────────────

    public function test_bm18_e22_equals_sum_bm18a_d92_d113_range(): void
    {
        $formula = '=SUM(BM18A!D92:D113)';
        $crossSheetDeps = $this->extractCrossSheetDependencies($formula);

        $this->assertCount(1, $crossSheetDeps);
        $this->assertSame('BM18A', $crossSheetDeps[0]['hoja']);
        $this->assertSame('rango', $crossSheetDeps[0]['tipo']);
        $this->assertSame('D92', $crossSheetDeps[0]['celda_inicio']);
        $this->assertSame('D113', $crossSheetDeps[0]['celda_fin']);

        $config = $this->buildConfigFromCrossSheetDependency('BM18', 'A', 'E22', $crossSheetDeps[0]);

        $this->assertSame([
            '_rule_key' => 'bm_real_BM18_E22',
            'sheet' => 'BM18',
            'section' => 'A',
            'source' => ['cell' => 'E22'],
            'target' => ['sheet' => 'BM18A', 'range' => 'D92:D113', 'aggregation' => 'sum'],
        ], $config);

        // calc_value real confirmado en ambos XLSM: 0. Se simula el rango
        // completo D92:D113 (22 filas) con valor 0 cada una -- suma = 0,
        // igual al calc_value real de E22.
        $sourceRows = new Collection([$this->makeRow(22, ['E' => 0])]);
        $targetRows = [];
        for ($row = 92; $row <= 113; $row++) {
            $targetRows[] = $this->makeRow($row, ['D' => 0]);
        }
        $config['_target_rows'] = new Collection($targetRows);

        $result = (new CrossSheetEqualsEvaluator())->evaluate($config, $sourceRows);

        $this->assertSame(0, $result->failedRows, 'BM18!E22 (0) debe coincidir con SUM(BM18A!D92:D113) = 0, valores reales del XLSM');
        $this->assertSame(1, $result->totalRows);
    }

    public function test_bm18_e22_range_sum_mismatch_detected(): void
    {
        $crossSheetDeps = $this->extractCrossSheetDependencies('=SUM(BM18A!D92:D113)');
        $config = $this->buildConfigFromCrossSheetDependency('BM18', 'A', 'E22', $crossSheetDeps[0]);

        $sourceRows = new Collection([$this->makeRow(22, ['E' => 100])]);
        $config['_target_rows'] = new Collection([
            $this->makeRow(92, ['D' => 10]),
            $this->makeRow(93, ['D' => 20]),
        ]);

        $result = (new CrossSheetEqualsEvaluator())->evaluate($config, $sourceRows);

        $this->assertSame(1, $result->failedRows);
        $this->assertSame(30.0, $result->details[0]['target_value']);
        $this->assertSame(100.0, $result->details[0]['source_value']);
    }
}
