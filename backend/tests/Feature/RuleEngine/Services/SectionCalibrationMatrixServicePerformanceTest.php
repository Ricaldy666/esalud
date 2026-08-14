<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regresion de rendimiento (2026-08-14): producción reportaba
 * `Maximum execution time of 30 seconds exceeded` en
 * SectionCalibrationMatrixService.php:2130 (usort dentro de
 * getFunctionalColumns()) y :2330/2331 (columnNumber()) al calcular
 * buildStructureCalibrationSummary() contra la estructura activa real
 * (304 secciones aplicables, estructura v32).
 *
 * Causa raiz medida (benchmark manual contra la estructura local real,
 * no reproducido aqui por ser demasiado pesado para CI): tres puntos
 * llamaban a getFunctionalColumns($sectionData, $cellDataRows) -- que
 * es invariante por fila, depende solo de la seccion completa -- desde
 * DENTRO de un loop por fila, recalculandolo (con su propio usort()) en
 * cada iteracion:
 *   1. buildFunctionalRulesForMatrixRow(), llamado por cada fila desde
 *      el loop principal de buildMatrix().
 *   2. getFormulaTotalColumnsFromCellData(), que tenia su PROPIO loop
 *      por fila llamando a getFunctionalColumns() en cada iteracion
 *      (con la seccion COMPLETA, no la fila) -- O(filas^2) por si sola,
 *      y llamada 3 veces por seccion (buildMatrix/buildColumnGroups/
 *      buildDynamicPatternDefinitions).
 *   3. getEditableInputColumnsForRow(), llamado por cada fila sin
 *      columna-total activa desde buildDynamicPatternDefinitions().
 *
 * Medido contra la estructura activa real (v32, 379 secciones, 304
 * aplicables): 58.8s / columnNumber() llamado 288.093.566 veces antes
 * del fix -> 7.3s / 15.456.650 llamadas despues. Salida JSON
 * byte-identica (diff vacio, mismo SHA-256) antes/despues.
 *
 * Estos tests NO repiten ese benchmark a escala real (demasiado lento
 * para CI). Cubren en cambio: (a) equivalencia exacta entre pasar el
 * resultado precalculado de getFunctionalColumns() y dejar que cada
 * metodo lo recalcule por su cuenta (el argumento que hace seguro el
 * fix), y (b) una cota de tiempo generosa sobre una seccion sintetica
 * de 150 filas que un regreso al comportamiento O(filas^2) rompería con
 * amplio margen.
 */
class SectionCalibrationMatrixServicePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
    }

    private function dummyFields(array $letras, array $esTotal = []): array
    {
        return array_map(fn (string $letra) => [
            'letra' => $letra,
            'label' => "Campo {$letra}",
            'esTotal' => in_array($letra, $esTotal, true),
            'esControlOculto' => false,
            'reglaDetectada' => null,
        ], $letras);
    }

    /**
     * Seccion sintetica de $rows filas. Cada fila tiene A/B (etiqueta,
     * bloqueadas), C (total, formula "=D{fila}+E{fila}") y D/E (entradas
     * editables). Una de cada 5 filas omite la formula de C -- fuerza el
     * camino de "columna directa editable" (getEditableInputColumnsForRow),
     * el resto ejercita getFormulaTotalColumnsFromCellData()/
     * buildFunctionalRulesForMatrixRow().
     */
    private function buildManyRowSection(CellDataStorageService $cellData, string $sheet, string $section, int $rows): array
    {
        $filaInicio = 10;
        $filaFin = $filaInicio + $rows - 1;

        // Fila de encabezado (filaHeader=9): labelForColumn() la usa para
        // resolver la etiqueta de C a "TOTAL", requisito de
        // isFunctionalHorizontalFormula() para reconocer C como columna de
        // total real -- sin esto todas las filas caerian en el camino de
        // entrada directa (igualmente valido para medir tiempo, pero no
        // ejercitaria getFormulaTotalColumnsFromCellData() con datos reales).
        $cells = [
            'A9' => ['valor_bruto' => 'Concepto', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
            'C9' => ['valor_bruto' => 'TOTAL', 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null],
        ];
        for ($row = $filaInicio; $row <= $filaFin; $row++) {
            $hasTotal = ($row % 5) !== 0;

            $cells["A{$row}"] = ['valor_bruto' => "Concepto {$row}", 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
            $cells["B{$row}"] = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
            $cells["C{$row}"] = $hasTotal
                ? [
                    'valor_bruto' => 0,
                    'es_editable' => false,
                    'esta_bloqueada' => true,
                    'es_formula' => true,
                    'formula' => "=D{$row}+E{$row}",
                    'dependencias' => ["D{$row}", "E{$row}"],
                ]
                : ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
            $cells["D{$row}"] = ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
            $cells["E{$row}"] = ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
        }

        $cellData->saveCellData($sheet, $section, $cells);

        return [
            'codigo' => $section,
            'titulo' => "SECCION {$section} DE PRUEBA",
            'filaHeader' => 9,
            'filaInicioDatos' => $filaInicio,
            'filaFinDatos' => $filaFin,
            'fields' => $this->dummyFields(['A', 'B', 'C', 'D', 'E'], esTotal: ['C']),
        ];
    }

    private function createStructureWithSection(array $sectionData, string $sheet = 'PERFTEST'): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-perf-test-' . uniqid(),
            'estructura' => [
                'forms' => [
                    ['sheetName' => $sheet, 'sections' => [$sectionData]],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);
    }

    /**
     * Cota de tiempo generosa (muy por debajo de lo que tomaria un
     * O(filas^2) reintroducido): 150 filas x 150 = 22.500 "unidades" de
     * trabajo si el bug volviera, contra 150 si se mantiene lineal. 5s
     * es holgado para hardware de CI lento en el camino optimizado (que
     * en local toma decenas de ms), pero un regreso al bug haria esta
     * seccion sola tardar muchos segundos (proporcionalmente al costo
     * medido de 58.8s/304 secciones con ~18 filas promedio).
     */
    public function test_many_row_section_builds_within_time_bound(): void
    {
        $cellData = app(CellDataStorageService::class);
        $sectionData = $this->buildManyRowSection($cellData, 'PERFTEST', 'BIG', 150);
        $this->createStructureWithSection($sectionData, 'PERFTEST');

        $service = app(SectionCalibrationMatrixService::class);

        $start = microtime(true);
        $matrix = $service->buildPatternMatrix('PERFTEST', 'BIG');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'buildPatternMatrix() para 150 filas no deberia acercarse a este tiempo salvo que el bug O(filas^2) haya vuelto');
        $this->assertSame('ok', $matrix['section']['status']);
        $this->assertCount(150, $matrix['rows']);

        // Correccion funcional basica: filas con formula de total agrupan en
        // un patron "formula", las filas sin formula (multiplos de 5) en uno
        // "direct_input" con D/E como columnas de origen.
        $formulaPatterns = array_filter($matrix['patterns'], fn ($p) => ($p['mode'] ?? '') === 'formula');
        $directPatterns = array_filter($matrix['patterns'], fn ($p) => ($p['mode'] ?? '') === 'direct_input');
        $this->assertNotEmpty($formulaPatterns);
        $this->assertNotEmpty($directPatterns);

        $totalPatternRows = array_sum(array_map(fn ($p) => count($p['filas']), $matrix['patterns']));
        $this->assertSame(150, $totalPatternRows, 'cada una de las 150 filas debe caer en exactamente un patron');
    }

    /**
     * Prueba directamente el argumento de equivalencia que hace seguro el
     * fix de getEditableInputColumnsForRow(): pasar el resultado
     * precalculado section-wide de getFunctionalColumns() debe dar
     * EXACTAMENTE el mismo resultado que dejar que el metodo lo recalcule
     * el solo con [$rowCells] (comportamiento previo al fix, todavia
     * disponible via el default null del parametro).
     */
    public function test_editable_input_columns_equivalence_with_precomputed_functional_columns(): void
    {
        $cellData = app(CellDataStorageService::class);
        $sectionData = $this->buildManyRowSection($cellData, 'PERFTEST', 'EQUIV', 30);
        $this->createStructureWithSection($sectionData, 'PERFTEST');

        $service = app(SectionCalibrationMatrixService::class);
        $allCellData = $cellData->getAllCellData('PERFTEST', 'EQUIV');
        $cellDataRows = [];
        foreach ($allCellData as $coord => $cd) {
            preg_match('/^([A-Z]+)(\d+)$/', $coord, $m);
            $cellDataRows[(int) $m[2]][$m[1]] = $cd;
        }

        $reflection = new ReflectionClass($service);
        $getFunctionalColumns = $reflection->getMethod('getFunctionalColumns');
        $getFunctionalColumns->setAccessible(true);
        $getEditableInputColumnsForRow = $reflection->getMethod('getEditableInputColumnsForRow');
        $getEditableInputColumnsForRow->setAccessible(true);
        $detectLabelColumns = $reflection->getMethod('detectLabelColumns');
        $detectLabelColumns->setAccessible(true);

        // detectLabelColumns() setea $this->currentLabelColumns -- se invoca
        // normalmente dentro de buildMatrix(); aqui se llama a mano porque el
        // test ejercita getEditableInputColumnsForRow() de forma aislada.
        $detectLabelColumns->invoke($service, $sectionData, $cellDataRows, 10, 39, 9);

        $precomputed = $getFunctionalColumns->invoke($service, $sectionData, $cellDataRows);

        foreach ($cellDataRows as $row => $rowCells) {
            $withPrecomputed = $getEditableInputColumnsForRow->invoke($service, $sectionData, $rowCells, $precomputed);
            $withoutPrecomputed = $getEditableInputColumnsForRow->invoke($service, $sectionData, $rowCells, null);

            $this->assertSame(
                $withoutPrecomputed,
                $withPrecomputed,
                "fila {$row}: pasar functionalColumns precalculado debe dar el mismo resultado que recalcularlo por fila"
            );
        }
    }

    /**
     * Mismo argumento de equivalencia para buildFunctionalRulesForMatrixRow():
     * pasar la lista precalculada de columnas funcionales no debe cambiar el
     * resultado frente a dejar que el metodo la recalcule internamente.
     */
    public function test_functional_rules_for_matrix_row_equivalence_with_precomputed_functional_columns(): void
    {
        $cellData = app(CellDataStorageService::class);
        $sectionData = $this->buildManyRowSection($cellData, 'PERFTEST', 'EQUIV2', 30);
        $this->createStructureWithSection($sectionData, 'PERFTEST');

        $service = app(SectionCalibrationMatrixService::class);
        $allCellData = $cellData->getAllCellData('PERFTEST', 'EQUIV2');
        $cellDataRows = [];
        foreach ($allCellData as $coord => $cd) {
            preg_match('/^([A-Z]+)(\d+)$/', $coord, $m);
            $cellDataRows[(int) $m[2]][$m[1]] = $cd;
        }

        $reflection = new ReflectionClass($service);
        $getFunctionalColumns = $reflection->getMethod('getFunctionalColumns');
        $getFunctionalColumns->setAccessible(true);
        $buildFunctionalRulesForMatrixRow = $reflection->getMethod('buildFunctionalRulesForMatrixRow');
        $buildFunctionalRulesForMatrixRow->setAccessible(true);
        $detectLabelColumns = $reflection->getMethod('detectLabelColumns');
        $detectLabelColumns->setAccessible(true);

        $detectLabelColumns->invoke($service, $sectionData, $cellDataRows, 10, 39, 9);
        $precomputed = $getFunctionalColumns->invoke($service, $sectionData, $cellDataRows);

        foreach ($cellDataRows as $row => $rowCells) {
            $withPrecomputed = $buildFunctionalRulesForMatrixRow->invoke($service, $row, $rowCells, $sectionData, $cellDataRows, $precomputed);
            $withoutPrecomputed = $buildFunctionalRulesForMatrixRow->invoke($service, $row, $rowCells, $sectionData, $cellDataRows, null);

            $this->assertSame(
                $withoutPrecomputed,
                $withPrecomputed,
                "fila {$row}: pasar functionalColumns precalculado debe dar el mismo resultado que recalcularlo por fila"
            );
        }
    }

    /**
     * columnNumber() memoizado debe seguir devolviendo el mismo valor que la
     * conversion de letra de columna a numero de siempre (A=1, Z=26, AA=27,
     * ...), para las mismas letras repetidas dentro de una instancia -- el
     * unico cambio es que no vuelve a parsear el string dos veces.
     */
    public function test_column_number_memoization_returns_consistent_values(): void
    {
        $service = app(SectionCalibrationMatrixService::class);
        $reflection = new ReflectionClass($service);
        $columnNumber = $reflection->getMethod('columnNumber');
        $columnNumber->setAccessible(true);

        $cases = ['A' => 1, 'B' => 2, 'Z' => 26, 'AA' => 27, 'AB' => 28, 'AZ' => 52, 'BA' => 53];
        foreach ($cases as $letter => $expected) {
            // Se invoca dos veces a proposito: la primera puebla el cache de
            // instancia, la segunda debe leerlo -- ambas deben coincidir.
            $this->assertSame($expected, $columnNumber->invoke($service, $letter));
            $this->assertSame($expected, $columnNumber->invoke($service, $letter));
        }
    }
}
