<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Services\RuleIngestionService;
use Illuminate\Console\Command;

class RuleIngestFromStructureCommand extends Command
{
    protected $signature = 'rule:ingest-from-structure
                            {structure_id : ID de la estructura en rem_template_structures}';

    protected $description = 'Extrae reglas detectadas desde Excel y las ingiere en rem_rules';

    public function handle(RuleIngestionService $ingestionService): int
    {
        $structureId = (int) $this->argument('structure_id');

        $this->info("Ingiriendo reglas desde estructura ID {$structureId}...");

        try {
            $stats = $ingestionService->ingest($structureId);
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->line("  Estructura:  {$stats['anio']}/{$stats['serie']} v{$stats['version']} (ID {$stats['structure_id']})");
        $this->line("  Detectadas:  {$stats['total_detected']}");
        $this->line("  Omitidas:    {$stats['skipped_control_oculto']} (control_oculto)");
        $this->line("  Creadas:     {$stats['created']}");
        $this->line("  Reutilizadas: {$stats['reused']}");
        $this->line("  Bindings:    {$stats['bindings_created']}");
        $this->newLine();

        $this->line('  Distribucion por tipo:');
        foreach ($stats['distribution'] as $tipo => $count) {
            $this->line("    {$tipo}: {$count}");
        }

        $this->newLine();
        $this->info('Ingestion completada.');

        return self::SUCCESS;
    }
}
