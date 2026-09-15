<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * BM-11.15 (ENGINE_UI_GAP, BM-11.14): certifica el soporte GENERICO
 * (sin hardcodes de BM/BM18/seccion B/BM18A) para secciones 100% derivadas
 * de otra hoja -- caso real: BM18/B (0 celdas editables, 20 reglas tecnicas
 * reales -- 1 sum_equals + 19 cross_sheet_equals -- ejecutando
 * correctamente, "Filas analizadas: Sin filas" incorrecto antes del fix).
 *
 * El fallback (SectionCalibrationMatrixService::buildDerivedAutoFillPattern())
 * es estrictamente ADITIVO: solo se evalua cuando el camino existente
 * (isFunctionalHorizontalFormula()/hasEditableInputComponentsForFormula(),
 * proteccion historica de Serie A, CORRECCION_DE_ARRASTRE_INVALIDO
 * 2026-08-21) ya produjo 0 patrones. Esa proteccion NO se toca -- ver
 * SectionCalibrationMatrixServiceHorizontalTwoComponentTest/
 * ExistentialEvidenceSearchTest/RowLevelValidationGateTest (31/31 passing,
 * sin modificar, reconfirmado en esta misma fase).
 *
 * 100% aislado: Storage::fake('local'), RefreshDatabase (esalud_testing),
 * fixtures sinteticas creadas dentro de cada test -- nunca estructura BM
 * real (72), reglas BM reales, ni reglas-funcionales.json real.
 */
