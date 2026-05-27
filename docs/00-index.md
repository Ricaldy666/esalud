# Índice de documentación

> Fecha: 2026-05-27

## Arquitectura

| Documento | Descripción |
|---|---|
| [ARCHITECTURE.md](architecture/ARCHITECTURE.md) | Visión general, diagrama Mermaid, capas y flujo HTTP |
| [stack.md](architecture/stack.md) | Tabla completa del stack tecnológico con justificaciones |
| [database-er.md](architecture/database-er.md) | Modelo ER preliminar con 12 entidades y definición de campos |
| [api-design.md](architecture/api-design.md) | Diseño de la API REST: versionado, formatos, paginación |
| [security.md](architecture/security.md) | Políticas de seguridad: Sanctum, Spatie, cifrado, headers |
| [folder-structure.md](architecture/folder-structure.md) | Organización de backend (Domain) y frontend (Feature-based) |

## Decisiones técnicas (ADR)

| Documento | Descripción |
|---|---|
| [ADR README](adr/README.md) | Índice de ADRs registrados |
| [ADR Template](adr/template.md) | Plantilla para nuevos ADRs |
| [ADR 0001](adr/0001-stack-laravel-react.md) | Stack Laravel + React + MySQL |
| [ADR 0002](adr/0002-mysql-como-bd.md) | MySQL como base de datos principal |
| [ADR 0003](adr/0003-typescript-frontend.md) | TypeScript en el frontend |
| [ADR 0004](adr/0004-arquitectura-dos-repositorios.md) | Arquitectura de dos repositorios |

## Compliance (Ley 21.663)

| Documento | Descripción |
|---|---|
| [ley-21663-mapping.md](compliance/ley-21663-mapping.md) | Mapeo de obligaciones de la Ley de Ciberseguridad |
| [risk-matrix.md](compliance/risk-matrix.md) | Matriz de riesgos con 10 escenarios identificados |
| [incident-response.md](compliance/incident-response.md) | Plan de respuesta a incidentes de seguridad |
| [audit-policy.md](compliance/audit-policy.md) | Política de auditoría y retención de logs |
| [data-classification.md](compliance/data-classification.md) | Clasificación de datos en 4 niveles |

## Manuales

| Documento | Descripción |
|---|---|
| [user-manual.md](manuals/user-manual.md) | Manual de usuario del sistema |
| [admin-manual.md](manuals/admin-manual.md) | Manual de administración |
| [technical-manual.md](manuals/technical-manual.md) | Manual técnico |
| [deployment-manual.md](manuals/deployment-manual.md) | Manual de despliegue |

## API

| Documento | Descripción |
|---|---|
| [openapi.yaml](api/openapi.yaml) | Especificación OpenAPI 3.0 |
