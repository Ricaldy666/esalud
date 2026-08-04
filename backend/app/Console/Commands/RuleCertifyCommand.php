<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Services\CertificationService;
use Illuminate\Console\Command;

class RuleCertifyCommand extends Command
{
    protected $signature = 'rule:certify
                            {--sheet=A01 : Filtrar por hoja (ej: A01)}
                            {--rule= : Rule_key específica para certificar}
                            {--type= : Filtrar por tipo de regla (sum_equals, required_and_le_parent)}
                            {--output= : Formato de salida (table, json, card). Omite para modo interactivo}
                            {--interactive : Forzar modo interactivo uno por uno}
                            {--export : Exportar todas las fichas a JSON}
                            {--stats : Mostrar estadísticas de certificación}';

    protected $description = 'Herramienta de certificación funcional del catálogo de reglas REM';

    public function handle(CertificationService $certification): int
    {
        if ($this->option('stats')) {
            return $this->showStats($certification);
        }

        if ($this->option('export')) {
            return $this->exportAll($certification);
        }

        $hasOutput = !empty($this->option('output'));
        $interactive = $this->option('interactive') || !$hasOutput;

        if ($interactive) {
            return $this->interactiveMode($certification);
        }

        return $this->outputMode($certification);
    }

    private function interactiveMode(CertificationService $certification): int
    {
        $filters = $this->buildFilters();
        $rules = $certification->getRules($filters);

        if ($rules->isEmpty()) {
            $this->error("No se encontraron reglas con los filtros especificados.");
            $this->line("Filtros: " . json_encode($filters));
            return self::FAILURE;
        }

        $total = $rules->count();
        $current = 0;

        $this->info("📋 CERTIFICACIÓN FUNCIONAL — Serie A 2026");
        $this->line("Reglas encontradas: {$total}");
        $this->line("Comandos: [n]ext | [p]rev | [c]ertificar | [r]equiere revisión | [o]bservación | [q]uit | [?] ayuda");
        $this->newLine();

        while ($current >= 0 && $current < $total) {
            $rule = $rules[$current];
            $card = $certification->buildCertificationCard($rule);

            $this->renderCard($card, $current + 1, $total);

            $action = $this->askAction($current, $total);

            switch ($action) {
                case 'n':
                case 'next':
                    $current++;
                    break;
                case 'p':
                case 'prev':
                    $current--;
                    break;
                case 'c':
                case 'certificar':
                    $certification->saveCertificationStatus(
                        $rule->rule_key, 'Certificada', $card['observaciones']
                    );
                    $this->info("✅ Regla {$rule->rule_key} marcada como CERTIFICADA");
                    $current++;
                    break;
                case 'r':
                case 'revisar':
                    $obs = $this->ask('Observaciones para la revisión', $card['observaciones']);
                    $certification->saveCertificationStatus(
                        $rule->rule_key, 'Requiere revisión', $obs
                    );
                    $this->warn("🔍 Regla {$rule->rule_key} marcada como REQUIERE REVISIÓN");
                    $current++;
                    break;
                case 'o':
                case 'obs':
                    $obs = $this->ask('Observaciones', $card['observaciones']);
                    $certification->saveCertificationStatus(
                        $rule->rule_key, $card['estado'], $obs
                    );
                    $this->info("📝 Observaciones guardadas para {$rule->rule_key}");
                    break;
                case 's':
                case 'stats':
                    $stats = $certification->getStats();
                    $this->showStatsTable($stats);
                    break;
                case 'q':
                case 'quit':
                    $this->info("Certificación finalizada.");
                    return self::SUCCESS;
                case '?':
                case 'help':
                    $this->showHelp();
                    break;
                default:
                    $this->error("Comando no reconocido. Usa '?' para ayuda.");
                    break;
            }
        }

        $this->info("¡Has revisado todas las reglas!");
        $stats = $certification->getStats();
        $this->showStatsTable($stats);

        return self::SUCCESS;
    }

