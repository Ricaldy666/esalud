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
