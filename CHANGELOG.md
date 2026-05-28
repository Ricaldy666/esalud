# Changelog

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
