<?php

namespace Tests\Feature\REM;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Jobs\ValidateRemUploadJob;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemUpload;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * BM-11.5 -> BM-11.7: este archivo documentaba el gap de alcance por
 * establecimiento en ValidateRemUploadJob::evaluateFunctionalRules() (BM-11.4
 * §5) -- ese gap quedo CORREGIDO en BM-11.7 (nuevo helper privado
 * establishmentInScope(), usando $upload->healthCenter?->name como fuente
 * canonica, mismo patron ya usado en RuleEngineService::execute()). Estos
 * tests ya NO documentan un bug -- documentan el comportamiento CORRECTO.
 *
 * Sin tocar el upload #197 real ni ningun dato de BM/Serie A.
 */
class ValidateRemUploadJobEstablishmentScopeCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    // ── Caso A: included=['Centro A'], carga de Centro A -> aplica ───────

    public function test_included_health_centers_applies_to_the_listed_establishment(): void
    {
        $centerA = $this->createHealthCenter('Centro A');

        $results = $this->evaluateForCenter($centerA, [
            10 => [
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'included_health_centers' => ['Centro A'],
                'excluded_health_centers' => [],
            ],
        ]);

        $this->assertCount(1, $results, 'GAP CERRADO: Centro A (incluido) recibe el resultado.');
    }

    // ── Caso B: included=['Centro A'], carga de Centro B -> NO aplica ────

    public function test_included_health_centers_does_not_apply_to_an_unlisted_establishment(): void
    {
        $centerB = $this->createHealthCenter('Centro B');

        $results = $this->evaluateForCenter($centerB, [
            10 => [
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'included_health_centers' => ['Centro A'],
                'excluded_health_centers' => [],
            ],
        ]);

        $this->assertCount(0, $results, 'GAP CERRADO: Centro B (NO incluido) ya no recibe el resultado.');
    }

    // ── Caso C: excluded=['Centro A'], carga de Centro A -> NO aplica ────

    public function test_excluded_health_centers_does_not_apply_to_the_listed_establishment(): void
    {
        $centerA = $this->createHealthCenter('Centro A');

        $results = $this->evaluateForCenter($centerA, [
            10 => [
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'included_health_centers' => [],
                'excluded_health_centers' => ['Centro A'],
            ],
        ]);

        $this->assertCount(0, $results, 'GAP CERRADO: Centro A (excluido) ya no recibe el resultado.');
    }

    // ── Caso D: excluded=['Centro A'], carga de Centro B -> aplica ───────

    public function test_excluded_health_centers_applies_to_a_non_listed_establishment(): void
    {
        $centerB = $this->createHealthCenter('Centro B');

        $results = $this->evaluateForCenter($centerB, [
            10 => [
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'included_health_centers' => [],
                'excluded_health_centers' => ['Centro A'],
            ],
        ]);

        $this->assertCount(1, $results, 'Centro B (no excluido) sigue recibiendo el resultado.');
    }

    // ── Legado: sin included/excluded -> aplica a cualquier establecimiento
    // (protege Serie A -- BM-11.6 §2/BM-11.7 §8) ───────────────────────────

    public function test_legacy_functional_rule_without_scope_applies_regardless_of_establishment(): void
    {
        $centerA = $this->createHealthCenter('Centro A');
        $centerB = $this->createHealthCenter('Centro B');

        $rulesWithoutScopeKeys = [
            10 => [
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                // sin 'included_health_centers'/'excluded_health_centers' --
                // forma exacta de una regla funcional legada real.
            ],
        ];

        $resultsForA = $this->evaluateForCenter($centerA, $rulesWithoutScopeKeys);
        $resultsForB = $this->evaluateForCenter($centerB, $rulesWithoutScopeKeys);

        $this->assertCount(1, $resultsForA, 'Comportamiento legado: sin scope, aplica a Centro A.');
        $this->assertCount(1, $resultsForB, 'Comportamiento legado: sin scope, aplica tambien a Centro B.');
    }

    // ── Fail-safe: included y excluded poblados a la vez -> no aplica a
    // nadie (BM-11.7 §5) -- FunctionalRuleService::resolveScope() ya lo
    // impide producir, pero el Job no debe inventar semantica nueva si de
    // todos modos llegara un dato asi. ─────────────────────────────────────

    public function test_functional_rule_with_both_included_and_excluded_simultaneously_applies_to_no_one(): void
    {
        $centerA = $this->createHealthCenter('Centro A');
        $centerC = $this->createHealthCenter('Centro C');

        $malformedRules = [
            10 => [
                'empty_behavior' => 'debe_registrar_cero',
                'status' => 'aprobada',
                'included_health_centers' => ['Centro A'],
                'excluded_health_centers' => ['Centro C'],
            ],
        ];

        $resultsForA = $this->evaluateForCenter($centerA, $malformedRules);
        $resultsForC = $this->evaluateForCenter($centerC, $malformedRules);

        $this->assertCount(0, $resultsForA, 'Fail-safe: scope contradictorio no aplica ni siquiera al establecimiento incluido.');
        $this->assertCount(0, $resultsForC, 'Fail-safe: scope contradictorio no aplica ni siquiera al establecimiento excluido.');
    }

    // ── establishmentInScope() aislado: casos de establecimiento null/vacio
    // y estructuras malformadas (BM-11.7 §5), via reflection directa sobre
    // el helper -- sin necesidad de construir un RemUpload sin
    // health_center_id (la columna es obligatoria en este esquema). ───────

    public function test_establishment_in_scope_treats_null_establishment_name_safely(): void
    {
        $job = new ValidateRemUploadJob($this->createUpload($this->createHealthCenter('Centro A')->id));

        $this->assertFalse(
            $this->invokeEstablishmentInScope($job, ['included_health_centers' => ['Centro A'], 'excluded_health_centers' => []], null),
            'Sin nombre de establecimiento resoluble, included no vacio nunca puede confirmar pertenencia -- no aplica.',
        );

        $this->assertTrue(
            $this->invokeEstablishmentInScope($job, ['included_health_centers' => [], 'excluded_health_centers' => ['Centro A']], null),
            'Sin nombre de establecimiento resoluble, excluded no vacio nunca puede confirmar exclusion -- aplica (fail-open del lado seguro: nunca se asume que es el excluido).',
        );

        $this->assertTrue(
            $this->invokeEstablishmentInScope($job, ['included_health_centers' => [], 'excluded_health_centers' => []], null),
            'Sin scope alguno, aplica igual -- comportamiento legado, independiente del nombre.',
        );
    }

    public function test_establishment_in_scope_normalizes_malformed_non_array_values_to_empty(): void
    {
        $job = new ValidateRemUploadJob($this->createUpload($this->createHealthCenter('Centro A')->id));

        // included_health_centers como string (no array) -- se normaliza a
        // [] -- nunca se amplia la aplicacion a partir de un dato corrupto.
        $this->assertTrue(
            $this->invokeEstablishmentInScope($job, ['included_health_centers' => 'Centro A', 'excluded_health_centers' => []], 'Centro B'),
            'included_health_centers malformado (no array) se trata como sin scope -- aplica a cualquiera, igual que el legado.',
        );
    }

    private function invokeEstablishmentInScope(ValidateRemUploadJob $job, array $functionalRule, ?string $healthCenterName): bool
    {
        $method = (new ReflectionClass($job))->getMethod('establishmentInScope');
        $method->setAccessible(true);

        return $method->invoke($job, $functionalRule, $healthCenterName);
    }

    private function evaluateForCenter(HealthCenter $center, array $rules)
    {
        $upload = $this->createUpload($center->id);
        $this->createRemDataRow($upload, 10, ['C' => 20, 'U' => null, 'V' => 20]);

        $functionalRuleService = Mockery::mock(FunctionalRuleService::class);
        $functionalRuleService->shouldReceive('getFunctionalRulesForEngine')
            ->with('A01', 'A')
            ->once()
            ->andReturn($rules);

        return $this->invokeEvaluateFunctionalRules(
            new ValidateRemUploadJob($upload),
            $upload,
            $functionalRuleService,
            $this->cellData([
                10 => ['C' => ['formula' => '=SUM(U10:V10)', 'blocked' => true, 'formula_cell' => true], 'U' => [], 'V' => []],
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
            'type' => 'CESFAM',
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
            'month' => 1,
            'rem_type' => 'A',
            'original_filename' => 'a01.xlsm',
            'stored_path' => 'test/a01.xlsm',
            'file_size' => 1,
            'mime_type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'status' => 'processed',
        ]);
    }

    private function createRemDataRow(RemUpload $upload, int $row, array $values): RemData
    {
        return RemData::create([
            'rem_upload_id' => $upload->id,
            'section' => 'A01',
            'data' => [
                'concept' => 'Fila ' . $row,
                'professional' => 'Matrona/on',
                'total' => $values['C'] ?? null,
                'values' => $values,
                'row_number' => $row,
                'section' => 'A01',
                'rem_section_code' => 'A',
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
