<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Services\ObservabilityService;
use Illuminate\Console\Command;

class RuleLastExecutionsCommand extends Command
{
    protected $signature = 'rule:last-executions
                            {--limit=20 : Cantidad de registros}
                            {--upload= : Filtrar por upload ID}
                            {--status= : Filtrar por estado (passed|failed|skipped)}';

    protected $description = 'Muestra las ultimas ejecuciones de reglas';

    public function handle(ObservabilityService $obs): int
    {
        $limit = (int) $this->option('limit');
        $uploadId = $this->option('upload') ? (int) $this->option('upload') : null;
        $status = $this->option('status');

        $execs = $obs->recentExecutions($limit, $uploadId, $status);

        if (empty($execs)) {
            $this->warn('No se encontraron ejecuciones.');
            return self::SUCCESS;
        }

        $this->info("=== rule:last-executions ({$limit} registros) ===");

        $rows = [];
        foreach ($execs as $e) {
            $style = $e['status'] === 'failed' ? 'error' : ($e['status'] === 'passed' ? 'info' : 'comment');
            $label = mb_substr($e['rule_key'], 0, 30);
            $rows[] = [
                $e['id'],
                $label,
                $e['status'],
                $e['total_rows'],
                $e['failed_rows'],
                $e['execution_ms'] !== null ? "{$e['execution_ms']}ms" : '-',
                $e['triggered_by'],
                $e['upload_filename'] ?? "#{$e['upload_id']}",
            ];
        }

        $this->table(
            ['ID', 'Regla', 'Status', 'Filas', 'Fallos', 'Tiempo', 'Origen', 'Upload'],
            $rows
        );

        return self::SUCCESS;
    }
}
