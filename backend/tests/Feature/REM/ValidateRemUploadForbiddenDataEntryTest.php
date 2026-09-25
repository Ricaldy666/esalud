<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Fase 1: proteccion estructural -- informacion en celdas que la plantilla
 * certificada marca como bloqueadas/no habilitadas.
 * Fase 2: decision funcional recalibrable 'no_se_puede_ingresar_informacion'
 * (vacio = correcto; 0 o cualquier valor = incumplimiento).
 *
 * Todo sintetico (hoja SIM) contra esalud_testing + Storage::fake().
 */
class ValidateRemUploadForbiddenDataEntryTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'SIM';
    private const SECTION = 'A';
    private const FORBIDDEN = FunctionalRuleService::FORBIDDEN_DATA_ENTRY;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    // ─── Fase 1: proteccion estructural ──────────────────────────────────

    public function test_blocked_cell_with_a_new_value_is_detected_as_error(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 20, ['C' => 5, 'D' => 5, 'E' => 3]);

        $results = $this->evaluate($upload, [
            20 => ['C' => $this->formula(), 'D' => [], 'E' => ['blocked' => true]],
        ]);

        $structural = $this->structural($results);
        $this->assertCount(1, $structural);
        $result = $structural->first();
        $this->assertSame('error', $result['severity']);
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Se ingresó información en una celda no habilitada', $result['message']);
        $context = json_decode($result['context'], true);
        $this->assertSame(['E20'], array_column($context['pending_cells'], 'coordinate'));
        $this->assertSame(3, $context['pending_cells'][0]['value']);
    }

    public function test_blocked_cell_that_stays_empty_is_correct(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 20, ['C' => 5, 'D' => 5, 'E' => null]);

        $results = $this->evaluate($upload, [
            20 => ['C' => $this->formula(), 'D' => [], 'E' => ['blocked' => true]],
        ]);

        $this->assertCount(0, $this->structural($results));
    }

    public function test_blocked_cell_keeping_the_template_value_is_correct(): void
    {
        // Mismo caso real que A09/C: la plantilla trae 0 y un texto en celdas bloqueadas.
        $upload = $this->createUpload('A');
        $this->createRow($upload, 51, ['C' => 0, 'D' => 4]);
        $this->createRow($upload, 52, ['C' => '1 a 2', 'D' => 4]);

        $results = $this->evaluate($upload, [
            51 => ['C' => ['blocked' => true, 'template' => 0], 'D' => []],
            52 => ['C' => ['blocked' => true, 'template' => '1 a 2'], 'D' => []],
        ]);

        $this->assertCount(0, $this->structural($results));
    }

    public function test_blocked_cell_changing_the_template_value_is_detected(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 51, ['C' => 7, 'D' => 4]);

        $results = $this->evaluate($upload, [
            51 => ['C' => ['blocked' => true, 'template' => 0], 'D' => []],
        ]);

        $structural = $this->structural($results);
        $this->assertCount(1, $structural);
        $this->assertSame(0, json_decode($structural->first()['context'], true)['pending_cells'][0]['template_value']);
    }

    public function test_formula_cells_never_produce_a_structural_false_positive(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 20, ['C' => 12, 'D' => 12]);

        $results = $this->evaluate($upload, [
            20 => ['C' => $this->formula(), 'D' => []],
        ]);

        $this->assertCount(0, $this->structural($results));
    }

    public function test_cells_without_reliable_cell_data_are_not_judged(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 20, ['C' => 5, 'D' => 5, 'Z' => 9]);

        $results = $this->evaluate($upload, [
            20 => ['C' => $this->formula(), 'D' => []],
        ]);

        $this->assertCount(0, $this->structural($results), 'Z no existe en cell-data: no se inventa la decision.');
    }

    public function test_editable_cells_with_values_are_not_structural_violations(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 20, ['C' => 5, 'D' => 2, 'E' => 3]);

        $results = $this->evaluate($upload, [
            20 => ['C' => $this->formula(), 'D' => [], 'E' => []],
        ]);

        $this->assertCount(0, $results);
    }

    // ─── Fase 2: no_se_puede_ingresar_informacion ───────────────────────

    public function test_forbidden_row_that_stays_empty_is_correct(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => null, 'D' => null, 'E' => null]);
        $this->writeRules(rows: [30 => self::FORBIDDEN]);

        $this->assertCount(0, $this->evaluate($upload, $this->editableRow(30)));
    }

    public function test_forbidden_row_with_zero_is_a_violation(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 0, 'D' => 0, 'E' => null]);
        $this->writeRules(rows: [30 => self::FORBIDDEN]);

        $functional = $this->functional($this->evaluate($upload, $this->editableRow(30)));

        $this->assertCount(1, $functional);
        $this->assertSame('error', $functional->first()['severity'], 'Sin severidad calibrada, el default es error.');
        $this->assertStringContainsString('D30', $functional->first()['message']);
        $this->assertSame(self::FORBIDDEN, json_decode($functional->first()['context'], true)['criterio']['empty_behavior']);
    }

    public function test_forbidden_row_with_any_value_is_a_violation(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 8, 'D' => 5, 'E' => 3]);
        $this->writeRules(rows: [30 => self::FORBIDDEN]);

        $functional = $this->functional($this->evaluate($upload, $this->editableRow(30)));

        $this->assertCount(1, $functional);
        $context = json_decode($functional->first()['context'], true);
        $this->assertSame(['D30', 'E30'], array_column($context['pending_cells'], 'coordinate'));
    }

    /**
     * Regresion carga real #209 (BM18/D fila 61): el 0 SIEMPRE es informacion
     * ingresada, en cualquiera de sus formas; solo null o vacio real se permiten.
     */
    #[DataProvider('zeroLikeValues')]
    public function test_forbidden_detects_every_form_of_zero(mixed $value): void
    {
        $upload = $this->createUpload('BM');
        $this->createRow($upload, 61, ['C' => null, 'D' => $value]);
        $this->writeRules(rows: [61 => self::FORBIDDEN]);

        $functional = $this->functional($this->evaluate($upload, $this->editableRow(61), [], 'BM'));

        $this->assertCount(1, $functional, 'Valor ' . var_export($value, true) . ' debe ser incumplimiento.');
        $context = json_decode($functional->first()['context'], true);
        $this->assertSame(['D61'], array_column($context['pending_cells'], 'coordinate'));
    }

    public static function zeroLikeValues(): array
    {
        return ['entero 0' => [0], 'string "0"' => ['0'], 'float 0.0' => [0.0], 'string "0.0"' => ['0.0']];
    }

    #[DataProvider('emptyLikeValues')]
    public function test_forbidden_allows_null_and_real_empty(mixed $value): void
    {
        $upload = $this->createUpload('BM');
        $this->createRow($upload, 61, ['C' => null, 'D' => $value]);
        $this->writeRules(rows: [61 => self::FORBIDDEN]);

        $this->assertCount(0, $this->evaluate($upload, $this->editableRow(61), [], 'BM'));
    }

    public static function emptyLikeValues(): array
    {
        return ['null' => [null], 'string vacio' => ['']];
    }

    public function test_real_case_bm18_d_rows_60_and_61_are_both_reported(): void
    {
        // Mismo shape que la carga real #209: fila 60 debe_registrar_cero con
        // D vacia; fila 61 no_se_puede_ingresar_informacion con 0.
        $upload = $this->createUpload('BM');
        $this->createRow($upload, 60, ['C' => 7, 'D' => null]);
        $this->createRow($upload, 61, ['C' => 0, 'D' => 0]);
        $this->writeRules(rows: [60 => 'debe_registrar_cero', 61 => self::FORBIDDEN], groups: [1 => ['empty' => 'puede_quedar_vacio']]);

        $cells = [
            60 => ['C' => [], 'D' => []],
            61 => ['C' => [], 'D' => []],
        ];
        $functional = $this->functional($this->evaluate($upload, $cells, [1 => [60, 61]], 'BM'))->keyBy('rule_key');

        $this->assertSame('warning', $functional['f_SIM_60']['severity']);
        $this->assertStringContainsString('D60', $functional['f_SIM_60']['message']);
        $this->assertSame('error', $functional['f_SIM_61']['severity']);
        $this->assertSame(['C61', 'D61'], array_column(json_decode($functional['f_SIM_61']['context'], true)['pending_cells'], 'coordinate'));
    }

    public function test_calibrated_severity_is_respected(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 1, 'D' => 1, 'E' => null]);
        $this->writeRules(groups: [2 => ['empty' => self::FORBIDDEN, 'inconsistency' => 'advertencia']]);

        $results = $this->evaluate($upload, $this->editableRow(30), [2 => [30]]);

        $this->assertSame('warning', $this->functional($results)->first()['severity']);
    }

    public function test_forbidden_works_by_group(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 1, 'D' => 1, 'E' => null]);
        $this->createRow($upload, 31, ['C' => null, 'D' => null, 'E' => null]);
        $this->writeRules(groups: [2 => ['empty' => self::FORBIDDEN]]);

        $results = $this->evaluate($upload, $this->editableRow(30) + $this->editableRow(31), [2 => [30, 31]]);

        $functional = $this->functional($results);
        $this->assertCount(1, $functional, 'Solo la fila del grupo que recibio informacion.');
        $this->assertSame('f_SIM_30', $functional->first()['rule_key']);
    }

    public function test_forbidden_works_by_row(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 2, 'D' => 2, 'E' => null]);
        $this->writeRules(rows: [30 => self::FORBIDDEN]);

        $this->assertSame('f_SIM_30', $this->functional($this->evaluate($upload, $this->editableRow(30)))->first()['rule_key']);
    }

    public function test_explicit_row_decision_prevails_over_the_group(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 2, 'D' => 2, 'E' => null]);
        $this->createRow($upload, 31, ['C' => null, 'D' => null, 'E' => null]);
        // Grupo prohibe; la fila 30 tiene decision propia que lo permite.
        $this->writeRules(rows: [30 => 'puede_quedar_vacio'], groups: [2 => ['empty' => self::FORBIDDEN]]);

        $results = $this->evaluate($upload, $this->editableRow(30) + $this->editableRow(31), [2 => [30, 31]]);

        $this->assertCount(0, $results->where('passed', false));

        // Y al reves: grupo permite vacio, la fila decide que no admite informacion.
        $upload2 = $this->createUpload('A');
        $this->createRow($upload2, 30, ['C' => 2, 'D' => 2, 'E' => null]);
        $this->writeRules(rows: [30 => self::FORBIDDEN], groups: [2 => ['empty' => 'puede_quedar_vacio']]);

        $this->assertCount(1, $this->functional($this->evaluate($upload2, $this->editableRow(30), [2 => [30]])));
    }

    public function test_forbidden_is_never_inherited_to_other_rows_of_the_section(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => null, 'D' => null, 'E' => null]);
        // Fila 31: misma firma estructural, sin decision propia, con datos.
        $this->createRow($upload, 31, ['C' => 9, 'D' => 5, 'E' => 4]);
        $this->writeRules(rows: [30 => self::FORBIDDEN]);

        $results = $this->evaluate($upload, $this->editableRow(30) + $this->editableRow(31));

        $this->assertCount(0, $results, 'La fila 31 no hereda "no se puede ingresar informacion".');
    }

    public function test_explicit_columns_limit_the_forbidden_scope(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 8, 'D' => 5, 'E' => 3]);
        $this->writeRules(rows: [30 => ['empty_behavior' => self::FORBIDDEN, 'columns' => ['e']]]);

        $functional = $this->functional($this->evaluate($upload, $this->editableRow(30)));

        $this->assertCount(1, $functional);
        $context = json_decode($functional->first()['context'], true);
        $this->assertSame(['E30'], array_column($context['pending_cells'], 'coordinate'), 'D queda fuera del alcance declarado.');
    }

    public function test_forbidden_group_decision_resolves_for_serie_bm(): void
    {
        $upload = $this->createUpload('BM');
        $this->createRow($upload, 119, ['C' => null, 'D' => 1, 'E' => null]);
        $this->writeRules(groups: [2 => ['empty' => self::FORBIDDEN]]);

        $results = $this->evaluate($upload, $this->editableRow(119), [2 => [119]], 'BM');

        $this->assertCount(1, $this->functional($results));
    }

    // ─── No regresion ───────────────────────────────────────────────────

    public function test_no_aplica_keeps_skipping_the_row_without_validating(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 9, 'D' => 5, 'E' => 4]);
        $this->writeRules(groups: [2 => ['empty' => 'no_aplica']]);

        $this->assertCount(0, $this->evaluate($upload, $this->editableRow(30), [2 => [30]]));
    }

    public function test_debe_registrar_cero_is_unchanged(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => 0, 'D' => 0, 'E' => null]);
        $this->writeRules(rows: [30 => 'debe_registrar_cero']);

        $functional = $this->functional($this->evaluate($upload, $this->editableRow(30)));

        $this->assertCount(1, $functional);
        $this->assertSame('warning', $functional->first()['severity']);
        $this->assertStringContainsString('E30', $functional->first()['message']);
    }

    public function test_puede_quedar_vacio_is_unchanged(): void
    {
        $upload = $this->createUpload('A');
        $this->createRow($upload, 30, ['C' => null, 'D' => null, 'E' => null]);
        $this->createRow($upload, 31, ['C' => 3, 'D' => 3, 'E' => null]);
        $this->writeRules(rows: [30 => 'puede_quedar_vacio', 31 => 'puede_quedar_vacio']);

        $results = $this->evaluate($upload, $this->editableRow(30) + $this->editableRow(31));

        $this->assertCount(1, $results, 'Solo la traza "aceptada" de la fila vacia.');
        $this->assertTrue($results->first()['passed']);
        $this->assertSame('info', $results->first()['severity']);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function structural(Collection $results): Collection
    {
        return $results->where('rule_type', 'structural_input')->values();
    }

    private function functional(Collection $results): Collection
    {
        return $results->where('rule_type', 'functional_rule')->where('passed', false)->values();
    }

    private function formula(): array
    {
        return ['blocked' => true, 'formula_cell' => true, 'formula' => '=SUM(D{row}:E{row})'];
    }

    /** C = formula total; D y E = celdas de captura editables. */
    private function editableRow(int $row): array
    {
        return [$row => ['C' => $this->formula(), 'D' => [], 'E' => []]];
    }

    /**
     * @param array<int, string|array> $rows   fila => empty_behavior | datos extra de la regla
     * @param array<int, array>        $groups pattern_id => ['empty' => ..., 'inconsistency' => ...]
     */
    private function writeRules(array $rows = [], array $groups = []): void
    {
        $data = ['_questions' => []];
        foreach ($rows as $row => $rule) {
            $rule = is_array($rule) ? $rule : ['empty_behavior' => $rule];
            $data[self::SHEET . '_' . self::SECTION . '_' . $row] = $rule + [
                'rule_key' => '',
                'sheet' => self::SHEET,
                'section' => self::SECTION,
                'row' => $row,
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'status' => 'aprobada',
            ];
        }
        foreach ($groups as $patternId => $answers) {
            foreach ($answers as $suffix => $response) {
                $data['_questions'][self::SHEET . '_' . self::SECTION][] = [
                    'id' => "patron_{$patternId}_{$suffix}",
                    'type' => 'pattern_question',
                    'response' => $response,
                    'pattern_id' => $patternId,
                    'pattern_key' => "pattern_{$patternId}",
                    'review_status' => 'reviewed',
                    'status' => 'answered',
                ];
            }
        }
        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode($data));
    }

    /**
     * @param array<int, array<string, array>> $cells   fila => columna => definicion
     * @param array<int, int[]>                $patterns pattern_id => filas
     */
    private function evaluate(RemUpload $upload, array $cells, array $patterns = [], string $serie = 'A'): Collection
    {
        $matrixService = Mockery::mock(SectionCalibrationMatrixService::class);
        $matrixService->shouldReceive('getPatternsForValidation')
            ->with(self::SHEET, self::SECTION, $serie)
            ->andReturn(array_map(
                fn ($id, $rows) => ['id' => $id, 'key' => "pattern_{$id}", 'rows' => array_map(fn ($r) => ['fila' => $r], $rows)],
                array_keys($patterns),
                array_values($patterns),
            ));
        $matrixService->shouldReceive('forgetSectionCache');

        $job = new ValidateRemUploadJob($upload);
        $method = (new ReflectionClass($job))->getMethod('evaluateFunctionalRules');
        $method->setAccessible(true);

        return $method->invoke($job, $upload, new FunctionalRuleService(), $this->cellData($cells), $matrixService);
    }

    private function createUpload(string $remType): RemUpload
    {
        $center = HealthCenter::create([
            'name' => 'Posta Sintetica',
            'code_deis' => 'TEST_' . uniqid(),
            'type' => 'POSTA',
            'commune' => 'Iquique',
            'is_active' => true,
        ]);

        return RemUpload::create([
            'health_center_id' => $center->id,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 5,
            'rem_type' => $remType,
            'original_filename' => 'sim.xlsm',
            'stored_path' => 'test/sim.xlsm',
            'file_size' => 1,
            'mime_type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'status' => 'processed',
        ]);
    }

    private function createRow(RemUpload $upload, int $row, array $values): RemData
    {
        return RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => self::SHEET,
            'data' => [
                'concept' => 'Fila ' . $row,
                'professional' => '',
                'total' => $values['C'] ?? null,
                'values' => $values,
                'row_number' => $row,
                'section' => self::SHEET,
                'rem_section_code' => self::SECTION,
                'total_column' => 'C',
            ],
        ]);
    }

    private function cellData(array $rows): CellDataStorageService
    {
        return new class($rows) extends CellDataStorageService {
            public function __construct(private array $rows)
            {
            }

            public function getCellForCoordinate(string $sheet, string $section, string $coordinate): ?array
            {
                if (!preg_match('/^([A-Z]+)(\d+)$/', $coordinate, $matches)) {
                    return null;
                }

                return $this->makeCell((int) $matches[2], $matches[1]);
            }

            public function getCellsForRow(string $sheet, string $section, int $row): array
            {
                $cells = [];
                foreach (array_keys($this->rows[$row] ?? []) as $column) {
                    $cells[$column . $row] = $this->makeCell($row, $column);
                }

                return $cells;
            }

            private function makeCell(int $row, string $column): ?array
            {
                if (!array_key_exists($row, $this->rows) || !array_key_exists($column, $this->rows[$row])) {
                    return null;
                }

                $definition = $this->rows[$row][$column];
                $blocked = (bool) ($definition['blocked'] ?? false);
                $formula = isset($definition['formula']) ? str_replace('{row}', (string) $row, $definition['formula']) : null;

                return [
                    'fila' => $row,
                    'columna' => $column,
                    'coordenada' => $column . $row,
                    'formula' => $formula,
                    'es_formula' => (bool) ($definition['formula_cell'] ?? false),
                    'es_editable' => !$blocked,
                    'esta_bloqueada' => $blocked,
                    'valor_bruto' => $definition['template'] ?? null,
                ];
            }
        };
    }
}
