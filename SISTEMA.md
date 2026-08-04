# SISTEMA.md — Esalud

> Documento de referencia oficial del sistema. Describe qué es, para qué existe,
> cómo está organizado y cómo se comporta en producción. Para instalación y
> despliegue paso a paso ver [`docs/manuals/deployment-manual.md`](docs/manuals/deployment-manual.md)
> y [`docs/handoff/DEPLOYMENT.md`](docs/handoff/DEPLOYMENT.md); para uso diario
> ver [`docs/MANUAL_USUARIO.md`](docs/MANUAL_USUARIO.md); para detalle técnico
> ver [`docs/MANUAL_TECNICO.md`](docs/MANUAL_TECNICO.md).

---

## 1. Objetivo del sistema y normativa vigente

Esalud es el sistema institucional de gestión estadística de Atención Primaria
de Salud (APS) de la Corporación Municipal de Desarrollo Social de Iquique
(CORMUDESI), Chile. Su objetivo es digitalizar la carga, validación y
consolidación de los archivos **REM** (Resumen Estadístico Mensual) que los
establecimientos de salud reportan al Ministerio de Salud (MINSAL) a través
del DEIS (Departamento de Estadística e Información en Salud), reduciendo el
trabajo manual en Excel y detectando errores de consistencia antes de que el
reporte se remita a la autoridad sanitaria.

El sistema debe cumplir con:

| Normativa | Alcance | Estado |
|---|---|---|
| Ley N° 21.663 (Ciberseguridad) | Autenticación, cifrado, registro de acceso, HTTPS | Implementado; **HTTPS es obligatorio en producción** — ver §9 |
| Ley N° 19.628 (Protección de datos personales) | Datos de salud sensibles, acceso por roles, auditoría | Implementado (RBAC + `activity_log`) |
| Normas MINSAL / DEIS (REM) | Estructura y validación de archivos REM oficiales según el Manual REM vigente | En desarrollo — Serie A implementada; Serie P planificada, no implementada |

Las especificaciones funcionales detalladas de MINSAL están documentadas en
[`docs/architecture/especificaciones-minsal.md`](docs/architecture/especificaciones-minsal.md).

---

## 2. Módulos principales

| Módulo | Función | Estado |
|---|---|---|
| **Cargas REM** | Subir, previsualizar y procesar archivos Excel REM Serie A | Operativo |
| **Motor de validación estructural** | Verifica que el archivo respete el layout de celdas de la estructura activa | Operativo |
| **Motor de Reglas (RuleEngine)** | Evalúa reglas de consistencia (RF-02: sumas, subtotales, relaciones entre celdas) sobre los datos cargados | Operativo — 764 reglas activas, 859 bindings, estructura Serie A v15 activa |
| **Catálogo de Reglas / Certificación** | Interfaz para administrar, certificar y comparar reglas del motor contra el catálogo técnico | Operativo (roles Administrador/Superadmin/Revisor) |
| **Criterios funcionales** | Flujo de revisión humana para decidir el comportamiento funcional de cada celda/fila (p. ej. "puede quedar vacío", "debe registrar cero") | Operativo |
| **Calibración REM** | Herramienta de mapeo celda a celda de una estructura REM nueva, con matriz de patrones por sección | Operativo (roles Superadmin/Analista/Revisor/Auditor) |
| **Gestión de usuarios, roles y centros de salud** | CRUD con control de acceso por rol (Spatie Permission) | Operativo (rol Administrador) |
| **Auditoría** | Registro de cambios (creado/actualizado/eliminado) sobre entidades sensibles | Operativo (rol Administrador) |
| **GES** / **Metas APS** | Accesos de demostración visual en el menú, sin funcionalidad real todavía | No implementado (placeholder "Próximamente") — corresponde a RF-03/RF-04 pendientes |

---

## 3. Flujo general

1. El usuario se autentica (Laravel Sanctum, sesión SPA).
2. Según su rol, ve un subconjunto del menú (ver §8).
3. El flujo principal del sistema es: **cargar un archivo REM → que el sistema
   lo procese y valide automáticamente → revisar el resultado → corregir o
   certificar reglas cuando corresponda**.
4. En paralelo, los roles de administración gestionan usuarios, centros de
   salud y el catálogo de reglas que rige la validación.
5. Toda acción sensible (crear/editar/eliminar usuarios y centros) queda
   registrada en el módulo de Auditoría.

---

## 4. Flujo de carga y validación REM

