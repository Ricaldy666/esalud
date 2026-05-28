# ADR 0006: Estrategia de autenticación (Fase 02)

- **Fecha**: 2026-05-28
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Esalud necesita autenticación para el frontend SPA. La Fase 01 configuró Sanctum con el middleware `EnsureFrontendRequestsAreStateful`. En esta fase se implementa el flujo completo de login/logout con sesiones, roles de usuario y registro de actividad.

## Decisiones

### D1: Sanctum SPA con cookies HttpOnly (web guard)

Se usó Sanctum en modo SPA con el guard `web` (sesiones en cookies HttpOnly) en lugar de tokens API. Esto evita almacenar tokens en localStorage/ sessionStorage, reduciendo el riesgo de robo de tokens por XSS.

El flujo es:
1. Frontend solicita cookie CSRF: `GET /sanctum/csrf-cookie`
2. Frontend envía credenciales: `POST /auth/login`
3. Laravel verifica credenciales, inicia sesión y devuelve cookie de sesión
4. Peticiones subsecuentes incluyen automáticamente la cookie (HttpOnly, SameSite)

### D2: Sin registro público

No hay endpoint de registro público. Los usuarios son creados por un administrador (CRUD planeado para Fase 03). Esto sigue el principio de mínimo privilegio y evita cuentas no autorizadas en el sistema.

### D3: Roles con Spatie Permission

Tres roles iniciales:
- **Administrador**: Acceso completo al sistema
- **Analista**: Carga y gestión de datos REM, evaluación de metas
- **Lector**: Solo visualización de reportes y dashboard

Los permisos granulares se asignarán en fases posteriores a medida que se implementen las funcionalidades.

### D4: Sesión mantenida al recargar (useAuthInit)

El frontend usa un hook `useAuthInit` que llama a `GET /auth/me` al cargar la aplicación. Si la sesión es válida, restaura el usuario en el store de Zustand. Si no, redirige al login. Esto permite mantener la sesión entre recargas sin depender de localStorage.

## Consecuencias

### Positivas

- Sin tokens en localStorage = menor superficie de ataque XSS
- Sesión manejada completamente por Laravel (segura por defecto)
- Roles permiten escalar permisos sin modificar el flujo de auth
- useAuthInit da experiencia fluida (sin login en cada recarga)

### Negativas / Riesgos

- Sanctum SPA requiere mismo dominio o proxy en desarrollo (Vite proxy configurado)
- Sin registro público, el admin debe crear usuarios manualmente
- Las cookies de sesión son vulnerables a CSRF si no se protegen (Sanctum usa cookie CSRF)

## Compliance

La autenticación basada en sesiones con cookies HttpOnly cumple con los requisitos de la Ley 21.663 de Ciberseguridad al evitar almacenamiento de tokens en el cliente. El registro de actividad con Spatie Activitylog sobre cambios en usuarios proporciona trazabilidad.
