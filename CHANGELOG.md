# Changelog

## [0.4.3.1-alpha] — 2026-06-10

### Fixed
- `backend/config/cors.php`: agregados dominios `http://atenea.cormudesi.cl`
  y `https://atenea.cormudesi.cl` al array `allowed_origins` para permitir el
  funcionamiento del frontend desde el servidor de producción interno de
  CORMUDESI. Reportado por Nelson durante despliegue inicial post-handoff.

## [0.4.2-alpha] — 2026-05-29

### Added

**Fase 04B-2a — Parser piloto asíncrono (Hoja A01)**

- `RemParserService` + `ParseResult`: analiza archivos REM usando config JSON del template
- `ProcessRemUploadJob`: job async con transición pending→processing→success/with_errors/failed
- `error_report` celda por celda con validación de tipo entero, rango y nulos
- `RemReprocessCommand`: `php artisan rem:reprocess {uploadId}` para re-procesar uploads
- `RemInspectSheetCommand`: `php artisan rem:inspect-sheet {path} {sheet}` para inspección visual
- Template REM A actualizado con config detallada de hoja A01 (31 columnas, grupos etarios, indicadores)
- Concept carry-forward: filas con concepto vacío heredan el último concepto conocido
- Endpoint `GET /api/v1/rem-uploads/{id}/status` para polling de estado de procesamiento
- Upload id=1 procesado exitosamente: 22 filas extraídas (11 conceptos × 2 profesionales), status success
- ADR 0009: Arquitectura de procesamiento REM asíncrono

### Changed

- `RemUploadController@store`: ahora dispara `ProcessRemUploadJob` automáticamente
- `RemTemplateSeeder`: usa `updateOrCreate`, config detallada para REM A (hoja A01)
- `PROJECT_STATE.md`: Fase 04B-2a completada

## [0.4.1-alpha] — 2026-05-29

### Added

**Fase 04B-1 — Discovery infrastructure**

- `phpoffice/phpspreadsheet` v5.7.0 instalado (parser de archivos Excel)
- Filesystem disk `rem-discovery` configurado (storage/app/rem-discovery/)
- `RemDiscoveryService`: analiza estructura de archivos .xlsm/.xlsx (hojas, dimensiones, merged cells, fórmulas, sample data, detección de headers)
- `RemDiscoverCommand`: comando `php artisan rem:discover {path}` con output dual JSON+MD
- Discovery ejecutado sobre `SA_26_V1.2-2.xlsm` (REM A real): 30 hojas analizadas, reportes generados
- Ruta `GET /api/v1/rem-discovery/latest` (solo admin) para consultar último discovery
- Bitácora: `fase-04b-1-discovery/` con prompt, resultado, decisiones, pruebas y reporte MD

### Changed

- `PROJECT_STATE.md`: Fase 04B-1 completada, próxima 04B-2

## [0.4.0-alpha] — 2026-05-29

### Added

**Fase 04A — Modelos REM y upload básico**

- Filesystem disk `rem-uploads` configurado (storage/app/rem-uploads/)
- Migraciones: rem_templates, rem_uploads, rem_data (3 tablas nuevas)
- RemTemplate model con scopes active(), forYearAndType(), SoftDeletes + LogsActivity
- RemUpload model con uuid automático, 4 relaciones, 4 scopes de filtro
- RemData model con relación a RemUpload
- RemUploadPolicy: Admin ve todo, Analista/Lector ve su centro o sus uploads, create solo Admin/Analista
- RemTemplatePolicy: lectura pública autenticada, CRUD solo Admin
- RemDataPolicy: delega a RemUploadPolicy
- StoreRemUploadRequest con validación: xlsx/xlsm/xls, max 10MB, año 2015-2030
- RemUploadController: index con filtros + paginación + restricción por rol, show con remData, store con guardado en disco, destroy (soft delete)
- RemTemplateController: index y show (solo lectura)
- RemUploadResource + RemTemplateResource
- 6 rutas nuevas (22 total): rem-uploads.index, .show, .store, .destroy + rem-templates.index, .show
- RemTemplateSeeder: 5 registros 2026 (A/BM/BS/D/P) con config placeholder
- ADR 0008: Estrategia de almacenamiento de archivos REM
- OpenAPI actualizado con schemas RemUpload, RemTemplate, PaginationMeta
- AuthenticationException manejado como JSON 401 para rutas API sin sesión

