# Mapeo Ley N° 21.663 — Ciberseguridad

> Fecha: 2026-05-27

## Marco

La Ley N° 21.663 (Ley Marco de Ciberseguridad) establece obligaciones para organismos públicos en materia de seguridad de la información. Como sistema institucional de CORMUDESI, Esalud debe cumplir con los siguientes requerimientos.

## Mapeo obligaciones

| Artículo | Obligación | Cómo se cumple en Esalud | Estado |
|---|---|---|---|
| 5 | Política de seguridad de la información | Documento de seguridad en `docs/compliance/` | [Por completar con Dorian] |
| 6 | Designar encargado de ciberseguridad | [Por completar con Dorian] | [Por completar con Dorian] |
| 7 | Gestión de riesgos | Matriz de riesgos en `docs/compliance/risk-matrix.md` | Documentado |
| 8 | Notificación de incidentes | Plan de respuesta en `docs/compliance/incident-response.md` | Documentado |
| 9 | Registro de actividades (logging) | Spatie Activitylog + política de auditoría en `docs/compliance/audit-policy.md` | Documentado |
| 10 | Clasificación de información | Política en `docs/compliance/data-classification.md` | Documentado |
| 11 | Control de acceso | RBAC con Spatie Permission + Sanctum | [Por completar con Dorian] |
| 12 | Cifrado de datos | Cifrado en tránsito (HTTPS) y reposo (AES-256-CBC) | [Por completar con Dorian] |
| 13 | Continuidad operacional | [Por completar con Dorian] | [Por completar con Dorian] |
| 14 | Capacitación en ciberseguridad | [Por completar con Dorian] | [Por completar con Dorian] |
| 17 | Notificar incidentes a CSIRT dentro de 3 días | Procedimiento en `docs/compliance/incident-response.md` | Documentado |

## Próximos pasos

- [Por completar con Dorian] Validar el mapeo completo con el oficial de ciberseguridad de CORMUDESI
- [Por completar con Dorian] Definir plazos de implementación para cada obligación
- [Por completar con Dorian] Establecer proceso de auditoría interna
