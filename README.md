# Esalud

Sistema institucional de gestión estadística de Atención Primaria de Salud (APS) para CORMUDESI, Iquique, Chile. Procesa archivos REM del MINSAL, evalúa metas sanitarias y presenta análisis epidemiológico, cumpliendo la Ley N° 21.663 de Ciberseguridad.

## Stack tecnológico

| Capa | Tecnología | Versión |
|---|---|---|
| Backend | Laravel | 13.x |
| Lenguaje | PHP | ^8.3 |
| Base de datos | MySQL | 8.x |
| Frontend | React | 19.x |
| Lenguaje frontend | TypeScript | 5.x |
| Bundler | Vite | 8.x |
| Estilos | Tailwind CSS | 4.x |
| Autenticación API | Laravel Sanctum | — |
| Auditoría | Spatie Activitylog | — |
| Permisos | Spatie Permission | — |
| Cola async | Redis + Laravel Queues + Horizon | — |

## Estructura general

```
esalud/
├── backend/         → API Laravel (MVC + Domain)
├── frontend/        → SPA React + TypeScript + Vite
├── docs/            → Documentación técnica, ADRs, compliance
├── README.md
├── CHANGELOG.md
└── CONTRIBUTING.md
```

## Estado actual

**Fase 00 — Setup Documental completada.**

- Migración del frontend de JavaScript a TypeScript
- Estructura de documentación profesional creada
- 4 ADRs con decisiones técnicas justificadas
- Documentos de compliance Ley 21.663 (estructuras base)
- Repositorio de bitácora personal creado

## Documentación

Ver [`docs/00-index.md`](docs/00-index.md) para el índice completo.

## Responsable

**Dorian** — Systems Engineer, CORMUDESI.
