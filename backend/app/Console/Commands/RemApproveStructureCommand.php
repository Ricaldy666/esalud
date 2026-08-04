<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\StructureApprovalService;
use Illuminate\Console\Command;

class RemApproveStructureCommand extends Command
{
    protected $signature = 'rem:approve-structure
                            {id : ID de la estructura en rem_template_structures}
                            {--user=1 : ID del usuario que aprueba}';

    protected $description = 'Aprueba una estructura draft → approved';

    public function handle(StructureApprovalService $service): int
    {
        $id = (int) $this->argument('id');
        $userId = (int) $this->option('user');

        $structure = RemTemplateStructure::find($id);

        if (!$structure) {
            $this->error("Estructura ID {$id} no encontrada.");
            return self::FAILURE;
        }

        $this->line("Estructura ID {$id}: {$structure->anio}/{$structure->serie} v{$structure->version_number}");
        $this->line("  Estado actual: {$structure->status}");

        try {
            $service->approve($structure, $userId);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("✅ Estructura ID {$id} aprobada (status: approved)");
        return self::SUCCESS;
    }
}
