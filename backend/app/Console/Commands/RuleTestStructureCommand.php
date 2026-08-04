<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemFormulaRuleBuilder;
use App\Domain\RuleEngine\Services\RuleKeyGeneratorService;
use Illuminate\Console\Command;

class RuleTestStructureCommand extends Command
{
    protected $signature = 'rule:test-structure {structure_id : ID de la estructura}';
    protected $description = 'Prueba la ingestion de reglas en memoria: verifica keys, tipos y consistencia';

    public function handle(RuleKeyGeneratorService $keyGenerator): int
    {
        $structureId = (int) $this->argument('structure_id');

        $structure = RemTemplateStructure::withTrashed()->find($structureId);
        if (!$structure) {
            $this->error("Estructura ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $est = $structure->estructura;
        $version = $structure->version_number;

        $builder = new RemFormulaRuleBuilder;
        $dtoRules = $builder->build($structure);

        $ingested = [];
        $controlOculto = 0;

        foreach ($est['forms'] ?? [] as $form) {
            $sheet = $form['sheetName'] ?? '?';

            foreach ($form['sections'] ?? [] as $section) {
                $sectionCodigo = $section['codigo'] ?? 'IMPLICITA';

                foreach ($section['fields'] ?? [] as $field) {
                    $regla = $field['reglaDetectada'] ?? null;
                    if ($regla === null) continue;

                    $tipo = is_array($regla) ? ($regla['tipo'] ?? '') : $regla;
                    if ($tipo === 'control_oculto') {
                        $controlOculto++;
                        continue;
                    }

                    $letra = $field['letra'] ?? '?';
                    $rangoFilas = is_array($regla) ? ($regla['rangoFilas'] ?? null) : null;
                    $columnasOrigen = is_array($regla) ? ($regla['columnasOrigen'] ?? []) : [];

                    $ruleKey = $keyGenerator->generate($sheet, $sectionCodigo, $letra, $tipo);

                    $ingested[] = [
                        'rule_key' => $ruleKey,
                        'sheet' => $sheet,
                        'section' => $sectionCodigo,
                        'letra' => $letra,
                        'tipo' => $tipo,
                        'rango_filas' => $rangoFilas,
                        'source_columns' => $columnasOrigen,
                    ];
                }
            }
        }

        $this->info("=== rule:test-structure {$structureId} ===");
        $this->line("Estructura: {$structure->serie} {$structure->anio} v{$version}");
        $this->line("Campos con regla:        " . (count($dtoRules) + $controlOculto));
        $this->line("Reglas DTO (builder):    " . count($dtoRules));
        $this->line("Reglas ingestion (key):  " . count($ingested));
        $this->line("control_oculto omitidos: {$controlOculto}");
        $this->line("Coinciden DTO vs ingestion: " . (count($dtoRules) === count($ingested) ? 'SI' : 'NO'));

        $keys = array_column($ingested, 'rule_key');
        $uniqueKeys = array_unique($keys);
        $this->line("Keys unicas:             " . count($uniqueKeys));
        $this->line("Colisiones:              " . (count($keys) - count($uniqueKeys)));

        $byType = [];
        foreach ($ingested as $r) {
            $byType[$r['tipo']] = ($byType[$r['tipo']] ?? 0) + 1;
        }
        $this->newLine();
        $this->line("Distribucion por tipo:");
        foreach ($byType as $t => $c) {
            $this->line("  {$t}: {$c}");
        }

        $bySection = [];
        foreach ($ingested as $r) {
            $key = "{$r['sheet']}/{$r['section']}";
            $bySection[$key] = ($bySection[$key] ?? 0) + 1;
        }
        $this->newLine();
        $this->line("Reglas por seccion:");
        foreach ($bySection as $sec => $c) {
            $this->line("  {$sec}: {$c}");
        }

        $sample = array_slice($ingested, 0, 10);
        $this->newLine();
        $this->line("Primeras 10 reglas ingestion:");
        foreach ($sample as $r) {
            $src = implode(',', array_slice($r['source_columns'], 0, 3));
            if (count($r['source_columns']) > 3) $src .= '...';
            $rango = $r['rango_filas'] ?? '-';
            $this->line("  {$r['rule_key']}  sheet={$r['sheet']} sec={$r['section']} col={$r['letra']} tipo={$r['tipo']} rango={$rango} src=[{$src}]");
        }

        return self::SUCCESS;
    }
}
