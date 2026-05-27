# ADR 0002: MySQL como base de datos principal

- **Fecha**: 2026-05-27
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Esalud necesita almacenar: datos estructurados (usuarios, centros de salud), datos semiestructurados (archivos REM con formato variable anual), y metadatos de documentos. Se evaluó MySQL 8 vs PostgreSQL 16.

## Opciones evaluadas

### Opción 1: MySQL 8

Pros:
- Infraestructura existente en CORMUDESI (MySQL 8 en producción)
- Soporte JSON nativo con índices funcionales (`JSON_EXTRACT`, `JSON_VALUE`)
- Rendimiento probado en cargas de trabajo similares en el sector público
- Compatibilidad total con Laravel Eloquent

Contras:
- JSON en MySQL no soporta índices GIN como PostgreSQL
- Limitaciones en recursividad de CTE

### Opción 2: PostgreSQL 16

Pros:
- JSONB con índices GIN para consultas flexibles
- CTE recursivas más potentes
- Extensiones como PostGIS

Contras:
- Infraestructura no disponible en CORMUDESI — requeriría migración
- Mayor complejidad operacional
- El equipo tiene más experiencia con MySQL

## Decisión

Se eligió **MySQL 8**.

## Consecuencias

### Positivas

- Sin cambios en infraestructura existente
- Operaciones DBA

### Negativas / Riesgos

- Monitorear rendimiento de consultas JSON en volúmenes altos de datos REM
- Evaluar necesidad de índices funcionales adicionales
- [Por completar con Dorian] Definir umbral de volumen para evaluar particionamiento

## Compliance

El compliance requiere registros de auditoría (activity_log). MySQL 8 maneja tablas de registro con millones de filas sin problemas con el particionamiento adecuado.
