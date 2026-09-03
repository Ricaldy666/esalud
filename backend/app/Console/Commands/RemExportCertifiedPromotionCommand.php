<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Exporta UNICAMENTE el estado final certificado (una estructura, no todo
 * el historial intermedio) a un paquete JSON pensado para promoverse a otro
 * entorno con `rem:promote-certified-structure` -- distinto de
 * `rem:export-seed-data`, que exporta el historial completo de estructuras
 * para reproducir este mismo entorno en otro lado.
 *
 * Alcance de bindings (auditado y decidido explicitamente, no todo el
 * historico): solo las que aplican SIN ambiguedad al estado certificado --
 * las que apuntan a la estructura certificada (bindable_type=structure) y
 * las estructura-agnosticas (bindable_type=serie). Las que apuntan a
 * cualquier OTRA estructura local (el historial intermedio de la campaña)
 * se excluyen a proposito: no tienen destino valido en otro entorno y se
 * reportan como excluidas, nunca se omiten en silencio.
 *
 * Los bindings de tipo structure NO se exportan con el bindable_id local ni
 * con la clave natural de la estructura certificada -- llevan el
 * marcador BINDABLE_TARGET_CERTIFIED_STRUCTURE, que
 * CertifiedStructurePromotionService resuelve contra el ID que la
 * estructura reciba en el entorno DESTINO. Nunca debe aparecer aqui el ID
 * local de la estructura certificada.
 */
class RemExportCertifiedPromotionCommand extends Command
{
    public const BINDABLE_TARGET_CERTIFIED_STRUCTURE = 'certified_structure';

    protected $signature = 'rem:export-certified-promotion
                            {--structure= : ID de la estructura certificada a exportar (default: la activa de anio=2026,serie=A)}
                            {--out= : Ruta de salida (default: database/seeders/data/rem-certified-promotion.json)}';

    protected $description = 'Exporta el estado final certificado (una sola estructura + catalogo completo de reglas + bindings en alcance) a un paquete JSON para promocion a otro entorno.';

