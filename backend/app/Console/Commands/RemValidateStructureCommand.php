<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemFormulaRuleBuilder;
use App\Domain\RemParser\Services\RemFormulaValidationExecutor;
use App\Domain\REM\Models\RemUpload;
use Illuminate\Console\Command;

class RemValidateStructureCommand extends Command
{
    protected $signature = 'rem:validate-structure
                            {upload_id : ID de la carga en rem_uploads}
                            {structure_id : ID de la estructura en rem_template_structures}';

    protected $description = 'Ejecuta validaciones desde reglas detectadas contra datos reales de una carga';

    public function handle(
        RemFormulaRuleBuilder $builder,
        RemFormulaValidationExecutor $executor,
    ): int {
        $uploadId = (int) $this->argument('upload_id');
        $structureId = (int) $this->argument('structure_id');

        $upload = RemUpload::find($uploadId);
        if (!$upload) {
            $this->error("Upload ID {$uploadId} no encontrado en rem_uploads.");
            return self::FAILURE;
        }

        $structure = RemTemplateStructure::find($structureId);
        if (!$structure) {
            $this->error("Structure ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $this->info("=== Validando upload #{$uploadId} contra estructura #{$structureId} ===");
        $this->line("  Upload: {$upload->original_filename}");
        $this->line("  Estructura: {$structure->anio}/{$structure->serie} v{$structure->version_number}");
        $this->newLine();

        $this->line("Generando reglas desde estructura...");
        $rules = $builder->build($structure);

        $sumEquals = array_filter($rules, fn($r) => $r->ruleType === 'sum_equals');
        $required = array_filter($rules, fn($r) => $r->ruleType === 'required_and_le_parent');

        $this->line("Reglas generadas: " . count($rules));
        $this->line("  sum_equals:             " . count($sumEquals));
        $this->line("  required_and_le_parent: " . count($required));
        $this->newLine();

        $this->line("Ejecutando validaciones...");
        $results = $executor->execute($uploadId, $rules);

        $totalPassed = 0;
        $totalFailed = 0;
        $totalNoData = 0;

        foreach ($results as $r) {
            if ($r['total_rows'] === 0) {
                $totalNoData++;
                continue;
            }
            if ($r['failed_rows'] === 0) {
                $totalPassed++;
            } else {
                $totalFailed++;
            }
        }

        $this->line("Resultados: {$totalPassed} passed, {$totalFailed} failed, {$totalNoData} sin datos");
        $this->newLine();

        if ($totalFailed > 0) {
            $this->warn("--- Detalle de fallos ---");
            foreach ($results as $r) {
                if ($r['failed_rows'] > 0) {
                    $this->line("  {$r['rule_key']}: {$r['failed_rows']}/{$r['total_rows']} filas fallaron");
                    foreach (array_slice($r['details'], 0, 5) as $d) {
                        $this->line("    row {$d['row_number']}: {$d['reason']}");
                    }
                    if (count($r['details']) > 5) {
                        $this->line("    ... y " . (count($r['details']) - 5) . " mas");
                    }
                }
            }
        }

        if ($totalPassed === count($rules) - $totalNoData) {
            $this->info("✅ Todas las validaciones pasaron");
        }

        return self::SUCCESS;
    }
}
