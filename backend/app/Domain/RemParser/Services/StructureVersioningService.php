<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;

class StructureVersioningService
{
    public function resolveNextVersion(int $anio, string $serie): int
    {
        $latest = RemTemplateStructure::where('anio', $anio)
            ->where('serie', $serie)
            ->max('version_number');

        return ($latest ?? 0) + 1;
    }
}
