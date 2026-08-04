<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Instrumentacion de diagnostico de memoria (OOM en el worker de colas al
 * procesar cargas REM grandes -- "Allowed memory size of 536870912 bytes
 * exhausted", 512M, el limite por defecto de PHP CLI). Registra
 * memory_get_usage(true)/memory_get_peak_usage(true) en cada etapa principal
 * del pipeline (lectura Excel, parseo, persistencia, validacion, motor de
 * reglas) para localizar exactamente donde crece el consumo, sin cambiar
 * ningun comportamiento del pipeline -- solo logging de solo lectura.
 *
 * Desactivada por defecto (config('diagnostics.memory_probe_enabled'),
 * variable de entorno MEMORY_PROBE_ENABLED). Con el flag en false, log()
 * retorna de inmediato sin escribir ni calcular nada -- cero costo y cero
 * logging adicional en producción.
 */
class MemoryProbe
{
    public static function log(string $stage, array $context = []): void
    {
        if (! config('diagnostics.memory_probe_enabled')) {
            return;
        }

        Log::info('[MemoryProbe] ' . $stage, array_merge([
            'memory_current_mb' => round(memory_get_usage(true) / 1048576, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ], $context));
    }
}
