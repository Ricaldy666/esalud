<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Exceptions\RuleManifestImportException;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Services\RemRuleManifestImporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BM-6.2. Cubre RemRuleManifestImporterService + rem:import-rule-manifest
 * de forma 100% aislada -- serie real 'D' (una de las 5 series validas de
 * MetadataExtractorService::TIPOS_REM, elegida porque no tiene desarrollo
 * propio en este repositorio) + hoja/año ficticios ('ZZTEST', 2099) para no
 * colisionar con ningun dato real de Serie A/BM. No depende de cell-data
 * del filesystem -- el importador solo toca rem_template_structures/
 * rem_rules/rem_rule_bindings.
 */
class RemRuleManifestImporterServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SERIE = 'D';
    private const ANIO = 2099;
    private const SHEET = 'ZZTEST';
    private const TARGET_SHEET = 'ZZTARGET';

    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function writeManifest(array $manifest): string
    {
        $path = sys_get_temp_dir() . '/bm62_test_manifest_' . uniqid() . '.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT));
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function ruleEntry(string $key, string $column = 'C', int $from = 10, int $to = 12, ?int $totalRow = null): array
    {
        $config = [
            'sheet' => self::SHEET,
            'section' => 'A',
            'column' => $column,
            'row_range' => ['from' => $from, 'to' => $to],
            'rule_logic' => $totalRow !== null ? "Suma({$column}) = Columna {$column}" : "Suma(D + E) = Columna {$column}",
        ];
        if ($totalRow !== null) {
            $config['total_row'] = $totalRow;
        }

        return [
            'rule_key' => $key,
            'rule_type' => 'sum_equals',
            'source' => 'excel_formula',
            'name' => "Test rule {$key}",
            'description' => "Descripcion de prueba {$key}",
            'category' => 'sum_equals_horizontal',
            'severity' => 'error',
            'status' => 'active',
            'scope' => $from === $to ? 'per_row' : 'row_range',
            'version' => '1.0.0',
            'metadata' => ['catalog_rule_id' => 999, 'catalog_rule_key' => 'zztest_a_c_sum_equals'],
            'config' => $config,
        ];
    }

    private function baseManifest(array $rules): array
    {
        return [
            'schema_version' => '1.0.0',
            'manifest_id' => 'zztest-manifest',
            'serie' => self::SERIE,
            'anio' => self::ANIO,
            'expected_rule_count' => count($rules),
            'rules' => $rules,
        ];
    }

    private function createActiveStructure(array $sections = ['A']): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => self::ANIO,
            'serie' => self::SERIE,
            'hash_estructura' => sha1('bm62-test-' . uniqid()),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    [
                        'sheetName' => self::SHEET,
                        'sections' => array_map(fn ($s) => ['codigo' => $s, 'filaInicioDatos' => 10, 'filaFinDatos' => 20, 'fields' => []], $sections),
                    ],
                ],
            ],
        ]);
    }

    /**
     * BM-8.2. Estructura con DOS hojas ficticias (ZZTEST=fuente,
     * ZZTARGET=destino) para probar cross_sheet_equals de forma aislada,
     * sin depender de BM18/BM18A.
     */
    private function createActiveStructureWithTargetSheet(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => self::ANIO,
            'serie' => self::SERIE,
            'hash_estructura' => sha1('bm82-test-' . uniqid()),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    ['sheetName' => self::SHEET, 'sections' => [['codigo' => 'A', 'filaInicioDatos' => 10, 'filaFinDatos' => 20, 'fields' => []]]],
                    ['sheetName' => self::TARGET_SHEET, 'sections' => [['codigo' => 'A', 'filaInicioDatos' => 10, 'filaFinDatos' => 20, 'fields' => []]]],
                ],
            ],
        ]);
    }

    private function crossSheetDirectEntry(string $key, string $sourceCell = 'B2', string $targetCell = 'C3'): array
    {
        return [
            'rule_key' => $key,
            'rule_type' => 'cross_sheet_equals',
            'source' => 'excel_formula',
            'name' => "Test {$key}",
            'description' => 'Test BM-8.2 direct',
            'category' => 'cross_sheet_equals',
            'severity' => 'error',
            'status' => 'active',
            'scope' => 'per_row',
            'version' => '1.0.0',
            'metadata' => ['origin_sheet' => self::SHEET, 'origin_cell' => $sourceCell],
            'config' => [
                'sheet' => self::SHEET,
                'section' => 'A',
                'source' => ['cell' => $sourceCell],
                'target' => ['sheet' => self::TARGET_SHEET, 'cell' => $targetCell],
            ],
        ];
    }

    private function crossSheetSumRangeEntry(string $key, string $sourceCell = 'B3', string $range = 'D1:D3'): array
    {
        return [
            'rule_key' => $key,
            'rule_type' => 'cross_sheet_equals',
            'source' => 'excel_formula',
            'name' => "Test {$key}",
            'description' => 'Test BM-8.2 sum_range',
            'category' => 'cross_sheet_equals',
            'severity' => 'error',
            'status' => 'active',
            'scope' => 'per_row',
            'version' => '1.0.0',
            'metadata' => ['origin_sheet' => self::SHEET, 'origin_cell' => $sourceCell],
            'config' => [
                'sheet' => self::SHEET,
                'section' => 'A',
                'source' => ['cell' => $sourceCell],
                'target' => ['sheet' => self::TARGET_SHEET, 'range' => $range, 'aggregation' => 'sum'],
            ],
        ];
    }

    // --- BM-8.2: validacion nativa de cross_sheet_equals en el importador ---

    public function test_cross_sheet_direct_config_is_valid_and_would_create(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $path = $this->writeManifest($this->baseManifest([$this->crossSheetDirectEntry('zztest_b2_cross_sheet_equals_zztarget_c3')]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertSame(0, count($plan['invalid']), json_encode($plan['invalid']));
        $this->assertCount(1, $plan['would_create']);
    }

    public function test_cross_sheet_sum_range_config_is_valid_and_would_create(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $path = $this->writeManifest($this->baseManifest([$this->crossSheetSumRangeEntry('zztest_b3_cross_sheet_equals_zztarget_sum_d1_d3')]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertSame(0, count($plan['invalid']), json_encode($plan['invalid']));
        $this->assertCount(1, $plan['would_create']);
    }

    /**
     * BM-8.2, punto 14 del pedido: manifiesto sintetico con exactamente 1
     * DIRECT + 1 SUM_RANGE -- dry-run puro, nada escrito a BD real.
     */
    public function test_synthetic_manifest_one_direct_one_sum_range_dry_run(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $path = $this->writeManifest($this->baseManifest([
            $this->crossSheetDirectEntry('zztest_b2_cross_sheet_equals_zztarget_c3'),
            $this->crossSheetSumRangeEntry('zztest_b3_cross_sheet_equals_zztarget_sum_d1_d3'),
        ]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertSame(2, $plan['valid']);
        $this->assertCount(2, $plan['would_create']);
        $this->assertCount(0, $plan['invalid']);
        $this->assertCount(0, $plan['conflicts']);
        $this->assertSame(0, Rule::count(), 'plan() nunca escribe');
        $this->assertSame(0, RuleBinding::count());
    }

    public function test_cross_sheet_missing_source_cell_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_bad_1');
        unset($entry['config']['source']['cell']);
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString('source.cell', $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_missing_target_sheet_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_bad_2');
        unset($entry['config']['target']['sheet']);
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString('target.sheet', $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_target_without_cell_or_range_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_bad_3');
        unset($entry['config']['target']['cell']);
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString("'cell' o 'range'", $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_target_with_cell_and_range_simultaneously_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_bad_4');
        $entry['config']['target']['range'] = 'D1:D3';
        $entry['config']['target']['aggregation'] = 'sum';
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString('simultaneamente', $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_range_without_aggregation_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetSumRangeEntry('zztest_bad_5');
        unset($entry['config']['target']['aggregation']);
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString('aggregation', $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_aggregation_other_than_sum_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetSumRangeEntry('zztest_bad_6');
        $entry['config']['target']['aggregation'] = 'avg';
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString("no soportada", $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_malformed_target_cell_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_bad_7', 'B2', 'NOT_A_CELL');
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString('target.cell invalida', $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_target_sheet_not_in_structure_is_invalid(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_bad_8');
        $entry['config']['target']['sheet'] = 'HOJA_QUE_NO_EXISTE';
        $path = $this->writeManifest($this->baseManifest([$entry]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString('no existe en la estructura activa', $plan['invalid'][0]['reason']);
    }

    public function test_cross_sheet_idempotent_skip_on_identical_content(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_b2_cross_sheet_equals_zztarget_c3');

        Rule::create([
            'rule_key' => $entry['rule_key'], 'rule_type' => $entry['rule_type'], 'source' => $entry['source'],
            'name' => $entry['name'], 'description' => $entry['description'], 'category' => $entry['category'],
            'severity' => $entry['severity'], 'scope' => $entry['scope'], 'config' => $entry['config'],
            'status' => $entry['status'], 'version' => $entry['version'], 'metadata' => $entry['metadata'],
        ]);

        $path = $this->writeManifest($this->baseManifest([$entry]));
        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertSame([$entry['rule_key']], $plan['would_skip']);
        $this->assertCount(0, $plan['would_create']);
        $this->assertCount(0, $plan['conflicts']);
        $this->assertSame(1, Rule::count());
    }

    public function test_cross_sheet_conflict_on_different_content_aborts_commit(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $entry = $this->crossSheetDirectEntry('zztest_b2_cross_sheet_equals_zztarget_c3');

        Rule::create([
            'rule_key' => $entry['rule_key'], 'rule_type' => $entry['rule_type'], 'source' => 'otro_origen',
            'name' => 'Distinto', 'description' => 'Distinto', 'category' => $entry['category'],
            'severity' => $entry['severity'], 'scope' => $entry['scope'], 'config' => $entry['config'],
            'status' => $entry['status'], 'version' => $entry['version'], 'metadata' => $entry['metadata'],
        ]);

        $path = $this->writeManifest($this->baseManifest([$entry]));
        $importer = app(RemRuleManifestImporterService::class);
        $plan = $importer->plan($path);

        $this->assertCount(1, $plan['conflicts']);

        $this->expectException(RuleManifestImportException::class);
        $importer->commit($path);
    }

    public function test_cross_sheet_batch_rolls_back_completely_on_failure(): void
    {
        $this->createActiveStructureWithTargetSheet();
        $ok = $this->crossSheetDirectEntry('zztest_ok_cross_sheet');
        $tooLong = $this->crossSheetSumRangeEntry(str_repeat('y', 300)); // excede varchar(255)

        $path = $this->writeManifest($this->baseManifest([$ok, $tooLong]));
        $importer = app(RemRuleManifestImporterService::class);

        $plan = $importer->plan($path);
        $this->assertCount(2, $plan['would_create']);

        try {
            $importer->commit($path);
            $this->fail('Se esperaba una excepcion de BD por rule_key demasiado larga.');
        } catch (\Throwable $e) {
            // esperado
        }

        $this->assertSame(0, Rule::count(), 'rollback total -- ni siquiera la regla cross-sheet valida debe quedar');
        $this->assertSame(0, RuleBinding::count());
    }

    // --- A/B: manifiesto real 53/53 -------------------------------------

    public function test_real_bm_manifest_has_exactly_53_distinct_rule_keys(): void
    {
        $path = base_path('database/seeders/data/rem-bm-2026-internal-rules-manifest.json');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(53, count($manifest['rules']));
        $this->assertSame(53, $manifest['expected_rule_count']);

        $keys = array_column($manifest['rules'], 'rule_key');
        $this->assertCount(53, array_unique($keys), 'las 53 rule_key deben ser distintas');
    }

    // --- BM-8.3: manifiesto real de las 37 relaciones cross-sheet BM18->BM18A ---

    private function loadRealCrossSheetManifest(): array
    {
        $path = base_path('database/seeders/data/rem-bm-2026-cross-sheet-rules-manifest.json');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        return $manifest;
    }

    public function test_real_cross_sheet_manifest_has_exactly_37_distinct_rule_keys(): void
    {
        $manifest = $this->loadRealCrossSheetManifest();

        $this->assertSame(37, count($manifest['rules']));
        $this->assertSame(37, $manifest['expected_rule_count']);

        $keys = array_column($manifest['rules'], 'rule_key');
        $this->assertCount(37, array_unique($keys), 'las 37 rule_key deben ser distintas');
    }

    public function test_real_cross_sheet_manifest_has_31_direct_and_6_sum_range(): void
    {
        $manifest = $this->loadRealCrossSheetManifest();

        $direct = array_filter($manifest['rules'], fn ($r) => $r['metadata']['relation_type'] === 'DIRECT');
        $sumRange = array_filter($manifest['rules'], fn ($r) => $r['metadata']['relation_type'] === 'SUM_RANGE');

        $this->assertCount(31, $direct);
        $this->assertCount(6, $sumRange);
        $this->assertSame(31, $manifest['relation_counts']['direct']);
        $this->assertSame(6, $manifest['relation_counts']['sum_range']);
    }

    public function test_real_cross_sheet_manifest_all_rules_target_bm18a_from_bm18(): void
    {
        $manifest = $this->loadRealCrossSheetManifest();

        foreach ($manifest['rules'] as $r) {
            $this->assertSame('BM18', $r['config']['sheet'], "{$r['rule_key']}: sheet fuente debe ser BM18");
            $this->assertSame('BM18A', $r['config']['target']['sheet'], "{$r['rule_key']}: target.sheet debe ser BM18A");
            $this->assertSame('cross_sheet_equals', $r['rule_type']);
        }
    }

    public function test_real_cross_sheet_manifest_never_uses_catalog_rule_id_or_derived_from(): void
    {
        $manifest = $this->loadRealCrossSheetManifest();

        foreach ($manifest['rules'] as $r) {
            $this->assertArrayNotHasKey('catalog_rule_id', $r['metadata'], "{$r['rule_key']}: las 37 cross-sheet no provienen del catalogo 912-921");
            $this->assertArrayNotHasKey('derived_from_rule_id', $r['metadata'], "{$r['rule_key']}: no son hijas de ninguna regla real de rem_rules");
        }
    }

    public function test_real_cross_sheet_manifest_has_zero_collisions_with_internal_manifest(): void
    {
        $internal = json_decode(file_get_contents(base_path('database/seeders/data/rem-bm-2026-internal-rules-manifest.json')), true);
        $crossSheet = $this->loadRealCrossSheetManifest();

        $internalKeys = array_column($internal['rules'], 'rule_key');
        $crossKeys = array_column($crossSheet['rules'], 'rule_key');

        $this->assertCount(0, array_intersect($internalKeys, $crossKeys), '0 colisiones esperadas entre los dos manifiestos BM');
        $this->assertCount(90, array_unique(array_merge($internalKeys, $crossKeys)), '53 + 37 = 90 rule_key distintas combinadas');
    }

    public function test_real_cross_sheet_manifest_dry_run_via_command_is_read_only(): void
    {
        // La BD de test esta vacia (RefreshDatabase) -- se crea una
        // estructura BM/2026 equivalente (mismas hojas/secciones que el
        // manifiesto real declara) solo para que el importador pueda
        // resolverla; el ID resultante en la BD de test no sera 72 (eso es
        // exclusivo de esalud_dev), lo que se certifica aqui es el
        // comportamiento READ-ONLY del dry-run contra el manifiesto real,
        // no el ID numerico de la estructura.
        RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'BM',
            'hash_estructura' => sha1('bm83-real-manifest-dry-run-' . uniqid()),
            'version_number' => 1,
            'status' => 'active',
            'estructura' => [
                'forms' => [
                    ['sheetName' => 'BM18', 'sections' => [
                        ['codigo' => 'A', 'filaInicioDatos' => 13, 'filaFinDatos' => 38, 'fields' => []],
                        ['codigo' => 'B', 'filaInicioDatos' => 40, 'filaFinDatos' => 53, 'fields' => []],
                    ]],
                    ['sheetName' => 'BM18A', 'sections' => [
                        ['codigo' => 'A', 'filaInicioDatos' => 13, 'filaFinDatos' => 118, 'fields' => []],
                        ['codigo' => 'B', 'filaInicioDatos' => 124, 'filaFinDatos' => 205, 'fields' => []],
                    ]],
                ],
            ],
        ]);

        $manifest = $this->loadRealCrossSheetManifest();
        $path = base_path('database/seeders/data/rem-bm-2026-cross-sheet-rules-manifest.json');

        $rulesBefore = Rule::count();
        $bindingsBefore = RuleBinding::count();

        $this->artisan('rem:import-rule-manifest', ['manifest' => $path])->assertExitCode(0);

        $this->assertSame($rulesBefore, Rule::count(), 'dry-run del manifiesto real no debe crear ninguna fila');
        $this->assertSame($bindingsBefore, RuleBinding::count());
        $this->assertSame(37, count($manifest['rules']));
    }

    // --- C: dry-run (plan) no persiste nada ------------------------------

    public function test_plan_creates_zero_rules_and_zero_bindings(): void
    {
        $this->createActiveStructure();
        $path = $this->writeManifest($this->baseManifest([
            $this->ruleEntry('zztest_a_c_sum_equals'),
        ]));

        $importer = app(RemRuleManifestImporterService::class);
        $plan = $importer->plan($path);

        $this->assertSame(1, count($plan['would_create']));
        $this->assertSame(0, Rule::count());
        $this->assertSame(0, RuleBinding::count());
    }

    // --- D: resuelve la estructura correcta ------------------------------

    public function test_plan_resolves_the_correct_active_structure(): void
    {
        $structure = $this->createActiveStructure();
        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));

        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertSame($structure->id, $plan['structure']['id']);
        $this->assertSame(1, $plan['structure']['version_number']);
    }

    // --- E: estructura ausente -> fail -----------------------------------

    public function test_plan_fails_closed_when_no_active_structure_exists(): void
    {
        // No se crea ninguna estructura para SERIE/ANIO.
        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));

        $this->expectException(RuleManifestImportException::class);
        $this->expectExceptionMessageMatches('/exactamente 1 estructura activa/');

        app(RemRuleManifestImporterService::class)->plan($path);
    }

    // --- F: estructura ambigua (mas de una activa) -> fail ----------------

    public function test_plan_fails_closed_when_multiple_active_structures_exist(): void
    {
        $this->createActiveStructure();
        // Segunda estructura activa para la misma serie/anio -- estado ambiguo.
        RemTemplateStructure::create([
            'anio' => self::ANIO, 'serie' => self::SERIE,
            'hash_estructura' => sha1('bm62-test-dup-' . uniqid()),
            'version_number' => 2, 'status' => 'active',
            'estructura' => ['forms' => [['sheetName' => self::SHEET, 'sections' => [['codigo' => 'A', 'filaInicioDatos' => 10, 'filaFinDatos' => 20, 'fields' => []]]]]],
        ]);
        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));

        $this->expectException(RuleManifestImportException::class);
        $this->expectExceptionMessageMatches('/se encontraron 2/');

        app(RemRuleManifestImporterService::class)->plan($path);
    }

    // --- G: config invalida -> se reporta como invalid, nunca crashea el plan ---

    public function test_plan_marks_unparseable_rule_logic_as_invalid(): void
    {
        $this->createActiveStructure();
        $badRule = $this->ruleEntry('zztest_a_c_broken');
        $badRule['config']['rule_logic'] = 'esto no sigue el formato Suma(...) = Columna ...';
        unset($badRule['config']['column']); // sin 'column' tampoco hay target_column derivable

        $path = $this->writeManifest($this->baseManifest([$badRule]));
        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertSame('zztest_a_c_broken', $plan['invalid'][0]['rule_key']);
        $this->assertCount(0, $plan['would_create']);
    }

    public function test_plan_marks_rule_targeting_nonexistent_section_as_invalid(): void
    {
        $this->createActiveStructure(['A']); // solo existe la seccion A
        $badRule = $this->ruleEntry('zztest_z_c_sum_equals');
        $badRule['config']['section'] = 'Z'; // no existe

        $path = $this->writeManifest($this->baseManifest([$badRule]));
        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertCount(1, $plan['invalid']);
        $this->assertStringContainsString("seccion 'Z' no existe", $plan['invalid'][0]['reason']);
    }

    // --- H: duplicado con contenido identico -> skip idempotente ---------

    public function test_plan_skips_rule_key_with_identical_existing_content(): void
    {
        $this->createActiveStructure();
        $entry = $this->ruleEntry('zztest_a_c_sum_equals');

        Rule::create([
            'rule_key' => $entry['rule_key'], 'rule_type' => $entry['rule_type'], 'source' => $entry['source'],
            'name' => $entry['name'], 'description' => $entry['description'], 'category' => $entry['category'],
            'severity' => $entry['severity'], 'scope' => $entry['scope'], 'config' => $entry['config'],
            'status' => $entry['status'], 'version' => $entry['version'], 'metadata' => $entry['metadata'],
        ]);

        $path = $this->writeManifest($this->baseManifest([$entry]));
        $plan = app(RemRuleManifestImporterService::class)->plan($path);

        $this->assertSame(['zztest_a_c_sum_equals'], $plan['would_skip']);
        $this->assertCount(0, $plan['would_create']);
        $this->assertCount(0, $plan['conflicts']);
        $this->assertSame(1, Rule::count(), 'no debe crear una segunda fila');
    }

    // --- I: duplicado con contenido distinto -> conflicto, commit aborta --

    public function test_plan_reports_conflict_when_existing_rule_key_has_different_content(): void
    {
        $this->createActiveStructure();
        $entry = $this->ruleEntry('zztest_a_c_sum_equals');

        Rule::create([
            'rule_key' => $entry['rule_key'], 'rule_type' => 'sum_equals', 'source' => 'otro_origen_distinto',
            'name' => 'Nombre distinto', 'description' => 'Descripcion distinta', 'category' => $entry['category'],
            'severity' => $entry['severity'], 'scope' => $entry['scope'], 'config' => $entry['config'],
            'status' => $entry['status'], 'version' => $entry['version'], 'metadata' => $entry['metadata'],
        ]);

        $path = $this->writeManifest($this->baseManifest([$entry]));
        $importer = app(RemRuleManifestImporterService::class);
        $plan = $importer->plan($path);

        $this->assertCount(1, $plan['conflicts']);
        $this->assertSame('zztest_a_c_sum_equals', $plan['conflicts'][0]['rule_key']);

        $this->expectException(RuleManifestImportException::class);
        $this->expectExceptionMessageMatches('/conflicto/');
        $importer->commit($path);
    }

    // --- J: rollback total si falla la creacion de una regla a mitad de camino ---

    public function test_commit_rolls_back_completely_if_one_rule_fails_mid_batch(): void
    {
        $this->createActiveStructure();
        $ok1 = $this->ruleEntry('zztest_a_c_ok1');
        $tooLong = $this->ruleEntry(str_repeat('x', 300)); // excede varchar(255) de rule_key -> falla en Rule::create()
        $ok2 = $this->ruleEntry('zztest_a_c_ok2');

        $path = $this->writeManifest($this->baseManifest([$ok1, $tooLong, $ok2]));
        $importer = app(RemRuleManifestImporterService::class);

        // El plan en si no valida longitud de columna (no es una regla fail-closed
        // declarada) -- las 3 aparecen como would_create; el fallo real ocurre
        // recien al intentar persistir dentro de la transaccion de commit().
        $plan = $importer->plan($path);
        $this->assertCount(3, $plan['would_create']);

        try {
            $importer->commit($path);
            $this->fail('Se esperaba que commit() lanzara una excepcion por la fila con rule_key demasiado larga.');
        } catch (\Throwable $e) {
            // cualquier excepcion de BD (QueryException) es la esperada aqui
        }

        $this->assertSame(0, Rule::count(), 'ninguna de las 3 reglas debe quedar persistida -- ni siquiera la primera, que hubiera sido valida por si sola');
        $this->assertSame(0, RuleBinding::count());
    }

    // --- K: nunca toca reglas de otra serie -------------------------------

    public function test_importer_never_touches_rules_from_another_serie(): void
    {
        $this->createActiveStructure();

        $foreignRule = Rule::create([
            'rule_key' => 'a01_a_c_sum_equals_zztest_unrelated', 'rule_type' => 'sum_equals', 'source' => 'excel_formula',
            'name' => 'Regla ajena Serie A', 'description' => 'No debe tocarse', 'category' => 'sum_equals_horizontal',
            'severity' => 'error', 'scope' => 'per_row',
            'config' => ['sheet' => 'A01', 'section' => 'A', 'column' => 'C', 'row_range' => ['from' => 1, 'to' => 1], 'rule_logic' => 'Suma(D) = Columna C'],
            'status' => 'active', 'version' => '1.0.0', 'metadata' => null,
        ]);

        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));
        app(RemRuleManifestImporterService::class)->commit($path);

        $foreignRule->refresh();
        $this->assertSame('Regla ajena Serie A', $foreignRule->name, 'la regla ajena no debe modificarse');
        $this->assertSame(2, Rule::count(), '1 ajena preexistente + 1 nueva del manifiesto');
    }

    // --- L: --commit requiere flag explicito (via el comando Artisan real) ---

    public function test_command_without_commit_flag_is_pure_dry_run(): void
    {
        $this->createActiveStructure();
        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));

        $this->artisan('rem:import-rule-manifest', ['manifest' => $path])
            ->assertExitCode(0);

        $this->assertSame(0, Rule::count());
        $this->assertSame(0, RuleBinding::count());
    }

    public function test_command_with_commit_flag_persists_the_plan(): void
    {
        $structure = $this->createActiveStructure();
        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));

        $this->artisan('rem:import-rule-manifest', ['manifest' => $path, '--commit' => true])
            ->assertExitCode(0);

        $this->assertSame(1, Rule::count());
        $this->assertSame(1, RuleBinding::count());

        // --- M: binding generado con structure + serie + anio correctos ---
        $binding = RuleBinding::first();
        $this->assertSame('structure', $binding->bindable_type);
        $this->assertSame($structure->id, $binding->bindable_id);
        $this->assertSame(self::SERIE, $binding->serie);
        $this->assertSame(self::ANIO, $binding->anio);
        $this->assertTrue($binding->active);
    }

    public function test_command_commit_is_idempotent_on_second_run(): void
    {
        $this->createActiveStructure();
        $path = $this->writeManifest($this->baseManifest([$this->ruleEntry('zztest_a_c_sum_equals')]));

        $this->artisan('rem:import-rule-manifest', ['manifest' => $path, '--commit' => true])->assertExitCode(0);
        $this->assertSame(1, Rule::count());

        // Segunda ejecucion, mismo manifiesto -- debe ser idempotente (skip), nunca duplicar.
        $this->artisan('rem:import-rule-manifest', ['manifest' => $path, '--commit' => true])->assertExitCode(0);
        $this->assertSame(1, Rule::count(), 'no debe crear una segunda copia');
        $this->assertSame(1, RuleBinding::count());
    }
}
