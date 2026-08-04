<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemFormulaRuleBuilder;
use Illuminate\Console\Command;

class RemTestParserCommand extends Command
{
    protected $signature = 'rem:test-parser {structure_id : ID de la estructura}';
    protected $description = 'Prueba el parser legacy: genera DTOs desde la estructura e imprime estadisticas';

    public function handle(): int
    {
        $structureId = (int) $this->argument('structure_id');

        $structure = RemTemplateStructure::withTrashed()->find($structureId);
        if (!$structure) {
            $this->error("Estructura ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $builder = new RemFormulaRuleBuilder;
        $rules = $builder->build($structure);

        $this->info("=== rem:test-parser {$structureId} ===");
        $this->line("Estructura: {$structure->serie} {$structure->anio} v{$structure->version_number}");
        $this->line("Total reglas generadas: " . count($rules));

        $byType = [];
        $byScope = [];
        foreach ($rules as $r) {
            $byType[$r->ruleType] = ($byType[$r->ruleType] ?? 0) + 1;
            $byScope[$r->scope] = ($byScope[$r->scope] ?? 0) + 1;
        }

        $this->newLine();
        $this->line("Por tipo:");
        foreach ($byType as $t => $c) {
            $this->line("  {$t}: {$c}");
        }

        $this->newLine();
        $this->line("Por scope:");
        foreach ($byScope as $s => $c) {
            $this->line("  {$s}: {$c}");
        }

        $this->newLine();
        $this->line("Primeras 10 reglas:");
        $this->line(str_pad('Key', 45) . ' ' . str_pad('Sheet', 8) . ' ' . str_pad('Col', 6) . ' ' . str_pad('Type', 25) . ' ' . str_pad('Scope', 12) . ' ' . str_pad('Range', 12));
        $this->line(str_repeat('-', 110));
        $i = 0;
        foreach ($rules as $r) {
            if ($i >= 10) break; $i++;
            $range = $r->rowFrom !== null ? "{$r->rowFrom}-{$r->rowTo}" : '-';
            $this->line(
                str_pad($r->ruleKey, 45)
                . ' ' . str_pad($r->sheet, 8)
                . ' ' . str_pad($r->targetColumn, 6)
                . ' ' . str_pad($r->ruleType, 25)
                . ' ' . str_pad($r->scope, 12)
                . ' ' . str_pad($range, 12)
            );
        }

        return self::SUCCESS;
    }
}
