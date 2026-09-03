<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\Exceptions\PromotionAbortedException;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Promueve un paquete de estado certificado (generado por
 * `rem:export-certified-promotion`) al entorno donde este servicio se
 * ejecute -- local para probar, o el servidor de produccion una vez
 * desplegado el codigo. Nunca conecta a otro entorno por si mismo: opera
 * sobre la conexion de BD del entorno donde corre, igual que
 * RemConfigurationSeeder.
 *
 * Principio no negociable: una estructura historica existente (cualquier
 * fila ya presente en rem_template_structures) NUNCA se modifica en su
 * `estructura`/`hash_estructura`. La unica escritura sobre una fila
 * existente es el status/superseded_by_id que ya hace
 * StructureApprovalService::activate() -- el mismo mecanismo que la app usa
 * en producción real cada vez que se activa una version nueva.
 *
 * Reglas/rule_versions SI se actualizan por clave natural (rule_key /
 * rule_key+version) -- a diferencia de las estructuras, son un catalogo
 * vivo donde rule_key es una clave estable y significativa entre entornos,
 * no un contador local coincidente. Ver auditoria previa (CLAUDE.md /
 * conversación) para la distincion completa.
 */
class CertifiedStructurePromotionService
{
    public const NUEVO = 'NUEVO';

    public const IDENTICO = 'IDENTICO';

    public const ACTUALIZAR = 'ACTUALIZAR';

    public const CONFLICTO = 'CONFLICTO';

    /** Campos de rem_rules comparados para clasificar NUEVO/IDENTICO/ACTUALIZAR. */
    private const RULE_FIELDS = [
        'rule_type', 'source', 'name', 'description', 'category',
        'severity', 'scope', 'config', 'status', 'version', 'metadata',
    ];

    public function __construct(
        private readonly StructureVersioningService $versioning,
        private readonly StructureApprovalService $approval,
    ) {
    }

    /**
     * 100% lectura -- nunca escribe. Construye el plan completo (por tabla)
     * y decide si hay que abortar antes de siquiera considerar --commit.
     */
    public function plan(array $package): array
    {
        $this->assertPackageShape($package);

        $structurePlan = $this->planStructure($package['structure']);

        $ruleKeysInPackage = array_column($package['rules'], 'rule_key');
        $rulesPlan = $this->planRules($package['rules']);
        $versionsPlan = $this->planRuleVersions($package['rule_versions'], $ruleKeysInPackage);
        $bindingsPlan = $this->planBindings($package['bindings'], $structurePlan, $ruleKeysInPackage);

        $abortReasons = [];

        if ($structurePlan['category'] === self::CONFLICTO) {
            $abortReasons[] = $structurePlan['reason'];
        }

        if (! empty($versionsPlan['rule_key_not_in_package'])) {
            $abortReasons[] = 'rule_versions referencian rule_key ausentes del propio paquete: '
                .implode(', ', array_slice($versionsPlan['rule_key_not_in_package'], 0, 5))
                .(count($versionsPlan['rule_key_not_in_package']) > 5 ? '...' : '');
        }

        if (! empty($bindingsPlan['rule_key_not_in_package'])) {
            $abortReasons[] = 'Bindings referencian rule_key ausentes del propio paquete: '
                .implode(', ', array_slice($bindingsPlan['rule_key_not_in_package'], 0, 5))
                .(count($bindingsPlan['rule_key_not_in_package']) > 5 ? '...' : '');
        }

        return [
            'structure' => $structurePlan,
            'rules' => $rulesPlan,
            'rule_versions' => $versionsPlan,
            'bindings' => $bindingsPlan,
            'excluded_bindings_in_package' => $package['counts']['excluded_bindings'] ?? null,
            'abort' => ! empty($abortReasons),
            'abort_reasons' => $abortReasons,
        ];
    }

