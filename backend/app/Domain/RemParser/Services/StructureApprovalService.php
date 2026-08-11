<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class StructureApprovalService
{
    public function approve(RemTemplateStructure $structure, int $userId): RemTemplateStructure
    {
        if ($structure->status !== 'draft') {
            throw new InvalidArgumentException(
                "Solo estructuras en estado 'draft' pueden ser aprobadas (actual: {$structure->status})"
            );
        }

        $structure->status = 'approved';
        $structure->approved_at = now();
        $structure->approved_by = $userId;
        $structure->save();

        return $structure;
    }

    public function activate(RemTemplateStructure $structure): RemTemplateStructure
    {
        if ($structure->status !== 'approved') {
            throw new InvalidArgumentException(
                "Solo estructuras en estado 'approved' pueden ser activadas (actual: {$structure->status})"
            );
        }

        $active = RemTemplateStructure::where('anio', $structure->anio)
            ->where('serie', $structure->serie)
            ->where('status', 'active')
            ->first();

        if ($active) {
            $active->status = 'superseded';
            $active->superseded_by_id = $structure->id;
            $active->save();
        }

        $structure->status = 'active';
        $structure->save();

        // Invalida el agregado de progreso cacheado (ver
        // SectionCalibrationMatrixService::buildStructureCalibrationSummary())
        // -- cambio de estructura activa invalida cualquier resumen previo.
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        return $structure;
    }
}
