<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Exceptions\RemTemplateConfigGenerationException;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RemParser\Services\RemTemplateConfigGeneratorService;
use Illuminate\Console\Command;

/**
 * BM-3.5 (2026-09-14): reemplazo oficial, tracked y generico del comando
 * local no versionado temp:create-template-config. Delgado a proposito --
 * toda la logica real vive en RemTemplateConfigGeneratorService. No
 * aprueba, no activa, no crea reglas/bindings, no escanea celdas.
 */
class RemGenerateTemplateConfigCommand extends Command
{
    protected $signature = 'rem:generate-template-config
                            {structure_id : ID de la estructura en rem_template_structures}';

    protected $description = 'Genera/actualiza rem_templates.config desde una RemTemplateStructure y enlaza rem_template_id';

    public function handle(RemTemplateConfigGeneratorService $generator): int
    {
        $structureId = (int) $this->argument('structure_id');

        $structure = RemTemplateStructure::find($structureId);
        if (!$structure) {
            $this->error("Estructura ID {$structureId} no encontrada.");
            return self::FAILURE;
        }

        $this->line("Estructura ID {$structureId}: {$structure->anio}/{$structure->serie} v{$structure->version_number} (status: {$structure->status})");

        try {
            $template = $generator->generateAndPersist($structure);
        } catch (RemTemplateConfigGenerationException $e) {
            $this->error("No se pudo generar el config: {$e->getMessage()}");
            return self::FAILURE;
        }

        $sheets = $template->config['sheets'] ?? [];
        $this->info("✅ RemTemplate ID {$template->id} ({$template->rem_type} {$template->year}) actualizado con " . count($sheets) . ' hoja(s):');
        foreach ($sheets as $sheet) {
            $s = $sheet['structure'];
            $this->line("  {$sheet['sheet_name']}: header={$s['header_row']} data={$s['data_start_row']}-{$s['data_end_row']} concept={$s['concept_column']} total={$s['total_column']} columnas=" . count($sheet['columns']));
        }

        return self::SUCCESS;
    }
}
