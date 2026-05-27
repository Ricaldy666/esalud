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
│   ├── features/            → Módulos por funcionalidad
│   │   ├── auth/            → Login, registro, perfil
│   │   ├── rem/             → Subida y gestión de archivos REM
│   │   ├── goals/           → Evaluación de metas sanitarias
│   │   └── library/         → Biblioteca documental
│   ├── components/          → Componentes compartidos (UI)
│   ├── hooks/               → Custom hooks
│   ├── services/            → Clientes API (axios)
│   ├── store/               → Estado global (Context / Zustand)
│   ├── types/               → Tipos TypeScript compartidos
│   └── utils/               → Utilidades
├── public/
└── index.html
```

### Justificación (Feature-based)

Organizar por feature (`src/features/rem/`) en lugar de por tipo técnico (`src/controllers/`, `src/components/`) permite que cada funcionalidad sea autónoma. Esto alinea el frontend con la estructura de dominios del backend.
