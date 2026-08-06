<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Exporta la configuracion REM (estructuras detectadas, reglas, versiones de
 * reglas y bindings) de la base de datos LOCAL a fixtures JSON versionables
 * en database/seeders/data/, para poder sembrarla despues en cualquier otro
 * entorno -- incluida produccion -- con los seeders idempotentes
 * correspondientes.
 *
 * Todas las referencias se exportan por CLAVE NATURAL, nunca por ID crudo:
 * usuarios por email, reglas por rule_key, estructuras por
 * (anio, serie, version_number). Esto es deliberado -- los IDs
 * autoincrementales locales (ej. rem_template_structures.id=19/21,
 * referenciados por 661 rem_rule_bindings) no tienen ninguna garantia de
 * coincidir con los IDs que esas mismas filas reciban al sembrarse en otro
 * entorno.
 *
 * rem_template_structures.rem_template_id NO se exporta/preserva a
 * proposito: 14 de 17 filas locales apuntan a rem_templates.id=1 pero la
 * app resuelve la estructura activa por (anio,serie,status), no por esta
 * columna -- parece vestigial. Se sembrara como null.
 *
 * Uso: solo en el entorno LOCAL, nunca en produccion. Los fixtures
 * resultantes se comitean y se siembran del lado del servidor con
 * `php artisan db:seed --class="Database\Seeders\RemConfigurationSeeder"`.
 *
 * Explicitamente fuera de alcance de este comando (no son filas de BD):
 * storage/app/private/certificacion/reglas-funcionales.json y
 * storage/app/private/certificacion/cell-data/*.json -- se transfieren por
 * separado (ver plan de despliegue).
 */
class RemExportSeedDataCommand extends Command
{
    protected $signature = 'rem:export-seed-data';

    protected $description = 'Exporta rem_template_structures, rem_rules, rem_rule_versions y rem_rule_bindings a fixtures JSON en database/seeders/data/, usando claves naturales en vez de IDs crudos.';

    public function handle(): int
    {
        $targetDir = database_path('seeders/data');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $this->exportStructures($targetDir);
        $this->exportRules($targetDir);
        $this->exportRuleVersions($targetDir);
        $this->exportRuleBindings($targetDir);

        $this->newLine();
        $this->warn(
            'IMPORTANTE: reglas-funcionales.json y storage/app/private/certificacion/cell-data/*.json '.
            'NO se exportaron aqui -- son archivos en disco, no filas de BD. Transferirlos por separado.'
        );

        return self::SUCCESS;
    }

    private function emailFor(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        return User::find($userId)?->email;
    }

    private function writeJson(string $path, array $rows): void
    {
        file_put_contents(
            $path,
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * rem-template-structures.json sin comprimir pesa ~62 MB (17 versiones,
     * la mayoria con una estructura de 27 hojas casi identica entre si) --
     * demasiado grande para comitear comodamente. Comprimido queda en ~2 MB
     * (la repeticion entre versiones comprime muy bien). Es el unico
     * fixture que se escribe asi; los otros tres son pequeños y quedan en
     * JSON plano, legibles en el diff de git.
     */
    private function writeCompressedJson(string $path, array $rows): void
    {
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, gzencode($json, 9));
    }

    private function exportStructures(string $dir): void
    {
        $structures = RemTemplateStructure::orderBy('id')->get();
        $byId = $structures->keyBy('id');

        $rows = $structures->map(function (RemTemplateStructure $s) use ($byId) {
            $supersededByKey = null;
            if ($s->superseded_by_id && $byId->has($s->superseded_by_id)) {
                $target = $byId->get($s->superseded_by_id);
                $supersededByKey = [
                    'anio' => $target->anio,
                    'serie' => $target->serie,
                    'version_number' => $target->version_number,
                ];
            }

            return [
                'anio' => $s->anio,
                'serie' => $s->serie,
                'version_number' => $s->version_number,
                'hash_estructura' => $s->hash_estructura,
                'estructura' => $s->estructura,
                'metadata' => $s->metadata,
                'source_filename' => $s->source_filename,
                'status' => $s->status,
                'approved_at' => $s->approved_at?->toIso8601String(),
                'approved_by_email' => $this->emailFor($s->approved_by),
                'superseded_by' => $supersededByKey,
                'notes' => $s->notes,
            ];
        })->values()->all();

        $this->writeCompressedJson($dir.'/rem-template-structures.json.gz', $rows);
        $this->info('rem-template-structures.json.gz: '.count($rows).' filas (comprimido)');
    }

    private function exportRules(string $dir): void
    {
        $rows = Rule::orderBy('id')->get()->map(fn (Rule $r) => [
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

        $this->writeJson($dir.'/rem-rules.json', $rows);
        $this->info('rem-rules.json: '.count($rows).' filas');
    }

    private function exportRuleVersions(string $dir): void
    {
        $rows = RuleVersion::with('rule')->orderBy('id')->get()
            ->filter(fn (RuleVersion $v) => $v->rule !== null)
            ->map(fn (RuleVersion $v) => [
                'rule_key' => $v->rule->rule_key,
                'version' => $v->version,
                'config' => $v->config,
                'changelog' => $v->changelog,
                'created_by_email' => $this->emailFor($v->created_by),
            ])->values()->all();

        $this->writeJson($dir.'/rem-rule-versions.json', $rows);
        $this->info('rem-rule-versions.json: '.count($rows).' filas');
    }

    private function exportRuleBindings(string $dir): void
    {
        $structuresById = RemTemplateStructure::all()->keyBy('id');

        $rows = RuleBinding::with('rule')->orderBy('id')->get()
            ->filter(fn (RuleBinding $b) => $b->rule !== null)
            ->map(function (RuleBinding $b) use ($structuresById) {
                $structureKey = null;
                if ($b->bindable_type === 'structure' && $b->bindable_id && $structuresById->has($b->bindable_id)) {
                    $s = $structuresById->get($b->bindable_id);
                    $structureKey = [
                        'anio' => $s->anio,
                        'serie' => $s->serie,
                        'version_number' => $s->version_number,
                    ];
                }

                return [
                    'rule_key' => $b->rule->rule_key,
                    'bindable_type' => $b->bindable_type,
                    'bindable_structure' => $structureKey,
                    'serie' => $b->serie,
                    'anio' => $b->anio,
                    'conditions' => $b->conditions,
                    'active' => $b->active,
                ];
            })->values()->all();

        $this->writeJson($dir.'/rem-rule-bindings.json', $rows);
        $this->info('rem-rule-bindings.json: '.count($rows).' filas');
    }
}
