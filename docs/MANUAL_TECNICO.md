# Manual técnico — Esalud

> Sustituye al borrador `docs/manuals/technical-manual.md` (pendiente de
> retirar). Complementa a [`SISTEMA.md`](../SISTEMA.md) (qué es el sistema) y
> a [`docs/handoff/DEPLOYMENT.md`](handoff/DEPLOYMENT.md) (procedimiento de
> despliegue paso a paso, con comandos exactos para el servidor). Este manual
> resume la arquitectura, requisitos, instalación local, mantenimiento y
> troubleshooting operativo.

## Tabla de contenido

1. [Arquitectura del sistema](#1-arquitectura-del-sistema)
2. [Requisitos técnicos](#2-requisitos-técnicos)
3. [Instalación y configuración](#3-instalación-y-configuración)
4. [Despliegue](#4-despliegue)
5. [Mantenimiento](#5-mantenimiento)
6. [Troubleshooting](#6-troubleshooting)
7. [Referencia API](#7-referencia-api)

---

## 1. Arquitectura del sistema

| Capa | Tecnología | Versión |
|---|---|---|
| Backend | Laravel | 13.x |
| Lenguaje backend | PHP | ^8.3 |
| Base de datos | MySQL | 8.x |
| Frontend | React | 19.x |
| Lenguaje frontend | TypeScript | ~5.8 |
| Bundler | Vite | 8.x |
| Estilos | Tailwind CSS | 4.x |
| Autenticación API | Laravel Sanctum (SPA) | v4.3 |
| Permisos RBAC | Spatie Permission | v7.4 |
| Auditoría | Spatie Activitylog | v4.12 |
| Cola asíncrona | Database (Laravel Queues) | — |
| Parseo Excel | PhpSpreadsheet | — |

Organización del código:

- **Backend** (`backend/app/`): Domain-Driven Design por dominio —
  `Domain/REM/` (carga y parseo), `Domain/RemParser/` (extracción de
  estructura/metadata), `Domain/RuleEngine/` (motor de reglas RF-02),
  `Domain/Calibration/` (mapeo celda a celda), `Domain/Users/`,
  `Domain/HealthCenters/`, `Domain/Roles/`, `Domain/Audit/`, `Domain/Auth/`.
- **Frontend** (`frontend/src/`): organización por feature en
  `src/features/` (`rem/`, `rule-engine/`, `health-centers/`, `auth/`, …) y
  código compartido en `src/shared/`.
- **Pipeline asíncrono de carga REM**: `ProcessRemUploadJob` →
  `ValidateRemUploadJob` → `ValidateWithEngineJob`, encolados vía
  `QUEUE_CONNECTION=database`. Detalle funcional en `SISTEMA.md` §4.
- Diagrama completo y patrones aplicados en
  [`docs/architecture/ARCHITECTURE.md`](architecture/ARCHITECTURE.md);
  resumen del parser REM en
  [`ARQUITECTURA_REM_PARSER.md`](../ARQUITECTURA_REM_PARSER.md).

### Diseño de memoria del pipeline (relevante para operación)

`CellDataStorageService` cachea el contenido de `cell_data` (celdas leídas
del Excel) por sección. Hasta esta fase el caché no tenía límite y podía
acumular las ~379 secciones de un upload completo, agotando
`memory_limit=512M` a mitad de proceso. Se corrigió agregando evicción bajo
demanda: `RemParserService`, `ValidateRemUploadJob` y
`SectionCalibrationMatrixService` liberan cada sección/hoja de la caché
apenas terminan de usarla (`forgetSection()` / `forgetSheet()`). Con el fix,
el pipeline completo (`Process` + `Validate` + `Engine`) mide un pico real de
~226MB. **No subir `memory_limit` como solución** si vuelve a aparecer un
OOM — primero verificar si algún código nuevo volvió a acumular caché sin
evicción.

---

## 2. Requisitos técnicos

### Backend
- PHP **^8.3** (confirmado con 8.3.30) con extensiones: `bcmath`, `ctype`,
  `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `xml`,
  `zip`, `curl`, `gd`, `intl`, `tokenizer`, `SimpleXML`. `zip`+`xml`+`gd`
  son requeridas por PhpSpreadsheet (parseo de archivos REM `.xlsx/.xlsm`).
- Composer **2.x**
- MySQL **8.x** (o MariaDB compatible)
- `storage/` y `bootstrap/cache/` escribibles por el usuario de PHP-FPM.

### Frontend
- Node **^20 LTS** o superior (confirmado con v24.14.0) + npm **10.x+**
  (confirmado con 11.9.0). Sin restricción de `engines` declarada en
  `package.json`.

### Infraestructura de producción
- Nginx + PHP-FPM.
- Supervisor (worker de colas persistente — obligatorio, ver §5).
- HTTPS (obligatorio para cumplir Ley 21.663 — ver `SISTEMA.md` §1 y §9).

---

## 3. Instalación y configuración

### 3.1 Clonar e instalar

```bash
git clone https://github.com/Ricaldy666/esalud.git esalud
cd esalud

# Backend
cd backend
cp .env.example .env
composer install
php artisan key:generate

# Frontend
cd ../frontend
cp .env.example .env
npm install
```

### 3.2 Base de datos

```sql
CREATE DATABASE esalud_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
cd backend
php artisan migrate
php artisan db:seed   # RoleSeeder, AdminUserSeeder, HealthCenterSeeder, RemTemplateSeeder, RuleCatalogCsvSeeder
```

`RoleSeeder` crea los 5 roles del sistema (`Superadmin`, `Administrador`,
`Auditor`, `Revisor`, `Analista`) y `AdminUserSeeder` crea
`admin@esalud.cl` / `password` con **ambos** `Superadmin` y `Administrador`
asignados — necesario para que ese usuario pueda acceder de inmediato a
Usuarios, Centros de Salud, Auditoría y el Motor de Reglas (ver §6,
"rol Administrador ausente").

> **Nota**: los seeders oficiales dejan la base de datos con el esquema y
> los roles, pero **no** reconstruyen la estructura REM activa (v15/id=36),
> las 764 reglas certificadas ni los 859 bindings — eso se transporta por
> dump/restore selectivo, ver `docs/handoff/DEPLOYMENT.md` §3.

### 3.3 Variables de entorno — local vs. producción

`backend/.env` y `frontend/.env` reales **nunca se comparten ni se
commitean** (`.gitignore`). `.env.example` es un template neutro con
`localhost` como placeholder. Variables que cambian entre entornos:

| Variable | Local (LAN interna) | Producción |
|---|---|---|
| `APP_URL` | `http://<tu-ip-lan>:8080` | `https://atenea.cormudesi.cl` (o `http://` si aún no hay TLS) |
| `VITE_API_URL` | `/api/v1` (relativa) | `/api/v1` (misma, Nginx hace proxy bajo el mismo dominio) |
| `SESSION_SECURE_COOKIE` | `false` | `false` mientras sea HTTP; `true` solo con HTTPS activo |
| `SANCTUM_STATEFUL_DOMAINS` | `<tu-ip-lan>:5173,localhost:5173,127.0.0.1:5173` | `atenea.cormudesi.cl` |

`config/cors.php` ya cubre ambos entornos simultáneamente (incluye un
patrón regex para toda la subred `192.168.1.x:5173`) y no requiere
modificación por entorno. Detalle completo en `docs/handoff/DEPLOYMENT.md`
§11.

### 3.4 Servir en desarrollo

```bash
# Terminal 1 — Backend
cd backend && php artisan serve

# Terminal 2 — Worker de colas (necesario para que las cargas REM avancen)
cd backend && php artisan queue:work --queue=default --timeout=180 --memory=512 --sleep=1

# Terminal 3 — Frontend
cd frontend && npm run dev
```

No usar `queue:listen` en desarrollo salvo con `--timeout` explícito y alto
(su default de 60s mata jobs reales como `ValidateRemUploadJob`, que puede
tardar 60s+ — ver §6). `queue:work --once` o `queue:work` con los flags de
arriba es lo recomendado incluso en local.

---

## 4. Despliegue

El procedimiento completo, con comandos exactos, configuración de
Nginx/PHP-FPM/Supervisor, plan de transporte de datos (estructura REM
activa, reglas, bindings, `cell_data`), checklist PRE/POST-DEPLOY, respaldo
y rollback está en **[`docs/handoff/DEPLOYMENT.md`](handoff/DEPLOYMENT.md)**
— es el documento operativo autoritativo, no se duplica aquí.

Resumen de los puntos no negociables:

- **No usar** `php artisan serve`, `npm run dev` ni una terminal
  `queue:listen` abierta manualmente en producción.
- Worker de colas vía **Supervisor**: `queue:work database --queue=default
  --timeout=180 --memory=512 --sleep=1` (sin `--tries` — cada Job define el
  suyo).
- **HTTPS obligatorio** (Ley 21.663). Mientras no exista, `SESSION_SECURE_COOKIE=false`.
- Cada despliegue nuevo debe terminar con `php artisan queue:restart` (el
  worker no recarga código solo).

---

## 5. Mantenimiento

- **Worker de colas**: verificar con `sudo supervisorctl status
  esalud-queue-worker:*` que esté `RUNNING`. Si un upload queda
  indefinidamente en `validating`, lo primero a revisar es si el worker
  está caído.
- **Logs**: `storage/logs/laravel.log`. La instrumentación de diagnóstico
  `App\Support\MemoryProbe` (agregada para el diagnóstico de memoria de
  esta fase) genera volumen adicional de log — evaluar desactivarla por
  variable de entorno o retirar sus llamadas si el volumen de log en
  producción se vuelve un problema.
- **Backups**: `mysqldump` de la base de producción +
  `tar` de `storage/app/private/` antes de cualquier cambio estructural.
  Procedimiento exacto en `docs/handoff/DEPLOYMENT.md` §9.
- **Rotación de estructura REM**: activar una nueva versión de estructura
  se hace con los comandos `Rem*Command` (`RemActivateStructureCommand`,
  `RemApproveStructureCommand`, etc.), no editando la base directamente.
- **Catálogo de reglas**: administrar vía la pantalla "Catálogo de
  Reglas" (rol Administrador/Superadmin/Revisor) o los comandos
  `Rule*Command` correspondientes (certificación, exportación,
  comparación contra legado).

---

## 6. Troubleshooting

| Síntoma | Causa probable | Solución |
|---|---|---|
| Upload queda detenido en `validating` indefinidamente | No hay worker de colas corriendo | Verificar Supervisor / iniciar `queue:work` |
| `ValidateRemUploadJob` falla ~25ms después de `RUNNING` y reintenta con `queue:listen` | `ProcessTimedOutException` — `queue:listen` usa un timeout externo de proceso (default 60s) independiente del `$timeout` del Job, y `ValidateRemUploadJob` puede tardar 60s+ | Usar `queue:work` con `--timeout=180` (o más), no `queue:listen` con el default |
| `Allowed memory size ... exhausted` durante una carga | Regresión del fix de caché de `cell_data` (poco probable si no se tocó `CellDataStorageService`/`RemParserService`) | Revisar si algún código nuevo dejó de llamar `forgetSection()`/`forgetSheet()`; no subir `memory_limit` como parche |
| Usuario `Superadmin` no puede acceder a Usuarios/Centros de Salud/Auditoría/Motor de Reglas | Falta el rol `Administrador` en la base (bug corregido en esta fase: `RoleSeeder`/`AdminUserSeeder` no lo creaban/asignaban) | Confirmar que `RoleSeeder` y `AdminUserSeeder` están actualizados y re-ejecutar `php artisan db:seed --class=RoleSeeder --class=AdminUserSeeder` (o asignar el rol manualmente vía tinker) |
| `npm run build` falla con errores de TypeScript | Ver historial en `docs/handoff/DEPLOYMENT.md` §0.1 punto 1 — ya resuelto en esta fase; si reaparece, correr `npx tsc -b` para aislar el archivo/línea exacta | Corregir tipos sin tocar lógica de negocio |
| `npm ci` falla con `EPERM` sobre `lightningcss` | Un `npm run dev` local sigue corriendo y bloquea el archivo nativo | Cerrar el proceso `node` de desarrollo antes de `npm ci`; en un servidor limpio sin `dev` activo no debería ocurrir |
| Carga REM pierde comportamiento fino de jerarquía/overflow certificado | Faltan `cell-data/` (108MB/381 archivos) y `reglas-funcionales.json` en `storage/app/private/certificacion/` — 100% gitignorados, no viajan con `git pull` | Transportar la carpeta según `docs/handoff/DEPLOYMENT.md` §3.2 |
| `tests/Feature/REM` falla en el servidor | `phpunit.xml` requiere una base MySQL real `esalud_testing` (no sqlite en memoria) | Crear esa base con las credenciales que espera `phpunit.xml` (o ajustarlas) |
| 35 tests de `RuleEngine` en rojo en la suite completa | Fallos preexistentes, no relacionados con esta fase (confirmado repetidamente) | Esperado — el criterio de aceptación de esta fase es `tests/Feature/REM` 64/64, no la suite completa |

---

## 7. Referencia API

Todas las rutas viven bajo `/api/v1`, autenticadas con Sanctum
(`auth:sanctum`) salvo `/health` y `/auth/login`. Lista completa siempre
actualizada con `php artisan route:list --path=api/v1`. Grupos principales:

| Prefijo | Controlador(es) | Propósito |
|---|---|---|
| `/auth` | `AuthController` | Login, logout, usuario actual |
| `/users`, `/health-centers`, `/roles`, `/activity-log` | `UserController`, `HealthCenterController`, `RoleController`, `ActivityLogController` | Gestión y auditoría (rol Administrador) |
| `/rem-uploads` (+ `/preview`, `/{id}/status`, `/{id}/validation-results`) | `RemUploadController` | Ciclo de vida de una carga REM |
| `/rem-templates` | `RemTemplateController` | Plantillas REM por año/tipo |
| `/rem-explorer/structures*` | `RemExplorerController` | Inspección de estructuras REM parseadas |
| `/rem-discovery/latest` | closure en `routes/api.php` | Último discovery generado (solo rol `Administrador`) |
| `/rule-engine/rules`, `/logs`, `/structures`, `/bindings`, `/compare` | `RuleController`, `RuleExecutionLogController`, `StructureController`, `BindingController`, `ComparisonController` | Administración y observabilidad del motor de reglas |
| `/rule-engine/config` | `FeatureFlagController` | Feature flags del motor (`enabled`, `mode`, `fail_open`, `log_mode`) |
| `/rule-engine/uploads/{id}/validation-summary`, `/validation-errors` | `ValidationSummaryController`, `ValidationErrorController` | Resultado de validación de una carga |
| `/rule-engine/calibrations/*` | `CalibrationController` | Flujo de calibración celda a celda |
| `/rule-engine/catalog/*` | `CatalogController`, `CalibrationViewController` | Catálogo de reglas, certificación, criterios funcionales |

Definición completa y actualizada: `backend/routes/api.php`.
