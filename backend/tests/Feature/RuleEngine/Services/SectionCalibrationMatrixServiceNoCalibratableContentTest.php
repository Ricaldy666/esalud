<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\PatternReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cubre SectionCalibrationMatrixService::sectionHasNoCalibratableContent() /
 * findNoCalibratableClosureQuestion() -- hallazgo A32/E1 (2026-08-11):
 * seccion estructuralmente valida (filas reales, encabezados correctos)
 * pero con TODAS sus celdas funcionales bloqueadas y sin formulas, por lo
 * que buildDynamicPatternDefinitions() nunca genera un patron y la UI de
 * calibracion rapida forzaba 6 decisiones funcionales sin evidencia real.
 *
 * Criterio final (aprobado por el usuario): SOLO se permite 'not_calibratable'
 * cuando las 6 condiciones se cumplen a la vez -- estructura disponible y
 * consistente con el cell-data, cell-data disponible, sin advertencias
 * pendientes, sin celdas editables, sin formulas funcionales y sin patrones
 * calibrables. Cualquier duda tecnica (sin escanear, escaneo incompleto,
 * advertencias, patrones existentes) bloquea 'not_calibratable' -- nunca lo
 * habilita por ausencia de evidencia.
 */
class SectionCalibrationMatrixServiceNoCalibratableContentTest extends TestCase
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
            new ColumnRoleResolverService(),
            new PatternReconciliationService(),
        );
    }

    /**
     * @return array{status:string,reason:?string,criteria:array<string,bool>}
     */
    private function evaluate(
        array $sectionData,
        array $cellDataRows,
        bool $hasCellData,
        array $patterns,
        array $warnings,
    ): array {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'sectionHasNoCalibratableContent');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $sectionData, $cellDataRows, $hasCellData, $patterns, $warnings);
    }

    private function findClosure(array $questions): ?array
    {
        $method = new ReflectionMethod(SectionCalibrationMatrixService::class, 'findNoCalibratableClosureQuestion');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $questions);
    }

    /** @return array{sectionData:array,cellDataRows:array} */
    private function lockedTwoRowFixture(): array
    {
        $sectionData = [
            'filaInicioDatos' => 10,
            'filaFinDatos' => 11,
            'fields' => [
                ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false],
                ['letra' => 'B', 'label' => 'SubConcepto', 'esTotal' => false, 'esControlOculto' => false],
                ['letra' => 'C', 'label' => 'Dato', 'esTotal' => false, 'esControlOculto' => false],
            ],
        ];

        $lockedCell = ['es_formula' => false, 'esta_bloqueada' => true, 'valor_bruto' => null];
        $cellDataRows = [
            10 => ['A' => ['es_formula' => false, 'esta_bloqueada' => true, 'valor_bruto' => 'Item 1'], 'B' => $lockedCell, 'C' => $lockedCell],
            11 => ['A' => ['es_formula' => false, 'esta_bloqueada' => true, 'valor_bruto' => 'Item 2'], 'B' => $lockedCell, 'C' => $lockedCell],
        ];

        return compact('sectionData', 'cellDataRows');
    }

    public function test_all_criteria_met_returns_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();

        $result = $this->evaluate($sectionData, $cellDataRows, true, [], []);

        $this->assertSame('not_calibratable', $result['status']);
        $this->assertTrue($result['criteria']['structure_available_and_consistent']);
        $this->assertTrue($result['criteria']['cell_data_available']);
        $this->assertTrue($result['criteria']['no_pending_warnings']);
        $this->assertTrue($result['criteria']['no_editable_cells']);
        $this->assertTrue($result['criteria']['no_functional_formulas']);
        $this->assertTrue($result['criteria']['no_calibratable_patterns']);
    }

    public function test_editable_cell_blocks_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();
        $cellDataRows[11]['C']['esta_bloqueada'] = false;

        $result = $this->evaluate($sectionData, $cellDataRows, true, [], []);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['no_editable_cells']);
        $this->assertStringContainsString('editables', $result['reason']);
    }

    public function test_formula_cell_blocks_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();
        $cellDataRows[10]['C']['es_formula'] = true;

        $result = $this->evaluate($sectionData, $cellDataRows, true, [], []);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['no_functional_formulas']);
    }

    public function test_missing_cell_data_blocks_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();

        $result = $this->evaluate($sectionData, $cellDataRows, false, [], []);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['cell_data_available']);
        $this->assertStringContainsString('no tiene cell-data escaneado', $result['reason']);
    }

    /**
     * Escaneo incompleto / discrepancia estructura vs cell-data: la fila 11
     * existe en la estructura (filaFinDatos=11) pero su columna C nunca fue
     * escaneada (ausente del cell-data, no solo con valor null).
     */
    public function test_incomplete_scan_blocks_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();
        unset($cellDataRows[11]['C']);

        $result = $this->evaluate($sectionData, $cellDataRows, true, [], []);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['structure_available_and_consistent']);
        $this->assertStringContainsString('Escaneo incompleto', $result['reason']);
    }

    public function test_pending_warnings_block_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();

        $result = $this->evaluate($sectionData, $cellDataRows, true, [], ['Advertencia técnica pendiente de revisión.']);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['no_pending_warnings']);
    }

    public function test_existing_patterns_block_not_calibratable(): void
    {
        ['sectionData' => $sectionData, 'cellDataRows' => $cellDataRows] = $this->lockedTwoRowFixture();

        $result = $this->evaluate($sectionData, $cellDataRows, true, [['id' => 1, 'filas' => [10, 11]]], []);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['no_calibratable_patterns']);
    }

    public function test_invalid_row_range_blocks_not_calibratable(): void
    {
        $sectionData = ['filaInicioDatos' => 0, 'filaFinDatos' => 0, 'fields' => []];

        $result = $this->evaluate($sectionData, [], true, [], []);

        $this->assertSame('requires_calibration', $result['status']);
        $this->assertFalse($result['criteria']['structure_available_and_consistent']);
    }

    // ─── findNoCalibratableClosureQuestion() ────────────────────────────

    public function test_finds_no_calibratable_closure_question(): void
    {
        $questions = [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'no_calibrable',
                'review_status' => 'section_reviewed',
                'closure_reason' => 'no_calibratable_data',
            ],
        ];

        $found = $this->findClosure($questions);

        $this->assertNotNull($found);
        $this->assertSame('no_calibrable', $found['response']);
    }

    public function test_normal_review_closure_is_not_a_no_calibratable_closure(): void
    {
        $questions = [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'revisada',
                'review_status' => 'section_reviewed',
            ],
        ];

        $this->assertNull($this->findClosure($questions));
    }

    public function test_no_questions_means_no_closure(): void
    {
        $this->assertNull($this->findClosure([]));
    }

    // ─── Integracion completa via buildPatternMatrix() ──────────────────

    private function dummyFields(): array
    {
        return [
            ['letra' => 'A', 'label' => 'Concepto', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
            ['letra' => 'B', 'label' => 'Dato', 'esTotal' => false, 'esControlOculto' => false, 'reglaDetectada' => null],
        ];
    }

    private function createActiveStructure(string $sheetName, string $sectionCode): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'rem_template_id' => null,
            'version_number' => 1,
            'hash_estructura' => 'hash-no-calibratable-test-' . uniqid(),
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => $sheetName,
                        'sections' => [
                            [
                                'codigo' => $sectionCode,
                                'titulo' => 'SECCION DE PRUEBA',
                                'filaHeader' => 9,
                                'filaInicioDatos' => 10,
                                'filaFinDatos' => 11,
                                'fields' => $this->dummyFields(),
                            ],
                        ],
                    ],
                ],
            ],
            'metadata' => null,
            'source_filename' => 'test.xlsm',
            'status' => 'active',
        ]);
    }

    public function test_build_pattern_matrix_reports_not_calibratable_for_locked_section(): void
    {
        $this->createActiveStructure('TESTSHEET1', 'X');

        $cellData = app(CellDataStorageService::class);
        $locked = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
        $cellData->saveCellData('TESTSHEET1', 'X', [
            'A10' => array_merge($locked, ['valor_bruto' => 'Item 1']),
            'B10' => $locked,
            'A11' => array_merge($locked, ['valor_bruto' => 'Item 2']),
            'B11' => $locked,
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSHEET1', 'X');

        $this->assertSame('not_calibratable', $matrix['calibration_applicability']['status']);
        $this->assertEmpty($matrix['patterns']);
    }

    /**
     * Caso negativo explicito pedido por el usuario: 0 patrones pero sin
     * cell-data escaneado NUNCA debe entrar en 'not_calibratable'.
     */
    public function test_build_pattern_matrix_does_not_mark_not_calibratable_when_never_scanned(): void
    {
        $this->createActiveStructure('TESTSHEET2', 'X');

        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSHEET2', 'X');

        $this->assertSame('requires_calibration', $matrix['calibration_applicability']['status']);
        $this->assertFalse($matrix['calibration_applicability']['criteria']['cell_data_available']);
    }

    public function test_effective_section_reviewed_false_without_closure(): void
    {
        $this->createActiveStructure('TESTSHEET3', 'X');
        $cellData = app(CellDataStorageService::class);
        $locked = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
        $cellData->saveCellData('TESTSHEET3', 'X', [
            'A10' => array_merge($locked, ['valor_bruto' => 'Item 1']),
            'B10' => $locked,
            'A11' => array_merge($locked, ['valor_bruto' => 'Item 2']),
            'B11' => $locked,
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSHEET3', 'X');

        $this->assertSame('not_calibratable', $matrix['calibration_applicability']['status']);
        $this->assertFalse($matrix['reconciliation']['effective_section_reviewed']);
        $this->assertFalse($matrix['reconciliation']['historical_section_reviewed']);
    }

    public function test_effective_section_reviewed_true_after_no_calibratable_closure(): void
    {
        $this->createActiveStructure('TESTSHEET4', 'X');
        $cellData = app(CellDataStorageService::class);
        $locked = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
        $cellData->saveCellData('TESTSHEET4', 'X', [
            'A10' => array_merge($locked, ['valor_bruto' => 'Item 1']),
            'B10' => $locked,
            'A11' => array_merge($locked, ['valor_bruto' => 'Item 2']),
            'B11' => $locked,
        ]);

        app(FunctionalRuleService::class)->saveQuestions('TESTSHEET4', 'X', [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'question' => 'Sección X: sin datos capturables, no requiere calibración',
                'response' => 'no_calibrable',
                'review_status' => 'section_reviewed',
                'closure_reason' => 'no_calibratable_data',
                'reviewed_by' => 'Tester',
                'reviewed_at' => now()->toIso8601String(),
            ],
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSHEET4', 'X');

        $this->assertSame('not_calibratable', $matrix['calibration_applicability']['status']);
        $this->assertTrue($matrix['reconciliation']['effective_section_reviewed']);
        $this->assertTrue($matrix['reconciliation']['historical_section_reviewed']);

        $closureQuestion = collect($matrix['questions'])->firstWhere('response', 'no_calibrable');
        $this->assertNotNull($closureQuestion);
        $this->assertSame('no_calibratable_data', $closureQuestion['closure_reason']);
    }

    /**
     * Si la seccion recupera contenido calibrable (ej. una celda ahora
     * editable) despues de un cierre 'no_calibrable' previo, el cierre
     * historico se conserva como antecedente pero effective_section_reviewed
     * debe volver a false -- la seccion vuelve a requerir revision.
     */
    public function test_effective_section_reviewed_reverts_when_content_becomes_calibratable(): void
    {
        $this->createActiveStructure('TESTSHEET5', 'X');
        $cellData = app(CellDataStorageService::class);
        $locked = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
        $cellData->saveCellData('TESTSHEET5', 'X', [
            'A10' => array_merge($locked, ['valor_bruto' => 'Item 1']),
            'B10' => $locked,
            'A11' => array_merge($locked, ['valor_bruto' => 'Item 2']),
            'B11' => $locked,
        ]);

        app(FunctionalRuleService::class)->saveQuestions('TESTSHEET5', 'X', [
            [
                'id' => 'section_review',
                'type' => 'section_review',
                'response' => 'no_calibrable',
                'review_status' => 'section_reviewed',
                'closure_reason' => 'no_calibratable_data',
                'reviewed_by' => 'Tester',
                'reviewed_at' => now()->toIso8601String(),
            ],
        ]);

        // El cell-data cambia: B11 ahora es editable (capturable) -- ej. un
        // futuro re-escaneo tras una correccion de plantilla.
        $editable = ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
        $cellData->saveCellData('TESTSHEET5', 'X', [
            'A10' => array_merge($locked, ['valor_bruto' => 'Item 1']),
            'B10' => $locked,
            'A11' => array_merge($locked, ['valor_bruto' => 'Item 2']),
            'B11' => $editable,
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSHEET5', 'X');

        $this->assertSame('requires_calibration', $matrix['calibration_applicability']['status']);
        $this->assertFalse($matrix['calibration_applicability']['criteria']['no_editable_cells']);
        $this->assertTrue($matrix['reconciliation']['historical_section_reviewed'], 'El cierre historico debe conservarse como antecedente.');
        $this->assertFalse($matrix['reconciliation']['effective_section_reviewed'], 'La seccion debe volver a requerir revision.');
    }

    /**
     * Regresion: una seccion con patrones reales (ej. captura directa) no
     * debe verse afectada por este mecanismo nuevo -- calibration_applicability
     * debe seguir en 'requires_calibration' y effective_section_reviewed debe
     * calcularse exactamente igual que antes (via PatternReconciliationService).
     */
    public function test_section_with_real_editable_data_is_unaffected(): void
    {
        $this->createActiveStructure('TESTSHEET6', 'X');
        $cellData = app(CellDataStorageService::class);
        $editable = ['valor_bruto' => null, 'es_editable' => true, 'esta_bloqueada' => false, 'es_formula' => false, 'formula' => null];
        $locked = ['valor_bruto' => null, 'es_editable' => false, 'esta_bloqueada' => true, 'es_formula' => false, 'formula' => null];
        $cellData->saveCellData('TESTSHEET6', 'X', [
            'A10' => array_merge($locked, ['valor_bruto' => 'Item 1']),
            'B10' => array_merge($editable, ['valor_bruto' => 5]),
            'A11' => array_merge($locked, ['valor_bruto' => 'Item 2']),
            'B11' => array_merge($editable, ['valor_bruto' => 8]),
        ]);

        $matrix = app(SectionCalibrationMatrixService::class)->buildPatternMatrix('TESTSHEET6', 'X');

        $this->assertSame('requires_calibration', $matrix['calibration_applicability']['status']);
        $this->assertNotEmpty($matrix['patterns'], 'Una seccion de captura directa real debe seguir generando al menos un patron.');
        $this->assertFalse($matrix['reconciliation']['effective_section_reviewed']);
    }
}
