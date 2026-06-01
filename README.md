# Esalud

Sistema institucional de gestión estadística de Atención Primaria de Salud (APS) para la Corporación Municipal de Desarrollo Social de Iquique (CORMUDESI), Chile. Procesa archivos REM del MINSAL, evalúa metas sanitarias y presenta análisis epidemiológico, cumpliendo la Ley N° 21.663 de Ciberseguridad y la Ley N° 19.628 de Protección de Datos Personales.

---

## Stack tecnológico

| Capa | Tecnología | Versión |
|------|-----------|---------|
| Backend | Laravel | 13.x |
| Lenguaje backend | PHP | ^8.3 |
| Base de datos | MySQL | 8.x |
| Frontend | React | 19.x |
| Lenguaje frontend | TypeScript | ~5.8 |
| Bundler | Vite | 8.x |
| Estilos | Tailwind CSS | 4.x |
| Componentes | shadcn/ui (base-nova) | v4.7 |
| Autenticación API | Laravel Sanctum (SPA + tokens) | v4.3 |
| Permisos RBAC | Spatie Permission | v7.4 |
| Auditoría | Spatie Activitylog | v4.12 |
| Cola asíncrona | Database (Laravel Queues) | — |
| Estado frontend | Zustand | 5.x |
| Data fetching | TanStack Query | 5.100.x |
| Tablas | TanStack Table | 8.21.x |
| Formularios | react-hook-form + zod | — |
| Notificaciones | sonner | — |

---

## Requisitos previos

- **PHP** ^8.3 con extensiones: `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenxml`, `xml`, `zip`
- **Composer** 2.x
- **Node.js** ^20 LTS + **npm** 10.x
- **MySQL** 8.x
- **Git**
- Extensión **zip** habilitada en PHP (para PhpSpreadsheet)

---

## Instalación paso a paso

### 1. Clonar el repositorio

```bash
git clone <repo-url> esalud
cd esalud
```

### 2. Configurar backend (Laravel)

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
```

Editar `.env` con tus credenciales de base de datos y configuración de dominio:

```env
DB_DATABASE=esalud_dev
DB_USERNAME=root
DB_PASSWORD=

SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

### 3. Configurar frontend (React)

```bash
cd ../frontend
cp .env.example .env
npm install
```

### 4. Crear base de datos y migrar

Desde la consola MySQL o phpMyAdmin:

```sql
CREATE DATABASE esalud_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego:

```bash
cd ../backend
php artisan migrate
```

### 5. Poblar datos iniciales

```bash
php artisan db:seed --class=RemTemplateSeeder
```

> El seeder del template REM también puede ejecutarse junto con otros seeders. Ver `database/seeders/` para más detalles.

### 6. Servir en desarrollo

**Terminal 1 — Backend:**

```bash
php artisan serve
```

**Terminal 2 — Queue worker (para procesamiento REM):**

```bash
php artisan queue:work --queue=default --tries=3 --timeout=300
```

**Terminal 3 — Frontend:**

```bash
cd frontend
npm run dev
```

El frontend estará disponible en `http://localhost:5173` y el API en `http://localhost:8000/api/v1`.

---

## Credenciales iniciales

| Rol | Email | Contraseña |
|-----|-------|-----------|
| Administrador | `admin@esalud.cl` | `password` |

> **⚠️ IMPORTANTE:** Cambiar la contraseña inmediatamente después del primer inicio de sesión en entorno productivo.

---

## Estructura del proyecto

