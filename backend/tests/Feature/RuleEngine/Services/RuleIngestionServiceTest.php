<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\RuleConfigNormalizerService;
use App\Domain\RuleEngine\Services\RuleIngestionService;
use App\Domain\RuleEngine\Services\RuleKeyGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RuleIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RuleIngestionService(
            new RuleKeyGeneratorService,
            new RuleConfigNormalizerService,
        );
    }

    private function createSimpleStructure(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'serie' => 'A',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_ingest_1',
            'status' => 'active',
            'source_filename' => 'REM_A_2026_v1.xlsx',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SECCION_1',
                                'fields' => [
                                    [
                                        'letra' => 'A',
                                        'label' => 'Personal Medico',
                                        'reglaDetectada' => 'sum_equals',
                                    ],
                                    [
                                        'letra' => 'B',
                                        'label' => 'Horas Medicas',
                                        'reglaDetectada' => [
                                            'tipo' => 'required_and_le_parent',
                                            'columnasOrigen' => ['C1:C100'],
                                            'columnaDestino' => null,
                                            'rangoFilas' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_ingest_creates_rules_from_structure(): void
    {
        $structure = $this->createSimpleStructure();

        $stats = $this->service->ingest($structure->id);

        $this->assertSame(2, $stats['total_detected']);
        $this->assertSame(0, $stats['skipped_control_oculto']);
        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, $stats['bindings_created']);
        $this->assertSame($structure->id, $stats['structure_id']);
        $this->assertSame(2026, $stats['anio']);
        $this->assertSame('A', $stats['serie']);
        $this->assertSame(1, $stats['version']);
        $this->assertArrayHasKey('sum_equals', $stats['distribution']);
        $this->assertArrayHasKey('required_and_le_parent', $stats['distribution']);

        $this->assertSame(2, Rule::count());
    }

    public function test_ingest_creates_rules_with_correct_properties(): void
    {
        $structure = $this->createSimpleStructure();

        $this->service->ingest($structure->id);

        $sumRule = Rule::where('rule_key', 'hoja1_seccion_1_a_sum_equals')->first();
        $this->assertNotNull($sumRule);
        $this->assertSame('sum_equals', $sumRule->rule_type);
        $this->assertSame('error', $sumRule->severity);
        $this->assertSame('active', $sumRule->status);
        $this->assertSame('excel_formula', $sumRule->source);
        $this->assertSame('per_row', $sumRule->scope);
        $this->assertSame('1.0.0', $sumRule->version);

        $parentRule = Rule::where('rule_key', 'hoja1_seccion_1_b_required_and_le_parent')->first();
        $this->assertNotNull($parentRule);
        $this->assertSame('required_and_le_parent', $parentRule->rule_type);
        $this->assertSame('warning', $parentRule->severity);
    }

    public function test_ingest_reuses_existing_rules_with_same_key(): void
    {
        $structure = $this->createSimpleStructure();

        $stats = $this->service->ingest($structure->id);

        $this->assertSame(2, $stats['created']);

        $stats2 = $this->service->ingest($structure->id);

        $this->assertSame(0, $stats2['created']);
        $this->assertSame(2, $stats2['reused']);
        $this->assertSame(2, Rule::count());
    }

    public function test_ingest_skips_control_oculto(): void
    {
        $structure = RemTemplateStructure::create([
            'serie' => 'B',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_oculto',
            'status' => 'active',
            'source_filename' => 'REM_B.xlsx',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SEC1',
                                'fields' => [
                                    [
                                        'letra' => 'A',
                                        'label' => 'Visible',
                                        'reglaDetectada' => 'sum_equals',
                                    ],
                                    [
                                        'letra' => 'B',
                                        'label' => 'Oculto',
                                        'reglaDetectada' => 'control_oculto',
                                    ],
                                    [
                                        'letra' => 'C',
                                        'label' => 'Otro Visible',
                                        'reglaDetectada' => [
                                            'tipo' => 'required_and_le_parent',
                                            'columnasOrigen' => ['D1:D100'],
                                            'columnaDestino' => null,
                                            'rangoFilas' => null,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $stats = $this->service->ingest($structure->id);

        $this->assertSame(3, $stats['total_detected']);
        $this->assertSame(1, $stats['skipped_control_oculto']);
        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, Rule::count());

        $this->assertNull(Rule::where('rule_key', 'like', '%control_oculto%')->first());
    }

    public function test_ingest_creates_bindings_for_each_rule(): void
    {
        $structure = $this->createSimpleStructure();

        $this->service->ingest($structure->id);

        $rules = Rule::all();

        foreach ($rules as $rule) {
            $binding = RuleBinding::where('rule_id', $rule->id)
                ->where('bindable_type', 'structure')
                ->where('bindable_id', $structure->id)
                ->first();

            $this->assertNotNull($binding);
            $this->assertTrue($binding->active);
            $this->assertSame('A', $binding->serie);
            $this->assertSame(2026, $binding->anio);
        }

        $this->assertSame(2, RuleBinding::count());
    }

    public function test_ingest_does_not_duplicate_bindings(): void
    {
        $structure = $this->createSimpleStructure();

        $stats = $this->service->ingest($structure->id);
        $this->assertSame(2, $stats['bindings_created']);

        $stats2 = $this->service->ingest($structure->id);
        $this->assertSame(0, $stats2['bindings_created']);

        $this->assertSame(2, RuleBinding::count());
    }

    public function test_ingest_creates_versions_for_new_rules(): void
    {
        $structure = $this->createSimpleStructure();

        $this->service->ingest($structure->id);

        $rules = Rule::all();

        foreach ($rules as $rule) {
            $version = RuleVersion::where('rule_id', $rule->id)->first();
            $this->assertNotNull($version);
            $this->assertSame('1.0.0', $version->version);
            $this->assertIsArray($version->config);
        }
    }

    public function test_ingest_handles_row_range_scope(): void
    {
        $structure = RemTemplateStructure::create([
            'serie' => 'C',
            'anio' => 2026,
            'version_number' => 1,
            'hash_estructura' => 'hash_range',
            'status' => 'active',
            'source_filename' => 'REM_C.xlsx',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => 'Hoja1',
                        'sections' => [
                            [
                                'codigo' => 'SEC1',
                                'fields' => [
                                    [
                                        'letra' => 'A',
                                        'label' => 'Row Range Rule',
                                        'reglaDetectada' => [
                                            'tipo' => 'sum_equals',
                                            'columnasOrigen' => ['B1:B50'],
                                            'columnaDestino' => 'C',
                                            'rangoFilas' => '5:50',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->service->ingest($structure->id);

        $rule = Rule::first();
        $this->assertSame('row_range', $rule->scope);
        $this->assertSame(5, $rule->config['row_from']);
        $this->assertSame(50, $rule->config['row_to']);
    }
}
