# Seguridad

> Fecha: 2026-05-27

## Autenticación

- **Sanctum** para autenticación SPA y API token
- Tokens con expiración configurable
- Sesiones HTTP-only para la SPA (SameSite=Strict)

## Autorización

- **Spatie Permission** para RBAC
- Roles: Administrador, Gestor, Visualizador
- Permisos granular por funcionalidad (ej: `rem.upload`, `goals.evaluate`)

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
