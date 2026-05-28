# Seguridad

> Fecha: 2026-05-28

## Autenticación

- **Sanctum** en modo SPA (statefulApi) con guard `web`
- Sesiones en cookies HttpOnly (SameSite=Strict)
- Cookie CSRF (`XSRF-TOKEN`) para prevenir ataques CSRF
- Sin almacenamiento de tokens en el cliente (ni localStorage, ni sessionStorage)
- Endpoints: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`

### Flujo de autenticación

1. Frontend llama a `GET /sanctum/csrf-cookie` para obtener la cookie CSRF
2. Frontend envía `POST /auth/login` con email y password
3. Laravel verifica credenciales con `Auth::attempt()`, regenera la sesión y actualiza `last_login_at`
4. Peticiones autenticadas usan `auth:sanctum` middleware
5. Al cerrar sesión, se invalida la sesión y se regenera el token CSRF

### Mantenimiento de sesión

El frontend llama a `GET /auth/me` al cargar la aplicación (useAuthInit). Si la sesión es válida, restaura el usuario en el store. Si no, redirige al login.

## Autorización

- **Spatie Permission** para RBAC
- Roles: Administrador, Analista, Lector
- Permisos granular por funcionalidad (ej: `rem.upload`, `goals.evaluate`) — pendiente Fase 03+

## Cifrado

- **Tránsito**: HTTPS obligatorio en producción
- **Reposo**: cifrado AES-256-CBC en Laravel (valores sensibles con `encrypt()`)
- Contraseñas con `bcrypt` (cost: 12)

## Política de contraseñas

- Mínimo 12 caracteres
- Al menos 1 mayúscula, 1 minúscula, 1 número, 1 especial
- Rotación cada 90 días para roles administrativos

## Auditoría

- **Spatie Activitylog** registra toda operación CRUD sobre datos sensibles
- User model loguea cambios en: name, email, is_active, health_center_id
- Registro incluye: usuario, acción, modelo, cambios, IP, timestamp
- Retención mínima de 1 año

## Rate limiting

- Endpoints autenticados: 60 rpm
- Login: 10 rpm por IP
- Procesamiento REM: 5 rpm por usuario

## Headers de seguridad

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'self'
Referrer-Policy: strict-origin-when-cross-origin
```

## MFA

[Por completar con Dorian] — Autenticación multifactor para roles administrativos.
