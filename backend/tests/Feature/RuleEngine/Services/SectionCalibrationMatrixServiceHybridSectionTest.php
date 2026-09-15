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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use App\Models\User;

/**
 * BM-11.25 (BM1124_BM18A_ENGINE_GAP_DETECTED): certifica el soporte
 * GENERICO (sin hardcodes de BM/BM18/seccion A/filas 13-23/32-33/nombres de
 * conceptos) para secciones HIBRIDAS -- una seccion puede contener
 * simultaneamente un subconjunto de filas 100% derivado de otra hoja
 * (derived_auto_fill, ver SectionCalibrationMatrixServiceDerivedAutoFillTest,
 * BM-11.15) Y un subconjunto de filas con captura funcional real (formula/
 * direct_input). Caso real que motivo esta fase: BM18/A -- bloque de
 * examenes de laboratorio/imagenologia (filas 13-23, 100% derivado de
 * BM18A) + bloque de ecografias obstetricas/ginecologicas (filas 24-37,
 * captura real en columna F).
 *
 * Antes de este fix, buildDerivedAutoFillPattern() solo se evaluaba cuando
 * `empty($patterns)` -- si CUALQUIER parte de la seccion producia un patron
 * normal, el subconjunto derivado desaparecia en silencio (invisible en
 * "Filas analizadas", sin patron, sin advertencia). Ahora evalua sobre las
 * filas que quedaron fuera de TODO patron normal ($leftoverRows), sin tocar
 * el contrato de evidencia (0 reglas tecnicas => null; una sola celda
 * editable en el subconjunto dado => null).
 *
 * 100% aislado: Storage::fake('local'), RefreshDatabase (esalud_testing),
 * fixtures sinteticas -- nunca estructura BM real (72), reglas BM reales,
 * ni reglas-funcionales.json real.
 */
