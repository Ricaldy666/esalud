<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemFormulaRuleBuilder;
use App\Domain\RuleEngine\Services\RuleKeyGeneratorService;
use App\Domain\RuleEngine\Testing\ComparisonReport;
use Illuminate\Console\Command;

class RuleTestAllCommand extends Command
{
    protected $signature = 'rule:test-all';
    protected $description = 'Bateria completa de pruebas: parser, estructura, comparacion legacy vs engine';

    public function handle(ComparisonReport $report, RuleKeyGeneratorService $keyGenerator): int
    {
        $startTime = microtime(true);
        $results = [];

        $this->info('=== rule:test-all ===');
        $this->newLine();

        // Test structures: 7 (A 2026 v1) and 3 (P 2026 v1)
        $structureIds = [7, 3];

        foreach ($structureIds as $sid) {
            $structure = RemTemplateStructure::withTrashed()->find($sid);
            if (!$structure) {
                $this->warn("Structure {$sid} no encontrada, saltando.");
                continue;
            }

            $this->info("--- Structure {$sid}: {$structure->serie} {$structure->anio} v{$structure->version_number} ---");

            // 1. Parser test
            try {
                $builder = new RemFormulaRuleBuilder;
                $dtoRules = $builder->build($structure);
                $parserCount = count($dtoRules);
                $this->line("  rem:test-parser: {$sid} -> reglas={$parserCount}");
                $results["parser_{$sid}"] = ['total_rules' => $parserCount];
            } catch (\TypeError $e) {
                $this->line("  rem:test-parser: {$sid} -> ERROR: builder no soporta esta estructura");
                $results["parser_{$sid}"] = ['error' => $e->getMessage()];
            }

            // 2. Structure test
            $est = $structure->estructura;
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
                        if ($tipo === 'control_oculto') { $controlOculto++; continue; }
                        $letra = $field['letra'] ?? '?';
                        $ruleKey = $keyGenerator->generate($sheet, $sectionCodigo, $letra, $tipo);
                        $ingested[] = $ruleKey;
                    }
                }
            }
            $unique = array_unique($ingested);
            $collisions = count($ingested) - count($unique);
            $this->line("  rule:test-structure: {$sid} -> reglas=" . count($ingested) . ", unicas=" . count($unique) . ", colisiones={$collisions}");
            $results["structure_{$sid}"] = ['total' => count($ingested), 'unique' => count($unique), 'collisions' => $collisions];

            // 3. Compare legacy (upload 1)
            $this->line("  rule:compare-legacy: {$sid} upload=1 ...");
            $compStart = microtime(true);
            $compResult = $report->generateReport($sid, 1);
            $compMs = (int) ((microtime(true) - $compStart) * 1000);
            if (isset($compResult['error'])) {
                $this->line("    error: {$compResult['error']}");
                $results["compare_{$sid}"] = ['error' => $compResult['error']];
            } else {
                $s = $compResult['summary'];
                $matchLabel = $s['match_percentage'] == 100 ? '100%' : "{$s['match_percentage']}%";
                $this->line("    coincidence: {$matchLabel} ({$s['match_count']}/{$s['total_rules_in_map']}) diffs={$s['difference_count']}");
                $this->line("    legacy: passed={$s['legacy']['passed']} failed={$s['legacy']['failed']} skipped={$s['legacy']['skipped']}");
                $this->line("    engine: passed={$s['engine']['passed']} failed={$s['engine']['failed']} skipped={$s['engine']['skipped']}");
                $results["compare_{$sid}"] = $s;
            }
            $this->line("    time: {$compMs} ms");
        }

        $totalTime = (int) ((microtime(true) - $startTime) * 1000);

        // Save full report
        $reportPath = storage_path("app/test-reports/rule-test-all_" . now()->format('Y-m-d_His') . ".json");
        $fullReport = [
            'command' => 'rule:test-all',
            'timestamp' => now()->toIso8601String(),
            'results' => $results,
            'total_time_ms' => $totalTime,
        ];
        $dir = dirname($reportPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($reportPath, json_encode($fullReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info("Reporte completo guardado en: {$reportPath}");
        $this->line("Tiempo total: {$totalTime} ms");

        return self::SUCCESS;
    }
}