    private function outputMode(CertificationService $certification): int
    {
        $filters = $this->buildFilters();
        $rules = $certification->getRules($filters);

        if ($rules->isEmpty()) {
            $this->error("No se encontraron reglas.");
            return self::FAILURE;
        }

        $output = $this->option('output') ?: 'table';

        foreach ($rules as $i => $rule) {
            $card = $certification->buildCertificationCard($rule);

            if ($output === 'json') {
                $this->line(json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } elseif ($output === 'card') {
                $this->renderCard($card, $i + 1, $rules->count());
            } else {
                $this->table(
                    ['Campo', 'Valor'],
                    [
                        ['rule_key', $card['rule_key']],
                        ['tipo', $card['rule_type']],
                        ['hoja', $card['hoja']],
                        ['sección', $card['seccion']],
                        ['severidad', $card['severity']],
                        ['estado', $card['estado']],
                        ['columnas_origen', implode(', ', (array)$card['columnas_origen'])],
                        ['columna_destino', $card['columna_destino'] ?? ''],
                        ['rango_filas', $card['rango_filas'] ?? ''],
                        ['fórmula', $card['formula_interpretada']],
                    ]
                );
            }
        }

        return self::SUCCESS;
    }

    private function exportAll(CertificationService $certification): int
    {
        $this->info("Exportando fichas de certificación...");
        $cards = $certification->exportAllCards();

        $path = storage_path('app/certificacion/serie-a-fichas-completas.json');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $count = count($cards);
        file_put_contents($path, json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Exportadas {$count} fichas a: {$path}");

        return self::SUCCESS;
    }

    private function showStats(CertificationService $certification): int
    {
        $stats = $certification->getStats();
        $this->info("📊 ESTADÍSTICAS DE CERTIFICACIÓN");
        $this->showStatsTable($stats);
        return self::SUCCESS;
    }

    private function renderCard(array $card, int $index, int $total): void
    {
        $this->newLine();
        $this->info(str_repeat('═', 60));
        $this->info("  FICHA DE CERTIFICACIÓN — {$index}/{$total}");
        $this->info(str_repeat('═', 60));

        $rows = [
            ['Rule Key', $card['rule_key']],
            ['Tipo', $card['rule_type']],
            ['Severidad', $card['severity']],
            ['Hoja', $card['hoja']],
            ['Sección', $card['seccion']],
            ['Descripción', wordwrap($card['description'] ?? '', 40, "\n             ")],
        ];

        if (!empty($card['columnas_origen'])) {
            $rows[] = ['Columnas Origen', implode(', ', (array)$card['columnas_origen'])];
        }
        if (!empty($card['columna_destino'])) {
            $rows[] = ['Columna Destino', $card['columna_destino']];
        }
        if (!empty($card['rango_filas'])) {
            $rows[] = ['Rango Filas', $card['rango_filas']];
        }

        $rows[] = ['Fórmula Interpretada', wordwrap($card['formula_interpretada'] ?? '', 40, "\n             ")];
        $rows[] = ['Estado', $this->formatEstado($card['estado'])];

        if (!empty($card['observaciones'])) {
            $rows[] = ['Observaciones', wordwrap($card['observaciones'], 40, "\n             ")];
        }

        $this->table(['Campo', 'Valor'], $rows);

        // Show evidence section
        $this->line("");
        $this->info("  EVIDENCIA EN XLSM");
        $this->line(str_repeat('─', 60));

        $evidence = $card['evidencia_xlsm'];
        if ($evidence && $evidence['encontrada']) {
            $this->line("  Sección: {$evidence['titulo_seccion']}");
            $this->line("  Columna: {$evidence['label_columna']} (Letra: " . ($card['columna_destino'] ?? '?') . ")");
            $this->line("  Es Total: " . ($evidence['es_total'] ? 'Sí' : 'No'));
            $this->line("  Es Control Oculto: " . ($evidence['es_control_oculto'] ? 'Sí' : 'No'));

            if ($evidence['regla_detectada']) {
                $rd = $evidence['regla_detectada'];
                $this->line("  Regla Detectada: {$rd['tipo']}");
                if (!empty($rd['columnas_origen'])) {
                    $this->line("  Origen fórmula: " . implode(', ', $rd['columnas_origen']));
                }
                if ($rd['rango_filas']) {
                    $this->line("  Rango filas detectado: {$rd['rango_filas']}");
                }
            }
        } else {
            $this->warn("  ⚠ Sin evidencia directa en estructura XLSM");
        }

        $this->line("");
        $this->info("  EVIDENCIA EN MANUAL REM");
        $this->line(str_repeat('─', 60));
        if (!empty($card['evidencia_manual_rem'])) {
            $this->line($card['evidencia_manual_rem']);
        } else {
            $this->warn("  ⏳ Pendiente (completar durante certificación)");
        }

        $this->line("");
        $this->info(str_repeat('─', 60));
        $this->info("  [n]ext | [p]rev | [c]ertificar | [r]equiere revisión | [o]bs | [s]tats | [q]uit | [?]");
        $this->line(str_repeat('─', 60));
    }

    private function askAction(int $current, int $total): string
    {
        $default = $current < $total - 1 ? 'n' : 'q';
        return $this->ask('Acción', $default);
    }

    private function formatEstado(string $estado): string
    {
        return match ($estado) {
            'Certificada' => "✅ {$estado}",
            'Requiere revisión' => "🔍 {$estado}",
            default => "⏳ {$estado}",
        };
    }

    private function showStatsTable(array $stats): void
    {
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Total reglas', $stats['total']],
                ['✅ Certificadas', $stats['certificadas']],
                ['🔍 Requiere revisión', $stats['requiere_revision']],
                ['⏳ Pendientes', $stats['pendientes']],
            ]
        );
    }

    private function showHelp(): void
    {
        $this->info("COMANDOS DISPONIBLES:");
        $this->table(
            ['Comando', 'Descripción'],
            [
                ['n / next', 'Siguiente regla'],
                ['p / prev', 'Regla anterior'],
                ['c / certificar', 'Marcar como Certificada'],
                ['r / revisar', 'Marcar como Requiere revisión'],
                ['o / obs', 'Agregar/editar observaciones'],
                ['s / stats', 'Mostrar estadísticas'],
                ['q / quit', 'Salir'],
                ['? / help', 'Mostrar esta ayuda'],
            ]
        );
    }

    private function buildFilters(): array
    {
        $filters = [];
        if ($this->option('sheet')) {
            $filters['sheet'] = strtoupper($this->option('sheet'));
        }
        if ($this->option('rule')) {
            $filters['rule_key'] = $this->option('rule');
        }
        if ($this->option('type')) {
            $filters['rule_type'] = $this->option('type');
        }
        return $filters;
    }
}
