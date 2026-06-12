# Changelog

## [0.4.4.0-g1] — 2026-06-12

### Added

**Fase 04B-2b-1 — Bloque 1 Serie A (G1: 12 hojas)**

- `RemTemplateSeeder`: 13 configuraciones de hoja (A01 refactorizado + 12 G1 nuevas: A02, A04, A05, A06, A08, A09, A11a, A23, A29, A30, A31, A32)
- Helpers `colLetter()`, `colIndex()`, `baseColumnsAgeSexPairs()`, `baseColumnsAgeSexPairsNum()` para generación programática de columnas etarias con soporte Excel multi-letra
- Upload id=1 reprocesado exitosamente: **191 filas** (vs 22 en fase anterior), **7,918 celdas parseadas, 0 errores**, status `success`
- `expected_sheets` ampliado de 1 a 13: validación de cobertura total

### Changed

- `RemParserService`: límite de filas cambiado de `$row <= 300` a `min(getHighestRow(), max_data_rows)` con default 1500 configurable por hoja (Paso 0)
- `PROJECT_STATE.md`: Fase 04B-2b-1 completada, roadmap actualizado

### Technical Debt / Pendiente

- Solo se procesa Sección A de cada hoja. Secciones B+ serán cubiertas en sub-fase G4 (04B-2b-4)
- Algunas columnas sin label claro en el Excel original se mapearon como `extra_1..6` (A31: CH-CK, A32: CK-CP)

## [0.4.3.2-docs] — 2026-06-12

### Added

**Fase 04B-2b-doc — Documentación oficial especificaciones MINSAL**

- `docs/architecture/especificaciones-minsal.md`: Documentación oficial de los 5
  Requisitos Funcionales del MINSAL (RF-01 a RF-05), modelo conceptual de datos,
  series A y P, motor de cálculo de metas con sintaxis de variables, y reglas de
  validación cruzada.
- `docs/architecture/glosario.md`: Glosario alfabético de términos técnicos del
  dominio salud (APS, DEIS, PBC, NANEAS, IAAPS, EMPAM, FONASA, CIE-10, etc.).
- `docs/adr/0010-alcance-series-rem-y-motor-metas.md`: ADR sobre decisión de
  soportar Series A (mensual) y P (semestral) con parser genérico JSON-config
  y motor de metas separado.

### Changed

- Roadmap del proyecto expandido con nuevas sub-fases: 04B-2c (mapeo Serie P),
  04B-4 (sumatoria/agregación RF-03), y reasignación de fases 05-08 alineadas
  con los 5 RFs del MINSAL.
- `esalud-bitacora/PROJECT_STATE.md`: Actualizado con especificaciones recibidas,
  roadmap expandido y nuevo estado de fase.


## [0.4.3.1-alpha] â€” 2026-06-10

### Fixed
- `backend/config/cors.php`: agregados dominios `http://atenea.cormudesi.cl`
  y `https://atenea.cormudesi.cl` al array `allowed_origins` para permitir el
  funcionamiento del frontend desde el servidor de producciÃ³n interno de
  CORMUDESI. Reportado por Nelson durante despliegue inicial post-handoff.

## [0.4.2-alpha] â€” 2026-05-29

### Added

**Fase 04B-2a â€” Parser piloto asÃ­ncrono (Hoja A01)**

- `RemParserService` + `ParseResult`: analiza archivos REM usando config JSON del template
- `ProcessRemUploadJob`: job async con transiciÃ³n pendingâ†’processingâ†’success/with_errors/failed
- `error_report` celda por celda con validaciÃ³n de tipo entero, rango y nulos
- `RemReprocessCommand`: `php artisan rem:reprocess {uploadId}` para re-procesar uploads
- `RemInspectSheetCommand`: `php artisan rem:inspect-sheet {path} {sheet}` para inspecciÃ³n visual
- Template REM A actualizado con config detallada de hoja A01 (31 columnas, grupos etarios, indicadores)
- Concept carry-forward: filas con concepto vacÃ­o heredan el Ãºltimo concepto conocido
- Endpoint `GET /api/v1/rem-uploads/{id}/status` para polling de estado de procesamiento
- Upload id=1 procesado exitosamente: 22 filas extraÃ­das (11 conceptos Ã— 2 profesionales), status success
- ADR 0009: Arquitectura de procesamiento REM asÃ­ncrono

