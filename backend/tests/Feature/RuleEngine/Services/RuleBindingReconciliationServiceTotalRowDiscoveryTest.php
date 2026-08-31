<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cubre la Fase 1 (2026-08-27, ver CLAUDE.md punto 16.10) del diseno de
 * auto-discovery de total_row en RuleBindingReconciliationService --
 * 100% diagnostico: agrega 'total_row_candidate'/'total_row_position'/
 * 'total_row_excluded' al resultado de classifyRule(), pero NUNCA cambia
 * 'clasificacion' ni 'motivo'. Casos de control tomados directamente de
 * la auditoria real (16.8/16.9): reglas 56 (trailing excluido), 87
 * (leading excluido), 278 (leading excluido, con el decoy real de la fila
 * 52 de A19B/A que NO debe seleccionarse), 72 (leading NO excluido).
 */
class RuleBindingReconciliationServiceTotalRowDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function cell(bool $editable, bool $blocked, bool $formula = false, ?string $formulaText = null, ?string $valorBruto = null): array
    {
        return [
            'valor_bruto' => $valorBruto,
            'es_editable' => $editable,
            'esta_bloqueada' => $blocked,
            'es_formula' => $formula,
            'formula' => $formulaText,
        ];
    }

    private function dummyField(string $letra, string $label = 'Campo'): array
    {
        return ['letra' => $letra, 'label' => $label, 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null];
    }

    private function structureWithSection(string $sheet, string $section, int $inicio, int $fin, array $fields): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026, 'serie' => 'A', 'rem_template_id' => null, 'version_number' => 67,
            'hash_estructura' => 'hash-discovery-' . uniqid(),
            'estructura' => ['forms' => [
                ['sheetName' => $sheet, 'sections' => [[
                    'codigo' => $section, 'titulo' => $section, 'filaHeader' => $inicio - 1,
                    'filaInicioDatos' => $inicio, 'filaFinDatos' => $fin, 'fields' => $fields,
                ]]],
            ]],
            'metadata' => null, 'source_filename' => 'test.xlsm', 'status' => 'active',
        ]);
    }

    private function verticalRule(string $sheet, string $section, string $column, int $from, int $to, ?int $totalRow = null): Rule
    {
        $config = [
            'sheet' => $sheet, 'section' => $section, 'column' => $column,
            'row_range' => ['from' => $from, 'to' => $to],
            'rule_logic' => "Suma({$column}) = Columna {$column}",
        ];
        if ($totalRow !== null) {
            $config['total_row'] = $totalRow;
        }

        return Rule::create([
            'rule_key' => 'test_' . strtolower($sheet) . '_' . strtolower($section) . '_' . strtolower($column) . '_sum_equals',
            'rule_type' => 'sum_equals', 'source' => 'test', 'name' => 'test', 'description' => 'test',
            'category' => $sheet, 'severity' => 'error', 'scope' => 'per_row',
            'config' => $config, 'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);
    }

    public function test_control_56_trailing_total_excluded_is_discovered_without_changing_classification(): void
    {
        $structure = $this->structureWithSection('TST1', 'A', 10, 12, [$this->dummyField('C', 'Concepto'), $this->dummyField('X', 'Target')]);
        $rule = $this->verticalRule('TST1', 'A', 'X', 10, 11);

        app(CellDataStorageService::class)->saveCellData('TST1', 'A', [
            'C12' => $this->cell(false, true, false, null, 'TOTAL'),
            'X12' => $this->cell(false, true, true, '=SUM(X10+X11)'),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion'], 'la clasificacion no debe cambiar');
        $this->assertSame('invalid_row_range_configuration: falta total_row en config.', $result['motivo'], 'el motivo no debe cambiar');
        $this->assertSame(12, $result['total_row_candidate']);
        $this->assertSame('trailing', $result['total_row_position']);
        $this->assertTrue($result['total_row_excluded'], 'mecanismo #12 debe detectar la fila 12 como excluida de rem_data');
    }

    public function test_control_87_leading_total_excluded_is_discovered_without_changing_classification(): void
    {
        $structure = $this->structureWithSection('TST2', 'B', 19, 25, [$this->dummyField('A', 'Concepto'), $this->dummyField('Y', 'Target')]);
        $rule = $this->verticalRule('TST2', 'B', 'Y', 20, 25);

        app(CellDataStorageService::class)->saveCellData('TST2', 'B', [
            'A19' => $this->cell(false, true, false, null, 'TOTAL REGULACION'),
            'Y19' => $this->cell(false, true, true, '=SUM(Y20:Y25)'),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion']);
        $this->assertSame('invalid_row_range_configuration: falta total_row en config.', $result['motivo']);
        $this->assertSame(19, $result['total_row_candidate']);
        $this->assertSame('leading', $result['total_row_position']);
        $this->assertTrue($result['total_row_excluded'], 'mecanismo #6 debe detectar la fila 19 como excluida de rem_data');
    }

    /**
     * Replica el hallazgo real de A19B/A (2026-08-27): la fila trailing
     * (row_to+1 = 52) tiene una formula real, pero de un bloque de negocio
     * NO relacionado (referencia filas 53-57, fuera de [12:51]) -- no debe
     * seleccionarse como candidato. El total real es la fila leading (11),
     * que SI suma exactamente [12:51].
     */
    public function test_control_278_a19b_style_decoy_at_trailing_is_not_selected(): void
    {
        $structure = $this->structureWithSection('TST3', 'C', 11, 57, [$this->dummyField('A', 'Concepto'), $this->dummyField('Z', 'Target')]);
        $rule = $this->verticalRule('TST3', 'C', 'Z', 12, 51);

        app(CellDataStorageService::class)->saveCellData('TST3', 'C', [
            'A11' => $this->cell(false, true, false, null, 'TOTAL DE RECLAMOS'),
            'Z11' => $this->cell(false, true, true, '=SUM(Z12:Z51)'),
            // Decoy: formula real en la posicion trailing (row_to+1=52),
            // pero de un bloque distinto -- referencia filas 53-57, fuera
            // del row_range [12:51] de esta regla.
            'A52' => $this->cell(false, true, false, null, 'TOTAL CONSULTAS'),
            'Z52' => $this->cell(false, true, true, '=SUM(Z53:Z57)'),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion']);
        $this->assertSame(11, $result['total_row_candidate'], 'debe elegir la fila 11 (leading, coincide exacto), nunca la 52 (decoy no relacionado)');
        $this->assertSame('leading', $result['total_row_position']);
        $this->assertTrue($result['total_row_excluded']);
    }

    public function test_control_72_leading_total_not_excluded_is_discovered(): void
    {
        $structure = $this->structureWithSection('TST4', 'D', 57, 61, [$this->dummyField('A', 'Concepto'), $this->dummyField('W', 'Target')]);
        $rule = $this->verticalRule('TST4', 'D', 'W', 60, 61);

        app(CellDataStorageService::class)->saveCellData('TST4', 'D', [
            // Etiqueta funcional real, NO literalmente "TOTAL" -- mecanismo
            // #6 no debe reconocerla (hallazgo real A04/D fila 59).
            'A59' => $this->cell(false, true, false, null, 'Sabado, Domingo o festivo'),
            'W59' => $this->cell(false, true, true, '=SUM(W60:W61)'),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion']);
        $this->assertSame(59, $result['total_row_candidate']);
        $this->assertSame('leading', $result['total_row_position']);
        $this->assertFalse($result['total_row_excluded'], 'mecanismo #6 no debe detectar una etiqueta funcional generica como TOTAL');
    }

    public function test_no_candidate_leaves_all_fields_null(): void
    {
        $structure = $this->structureWithSection('TST5', 'E', 100, 106, [$this->dummyField('A', 'Concepto'), $this->dummyField('B', 'Target')]);
        $rule = $this->verticalRule('TST5', 'E', 'B', 100, 105);

        app(CellDataStorageService::class)->saveCellData('TST5', 'E', [
            'A99' => $this->cell(true, false, false, null, null),
            'B99' => $this->cell(true, false, false, null, null),
            'A106' => $this->cell(true, false, false, null, null),
            'B106' => $this->cell(true, false, false, null, null),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion']);
        $this->assertNull($result['total_row_candidate']);
        $this->assertNull($result['total_row_position']);
        $this->assertNull($result['total_row_excluded']);
    }

    public function test_ambiguous_candidates_on_both_sides_resolve_to_null(): void
    {
        $structure = $this->structureWithSection('TST6', 'F', 19, 27, [$this->dummyField('A', 'Concepto'), $this->dummyField('C', 'Target')]);
        $rule = $this->verticalRule('TST6', 'F', 'C', 20, 25);

        app(CellDataStorageService::class)->saveCellData('TST6', 'F', [
            'A19' => $this->cell(false, true, false, null, 'TOTAL A'),
            'C19' => $this->cell(false, true, true, '=SUM(C20:C25)'),
            'A26' => $this->cell(false, true, false, null, 'TOTAL B'),
            'C26' => $this->cell(false, true, true, '=SUM(C20:C25)'),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion']);
        $this->assertNull($result['total_row_candidate'], 'ambiguedad (ambas posiciones coinciden exacto) no debe resolverse automaticamente');
        $this->assertNull($result['total_row_position']);
        $this->assertNull($result['total_row_excluded']);
    }

    public function test_rule_with_total_row_already_set_never_runs_discovery(): void
    {
        $structure = $this->structureWithSection('TST7', 'G', 10, 12, [$this->dummyField('C', 'Concepto'), $this->dummyField('X', 'Target')]);
        $rule = $this->verticalRule('TST7', 'G', 'X', 10, 11, totalRow: 12);

        app(CellDataStorageService::class)->saveCellData('TST7', 'G', [
            'C12' => $this->cell(false, true, false, null, 'TOTAL'),
            'X12' => $this->cell(false, true, true, '=SUM(X10+X11)'),
        ]);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('SAFE_1_TO_1', $result['clasificacion'], 'total_row valido y dentro de rango debe clasificar SAFE_1_TO_1 como antes');
        $this->assertNull($result['total_row_candidate'], 'discovery no debe ejecutarse cuando total_row ya esta en config');
        $this->assertNull($result['total_row_position']);
        $this->assertNull($result['total_row_excluded']);
    }

    public function test_zero_zero_row_range_placeholder_never_runs_discovery(): void
    {
        $structure = $this->structureWithSection('TST8', 'H', 10, 20, [$this->dummyField('C', 'Concepto'), $this->dummyField('X', 'Target')]);
        $rule = $this->verticalRule('TST8', 'H', 'X', 0, 0);

        $svc = app(RuleBindingReconciliationService::class);
        $result = $svc->classifySingleRule($rule, $structure);

        $this->assertSame('BLOCKED_BY_ENGINE_GAP', $result['clasificacion']);
        $this->assertNull($result['total_row_candidate'], 'row_range={0,0} es el placeholder horizontal -- discovery no debe correr');
        $this->assertNull($result['total_row_position']);
        $this->assertNull($result['total_row_excluded']);
    }
}
