<?php

namespace Tests\Feature\Rem;

use App\Domain\RemParser\Exceptions\PromotionAbortedException;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\CertifiedStructurePromotionService;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CertifiedStructurePromotionService -- mecanismo de promocion segura del
 * estado certificado REM A a otro entorno (diseñado 2026-09-03 tras
 * descartar RemConfigurationSeeder como inseguro para produccion: sobrescribia
 * estructuras historicas por version_number, que no identifica el mismo
 * contenido entre entornos).
 *
 * Cada test construye una "produccion simulada" pequeña (una estructura
 * historica con su propio hash, algunas reglas con contenido divergente) y
 * un paquete certificado tambien pequeño, para poder verificar exactamente
 * que se crea/actualiza/preserva sin depender de las 798/359/649 filas
 * reales.
 */
class CertifiedStructurePromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CertifiedStructurePromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CertifiedStructurePromotionService::class);
    }

    // --- Helpers de construccion ---

    private function seedHistoricalProduction(): RemTemplateStructure
    {
        return RemTemplateStructure::create([
            'anio' => 2026,
            'serie' => 'A',
            'version_number' => 32,
            'hash_estructura' => 'hash-produccion-v32-original',
            'estructura' => ['forms' => ['A01' => ['legacy' => true]]],
            'metadata' => ['origen' => 'produccion'],
            'source_filename' => 'produccion_v32.xlsm',
            'status' => 'active',
        ]);
    }

    private function certifiedPackage(array $overrides = []): array
    {
        $base = [
            'package_type' => 'certified_structure_promotion',
            'generated_at' => now()->toIso8601String(),
            'source_structure' => ['anio' => 2026, 'serie' => 'A', 'version_number' => 35],
            'counts' => ['rules' => 2, 'rule_versions' => 1, 'bindings' => 3, 'excluded_bindings' => 40],
            'structure' => [
                'anio' => 2026,
                'serie' => 'A',
                'version_number' => 35,
                'hash_estructura' => 'hash-certificado-local-v35',
                'estructura' => ['forms' => ['A01' => ['certificado' => true]]],
                'metadata' => ['origen' => 'certificacion-local'],
                'source_filename' => 'certificado_v35.xlsm',
                'notes' => 'Estado final certificado REM A',
                'approved_at' => null,
                'approved_by_email' => null,
            ],
            'rules' => [
                [
                    'rule_key' => 'r1', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog',
                    'name' => 'Regla 1', 'description' => null, 'category' => 'A01', 'severity' => 'error',
                    'scope' => 'per_row', 'config' => ['row_range' => [10, 20]], 'status' => 'active',
                    'version' => '2.0.0', 'metadata' => null, 'created_by_email' => null, 'updated_by_email' => null,
                ],
                [
                    'rule_key' => 'r2', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog',
                    'name' => 'Regla 2', 'description' => null, 'category' => 'A01', 'severity' => 'error',
                    'scope' => 'per_row', 'config' => ['row_range' => [30, 40]], 'status' => 'active',
                    'version' => '1.0.0', 'metadata' => null, 'created_by_email' => null, 'updated_by_email' => null,
                ],
            ],
            'rule_versions' => [
                ['rule_key' => 'r1', 'version' => '2.0.0', 'config' => ['row_range' => [10, 20]], 'changelog' => 'certificacion', 'created_by_email' => null],
            ],
            'bindings' => [
                ['rule_key' => 'r1', 'bindable_type' => 'structure', 'bindable_target' => 'certified_structure', 'serie' => 'A', 'anio' => 2026, 'conditions' => null, 'active' => true],
                ['rule_key' => 'r2', 'bindable_type' => 'structure', 'bindable_target' => 'certified_structure', 'serie' => 'A', 'anio' => 2026, 'conditions' => null, 'active' => true],
                ['rule_key' => 'r1', 'bindable_type' => 'serie', 'bindable_target' => null, 'serie' => 'A', 'anio' => 2026, 'conditions' => null, 'active' => true],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    private function tableCounts(): array
    {
        return [
            RemTemplateStructure::count(),
            Rule::count(),
            RuleVersion::count(),
            RuleBinding::count(),
        ];
    }

    // --- 1: dry-run contra BD vacia ---

    public function test_dry_run_against_empty_database_classifies_everything_as_new(): void
    {
        $plan = $this->service->plan($this->certifiedPackage());

        $this->assertSame(CertifiedStructurePromotionService::NUEVO, $plan['structure']['category']);
        $this->assertSame(1, $plan['structure']['next_version_number']);
        $this->assertCount(2, $plan['rules']['nuevo']);
        $this->assertCount(0, $plan['rules']['actualizar']);
        $this->assertCount(1, $plan['rule_versions']['nuevo']);
        $this->assertCount(3, $plan['bindings']['nuevo']);
        $this->assertFalse($plan['abort']);
    }

    // --- 2: produccion simulada con v32 historica de contenido distinto ---

    public function test_plan_against_simulated_production_detects_new_structure_and_rule_conflicts(): void
    {
        $old = $this->seedHistoricalProduction();

        Rule::create([
            'rule_key' => 'r1', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog',
            'name' => 'Regla 1 (produccion, desactualizada)', 'category' => 'A01', 'severity' => 'error',
            'scope' => 'per_row', 'config' => ['row_range' => [1, 5]], 'status' => 'active', 'version' => '1.0.0',
        ]);
        Rule::create([
            'rule_key' => 'r2', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog',
            'name' => 'Regla 2', 'category' => 'A01', 'severity' => 'error',
            'scope' => 'per_row', 'config' => ['row_range' => [30, 40]], 'status' => 'active', 'version' => '1.0.0',
        ]);

        $plan = $this->service->plan($this->certifiedPackage());

        $this->assertSame(CertifiedStructurePromotionService::NUEVO, $plan['structure']['category']);
        $this->assertSame(33, $plan['structure']['next_version_number']);
        $this->assertSame($old->id, $plan['structure']['current_active_id']);

        // r1 difiere en config/name/version -> ACTUALIZAR con el detalle de campos.
        $this->assertCount(1, $plan['rules']['actualizar']);
        $this->assertSame('r1', $plan['rules']['actualizar'][0]['rule_key']);
        $this->assertArrayHasKey('config', $plan['rules']['actualizar'][0]['changes']);

        // r2 es byte-identica a lo ya certificado -> IDENTICO, no ACTUALIZAR.
        $this->assertContains('r2', $plan['rules']['identico']);

        $this->assertFalse($plan['abort']);
    }

    // --- 3: dry-run no modifica absolutamente ninguna tabla ---

    public function test_dry_run_never_writes_to_any_table(): void
    {
        $this->seedHistoricalProduction();
        Rule::create(['rule_key' => 'r1', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog', 'name' => 'x', 'category' => 'A01', 'severity' => 'error', 'scope' => 'per_row', 'config' => [], 'status' => 'active', 'version' => '1.0.0']);

        $before = $this->tableCounts();
        $this->service->plan($this->certifiedPackage());
        $after = $this->tableCounts();

        $this->assertSame($before, $after);
    }

    // --- 4/5/6/7: commit -- estructura historica intacta, activacion, bindings, reglas, versiones ---

    public function test_commit_preserves_historical_structure_and_promotes_certified_state(): void
    {
        $old = $this->seedHistoricalProduction();
        $originalHash = $old->hash_estructura;
        $originalEstructura = $old->estructura;

        $approver = User::factory()->create();

        $result = $this->service->commit($this->certifiedPackage(), $approver->email);

        // La fila historica: NUNCA se toca su contenido, solo status/superseded_by_id.
        $old->refresh();
        $this->assertSame($originalHash, $old->hash_estructura);
        $this->assertEquals($originalEstructura, $old->estructura);
        $this->assertSame('superseded', $old->status);
        $this->assertSame($result['structure_id'], $old->superseded_by_id);

        // La nueva estructura: version_number = MAX+1, activa, con el contenido certificado.
        $new = RemTemplateStructure::find($result['structure_id']);
        $this->assertSame(33, $new->version_number);
        $this->assertSame('active', $new->status);
        $this->assertSame('hash-certificado-local-v35', $new->hash_estructura);

        // Exactamente 649-equivalente (aqui 3) bindings del paquete, todos
        // resueltos contra el ID REAL de la nueva estructura -- nunca un ID
        // local hardcodeado (el paquete ni siquiera tiene uno que filtrar).
        $this->assertSame(3, RuleBinding::count());
        $structureBindings = RuleBinding::where('bindable_type', 'structure')->get();
        $this->assertCount(2, $structureBindings);
        foreach ($structureBindings as $b) {
            $this->assertSame($new->id, $b->bindable_id);
        }
        $serieBindings = RuleBinding::where('bindable_type', 'serie')->get();
        $this->assertCount(1, $serieBindings);
        $this->assertNull($serieBindings->first()->bindable_id);

        // Las 798 reglas certificadas (aqui 2) quedan en el estado certificado.
        $this->assertSame(2, Rule::count());
        $r1 = Rule::where('rule_key', 'r1')->first();
        $this->assertSame(['row_range' => [10, 20]], $r1->config);
        $this->assertSame('2.0.0', $r1->version);

        // Las rule_versions correspondientes.
        $this->assertSame(1, RuleVersion::count());
        $v = RuleVersion::where('rule_id', $r1->id)->where('version', '2.0.0')->first();
        $this->assertNotNull($v);
    }

    // --- 8: segundo --commit no duplica (hash ya existe -> CONFLICTO -> abort) ---

    public function test_second_commit_with_same_certified_hash_aborts_without_duplicating(): void
    {
        $this->seedHistoricalProduction();
        $approver = User::factory()->create();

        $this->service->commit($this->certifiedPackage(), $approver->email);
        $countsAfterFirst = $this->tableCounts();

        $this->expectException(PromotionAbortedException::class);

        try {
            $this->service->commit($this->certifiedPackage(), $approver->email);
        } finally {
            $this->assertSame($countsAfterFirst, $this->tableCounts());
        }
    }

    // --- 9: rollback completo ante fallo dentro de la transaccion ---

    public function test_commit_rolls_back_completely_on_mid_transaction_failure(): void
    {
        $this->seedHistoricalProduction();
        $approver = User::factory()->create();

        $before = $this->tableCounts();

        // Un rule_version con rule_key ausente del paquete ahora lo detecta
        // plan() por adelantado (ver test de deteccion temprana mas abajo) --
        // ya no sirve para forzar un fallo DENTRO de la transaccion. Para
        // probar el rollback real se usa aqui un fallo autentico de BD que
        // plan() no valida (no es su responsabilidad validar longitudes de
        // columna): 'version' excede el VARCHAR(10) de rem_rule_versions,
        // asi que RuleVersion::create() lanza una QueryException real DESPUES
        // de que la estructura y las reglas ya se escribieron dentro de la
        // misma transaccion.
        $package = $this->certifiedPackage();
        $package['rule_versions'][] = [
            'rule_key' => 'r1',
            'version' => '99.99.99.99.99', // 14 caracteres, excede VARCHAR(10)
            'config' => [],
            'changelog' => null,
            'created_by_email' => null,
        ];

        try {
            $this->service->commit($package, $approver->email);
            $this->fail('Se esperaba una excepcion (QueryException por columna excedida).');
        } catch (\Throwable $e) {
            // esperado -- QueryException real de MySQL, no PromotionAbortedException
        }

        $this->assertSame($before, $this->tableCounts(), 'El rollback debe dejar la BD exactamente como estaba antes del commit.');
    }

    // --- Paquete invalido: binding de tipo structure sin el marcador correcto ---

    public function test_plan_rejects_package_with_raw_structure_reference_in_binding(): void
    {
        $package = $this->certifiedPackage();
        $package['bindings'][0]['bindable_target'] = 'structure:67';

        $this->expectException(PromotionAbortedException::class);
        $this->service->plan($package);
    }

    // --- Binding referenciando una rule_key ausente del paquete -> abort previo ---

    public function test_plan_aborts_when_binding_references_rule_key_not_in_package(): void
    {
        $package = $this->certifiedPackage();
        $package['bindings'][] = [
            'rule_key' => 'r-fantasma', 'bindable_type' => 'serie', 'bindable_target' => null,
            'serie' => 'A', 'anio' => 2026, 'conditions' => null, 'active' => true,
        ];

        $plan = $this->service->plan($package);

        $this->assertTrue($plan['abort']);
        $this->assertContains('r-fantasma', $plan['bindings']['rule_key_not_in_package']);
    }

    // --- Registros productivos fuera del paquete quedan intactos ---

    public function test_records_outside_the_package_remain_untouched(): void
    {
        $this->seedHistoricalProduction();
        $unrelated = Rule::create([
            'rule_key' => 'solo-en-produccion', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog',
            'name' => 'Regla exclusiva de produccion', 'category' => 'X', 'severity' => 'error',
            'scope' => 'per_row', 'config' => ['marker' => true], 'status' => 'active', 'version' => '1.0.0',
        ]);
        $approver = User::factory()->create();

        $this->service->commit($this->certifiedPackage(), $approver->email);

        $unrelated->refresh();
        $this->assertSame(['marker' => true], $unrelated->config);
        $this->assertSame('Regla exclusiva de produccion', $unrelated->name);
        $this->assertSame(3, Rule::count()); // 2 del paquete + 1 preexistente intacta
    }

    // --- Deteccion temprana: rule_version con rule_key ausente del paquete ---
    // (autorizado explicitamente para cerrar la asimetria con bindings)

    public function test_plan_aborts_when_rule_version_references_rule_key_not_in_package(): void
    {
        $package = $this->certifiedPackage();
        $package['rule_versions'][] = [
            'rule_key' => 'r-huerfana-no-existe',
            'version' => '1.0.0',
            'config' => [],
            'changelog' => null,
            'created_by_email' => null,
        ];

        $plan = $this->service->plan($package);

        $this->assertTrue($plan['abort']);
        $this->assertContains('r-huerfana-no-existe@1.0.0', $plan['rule_versions']['rule_key_not_in_package']);
        $this->assertStringContainsString('rule_versions', implode(' ', $plan['abort_reasons']));
    }

    public function test_commit_aborts_before_any_write_when_rule_version_references_rule_key_not_in_package(): void
    {
        $this->seedHistoricalProduction();
        $approver = User::factory()->create();
        $before = $this->tableCounts();

        $package = $this->certifiedPackage();
        $package['rule_versions'][] = [
            'rule_key' => 'r-huerfana-no-existe',
            'version' => '1.0.0',
            'config' => [],
            'changelog' => null,
            'created_by_email' => null,
        ];

        $this->expectException(PromotionAbortedException::class);

        try {
            $this->service->commit($package, $approver->email);
        } finally {
            // No solo "rollback" -- ni siquiera se abrio la transaccion.
            $this->assertSame($before, $this->tableCounts());
        }
    }

    // --- Hallazgo real: dos snapshots legitimos con el mismo (rule_id,
    // version) pero config distinto (caso real: regla 529
    // a32_f_b_sum_equals, RuleVersion 79/80, ver activity_log 1420/1421)
    // deben promoverse como DOS filas, nunca colapsar en una.

    public function test_two_legitimate_version_snapshots_sharing_the_same_version_label_are_both_preserved(): void
    {
        $approver = User::factory()->create();

        // Asignacion directa, NO via el parametro $overrides de
        // certifiedPackage(): array_replace_recursive mezcla listas
        // elemento-a-elemento por indice (y dentro de cada elemento,
        // clave a clave) en vez de reemplazarlas -- inutilizable para
        // sustituir 'rule_versions' por una lista de forma distinta.
        $package = $this->certifiedPackage();
        // Ambos con version="1.0.0" para r2, pero config DISTINTO --
        // exactamente la forma del caso real 79/80.
        $package['rule_versions'][] = [
            'rule_key' => 'r2', 'version' => '1.0.0',
            'config' => ['section' => 'F'],
            'changelog' => 'snapshot pre-remap (analogo a RuleVersion 79)',
            'created_by_email' => null,
        ];
        $package['rule_versions'][] = [
            'rule_key' => 'r2', 'version' => '1.0.0',
            'config' => ['section' => 'F1'],
            'changelog' => 'snapshot pre-restore (analogo a RuleVersion 80)',
            'created_by_email' => null,
        ];

        $plan = $this->service->plan($package);
        $this->assertFalse($plan['abort']);
        // Ninguna de las dos existe todavia -> ambas NUEVO, ninguna colisiona.
        $this->assertCount(3, $plan['rule_versions']['nuevo']); // r1@2.0.0 (del paquete base) + las 2 de r2

        $result = $this->service->commit($package, $approver->email);
        $this->assertSame(3, $result['rule_versions']['created']);
        $this->assertSame(0, $result['rule_versions']['updated']);

        $r2 = Rule::where('rule_key', 'r2')->first();
        $versions = RuleVersion::where('rule_id', $r2->id)->where('version', '1.0.0')->get();

        $this->assertCount(2, $versions, 'Las dos filas con el mismo (rule_id,version) pero config distinto deben preservarse ambas.');
        $configs = $versions->pluck('config')->all();
        $this->assertContains(['section' => 'F'], $configs);
        $this->assertContains(['section' => 'F1'], $configs);
    }

    public function test_re_promoting_the_same_two_snapshots_is_idempotent_and_never_creates_a_third_row(): void
    {
        $approver = User::factory()->create();

        $package = $this->certifiedPackage();
        $package['rule_versions'][] = ['rule_key' => 'r2', 'version' => '1.0.0', 'config' => ['section' => 'F'], 'changelog' => 'c1', 'created_by_email' => null];
        $package['rule_versions'][] = ['rule_key' => 'r2', 'version' => '1.0.0', 'config' => ['section' => 'F1'], 'changelog' => 'c2', 'created_by_email' => null];

        // Se aplican los dos snapshots "a mano" contra una BD ya poblada
        // (simula que ya fueron promovidos antes por otro medio), sin pasar
        // por commit() -- para probar que un plan() posterior los reconoce
        // como IDENTICO/ACTUALIZAR y no como NUEVO.
        $r1 = Rule::create(['rule_key' => 'r1', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog', 'name' => 'Regla 1', 'category' => 'A01', 'severity' => 'error', 'scope' => 'per_row', 'config' => ['row_range' => [10, 20]], 'status' => 'active', 'version' => '2.0.0']);
        $r2 = Rule::create(['rule_key' => 'r2', 'rule_type' => 'sum_equals', 'source' => 'vetted_catalog', 'name' => 'Regla 2', 'category' => 'A01', 'severity' => 'error', 'scope' => 'per_row', 'config' => ['row_range' => [30, 40]], 'status' => 'active', 'version' => '1.0.0']);
        RuleVersion::create(['rule_id' => $r1->id, 'version' => '2.0.0', 'config' => ['row_range' => [10, 20]], 'changelog' => 'certificacion']);
        RuleVersion::create(['rule_id' => $r2->id, 'version' => '1.0.0', 'config' => ['section' => 'F'], 'changelog' => 'c1']);
        RuleVersion::create(['rule_id' => $r2->id, 'version' => '1.0.0', 'config' => ['section' => 'F1'], 'changelog' => 'c2']);

        $plan = $this->service->plan($package);

        // Los 2 snapshots de r2 (F y F1) ya existen con contenido identico,
        // igual que r1@2.0.0 -> nada nuevo, todo IDENTICO. Planificar (sin
        // commit) no debe crear ninguna fila.
        $this->assertCount(0, $plan['rule_versions']['nuevo']);
        $this->assertCount(3, $plan['rule_versions']['identico']);

        $this->assertSame(3, RuleVersion::count(), 'No debe haberse creado ninguna fila de mas solo por planificar.');
    }
}
