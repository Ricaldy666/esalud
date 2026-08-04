<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Console\Command;

class RemDiffStructuresCommand extends Command
{
    protected $signature = 'rem:diff-structures
                            {id1 : ID de la primera estructura}
                            {id2 : ID de la segunda estructura}';

    protected $description = 'Compara dos estructuras y muestra diferencias';

    public function handle(): int
    {
        $id1 = (int) $this->argument('id1');
        $id2 = (int) $this->argument('id2');

        $s1 = RemTemplateStructure::find($id1);
        $s2 = RemTemplateStructure::find($id2);

        if (!$s1 || !$s2) {
            $this->error("Una o ambas estructuras no fueron encontradas.");
            return self::FAILURE;
        }

        $this->line("=== Comparando ID {$id1} vs ID {$id2} ===");
        $this->line("");

        $this->line("  ID {$id1}: {$s1->anio}/{$s1->serie} v{$s1->version_number} | hash: {$s1->hash_estructura}");
        $this->line("  ID {$id2}: {$s2->anio}/{$s2->serie} v{$s2->version_number} | hash: {$s2->hash_estructura}");
        $this->line("");

        if ($s1->hash_estructura === $s2->hash_estructura) {
            $this->info("  ✅ Misma estructura (hash identico)");
            return self::SUCCESS;
        }

        $this->warn("  ⚠️  Hash diferente — estructuras distintas");
        $this->line("");

        $forms1 = $s1->estructura['forms'] ?? [];
        $forms2 = $s2->estructura['forms'] ?? [];

        $names1 = array_column($forms1, 'sheetName');
        $names2 = array_column($forms2, 'sheetName');

        $added = array_diff($names2, $names1);
        $removed = array_diff($names1, $names2);

        if ($added) {
            $this->line("  Hojas agregadas en ID {$id2}: " . implode(', ', $added));
        }
        if ($removed) {
            $this->line("  Hojas eliminadas en ID {$id2}: " . implode(', ', $removed));
        }

        $common = array_intersect($names1, $names2);
        foreach ($common as $name) {
            $idx1 = array_search($name, $names1);
            $idx2 = array_search($name, $names2);
            $f1 = $forms1[$idx1] ?? [];
            $f2 = $forms2[$idx2] ?? [];
            $secs1 = $f1['sections'] ?? [];
            $secs2 = $f2['sections'] ?? [];

            $codigos1 = array_column($secs1, 'codigo');
            $codigos2 = array_column($secs2, 'codigo');

            $secAdded = array_diff($codigos2, $codigos1);
            $secRemoved = array_diff($codigos1, $codigos2);

            if ($secAdded || $secRemoved) {
                $this->line("  [{$name}] secciones cambiadas:");
                if ($secAdded) $this->line("    + agregadas: " . implode(', ', $secAdded));
                if ($secRemoved) $this->line("    - eliminadas: " . implode(', ', $secRemoved));
            }
        }

        return self::SUCCESS;
    }
}