    /**
     * Escribe. Vuelve a calcular plan() internamente (nunca confia en un
     * plan calculado antes por el llamador, que pudo quedar obsoleto) y
     * aborta con PromotionAbortedException si hay cualquier conflicto no
     * previsto -- nunca escribe parcialmente. Todo dentro de una unica
     * transaccion: si cualquier paso falla, rollback completo.
     *
     * @param  string  $approvedByEmail  Usuario de ESTE entorno que aprueba la
     *                                    activacion -- StructureApprovalService::approve()
     *                                    exige un ID real, nunca null.
     */
    public function commit(array $package, string $approvedByEmail): array
    {
        $plan = $this->plan($package);

        if ($plan['abort']) {
            throw new PromotionAbortedException(
                'Promoción abortada, conflicto no previsto: '.implode(' | ', $plan['abort_reasons'])
            );
        }

        $approver = User::where('email', $approvedByEmail)->first();
        if (! $approver) {
            throw new PromotionAbortedException(
                "No existe un usuario con email={$approvedByEmail} en este entorno -- requerido para aprobar la activacion."
            );
        }

        return DB::transaction(function () use ($package, $plan, $approver) {
            $structure = $this->createAndActivateStructure($package['structure'], $plan['structure'], $approver->id);

            [$ruleIdsByKey, $rulesCreated, $rulesUpdated] = $this->writeRules($package['rules']);
            [$versionsCreated, $versionsUpdated] = $this->writeRuleVersions($package['rule_versions'], $ruleIdsByKey);
            [$bindingsCreated, $bindingsUpdated] = $this->writeBindings($package['bindings'], $ruleIdsByKey, $structure->id);

            return [
                'structure_id' => $structure->id,
                'structure_version_number' => $structure->version_number,
                'superseded_id' => $plan['structure']['current_active_id'] ?? null,
                'rules' => ['created' => $rulesCreated, 'updated' => $rulesUpdated],
                'rule_versions' => ['created' => $versionsCreated, 'updated' => $versionsUpdated],
                'bindings' => ['created' => $bindingsCreated, 'updated' => $bindingsUpdated],
            ];
        });
    }

    private function assertPackageShape(array $package): void
    {
        foreach (['structure', 'rules', 'rule_versions', 'bindings'] as $key) {
            if (! array_key_exists($key, $package)) {
                throw new PromotionAbortedException("Paquete invalido: falta la clave '{$key}'.");
            }
        }

        // Ningun binding de tipo structure debe llevar un ID/clave local --
        // solo el marcador que este servicio resuelve contra la estructura
        // recien creada en ESTE entorno. Un paquete que no lo respete es
        // exactamente el tipo de conflicto no previsto que debe abortar.
        foreach ($package['bindings'] as $row) {
            if (($row['bindable_type'] ?? null) === 'structure'
                && ($row['bindable_target'] ?? null) !== 'certified_structure') {
                throw new PromotionAbortedException(
                    "Paquete invalido: binding de tipo structure para rule_key={$row['rule_key']} "
                    ."no lleva el marcador 'certified_structure' (bindable_target=".json_encode($row['bindable_target'] ?? null).')'
                );
            }
        }
    }

    // --- Planificacion (read-only) ---

    private function planStructure(array $s): array
    {
        $existingSameHash = RemTemplateStructure::where('anio', $s['anio'])
            ->where('serie', $s['serie'])
            ->where('hash_estructura', $s['hash_estructura'])
            ->first();

        if ($existingSameHash) {
            return [
                'category' => self::CONFLICTO,
                'reason' => "Ya existe una estructura con hash_estructura={$s['hash_estructura']} en este entorno "
                    ."(anio={$s['anio']}, serie={$s['serie']}, version_number={$existingSameHash->version_number}, id={$existingSameHash->id}) "
                    .'-- se aborta para no duplicar una promocion ya realizada.',
                'existing_id' => $existingSameHash->id,
                'existing_version_number' => $existingSameHash->version_number,
            ];
        }

        $currentActive = RemTemplateStructure::where('anio', $s['anio'])
            ->where('serie', $s['serie'])
            ->where('status', 'active')
            ->first();

        return [
            'category' => self::NUEVO,
            'next_version_number' => $this->versioning->resolveNextVersion($s['anio'], $s['serie']),
            'current_active_id' => $currentActive?->id,
            'current_active_version_number' => $currentActive?->version_number,
            'current_active_hash' => $currentActive?->hash_estructura,
        ];
    }

