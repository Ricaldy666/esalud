<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Services\ObservabilityService;
use Illuminate\Console\Command;

class RuleDiffSummaryCommand extends Command
{
    protected $signature = 'rule:diff-summary';
    protected $description = 'Resumen de ejecuciones del engine vs legacy';

    public function handle(ObservabilityService $obs): int
    {
        $d = $obs->diffSummary();

        $this->info('=== Rule Engine Diff Summary ===');
        $this->newLine();

        $this->line("Uploads con engine: {$d['total_uploads_with_engine']}");
        $this->line("Reglas ejecutadas:  {$d['total_rules_executed']}");
        $this->line("Passed:             {$d['total_passed']}");
        $this->line("Failed:             {$d['total_failed']}");
        $this->line("Skipped:            {$d['total_skipped']}");
        $this->line("Pass rate:          {$d['pass_rate']}%");
        $this->newLine();

        if (!empty($d['uploads'])) {
            $this->line('Detalle por upload:');
            $rows = [];
            foreach ($d['uploads'] as $u) {
                $e = $u['engine'];
                $rows[] = [
                    $u['upload_id'],
                    $u['filename'],
                    $u['type'],
                    $u['period'],
                    $u['upload_status'],
                    $e['total_rules'],
                    $e['passed'],
                    $e['failed'],
                    $e['skipped'],
                    "{$e['avg_execution_ms']}ms",
                ];
            }
            $this->table(
                ['ID', 'Archivo', 'Tipo', 'Periodo', 'Estado', 'Reglas', 'Passed', 'Failed', 'Skipped', 'Tiempo'],
                $rows
            );
        }

        return self::SUCCESS;
    }
}