class SectionCalibrationMatrixServiceDerivedAutoFillTest extends TestCase
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

    private function fieldsWithTotals(array $labelsByLetter, array $totalLetters): array
    {
        $fields = [];
        foreach ($labelsByLetter as $letra => $label) {
            $fields[] = [
                'letra' => $letra,
                'label' => $label,
                'esTotal' => in_array($letra, $totalLetters, true),
                'esControlOculto' => in_array($letra, $totalLetters, true),
            ];
        }

        return $fields;
    }

    private function labelCell(string $value): array
    {
        return ['valor_bruto' => $value, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
    }

    private function blockedFormulaCell(string $formula, array $dependencias): array
    {
        return ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => true, 'formula' => $formula, 'dependencias' => $dependencias];
    }

    private function editableCell(): array
    {
        return ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null, 'dependencias' => []];
    }

    /**
     * @param  array<int, array{rule_type: string, config: array}>  $ruleConfigs
     */
    private function createStructureWithRules(string $serie, array $ruleConfigs): RemTemplateStructure
    {
        $structure = RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => $serie,
            'hash_estructura' => 'test_hash_derived_' . uniqid(),
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'status' => 'active',
            'source_filename' => 'test.xlsm',
        ]);

        foreach ($ruleConfigs as $i => $definition) {
            $rule = Rule::create([
                'rule_key' => "test_derived_rule_{$i}_" . uniqid(),
                'rule_type' => $definition['rule_type'],
                'source' => 'excel_formula',
                'name' => 'Test derived rule',
                'description' => 'Fixture BM-11.15',
                'severity' => 'error',
                'scope' => 'per_row',
                'config' => $definition['config'],
                'status' => 'active',
                'version' => '1.0.0',
                'metadata' => ['sheet' => $definition['config']['sheet'] ?? ''],
            ]);
            RuleBinding::create([
                'rule_id' => $rule->id,
                'bindable_type' => 'structure',
                'bindable_id' => $structure->id,
                'serie' => $serie,
                'anio' => 2026,
                'active' => true,
            ]);
        }

        return $structure;
    }

    private function seedCellData(string $sheet, string $section, array $cellDataRows): void
    {
        $flat = [];
        foreach ($cellDataRows as $row => $cols) {
            foreach ($cols as $col => $cell) {
                $flat["{$col}{$row}"] = $cell;
            }
        }
        app(CellDataStorageService::class)->saveCellData($sheet, $section, $flat);
    }

    // ── Caso A + D: seccion 100% derivada real -> derived_auto_fill, filas
    // reales analizadas (no "Sin filas") ──────────────────────────────────

    public function test_section_with_zero_editable_cells_and_real_technical_rules_is_classified_derived_auto_fill(): void
    {
        $sheet = 'TESTDERIVED';
        $section = 'X';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 11,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [];
        foreach ([10, 11] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Concepto {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row}:D{$row})", ["C{$row}", "D{$row}"]),
                // C/D simulan referencias cross-hoja: formula real, SIN
                // dependencias locales (mismo patron que BM-2.6A/BM-11.14
                // confirmo para BM18/B: una celda cross-hoja tiene
                // 'dependencias' same-sheet vacio por diseño).
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ];
        }
        $this->seedCellData($sheet, $section, $cellDataRows);

        $structure = $this->createStructureWithRules('BMTEST', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'B', 'row_range' => ['from' => 10, 'to' => 11], 'rule_logic' => 'Suma(C + D) = Columna B'],
            ],
            [
                'rule_type' => 'cross_sheet_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'source' => ['cell' => 'C10'], 'target' => ['sheet' => 'OTRAHOJA', 'cell' => 'D1']],
            ],
        ]);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture derivada', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMTEST');

        $this->assertSame('derived_auto_fill', $matrix['capture_mode']);
        $this->assertNotNull($matrix['capture_mode_reason']);
        $this->assertCount(1, $matrix['patterns']);
        $this->assertSame('derived_auto_fill', $matrix['patterns'][0]['mode']);
        // Caso D: filas reales analizadas -- NO "Sin filas".
        $this->assertEqualsCanonicalizing([10, 11], $matrix['patterns'][0]['filas']);
        $this->assertNotSame('captura directa', mb_strtolower($matrix['patterns'][0]['regla_funcional_label'] ?? ''));

        // rule_key/rule_type reales visibles por fila (fix de propagacion de
        // $serie a CertificationService::getSectionRules(), BM-11.15 §1).
        $row10 = collect($matrix['all_rows'])->firstWhere('row', 10);
        $this->assertNotNull($row10['rule_key']);

        unset($structure);
    }

    // ── Caso B: sin editables PERO sin evidencia tecnica -> NO derivada ───

    public function test_section_with_zero_editable_cells_but_no_technical_rules_is_not_classified_derived(): void
    {
        $sheet = 'TESTNORULES';
        $section = 'Y';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 11,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [];
        foreach ([10, 11] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Concepto {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row}:D{$row})", ["C{$row}", "D{$row}"]),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ];
        }
        $this->seedCellData($sheet, $section, $cellDataRows);

        // Estructura activa SIN ninguna regla/binding real -- 0 evidencia
        // tecnica, aunque el shape de celdas sea identico al Caso A.
        $this->createStructureWithRules('BMTEST2', []);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture sin reglas', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMTEST2');

        $this->assertSame('standard', $matrix['capture_mode']);
        $this->assertNull($matrix['capture_mode_reason']);
        $this->assertCount(0, $matrix['patterns'], 'Sin reglas tecnicas reales, la seccion no puede clasificarse como derivada -- nunca se amplia silenciosamente.');
    }

    // ── Caso con una celda editable real -> NUNCA derivada (guard explicito) ──

    public function test_section_with_one_editable_cell_is_never_classified_derived_even_with_real_rules(): void
    {
        $sheet = 'TESTMIXED';
        $section = 'Z';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 11,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [
            10 => [
                'A' => $this->labelCell('Concepto 10'),
                'B' => $this->blockedFormulaCell('=SUM(C10:D10)', ['C10', 'D10']),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ],
            // Fila 11 tiene C11 genuinamente editable -- rompe el requisito
            // "0 celdas editables en TODA la seccion".
            11 => [
                'A' => $this->labelCell('Concepto 11'),
                'B' => $this->blockedFormulaCell('=SUM(C11:D11)', ['C11', 'D11']),
                'C' => $this->editableCell(),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ],
        ];
        $this->seedCellData($sheet, $section, $cellDataRows);

        $this->createStructureWithRules('BMTEST3', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'B', 'row_range' => ['from' => 10, 'to' => 11], 'rule_logic' => 'Suma(C + D) = Columna B'],
            ],
        ]);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture mixta', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMTEST3');

        $this->assertSame('standard', $matrix['capture_mode'], 'Una sola celda editable en cualquier fila descarta por completo la clasificacion derivada.');
    }

    // ── Caso E: consolidacion vertical detectada/reportada, TOTAL excluido
    // de pattern rows ──────────────────────────────────────────────────────

    public function test_vertical_consolidation_row_is_reported_but_excluded_from_pattern_rows(): void
    {
        $sheet = 'TESTVERTICAL';
        $section = 'W';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 12,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [
            10 => [
                'A' => $this->labelCell('Concepto 10'),
                'B' => $this->blockedFormulaCell('=SUM(C10:D10)', ['C10', 'D10']),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ],
            11 => [
                'A' => $this->labelCell('Concepto 11'),
                'B' => $this->blockedFormulaCell('=SUM(C11:D11)', ['C11', 'D11']),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D2', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E2', []),
            ],
            // Fila TOTAL vertical real -- B12 = SUM(B10:B11), misma columna,
            // filas anteriores -- debe detectarse y reportarse, sin volver a
            // incluirse como fila de patron.
            12 => [
                'A' => $this->labelCell('TOTAL'),
                'B' => $this->blockedFormulaCell('=SUM(B10:B11)', ['B10', 'B11']),
            ],
        ];
        $this->seedCellData($sheet, $section, $cellDataRows);

        $this->createStructureWithRules('BMTEST4', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'B', 'row_range' => ['from' => 10, 'to' => 11], 'rule_logic' => 'Suma(C + D) = Columna B'],
            ],
        ]);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture vertical', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMTEST4');

        $this->assertTrue($matrix['has_vertical_consolidation'], 'GAP CERRADO: fila TOTAL real reportada.');
        $this->assertSame([12], $matrix['vertical_consolidation_rows']);
        $this->assertSame('derived_auto_fill', $matrix['capture_mode']);
        $this->assertNotContains(12, $matrix['patterns'][0]['filas'], 'La fila TOTAL nunca debe incluirse como fila de captura funcional.');
        $this->assertEqualsCanonicalizing([10, 11], $matrix['patterns'][0]['filas']);
    }

    // ── Caso F: la calibracion derivada NO genera empty_behavior artificial ──
    // (el frontend, BM-11.15 §7/§8, nunca envia una pregunta 'empty' para un
    // patron derived_auto_fill -- se confirma aqui que, sin esa pregunta,
    // FunctionalRuleService nunca produce una regla funcional, sin tocar
    // ese servicio en esta fase).

    public function test_derived_section_confirmation_without_empty_question_never_produces_functional_rule(): void
    {
        $service = new FunctionalRuleService();
        $service->saveQuestions('TESTDERIVED', 'X', [
            [
                'id' => 'patron_1_logic_correct',
                'type' => 'pattern_question',
                'question' => 'Confirmación de la lógica automática detectada (sección derivada de otra hoja)',
                'response' => 'si',
                'pattern_id' => 1,
                'pattern_key' => 'pattern_1',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_1_formula_confirmation',
                'type' => 'pattern_confirmation',
                'question' => 'Confirmación de lectura técnica desde el XLSM',
                'response' => 'confirmed',
                'pattern_id' => 1,
                'pattern_key' => 'pattern_1',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
        ], 'BMTEST');

        $patterns = [['id' => 1, 'key' => 'pattern_1', 'rows' => [['fila' => 10], ['fila' => 11]]]];
        $resolved = $service->getPatternFunctionalRulesForRows('TESTDERIVED', 'X', $patterns);

        $this->assertSame([], $resolved, 'Sin pregunta "empty", patternQuestionsToFunctionalRule() nunca genera una regla funcional -- no se crea empty_behavior artificial.');
    }
}
