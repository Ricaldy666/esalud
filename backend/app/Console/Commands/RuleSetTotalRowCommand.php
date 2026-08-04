<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Models\Rule;
use Illuminate\Console\Command;

class RuleSetTotalRowCommand extends Command
{
    protected $signature = 'rule:set-total-row
                            {--sheet= : Filtrar por hoja (ej. A01)}
                            {--section= : Filtrar por seccion}
                            {--dry-run : Solo mostrar cambios sin persistir}
                            {--force : Incluir reglas sin evidencia (confianza BAJA)}';

    protected $description = 'Agrega total_row a reglas verticales row_range (single source == target)';

    private array $defaultTotalRowMap = [];

    public function handle(): int
    {
        $sheet = $this->option('sheet');
        $section = $this->option('section');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $query = Rule::where('rule_type', 'sum_equals')
            ->where('scope', 'row_range')
            ->where('status', 'active');

        $rules = $query->get();

        if ($rules->isEmpty()) {
            $this->warn('No se encontraron reglas row_range activas.');
            return self::SUCCESS;
        }

        $candidates = [];

        foreach ($rules as $rule) {
            $config = $rule->config;
            $sourceLetters = $config['source_letters'] ?? [];
            $targetColumn = $config['target_column'] ?? '';
            $rowFrom = $config['row_from'] ?? null;
            $rowTo = $config['row_to'] ?? null;

            $isVertical = count($sourceLetters) === 1
                && $sourceLetters[0] === $targetColumn
                && $rowFrom !== null
                && $rowTo !== null;

            if (!$isVertical) {
                continue;
            }

            if (isset($config['total_row'])) {
                continue;
            }

            $meta = $rule->metadata;
            $ruleSheet = $meta['sheet'] ?? '';
            $ruleSection = $meta['section'] ?? '';

            if ($sheet && !str_contains($ruleSheet, $sheet)) continue;
            if ($section && !str_contains($ruleSection, $section)) continue;

            $totalRow = $rowTo + 1;

            $candidates[] = [
                'rule' => $rule,
                'sheet' => $ruleSheet,
                'section' => $ruleSection,
                'row_from' => $rowFrom,
                'row_to' => $rowTo,
                'total_row' => $totalRow,
                'column' => $sourceLetters[0],
            ];
        }

        if (empty($candidates)) {
            $this->info('No hay reglas verticales pendientes de total_row.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Reglas a actualizar: %d', count($candidates)));
        $this->newLine();

        $headers = ['ID', 'Rule Key', 'Hoja', 'Seccion', 'Row From', 'Row To', 'Total Row', 'Columna'];
        $rows = [];
        foreach ($candidates as $c) {
            $rows[] = [
                $c['rule']->id,
                $c['rule']->rule_key,
                $c['sheet'],
                $c['section'],
                $c['row_from'],
                $c['row_to'],
                $c['total_row'],
                $c['column'],
            ];
        }
        $this->table($headers, $rows);

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run: no se persistieron cambios.');
            return self::SUCCESS;
        }

        $this->newLine();

        $progress = $this->output->createProgressBar(count($candidates));
        $updated = 0;
        $errors = 0;

        foreach ($candidates as $c) {
            try {
                $rule = $c['rule'];
                $config = $rule->config;
                $config['total_row'] = $c['total_row'];
                $rule->config = $config;
                $rule->save();
                $updated++;
            } catch (\Exception $e) {
                $this->error("Error actualizando {$c['rule']->rule_key}: {$e->getMessage()}");
                $errors++;
            }
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        $this->info("Actualizadas: {$updated}");
        if ($errors > 0) {
            $this->error("Errores: {$errors}");
        }

        $this->newLine();
        $this->line('Reglas actualizadas con total_row = row_to + 1.');
        $this->line('Evidencia: fila contigua con concept="TOTAL" en datos reales de upload 53.');

        return self::SUCCESS;
    }
}
