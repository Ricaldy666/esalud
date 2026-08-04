# ADR 0007: Estrategia de autorización con Policies (Fase 03)

- **Fecha**: 2026-05-28
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Fase 03 introduce CRUDs de administración (Usuarios, Centros de Salud, Auditoría). Se necesita un mecanismo de autorización que restrinja el acceso a estos endpoints solo para usuarios con rol Administrador.

## Decisiones

### D1: Laravel Policies

Se usaron Policies de Laravel para encapsular la lógica de autorización por modelo. Cada modelo expuesto via API tiene su propia Policy:

- `UserPolicy` — CRUD de usuarios
- `HealthCenterPolicy` — CRUD de centros de salud
- `ActivityLogPolicy` — consulta de logs de auditoría

Todas las policies verifican `$user->hasRole('Administrador')` usando Spatie Permission.

### D2: Controller base con AuthorizesRequests

Se actualizó `App\Http\Controllers\Controller` para incluir los traits `AuthorizesRequests` y `ValidatesRequests`. Los Domain controllers ahora extienden esta clase base, permitiendo usar `$this->authorize()` en lugar de `Gate::authorize()`.

### D3: AuthServiceProvider

Se creó `App\Providers\AuthServiceProvider` con el mapeo explícito de policies:

```php
protected $policies = [
    User::class => UserPolicy::class,
    HealthCenter::class => HealthCenterPolicy::class,
    Activity::class => ActivityLogPolicy::class,
];
```

Se registró en `bootstrap/app.php` via `->withProviders()`.

### D4: Protección a nivel de frontend

Además de la protección backend, se agregó `RoleProtectedRoute` en React Router para filtrar rutas por rol. Esto evita que un Analista vea enlaces a páginas de administración. La protección backend sigue siendo la barrera de seguridad principal, la del frontend es solo para UX.

## Consecuencias

### Positivas

- Políticas centralizadas y reutilizables
- Fácil agregar nuevos permisos sin modificar controladores
- Doble protección (backend + frontend route guard)
- `$this->authorize()` consistente con el estilo idiomatic de Laravel

### Negativas

- Roles hardcodeados en las policies (si se agregan más roles, hay que actualizar)
- `ActivityLogPolicy` requiere registro explícito porque `Activity` no es un modelo propio
