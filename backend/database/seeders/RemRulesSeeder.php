<?php

namespace Database\Seeders;

use App\Domain\RuleEngine\Models\Rule;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Siembra rem_rules desde database/seeders/data/rem-rules.json (generado por
 * `php artisan rem:export-seed-data`). Idempotente por rule_key (clave unica
 * de la tabla).
 *
 * Es un superset de RuleCatalogCsvSeeder (529 filas via CSV) -- incluye
 * tambien las reglas que solo existen en la base de datos local (source
 * excel_formula/real_data_pattern/vetted_catalog). Correr ambos seeders es
 * seguro (ambos hacen updateOrCreate por rule_key), pero este ya cubre el
 * total completo.
 */
class RemRulesSeeder extends Seeder
{
    private const FIXTURE_PATH = 'database/seeders/data/rem-rules.json';

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
        foreach ($rows as $row) {
            Rule::updateOrCreate(
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
                    'created_by' => $row['created_by_email'] ? User::where('email', $row['created_by_email'])->first()?->id : null,
                    'updated_by' => $row['updated_by_email'] ? User::where('email', $row['updated_by_email'])->first()?->id : null,
                ]
            );
            $count++;
        }

        $this->command?->info("rem_rules: {$count} filas sembradas/actualizadas.");
    }
}