### Changed

- `RemUploadController@store`: ahora dispara `ProcessRemUploadJob` automÃ¡ticamente
- `RemTemplateSeeder`: usa `updateOrCreate`, config detallada para REM A (hoja A01)
- `PROJECT_STATE.md`: Fase 04B-2a completada

## [0.4.1-alpha] â€” 2026-05-29

### Added

**Fase 04B-1 â€” Discovery infrastructure**

- `phpoffice/phpspreadsheet` v5.7.0 instalado (parser de archivos Excel)
- Filesystem disk `rem-discovery` configurado (storage/app/rem-discovery/)
- `RemDiscoveryService`: analiza estructura de archivos .xlsm/.xlsx (hojas, dimensiones, merged cells, fÃ³rmulas, sample data, detecciÃ³n de headers)
- `RemDiscoverCommand`: comando `php artisan rem:discover {path}` con output dual JSON+MD
- Discovery ejecutado sobre `SA_26_V1.2-2.xlsm` (REM A real): 30 hojas analizadas, reportes generados
- Ruta `GET /api/v1/rem-discovery/latest` (solo admin) para consultar Ãºltimo discovery
- BitÃ¡cora: `fase-04b-1-discovery/` con prompt, resultado, decisiones, pruebas y reporte MD

### Changed

- `PROJECT_STATE.md`: Fase 04B-1 completada, prÃ³xima 04B-2

## [0.4.0-alpha] â€” 2026-05-29

### Added

**Fase 04A â€” Modelos REM y upload bÃ¡sico**

- Filesystem disk `rem-uploads` configurado (storage/app/rem-uploads/)
- Migraciones: rem_templates, rem_uploads, rem_data (3 tablas nuevas)
- RemTemplate model con scopes active(), forYearAndType(), SoftDeletes + LogsActivity
- RemUpload model con uuid automÃ¡tico, 4 relaciones, 4 scopes de filtro
- RemData model con relaciÃ³n a RemUpload
- RemUploadPolicy: Admin ve todo, Analista/Lector ve su centro o sus uploads, create solo Admin/Analista
- RemTemplatePolicy: lectura pÃºblica autenticada, CRUD solo Admin
- RemDataPolicy: delega a RemUploadPolicy
- StoreRemUploadRequest con validaciÃ³n: xlsx/xlsm/xls, max 10MB, aÃ±o 2015-2030
- RemUploadController: index con filtros + paginaciÃ³n + restricciÃ³n por rol, show con remData, store con guardado en disco, destroy (soft delete)
- RemTemplateController: index y show (solo lectura)
- RemUploadResource + RemTemplateResource
- 6 rutas nuevas (22 total): rem-uploads.index, .show, .store, .destroy + rem-templates.index, .show
- RemTemplateSeeder: 5 registros 2026 (A/BM/BS/D/P) con config placeholder
- ADR 0008: Estrategia de almacenamiento de archivos REM
- OpenAPI actualizado con schemas RemUpload, RemTemplate, PaginationMeta
- AuthenticationException manejado como JSON 401 para rutas API sin sesiÃ³n

### Changed

- `database-er.md`: 3 tablas REM marcadas como implementadas
- `PROJECT_STATE.md`: Fase 04A completada

## [0.3.0] â€” 2026-05-28

### Added

**Backend â€” CRUDs de administraciÃ³n**

