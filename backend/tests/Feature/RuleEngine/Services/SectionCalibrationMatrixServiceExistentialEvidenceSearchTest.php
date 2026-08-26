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
 * Cubre hasEditableInputComponentsForFormula() -- hallazgo de la auditoria de
 * las 13 secciones CORRECCION_DE_ARRASTRE_INVALIDO (2026-08-21): la funcion
 * retornaba `false` en cuanto la PRIMERA fila con la firma exacta (columna
 * total + conjunto de dependencias) fallaba el chequeo de editabilidad --
 * nunca seguia buscando otras filas con la misma firma. Patron real
 * confirmado en A05/C, A05/C2, A05/G, A05/Q, A08/R, A09/G: una fila
 * TOTAL/subtotal lider comparte la firma exacta con filas normales de
 * captura real, pero sus propios componentes son ellos mismos formulas
 * bloqueadas -- si esa fila aparecia primero en la iteracion, la evidencia
 * real de las filas validas nunca se confirmaba.
 *
 * Fix: busqueda EXISTENCIAL -- recorre TODAS las filas con la firma exacta,
 * retorna true en cuanto encuentra UNA con componentes editables, y solo
 * retorna false si NINGUNA lo es. Sin excepciones por hoja/seccion/fila/
 * columna/texto de encabezado.
 */
