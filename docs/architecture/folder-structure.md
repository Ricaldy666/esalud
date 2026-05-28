# Estructura de carpetas

> Fecha: 2026-05-27

## Backend (`backend/`)

```
backend/
├── app/
│   ├── Domain/              → Lógica de negocio por dominio
│   │   ├── Rem/             → Módulo de gestión REM
│   │   ├── HealthGoals/     → Metas sanitarias
│   │   ├── HealthCenter/    → CESFAM / centros de salud
│   │   └── Library/         → Biblioteca documental
│   ├── Http/
│   │   ├── Controllers/     → Controladores API
│   │   ├── Requests/        → Form requests (validación)
│   │   └── Resources/       → API Resources (transformers)
│   ├── Models/              → Eloquent models
│   └── Providers/           → Service providers
├── config/                  → Configuración de Laravel
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── routes/
│   ├── web.php
│   └── api.php
└── tests/
    ├── Feature/
    └── Unit/
```

### Justificación (Domain)

Organizar por dominio (`app/Domain/Rem/`, `app/Domain/HealthGoals/`) en lugar de por capa técnica permite mantener el código cohesivo y facilita la evolución independiente de cada módulo. Cada dominio contiene sus propios DTOs, Actions/Services y Enums.

## Frontend (`frontend/`)

```
frontend/
├── src/
│   ├── app/
│   │   ├── providers/       → QueryProvider, providers globales
│   │   ├── router/          → React Router (createBrowserRouter)
│   │   └── store/           → Estado global (Zustand, futuro)
│   ├── features/
│   │   ├── health/          → Health check (services/, hooks/, types)
│   │   ├── auth/            → Login, registro, perfil (futuro)
│   │   ├── rem/             → Subida y gestión de archivos REM (futuro)
│   │   ├── goals/           → Evaluación de metas sanitarias (futuro)
│   │   └── library/         → Biblioteca documental (futuro)
│   ├── shared/
│   │   ├── components/ui/   → Componentes UI (shadcn/ui en Fase 02)
│   │   ├── hooks/           → Custom hooks compartidos
│   │   ├── services/        → Cliente Axios (api.ts)
│   │   ├── types/           → Tipos TypeScript compartidos (ApiResponse<T>)
│   │   ├── utils/           → Utilidades
│   │   ├── constants/       → Constantes
│   │   └── lib/             → Librerías auxiliares
│   ├── pages/               → Páginas de la aplicación (HealthCheckPage)
│   └── styles/              → Estilos globales (Tailwind)
├── public/
└── index.html
```

### Justificación (Feature-based)

Organizar por feature (`src/features/rem/`) en lugar de por tipo técnico permite que cada funcionalidad sea autónoma. Esto alinea el frontend con la estructura de dominios del backend.

### Reglas arquitectónicas

- `features/` = código específico de una funcionalidad
- `shared/` = código reutilizable entre features
- `app/` = configuración a nivel de aplicación (providers, router, store global)
- Un feature **NUNCA** importa de otro feature; si necesitan algo común, sube a `shared/`