    private function planRules(array $rules): array
    {
        $nuevo = [];
        $identico = [];
        $actualizar = [];

        foreach ($rules as $row) {
            $existing = Rule::where('rule_key', $row['rule_key'])->first();

            if (! $existing) {
                $nuevo[] = $row['rule_key'];

                continue;
            }

            $changes = $this->diffFields($existing, $row, self::RULE_FIELDS);

            if (empty($changes)) {
                $identico[] = $row['rule_key'];
            } else {
                $actualizar[] = ['rule_key' => $row['rule_key'], 'changes' => $changes];
            }
        }

        return [
            'nuevo' => $nuevo,
            'identico' => $identico,
            'actualizar' => $actualizar,
            'intact_count' => Rule::count() - (count($identico) + count($actualizar)),
        ];
    }

    /**
     * (rule_id, version) NO identifica de forma unica un snapshot de
     * auditoria: rule:remap-section y rule:restore-config-version crean
     * cada uno una fila nueva en rem_rule_versions tomando 'version' de
     * rem_rules.version en ese momento, sin incrementarlo -- dos snapshots
     * de contenido (config) genuinamente distinto pueden compartir
     * legitimamente el mismo (rule_id, version) (caso real encontrado:
     * regla 529 a32_f_b_sum_equals, RuleVersion 79 y 80, mismo version
     * "1.0.0", dos operaciones reales el 2026-08-27 -- ver activity_log
     * 1420/1421). Tratarlas como la misma fila destruiria evidencia de
     * auditoria real. Por eso el match de "ya existe" exige tambien
     * `config` identico -- si (rule_id, version) coincide pero el config
     * difiere, es un snapshot ADICIONAL, nunca una sobreescritura.
     */
    private function findMatchingVersion(int $ruleId, array $row): ?RuleVersion
    {
        return RuleVersion::where('rule_id', $ruleId)
            ->where('version', $row['version'])
            ->get()
            ->first(fn (RuleVersion $v) => $v->config === $row['config']);
    }

    private function planRuleVersions(array $versions, array $ruleKeysInPackage): array
    {
        $nuevo = [];
        $identico = [];
        $actualizar = [];
        $ruleKeyNotFound = [];

        foreach ($versions as $row) {
            if (! in_array($row['rule_key'], $ruleKeysInPackage, true)) {
                $ruleKeyNotFound[] = "{$row['rule_key']}@{$row['version']}";

                continue;
            }

            $rule = Rule::where('rule_key', $row['rule_key'])->first();
            $label = "{$row['rule_key']}@{$row['version']}";

            $existing = $rule ? $this->findMatchingVersion($rule->id, $row) : null;

            if (! $existing) {
                // O bien no hay ninguna fila (rule_id,version), o hay una
                // con config DISTINTO -- en ambos casos es un snapshot
                // nuevo, nunca se sobrescribe uno existente con contenido
                // diferente.
                $nuevo[] = $label;

                continue;
            }

            $changes = [];
            if (($existing->changelog ?? null) !== ($row['changelog'] ?? null)) {
                $changes['changelog'] = ['from' => $existing->changelog, 'to' => $row['changelog']];
            }

            if (empty($changes)) {
                $identico[] = $label;
            } else {
                $actualizar[] = ['key' => $label, 'changes' => $changes];
            }
        }

        return [
            'nuevo' => $nuevo,
            'identico' => $identico,
            'actualizar' => $actualizar,
            'rule_key_not_in_package' => $ruleKeyNotFound,
            'intact_count' => RuleVersion::count() - (count($identico) + count($actualizar)),
        ];
    }

