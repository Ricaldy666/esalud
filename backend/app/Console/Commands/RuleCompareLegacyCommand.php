<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Testing\ComparisonReport;
use Illuminate\Console\Command;

class RuleCompareLegacyCommand extends Command
{
    protected $signature = 'rule:compare-legacy
                            {structure_id : ID de la estructura}
                            {upload_id : ID de la carga}
                            {--output= : Ruta opcional para guardar reporte JSON}';

    protected $description = 'Compara legacy vs RuleEngine en memoria, mismo structure y upload';

    public function handle(ComparisonReport $report): int
    {
        $structureId = (int) $this->argument('structure_id');
        $uploadId = (int) $this->argument('upload_id');
        $output = $this->option('output');

        $structure = RemTemplateStructure::withTrashed()->find($structureId);
        if (!$structure) {
            $this->error("Estructura ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $upload = RemUpload::find($uploadId);
        if (!$upload) {
            $this->error("Upload ID {$uploadId} no encontrado.");
            return self::FAILURE;
        }

        $this->info("=== rule:compare-legacy === ");
        $this->line("Estructura: #{$structureId} ({$structure->serie} {$structure->anio} v{$structure->version_number})");
        $this->line("Upload:     #{$uploadId} ({$upload->original_filename})");
        $this->newLine();

        $result = $report->generateReport($structureId, $uploadId, $output);

        if (isset($result['error'])) {
            $this->error("Error: {$result['error']}");
            if (!empty($result['engine_only'])) {
                $s = $result['engine_summary'];
                $this->line("Solo Engine disponible: passed={$s['passed']} failed={$s['failed']} skipped={$s['skipped']}");
            }
            return self::FAILURE;
        }

        $s = $result['summary'];
        $this->line("Total reglas en mapa: {$s['total_rules_in_map']}");
        $this->line("Coinciden exactas:    {$s['match_count']} ({$s['match_percentage']}%)");
        $this->line("Diferencias:          {$s['difference_count']}");
        $this->newLine();

        $this->table(
            ['Metrica', 'Legacy', 'Engine'],
            [
                ['Ejecutadas', $s['legacy']['passed'] + $s['legacy']['failed'], $s['engine']['passed'] + $s['engine']['failed']],
                ['Passed', $s['legacy']['passed'], $s['engine']['passed']],
                ['Failed', $s['legacy']['failed'], $s['engine']['failed']],
                ['Skipped', $s['legacy']['skipped'], $s['engine']['skipped']],
            ]
        );

        if ($s['difference_count'] > 0) {
            $this->newLine();
            $this->warn("=== Diferencias encontradas ({$s['difference_count']}) ===");
            $this->line(str_pad('CompKey', 65) . ' ' . str_pad('Status', 12) . ' ' . str_pad('Legacy', 30) . ' ' . str_pad('Engine', 30));
            $this->line(str_repeat('-', 140));
            $i = 0;
            foreach ($result['differences'] as $d) {
                if ($i >= 20) break; $i++;
                $ck = substr($d['comp_key'], 0, 60);
                $leg = "{$d['legacy']['status']} rows={$d['legacy']['total_rows']} failed={$d['legacy']['failed_rows']}";
                $eng = "{$d['engine']['status']} rows={$d['engine']['total_rows']} failed={$d['engine']['failed_rows']}";
                $this->line(str_pad($ck, 65) . ' ' . str_pad($leg, 30) . ' ' . str_pad($eng, 30));
            }
        } else {
            $this->info("✅ Sin diferencias: 100% match entre Legacy y RuleEngine.");
        }

        $this->newLine();
        $this->line("Tiempo de ejecucion: {$result['execution_time_ms']} ms");

        if ($output) {
            $this->line("Reporte guardado en: {$output}");
        }

        return self::SUCCESS;
    }
}
