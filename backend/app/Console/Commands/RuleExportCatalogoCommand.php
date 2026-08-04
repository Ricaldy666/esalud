<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RuleExportCatalogoCommand extends Command
{
    protected $signature = 'rule:export-catalogo
        {--serie= : Serie REM (A, BM, D, P, BS)}
        {--output= : Ruta de salida del archivo CSV}';

    protected $description = 'Genera catálogo funcional de reglas de consistencia para revisión de Estadística APS';

    public function handle(): int
    {
        $serie = strtoupper($this->option('serie') ?? 'A');
        $anio = 2026;

        $this->info("Generando catálogo de reglas Serie {$serie} {$anio}...");

        $rules = DB::select("
            SELECT r.id, r.rule_key, r.rule_type, r.name, r.source, r.severity, r.status,
                   r.metadata, r.config, r.description, rb.anio, rb.serie
            FROM rem_rule_bindings rb
            JOIN rem_rules r ON r.id = rb.rule_id
            WHERE rb.serie = ? AND rb.anio = ? AND rb.active = 1
            ORDER BY JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.sheet')),
                     JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.section')),
                     r.rule_key
        ", [$serie, $anio]);

        $outputPath = $this->option('output') ?? storage_path('app/catalogo-reglas-serie-' . strtolower($serie) . '-' . $anio . '.csv');

        $rows = [];

        $headers = [
            'ID', 'Código técnico', 'Serie', 'Año', 'Formulario', 'Sección',
            'Columna', 'Variable', 'Tipo regla', 'Descripción', 'Lógica',
            'Fila(s)', 'Severidad', 'Estado', 'Fuente',
        ];

        foreach ($rules as $r) {
            $meta = json_decode($r->metadata, true) ?? [];
            $config = json_decode($r->config, true) ?? [];
            $sheet = $meta['sheet'] ?? '';
            $section = $meta['section'] ?? '';
            $letra = $meta['letra'] ?? '';
            $label = $meta['label'] ?? '';
            $type = $r->rule_type;

            $typeLabel = $type === 'sum_equals' ? 'Suma igual al Total' : 'Requerido y menor o igual al Total';
            $severityLabel = $r->severity === 'error' ? 'Error' : 'Advertencia';
            $statusLabel = match ($r->status) { 'active' => 'Activa', 'inactive' => 'Inactiva', default => 'Deprecada' };

            $letters = $config['source_letters'] ?? [];
            $target = $config['target_column'] ?? $letra;

            $logic = $type === 'sum_equals'
                ? 'Suma(' . implode('+', $letters) . ') = ' . $target
                : $target . ' requerida y <= ' . ($letters[0] ?? 'Total');

            $rowFrom = $config['row_from'] ?? null;
            $rowTo = $config['row_to'] ?? null;
            $rowRange = $rowFrom && $rowTo ? ($rowFrom === $rowTo ? "Fila {$rowFrom}" : "Filas {$rowFrom}–{$rowTo}") : '—';

            $variable = !empty($label) && !str_starts_with($label, '=') ? $label : "Columna {$letra}";

            $rows[] = [
                $r->id,
                $r->rule_key,
                $r->serie ?? $serie,
                $r->anio ?? $anio,
                $sheet,
                $section,
                $letra,
                $variable,
                $typeLabel,
                $r->description ?? '',
                $logic,
                $rowRange,
                $severityLabel,
                $statusLabel,
                $r->source === 'excel_formula' ? 'Estructura Excel' : 'Manual REM',
            ];
        }

        $fp = fopen($outputPath, 'w');
        fputs($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($fp, $row, ';');
        }

        fclose($fp);

        $this->info('Archivo generado: ' . $outputPath);
        $this->line('  Total reglas exportadas: ' . count($rules) . ' en Serie ' . $serie);

        return Command::SUCCESS;
    }
}