class SectionCalibrationMatrixServiceHybridSectionTest extends TestCase
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

    private function createStructureWithRules(string $serie, array $ruleConfigs): RemTemplateStructure
    {
        $structure = RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => $serie,
            'hash_estructura' => 'test_hash_hybrid_' . uniqid(),
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'status' => 'active',
            'source_filename' => 'test.xlsm',
        ]);

        foreach ($ruleConfigs as $i => $definition) {
            $rule = Rule::create([
                'rule_key' => "test_hybrid_rule_{$i}_" . uniqid(),
                'rule_type' => $definition['rule_type'],
                'source' => 'excel_formula',
                'name' => 'Test hybrid rule',
                'description' => 'Fixture BM-11.25',
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

    // ── CASO C + D: seccion hibrida -- bloque derivado (10-11) + bloque
    // normal editable (12-13) coexisten, ambos como patrones separados, sin
    // solapamiento de filas ──────────────────────────────────────────────

    public function test_hybrid_section_produces_both_normal_and_derived_patterns_with_disjoint_rows(): void
    {
        $sheet = 'TESTHYBRID';
        $section = 'H';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 13,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [];
        // Bloque derivado: filas 10-11, B=SUM(C+D) [2 componentes], C/D
        // SIEMPRE formula (referencias tipo cross-hoja) -- nunca editables
        // en NINGUNA fila de toda la seccion con ESTA combinacion exacta de
        // dependencias.
        foreach ([10, 11] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Derivado {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row}:D{$row})", ["C{$row}", "D{$row}"]),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ];
        }
        // Bloque normal: filas 12-13, B=SUM(C) [1 componente distinto],
        // C genuinamente editable -- combinacion de dependencias DIFERENTE
        // a la del bloque derivado, para que nunca compartan evidencia.
        foreach ([12, 13] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Normal {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row})", ["C{$row}"]),
                'C' => $this->editableCell(),
                'D' => $this->labelCell(''),
            ];
        }
        $this->seedCellData($sheet, $section, $cellDataRows);

        $this->createStructureWithRules('BMHYBRID1', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'B', 'row_range' => ['from' => 10, 'to' => 11], 'rule_logic' => 'Suma(C + D) = Columna B'],
            ],
        ]);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture hibrida', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMHYBRID1');

        $this->assertSame('hybrid', $matrix['capture_mode'], 'Seccion con patron normal Y patron derivado debe reportar capture_mode=hybrid, no derived_auto_fill ni standard.');
        $this->assertNotNull($matrix['capture_mode_reason']);
        $this->assertCount(2, $matrix['patterns'], 'Deben existir EXACTAMENTE 2 patrones: uno normal y uno derived_auto_fill.');

        $normalPattern = collect($matrix['patterns'])->firstWhere('mode', '!=', 'derived_auto_fill');
        $derivedPattern = collect($matrix['patterns'])->firstWhere('mode', 'derived_auto_fill');

        $this->assertNotNull($normalPattern, 'El bloque normal (12-13) debe seguir produciendo su propio patron, sin regresion.');
        $this->assertNotNull($derivedPattern, 'El bloque derivado (10-11) ya NO debe desaparecer solo porque el bloque normal existe.');

        $this->assertEqualsCanonicalizing([12, 13], $normalPattern['filas']);
        $this->assertEqualsCanonicalizing([10, 11], $derivedPattern['filas']);

        // CASO D: ninguna fila puede aparecer en ambos patrones a la vez.
        $this->assertEmpty(
            array_intersect($normalPattern['filas'], $derivedPattern['filas']),
            'Una fila NUNCA debe aparecer simultaneamente como normal y derived.'
        );

        // IDs distintos, sin colision.
        $this->assertNotSame($normalPattern['id'], $derivedPattern['id']);

        // "Filas analizadas" (patternRowsText en frontend) se arma con la
        // union de filas de TODOS los patrones -- confirmar aqui que la
        // union real cubre las 4 filas de la seccion, ninguna perdida.
        $allPatternRows = array_merge(...array_map(fn($p) => $p['filas'], $matrix['patterns']));
        sort($allPatternRows);
        $this->assertSame([10, 11, 12, 13], $allPatternRows, 'Ninguna fila aplicable debe desaparecer silenciosamente.');
    }

    // ── CASO G (contexto hibrido): una fila con una celda editable real
    // JAMAS puede terminar representada dentro de un patron
    // derived_auto_fill -- fail closed ──────────────────────────────────────
    //
    // Hallazgo durante la implementacion: cuando la fila con la celda
    // editable ADEMAS tiene evidencia suficiente para calificar por si sola
    // como patron normal (getEditableInputColumnsForRow() la detecta), el
    // sistema la separa en su PROPIO patron normal (mode=direct_input) en
    // vez de dejarla mezclada en el intento de patron derivado -- un
    // resultado MAS seguro que un veto de seccion completa, porque nunca
    // representa una fila genuinamente editable como "sin captura". Esta
    // prueba certifica esa propiedad: la fila 11 (editable) NUNCA aparece
    // en el patron derivado, sin importar en cual patron normal termine.

    public function test_hybrid_row_with_editable_cell_is_never_represented_as_derived(): void
    {
        $sheet = 'TESTHYBRIDGUARD';
        $section = 'G';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 13,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [
            10 => [
                'A' => $this->labelCell('Derivado 10'),
                'B' => $this->blockedFormulaCell('=SUM(C10:D10)', ['C10', 'D10']),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ],
            // Fila 11: misma combinacion de dependencias [C,D] que la fila
            // 10 (candidata natural al mismo bloque derivado), pero C11 es
            // editable real -- rompe por si sola el requisito "0 celdas
            // editables".
            11 => [
                'A' => $this->labelCell('Fila con celda editable'),
                'B' => $this->blockedFormulaCell('=SUM(C11:D11)', ['C11', 'D11']),
                'C' => $this->editableCell(),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E2', []),
            ],
            12 => [
                'A' => $this->labelCell('Normal 12'),
                'B' => $this->blockedFormulaCell('=SUM(C12)', ['C12']),
                'C' => $this->editableCell(),
                'D' => $this->labelCell(''),
            ],
            13 => [
                'A' => $this->labelCell('Normal 13'),
                'B' => $this->blockedFormulaCell('=SUM(C13)', ['C13']),
                'C' => $this->editableCell(),
                'D' => $this->labelCell(''),
            ],
        ];
        $this->seedCellData($sheet, $section, $cellDataRows);

        $this->createStructureWithRules('BMHYBRID2', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'B', 'row_range' => ['from' => 10, 'to' => 11], 'rule_logic' => 'Suma(C + D) = Columna B'],
            ],
        ]);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture guard hibrida', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMHYBRID2');

        $derivedPatterns = collect($matrix['patterns'])->where('mode', 'derived_auto_fill');
        $normalPatterns = collect($matrix['patterns'])->where('mode', '!=', 'derived_auto_fill');

        // Union de TODAS las filas de patrones derivados -- fila 11 NUNCA
        // debe aparecer aqui, bajo ninguna circunstancia.
        $derivedRows = $derivedPatterns->flatMap(fn($p) => $p['filas'])->values()->all();
        $this->assertNotContains(11, $derivedRows, 'Una fila con una celda editable real jamas puede representarse como derived_auto_fill.');

        // La fila 11 SI debe seguir siendo capturable -- aparece en algun
        // patron normal (sola o agrupada), nunca perdida.
        $normalRows = $normalPatterns->flatMap(fn($p) => $p['filas'])->values()->all();
        $this->assertContains(11, $normalRows, 'La fila con celda editable real debe seguir siendo representada como captura funcional normal.');

        // Las filas 12-13 (bloque normal original) siguen intactas, sin
        // regresion.
        $this->assertTrue(collect($normalPatterns)->contains(fn($p) => $p['filas'] === [12, 13] || (in_array(12, $p['filas']) && in_array(13, $p['filas']))));

        // Ninguna fila se solapa entre derivado y normal.
        $this->assertEmpty(array_intersect($derivedRows, $normalRows));
    }

    // ── CASO H (contexto hibrido): subconjunto leftover con formulas reales
    // pero SIN ninguna regla tecnica que las respalde -- NO derived ─────────

    public function test_hybrid_leftover_without_technical_rule_is_never_classified_derived(): void
    {
        $sheet = 'TESTHYBRIDNORULE';
        $section = 'N';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 13,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [];
        foreach ([10, 11] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Derivado {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row}:D{$row})", ["C{$row}", "D{$row}"]),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ];
        }
        foreach ([12, 13] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Normal {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row})", ["C{$row}"]),
                'C' => $this->editableCell(),
                'D' => $this->labelCell(''),
            ];
        }
        $this->seedCellData($sheet, $section, $cellDataRows);

        // Sin ninguna regla tecnica para el rango 10-11 -- el bloque
        // derivado carece de evidencia certificada.
        $this->createStructureWithRules('BMHYBRID3', []);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture sin regla hibrida', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMHYBRID3');

        $normalPattern = collect($matrix['patterns'])->firstWhere('mode', '!=', 'derived_auto_fill');
        $this->assertNotNull($normalPattern);
        $this->assertEqualsCanonicalizing([12, 13], $normalPattern['filas']);

        $derivedPattern = collect($matrix['patterns'])->firstWhere('mode', 'derived_auto_fill');
        $this->assertNull($derivedPattern, 'Sin reglas tecnicas reales, el subconjunto leftover NUNCA puede clasificarse derived, aunque sea 100% formula.');
        $this->assertNotSame('hybrid', $matrix['capture_mode']);
    }

    // ── CASO F: consolidacion vertical NO consecutiva -- Dtotal =
    // SUM(D10+D12), saltando filas intermedias de la misma columna ─────────

    public function test_non_consecutive_vertical_consolidation_is_detected_via_same_column_previous_rows(): void
    {
        $sheet = 'TESTVERTNC';
        $section = 'V';
        $labels = ['A' => 'Concepto', 'C' => 'Profesional', 'D' => 'TOTAL', 'F' => 'Captura'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 14,
            'fields' => $this->fieldsWithTotals($labels, ['D']),
        ];

        $cellDataRows = [
            // Filas fuente reales -- 10/12 (ej. "Medico"), 11/13 (ej.
            // "Matrona") -- D=SUM(F), F editable.
            10 => ['A' => $this->labelCell('Item 1'), 'C' => $this->labelCell('Medico/a'), 'D' => $this->blockedFormulaCell('=SUM(F10)', ['F10']), 'F' => $this->editableCell()],
            11 => ['A' => $this->labelCell(''), 'C' => $this->labelCell('Matron/a'), 'D' => $this->blockedFormulaCell('=SUM(F11)', ['F11']), 'F' => $this->editableCell()],
            12 => ['A' => $this->labelCell('Item 2'), 'C' => $this->labelCell('Medico/a'), 'D' => $this->blockedFormulaCell('=SUM(F12)', ['F12']), 'F' => $this->editableCell()],
            13 => ['A' => $this->labelCell(''), 'C' => $this->labelCell('Matron/a'), 'D' => $this->blockedFormulaCell('=SUM(F13)', ['F13']), 'F' => $this->editableCell()],
            // Fila TOTAL por profesional, NO consecutiva -- solo suma las
            // filas de "Medico/a" (10, 12), saltando las de "Matrona" (11,
            // 13). Misma columna D, mismas filas ANTERIORES -- debe
            // detectarse igual que el caso consecutivo ya certificado
            // (Caso E, SectionCalibrationMatrixServiceDerivedAutoFillTest).
            14 => ['A' => $this->labelCell('Total Medico/a'), 'C' => $this->labelCell(''), 'D' => $this->blockedFormulaCell('=SUM(D10+D12)', ['D10', 'D12']), 'F' => $this->labelCell('')],
        ];
        $this->seedCellData($sheet, $section, $cellDataRows);

        $this->createStructureWithRules('BMVERTNC', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'D', 'row_range' => ['from' => 10, 'to' => 13], 'rule_logic' => 'Suma(F) = Columna D'],
            ],
        ]);

        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture vertical no consecutiva', ...$sectionData]],
        ]]]);

        $matrix = $service->buildPatternMatrix($sheet, $section, 'BMVERTNC');

        $this->assertTrue(
            $matrix['has_vertical_consolidation'],
            'D14=SUM(D10+D12) (misma columna, filas ANTERIORES, no necesariamente consecutivas) debe reconocerse como consolidacion vertical real.'
        );
        $this->assertSame([14], $matrix['vertical_consolidation_rows']);

        // La fila TOTAL nunca debe incluirse como fila de captura funcional
        // en NINGUN patron (normal ni derivado).
        $allPatternRows = array_merge(...array_map(fn($p) => $p['filas'], $matrix['patterns']));
        $this->assertNotContains(14, $allPatternRows, 'La fila TOTAL (consolidacion vertical no consecutiva) nunca debe aparecer como fila de patron.');

        // Las 4 filas fuente (10-13) deben seguir apareciendo en algun
        // patron real (captura funcional normal).
        sort($allPatternRows);
        $this->assertSame([10, 11, 12, 13], $allPatternRows);
    }

    // ── CASO I (parcial, capa de persistencia): payload de seccion hibrida
    // -- dos pattern_id distintos en la MISMA seccion, uno derived (solo
    // logic_correct) y otro normal (empty/severity/etc), NUNCA se mezclan ──

    public function test_hybrid_section_payload_keeps_derived_and_normal_pattern_answers_isolated(): void
    {
        $service = new FunctionalRuleService();
        $service->saveQuestions('TESTHYBRID', 'H', [
            // Patron 1 (derivado): SOLO logic_correct + formula_confirmation.
            [
                'id' => 'patron_1_logic_correct',
                'type' => 'pattern_question',
                'question' => 'Confirmación de la lógica automática detectada',
                'response' => 'si',
                'pattern_id' => 1,
                'pattern_key' => 'pattern_1',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            // Patron 2 (normal): empty/all_est/exceptions/inconsistency completos.
            [
                'id' => 'patron_2_empty',
                'type' => 'pattern_question',
                'question' => 'Si no existen datos, debe registrarse 0 o puede quedar vacío',
                'response' => 'puede_quedar_vacio',
                'pattern_id' => 2,
                'pattern_key' => 'pattern_2',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_2_all_est',
                'type' => 'pattern_question',
                'question' => 'Aplicabilidad a establecimientos que reportan la hoja',
                'response' => 'si',
                'pattern_id' => 2,
                'pattern_key' => 'pattern_2',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_2_exceptions',
                'type' => 'pattern_question',
                'question' => 'Excepciones por establecimiento o tipo de establecimiento',
                'response' => 'no',
                'pattern_id' => 2,
                'pattern_key' => 'pattern_2',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
            [
                'id' => 'patron_2_inconsistency',
                'type' => 'pattern_question',
                'question' => 'Clasificación funcional de la inconsistencia',
                'response' => 'advertencia',
                'pattern_id' => 2,
                'pattern_key' => 'pattern_2',
                'review_status' => 'reviewed',
                'status' => 'answered',
            ],
        ], 'BMHYBRID1');

        // Patron 1 (derivado): getPatternFunctionalRulesForRows() NUNCA debe
        // producir empty_behavior -- no hay pregunta 'empty' para el
        // pattern_id=1.
        $derivedPatterns = [['id' => 1, 'key' => 'pattern_1', 'rows' => [['fila' => 10], ['fila' => 11]]]];
        $resolvedDerived = $service->getPatternFunctionalRulesForRows('TESTHYBRID', 'H', $derivedPatterns);
        $this->assertSame([], $resolvedDerived, 'El patron derivado no debe generar empty_behavior -- carece de pregunta empty por diseño.');

        // Patron 2 (normal): SI debe resolver empty_behavior real a partir
        // de sus propias respuestas -- sin heredar ni mezclarse con las del
        // patron 1.
        $normalPatterns = [['id' => 2, 'key' => 'pattern_2', 'rows' => [['fila' => 12], ['fila' => 13]]]];
        $resolvedNormal = $service->getPatternFunctionalRulesForRows('TESTHYBRID', 'H', $normalPatterns);
        $this->assertArrayHasKey(12, $resolvedNormal);
        $this->assertArrayHasKey(13, $resolvedNormal);
        $this->assertSame('puede_quedar_vacio', $resolvedNormal[12]['empty_behavior']);
        $this->assertSame('puede_quedar_vacio', $resolvedNormal[13]['empty_behavior']);
        $this->assertSame([], $resolvedNormal[12]['included_health_centers']);
        $this->assertSame([], $resolvedNormal[12]['excluded_health_centers']);
    }

    // ── CASO J (contrato de datos): filas derivadas en la respuesta de
    // row-functional-decisions deben traer su propio capture_mode, distinto
    // del de las filas normales en la MISMA seccion hibrida ─────────────────

    public function test_hybrid_section_row_functional_decisions_reports_capture_mode_per_row(): void
    {
        Role::create(['name' => 'Superadmin']);
        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');
        Sanctum::actingAs($admin);

        $sheet = 'TESTHYBRIDROWS';
        $section = 'R';
        $labels = ['A' => 'Concepto', 'B' => 'TOTAL', 'C' => 'Origen 1', 'D' => 'Origen 2'];
        $sectionData = [
            'filaHeader' => 9,
            'filaInicioDatos' => 10,
            'filaFinDatos' => 13,
            'fields' => $this->fieldsWithTotals($labels, ['B', 'C', 'D']),
        ];

        $cellDataRows = [];
        foreach ([10, 11] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Derivado {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row}:D{$row})", ["C{$row}", "D{$row}"]),
                'C' => $this->blockedFormulaCell('=OTRAHOJA!D1', []),
                'D' => $this->blockedFormulaCell('=OTRAHOJA!E1', []),
            ];
        }
        foreach ([12, 13] as $row) {
            $cellDataRows[$row] = [
                'A' => $this->labelCell("Normal {$row}"),
                'B' => $this->blockedFormulaCell("=SUM(C{$row})", ["C{$row}"]),
                'C' => $this->editableCell(),
                'D' => $this->labelCell(''),
            ];
        }
        $this->seedCellData($sheet, $section, $cellDataRows);

        // La ruta real restringe {serie} a MetadataExtractorService::TIPOS_REM
        // (A/BM/BS/D/P) -- 'BM' es seguro aqui porque esalud_testing usa
        // RefreshDatabase (aislado, sin ninguna estructura BM real presente).
        $this->createStructureWithRules('BM', [
            [
                'rule_type' => 'sum_equals',
                'config' => ['sheet' => $sheet, 'section' => $section, 'column' => 'B', 'row_range' => ['from' => 10, 'to' => 11], 'rule_logic' => 'Suma(C + D) = Columna B'],
            ],
        ]);

        // seedStructureData() es un override de INSTANCIA (test-only, ver
        // SectionCalibrationMatrixService::seedStructureData()) -- para que
        // la resolucion real del contenedor (usada por CatalogController en
        // la request HTTP de abajo) reciba la MISMA instancia ya sembrada,
        // se fuerza explicitamente como singleton solo para este test.
        $service = $this->service();
        $service->seedStructureData(['forms' => [[
            'sheetName' => $sheet,
            'sections' => [['codigo' => $section, 'titulo' => 'Fixture row-decisions hibrida', ...$sectionData]],
        ]]]);
        $this->app->instance(SectionCalibrationMatrixService::class, $service);

        $response = $this->getJson("/api/v1/rule-engine/catalog/BM/{$sheet}/sections/{$section}/row-functional-decisions");

        $response->assertOk();
        $rows = collect($response->json('data.rows'))->keyBy('row');

        $this->assertSame('derived_auto_fill', $rows[10]['capture_mode'] ?? null, 'Fila del bloque derivado debe reportar capture_mode=derived_auto_fill.');
        $this->assertSame('derived_auto_fill', $rows[11]['capture_mode'] ?? null);
        $this->assertNotSame('derived_auto_fill', $rows[12]['capture_mode'] ?? null, 'Fila del bloque normal NUNCA debe heredar el capture_mode del bloque derivado de la misma seccion.');
        $this->assertNotSame('derived_auto_fill', $rows[13]['capture_mode'] ?? null);
    }
}
