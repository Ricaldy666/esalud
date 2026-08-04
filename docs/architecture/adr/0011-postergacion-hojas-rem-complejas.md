# ADR 0011: Postergación de hojas REM con estructura compleja (A03, A07, A10)

- **Fecha**: 2026-06-13
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Durante la Fase 04B-2b-2-G2a se inspeccionaron las hojas A03, A07 y A10
del archivo `SA_26_V1.2-2.xlsm` (REM A 2026) para determinar si son
mapeables con el parser genérico actual (`RemParserService::parseSheet()`)
y los helpers de generación de columnas etarias (`baseColumnsAgeSexPairs()`).

El parser genérico implementado en 04B-2b-1 soporta una estructura
específica: header_row único, concept_column, professional_column,
total_column, pares de columnas Hombres/Mujeres por rango etario
(age/sex pairs), y section_break_pattern para detenerse en la primera
sección. Esta estructura funciona para 13 hojas de G1.

Las tres hojas inspeccionadas presentan variantes estructurales que el
parser actual no puede procesar sin modificaciones significativas.

## Hallazgos por hoja

### A03 — Evaluación del Desarrollo (6+ sub-secciones)

- Tiene secciones A.1, A.2, ..., A.6, B, B.1 (múltiples, no solo "SECCIÓN A")
- Concepto jerárquico en dos columnas: col A (actividad/evaluación) y
  col B (sub-resultado: Normal, Alterado, Riesgo, Retraso)
- `professional_column` no existe; columna B funciona como sub-concepto
- Rangos etarios varían por sub-sección (A.1: meses individuales <24;
  A.2: <7m, 7-11m, 12-17m...; A.6: 3m, 4m...)
- Columnas extra por sección: Pueblos Originarios + Migrantes en algunas
- El parser actual solo capturaría 3 filas de la Sección A.1

### A07 — Especialidades y Sub-Especialidades

- 141 columnas de ancho (vs ~40-60 típico de G1)
- Edades SIN pares H/M: columnas C-U valores únicos por rango etario
  (0-4, 5-9, 10-14... 80+), no desagregados por sexo
- Columnas adicionales: Beneficiarios (V), Por Sexo H/M (W-X),
  Consultas Nuevas según Origen (Y+: Total, APS, CAE/CDT/CRS/Hospitalización,
  Urgencia) para menores de 18 y 18+
- No existe `professional_column`
- `baseColumnsAgeSexPairs()` no es aplicable (no hay pares H/M)

### A10 — No existe en el archivo REM

- La hoja A10 no está presente en `SA_26_V1.2-2.xlsm`
- Posible error de nomenclatura en el plan original
- Se reemplaza por A11 en adelante

## Opciones evaluadas

### Opción 1: Mapeo mínimo ahora, detalle después (descartada)

Mapear solo concepto + total de la primera sección de A03 y A07,
ignorando columnas demográficas.

Pros:
- Avance inmediato en cobertura numérica

Contras:
- Datos incompletos (sin desglose etario ni sexo)
- Falsa sensación de avance; habría que re-mapear en G4
- Las columnas de A07 no son age/sex pairs, el parser no las procesaría
  correctamente como columnas de datos

### Opción 2: Postergar a G4 + continuar con G2/G3 simples (ELEGIDA)

Postergar A03, A07 y hojas huérfanas a la sub-fase G4, que cubrirá
secciones B+ de todas las hojas y estructuras complejas. Continuar G2
con hojas consecutivas A12-A22 (probablemente G1-like).

Pros:
- No bloquea el avance de G2/G3
- G4 absorberá naturalmente las hojas complejas junto con secciones B+
- El parser se adaptará una sola vez en G4 con conocimiento completo de
  todas las variantes

Contras:
- Cobertura total de Serie A se retrasa hasta G4
- Las hojas complejas quedan "pendientes" por más tiempo

### Opción 3: Modificar el parser ahora (descartada)

Extender `RemParserService` para soportar:
- Concepto jerárquico (dos columnas)
- Edades sin pares H/M
- Múltiples configuraciones por hoja física (una por sub-sección)
- Columnas especiales no etarias como columnas de datos y no como
  parte del esquema age/sex pairs

Pros:
- Solución definitiva inmediata

Contras:
- Riesgo alto de regresión en G1
- Tiempo de desarrollo significativo sin conocer aún todas las variantes
  de G3 y G4
- Mejor hacer un único refactor en G4 cuando se conozcan todas las
  variantes estructurales de toda la Serie A

## Decisión

Se elige **Opción 2: Postergar a G4**. Las hojas A03 y A07 se moverán
a la sub-fase G4 (junto con secciones B+ de todas las hojas). A10 se
elimina del plan por no existir en el archivo REM oficial.

## Consecuencias

### Positivas

- G2 puede avanzar inmediatamente con A12-A22 (hojas probables G1-like)
- G3 (A24-A28, A33, A34) también son probables G1-like y no se bloquean
- En G4 se hará un único refactor del parser con conocimiento completo
  de todas las variantes estructurales de la Serie A
- Se liberan las hojas huérfanas (A11, A19a, A19b, A21) para
  inspeccionarlas junto con G2

### Negativas / Riesgos

- A03 y A07 no estarán disponibles hasta G4
- Riesgo bajo: el usuario final no consume datos REM hasta que el
  mapeo esté completo (mínimo G4)
- Las hojas huérfanas (A11, A19a, A19b, A21) deben inspeccionarse;
  podrían sumarse al grupo de complejas si presentan variantes

## Referencias

- ADR 0009: Arquitectura de procesamiento REM asíncrono
- ADR 0010: Alcance de series REM y motor de metas sanitarias
- Fase 04B-2b-1: Bloque 1 Serie A (G1: 12 hojas)
- Hallazgos de inspección: `esalud-bitacora/phases/fase-04b-2b-2-g2a/` (próximamente)
