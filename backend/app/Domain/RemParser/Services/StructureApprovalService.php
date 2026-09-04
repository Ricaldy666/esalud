<?php

namespace App\Domain\RemParser\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // DB::afterCommit() (no Cache::forget() directo): activate() puede
        // correr dentro de una transaccion mas amplia (ej.
        // CertifiedStructurePromotionService::commit()). Un forget()
        // sincrono aqui corria ANTES del COMMIT real -- una request
        // concurrente en esa ventana recalculaba contra la estructura
        // todavia vieja (su conexion no ve los cambios sin confirmar bajo
        // REPEATABLE READ) y volvia a cachear ese resultado obsoleto por
        // CALIBRATION_SUMMARY_CACHE_TTL_SECONDS. Diferir el forget() hasta
        // el COMMIT real cierra esa ventana. Fuera de una transaccion
        // (caso normal), afterCommit() ejecuta el callback de inmediato --
        // sin cambio de comportamiento.
        DB::afterCommit(function () {
            Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);
        });

        return $structure;
    }
}
