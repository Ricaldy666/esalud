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
 * Fase 3 (2026-08-21, hallazgo A07/A): cubre el rediseño del gate de
 * validacion de formulas horizontales.
 *
 * Causa raiz corregida: buildDynamicPatternDefinitions() calculaba una lista
 * de "columnas total" candidatas UNA VEZ por seccion completa
 * (getFormulaTotalColumnsFromCellData(), que si valida evidencia por fila),
 * pero el bucle de activacion POR FILA nunca volvia a exigir esa evidencia --
 * solo pedia formula + misma fila + dependencias no vacias + no
 * autorreferencia. Una vez que CUALQUIER fila habilitaba la columna, TODAS
 * las demas filas de esa columna quedaban activas sin importar su propia
 * forma ("arrastre").
 *
 * Fix (2 cambios, ninguno hardcodea hoja/seccion/fila/cantidad de
 * componentes):
 *  1. isFunctionalHorizontalFormula() se simplifico a un unico criterio de
 *     evidencia (isSexMainRuleFormula() O hasEditableInputComponentsForFormula()),
 *     sin ramas por cantidad de componentes ni texto de etiqueta.
 *  2. El bucle de activacion por fila en buildDynamicPatternDefinitions()
 *     ahora revalida isFunctionalHorizontalFormula() con la firma PROPIA de
 *     cada fila antes de marcarla activa.
 */
