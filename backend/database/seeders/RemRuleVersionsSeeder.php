<?php

namespace Database\Seeders;

use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Siembra rem_rule_versions desde database/seeders/data/rem-rule-versions.json.
 * Debe correr DESPUES de RemRulesSeeder -- resuelve rule_id consultando
 * rem_rules por rule_key en este mismo entorno. Idempotente por
 * (rule_id, version).
 */
class RemRuleVersionsSeeder extends Seeder
{
    private const FIXTURE_PATH = 'database/seeders/data/rem-rule-versions.json';

    public function run(): void
    {
        $path = base_path(self::FIXTURE_PATH);
        if (! file_exists($path)) {
            $this->command?->error("Fixture no encontrado: {$path}. Corra 'php artisan rem:export-seed-data' en el entorno local primero.");

            return;
        }

        $rows = json_decode(file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->command?->error("Fixture invalido: {$path}");

            return;
        }

        $count = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $rule = Rule::where('rule_key', $row['rule_key'])->first();
            if (! $rule) {
                $skipped++;

                continue;
            }

            RuleVersion::updateOrCreate(
                ['rule_id' => $rule->id, 'version' => $row['version']],
                [
                    'config' => $row['config'],
                    'changelog' => $row['changelog'] ?? null,
                    'created_by' => $row['created_by_email'] ? User::where('email', $row['created_by_email'])->first()?->id : null,
                ]
            );
            $count++;
        }

        $this->command?->info("rem_rule_versions: {$count} filas sembradas/actualizadas".($skipped ? ", {$skipped} omitidas (rule_key no encontrado)" : '').'.');
    }
}
