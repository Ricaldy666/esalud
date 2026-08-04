<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\StructureApprovalService;
use Illuminate\Console\Command;

class RemActivateStructureCommand extends Command
{
    protected $signature = 'rem:activate-structure
                            {id : ID de la estructura aprobada a activar}';

    protected $description = 'Activa una estructura approved → active (supersede la active anterior)';

    public function handle(StructureApprovalService $service): int
    {
        $id = (int) $this->argument('id');

        $structure = RemTemplateStructure::find($id);

        if (!$structure) {
            $this->error("Estructura ID {$id} no encontrada.");
            return self::FAILURE;
        }

        $this->line("Estructura ID {$id}: {$structure->anio}/{$structure->serie} v{$structure->version_number}");
        $this->line("  Estado actual: {$structure->status}");

        try {
            $service->activate($structure);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("✅ Estructura ID {$id} activada. Versiones previas marcadas como superseded.");
        return self::SUCCESS;
    }
}
