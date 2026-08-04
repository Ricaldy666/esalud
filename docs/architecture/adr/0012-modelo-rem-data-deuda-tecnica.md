# ADR-0012: Deuda Técnica en Modelo rem_data Detectada en Piloto Serie P

## Status
Documentado (no implementado todavía)

## Contexto
Durante el piloto de mapeo de Serie P (Fase 04B-2c), procesando REM-P1
Sección A, se identificaron dos puntos de deuda técnica en el modelo
rem_data:

### Punto 1: Ambigüedad en campo `section`
El campo `section` almacena el section_code (A, B, C, etc.), NO el
sheet_name (A01, P1, etc.). Esto significa que múltiples hojas con
"Sección A" (A01, A19a, P1) tienen el mismo valor en este campo. La
distinción real entre hojas se hace por rem_upload_id (que apunta al
template usado).

Riesgos:
- Queries agregadas mal escritas pueden mezclar datos de hojas distintas
- Reportes que solo filtren por section sin rem_upload_id pueden dar
  resultados incorrectos
- Confusión al consultar datos manualmente en BD

### Punto 2: professional_column captura sub-conceptos en Serie P
En REM-P1, la columna B no es un profesional real sino un sub-concepto
(Oral Combinado, Mujer, Hombres). El parser lo guarda como "professional"
pero semánticamente es incorrecto.

## Decisión
Postergar refactor a Fase 05+ (cuando se implemente Motor de Metas y
Reportes).

Razones:
1. El sistema funciona correctamente para procesamiento
2. La distinción real funciona vía rem_upload_id (no hay datos mezclados)
3. Refactor requiere: migración de BD, ajuste del parser, ajuste del
   seeder, ajuste de tests, ajuste de queries futuras
4. El piloto Serie P validó la arquitectura genérica — refactorizar
   ahora bloquea el avance

## Opciones de refactor futuro (a evaluar)
- Opción A: Renombrar `section` → `section_code` + agregar `sheet_code`
  (A01, P1) como columna separada
- Opción B: Campo compuesto `sheet_section` tipo "A01-A", "P1-A"
- Opción C: Mantener `section` pero documentar mejor en API/queries la
  necesidad de joinear con rem_upload + rem_template

Decisión final se tomará al iniciar Fase 05 (Motor de Metas) cuando las
queries de reportes lo requieran.

## Consecuencias
- Las queries actuales deben SIEMPRE filtrar por rem_upload_id + section
- Documentar en código (RemData model) que `section` es ambiguo sin
  rem_upload_id
- En reportes futuros, exponer sheet_name vía join con rem_template
