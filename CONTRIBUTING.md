# Contributing

## Flujo Git

```
main —— develop —— feature/xxx —— hotfix/xxx
```

- `main`: estable, solo merge desde `develop` o `hotfix/`.
- `develop`: integración continua.
- `feature/<nombre>`: ramas de funcionalidad desde `develop`.
- `hotfix/<nombre>`: correcciones urgentes desde `main`.

## Commits

Usar [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: nueva funcionalidad
fix: corrección de bug
docs: cambios en documentación
refactor: cambio sin corrección ni feature
chore: tareas de mantenimiento
test: agregar o modificar tests
```

## Convenciones de nombres

- **Archivos PHP**: `StudlyCase`, ej. `RemUploadController.php`
- **Archivos TypeScript/React**: `PascalCase` para componentes, `camelCase` para hooks/utils
- **Base de datos**: `snake_case` plural, PK `id`, FK `singular_id`
- **Rutas API**: `/api/v1/recursos`, kebab-case

## Decisiones técnicas

Toda decisión técnica relevante debe documentarse como ADR en `docs/adr/`.

## Cierre de fase

Al completar una fase:

1. Actualizar `CHANGELOG.md`
2. Actualizar documentación relevante en `docs/`
3. Si hay decisión técnica, agregar ADR
4. Registrar en la bitácora personal (`esalud-bitacora/`)