class SectionCalibrationMatrixServiceRowLevelValidationGateTest extends TestCase
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

    private function blockedNonFormulaCell(): array
    {
        return ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
    }

    private function formulaCell(string $formula, array $dependencias): array
    {
        return ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => $formula, 'dependencias' => $dependencias];
    }

    private function columnsRange(string $from, string $to): array
    {
        $start = ord($from);
        $end = ord($to);
        $letters = [];
        for ($i = $start; $i <= $end; $i++) {
            $letters[] = chr($i);
        }

        return $letters;
    }

    // ------------------------------------------------------------------
    // Gate puro (isFunctionalHorizontalFormula): sin limite de cantidad
    // ------------------------------------------------------------------

    public function test_two_component_row_still_accepted_by_own_evidence(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels(['B' => 'Nueva', 'J' => 'Control', 'R' => 'Cualquier etiqueta'])];
        $cellDataRows = [10 => [
            'B' => $this->editableCell(),
            'J' => $this->editableCell(),
            'R' => $this->formulaCell('=+B10+J10', ['B10', 'J10']),
        ]];

        $this->assertTrue($this->isFunctional('R', ['B', 'J'], $sectionData, $cellDataRows));
    }

    public function test_five_component_row_accepted_by_own_evidence(): void
    {
        $components = $this->columnsRange('C', 'G'); // C,D,E,F,G = 5
        $fields = ['B' => 'Total sin importar etiqueta'];
        foreach ($components as $c) { $fields[$c] = "Origen {$c}"; }

        $sectionData = ['fields' => $this->fieldsWithLabels($fields)];
        $row = [];
        foreach ($components as $c) { $row[$c] = $this->editableCell(); }
        $row['B'] = $this->formulaCell('=SUM(C10:G10)', array_map(fn($c) => "{$c}10", $components));
        $cellDataRows = [10 => $row];

        $this->assertTrue($this->isFunctional('B', $components, $sectionData, $cellDataRows));
    }

    public function test_eighteen_component_row_accepted_by_own_evidence(): void
    {
        $components = $this->columnsRange('C', 'T'); // C..T = 18
        $fields = ['B' => 'Total sin importar etiqueta'];
        foreach ($components as $c) { $fields[$c] = "Origen {$c}"; }

        $sectionData = ['fields' => $this->fieldsWithLabels($fields)];
        $row = [];
        foreach ($components as $c) { $row[$c] = $this->editableCell(); }
        $row['B'] = $this->formulaCell('=SUM(C10:T10)', array_map(fn($c) => "{$c}10", $components));
        $cellDataRows = [10 => $row];

        $this->assertTrue($this->isFunctional('B', $components, $sectionData, $cellDataRows));
    }

    public function test_self_reference_is_rejected(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels(['B' => 'Total', 'C' => 'Origen'])];
        $cellDataRows = [10 => [
            'C' => $this->editableCell(),
            'B' => $this->formulaCell('=+B10+C10', ['B10', 'C10']),
        ]];

        // B aparece en sus propias dependencias -- nunca valido.
        $this->assertFalse($this->isFunctional('B', ['B', 'C'], $sectionData, $cellDataRows));
    }

    public function test_component_that_is_formula_rejected_when_manual_expected(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels(['B' => 'Total', 'C' => 'Origen1', 'D' => 'Origen2'])];
        $cellDataRows = [10 => [
            'C' => $this->editableCell(),
            'D' => $this->formulaCell('=+X10+Y10', ['X10', 'Y10']), // no es entrada manual real
            'B' => $this->formulaCell('=+C10+D10', ['C10', 'D10']),
        ]];

        $this->assertFalse($this->isFunctional('B', ['C', 'D'], $sectionData, $cellDataRows));
    }

    public function test_blocked_non_editable_component_is_rejected(): void
    {
        $sectionData = ['fields' => $this->fieldsWithLabels(['B' => 'Total', 'C' => 'Origen1', 'D' => 'Origen2'])];
        $cellDataRows = [10 => [
            'C' => $this->editableCell(),
            'D' => $this->blockedNonFormulaCell(),
            'B' => $this->formulaCell('=+C10+D10', ['C10', 'D10']),
        ]];

        $this->assertFalse($this->isFunctional('B', ['C', 'D'], $sectionData, $cellDataRows));
    }

    // ------------------------------------------------------------------
    // Arrastre: pipeline completo via getPatternsForValidation()
    // ------------------------------------------------------------------

    private function seedAndGetPatterns(array $fields, array $cellDataRows, int $filaInicio, int $filaFin): array
    {
        RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'hash_estructura' => 'test_' . uniqid(),
            'version_number' => 1, 'estructura' => ['forms' => []], 'status' => 'active',
            'source_filename' => 'test.xlsm',
        ]);

        $flat = [];
        foreach ($cellDataRows as $row => $cols) {
            foreach ($cols as $col => $cell) { $flat["{$col}{$row}"] = $cell; }
        }
        app(CellDataStorageService::class)->saveCellData('HOJATEST', 'SECFIX', $flat);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => 'HOJATEST',
            'sections' => [['codigo' => 'SECFIX', 'titulo' => 'Fixture', 'filaHeader' => 1, 'filaInicioDatos' => $filaInicio, 'filaFinDatos' => $filaFin, 'fields' => $this->fieldsWithLabels($fields)]],
        ]]]);

        return $service->getPatternsForValidation('HOJATEST', 'SECFIX');
    }

    /**
     * Fila 10: B = C+D (2 componentes, real, editable) -- valida por su
     * propia evidencia. Fila 11: B = SUM(X:Z) donde X,Y,Z estan BLOQUEADOS
     * (no son entrada manual real, y su firma [X,Y,Z] no coincide con la
     * firma [C,D] de la fila 10) -- antes del fix, con B ya "habilitada" a
     * nivel de seccion por la fila 10, la fila 11 se marcaba activa igual
     * (arrastre), sin importar que su propia forma nunca hubiera sido
     * evaluada. Ahora la fila 11 no tiene ninguna evidencia editable propia
     * (ni activeTotalColumns ni directInputColumns), por lo que queda
     * excluida por completo de los patrones -- la prueba de que NO se
     * arrastro es que nunca aparece mezclada con la fila 10.
     */
    public function test_row_with_invalid_own_signature_does_not_activate_via_drag_from_sibling_row(): void
    {
        $fields = ['B' => 'Total', 'C' => 'Origen1', 'D' => 'Origen2', 'X' => 'Bloq1', 'Y' => 'Bloq2', 'Z' => 'Bloq3'];
        $cellDataRows = [
            10 => [
                'C' => $this->editableCell(),
                'D' => $this->editableCell(),
                'B' => $this->formulaCell('=+C10+D10', ['C10', 'D10']),
            ],
            11 => [
                'X' => $this->blockedNonFormulaCell(),
                'Y' => $this->blockedNonFormulaCell(),
                'Z' => $this->blockedNonFormulaCell(),
                'B' => $this->formulaCell('=SUM(X11:Z11)', ['X11', 'Y11', 'Z11']),
            ],
        ];

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 10, 11);

        $row10Pattern = collect($patterns)->first(fn($p) => in_array(10, $p['filas'], true));

        $this->assertNotNull($row10Pattern);
        $this->assertSame('formula', $row10Pattern['mode'], 'fila 10 tiene evidencia real propia, debe quedar en modo formula');
        $this->assertContains('B', $row10Pattern['total_columns']);
        $this->assertNotContains(11, $row10Pattern['filas'], 'fila 11 no debe agruparse con la fila 10 -- su formula (X+Y+Z, todos bloqueados) nunca fue validada por evidencia propia');

        // Ningun patron de la seccion debe contener la fila 11 con B activo.
        foreach ($patterns as $p) {
            if (in_array(11, $p['filas'], true)) {
                $this->assertNotContains('B', $p['total_columns'], 'B no debe aparecer como total activo para la fila 11 en ningun patron');
            }
        }
    }

    /**
     * Variante: la fila "arrastrada" tiene un componente que es FORMULA (no
     * entrada manual) en vez de bloqueado. Usa columnas ORIGEN DISTINTAS a
     * las de la fila 10 (E+F vs C+D) -- si compartieran las mismas letras de
     * columna, hasEditableInputComponentsForFormula() encontraria evidencia
     * editable valida en la fila 10 para esa firma y aceptaria la fila 11
     * legitimamente (misma firma = mismo campo real, agrupacion correcta,
     * no arrastre). Con firmas distintas, la fila 11 debe validarse
     * exclusivamente con SU PROPIA evidencia -- y como su C es formula, debe
     * rechazarse.
     */
    public function test_row_with_formula_component_does_not_activate_via_drag(): void
    {
        $fields = ['B' => 'Total', 'C' => 'Origen1', 'D' => 'Origen2', 'E' => 'Origen3', 'F' => 'Origen4'];
        $cellDataRows = [
            10 => [
                'E' => $this->editableCell(),
                'F' => $this->editableCell(),
                'B' => $this->formulaCell('=+E10+F10', ['E10', 'F10']),
            ],
            11 => [
                'C' => $this->formulaCell('=+M11+N11', ['M11', 'N11']),
                'D' => $this->editableCell(),
                'B' => $this->formulaCell('=+C11+D11', ['C11', 'D11']),
            ],
        ];

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 10, 11);

        $row10Pattern = collect($patterns)->first(fn($p) => in_array(10, $p['filas'], true));
        $this->assertNotNull($row10Pattern);
        $this->assertSame('formula', $row10Pattern['mode']);
        $this->assertNotContains(11, $row10Pattern['filas'], 'fila 11 (firma C+D, C es formula) no debe agruparse con la fila 10 (firma E+F, ambos editables)');

        foreach ($patterns as $p) {
            if (in_array(11, $p['filas'], true)) {
                $this->assertNotContains('B', $p['total_columns'], 'fila 11: C es formula (no manual), su propia firma C+D no tiene evidencia editable -- no debe activarse por arrastre desde la fila 10');
            }
        }
    }

    /**
     * "Dependencia de otra fila se rechaza cuando no corresponde": una
     * formula cuyas dependencias apuntan a una fila DISTINTA nunca debe
     * activarse, exista o no evidencia editable en esa otra fila.
     */
    public function test_formula_referencing_a_different_row_is_rejected(): void
    {
        $fields = ['B' => 'Total', 'C' => 'Origen1', 'D' => 'Origen2'];
        $cellDataRows = [
            10 => [
                'C' => $this->editableCell(),
                'D' => $this->editableCell(),
                // Fila 10 referencia la fila 9 -- nunca es "misma fila".
                'B' => $this->formulaCell('=+C9+D9', ['C9', 'D9']),
            ],
        ];

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 10, 10);
        $row10Pattern = collect($patterns)->first(fn($p) => in_array(10, $p['filas'], true));

        $this->assertNotNull($row10Pattern);
        $this->assertSame('direct_input', $row10Pattern['mode'], 'formula que referencia otra fila nunca debe activarse como total de la fila actual');
    }

    /**
     * "Formulas heterogeneas legitimas dentro de una seccion pueden generar
     * firmas/patrones distintos sin obligarlas a compartir componentes":
     * fila 10 usa B=C+D, fila 11 usa el MISMO total B pero con componentes
     * COMPLETAMENTE DISTINTOS (E+F) -- ambas reales, editables, verificadas
     * por su propia evidencia. Deben aparecer como 2 patrones separados
     * (firmas distintas), ambos en modo formula, ninguno forzado a compartir
     * componentes con el otro.
     */
    public function test_heterogeneous_formulas_for_same_total_column_generate_separate_patterns(): void
    {
        $fields = ['B' => 'Total', 'C' => 'Origen1', 'D' => 'Origen2', 'E' => 'Origen3', 'F' => 'Origen4'];
        $cellDataRows = [
            10 => [
                'C' => $this->editableCell(), 'D' => $this->editableCell(),
                'B' => $this->formulaCell('=+C10+D10', ['C10', 'D10']),
            ],
            11 => [
                'E' => $this->editableCell(), 'F' => $this->editableCell(),
                'B' => $this->formulaCell('=+E11+F11', ['E11', 'F11']),
            ],
        ];

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 10, 11);

        $this->assertCount(2, $patterns, 'las 2 formas de B (C+D vs E+F) deben generar 2 patrones distintos, no forzarse a compartir componentes');
        foreach ($patterns as $p) {
            $this->assertSame('formula', $p['mode']);
            $this->assertContains('B', $p['total_columns']);
        }
        $origins = collect($patterns)->pluck('formula_templates.B')->sort()->values()->all();
        $this->assertSame(['=+C{fila}+D{fila}', '=+E{fila}+F{fila}'], $origins);
    }

    /**
     * Fixture equivalente a A07/A: la mayoria de las filas suma TODOS los
     * componentes de un rango amplio (18, como los tramos etarios reales),
     * pero 2 filas "especiales" (equivalentes a Neonatologia y Geriatria)
     * usan un SUBCONJUNTO reducido y distinto entre si (2 y 5 componentes).
     * Las 3 formas deben aceptarse por su PROPIA evidencia -- ninguna debe
     * depender de que otra fila haya habilitado la columna.
     */
    public function test_a07a_equivalent_fixture_three_shapes_each_validated_individually(): void
    {
        $allAgeCols = $this->columnsRange('C', 'T'); // 18 columnas, como A07/A real
        $fields = ['A' => 'Especialidad', 'B' => 'Total'];
        foreach ($allAgeCols as $c) { $fields[$c] = "Tramo {$c}"; }

        $cellDataRows = [];

        // 3 filas "normales": suman los 18 tramos completos (igual que la
        // mayoria de especialidades reales de A07/A).
        foreach ([12, 13, 15] as $row) {
            foreach ($allAgeCols as $c) { $cellDataRows[$row][$c] = $this->editableCell(); }
            $cellDataRows[$row]['A'] = $this->editableCell();
            $cellDataRows[$row]['B'] = $this->formulaCell(
                "=SUM(C{$row}:T{$row})",
                array_map(fn($c) => "{$c}{$row}", $allAgeCols)
            );
        }

        // Fila "Neonatologia": solo 2 tramos (C,D).
        $cellDataRows[14]['A'] = $this->editableCell();
        $cellDataRows[14]['C'] = $this->editableCell();
        $cellDataRows[14]['D'] = $this->editableCell();
        $cellDataRows[14]['B'] = $this->formulaCell('=SUM(C14:D14)', ['C14', 'D14']);

        // Fila "Geriatria": solo 5 tramos (P,Q,R,S,T).
        $oldCols = $this->columnsRange('P', 'T');
        $cellDataRows[35]['A'] = $this->editableCell();
        foreach ($oldCols as $c) { $cellDataRows[35][$c] = $this->editableCell(); }
        $cellDataRows[35]['B'] = $this->formulaCell(
            '=SUM(P35:T35)',
            array_map(fn($c) => "{$c}35", $oldCols)
        );

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 12, 35);

        $bigBlock = collect($patterns)->first(fn($p) => in_array(12, $p['filas'], true));
        $neonatologia = collect($patterns)->first(fn($p) => in_array(14, $p['filas'], true));
        $geriatria = collect($patterns)->first(fn($p) => in_array(35, $p['filas'], true));

        $this->assertNotNull($bigBlock);
        $this->assertSame('formula', $bigBlock['mode']);
        $this->assertContains('B', $bigBlock['total_columns']);
        $this->assertEqualsCanonicalizing([12, 13, 15], $bigBlock['filas']);

        $this->assertNotNull($neonatologia);
        $this->assertSame('formula', $neonatologia['mode']);
        $this->assertSame([14], $neonatologia['filas']);

        $this->assertNotNull($geriatria);
        $this->assertSame('formula', $geriatria['mode']);
        $this->assertSame([35], $geriatria['filas']);

        // Las 3 formas quedan en patrones DISTINTOS (firmas distintas) --
        // ninguna se mezcla ni depende de las otras.
        $this->assertNotEquals($bigBlock['formula_templates'], $neonatologia['formula_templates']);
        $this->assertNotEquals($bigBlock['formula_templates'], $geriatria['formula_templates']);
        $this->assertNotEquals($neonatologia['formula_templates'], $geriatria['formula_templates']);
    }

    /**
     * Fixture equivalente a A32/L: 4 filas ("programas"), cada una suma un
     * subconjunto DISTINTO de 2 a 4 canales -- conserva las diferencias
     * reales encontradas en la auditoria (E+G, D+E+G, F+G, D:G). Ninguna
     * fila debe forzarse a compartir componentes con otra.
     */
    public function test_a32l_equivalent_fixture_preserves_real_heterogeneous_shapes(): void
    {
        $fields = ['B' => 'Concepto', 'C' => 'TOTAL ACCIONES', 'D' => 'Telefonico', 'E' => 'Videollamada', 'F' => 'Seminario', 'G' => 'Plataforma'];

        $cellDataRows = [
            193 => [
                'E' => $this->editableCell(), 'G' => $this->editableCell(),
                'C' => $this->formulaCell('=SUM(E193+G193)', ['E193', 'G193']),
            ],
            194 => [
                'D' => $this->editableCell(), 'E' => $this->editableCell(), 'G' => $this->editableCell(),
                'C' => $this->formulaCell('=SUM(D194+E194+G194)', ['D194', 'E194', 'G194']),
            ],
            195 => [
                'F' => $this->editableCell(), 'G' => $this->editableCell(),
                'C' => $this->formulaCell('=+F195+G195', ['F195', 'G195']),
            ],
            196 => [
                'D' => $this->editableCell(), 'E' => $this->editableCell(), 'F' => $this->editableCell(), 'G' => $this->editableCell(),
                'C' => $this->formulaCell('=SUM(D196:G196)', ['D196', 'E196', 'F196', 'G196']),
            ],
        ];

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 193, 196);

        // Las 4 filas deben aparecer activas para C, cada una con su propia
        // firma -- ninguna depende de que otra la haya habilitado.
        foreach ([193, 194, 195, 196] as $row) {
            $pattern = collect($patterns)->first(fn($p) => in_array($row, $p['filas'], true));
            $this->assertNotNull($pattern, "fila {$row} debe tener un patron propio");
            $this->assertSame('formula', $pattern['mode'], "fila {$row} debe reconocerse en modo formula por su propia evidencia");
            $this->assertContains('C', $pattern['total_columns'], "fila {$row} debe reconocer C como columna total");
        }

        $templates = collect($patterns)->pluck('formula_templates.C')->unique()->values()->all();
        $this->assertCount(4, $templates, 'las 4 formas de C deben conservarse como firmas distintas, sin forzar componentes compartidos');
    }
}