class SectionCalibrationMatrixServiceExistentialEvidenceSearchTest extends TestCase
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

    private function hasEditable(string $totalColumn, array $components, array $cellDataRows): bool
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'hasEditableInputComponentsForFormula');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $totalColumn, $components, $cellDataRows);
    }

    private function editableCell(): array
    {
        return ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
    }

    private function blockedFormulaCell(string $formula, array $dependencias): array
    {
        return ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => $formula, 'dependencias' => $dependencias];
    }

    private function blockedNonFormulaCell(): array
    {
        return ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
    }

    // 1. Primera fila con misma firma invalida + segunda valida -> true.
    public function test_first_matching_row_invalid_second_valid_returns_true(): void
    {
        $cellDataRows = [
            10 => [ // lider invalido: C,D son ellos mismos formula
                'C' => $this->blockedFormulaCell('=+X10+Y10', ['X10', 'Y10']),
                'D' => $this->blockedFormulaCell('=+Z10+W10', ['Z10', 'W10']),
                'B' => $this->blockedFormulaCell('=+C10+D10', ['C10', 'D10']),
            ],
            11 => [ // fila normal valida: mismos componentes C,D pero editables
                'C' => $this->editableCell(),
                'D' => $this->editableCell(),
                'B' => $this->blockedFormulaCell('=+C11+D11', ['C11', 'D11']),
            ],
        ];

        $this->assertTrue($this->hasEditable('B', ['C', 'D'], $cellDataRows));
    }

    // 2. Varias invalidas antes de una valida -> true.
    public function test_multiple_invalid_rows_before_one_valid_returns_true(): void
    {
        $cellDataRows = [];
        foreach ([10, 11, 12] as $row) {
            $cellDataRows[$row] = [
                'C' => $this->blockedFormulaCell("=+X{$row}+Y{$row}", ["X{$row}", "Y{$row}"]),
                'D' => $this->blockedFormulaCell("=+Z{$row}+W{$row}", ["Z{$row}", "W{$row}"]),
                'B' => $this->blockedFormulaCell("=+C{$row}+D{$row}", ["C{$row}", "D{$row}"]),
            ];
        }
        $cellDataRows[13] = [
            'C' => $this->editableCell(),
            'D' => $this->editableCell(),
            'B' => $this->blockedFormulaCell('=+C13+D13', ['C13', 'D13']),
        ];

        $this->assertTrue($this->hasEditable('B', ['C', 'D'], $cellDataRows));
    }

    // 3. Todas las filas con esa firma invalidas -> false.
    public function test_all_matching_rows_invalid_returns_false(): void
    {
        $cellDataRows = [];
        foreach ([10, 11, 12] as $row) {
            $cellDataRows[$row] = [
                'C' => $this->blockedFormulaCell("=+X{$row}+Y{$row}", ["X{$row}", "Y{$row}"]),
                'D' => $this->blockedFormulaCell("=+Z{$row}+W{$row}", ["Z{$row}", "W{$row}"]),
                'B' => $this->blockedFormulaCell("=+C{$row}+D{$row}", ["C{$row}", "D{$row}"]),
            ];
        }

        $this->assertFalse($this->hasEditable('B', ['C', 'D'], $cellDataRows));
    }

    // 4. Una fila con firma DIFERENTE no debe servir como evidencia.
    public function test_row_with_different_signature_is_not_used_as_evidence(): void
    {
        $cellDataRows = [
            10 => [ // firma distinta: B = E+F, no C+D
                'E' => $this->editableCell(),
                'F' => $this->editableCell(),
                'B' => $this->blockedFormulaCell('=+E10+F10', ['E10', 'F10']),
            ],
        ];

        $this->assertFalse($this->hasEditable('B', ['C', 'D'], $cellDataRows), 'la fila 10 tiene firma E+F, no debe validar la busqueda de C+D');
    }

    // 5. Coincidencia valida con componentes realmente editables -> true.
    public function test_single_valid_matching_row_returns_true(): void
    {
        $cellDataRows = [10 => [
            'C' => $this->editableCell(),
            'D' => $this->editableCell(),
            'B' => $this->blockedFormulaCell('=+C10+D10', ['C10', 'D10']),
        ]];

        $this->assertTrue($this->hasEditable('B', ['C', 'D'], $cellDataRows));
    }

    // 6. Componentes bloqueados (no formula, pero bloqueados) en TODAS las coincidencias -> false.
    public function test_blocked_non_formula_components_in_all_matches_returns_false(): void
    {
        $cellDataRows = [10 => [
            'C' => $this->blockedNonFormulaCell(),
            'D' => $this->blockedNonFormulaCell(),
            'B' => $this->blockedFormulaCell('=+C10+D10', ['C10', 'D10']),
        ]];

        $this->assertFalse($this->hasEditable('B', ['C', 'D'], $cellDataRows));
    }

    // 7. Componentes formula en TODAS las coincidencias -> false.
    public function test_formula_components_in_all_matches_returns_false(): void
    {
        $cellDataRows = [10 => [
            'C' => $this->blockedFormulaCell('=+X10+Y10', ['X10', 'Y10']),
            'D' => $this->blockedFormulaCell('=+Z10+W10', ['Z10', 'W10']),
            'B' => $this->blockedFormulaCell('=+C10+D10', ['C10', 'D10']),
        ]];

        $this->assertFalse($this->hasEditable('B', ['C', 'D'], $cellDataRows));
    }

    // 8. Dependencias diferentes aunque la columna total sea la misma -- no mezclar evidencia.
    public function test_different_dependencies_for_same_total_column_are_not_mixed(): void
    {
        $cellDataRows = [
            10 => [ // B = C+D, editable -- evidencia real para la firma C+D
                'C' => $this->editableCell(),
                'D' => $this->editableCell(),
                'B' => $this->blockedFormulaCell('=+C10+D10', ['C10', 'D10']),
            ],
            11 => [ // B = E+F, bloqueados -- firma DISTINTA, invalida en si misma
                'E' => $this->blockedNonFormulaCell(),
                'F' => $this->blockedNonFormulaCell(),
                'B' => $this->blockedFormulaCell('=+E11+F11', ['E11', 'F11']),
            ],
        ];

        $this->assertTrue($this->hasEditable('B', ['C', 'D'], $cellDataRows), 'la firma C+D tiene evidencia real en la fila 10');
        $this->assertFalse($this->hasEditable('B', ['E', 'F'], $cellDataRows), 'la firma E+F no tiene ninguna evidencia editable -- no debe "prestarse" de la firma C+D');
    }

    // ------------------------------------------------------------------
    // Fixtures equivalentes reales, extremo a extremo via getPatternsForValidation()
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

        $fieldsArr = [];
        foreach ($fields as $letra => $label) {
            $fieldsArr[] = ['letra' => $letra, 'label' => $label, 'esTotal' => false, 'esControlOculto' => false];
        }

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => 'HOJATEST',
            'sections' => [['codigo' => 'SECFIX', 'titulo' => 'Fixture', 'filaHeader' => 1, 'filaInicioDatos' => $filaInicio, 'filaFinDatos' => $filaFin, 'fields' => $fieldsArr]],
        ]]]);

        return $service->getPatternsForValidation('HOJATEST', 'SECFIX');
    }

    /**
     * Fixture equivalente a A05/C: fila lider (35) con C=SUM(D:P), 13
     * componentes, TODOS ellos formulas bloqueadas (subtotal); filas
     * normales (45,46) con la MISMA firma C=SUM(D:P) pero componentes
     * genuinamente editables. Antes del fix, la fila lider "envenenaba" la
     * busqueda y 45/46 quedaban sin relacion reconocida.
     *
     * La fila lider (35) SI vuelve a reconocerse tras el fix -- correcto:
     * C35=SUM(D35:P35) es una relacion real y verificable (confirmado
     * contra A05/C real), solo que sus origenes son ellos mismos formulas
     * (subtotal), no captura manual. Por eso cae en un patron SEPARADO de
     * 45/46 (firma de editabilidad distinta: bloqueada vs editable), nunca
     * mezclado con las filas de captura real.
     */
    public function test_a05c_equivalent_fixture_leader_row_does_not_poison_valid_rows(): void
    {
        $cols = range('D', 'P'); // 13 columnas, igual que A05/C real
        $fields = ['A' => 'Concepto', 'C' => 'Total'];
        foreach ($cols as $c) { $fields[$c] = "Origen {$c}"; }

        $cellDataRows = [];

        // Fila lider (35): componentes son ellos mismos formulas bloqueadas.
        foreach ($cols as $c) {
            $cellDataRows[35][$c] = $this->blockedFormulaCell("=+X35+Y35", ['X35', 'Y35']);
        }
        $cellDataRows[35]['A'] = $this->editableCell();
        $cellDataRows[35]['C'] = $this->blockedFormulaCell(
            '=SUM(D35:P35)',
            array_map(fn($c) => "{$c}35", $cols)
        );

        // Filas normales (45,46): mismos componentes, genuinamente editables.
        foreach ([45, 46] as $row) {
            foreach ($cols as $c) { $cellDataRows[$row][$c] = $this->editableCell(); }
            $cellDataRows[$row]['A'] = $this->editableCell();
            $cellDataRows[$row]['C'] = $this->blockedFormulaCell(
                "=SUM(D{$row}:P{$row})",
                array_map(fn($c) => "{$c}{$row}", $cols)
            );
        }

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 35, 46);

        $normalPattern = collect($patterns)->first(fn($p) => in_array(45, $p['filas'], true));
        $this->assertNotNull($normalPattern, 'las filas 45,46 deben tener un patron reconocido');
        $this->assertSame('formula', $normalPattern['mode']);
        $this->assertContains('C', $normalPattern['total_columns']);
        $this->assertEqualsCanonicalizing([45, 46], $normalPattern['filas']);

        // La fila lider (35) SI se reconoce (relacion real, verificable),
        // pero en un patron PROPIO y SEPARADO -- nunca mezclada con las
        // filas de captura real (45,46), por su firma de editabilidad
        // distinta (componentes bloqueados/formula vs editables).
        $leaderPattern = collect($patterns)->first(fn($p) => in_array(35, $p['filas'], true));
        $this->assertNotNull($leaderPattern, 'la fila lider (35) debe reconocerse -- su formula es real y verificable');
        $this->assertContains('C', $leaderPattern['total_columns']);
        $this->assertNotEquals($normalPattern, $leaderPattern, 'la fila lider (35) no debe mezclarse con las filas de captura real (45,46) -- firma de editabilidad distinta');
        $this->assertNotContains(35, $normalPattern['filas'], 'la fila lider no debe aparecer en el patron de las filas de captura real');
    }

    /**
     * Fixture equivalente a A08/R: subtotal lider (210) + multiples filas
     * validas (211-215) compartiendo firma para 2 columnas total (C y D).
     */
    public function test_a08r_equivalent_fixture_leader_plus_multiple_valid_rows(): void
    {
        $fields = ['A' => 'Concepto', 'B' => 'Total', 'C' => 'Ambos', 'D' => 'Otro', 'E' => 'O1', 'F' => 'O2', 'G' => 'O3', 'H' => 'O4', 'I' => 'O5', 'J' => 'O6', 'K' => 'O7', 'L' => 'O8'];

        $cellDataRows = [];

        // Fila lider (210): C,D son formulas (subtotal de las filas de abajo).
        $cellDataRows[210] = [
            'A' => $this->editableCell(),
            'E' => $this->blockedFormulaCell('=SUM(E211:E215)', ['E211', 'E212', 'E213', 'E214', 'E215']),
            'G' => $this->blockedFormulaCell('=SUM(G211:G215)', ['G211', 'G212', 'G213', 'G214', 'G215']),
            'I' => $this->blockedFormulaCell('=SUM(I211:I215)', ['I211', 'I212', 'I213', 'I214', 'I215']),
            'K' => $this->blockedFormulaCell('=SUM(K211:K215)', ['K211', 'K212', 'K213', 'K214', 'K215']),
            'F' => $this->blockedFormulaCell('=SUM(F211:F215)', ['F211', 'F212', 'F213', 'F214', 'F215']),
            'H' => $this->blockedFormulaCell('=SUM(H211:H215)', ['H211', 'H212', 'H213', 'H214', 'H215']),
            'J' => $this->blockedFormulaCell('=SUM(J211:J215)', ['J211', 'J212', 'J213', 'J214', 'J215']),
            'L' => $this->blockedFormulaCell('=SUM(L211:L215)', ['L211', 'L212', 'L213', 'L214', 'L215']),
            'C' => $this->blockedFormulaCell('=+E210+G210+I210+K210', ['E210', 'G210', 'I210', 'K210']),
            'D' => $this->blockedFormulaCell('=+F210+H210+J210+L210', ['F210', 'H210', 'J210', 'L210']),
        ];

        // Filas validas (211-215): E,G,I,K y F,H,J,L son editables reales.
        foreach (range(211, 215) as $row) {
            foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $c) {
                $cellDataRows[$row][$c] = $this->editableCell();
            }
            $cellDataRows[$row]['A'] = $this->editableCell();
            $cellDataRows[$row]['C'] = $this->blockedFormulaCell("=+E{$row}+G{$row}+I{$row}+K{$row}", ["E{$row}", "G{$row}", "I{$row}", "K{$row}"]);
            $cellDataRows[$row]['D'] = $this->blockedFormulaCell("=+F{$row}+H{$row}+J{$row}+L{$row}", ["F{$row}", "H{$row}", "J{$row}", "L{$row}"]);
        }

        $patterns = $this->seedAndGetPatterns($fields, $cellDataRows, 210, 215);

        $validPattern = collect($patterns)->first(fn($p) => in_array(211, $p['filas'], true));
        $this->assertNotNull($validPattern);
        $this->assertSame('formula', $validPattern['mode']);
        $this->assertContains('C', $validPattern['total_columns']);
        $this->assertContains('D', $validPattern['total_columns']);
        $this->assertEqualsCanonicalizing([211, 212, 213, 214, 215], $validPattern['filas']);

        // La fila lider (210) tambien se reconoce (formula real, verificable:
        // C210=E210+G210+I210+K210 es cierto sin importar que E210 etc. sean
        // a su vez subtotales), pero en un patron PROPIO -- nunca mezclada
        // con las filas de captura real 211-215.
        $leaderPattern = collect($patterns)->first(fn($p) => in_array(210, $p['filas'], true));
        $this->assertNotNull($leaderPattern, 'la fila lider (210) debe reconocerse -- su formula es real y verificable');
        $this->assertContains('C', $leaderPattern['total_columns']);
        $this->assertContains('D', $leaderPattern['total_columns']);
        $this->assertNotContains(210, $validPattern['filas']);
    }
}
