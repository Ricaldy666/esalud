<?php

namespace App\Console\Commands;

use App\Domain\REM\Services\RemDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RemDiscoverCommand extends Command
{
    protected $signature = 'rem:discover
                            {path : Ruta absoluta o relativa al archivo Excel}
                            {--output=both : Formato de salida (json|md|both)}';

    protected $description = 'Analiza la estructura de un archivo Excel REM y genera reportes';

    public function handle(RemDiscoveryService $service): int
    {
        $path = $this->argument('path');

        if (!file_exists($path)) {
            $altPath = base_path($path);
            if (file_exists($altPath)) {
                $path = $altPath;
            } else {
                $this->error("Archivo no encontrado: {$path}");
                $this->line("  Buscado en:");
                $this->line("  - {$path}");
                $this->line("  - {$altPath}");
                return self::FAILURE;
            }
        }

        $filename = basename($path);
        $sizeKb = number_format(filesize($path) / 1024, 2);

        $this->info("Analizando: {$filename}");
        $this->info("Tamano: {$sizeKb} KB");
        $this->newLine();

        $progress = $this->output->createProgressBar();
        $progress->start();

        try {
            $result = $service->discover($path);
            $progress->finish();
            $this->newLine(2);
        } catch (\Throwable $e) {
            $progress->finish();
            $this->newLine();
            $this->error("Error al analizar: " . $e->getMessage());
            return self::FAILURE;
        }

        if (isset($result['error'])) {
            $this->warn("El analisis completo con errores:");
            $this->warn("  " . $result['error']);
            $this->newLine();
        }

        if (!empty($result['sheets_failed'])) {
            $this->warn("Hojas con errores: " . count($result['sheets_failed']));
            foreach ($result['sheets_failed'] as $f) {
                $this->line("  [{$f['index']}] {$f['sheet_name']}: {$f['error']}");
            }
            $this->newLine();
        }

        $wb = $result['workbook'];
        $this->info("Resumen del libro");
        $this->line("  Hojas totales:     {$wb['total_sheets']}");
        $this->line("  Hojas visibles:    {$wb['visible_sheets']}");
        $this->line("  Hojas ocultas:     {$wb['hidden_sheets']}");
        $this->line("  Tiene macros:      " . ($wb['has_macros'] ? 'Si' : 'No'));
        $this->line("  Creador:           " . ($wb['creator'] ?? '(desconocido)'));
        $this->line("  Ultima modificacion: " . ($wb['last_modified'] ?? '(desconocida)'));
        $this->newLine();

        $rows = collect($result['sheets'])->map(function ($s) {
            return [
                $s['index'],
                $s['sheet_name'],
                $s['is_hidden'] ? 'Oculta' : '',
                $s['dimension'],
                $s['cells_with_values'],
                $s['cells_with_formulas'],
                $s['detected_header_row'] ?? '-',
                $s['merged_cells_count'],
            ];
        })->toArray();

        $this->table(
            ['#', 'Hoja', 'Estado', 'Rango', 'Celdas', 'Formulas', 'Header', 'Combinadas'],
            $rows
        );

        $format = $this->option('output');
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $timestamp = now()->format('YmdHis');

        if ($format === 'json' || $format === 'both') {
            $jsonName = "{$timestamp}_{$baseName}_discovery.json";
            Storage::disk('rem-discovery')->put(
                $jsonName,
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $jsonPath = storage_path("app/rem-discovery/{$jsonName}");
            $this->info("JSON: {$jsonPath}");
        }

        if ($format === 'md' || $format === 'both') {
            $mdName = "{$timestamp}_{$baseName}_discovery.md";
            Storage::disk('rem-discovery')->put(
                $mdName,
                $this->renderMarkdown($result)
            );
            $mdPath = storage_path("app/rem-discovery/{$mdName}");
            $this->info("Markdown: {$mdPath}");
        }

        return self::SUCCESS;
    }

    private function renderMarkdown(array $result): string
    {
        $file = $result['file'];
        $wb = $result['workbook'];

        $md = "# Reporte de Discovery REM\n\n";
        $md .= "> Generado: {$file['analyzed_at']}\n\n";

        $md .= "## Resumen del archivo\n\n";
        $md .= "| Campo | Valor |\n";
        $md .= "|---|---|\n";
        $md .= "| Archivo | `{$file['filename']}` |\n";
        $md .= "| Tamano | {$file['size_kb']} KB ({$file['size_bytes']} bytes) |\n";
        $md .= "| Tipo MIME | {$file['mime_type']} |\n";
        $md .= "| Extension | .{$file['extension']} |\n";
        $md .= "| Ruta | `{$file['path']}` |\n\n";

        $md .= "## Resumen del libro\n\n";
        $md .= "| Metrica | Valor |\n";
        $md .= "|---|---|\n";
        $md .= "| Hojas totales | {$wb['total_sheets']} |\n";
        $md .= "| Hojas visibles | {$wb['visible_sheets']} |\n";
        $md .= "| Hojas ocultas | {$wb['hidden_sheets']} |\n";
        $md .= "| Tiene macros | " . ($wb['has_macros'] ? 'Si' : 'No') . " |\n";
        $md .= "| Creador | {$wb['creator']} |\n";
        $md .= "| Ultima modificacion | {$wb['last_modified']} |\n";
        $md .= "| Titulo | {$wb['title']} |\n";
        $md .= "| Descripcion | {$wb['description']} |\n\n";

        if (!empty($result['sheets_failed'])) {
            $md .= "## Hojas con errores\n\n";
            foreach ($result['sheets_failed'] as $f) {
                $md .= "- `[{$f['index']}] {$f['sheet_name']}`: {$f['error']}\n";
            }
            $md .= "\n";
        }

        $md .= "## Hojas detectadas\n\n";
        $md .= "| # | Nombre | Oculta | Rango | Filas | Columnas | Celdas | Formulas | Header en fila | Combinadas |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|---|\n";

        foreach ($result['sheets'] as $s) {
            $oculta = $s['is_hidden'] ? 'Si' : 'No';
            $headerRow = $s['detected_header_row'] ?? '-';
            $md .= "| {$s['index']} | {$s['sheet_name']} | {$oculta} | {$s['dimension']} "
                  . "| {$s['max_row']} | {$s['max_column']} | {$s['cells_with_values']} "
                  . "| {$s['cells_with_formulas']} | {$headerRow} | {$s['merged_cells_count']} |\n";
        }

        $md .= "\n";

        foreach ($result['sheets'] as $s) {
            $md .= "---\n\n";
            $md .= "### Hoja {$s['index']}: {$s['sheet_name']}\n\n";
            $md .= "- **Dimension**: {$s['dimension']} ({$s['max_row']} filas x {$s['max_column']} columnas)\n";
            $md .= "- **Visible**: " . ($s['is_hidden'] ? 'No (oculta)' : 'Si') . "\n";
            $md .= "- **Celdas combinadas**: {$s['merged_cells_count']}\n";

            if (!empty($s['merged_cells'])) {
                $md .= "- **Rangos combinados**: `" . implode('`, `', $s['merged_cells']) . "`\n";
            }

            $md .= "- **Celdas con valores**: {$s['cells_with_values']}\n";
            $md .= "- **Celdas con formulas**: {$s['cells_with_formulas']}\n";
            $md .= "- **Celdas numericas**: {$s['cells_with_numeric']}\n";
            $md .= "- **Celdas con fechas**: {$s['cells_with_dates']}\n";

            if ($s['detected_header_row']) {
                $md .= "- **Fila de encabezados detectada**: fila {$s['detected_header_row']}\n";
            } else {
                $md .= "- **Fila de encabezados**: No detectada (posiblemente portada o metadata)\n";
            }

            $md .= "\n#### Muestra de datos (primeras filas)\n\n";

            if (!empty($s['sample_first_rows'])) {
                $md .= "| Fila | ";

                $allCols = [];
                foreach ($s['sample_first_rows'] as $rowData) {
                    foreach ($rowData['cells'] as $cell) {
                        $colIdx = array_search($cell['col'], $allCols);
                        if ($colIdx === false) {
                            $allCols[] = $cell['col'];
                        }
                    }
                }

                $md .= implode(' | ', $allCols) . " |\n";
                $md .= "|---" . str_repeat("|---", count($allCols)) . "|\n";

                foreach ($s['sample_first_rows'] as $rowData) {
                    $md .= "| {$rowData['row']} | ";
                    foreach ($allCols as $col) {
                        $found = null;
                        foreach ($rowData['cells'] as $cell) {
                            if ($cell['col'] === $col) {
                                $found = $cell['value'];
                                break;
                            }
                        }
                        $display = $found ?? '';
                        if (is_string($display) && mb_strlen($display) > 40) {
                            $display = mb_substr($display, 0, 40) . '...';
                        }
                        if ($display === '' || $display === null) {
                            $display = '';
                        }
                        $md .= str_replace('|', '/', (string)$display) . ' | ';
                    }
                    $md = rtrim($md, ' ') . "\n";
                }
                $md .= "\n";
            } else {
                $md .= "_Sin datos en las primeras filas._\n\n";
            }

            $md .= $this->generateObservations($s);
        }

        return $md;
    }

    private function generateObservations(array $sheet): string
    {
        $obs = [];

        if ($sheet['is_hidden']) {
            $obs[] = "Hoja oculta: puede contener parametria, tablas auxiliares o configuracion.";
        }

        if ($sheet['cells_with_formulas'] > 0) {
            $pctFormula = round($sheet['cells_with_formulas'] / max($sheet['cells_with_values'], 1) * 100, 1);
            $obs[] = "Contiene {$sheet['cells_with_formulas']} celdas con formulas ({$pctFormula}% de las celdas con datos).";
        }

        if ($sheet['detected_header_row']) {
            $obs[] = "Estructura tabular detectada con encabezados en fila {$sheet['detected_header_row']}.";
        } else {
            $obs[] = "Sin estructura tabular clara (posible portada, metadata o informe).";
        }

        if ($sheet['merged_cells_count'] > 0) {
            $obs[] = "{$sheet['merged_cells_count']} celdas combinadas: probablemente titulos, encabezados compuestos o notas.";
        }

        if ($sheet['max_row'] === 1) {
            $obs[] = "Hoja con una sola fila: posiblemente parametria o configuracion.";
        }

        if ($sheet['cells_with_dates'] > 0) {
            $obs[] = "Se detectaron {$sheet['cells_with_dates']} celdas con formato de fecha.";
        }

        if (preg_match('/^(Portada|Instrucciones|Indice|Contenido|Tabla|Parametros)/i', $sheet['sheet_name'])) {
            $obs[] = "Nombre sugiere que es una hoja de metadatos o configuracion, no de datos clinicos.";
        }

        $str = '';
        if (!empty($obs)) {
            $str .= "#### Observaciones del analizador\n\n";
            foreach ($obs as $o) {
                $str .= "- {$o}\n";
            }
            $str .= "\n";
        }

        return $str;
    }
}