```
Usuario                Frontend               Backend (Jobs asíncronos)
  │                       │                          │
  │  selecciona archivo   │                          │
  ├──────────────────────>│  POST /rem-uploads/preview
  │                       ├─────────────────────────>│  detecta serie, período,
  │                       │<─────────────────────────┤  centro de salud
  │  confirma carga       │                          │
  ├──────────────────────>│  POST /rem-uploads        │
  │                       ├─────────────────────────>│  status=pending
  │                       │                          │  ├─ ProcessRemUploadJob
  │                       │                          │  │  (status=processing → parseo Excel,
  │                       │                          │  │   escritura en rem_data)
  │                       │                          │  ├─ ValidateRemUploadJob
  │                       │                          │  │  (status=validating → validación
  │                       │                          │  │   estructural + funcional)
  │                       │                          │  └─ ValidateWithEngineJob
  │                       │                          │     (RuleEngine: RF-02, reglas de
  │                       │                          │      consistencia entre celdas)
  │  polling de estado    │  GET /rem-uploads/{id}/status
  │<──────────────────────┤<─────────────────────────┤  status final:
  │  ve resultado          │                          │  success | with_errors | rejected | failed
```

- El archivo se valida en dos capas: **estructural** (¿la celda existe donde
  la estructura activa la espera?) y **funcional** (¿el valor tiene sentido
  según las reglas del RuleEngine y los criterios funcionales certificados?).
- Los resultados quedan en `rem_validation_results` (una fila por regla
  evaluada) y se resumen en la pantalla de "Validación REM" por severidad y
  por formulario/sección.
- El pipeline está diseñado para operar con `memory_limit=512M` sin
  degradar: las secciones de `cell_data` se liberan de memoria a medida que
  se procesan, en vez de mantener las ~379 secciones cargadas
  simultáneamente (detalle técnico en `docs/MANUAL_TECNICO.md` §6).

---

## 5. Tablas principales de base de datos

| Tabla | Propósito | Campos clave |
|---|---|---|
| `users` | Usuarios del sistema | `email`, `rut`, roles (Spatie) |
| `health_centers` | Establecimientos de salud (CESFAM/CECOSF/PSR/SAPU/SAR) | código DEIS |
| `rem_templates` | Plantilla REM por año/tipo | `year`, `rem_type`, `config` (JSON), `is_active` |
| `rem_uploads` | Cada archivo REM subido | `uuid`, `health_center_id`, `user_id`, `year`, `month`, `rem_type`, `status`, `error_report` |
| `rem_data` | Datos parseados del archivo, por sección | `rem_upload_id`, `section`, `data` (JSON) |
| `rem_template_structures` | Estructura versionada de celdas de una serie/año (activable) | `anio`, `serie`, `hash_estructura`, `estructura` (JSON), `status` |
| `rem_rules` | Catálogo de reglas del motor de validación | `rule_key`, `rule_type`, `severity`, `scope`, `config` (JSON), `status`, `version` |
| `rem_rule_bindings` | Asocia una regla a una estructura/serie/año/condición específica | `rule_id`, `bindable_type`, `serie`, `anio`, `conditions` (JSON) |
| `rem_rule_versions` / `rem_rule_execution_logs` | Historial de versiones y ejecuciones del motor | — |
| `rem_validation_results` | Resultado de cada regla evaluada sobre una carga | `rem_upload_id`, `rule_key`, `severity`, `passed`, `message`, `context` (JSON) |
| `rem_calibrations` / `rem_calibration_cells` | Proceso de mapeo celda a celda al certificar una estructura nueva | `structure_id`, `hoja`, `progress_pct`, `coordinate`, `behavior` |
| `rule_engine_settings` | Feature flags del motor (`enabled`, `mode`, `fail_open`, `log_mode`) | vacío = usa defaults de `config/rule-engine.php` |
| `activity_log` | Auditoría de cambios (Spatie Activitylog) | evento, entidad, valores antes/después |

Diagrama entidad-relación completo en
[`docs/architecture/database-er.md`](docs/architecture/database-er.md).

---

## 6. Funciones mínimas

Para que el sistema sea operativo, deben funcionar como mínimo:

1. Autenticación y control de acceso por rol.
2. Subir, previsualizar y confirmar una carga REM.
3. Procesar el archivo (parseo) sin agotar memoria ni tiempo de espera del
   worker.
4. Validar estructuralmente contra la estructura REM activa.
5. Validar funcionalmente contra el catálogo de reglas vigente (RuleEngine).
6. Mostrar el resumen de validación (cumplimiento %, errores por severidad y
   por formulario).
7. Permitir consultar y certificar reglas del catálogo (roles autorizados).
8. Gestionar usuarios y centros de salud con control de acceso.
9. Registrar toda acción sensible en el log de auditoría.

---

## 7. Estados de una carga REM

| Estado (`rem_uploads.status`) | Significado | Progreso mostrado | Paso del pipeline |
|---|---|---|---|
| `pending` | Archivo recibido, esperando procesamiento | 20% | Antes de `ProcessRemUploadJob` |
| `processing` | Procesando archivo REM (parseo Excel) | 50% | `ProcessRemUploadJob` en curso |
| `validating` | Validando datos y reglas de consistencia | 75% | `ValidateRemUploadJob` / `ValidateWithEngineJob` en curso |
| `success` | Procesamiento completado exitosamente | 100% | Terminal — sin errores |
| `with_errors` | Procesamiento completado con observaciones | 100% | Terminal — con errores/advertencias |
| `rejected` | Estructura no disponible para la serie y período seleccionados | 100% | Terminal — no hay `rem_template_structures` activa compatible |
| `failed` | Error en el procesamiento del archivo | 100% | Terminal — excepción no controlada en el pipeline |

