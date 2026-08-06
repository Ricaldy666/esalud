<?php

namespace Database\Seeders;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use Illuminate\Database\Seeder;

/**
 * Siembra rem_rule_bindings desde database/seeders/data/rem-rule-bindings.json.
 * Debe correr DESPUES de RemRulesSeeder y RemTemplateStructureSeeder --
 * resuelve rule_id por rule_key y, cuando bindable_type='structure',
 * bindable_id por la clave natural (anio, serie, version_number) de la
 * estructura ya sembrada en ESTE entorno. rem_rule_bindings.bindable_id no
 * tiene una foreign key real en la base de datos (es una referencia a nivel
 * de aplicacion), por lo que esta resolucion es la unica proteccion contra
 * bindings huerfanos.
 *
 * Idempotente por (rule_id, bindable_type, bindable_id, serie, anio) --
 * confirmado sin colisiones en los datos de origen antes de elegir esta
 * clave.
 */
class RemRuleBindingsSeeder extends Seeder
{
    private const FIXTURE_PATH = 'database/seeders/data/rem-rule-bindings.json';

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
        $skippedNoRule = 0;
        $skippedNoStructure = 0;

        foreach ($rows as $row) {
            $rule = Rule::where('rule_key', $row['rule_key'])->first();
            if (! $rule) {
                $skippedNoRule++;

                continue;
            }

            $bindableId = null;
            if ($row['bindable_type'] === 'structure') {
                if (empty($row['bindable_structure'])) {
                    $skippedNoStructure++;

                    continue;
                }

                $structure = RemTemplateStructure::where([
                    'anio' => $row['bindable_structure']['anio'],
                    'serie' => $row['bindable_structure']['serie'],
                    'version_number' => $row['bindable_structure']['version_number'],
                ])->first();

                if (! $structure) {
                    $skippedNoStructure++;

                    continue;
                }

                $bindableId = $structure->id;
            }

            RuleBinding::updateOrCreate(
                [
                    'rule_id' => $rule->id,
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
            $count++;
        }

        $message = "rem_rule_bindings: {$count} filas sembradas/actualizadas.";
        if ($skippedNoRule) {
            $message .= " {$skippedNoRule} omitidas (rule_key no encontrado).";
        }
        if ($skippedNoStructure) {
            $message .= " {$skippedNoStructure} omitidas (estructura referenciada no encontrada).";
        }
        $this->command?->info($message);
    }
}