    public function handle(): int
    {
        $structure = $this->resolveStructure();
        if (! $structure) {
            $this->error('No se encontro una estructura certificada para exportar (revise --structure o el estado activo).');

            return self::FAILURE;
        }

        $this->info("Estructura certificada: id={$structure->id} anio={$structure->anio} serie={$structure->serie} version_number={$structure->version_number} status={$structure->status}");

        $expectedRuleCount = Rule::count();
        $expectedVersionCount = RuleVersion::whereHas('rule')->count();

        $expectedBoundToStructure = RuleBinding::where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->count();
        $expectedSerieBindings = RuleBinding::where('bindable_type', 'serie')->count();
        $expectedBindingCount = $expectedBoundToStructure + $expectedSerieBindings;
        $expectedExcludedBindings = RuleBinding::count() - $expectedBindingCount;

        $structurePayload = $this->exportStructure($structure);
        $rulesPayload = $this->exportRules();
        $versionsPayload = $this->exportRuleVersions();
        $bindingsPayload = $this->exportBindings($structure);

        // Auto-verificacion de consistencia: lo que se escribio debe calzar
        // exactamente con lo que se conto antes de construir el paquete --
        // no son numeros hardcodeados, son el mismo estado certificado
        // recontado dos veces por caminos distintos. Cualquier discrepancia
        // aborta sin escribir el archivo.
        $errors = [];
        if (count($rulesPayload) !== $expectedRuleCount) {
            $errors[] = "reglas: esperadas {$expectedRuleCount}, exportadas ".count($rulesPayload);
        }
        if (count($versionsPayload) !== $expectedVersionCount) {
            $errors[] = "rule_versions: esperadas {$expectedVersionCount}, exportadas ".count($versionsPayload);
        }
        if (count($bindingsPayload) !== $expectedBindingCount) {
            $errors[] = "bindings: esperados {$expectedBindingCount}, exportados ".count($bindingsPayload);
        }

        if ($errors) {
            $this->error('Abortado -- el paquete no corresponde al estado certificado esperado:');
            foreach ($errors as $e) {
                $this->error("  - {$e}");
            }

            return self::FAILURE;
        }

        $package = [
            'package_type' => 'certified_structure_promotion',
            'generated_at' => now()->toIso8601String(),
            'source_structure' => [
                'anio' => $structure->anio,
                'serie' => $structure->serie,
                'version_number' => $structure->version_number,
            ],
            'counts' => [
                'rules' => count($rulesPayload),
                'rule_versions' => count($versionsPayload),
                'bindings' => count($bindingsPayload),
                'excluded_bindings' => $expectedExcludedBindings,
            ],
            'structure' => $structurePayload,
            'rules' => $rulesPayload,
            'rule_versions' => $versionsPayload,
            'bindings' => $bindingsPayload,
        ];

        $outPath = $this->option('out') ?: base_path('database/seeders/data/rem-certified-promotion.json');
        file_put_contents($outPath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Paquete escrito en: {$outPath}");
        $this->table(
            ['Contenido', 'Cantidad'],
            [
                ['Estructura certificada', 1],
                ['Reglas (rule_key)', count($rulesPayload)],
                ['Rule versions', count($versionsPayload)],
                ['Bindings en alcance', count($bindingsPayload).' ('.$expectedBoundToStructure.' structure + '.$expectedSerieBindings.' serie)'],
                ['Bindings excluidos (historial intermedio)', $expectedExcludedBindings],
            ]
        );

        return self::SUCCESS;
    }

    private function resolveStructure(): ?RemTemplateStructure
    {
        if ($id = $this->option('structure')) {
            return RemTemplateStructure::find((int) $id);
        }

        return RemTemplateStructure::where('anio', 2026)
            ->where('serie', 'A')
            ->where('status', 'active')
            ->first();
    }

    private function emailFor(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        return User::find($userId)?->email;
    }

    private function exportStructure(RemTemplateStructure $s): array
    {
        return [
            'anio' => $s->anio,
            'serie' => $s->serie,
            'version_number' => $s->version_number,
            'hash_estructura' => $s->hash_estructura,
            'estructura' => $s->estructura,
            'metadata' => $s->metadata,
            'source_filename' => $s->source_filename,
            'notes' => $s->notes,
            'approved_at' => $s->approved_at?->toIso8601String(),
            'approved_by_email' => $this->emailFor($s->approved_by),
        ];
    }

    private function exportRules(): array
    {
        return Rule::orderBy('id')->get()->map(fn (Rule $r) => [
            'rule_key' => $r->rule_key,
            'rule_type' => $r->rule_type,
            'source' => $r->source,
            'name' => $r->name,
            'description' => $r->description,
            'category' => $r->category,
            'severity' => $r->severity,
            'scope' => $r->scope,
            'config' => $r->config,
            'status' => $r->status,
            'version' => $r->version,
            'metadata' => $r->metadata,
            'created_by_email' => $this->emailFor($r->created_by),
            'updated_by_email' => $this->emailFor($r->updated_by),
        ])->values()->all();
    }

    private function exportRuleVersions(): array
    {
        return RuleVersion::with('rule')->orderBy('id')->get()
            ->filter(fn (RuleVersion $v) => $v->rule !== null)
            ->map(fn (RuleVersion $v) => [
                'rule_key' => $v->rule->rule_key,
                'version' => $v->version,
                'config' => $v->config,
                'changelog' => $v->changelog,
                'created_by_email' => $this->emailFor($v->created_by),
            ])->values()->all();
    }

    private function exportBindings(RemTemplateStructure $structure): array
    {
        $boundToStructure = RuleBinding::with('rule')
            ->where('bindable_type', 'structure')
            ->where('bindable_id', $structure->id)
            ->orderBy('id')
            ->get();

        $serieBindings = RuleBinding::with('rule')
            ->where('bindable_type', 'serie')
            ->orderBy('id')
            ->get();

        return $boundToStructure->concat($serieBindings)
            ->filter(fn (RuleBinding $b) => $b->rule !== null)
            ->map(fn (RuleBinding $b) => [
                'rule_key' => $b->rule->rule_key,
                'bindable_type' => $b->bindable_type,
                'bindable_target' => $b->bindable_type === 'structure'
                    ? self::BINDABLE_TARGET_CERTIFIED_STRUCTURE
                    : null,
                'serie' => $b->serie,
                'anio' => $b->anio,
                'conditions' => $b->conditions,
                'active' => $b->active,
            ])->values()->all();
    }
}
