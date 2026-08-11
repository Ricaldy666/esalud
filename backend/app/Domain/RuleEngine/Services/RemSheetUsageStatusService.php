<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RuleEngine\Models\RemSheetUsageStatus;
use App\Domain\RuleEngine\Models\RemSheetUsageStatusHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Gestiona el estado de uso de hojas REM (aplicable / no_utilizada),
 * determinado explicitamente por Estadistica APS -- nunca inferido
 * automaticamente. Ver migracion 2026_08_11_000001 para el diseño
 * completo (por que VARCHAR + validacion en Laravel en vez de ENUM SQL,
 * por que asociado a anio+serie+sheet_name y no a un structure_id puntual).
 */
class RemSheetUsageStatusService
{
    public const STATUS_APLICABLE = 'aplicable';

    public const STATUS_NO_UTILIZADA = 'no_utilizada';

    /**
     * Unica fuente de verdad de los estados permitidos -- agregar un
     * estado futuro es un cambio de una linea aqui, nunca una migracion
     * de esquema (el campo `status` es VARCHAR sin restriccion SQL).
     */
    public const ALLOWED_STATUSES = [
        self::STATUS_APLICABLE,
        self::STATUS_NO_UTILIZADA,
    ];

    /**
     * Ausencia de fila para (anio,serie,sheet_name) significa 'aplicable'
     * -- nunca se crea una fila para hojas que no se apartan del default.
     */
    public function getStatusFor(int $anio, string $serie, string $sheetName): string
    {
        $row = RemSheetUsageStatus::where('anio', $anio)
            ->where('serie', $serie)
            ->where('sheet_name', $sheetName)
            ->first();

        return $row->status ?? self::STATUS_APLICABLE;
    }

    /**
     * Todas las filas de (anio,serie) que NO estan en estado 'aplicable'
     * -- hoy solo existe 'no_utilizada', pero el metodo no asume cual es
     * el unico estado "distinto de aplicable" mas alla de la constante.
     *
     * @return Collection<int, RemSheetUsageStatus>
     */
    public function getNonAplicableSheets(int $anio, string $serie): Collection
    {
        return RemSheetUsageStatus::where('anio', $anio)
            ->where('serie', $serie)
            ->where('status', '!=', self::STATUS_APLICABLE)
            ->get();
    }

    /**
     * Registra explicitamente un cambio de estado -- SIEMPRE una accion
     * humana (comando/UI administrativa), nunca automatica. Crea la fila
     * si no existia (solo cuando el nuevo estado no es 'aplicable' --
     * volver a 'aplicable' una hoja que nunca tuvo fila es un no-op sin
     * sentido, ver guard abajo) o la actualiza, y registra la transicion
     * en el historial sin importar si la fila es nueva o existente.
     */
    public function setStatus(
        int $anio,
        string $serie,
        string $sheetName,
        string $status,
        string $reason,
        string $decidedBy,
        ?int $structureId,
    ): RemSheetUsageStatus {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Estado '{$status}' invalido. Permitidos: " . implode(', ', self::ALLOWED_STATUSES)
            );
        }

        $row = RemSheetUsageStatus::where('anio', $anio)
            ->where('serie', $serie)
            ->where('sheet_name', $sheetName)
            ->first();

        $previousStatus = $row->status ?? self::STATUS_APLICABLE;

        if ($previousStatus === $status) {
            throw new InvalidArgumentException(
                "La hoja {$sheetName} ({$anio}/{$serie}) ya esta en estado '{$status}' -- no hay transicion que registrar."
            );
        }

        $decidedAt = now();

        if (!$row) {
            $row = new RemSheetUsageStatus();
            $row->anio = $anio;
            $row->serie = $serie;
            $row->sheet_name = $sheetName;
        }

        $row->status = $status;
        $row->reason = $reason;
        $row->decided_by = $decidedBy;
        $row->decided_at = $decidedAt;
        $row->structure_id = $structureId;
        $row->save();

        RemSheetUsageStatusHistory::create([
            'rem_sheet_usage_status_id' => $row->id,
            'previous_status' => $previousStatus,
            'new_status' => $status,
            'reason' => $reason,
            'changed_by' => $decidedBy,
            'changed_at' => $decidedAt,
            'structure_id' => $structureId,
        ]);

        // Invalida el agregado de progreso cacheado (ver
        // SectionCalibrationMatrixService::buildStructureCalibrationSummary())
        // -- un cambio de estado de uso de hoja cambia el denominador de aplicables.
        Cache::forget(SectionCalibrationMatrixService::CALIBRATION_SUMMARY_CACHE_KEY);

        return $row->fresh();
    }
}
