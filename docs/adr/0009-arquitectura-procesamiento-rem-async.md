# ADR 0009: Arquitectura de procesamiento REM asíncrono (Fase 04B-2a)

- **Fecha**: 2026-05-29
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Fase 04A implementó el upload de archivos REM con status 'pending'. Ahora es necesario procesar estos archivos para extraer datos estructurados. Los archivos REM .xlsm pesan ~1.6 MB y contienen múltiples hojas con 131 columnas × 258 filas cada una, lo que hace que el parsing tome 5-7 segundos por archivo.

## Decisiones

### D1: Procesamiento asíncrono vía Job + Queue

El parsing se delega a un Job (`ProcessRemUploadJob`) encolado en la queue `database`. El controlador `store` crea el upload con status `pending`, dispara el Job y responde inmediatamente al frontend.

**Justificación**: 
- El parsing toma 5-7s por archivo (bloquearía la respuesta HTTP)
- Queue database es suficiente para desarrollo (Redis + Horizon en despliegue)
- Permite reintentos y trazabilidad via `failed_jobs`

### D2: Transición de estados

```
pending → processing → success / with_errors / failed
```

- **failed**: archivo corrupto o estructura totalmente inválida → NO guarda datos en rem_data
- **with_errors**: estructura OK pero celdas inválidas → guarda lo válido + error_report detallado
- **success**: 100% procesado sin errores

### D3: Error reporting celda por celda

`error_report` en `rem_uploads` contiene:
```json
{
  "summary": {
    "total_rows_processed": 22,
    "total_cells_parsed": 682,
    "total_error_cells": 0
  },
  "errors": [
    {
      "type": "data",
      "sheet": "A01",
      "row": 15,
      "column": "F",
      "value": "texto",
      "reason": "No es un numero entero valido"
    }
  ]
}
```

### D4: Parsing con config JSON por hoja

Cada template REM tiene un `config` JSON que define:
- `metadata`: año, tipo, versión del REM
- `validation`: hojas esperadas, tamaño máximo
- `sheets[]`: por hoja: estructura (header_row, data_start_row, section_break_pattern), columnas con mapeo a claves demográficas, reglas de validación

Esto permite que cambios anuales en los formatos REM se resuelvan actualizando el JSON del template, sin modificar código.

### D5: Solo hoja A01 en piloto

Por ahora solo la hoja A01 está configurada con 22 filas de datos extraídas. Las hojas A02-A34 se mapearán en Fase 04B-2b.

## Consecuencias

- **Positivas**: Respuesta HTTP inmediata al subir archivos, trazabilidad de errores, flexibilidad ante cambios de formato
- **Negativas**: Complejidad adicional (queue worker debe estar corriendo), latencia entre upload y datos disponibles
- **Riesgo**: Queue database puede saturarse con muchos uploads simultáneos → migrar a Redis en producción

## Referencias

- ADR 0008: Estrategia de almacenamiento de archivos REM
- Laravel Queues: https://laravel.com/docs/queues
- phpoffice/phpspreadsheet v5.7.0
