# Política de auditoría

> Fecha: 2026-05-27

## ¿Qué se registra?

| Evento | Registro | Herramienta |
|---|---|---|
| Inicio de sesión | Usuario, IP, timestamp, éxito/fallo | Laravel + Activitylog |
| Creación/edición de usuarios | Usuario que modifica, cambios realizados | Activitylog |
| Subida de archivo REM | Usuario, centro, archivo, resultado | Activitylog |
| Evaluación de metas | Usuario, meta, centro, valor alcanzado | Activitylog |
| Acceso a documentos confidenciales | Usuario, documento, timestamp | Activitylog |
| Cambios en permisos y roles | Usuario, rol/permiso modificado | Activitylog |
| Errores de aplicación | Stack trace, URL, usuario si autenticado | Laravel log |
| Procesamiento de colas | Job, estado, duración, errores | Horizon |

## Retención

- Logs de actividad: **mínimo 1 año**
- Logs de aplicación: **90 días**
- Registros de auditoría: **mínimo 5 años** para datos sanitarios

## Acceso a logs

- Solo el administrador del sistema puede acceder a los logs brutos
- Los informes de auditoría están disponibles para el encargado de ciberseguridad
- Los cambios en logs deben ser inmutables (append-only) mediante [Por completar con Dorian]

## Integridad

- Los logs de Activitylog son inmutables por diseño (solo escritura)
- [Por completar con Dorian] Implementar rotación y archivado automatizado de logs