### Changed

- `database-er.md`: 3 tablas REM marcadas como implementadas
- `PROJECT_STATE.md`: Fase 04A completada

## [0.3.0] — 2026-05-28

### Added

**Backend — CRUDs de administración**

- UserPolicy, HealthCenterPolicy, ActivityLogPolicy (solo Administrador)
- AuthServiceProvider con registro explícito de policies
- Controller base con AuthorizesRequests y ValidatesRequests
- StoreUserRequest + UpdateUserRequest con validación de RUT chileno
- UserController: index con filtros (search, role, is_active, health_center_id), paginación, create, update, destroy (protege auto-eliminación)
- HealthCenterController: index con búsqueda y filtros, CRUD completo, bloquea eliminación con usuarios asociados
- UserResource extendido con health_center embed, created_at, updated_at
- HealthCenterResource con users_count
- RoleController: GET /roles (lista de roles Spatie)
- ActivityLogController: GET /activity-log con filtros (subject_type, subject_id, causer_id, event, fechas)
- ActivityLogResource con relación causer y properties
- ActivityLogPolicy para restringir acceso solo a Administradores
- HealthCenter model: getActivitylogOptions para auditoría

**Frontend — Infraestructura**

- @tanstack/react-table instalado
- shadcn/ui: table, dialog, dropdown-menu, select, badge, alert-dialog, skeleton, sonner, toast
- ApiResponse<T> extendido con PaginationMeta, PaginatedResponse<T>, ApiError
- DataTable: componente genérico con TanStack Table + shadcn, paginación, búsqueda, loading skeleton, empty state
- ConfirmDialog: wrapper de AlertDialog con variante destructive
- PageHeader: título + descripción + acciones
- EmptyState: icono + mensaje + acción
- usePermissions: hook con hasRole, isAdmin, isAnalista, isLector
- RoleProtectedRoute: guard por rol en React Router
- AppLayout actualizado: sidebar condicional por rol (Usuarios/Centros/Auditoría solo Admin), navbar con dropdown del usuario, sonner Toaster global

**Frontend — Módulo de Usuarios**

- Tipos: User, UserFilters, CreateUserData, UpdateUserData
- Servicio: list/get/create/update/remove con CSRF
- Hooks React Query: useUsers, useUser, useCreateUser, useUpdateUser, useDeleteUser
- Schemas Zod con validación de RUT chileno y confirmación de password
- UserForm: react-hook-form + zodResolver, selects de rol y centro de salud
- UsersTable: DataTable con columnas (Nombre, RUT, Email, Rol, Centro, Estado, Último login, Acciones)
- UserDialog: Dialog wrapper para crear/editar
- UsersPage: filtros, tabla paginada, CRUD completo con confirmación y sonner toasts

**Frontend — Módulo de Centros de Salud**

- Misma estructura que usuarios: types, service, hooks, schemas, HealthCenterForm, HealthCentersTable, HealthCenterDialog
- Campos: name, code_deis, type (select enum), address, commune, is_active
- HealthCentersPage con filtros y CRUD

**Frontend — Módulo de Auditoría**

- AuditService + useActivityLog hook
- AuditTable: read-only con columnas (Fecha, Usuario, Acción, Entidad), click abre detalle
- AuditDetailDialog: muestra los cambios en formato JSON
- AuditPage con filtros (evento, entidad, rango de fechas)

### Fixed

- Se verificó que QueryProvider ya está correctamente montado en main.tsx (no era un bug)

## [0.2.0] — 2026-05-28

### Added

**Fase 02 — Autenticación y entidades base**

