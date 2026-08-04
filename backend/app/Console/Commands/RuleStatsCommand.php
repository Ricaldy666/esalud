<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Services\ObservabilityService;
use Illuminate\Console\Command;

class RuleStatsCommand extends Command
{
    protected $signature = 'rule:stats';
    protected $description = 'Estadisticas detalladas del Rule Engine';

    public function handle(ObservabilityService $obs): int
    {
        $s = $obs->stats();

        $this->info('=== Rule Engine Stats ===');
        $this->newLine();

        $this->line('Distribucion por tipo de regla:');
        foreach ($s['rules_by_type'] as $type => $count) {
            $this->line("  {$type}: {$count}");
        }
        $this->newLine();

        $this->line('Ejecuciones por estado:');
        foreach ($s['executions_by_status'] as $status => $count) {
            $style = $status === 'failed' ? 'error' : ($status === 'passed' ? 'info' : 'comment');
            $this->{$style}("  {$status}: {$count}");
        }
        $this->newLine();

        $this->line('Ejecuciones por origen:');
        foreach ($s['executions_by_trigger'] as $trigger => $count) {
            $this->line("  {$trigger}: {$count}");
        }
        $this->newLine();

        $this->line("Tiempo promedio:  {$s['avg_execution_time_ms']} ms");
        $this->line("Filas procesadas: {$s['total_rows_processed']}");
        $this->line("Filas falladas:   {$s['total_rows_failed']}");
        $this->newLine();

        if (!empty($s['by_structure'])) {
            $this->line('Por estructura:');
            $rows = [];
            foreach ($s['by_structure'] as $structId => $data) {
                $structure = \App\Domain\RemParser\Models\RemTemplateStructure::find($structId);
                $label = $structure ? "{$structure->serie} {$structure->anio} v{$structure->version_number}" : "#{$structId}";
                $rows[] = [$label, $data['total_logs'], round($data['avg_ms'], 1) . 'ms', $data['total_rows'], $data['total_failed']];
            }
            $this->table(['Estructura', 'Logs', 'Prom ms', 'Filas', 'Fallos'], $rows);
            $this->newLine();
        }

        if (!empty($s['last_20_uploads'])) {
            $this->line('Ultimos 20 uploads:');
            $rows = [];
            foreach ($s['last_20_uploads'] as $u) {
                $upload = \App\Domain\REM\Models\RemUpload::find($u['rem_upload_id']);
                $label = $upload ? $upload->original_filename : "#{$u['rem_upload_id']}";
                $rows[] = [
                    $label,
                    $u['total_rules'],
                    "{$u['passed']}/{$u['failed']}/{$u['skipped']}",
                    round($u['avg_ms'], 1) . 'ms',
                    $u['total_rows'],
                ];
            }
            $this->table(['Upload', 'Reglas', 'P/F/S', 'Prom ms', 'Filas'], $rows);
        }

        return self::SUCCESS;
    }
}
