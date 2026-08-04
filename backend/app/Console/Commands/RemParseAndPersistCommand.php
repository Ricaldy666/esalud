<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Services\RemParserService;
use App\Domain\RemParser\Services\RemTemplateStructurePersistenceService;
use Illuminate\Console\Command;

class RemParseAndPersistCommand extends Command
{
    protected $signature = 'rem:parse-persist
                            {path : Ruta al archivo Excel .xlsx/.xlsm}';

    protected $description = 'Parsea un Excel REM con el nuevo parser y persiste la estructura en BD';

    public function handle(
        RemParserService $parser,
        RemTemplateStructurePersistenceService $persistence,
    ): int {
        $path = $this->argument('path');

        if (!file_exists($path)) {
            $altPath = base_path($path);
            if (file_exists($altPath)) {
                $path = $altPath;
            } else {
                $this->error("Archivo no encontrado: {$path}");
                return self::FAILURE;
            }
        }

        $filename = basename($path);
        $this->info("Parseando: {$filename}");

        try {
            $dto = $parser->parse($path);
        } catch (\Throwable $e) {
            $this->error("Error al parsear: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->line("  Anio: {$dto->anio}");
        $this->line("  Serie: {$dto->serie}");
        $this->line("  Hash estructura: {$dto->hashEstructura}");
        $this->line("  Forms (hojas): " . count($dto->forms));

        $result = $persistence->persist(
            dto: $dto,
            sourceFilename: $filename,
        );

        if ($result->wasCreated) {
            $this->info("✅ Nueva estructura creada (ID: {$result->model->id})");
        } else {
            $this->info("♻️  Estructura ya existente (ID: {$result->model->id})");
        }

        return self::SUCCESS;
    }
}
