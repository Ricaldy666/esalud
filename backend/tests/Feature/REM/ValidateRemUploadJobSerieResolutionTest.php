<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regresion del bug detectado en la prueba real BM del 2026-09-25 (upload
 * #202): ValidateRemUploadJob llamaba getPatternsForValidation($sheet,
 * $section) sin serie, cayendo en el default 'A'. Para cualquier otra serie
 * la hoja no existe en la estructura activa de Serie A, se devolvian cero
 * patrones y las decisiones por patron (incluido no_aplica) nunca llegaban
 * al validador -- una fila separadora no_aplica heredaba debe_registrar_cero
 * de otra fila de la seccion.
 *
 * Todo sintetico (hoja BM18SIM) contra esalud_testing + Storage::fake().
 */
class ValidateRemUploadJobSerieResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'BM18SIM';
    private const SECTION = 'A';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_matrix_service_only_finds_a_non_a_sheet_when_its_serie_is_passed(): void
    {
        $this->createActiveStructure('A', 'A01SIM');
        $this->createActiveStructure('BM', self::SHEET);

        $matrixService = app(SectionCalibrationMatrixService::class);

        $withDefaultSerie = $matrixService->buildMatrix(self::SHEET, self::SECTION);
        $withRealSerie = $matrixService->buildMatrix(self::SHEET, self::SECTION, 'BM');

        $this->assertSame('not_found', $withDefaultSerie['section']['status'], 'Sin serie se busca en Serie A y la hoja BM no existe ahi.');
        $this->assertNotSame('not_found', $withRealSerie['section']['status'], 'Con la serie real la hoja BM se encuentra en su propia estructura.');
        $this->assertSame([], $matrixService->getPatternsForValidation(self::SHEET, self::SECTION));
    }

    public function test_non_a_upload_resolves_patterns_with_its_own_serie(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload('BM');
        $this->createRemDataRow($upload, 13, ['C' => 0, 'D' => 0, 'E' => 0, 'F' => 0]);
        $this->writeCalibration();

        $matrixService = $this->patternMatrix('BM');

        $this->invokeEvaluateFunctionalRules($upload, new FunctionalRuleService(), $this->cellData([13]), $matrixService);

        // La expectativa ->with(..., 'BM')->once() del mock es la asercion:
        // una llamada con otra serie (o sin ella) hace fallar el test.
        $this->addToAssertionCount(1);
    }

    public function test_no_aplica_row_does_not_inherit_debe_registrar_cero_in_non_a_serie(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload('BM');
        $this->createRemDataRow($upload, 13, ['C' => 0, 'D' => 0, 'E' => 0, 'F' => 0]);
        $this->createRemDataRow($upload, 119, ['C' => null, 'D' => null, 'E' => null, 'F' => null]);
        $this->writeCalibration();

        $results = $this->invokeEvaluateFunctionalRules(
            $upload,
            new FunctionalRuleService(),
            $this->cellData([13, 119]),
            $this->patternMatrix('BM'),
        );

        $row119 = $results->filter(fn (array $r) => $r['rule_key'] === 'f_' . self::SHEET . '_119');
        $this->assertCount(0, $row119, 'La fila no_aplica no debe heredar debe_registrar_cero de la fila 13.');
        $this->assertCount(0, $results->filter(fn (array $r) => $r['passed'] === false));
    }

    public function test_without_pattern_resolution_the_no_aplica_row_inherits_the_section_rule(): void
    {
        // Caracteriza el mecanismo del bug: si los patrones no se resuelven
        // (lo que pasaba antes al consultar la serie equivocada), la fila 119
        // hereda la regla por seccion de la fila 13 y falla como error.
        Storage::fake('local');
        $upload = $this->createUpload('BM');
        $this->createRemDataRow($upload, 13, ['C' => 0, 'D' => 0, 'E' => 0, 'F' => 0]);
        $this->createRemDataRow($upload, 119, ['C' => null, 'D' => null, 'E' => null, 'F' => null]);
        $this->writeCalibration();

        $matrixService = Mockery::mock(SectionCalibrationMatrixService::class);
        $matrixService->shouldReceive('getPatternsForValidation')->once()->andReturn([]);
        $matrixService->shouldReceive('forgetSectionCache')->once();

        $results = $this->invokeEvaluateFunctionalRules(
            $upload,
            new FunctionalRuleService(),
            $this->cellData([13, 119]),
            $matrixService,
        );

        $this->assertCount(1, $results);
        $this->assertSame('f_' . self::SHEET . '_119', $results->first()['rule_key']);
        $this->assertSame('error', $results->first()['severity']);
    }

    public function test_serie_a_upload_still_resolves_patterns_with_serie_a(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload('A');
        $this->createRemDataRow($upload, 13, ['C' => 0, 'D' => 0, 'E' => 0, 'F' => 0]);
        $this->writeCalibration();

        $this->invokeEvaluateFunctionalRules($upload, new FunctionalRuleService(), $this->cellData([13]), $this->patternMatrix('A'));

        $this->addToAssertionCount(1);
    }

    public function test_rem_type_is_normalized_before_resolving_patterns(): void
    {
        Storage::fake('local');
        $upload = $this->createUpload(' bm ');
        $this->createRemDataRow($upload, 13, ['C' => 0, 'D' => 0, 'E' => 0, 'F' => 0]);
        $this->writeCalibration();

        $this->invokeEvaluateFunctionalRules($upload, new FunctionalRuleService(), $this->cellData([13]), $this->patternMatrix('BM'));

        $this->addToAssertionCount(1);
    }

    private function writeCalibration(): void
    {
        $key = self::SHEET . '_' . self::SECTION;

        Storage::disk('local')->put('certificacion/reglas-funcionales.json', json_encode([
            $key . '_13' => [
                'rule_key' => '',
                'sheet' => self::SHEET,
                'section' => self::SECTION,
                'row' => 13,
                'empty_behavior' => 'debe_registrar_cero',
                'included_health_centers' => [],
                'excluded_health_centers' => [],
                'status' => 'aprobada',
            ],
            '_questions' => [
                $key => [
                    [
                        'id' => 'patron_2_logic_correct',
                        'type' => 'pattern_question',
                        'response' => 'no',
                        'pattern_id' => 2,
                        'pattern_key' => 'pattern_2',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                    [
                        'id' => 'patron_2_empty',
                        'type' => 'pattern_question',
                        'response' => 'no_aplica',
                        'pattern_id' => 2,
                        'pattern_key' => 'pattern_2',
                        'review_status' => 'reviewed',
                        'status' => 'answered',
                    ],
                ],
            ],
        ]));
    }

    private function patternMatrix(string $expectedSerie): SectionCalibrationMatrixService
    {
        $matrixService = Mockery::mock(SectionCalibrationMatrixService::class);
        $matrixService->shouldReceive('getPatternsForValidation')
            ->with(self::SHEET, self::SECTION, $expectedSerie)
            ->once()
            ->andReturn([
                ['id' => 2, 'key' => 'pattern_2', 'rows' => [['fila' => 119]]],
            ]);
        $matrixService->shouldReceive('forgetSectionCache')
            ->with(self::SHEET, self::SECTION)
            ->once();

        return $matrixService;
    }

    private function createActiveStructure(string $serie, string $sheet): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => $serie,
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => [[
                'sheetName' => $sheet,
                'sections' => [[
                    'codigo' => self::SECTION,
                    'filaHeader' => 11,
                    'filaInicioDatos' => 13,
                    'filaFinDatos' => 119,
                    'fields' => [
                        ['letra' => 'A', 'header' => 'CODIGO'],
                        ['letra' => 'C', 'header' => 'TOTAL', 'esTotal' => true],
                        ['letra' => 'D', 'header' => 'SAPU/SAR/SUR'],
                    ],
                ]],
            ]]],
            'hash_estructura' => 'hash_serie_resolution_' . $serie . '_' . uniqid(),
            'status' => 'active',
        ]);
    }

    private function invokeEvaluateFunctionalRules(
        RemUpload $upload,
        FunctionalRuleService $functionalRuleService,
        CellDataStorageService $cellDataStorage,
        ?SectionCalibrationMatrixService $matrixService,
    ) {
        $job = new ValidateRemUploadJob($upload);
        $method = (new ReflectionClass($job))->getMethod('evaluateFunctionalRules');
        $method->setAccessible(true);

        return $method->invoke($job, $upload, $functionalRuleService, $cellDataStorage, $matrixService);
    }

    private function createUpload(string $remType): RemUpload
    {
        $center = HealthCenter::create([
            'name' => 'Posta Sintetica Serie',
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
            'original_filename' => 'serie-resolution.xlsm',
            'stored_path' => 'test/serie-resolution.xlsm',
            'file_size' => 1,
            'mime_type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'status' => 'processed',
        ]);
    }

    private function createRemDataRow(RemUpload $upload, int $row, array $values): RemData
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

    /**
     * Mismas celdas para todas las filas indicadas: C = formula bloqueada,
     * D/E/F editables (igual que la fila separadora 119 real de BM18A/A).
     */
    private function cellData(array $rows): CellDataStorageService
    {
        $definition = [];
        foreach ($rows as $row) {
            $definition[$row] = [
                'C' => ['formula' => "=SUM(D{$row}:E{$row})", 'blocked' => true, 'formula_cell' => true],
                'D' => [],
                'E' => [],
                'F' => [],
            ];
        }

        return new class($definition) extends CellDataStorageService {
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

                return [
                    'fila' => $row,
                    'columna' => $column,
                    'coordenada' => $column . $row,
                    'formula' => $definition['formula'] ?? null,
                    'es_formula' => (bool) ($definition['formula_cell'] ?? false),
                    'es_editable' => !$blocked,
                    'esta_bloqueada' => $blocked,
                ];
            }
        };
    }
}
