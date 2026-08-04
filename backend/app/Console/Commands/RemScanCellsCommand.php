<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Services\CellScanOrchestrator;
use Illuminate\Console\Command;

class RemScanCellsCommand extends Command
{
    protected $signature = 'rem:scan-cells
        {sheet : Nombre de la hoja (ej. A01)}
        {section? : Codigo de seccion (ej. A). Si se omite, escanea todas las secciones de la hoja}
        {--all : Escanea todas las secciones existentes de la hoja}
        {--structure= : ID de estructura especifica (usa la activa por defecto)}';

    protected $description = 'Escanea celdas del XLSM a nivel de celda y guarda datos enriquecidos';

    public function handle(CellScanOrchestrator $orchestrator): int
    {
        $sheet = $this->argument('sheet');
        $section = $this->argument('section');
        $all = (bool) $this->option('all');
        $structureId = $this->option('structure');

        if ($section && $all) {
            $this->error('Use una seccion especifica o --all, no ambos.');
            return self::FAILURE;
        }

        $scanAll = $all || !$section;
        $this->info("Escaneando celdas de {$sheet}" . ($scanAll ? ' (todas las secciones)' : "/{$section}"));

        try {
            if (!$scanAll) {
                $cells = $orchestrator->scan(
                    sheet: $sheet,
                    section: $section,
                    structureId: $structureId ? (int) $structureId : null,
                );
                $this->info('Celdas escaneadas: ' . count($cells));
                $this->newLine();
                $this->table(
                    ['Coordenada', 'Tipo', 'Zona', 'Formula', 'Dependencias', 'Editable'],
                    collect($cells)->take(20)->map(fn($c) => [
                        $c->coordenada,
                        $c->tipoCelda,
                        $c->zona,
                        $c->esFormula ? substr($c->formula ?? '', 0, 40) : '-',
                        $c->esFormula ? implode(', ', array_slice($c->dependencias, 0, 5)) : '-',
                        $c->esEditable ? 'Si' : 'No',
                    ])->values()->toArray()
                );
            } else {
                $results = $orchestrator->scanAllSections($sheet);
                $total = count($results);
                $correct = 0;
                $errors = 0;

                $this->newLine();
                $this->line($sheet);
                foreach ($results as $secCode => $cellCount) {
                    if ($cellCount > 0) {
                        $correct++;
                        $this->line("{$secCode} OK ({$cellCount} celdas)");
                    } else {
                        $errors++;
                        $this->line("{$secCode} ERROR (0 celdas)");
                    }
                }

                $this->newLine();
                $this->line("Total secciones: {$total}");
                $this->line("Correctas: {$correct}");
                $this->line("Errores: {$errors}");
            }

            $this->info('Escaneo completado exitosamente.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
