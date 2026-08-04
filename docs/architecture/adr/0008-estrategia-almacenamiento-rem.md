# ADR 0008: Estrategia de almacenamiento de archivos REM (Fase 04A)

- **Fecha**: 2026-05-29
- **Estado**: Aceptado
- **Autor**: Dorian — Systems Engineer, CORMUDESI

## Contexto

Fase 04 introduce el procesamiento de archivos REM del MINSAL. Estos archivos son binarios en formato .xlsm (Excel con macros) de hasta ~5 MB. Es necesario definir cómo y dónde se almacenan físicamente, considerando:

- Retención legal mínima de 5 años (Ley 21.663 de Ciberseguridad + normativa MINSAL)
- Acceso para reprocesamiento si cambia la lógica de parseo
- Migración futura a almacenamiento en red (NAS) o S3
- Integridad de los archivos originales (no modificarlos)

## Decisiones

### D1: Filesystem local con disco dedicado

Se configuró un disco de Laravel llamado `rem-uploads` que apunta a `storage/app/rem-uploads/`. Esto aísla los archivos REM del disco `local` por defecto (que ahora apunta a `storage/app/private/`).

```php
'rem-uploads' => [
    'driver' => 'local',
    'root' => storage_path('app/rem-uploads'),
    'throw' => false,
],
```

### D2: Estructura jerárquica de directorios

Los archivos se organizan como:

```
storage/app/rem-uploads/
  {year}/
    {month padded}/
      {health_center_id}/
        {timestamp}_{original_filename}
```

Ejemplo: `storage/app/rem-uploads/2026/01/1/20260529_120000_SA_26_V1.2-2.xlsm`

Esto permite:
- Navegación intuitiva por período y centro
- Consultas eficientes por año/mes/centro sin depender solo de la BD
- Backup selectivo por año

### D3: Filename con timestamp para evitar colisiones

Cada archivo se almacena con el formato `{YmdHis}_{filename_original}` para evitar colisiones cuando múltiples usuarios suben archivos con el mismo nombre en el mismo período.

### D4: Soft delete + retención indefinida

Los registros `rem_uploads` usan soft deletes. El archivo físico permanece en disco aunque el registro se elimine. No se implementa archivado automático — se definirá una política de ciclo de vida en Fase de despliegue.

### D5: Migración futura a S3/NAS

El sistema usa el Filesystem de Laravel con un disco configurable via `config/filesystems.php`. Para migrar a S3 o NAS en el futuro, solo se cambia el driver en la configuración — el código del controlador (`RemUploadController`) no se modifica porque usa `Storage::disk('rem-uploads')` en lugar del disco por defecto.

## Consecuencias

- **Positivas**: Aislamiento del almacenamiento de archivos, estructura navegable, preparado para S3 futuro, soft delete cumple Ley 21.663
- **Negativas**: Ocupa espacio en disco del servidor, no hay replicación automática (se aborda en despliegue)
- **Riesgo**: Si se llena el disco, las subidas fallan — monitorear en producción

## Referencias

- Ley 21.663 — Marco de Ciberseguridad (retención 5 años)
- MINSAL — Normativa de respaldo de datos REM
- Laravel Filesystem: https://laravel.com/docs/filesystem
