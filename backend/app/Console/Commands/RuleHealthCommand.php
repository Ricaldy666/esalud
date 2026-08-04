<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Services\ObservabilityService;
use Illuminate\Console\Command;

class RuleHealthCommand extends Command
{
    protected $signature = 'rule:health';
    protected $description = 'Diagnostico general del Rule Engine';

    public function handle(ObservabilityService $obs): int
    {
        $h = $obs->health();

        $this->info('=== Rule Engine Health ===');
        $this->newLine();

        $this->line('Config:');
        $this->line("  enabled:     " . ($h['config_enabled'] ? '<info>true</info>' : '<comment>false</comment>'));
        $this->line("  mode:        {$h['config_mode']}");
        $this->newLine();

        $this->line('Reglas:');
        $this->line("  Activas:     {$h['total_rules_active']}");
        $this->line("  Bindings:    {$h['total_bindings_active']}");
        $this->newLine();

        $this->line('Estructuras:');
        $this->line("  Totales:     {$h['total_structures']}");
        $this->line("  Con reglas:  {$h['structures_with_rules']}");
        $warn = $h['structures_without_bindings'] > 0 ? '<comment>' . $h['structures_without_bindings'] . '</comment>' : '0';
        $this->line("  Sin reglas:  {$warn}");
        $this->newLine();

        $this->line('Uploads:');
        $this->line("  Totales:     {$h['total_uploads']}");
        $this->line("  Con engine:  {$h['uploads_with_engine']}");
        $this->line("  Sin engine:  {$h['uploads_without_engine']}");
        $this->newLine();

        $this->line('Ejecuciones:');
        $this->line("  Logs totales: {$h['total_execution_logs']}");
        $errStyle = $h['error_logs'] > 0 ? 'error' : 'info';
        $this->{$errStyle}("  Errores:      {$h['error_logs']}");

        if ($h['last_error']) {
            $this->line("  Ultimo error: #{$h['last_error']['id']} upload={$h['last_error']['upload_id']} msg={$h['last_error']['message']}");
        }

        return self::SUCCESS;
    }
}