- UserPolicy, HealthCenterPolicy, ActivityLogPolicy (solo Administrador)
- AuthServiceProvider con registro explÃ­cito de policies
- Controller base con AuthorizesRequests y ValidatesRequests
- StoreUserRequest + UpdateUserRequest con validaciÃ³n de RUT chileno
- UserController: index con filtros (search, role, is_active, health_center_id), paginaciÃ³n, create, update, destroy (protege auto-eliminaciÃ³n)
- HealthCenterController: index con bÃºsqueda y filtros, CRUD completo, bloquea eliminaciÃ³n con usuarios asociados
- UserResource extendido con health_center embed, created_at, updated_at
- HealthCenterResource con users_count
- RoleController: GET /roles (lista de roles Spatie)
- ActivityLogController: GET /activity-log con filtros (subject_type, subject_id, causer_id, event, fechas)
- ActivityLogResource con relaciÃ³n causer y properties
- ActivityLogPolicy para restringir acceso solo a Administradores
- HealthCenter model: getActivitylogOptions para auditorÃ­a

**Frontend â€” Infraestructura**

- @tanstack/react-table instalado
- shadcn/ui: table, dialog, dropdown-menu, select, badge, alert-dialog, skeleton, sonner, toast
- ApiResponse<T> extendido con PaginationMeta, PaginatedResponse<T>, ApiError
- DataTable: componente genÃ©rico con TanStack Table + shadcn, paginaciÃ³n, bÃºsqueda, loading skeleton, empty state
- ConfirmDialog: wrapper de AlertDialog con variante destructive
- PageHeader: tÃ­tulo + descripciÃ³n + acciones
- EmptyState: icono + mensaje + acciÃ³n
- usePermissions: hook con hasRole, isAdmin, isAnalista, isLector
- RoleProtectedRoute: guard por rol en React Router
- AppLayout actualizado: sidebar condicional por rol (Usuarios/Centros/AuditorÃ­a solo Admin), navbar con dropdown del usuario, sonner Toaster global

**Frontend â€” MÃ³dulo de Usuarios**

- Tipos: User, UserFilters, CreateUserData, UpdateUserData
- Servicio: list/get/create/update/remove con CSRF
- Hooks React Query: useUsers, useUser, useCreateUser, useUpdateUser, useDeleteUser
- Schemas Zod con validaciÃ³n de RUT chileno y confirmaciÃ³n de password
- UserForm: react-hook-form + zodResolver, selects de rol y centro de salud
- UsersTable: DataTable con columnas (Nombre, RUT, Email, Rol, Centro, Estado, Ãšltimo login, Acciones)
- UserDialog: Dialog wrapper para crear/editar
- UsersPage: filtros, tabla paginada, CRUD completo con confirmaciÃ³n y sonner toasts

**Frontend â€” MÃ³dulo de Centros de Salud**

- Misma estructura que usuarios: types, service, hooks, schemas, HealthCenterForm, HealthCentersTable, HealthCenterDialog
- Campos: name, code_deis, type (select enum), address, commune, is_active
- HealthCentersPage con filtros y CRUD

**Frontend â€” MÃ³dulo de AuditorÃ­a**

- AuditService + useActivityLog hook
- AuditTable: read-only con columnas (Fecha, Usuario, AcciÃ³n, Entidad), click abre detalle
- AuditDetailDialog: muestra los cambios en formato JSON
- AuditPage con filtros (evento, entidad, rango de fechas)

### Fixed

- Se verificÃ³ que QueryProvider ya estÃ¡ correctamente montado en main.tsx (no era un bug)

## [0.2.0] â€” 2026-05-28

### Added

**Fase 02 â€” AutenticaciÃ³n y entidades base**

- Entidad health_centers con migraciÃ³n, modelo y soft deletes
- MigraciÃ³n add_fields_to_users (rut, health_center_id, is_active, last_login_at, softDeletes)
- User model actualizado con traits: HasApiTokens, HasRoles, LogsActivity, SoftDeletes
- Spatie Permission instalado con roles: Administrador, Analista, Lector
- Spatie Activitylog instalado con logging de cambios en User
- AuthController: login (Sanctum SPA con sesiÃ³n), logout, me
- LoginRequest con validaciÃ³n de email/password
- UserResource con datos de usuario, roles y timestamps
- Rutas /auth/login, /auth/logout, /auth/me
- Seeders: RoleSeeder, HealthCenterSeeder (4 centros), AdminUserSeeder (admin@esalud.cl)
- Session middleware agregado a API routes para Sanctum SPA

**Frontend Fase 02**