- Entidad health_centers con migración, modelo y soft deletes
- Migración add_fields_to_users (rut, health_center_id, is_active, last_login_at, softDeletes)
- User model actualizado con traits: HasApiTokens, HasRoles, LogsActivity, SoftDeletes
- Spatie Permission instalado con roles: Administrador, Analista, Lector
- Spatie Activitylog instalado con logging de cambios en User
- AuthController: login (Sanctum SPA con sesión), logout, me
- LoginRequest con validación de email/password
- UserResource con datos de usuario, roles y timestamps
- Rutas /auth/login, /auth/logout, /auth/me
- Seeders: RoleSeeder, HealthCenterSeeder (4 centros), AdminUserSeeder (admin@esalud.cl)
- Session middleware agregado a API routes para Sanctum SPA

**Frontend Fase 02**

- Zustand para estado global de autenticación (authStore)
- shadcn/ui (base-nova) instalado con Tailwind v4: Button, Input, Label, Card
- Auth feature completa: types, service (login/logout/me), hooks (useLogin, useLogout, useAuthInit)
- LoginForm con react-hook-form, validación y errores por campo
- AuthLayout y AppLayout con sidebar, navbar y botón de logout
- ProtectedRoute que redirige a /login si no hay sesión
- LoginPage y DashboardPage
- App.tsx con useAuthInit para mantener sesión al recargar
- Vite proxy para Sanctum cookies en desarrollo (/api y /sanctum → :8000)

### Changed

- `shared/services/api.ts` — refactor a named exports ({ api, fetchCsrfCookie })
- `shared/types/api.ts` — nuevo tipo genérico ApiResponse<T>
- `vite.config.ts` — proxy agregado para Sanctum SPA
- `bootstrap/app.php` — session middleware en API routes

## [0.1.0] — 2026-05-28

### Added

**Frontend Fase 01 completado**

- Tailwind v4 con plugin `@tailwindcss/vite` (sin postcss/tailwind.config legacy)
- Path alias `@/` configurado en TypeScript y Vite
- Estructura de carpetas `shared/` con reglas arquitectónicas (features importan de shared, no entre sí)
- Tooling: Prettier, ESLint, Husky, lint-staged
- `.env` con `VITE_API_URL` tipado via `ImportMetaEnv`
- `shared/services/api.ts` — instancia Axios con `withCredentials: true` y `fetchCsrfCookie()`
- Feature Health: types, service (`getHealthStatus`), hook (`useHealthCheck`), barrel export
- `pages/HealthCheckPage.tsx` — UI de verificación del endpoint `/api/v1/health`
- `app/providers/QueryProvider.tsx` — React Query con defaults
- `app/router/index.tsx` — React Router con ruta `/`
- `main.tsx` integrado con QueryProvider + RouterProvider

**Backend Fase 01 (sesión anterior)**

- Base de datos `esalud_dev` en MySQL 8.4.3 (utf8mb4)
- Sanctum v4.3.2 configurado para SPA (statefulApi, cookies HttpOnly)
- CORS con `supports_credentials: true`
- Endpoint `GET /api/v1/health` con formato `{data, message, errors}`
- Migraciones: users, cache, jobs, sessions, personal_access_tokens

### Changed

- `docs/architecture/folder-structure.md` — actualizado con estructura real y reglas arquitectónicas
- `docs/api/openapi.yaml` — esquema unificado `ApiResponse<T>`, HealthData tipado
- `index.html` — title actualizado a "Esalud"

## [0.0.1] — 2026-05-27

### Added

**Stack real:** Laravel 13 · PHP 8.3 · React 19.2.6 · TypeScript 5.8.3 · Vite 8.0.14

- Migración del frontend de JavaScript/JSX a TypeScript/TSX
- Configuración TypeScript completa (tsconfig.json, tsconfig.app.json, tsconfig.node.json, vite.config.ts)
- Estructura de documentación profesional (`docs/`)
- 4 ADRs iniciales con decisiones técnicas justificadas
- Modelo ER preliminar con 12 entidades documentado
- Documentos de compliance Ley N° 21.663 (5 archivos: mapeo, riesgos, IR, auditoría, clasificación)
- Repositorio de bitácora personal (`esalud-bitacora/`)
- README institucional, CHANGELOG, CONTRIBUTING
- Sistema de continuidad entre sesiones (bootstrap/closing prompts)
- Especificación OpenAPI 3.0 con endpoint healthcheck
- 4 manuales (usuario, administración, técnico, despliegue)
- Índice navegable de documentación (`docs/00-index.md`)