    private function planBindings(array $bindings, array $structurePlan, array $ruleKeysInPackage): array
    {
        $nuevo = [];
        $identico = [];
        $actualizar = [];
        $ruleKeyNotFound = [];

        // Solo tiene sentido comparar bindings de tipo structure contra un
        // ID ya existente si la estructura misma esta en CONFLICTO (ya
        // existe) -- si es NUEVO, por definicion ningun binding puede
        // referenciarla todavia, todas seran NUEVO.
        $comparisonStructureId = $structurePlan['category'] === self::CONFLICTO
            ? $structurePlan['existing_id']
            : null;

        foreach ($bindings as $row) {
            if (! in_array($row['rule_key'], $ruleKeysInPackage, true)) {
                $ruleKeyNotFound[] = $row['rule_key'];

                continue;
            }

            $rule = Rule::where('rule_key', $row['rule_key'])->first();
            $label = $row['rule_key'].'/'.$row['bindable_type'];

            if (! $rule) {
                // La regla existe en el paquete pero todavia no en este
                // entorno -- el binding sera NUEVO en cuanto se cree la regla.
                $nuevo[] = $label;

                continue;
            }

            if ($row['bindable_type'] === 'structure' && $comparisonStructureId === null) {
                $nuevo[] = $label;

                continue;
            }

            $bindableId = $row['bindable_type'] === 'structure' ? $comparisonStructureId : null;

            $existing = RuleBinding::where('rule_id', $rule->id)
                ->where('bindable_type', $row['bindable_type'])
                ->where('bindable_id', $bindableId)
                ->where('serie', $row['serie'] ?? null)
                ->where('anio', $row['anio'] ?? null)
                ->first();

            if (! $existing) {
                $nuevo[] = $label;

                continue;
            }

            $changes = [];
            if (($existing->conditions ?? null) !== ($row['conditions'] ?? null)) {
                $changes['conditions'] = ['from' => $existing->conditions, 'to' => $row['conditions']];
            }
            if ((bool) $existing->active !== (bool) ($row['active'] ?? true)) {
                $changes['active'] = ['from' => $existing->active, 'to' => $row['active']];
            }

            if (empty($changes)) {
                $identico[] = $label;
            } else {
                $actualizar[] = ['key' => $label, 'changes' => $changes];
            }
        }

        return [
            'nuevo' => $nuevo,
            'identico' => $identico,
            'actualizar' => $actualizar,
            'rule_key_not_in_package' => $ruleKeyNotFound,
            'intact_count' => RuleBinding::count() - (count($identico) + count($actualizar)),
        ];
    }

