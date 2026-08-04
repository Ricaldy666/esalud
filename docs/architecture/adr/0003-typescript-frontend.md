# ADR 0003: TypeScript en el frontend

- **Fecha**: 2026-05-27
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

El frontend de Esalud se construye con React 19. Se decidió migrar de JavaScript a TypeScript para mejorar la mantenibilidad y seguridad del código.

## Opciones evaluadas

### Opción 1: TypeScript 5

Pros:
- Tipado estático que detecta errores en compilación
- Autocompletado y documentación en IDE
- Refactorización segura
- Estándar en la industria para proyectos React nuevos

Contras:
- Curva de aprendizaje inicial
- Tiempo adicional de compilación (mínimo con Vite)

### Opción 2: JavaScript (JSX) + JSDoc

Pros:
- Sin necesidad de configuración adicional
- Menor fricción inicial

Contras:
- Sin verificación de tipos en compilación
- JSDoc no cubre todos los casos de uso
- Menor adopción en proyectos institucionales

## Decisión

Se eligió **TypeScript 5** migrando de JavaScript/JSX a TypeScript/TSX.

## Consecuencias

### Positivas

- Detección temprana de errores de tipos
- Documentación viva del código a través de tipos
- Contratos claros entre frontend y backend (tipos de API)
- Alineado con estándares de la industria para proyectos institucionales

### Negativas / Riesgos

- Necesidad de tipar correctamente las respuestas de la API
- Las importaciones de assets (SVG, PNG) requieren declaraciones de módulo

## Compliance

TypeScript no impacta directamente en compliance, pero reduce bugs en producción que podrían comprometer la integridad de datos sanitarios.
