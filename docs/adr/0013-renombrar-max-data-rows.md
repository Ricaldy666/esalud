# ADR-0013: Renombrar max_data_rows a data_end_row (deuda técnica)

## Status
Documentado (refactor diferido a fase futura)

## Contexto
Durante el mapeo de REM-P2 Sección A (Fase 04B-2c-piloto-P2) se descubrió
que el campo `max_data_rows` en la config JSON de templates NO representa
la cantidad máxima de filas a procesar, sino la fila absoluta donde se
detiene el parseo.

### Comportamiento descubierto
- `max_data_rows = 30` con `data_start_row = 12`
- NO procesa 30 filas de datos
- SÍ procesa filas 12-30 (19 filas)
- Si la sección real llega hasta fila 40, se pierden 10 filas

### Impacto detectado en P2
P2 inicialmente perdió 10 filas (Promedio Talla/Edad, Estado Nutricional,
Niños sin evaluación) hasta que se ajustó `max_data_rows = 40`.

## Por qué no se detectó antes
En las 18 hojas previamente mapeadas (Serie A G1, G2-pivot, P1):
- Los valores de `max_data_rows` "coincidieron" con la fila final real
- No hubo evidencia de filas perdidas porque las secciones terminaban
  exactamente donde el parseo se detenía
- El nombre del campo sugería "cantidad de filas" pero el comportamiento
  era "fila final absoluta"

## Decisión
Postergar refactor a fase posterior (consolidación de Serie P o Fase 05).
Mantener el nombre actual con la siguiente interpretación documentada:

**`max_data_rows` = fila absoluta donde DETENER el parseo (inclusive)**

## Opciones de refactor futuro
- **Opción A (recomendada)**: Renombrar a `data_end_row` (semánticamente
  correcto, coherente con `header_row` y `data_start_row`)
- **Opción B**: Cambiar comportamiento a count real + recalcular todos los
  configs existentes (riesgoso, ~18 hojas afectadas)
- **Opción C**: Soportar ambos formatos con detección automática (deuda
  técnica permanente)

## Acciones de mitigación inmediata
1. Documentar el comportamiento en código (comentario en `RemParserService`)
2. Auditar configs existentes: verificar que ningún valor corte
   prematuramente la sección real
3. En nuevos configs, calcular:
   `max_data_rows = data_start_row + cantidad_esperada_de_filas - 1`

## Configs afectados (verificación pendiente)
- **Serie A G1**: 13 hojas — revisar si algún valor es menor a la última
  fila de datos real
- **Serie A G2**: A19a, A28, A34 — idem
- **Serie P**: P1 (`max_data_rows=25` con `data_start_row=11`, OK — 15
  filas reales caben en 11-25), P2 (corregido de 30 a 40)
