<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Evaluators\RequiredAndLeParentEvaluator;
use App\Domain\RuleEngine\Services\RuleEngineService;
use Illuminate\Console\Command;

class RuleValidateCommand extends Command
{
    protected $signature = 'rule:validate
                            {upload_id : ID de la carga en rem_uploads}
                            {structure_id : ID de la estructura en rem_template_structures}
                            {--write : Escribe resultados en BD (por defecto es dry-run)}';

    protected $description = 'Ejecuta reglas del Rule Engine contra datos reales de una carga';

    public function handle(RuleEngineService $engine): int
    {
        $uploadId = (int) $this->argument('upload_id');
        $structureId = (int) $this->argument('structure_id');
        $write = (bool) $this->option('write');

        $upload = RemUpload::find($uploadId);
        if (!$upload) {
            $this->error("Upload ID {$uploadId} no encontrado.");
            return self::FAILURE;
        }

        $structure = RemTemplateStructure::withTrashed()->find($structureId);
        if (!$structure) {
            $this->error("Structure ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $mode = $write ? 'ESCRITURA' : 'DRY-RUN';
        $this->info("=== Rule Engine: {$mode} ===");
        $this->line("  Upload:     #{$uploadId} ({$upload->original_filename})");
        $this->line("  Estructura: #{$structureId} ({$structure->anio}/{$structure->serie} v{$structure->version_number})");
        $this->newLine();

        $engine->registerEvaluator(new SumEqualsEvaluator);
        $engine->registerEvaluator(new RequiredAndLeParentEvaluator);

        $this->line("Resolviendo reglas activas...");
        $rules = $engine->resolveRules($structureId);
        $this->line("Reglas resueltas: " . $rules->count());

        $sumEquals = $rules->filter(fn($r) => $r->rule_type === 'sum_equals')->count();
        $required = $rules->filter(fn($r) => $r->rule_type === 'required_and_le_parent')->count();
        $this->line("  sum_equals:             {$sumEquals}");
        $this->line("  required_and_le_parent: {$required}");
        $this->newLine();

        $this->line("Ejecutando validaciones...");
        $stats = $engine->execute($uploadId, $structureId, $write);
        $this->newLine();

        $this->line("Resultados:");
        $this->line("  Ejecutadas:  {$stats['executed']}");
        $this->line("  Skipped:     {$stats['skipped']}");
        $this->line("  Passed:      {$stats['passed']}");
        $this->line("  Failed:      {$stats['failed']}");
        $this->newLine();

        if ($write) {
            $this->info("✅ Resultados escritos en BD.");
        } else {
            $this->warn("Modo DRY-RUN: no se escribieron registros en BD.");
            $this->line("Usa --write para persistir resultados.");
        }

        if ($stats['failed'] > 0) {
            $this->newLine();
            $this->warn("--- Detalle de fallos (primeros 10) ---");
            $count = 0;
            foreach ($stats['details'] as $r) {
                if ($r['status'] === 'failed') {
                    $count++;
                    if ($count > 10) break;
                    $this->line("  {$r['rule_key']}: {$r['failed_rows']}/{$r['total_rows']}");
                }
            }
        }

        $this->newLine();
        $this->info("Completado.");

        return self::SUCCESS;
    }
}