- Zustand para estado global de autenticaciÃ³n (authStore)
- shadcn/ui (base-nova) instalado con Tailwind v4: Button, Input, Label, Card
- Auth feature completa: types, service (login/logout/me), hooks (useLogin, useLogout, useAuthInit)
- LoginForm con react-hook-form, validaciÃ³n y errores por campo
- AuthLayout y AppLayout con sidebar, navbar y botÃ³n de logout
- ProtectedRoute que redirige a /login si no hay sesiÃ³n
- LoginPage y DashboardPage
- App.tsx con useAuthInit para mantener sesiÃ³n al recargar
- Vite proxy para Sanctum cookies en desarrollo (/api y /sanctum â†’ :8000)

### Changed

- `shared/services/api.ts` â€” refactor a named exports ({ api, fetchCsrfCookie })
- `shared/types/api.ts` â€” nuevo tipo genÃ©rico ApiResponse<T>
- `vite.config.ts` â€” proxy agregado para Sanctum SPA
- `bootstrap/app.php` â€” session middleware en API routes

## [0.1.0] â€” 2026-05-28

### Added

**Frontend Fase 01 completado**

- Tailwind v4 con plugin `@tailwindcss/vite` (sin postcss/tailwind.config legacy)
- Path alias `@/` configurado en TypeScript y Vite
- Estructura de carpetas `shared/` con reglas arquitectÃ³nicas (features importan de shared, no entre sÃ­)
- Tooling: Prettier, ESLint, Husky, lint-staged
- `.env` con `VITE_API_URL` tipado via `ImportMetaEnv`
- `shared/services/api.ts` â€” instancia Axios con `withCredentials: true` y `fetchCsrfCookie()`
- Feature Health: types, service (`getHealthStatus`), hook (`useHealthCheck`), barrel export
- `pages/HealthCheckPage.tsx` â€” UI de verificaciÃ³n del endpoint `/api/v1/health`
- `app/providers/QueryProvider.tsx` â€” React Query con defaults
- `app/router/index.tsx` â€” React Router con ruta `/`
- `main.tsx` integrado con QueryProvider + RouterProvider

**Backend Fase 01 (sesiÃ³n anterior)**

- Base de datos `esalud_dev` en MySQL 8.4.3 (utf8mb4)
- Sanctum v4.3.2 configurado para SPA (statefulApi, cookies HttpOnly)
- CORS con `supports_credentials: true`
- Endpoint `GET /api/v1/health` con formato `{data, message, errors}`
- Migraciones: users, cache, jobs, sessions, personal_access_tokens

### Changed

- `docs/architecture/folder-structure.md` â€” actualizado con estructura real y reglas arquitectÃ³nicas
- `docs/api/openapi.yaml` â€” esquema unificado `ApiResponse<T>`, HealthData tipado
- `index.html` â€” title actualizado a "Esalud"

## [0.0.1] â€” 2026-05-27

### Added

**Stack real:** Laravel 13 Â· PHP 8.3 Â· React 19.2.6 Â· TypeScript 5.8.3 Â· Vite 8.0.14

- MigraciÃ³n del frontend de JavaScript/JSX a TypeScript/TSX
- ConfiguraciÃ³n TypeScript completa (tsconfig.json, tsconfig.app.json, tsconfig.node.json, vite.config.ts)
- Estructura de documentaciÃ³n profesional (`docs/`)
- 4 ADRs iniciales con decisiones tÃ©cnicas justificadas
- Modelo ER preliminar con 12 entidades documentado
- Documentos de compliance Ley NÂ° 21.663 (5 archivos: mapeo, riesgos, IR, auditorÃ­a, clasificaciÃ³n)
- Repositorio de bitÃ¡cora personal (`esalud-bitacora/`)
- README institucional, CHANGELOG, CONTRIBUTING
- Sistema de continuidad entre sesiones (bootstrap/closing prompts)
- EspecificaciÃ³n OpenAPI 3.0 con endpoint healthcheck
- 4 manuales (usuario, administraciÃ³n, tÃ©cnico, despliegue)
- Ãndice navegable de documentaciÃ³n (`docs/00-index.md`)


