# ADR 0004: Arquitectura de dos repositorios

- **Fecha**: 2026-05-27
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

El proyecto Esalud tiene dos audiencias: la institución (CORMUDESI) recibe el sistema y su documentación; el desarrollador necesita mantener una bitácora personal de trabajo con prompts, fases, decisiones intermedias e informes de avance.

Se evaluó si usar un solo repositorio (con una carpeta `bitacora/`) o dos repositorios separados.

## Opciones evaluadas

### Opción 1: Dos repositorios separados

- `esalud/` — sistema público + documentación técnica institucional
- `esalud-bitacora/` — bitácora personal privada

Pros:
- Clara separación entre entregable institucional y proceso personal
- El repo público contiene solo lo relevante para CORMUDESI
- El repo privado puede contener prompts, borradores y reflexiones sin filtro
- Se puede compartir el repo público sin exponer la bitácora

Contras:
- Dos repositorios que mantener
- Necesidad de disciplina para mantener ambos actualizados

### Opción 2: Un solo repositorio con carpeta `bitacora/`

Pros:
- Todo en un lugar, un solo `git log`
- Mayor simplicidad operacional

Contras:
- Mezcla de contenido institucional con notas personales
- Riesgo de exponer la bitácora al compartir el repo
- El `git log` contendría mensajes personales irrelevantes para la institución

## Decisión

Se eligió **dos repositorios separados**.

## Consecuencias

### Positivas

- El repo `esalud/` contiene solo lo necesario para la institución
- `esalud-bitacora/` permite documentar libremente el proceso de desarrollo
- Al entregar el proyecto, se comparte solo `esalud/`

### Negativas / Riesgos

- Exigencia de disciplina: ambos repos deben actualizarse en cada sesión
- El `PROJECT_STATE.md` en la bitácora referencia ADRs en el repo público

## Compliance

La separación de repositorios no impacta directamente en compliance, pero permite que el repo institucional mantenga una traza limpia y auditable sin ruido del proceso de desarrollo.
