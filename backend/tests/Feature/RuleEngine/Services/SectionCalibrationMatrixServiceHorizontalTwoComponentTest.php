<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::isFunctionalHorizontalFormula() --
 * hallazgo A30/D (2026-08-21): 8 formulas horizontales reales y verificadas
 * en cell-data (R=B+J, S=C+K, ..., Y=I+Q -- pares "ambulatoria nueva" +
 * "ambulatoria control" por tramo etario/sexo) eran rechazadas porque su
 * columna total esta etiquetada "Hombres"/"Mujeres", lo que las hacia caer
 * en la rama que exige mas de 2 subcategorias del mismo sexo -- un par de
 * exactamente 2 componentes nunca podia superar esa condicion,
 * independientemente de la evidencia real en cell-data.
 *
 * Fix: para exactamente 2 componentes, se acepta la formula por evidencia
 * directa de cell-data (formula real en el total, dependencias exactamente
 * iguales a los 2 componentes declarados, componentes editables/no-formula)
 * via hasEditableInputComponentsForFormula() -- sin mirar el texto de la
 * etiqueta. No se modifico ninguna de las ramas >2 existentes.
 */
class SectionCalibrationMatrixServiceHorizontalTwoComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): SectionCalibrationMatrixService
    {
        return new SectionCalibrationMatrixService(
            new CertificationService(),
            new FunctionalRuleService(),
            new CellDataStorageService(),
        );
    }

    private function isFunctional(string $totalColumn, array $components, array $sectionData, array $cellDataRows): bool
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'isFunctionalHorizontalFormula');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $totalColumn, $components, $sectionData, $cellDataRows);
    }

    private function fieldsWithLabels(array $labelsByLetter): array
    {
        $fields = [];
        foreach ($labelsByLetter as $letra => $label) {
            $fields[] = ['letra' => $letra, 'label' => $label, 'esTotal' => false, 'esControlOculto' => false];
        }

        return $fields;
    }

    private function editableCell(): array
    {
        return ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
    }

    private function formulaCell(string $formula, array $dependencias): array
    {
        return ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => $formula, 'dependencias' => $dependencias];
    }

    /**
     * Caso real A30/D: R = B99 + J99, B y J no adyacentes a R, total
     * etiquetado "Hombres". Antes del fix, count(componentes)=2 nunca
     * superaba el ">2" exigido por la rama "hombres" -- rechazado siempre.
     */
    public function test_two_component_same_sex_non_adjacent_formula_is_accepted(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'B' => 'Nueva / 0 - 14 años / Hombres',
            'J' => 'Control / 0 - 14 años / Hombres',
            'R' => '0 - 14 años - Hombres',
        ])];
        $cellDataRows = [99 => [
            'B' => $this->editableCell(),
            'J' => $this->editableCell(),
            'R' => $this->formulaCell('=+B99+J99', ['B99', 'J99']),
        ]];

        $this->assertTrue($this->isFunctional('R', ['B', 'J'], $sectionData, $cellDataRows));
    }

    public function test_two_component_same_sex_mujeres_non_adjacent_formula_is_accepted(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'C' => 'Nueva / 0 - 14 años / Mujeres',
            'K' => 'Control / 0 - 14 años / Mujeres',
            'S' => '0 - 14 años - Mujeres',
        ])];
        $cellDataRows = [99 => [
            'C' => $this->editableCell(),
            'K' => $this->editableCell(),
            'S' => $this->formulaCell('=+C99+K99', ['C99', 'K99']),
        ]];

        $this->assertTrue($this->isFunctional('S', ['C', 'K'], $sectionData, $cellDataRows));
    }

    /**
     * Caso 2 componentes ADYACENTES ya cubierto por isSexMainRuleFormula()
     * (total seguido de Hombres/Mujeres en las 2 columnas siguientes) --
     * debe seguir aceptandose exactamente igual, sin verse afectado por el
     * nuevo bloque (que solo se evalua si isSexMainRuleFormula() ya
     * devolvio false).
     */
    public function test_two_component_adjacent_ambos_sexos_formula_still_accepted(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'A' => 'Ambos sexos',
            'B' => 'Hombres',
            'C' => 'Mujeres',
        ])];
        $cellDataRows = [50 => [
            'B' => $this->editableCell(),
            'C' => $this->editableCell(),
            'A' => $this->formulaCell('=+B50+C50', ['B50', 'C50']),
        ]];

        $this->assertTrue($this->isFunctional('A', ['B', 'C'], $sectionData, $cellDataRows));
    }

    /**
     * Formula del total con una dependencia EXTRA (3 columnas) que no
     * coincide exactamente con los 2 componentes declarados -- debe
     * rechazarse (hasEditableInputComponentsForFormula exige coincidencia
     * exacta, ordenada, de las dependencias).
     */
    public function test_formula_with_extra_dependency_is_rejected(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'B' => 'Nueva / Hombres',
            'J' => 'Control / Hombres',
            'Z' => 'Otra columna',
            'R' => '0 - 14 años - Hombres',
        ])];
        $cellDataRows = [99 => [
            'B' => $this->editableCell(),
            'J' => $this->editableCell(),
            'Z' => $this->editableCell(),
            // La formula real suma 3 columnas, no las 2 declaradas.
            'R' => $this->formulaCell('=+B99+J99+Z99', ['B99', 'J99', 'Z99']),
        ]];

        $this->assertFalse($this->isFunctional('R', ['B', 'J'], $sectionData, $cellDataRows));
    }

    /**
     * Uno de los 2 componentes declarados es en si mismo una formula (no un
     * dato de entrada editable real) -- debe rechazarse.
     */
    public function test_component_that_is_itself_a_formula_is_rejected(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'B' => 'Nueva / Hombres',
            'J' => 'Control / Hombres',
            'R' => '0 - 14 años - Hombres',
        ])];
        $cellDataRows = [99 => [
            'B' => $this->editableCell(),
            // J no es un dato de entrada real, es el resultado de otra formula.
            'J' => $this->formulaCell('=+X99+Y99', ['X99', 'Y99']),
            'R' => $this->formulaCell('=+B99+J99', ['B99', 'J99']),
        ]];

        $this->assertFalse($this->isFunctional('R', ['B', 'J'], $sectionData, $cellDataRows));
    }

    /**
     * Uno de los 2 componentes declarados esta bloqueado (no editable) --
     * tampoco es un dato de entrada real, debe rechazarse.
     */
    public function test_component_that_is_blocked_is_rejected(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'B' => 'Nueva / Hombres',
            'J' => 'Control / Hombres',
            'R' => '0 - 14 años - Hombres',
        ])];
        $blockedNonFormula = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
        $cellDataRows = [99 => [
            'B' => $this->editableCell(),
            'J' => $blockedNonFormula,
            'R' => $this->formulaCell('=+B99+J99', ['B99', 'J99']),
        ]];

        $this->assertFalse($this->isFunctional('R', ['B', 'J'], $sectionData, $cellDataRows));
    }

    /**
     * Caso historico >2 componentes del mismo sexo (rama existente, sin
     * modificar) -- debe seguir aceptandose exactamente igual que antes del
     * fix.
     */
    public function test_more_than_two_components_same_sex_label_still_accepted(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'B' => 'Categoria 1 Hombres',
            'C' => 'Categoria 2 Hombres',
            'D' => 'Categoria 3 Hombres',
            'E' => 'TOTAL Hombres',
        ])];
        $cellDataRows = [10 => [
            'B' => $this->editableCell(),
            'C' => $this->editableCell(),
            'D' => $this->editableCell(),
            'E' => $this->formulaCell('=+B10+C10+D10', ['B10', 'C10', 'D10']),
        ]];

        $this->assertTrue($this->isFunctional('E', ['B', 'C', 'D'], $sectionData, $cellDataRows));
    }

    /**
     * ACTUALIZADO 2026-08-21 (Fase 3, hallazgo A07/A -- ver
     * SectionDetectorService.../gate individual por fila): el diseño de esta
     * fase elimina las ramas basadas en cantidad de componentes y texto de
     * etiqueta -- la aceptacion pasa a depender EXCLUSIVAMENTE de evidencia
     * real (formula, dependencias exactas, componentes editables), nunca de
     * que las etiquetas de los componentes compartan un mismo sexo. Este caso
     * (3 componentes editables reales, con etiquetas mixtas Hombres/Mujeres)
     * ahora se ACEPTA correctamente -- la mezcla de etiquetas no invalida una
     * suma real y verificada. El caso que sí debe seguir rechazado (dependencia
     * que no corresponde al total) esta cubierto en
     * SectionCalibrationMatrixServiceRowLevelValidationGateTest.
     */
    public function test_more_than_two_components_mixed_sex_label_now_accepted_by_evidence(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels([
            'B' => 'Categoria 1 Hombres',
            'C' => 'Categoria 2 Mujeres',
            'D' => 'Categoria 3 Hombres',
            'E' => 'TOTAL Hombres',
        ])];
        $cellDataRows = [10 => [
            'B' => $this->editableCell(),
            'C' => $this->editableCell(),
            'D' => $this->editableCell(),
            'E' => $this->formulaCell('=+B10+C10+D10', ['B10', 'C10', 'D10']),
        ]];

        $this->assertTrue($this->isFunctional('E', ['B', 'C', 'D'], $sectionData, $cellDataRows));
    }

    /**
     * Extremo a extremo, via getPatternsForValidation(), con un fixture
     * equivalente a A30/D (mismo esquema de pares nueva+control -> total,
     * mismas letras B..Q origen / R..Y total): deben detectarse las 8
     * formulas reales, source=cell_data, un unico patron para las filas de
     * datos, sin caer a mode=direct_input.
     */
    public function test_a30d_equivalent_fixture_detects_eight_horizontal_formulas(): void
    {
        RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'hash_estructura' => 'test_hash_horizontal_2c',
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'status' => 'active',
            'source_filename' => 'test.xlsm',
        ]);

        $pairs = [
            'R' => ['B', 'J'], 'S' => ['C', 'K'], 'T' => ['D', 'L'], 'U' => ['E', 'M'],
            'V' => ['F', 'N'], 'W' => ['G', 'O'], 'X' => ['H', 'P'], 'Y' => ['I', 'Q'],
        ];
        $sexos = ['R' => 'Hombres', 'S' => 'Mujeres', 'T' => 'Hombres', 'U' => 'Mujeres', 'V' => 'Hombres', 'W' => 'Mujeres', 'X' => 'Hombres', 'Y' => 'Mujeres'];

        $labels = ['A' => 'Concepto'];
        foreach ($pairs as $total => [$nueva, $control]) {
            $labels[$nueva] = "Nueva / {$sexos[$total]}";
            $labels[$control] = "Control / {$sexos[$total]}";
            $labels[$total] = "Tramo etario - {$sexos[$total]}";
        }

        $sectionData = [
            'filaHeader' => 1,
            'filaInicioDatos' => 2,
            'filaFinDatos' => 3,
            'fields' => $this->fieldsWithLabels($labels),
        ];

        $cellDataRows = [];
        foreach ([2, 3] as $row) {
            $cellDataRows[$row]['A'] = ['valor_bruto' => "Concepto fila {$row}", 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
            foreach ($pairs as $total => [$nueva, $control]) {
                $cellDataRows[$row][$nueva] = $this->editableCell();
                $cellDataRows[$row][$control] = $this->editableCell();
                $cellDataRows[$row][$total] = $this->formulaCell("=+{$nueva}{$row}+{$control}{$row}", ["{$nueva}{$row}", "{$control}{$row}"]);
            }
        }

        // saveCellData espera coordenadas (letra+fila) como claves, no
        // letras sueltas agrupadas por fila -- se aplana el mapa aqui.
        $svc = app(CellDataStorageService::class);
        $flat = [];
        foreach ($cellDataRows as $row => $cols) {
            foreach ($cols as $col => $cell) {
                $flat["{$col}{$row}"] = $cell;
            }
        }
        $svc->saveCellData('HOJATEST', 'SECFIX', $flat);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => 'HOJATEST',
            'sections' => [['codigo' => 'SECFIX', 'titulo' => 'Fixture', 'filaHeader' => 1, 'filaInicioDatos' => 2, 'filaFinDatos' => 3, 'fields' => $this->fieldsWithLabels($labels)]],
        ]]]);

        $patterns = $service->getPatternsForValidation('HOJATEST', 'SECFIX');

        $this->assertCount(1, $patterns, 'debe agrupar las 2 filas de datos en un unico patron');
        $pattern = $patterns[0];

        $this->assertSame('cell_data', $pattern['source']);
        $this->assertSame('formula', $pattern['mode'], 'no debe degradar a direct_input');
        $this->assertEqualsCanonicalizing([2, 3], $pattern['filas']);

        foreach ($pairs as $total => [$nueva, $control]) {
            $this->assertContains($total, $pattern['total_columns'], "columna total {$total} debe detectarse como activa");
            $this->assertArrayHasKey($total, $pattern['formula_templates'], "debe existir plantilla de formula para {$total}");
        }
        $this->assertCount(8, $pattern['total_columns']);
    }
}