    private function diffFields($existing, array $row, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $existing->{$field};
            $new = $row[$field] ?? null;
            if ($old !== $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    // --- Escritura (solo dentro de commit(), ya validado por plan()) ---

    private function createAndActivateStructure(array $s, array $structurePlan, int $approverId): RemTemplateStructure
    {
        $structure = RemTemplateStructure::create([
            'anio' => $s['anio'],
            'serie' => $s['serie'],
            'version_number' => $structurePlan['next_version_number'],
            'hash_estructura' => $s['hash_estructura'],
            'estructura' => $s['estructura'],
            'metadata' => $s['metadata'] ?? null,
            'source_filename' => $s['source_filename'] ?? null,
            'status' => 'draft',
            'notes' => $s['notes'] ?? null,
        ]);

        $this->approval->approve($structure, $approverId);
        $this->approval->activate($structure->fresh());

        return $structure->fresh();
    }

    private function writeRules(array $rules): array
    {
        $ruleIdsByKey = [];
        $created = 0;
        $updated = 0;

        foreach ($rules as $row) {
            $existed = Rule::where('rule_key', $row['rule_key'])->exists();

            $rule = Rule::updateOrCreate(
                ['rule_key' => $row['rule_key']],
                [
                    'rule_type' => $row['rule_type'],
                    'source' => $row['source'],
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'category' => $row['category'] ?? null,
                    'severity' => $row['severity'],
                    'scope' => $row['scope'],
                    'config' => $row['config'],
                    'status' => $row['status'],
                    'version' => $row['version'],
                    'metadata' => $row['metadata'] ?? null,
                    'created_by' => $this->resolveUserId($row['created_by_email'] ?? null),
                    'updated_by' => $this->resolveUserId($row['updated_by_email'] ?? null),
                ]
            );

            $ruleIdsByKey[$row['rule_key']] = $rule->id;
            $existed ? $updated++ : $created++;
        }

        return [$ruleIdsByKey, $created, $updated];
    }

    private function writeRuleVersions(array $versions, array $ruleIdsByKey): array
    {
        $created = 0;
        $updated = 0;

        foreach ($versions as $row) {
            $ruleId = $ruleIdsByKey[$row['rule_key']] ?? null;
            if (! $ruleId) {
                // No deberia ocurrir -- plan() ya valida que toda version
                // referencie una rule_key presente en el paquete.
                throw new PromotionAbortedException("rule_version sin regla resuelta: {$row['rule_key']}@{$row['version']}");
            }

            $existing = $this->findMatchingVersion($ruleId, $row);

            if ($existing) {
                // Mismo (rule_id, version, config) -- solo el changelog
                // puede diferir (metadato, no contenido de auditoria).
                // Nunca se toca 'config' de una fila ya existente.
                $existing->update([
                    'changelog' => $row['changelog'] ?? null,
                    'created_by' => $this->resolveUserId($row['created_by_email'] ?? null),
                ]);
                $updated++;
            } else {
                // (rule_id, version) puede ya existir con OTRO config --
                // eso no es un update, es un snapshot adicional legitimo
                // (ver findMatchingVersion()). create(), nunca updateOrCreate.
                RuleVersion::create([
                    'rule_id' => $ruleId,
                    'version' => $row['version'],
                    'config' => $row['config'],
                    'changelog' => $row['changelog'] ?? null,
                    'created_by' => $this->resolveUserId($row['created_by_email'] ?? null),
                ]);
                $created++;
            }
        }

        return [$created, $updated];
    }

    private function writeBindings(array $bindings, array $ruleIdsByKey, int $newStructureId): array
    {
        $created = 0;
        $updated = 0;

        foreach ($bindings as $row) {
            $ruleId = $ruleIdsByKey[$row['rule_key']] ?? null;
            if (! $ruleId) {
                throw new PromotionAbortedException("binding sin regla resuelta: {$row['rule_key']}");
            }

            $bindableId = $row['bindable_type'] === 'structure' ? $newStructureId : null;

            $existed = RuleBinding::where('rule_id', $ruleId)
                ->where('bindable_type', $row['bindable_type'])
                ->where('bindable_id', $bindableId)
                ->where('serie', $row['serie'] ?? null)
                ->where('anio', $row['anio'] ?? null)
                ->exists();

            RuleBinding::updateOrCreate(
                [
                    'rule_id' => $ruleId,
                    'bindable_type' => $row['bindable_type'],
                    'bindable_id' => $bindableId,
                    'serie' => $row['serie'] ?? null,
                    'anio' => $row['anio'] ?? null,
                ],
                [
                    'conditions' => $row['conditions'] ?? null,
                    'active' => $row['active'] ?? true,
                ]
            );

            $existed ? $updated++ : $created++;
        }

        return [$created, $updated];
    }

    private function resolveUserId(?string $email): ?int
    {
        return $email ? User::where('email', $email)->first()?->id : null;
    }
}
