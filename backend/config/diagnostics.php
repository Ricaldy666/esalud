<?php

return [
    // Activa el logging de memory_get_usage()/memory_get_peak_usage() de
    // App\Support\MemoryProbe en cada etapa del pipeline de carga REM.
    // Debe quedar en false en producción salvo que se este diagnosticando
    // un problema de memoria puntual -- genera volumen adicional de log.
    'memory_probe_enabled' => env('MEMORY_PROBE_ENABLED', false),
];
