<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\DTOs\ParsedTemplateDTO;
use App\Domain\RemParser\DTOs\PersistResult;
use App\Domain\RemParser\Models\RemTemplateStructure;

class RemTemplateStructurePersistenceService
{
    private StructureVersioningService $versioning;

    public function __construct(?StructureVersioningService $versioning = null)
    {
        $this->versioning = $versioning ?? new StructureVersioningService();
    }

    public function persist(
        ParsedTemplateDTO $dto,
        ?int $remUploadId = null,
        ?int $remTemplateId = null,
        ?string $sourceFilename = null,
    ): PersistResult {
        $existing = RemTemplateStructure::where('anio', $dto->anio)
            ->where('serie', $dto->serie)
            ->where('hash_estructura', $dto->hashEstructura)
            ->first();

        if ($existing) {
            return new PersistResult(
                model: $existing,
                wasCreated: false,
            );
        }

        $versionNumber = $this->versioning->resolveNextVersion($dto->anio, $dto->serie);

        $model = new RemTemplateStructure();
        $model->rem_upload_id = $remUploadId;
        $model->rem_template_id = $remTemplateId;
        $model->anio = $dto->anio;
        $model->serie = $dto->serie;
        $model->hash_estructura = $dto->hashEstructura;
        $model->version_number = $versionNumber;
        $model->estructura = $dto->toArray();
        $model->metadata = $this->buildMetadata($sourceFilename);
        $model->source_filename = $sourceFilename;
        $model->status = 'draft';
        $model->save();

        return new PersistResult(
            model: $model,
            wasCreated: true,
        );
    }

    private function buildMetadata(?string $sourceFilename): array
    {
        $meta = [];

        if ($sourceFilename) {
            $meta['source_filename'] = $sourceFilename;
        }

        return $meta;
    }
}
