<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\RuleEngineService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * BM-11.5 -> BM-11.7 §7 -- Escenario sintetico, completamente aislado,
 * EQUIVALENTE a BM18/C (captura directa, 0 rem_rules tecnicas propias,
 * empty_behavior + severity configurados por calibracion funcional). NO usa
 * ni modifica el upload #197 real, ni ninguna estructura/regla/binding real
 * de BM (72/v1) -- todo se crea desde cero en esalud_testing via
 * RefreshDatabase. Sheet/section sinteticos (BM18SIM/C).
 *
 * Estado tras BM-11.7: el gap de alcance por establecimiento (BM-11.4 §5)
 * quedo CORREGIDO en ValidateRemUploadJob -- estos tests ya documentan el
 * comportamiento CORRECTO para el escenario que usaria BM18/C real una vez
 * calibrado: sin scope aplica a cualquiera (legado), con scope respeta el
 * establecimiento real de la carga.
 */
class Bm18cSyntheticCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'BM18SIM';
    private const SECTION = 'C';
    private const ROW = 57;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_rule_engine_execute_is_irrelevant_for_section_without_technical_rules(): void
    {
        $center = $this->createHealthCenter('Posta Sintetica Chanavayita');
        $upload = $this->createUpload($center->id);
        $this->createRemDataRow($upload, self::ROW, ['B' => null]);

        $structure = RemTemplateStructure::create([
            'serie' => 'BM',
            'anio' => 2026,
            'version_number' => 1,
            'estructura' => ['forms' => []],
            'hash_estructura' => 'hash_bm18c_sim_' . uniqid(),
            'status' => 'active',
        ]);
        // Deliberadamente: 0 Rule/RuleBinding creados para esta estructura,
        // replicando que BM18/C real tiene 0 sum_equals/cross_sheet_equals
        // propios (confirmado en BM-11.1/BM-11.3).

        $functionalRuleMock = Mockery::mock(FunctionalRuleService::class);
        // Sin ningun shouldReceive(): si $rules esta vacio, RuleEngineService
        // nunca deberia siquiera invocar getFunctionalRulesForEngine() --
        // cualquier llamada inesperada haria fallar este mock.
        $service = new RuleEngineService($functionalRuleMock);
        $service->registerEvaluator(new SumEqualsEvaluator());

        $result = $service->execute($upload->id, $structure->id);

        $this->assertSame(0, $result['total_rules']);
        $this->assertSame(0, $result['executed']);
        $this->assertEmpty($result['details']);
    }

    // ── exceptions=no / sin scope -> empty_behavior/severity aplica
    // normalmente a cualquier establecimiento (comportamiento legado) ─────

    public function test_validate_rem_upload_job_is_the_real_path_and_applies_without_scope(): void
    {
        $center = $this->createHealthCenter('Posta Sintetica Chanavayita');

        $rulesWithoutScope = [
            self::ROW => [
                'empty_behavior' => 'debe_registrar_cero',
                'severity' => 'error',
                'status' => 'aprobada',
                'included_health_centers' => [],
                'excluded_health_centers' => [],
            ],
        ];

        $results = $this->evaluateForCenter($center, $rulesWithoutScope);

        $this->assertCount(1, $results, 'ValidateRemUploadJob si evalua empty_behavior/severity aunque no exista ninguna rem_rule tecnica para la seccion.');
        $this->assertFalse($results->first()['passed']);
        $this->assertSame('error', $results->first()['severity']);
    }

    // ── included_health_centers -> respeta el establecimiento real de la
    // carga (GAP CERRADO en BM-11.7; antes ambos establecimientos recibian
    // el mismo resultado) ─────────────────────────────────────────────────

    public function test_included_health_centers_respects_the_real_establishment_of_the_upload(): void
    {
        $included = $this->createHealthCenter('Posta Caleta Chanavayita Sim');
        $other = $this->createHealthCenter('Posta Caleta San Marcos Sim');

        $rules = [
            self::ROW => [
                'empty_behavior' => 'debe_registrar_cero',
                'severity' => 'error',
                'status' => 'aprobada',
                'included_health_centers' => ['Posta Caleta Chanavayita Sim'],
                'excluded_health_centers' => [],
            ],
        ];

        $resultsIncluded = $this->evaluateForCenter($included, $rules);
        $resultsOther = $this->evaluateForCenter($other, $rules);

        $this->assertCount(1, $resultsIncluded, 'El establecimiento incluido recibe el resultado.');
        $this->assertCount(0, $resultsOther, 'GAP CERRADO: el establecimiento NO incluido ya no recibe el resultado.');
    }

    // ── excluded_health_centers -> idem, sentido inverso ──────────────────

    public function test_excluded_health_centers_respects_the_real_establishment_of_the_upload(): void
    {
        $excluded = $this->createHealthCenter('Posta Caleta San Marcos Sim');
        $other = $this->createHealthCenter('Posta Caleta Chanavayita Sim');

        $rules = [
            self::ROW => [
                'empty_behavior' => 'debe_registrar_cero',
                'severity' => 'error',
                'status' => 'aprobada',
                'included_health_centers' => [],
                'excluded_health_centers' => ['Posta Caleta San Marcos Sim'],
            ],
        ];

        $resultsExcluded = $this->evaluateForCenter($excluded, $rules);
        $resultsOther = $this->evaluateForCenter($other, $rules);

        $this->assertCount(0, $resultsExcluded, 'El establecimiento excluido ya no recibe el resultado.');
        $this->assertCount(1, $resultsOther, 'El establecimiento no excluido si recibe el resultado.');
    }

    private function evaluateForCenter(HealthCenter $center, array $rules)
    {
        $upload = $this->createUpload($center->id);
        $this->createRemDataRow($upload, self::ROW, ['B' => null]);

        $functionalRuleService = Mockery::mock(FunctionalRuleService::class);
        $functionalRuleService->shouldReceive('getFunctionalRulesForEngine')
            ->with(self::SHEET, self::SECTION)
            ->once()
            ->andReturn($rules);

        return $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $functionalRuleService,
            $this->cellData([
                self::ROW => ['B' => []],
            ]),
        );
    }

    private function invokeEvaluateFunctionalRules(
        ValidateRemUploadJob $job,
        RemUpload $upload,
        FunctionalRuleService $functionalRuleService,
        CellDataStorageService $cellDataStorage,
        $matrixService = null,
    ) {
        $method = (new ReflectionClass($job))->getMethod('evaluateFunctionalRules');
        $method->setAccessible(true);

        return $method->invoke($job, $upload, $functionalRuleService, $cellDataStorage, $matrixService);
    }

    private function createHealthCenter(string $name): HealthCenter
    {
        return HealthCenter::create([
            'name' => $name,
            'code_deis' => 'TEST_' . uniqid(),
            'type' => 'POSTA',
            'commune' => 'Iquique',
            'is_active' => true,
        ]);
    }

    private function createUpload(int $healthCenterId): RemUpload
    {
        return RemUpload::create([
            'health_center_id' => $healthCenterId,
            'user_id' => User::factory()->create()->id,
            'year' => 2026,
            'month' => 5,
            'rem_type' => 'BM',
            'original_filename' => 'bm18sim.xlsm',
            'stored_path' => 'test/bm18sim.xlsm',
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
                'concept' => 'URGENCIA SAPU/SAR/SUR (sintetico)',
                'professional' => '',
                'total' => $values['B'] ?? null,
                'values' => $values,
                'row_number' => $row,
                'section' => self::SHEET,
                'rem_section_code' => self::SECTION,
                'total_column' => 'B',
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