`success`, `with_errors`, `rejected` y `failed` son estados terminales: el
frontend deja de hacer polling al alcanzarlos.

---

## 8. Estructura del menú

| Ítem de menú | Ruta | Roles con acceso |
|---|---|---|
| Dashboard | `/` | Todos |
| Cargas REM | `/rem-uploads` | Todos |
| Calibración REM | `/calibracion` | Superadmin, Analista, Revisor, Auditor |
| Criterios funcionales | `/criterios-funcionales` | Todos |
| GES *(Próximamente)* | `/ges` | Analista (placeholder visual) |
| Metas APS *(Próximamente)* | `/metas-aps` | Analista (placeholder visual) |
| Motor de Reglas | `/rule-engine` | Administrador, Superadmin, Revisor, Auditor |
| Reglas | `/rule-engine/rules` | Administrador, Superadmin |
| Logs | `/rule-engine/logs` | Administrador, Superadmin |
| Estructuras | `/rule-engine/structures` | Administrador, Superadmin |
| Bindings | `/rule-engine/bindings` | Administrador, Superadmin |
| Comparación | `/rule-engine/comparison` | Administrador, Superadmin |
| Configuración | `/rule-engine/config` | Administrador, Superadmin |
| Catálogo de Reglas | `/rule-engine/catalog` | Administrador, Superadmin, Revisor |
| Usuarios | `/users` | Administrador |
| Centros de Salud | `/health-centers` | Administrador |
| Auditoría | `/audit` | Administrador |

Roles del sistema (Spatie Permission): `Superadmin`, `Administrador`,
`Auditor`, `Revisor`, `Analista`. El usuario semilla `admin@esalud.cl` tiene
`Superadmin` **y** `Administrador` (ver §9 — hallazgo corregido).

---

## 9. Recomendación técnica

- **HTTPS obligatorio en producción** para cumplir la Ley 21.663. Mientras el
  servidor sirva por HTTP, `SESSION_SECURE_COOKIE` debe permanecer en
  `false` (con `true` sobre HTTP el navegador descarta la cookie de sesión en
  silencio).
- **Worker de colas vía Supervisor**, no `queue:listen` ni terminal manual:
  `queue:work --timeout=180 --memory=512 --sleep=1` (sin `--tries` a nivel de
  Supervisor, para no sobrescribir el `$tries` propio de cada Job). Detalle
  completo en `docs/handoff/DEPLOYMENT.md` §6.
- El pipeline de carga fue corregido esta fase para operar de forma estable
  con `memory_limit=512M` (antes agotaba memoria por cache sin límite de
  `cell_data`); no se recomienda subir el límite de PHP como solución.
- **Hallazgo corregido en esta fase**: el rol `Administrador` no se sembraba
  en `RoleSeeder` ni se asignaba en `AdminUserSeeder`, aunque varias Policies
  y rutas del frontend lo exigían — esto habría dejado inaccesibles Usuarios,
  Centros de Salud, Auditoría y la mayoría del Motor de Reglas en una base de
  datos nueva. Corregido: ambos seeders ahora crean/asignan `Administrador`
  además de `Superadmin`. Verificado sin regresiones (mismos 35 fallos
  preexistentes de siempre en la suite de tests, no relacionados).
- **Pendiente, no bloqueante**: 35 tests de `RuleEngine` con fallos previos
  a esta fase (fuera de alcance de este despliegue); versionar el CSV que
  alimenta `RuleCatalogCsvSeeder`; exportar/importar la estructura activa
  (v15/id=36), `rem_rules`, `rem_rule_bindings`, `cell-data/` y
  `reglas-funcionales.json` al servidor de producción (no se transportan por
  `git pull`, ver `docs/handoff/DEPLOYMENT.md` §2-3).

---

## 10. Nombre del sistema

- **Nombre del proyecto / repositorio**: `Esalud` (usado en `README.md`,
  `APP_NAME`, y como identificador técnico interno).
- **Nombre mostrado al usuario final en la interfaz**: `Estadística APS`
  (texto visible en el sidebar y el header móvil de `AppLayout.tsx`).

Ambos nombres conviven actualmente en el sistema: "Esalud" identifica el
producto/repositorio a nivel técnico y de negocio (contratos, documentación,
commits); "Estadística APS" es el nombre de marca que ve el usuario final
dentro de la aplicación. No se unificaron en esta fase por no ser un cambio
de código de negocio — queda como decisión pendiente si se desea un nombre
único de cara al usuario.