```
esalud/
├── backend/                    → API Laravel (MVC + Domain-Driven Design)
│   ├── app/
│   │   ├── Console/Commands/   → Comandos Artisan personalizados
│   │   ├── Domain/             → Lógica de negocio por dominio
│   │   │   └── REM/            → Módulo de procesamiento REM
│   │   └── Models/             → Modelos Eloquent
│   ├── config/                 → Configuración de Laravel
│   ├── database/
│   │   ├── migrations/         → Migraciones de base de datos
│   │   └── seeders/            → Seeders (incl. RemTemplateSeeder)
│   ├── routes/                 → Definición de rutas API
│   ├── storage/app/            → Almacenamiento local (uploads, discovery)
│   └── .env.example
├── frontend/                   → SPA React + TypeScript + Vite
│   ├── src/
│   │   ├── features/           → Módulos por funcionalidad
│   │   ├── shared/             → Componentes y servicios compartidos
│   │   └── routes/             → Configuración de rutas React Router
│   ├── .env.example
│   └── vite.config.ts
├── docs/                       → Documentación técnica
│   ├── architecture/           → Arquitectura y stack
│   ├── adr/                    → Architecture Decision Records
│   ├── manuals/                → Manuales de despliegue y operación
│   ├── api/                    → Documentación de endpoints
│   └── compliance/             → Documentación de cumplimiento normativo
├── README.md
├── CHANGELOG.md
└── .gitignore
```

---

## Roles del sistema

| Rol | Permisos |
|-----|---------|
| **Administrador** | Acceso completo: usuarios, roles, centros, uploads REM, configuración |
| **Analista** | Gestión de uploads REM, metas sanitarias, reportes, visualización de datos |
| **Lector** | Visualización de dashboards y reportes (solo lectura) |

---

## Comandos útiles

```bash
# Discovery de estructura REM (inspeccionar archivo Excel)
php artisan rem:discover storage/app/rem-uploads/{ruta} --output=both

# Inspeccionar estructura de una hoja específica
php artisan rem:inspect-sheet {archivo} {hoja} --rows=30

# Reprocesar un upload existente
php artisan rem:reprocess {uploadId}

# Ejecutar worker de cola manualmente
php artisan queue:work --once --timeout=120

# Listar rutas API disponibles
php artisan route:list --path=api/v1
```

---

## Cumplimiento normativo

| Normativa | Alcance | Estado |
|-----------|---------|--------|
| Ley N° 21.663 (Ciberseguridad) | Autenticación, cifrado, registros de acceso, HTTPS | Implementado parcial — requiere HTTPS en producción |
| Ley N° 19.628 (Datos personales) | Datos de salud sensibles, acceso por roles, auditoría | Implementado |
| Normas MINSAL (REM) | Estructura y validación de archivos REM oficiales | En desarrollo (Fase 04) |

> **⚠️ HTTPS obligatorio en producción** para cumplir Ley 21.663. Ver [`docs/manuals/deployment-manual.md`](docs/manuals/deployment-manual.md) para configuración.

---

## Documentación

| Sección | Descripción |
|---------|-------------|
| [`docs/architecture/ARCHITECTURE.md`](docs/architecture/ARCHITECTURE.md) | Diagrama de arquitectura, capas, flujos, patrones |
| [`docs/architecture/stack.md`](docs/architecture/stack.md) | Justificación de cada tecnología |
| [`docs/adr/`](docs/adr/) | Architecture Decision Records (9 registros) |
| [`docs/manuals/deployment-manual.md`](docs/manuals/deployment-manual.md) | Manual de despliegue en Windows Server |
| [`docs/api/`](docs/api/) | Documentación de endpoints REST |
| [`docs/compliance/`](docs/compliance/) | Documentación Ley 21.663 y 19.628 |

---

## Versionado y changelog

Este proyecto sigue [Semantic Versioning](https://semver.org/). Ver [`CHANGELOG.md`](CHANGELOG.md) para el historial completo.

**Versión actual:** `v0.4.2-alpha`

| Hito | Versión | Fecha |
|------|---------|-------|
| Fase 04B-2a — Parser asíncrono piloto A01 | `v0.4.2-alpha` | 2026-05-29 |
| Fase 04B-1 — Discovery REM | `v0.4.1-alpha` | 2026-05-29 |
| Fase 04A — Modelos REM y upload | `v0.4.0-alpha` | 2026-05-29 |

---

## Responsable

**Dorian** — Systems Engineer, CORMUDESI, Iquique, Chile.
