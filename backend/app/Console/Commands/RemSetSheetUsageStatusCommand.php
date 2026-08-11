<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\RemSheetUsageStatusService;
use Illuminate\Console\Command;

/**
 * Registra explicitamente que una hoja REM (anio+serie+sheet_name) es
 * 'aplicable' o 'no_utilizada' segun la determinacion de Estadistica APS.
 * Nunca determina esto automaticamente -- exige motivo y responsable en
 * cada llamada, y --dry-run para previsualizar el efecto antes de
 * persistir (mismo patron ya usado en rem:patch-sheet-structure).
 */
class RemSetSheetUsageStatusCommand extends Command
{
    protected $signature = 'rem:set-sheet-usage-status
                            {sheet : Nombre de la hoja (ej. A21)}
                            {status : aplicable | no_utilizada}
                            {--serie= : Serie (ej. A) -- obligatorio}
                            {--year= : Anio (ej. 2026) -- obligatorio}
                            {--reason= : Motivo de la decision -- obligatorio}
                            {--by= : Responsable de la decision -- obligatorio}
                            {--dry-run : Muestra el efecto esperado sin persistir}';

    protected $description = 'Registra el estado de uso (aplicable/no_utilizada) de una hoja REM, determinado por Estadistica APS';

    public function handle(RemSheetUsageStatusService $service): int
    {
        $sheet = (string) $this->argument('sheet');
        $status = (string) $this->argument('status');
        $serie = $this->option('serie');
        $year = $this->option('year');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($status, RemSheetUsageStatusService::ALLOWED_STATUSES, true)) {
            $this->error("Estado '{$status}' invalido. Permitidos: " . implode(', ', RemSheetUsageStatusService::ALLOWED_STATUSES));
            return self::FAILURE;
        }
        if (!$serie || !$year || !$reason || !$by) {
            $this->error('--serie, --year, --reason y --by son obligatorios. Nunca se determina automaticamente que una hoja no se utiliza.');
            return self::FAILURE;
        }

        $anio = (int) $year;

        $structure = RemTemplateStructure::where('anio', $anio)
            ->where('serie', $serie)
            ->where('status', 'active')
            ->first();

        $currentStatus = $service->getStatusFor($anio, $serie, $sheet);
        $sectionsAffected = $this->countSectionsForSheet($structure, $sheet);

        $this->line("Hoja: {$sheet} ({$anio}/{$serie})");
        $this->line("Estado actual: {$currentStatus}");
        $this->line("Estado propuesto: {$status}");
        $this->line('Estructura activa en el momento: ' . ($structure ? "ID {$structure->id} (v{$structure->version_number})" : 'ninguna encontrada'));
        $this->line("Secciones de esa hoja en la estructura activa: {$sectionsAffected}");
        $this->line("Motivo: {$reason}");
        $this->line("Responsable: {$by}");

        if ($currentStatus === $status) {
            $this->warn("La hoja ya esta en estado '{$status}' -- no hay transicion que registrar.");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('');
            $this->info("DRY-RUN: no se persistio ningun cambio. Transicion que se registraria: {$currentStatus} → {$status}.");
            return self::SUCCESS;
        }

        try {
            $row = $service->setStatus($anio, $serie, $sheet, $status, $reason, $by, $structure?->id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("✅ {$sheet} ({$anio}/{$serie}): {$currentStatus} → {$row->status} registrado (id={$row->id}).");
        return self::SUCCESS;
    }

    private function countSectionsForSheet(?RemTemplateStructure $structure, string $sheet): int
    {
        if (!$structure) {
            return 0;
        }

        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        $form = collect($est['forms'] ?? [])->firstWhere('sheetName', $sheet);

        return $form ? count($form['sections'] ?? []) : 0;
    }
}
