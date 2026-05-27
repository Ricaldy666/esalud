# Stack tecnológico

> Fecha: 2026-05-27

| Capa | Tecnología | Versión | Justificación |
|---|---|---|---|
| Backend Framework | Laravel | 13.x | Madurez, ecosistema, ORM Eloquent, comunidad activa |
| Lenguaje backend | PHP | ^8.3 | Requerido por Laravel 13, tipado fuerte, rendimiento mejorado |
| Base de datos | MySQL | 8.x | Soporte JSON nativo con índices funcionales, infraestructura existente en CORMUDESI |
| Frontend Framework | React | 19.x | Ecosistema maduro, React Query, renderizado eficiente |
| Lenguaje frontend | TypeScript | ~5.8 | Seguridad de tipos, autodocumentación, mantenibilidad |
| Bundler | Vite | 8.x | Rapidez en desarrollo, HMR, integración con Laravel |
| Estilos | Tailwind CSS | 4.x | Utilidades atómicas, consistencia, bundle pequeño |
| Autenticación API | Laravel Sanctum | — | Token-based, simple, first-party de Laravel |
| Auditoría | Spatie Activitylog | — | Estándar en el ecosistema Laravel, flexible |
| Permisos RBAC | Spatie Permission | — | Maduro, integración con Eloquent, caching |
| Cola async | Redis + Laravel Queues | — | Procesamiento de archivos REM fuera de línea |
| Monitor de colas | Laravel Horizon | — | Dashboard, configuración por entorno, métricas |

## Justificaciones breves

**Laravel + React + MySQL**: Laravel 13 ofrece un ecosistema completo con ORM, migraciones, colas y autenticación listos para usar. React 19 con TypeScript proporciona seguridad de tipos en el frontend. MySQL 8 se eligió por su soporte JSON nativo y porque es la base de datos disponible en la infraestructura de CORMUDESI.

**Sanctum sobre Passport/Sanctum**: Sanctum es más simple para SPA + API token, no requiere tablas OAuth y es mantenido por el equipo de Laravel.

**Spatie Permission**: Es la solución más adoptada para RBAC en Laravel, con soporte directo para caching de permisos y relaciones Eloquent.
