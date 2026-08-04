<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;

class StructureResolverService
{
    public function resolve(RemUpload $upload): ?int
    {
        $baseQuery = RemTemplateStructure::where('serie', $upload->rem_type)
            ->where('anio', $upload->year);

        $structure = (clone $baseQuery)
            ->where('status', 'active')
            ->orderByRaw('COALESCE(version_number, 0) DESC')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$structure) {
            $structure = (clone $baseQuery)
                ->orderByRaw('COALESCE(version_number, 0) DESC')
                ->orderBy('id', 'DESC')
                ->first();
        }

        return $structure?->id;
    }
}
