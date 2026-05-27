# ADR 0001: Stack Laravel + React + MySQL

- **Fecha**: 2026-05-27
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Esalud necesita un stack tecnológico para un sistema institucional de gestión estadística APS. Los requisitos incluyen: procesamiento de archivos REM del MINSAL (formato CSV/Excel), evaluación de metas sanitarias, análisis epidemiológico, autenticación segura y cumplimiento de la Ley N° 21.663 de Ciberseguridad.

Las propuestas iniciales consideraban Python (Django/FastAPI) con PostgreSQL, o Node.js (Express/NestJS) con MongoDB.

## Opciones evaluadas

### Opción 1: Laravel + React + MySQL

Pros:
- Laravel ofrece ORM (Eloquent), migraciones, colas, autenticación y autorización listos para usar
- Ecosistema maduro con paquetes como Spatie Permission y Activitylog
- React con TypeScript proporciona un frontend moderno y tipado
- MySQL 8 con soporte JSON nativo se adapta a la estructura variable de los archivos REM
- Equipo con experiencia en PHP/Laravel

Contras:
- PHP tiene menor rendimiento que Go o Rust en procesamiento de datos
- React requiere configuración adicional para SSR/SEO (no necesario aquí)

### Opción 2: Python (FastAPI) + React + PostgreSQL

Pros:
- Procesamiento de datos con Pandas/Numpy para archivos REM
- Tipado fuerte nativo (Pydantic)
- PostgreSQL con JSONB

Contras:
- Mayor consumo de memoria en workers
- Ecosistema de permisos y autenticación menos integrado
- Curva de aprendizaje del equipo

### Opción 3: Node.js (NestJS) + React + MongoDB

Pros:
- TypeScript nativo en backend y frontend
- NestJS proporciona estructura modular
- MongoDB flexible para datos REM

Contras:
- MongoDB no transaccional para datos financieros/sanitarios
- Menor madurez en patrones de permisos y auditoría
- Mayor complejidad operacional

## Decisión

Se eligió **Laravel + React + MySQL**.

## Consecuencias

### Positivas

- Tiempo de desarrollo inicial más rápido gracias a la batería de Laravel
- Equipo productivo desde el día uno
- MySQL 8 con JSON permite manejar la estructura variable de REM sin esquemas rígidos
- Laravel Sanctum simplifica la autenticación SPA + API

### Negativas / Riesgos

- El procesamiento de archivos REM grandes requerirá Jobs asincrónicos para no bloquear el request
- MySQL con JSON no es tan flexible como PostgreSQL con JSONB en consultas complejas

## Compliance

Laravel facilita el cumplimiento de la Ley 21.663 con Activitylog para auditoría y políticas de seguridad configurables (rate limiting, CORS, headers HTTP).
