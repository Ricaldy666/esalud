# ADR 0005: Decisiones técnicas de Fase 01 (Cimientos)

- **Fecha**: 2026-05-28
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

La Fase 01 establece la base técnica del proyecto: conexión a base de datos, autenticación SPA, estilos, tooling de desarrollo y estructura del frontend. Se tomaron decisiones que definen la arquitectura para el resto del proyecto.

## Decisiones

### D1: Tailwind v4 con @tailwindcss/vite

Se eligió Tailwind v4 sobre Tailwind v3 (legacy) porque elimina la necesidad de `postcss.config.js` y `tailwind.config.js`. La configuración se hace vía `@import "tailwindcss"` en CSS y el plugin `@tailwindcss/vite` en Vite. Esto reduce la configuración inicial y alinea el proyecto con la versión más moderna del framework.

### D2: Sanctum SPA con HttpOnly Cookies

Sanctum se configuró en modo SPA (statefulApi) usando cookies HttpOnly para el token CSRF y de sesión. Esto evita almacenar tokens en localStorage, reduciendo la superficie de ataque XSS. Axios se configura con `withCredentials: true` para enviar cookies automáticamente.

### D3: Estructura shared/ vs carpetas top-level

Se descartó tener `components/`, `hooks/`, `services/`, `types/` y `utils/` como carpetas top-level independientes. En su lugar, todo el código reutilizable se agrupa bajo `shared/`, y el store global va dentro de `app/store/`. La regla arquitectónica es: features importan de shared/, shared no importa de features, y un feature nunca importa de otro feature.

### D4: Path alias @/

Se configuró el alias `@/` → `./src` tanto en `tsconfig.app.json` (para TypeScript) como en `vite.config.ts` (para Vite). Esto evita importaciones relativas profundas (`../../shared/services/api`) y mejora la legibilidad.

### D5: Formato de respuesta API unificado

Todas las respuestas de la API siguen el formato `{ data, message, errors }`. Esto estandariza el manejo de errores y éxito en el frontend, y permite tipar genéricamente con `ApiResponse<T>`.

## Consecuencias

### Positivas

- Configuración mínima para Tailwind v4
- Seguridad mejorada con cookies HttpOnly vs localStorage
- Estructura frontend predecible y escalable
- Importaciones limpias con path alias
- Contrato API claro entre backend y frontend

### Negativas / Riesgos

- Tailwind v4 es reciente; algunos plugins de terceros pueden no ser compatibles aún
- El alias `@/` puede confundir si no se documenta bien al equipo

## Compliance

No impacta directamente en compliance. La estructura ordenada y el tipado fuerte reducen bugs que podrían comprometer datos sanitarios.
