# ADR 0010: Alcance de series REM y motor de metas sanitarias

- **Fecha**: 2026-06-12
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Con la recepción de las especificaciones técnicas oficiales del MINSAL
(documentos de requisitos funcionales y Manual REM-P 2025-2026), se
amplía el conocimiento del alcance total del sistema:

- **Serie A** (mensual): ~30 hojas (A01-A34). Piloto A01 completado.
- **Serie P** (semestral): ~11 formularios (P1-P13). No implementada.
- **RF-02 a RF-05**: Validación cruzada, sumatorias, motor de metas y
  exportación están definidos pero no implementados.

Surge la necesidad de decidir el orden de implementación y la estrategia
técnica para soportar ambas series.

## Opciones evaluadas

### Opción 1: Parser genérico JSON-config para ambas series (elegida)

Un único RemParserService que procesa cualquier hoja de cualquier serie
usando la configuración JSON almacenada en rem_templates.config. Cada
hoja se describe con: data_start_row, section_break_pattern, columns[]
con mapeo semántico, reglas de validación por columna.

**Pros:**
- Misma lógica de parsing para A y P (DRY)
- Nuevas series (BM, BS, D) se agregan solo con config JSON
- Datos homogéneos: todas las series producen rem_data con misma estructura
- Flexibilidad ante cambios anuales de formato MINSAL

**Contras:**
- Mayor carga inicial de mapeo (~40 hojas entre A y P)
- Algunas hojas de Serie P tienen layouts muy distintos (secciones A-J)
- Requiere decidir data_end_row por sección para evitar ruido

### Opción 2: Parser dedicado por serie

RemParserServiceA, RemParserServiceP, etc., cada uno con lógica
hardcodeada para su serie específica.

**Pros:**
- Implementación más rápida para cada serie individual
- Código explícito, fácil de depurar

**Contras:**
- Duplicación masiva de lógica
- Cada cambio anual de MINSAL requiere modificar código
- Difícil mantener consistencia entre series
- No escala a futuro (BM, BS, D)

### Opción 3: Parser genérico + motor de metas separado

Similar a Opción 1, pero el motor de metas sanitarias (RF-04) se
implementa como un servicio independiente que opera sobre los rem_data
ya parseados, en lugar de integrarse en el parser.

**Pros:**
- Separación de concerns (parser lee archivos, motor evalúa metas)
- Motor de metas puede usar datos de múltiples series combinados
- Las fórmulas tipo [REM-P4_B_FILA_02_COL_03] operan sobre datos ya normalizados

**Contras:**
- Requiere diseño adicional del motor de expresiones
- Dependencia de datos normalizados y consistentes entre series

## Decisión

Se elige la **Opción 1 (parser genérico JSON-config)** para el parsing,
combinada con la **Opción 3 (motor de metas separado)** para RF-04.

Justificación:

1. El parser genérico ya está implementado y verificado con A01. Extenderlo
   a A02-A34 y luego a Serie P es incremental, no un rewrite.
2. La separación del motor de metas permite avanzar en paralelo: mapeo de
   hojas (04B-2b, 04B-2c) mientras se diseña el motor de expresiones
   (Fase 05).
3. El modelo de datos actual (rem_data con sheet, section, concept,
   professional, column_key, value) soporta ambas series sin cambios.

## Consecuencias

### Positivas

- Un solo parser para todo el sistema, configurable por JSON
- Datos de todas las series conviven en rem_data (misma estructura)
- Cambios anuales de MINSAL se resuelven actualizando el template JSON
- El motor de metas puede empezar a diseñarse en paralelo al mapeo

### Negativas / Riesgos

- Más hojas por mapear de lo estimado originalmente (~40 entre A y P)
- Algunas hojas de Serie P tienen layouts muy distintos (secciones A-J
  en lugar de A-E), lo que requerirá extensiones al config JSON
- Riesgo de que el config JSON se vuelva muy complejo si cada hoja tiene
  comportamientos excepcionales

### Mitigaciones

- Priorizar mapeo según uso institucional (definir con Don Amador)
- El JSON de config permite excepciones por hoja cuando sea necesario
- Documentar cada hoja mapeada con su estructura real en la bitácora

## Referencias

- ADR 0009: Arquitectura de procesamiento REM asíncrono
- Especificaciones Técnicas REM (MINSAL) — RF-01 a RF-05
- Manual REM-P 2025-2026 v1.0 (MINSAL)
- docs/architecture/especificaciones-minsal.md
