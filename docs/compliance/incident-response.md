# Plan de respuesta a incidentes

> Fecha: 2026-05-27

## Fases

### 1. Preparación

- Roles definidos: Encargado de ciberseguridad, Administrador del sistema, DBA
- Herramientas: Logs de Laravel, Activitylog, monitoreo de Horizon
- Contactos CSIRT: [Por completar con Dorian]

### 2. Detección y reporte

- Detección: logs de aplicación, Activitylog, alertas de Horizon
- Reporte interno: notificar al encargado de ciberseguridad dentro de 24 horas
- Reporte a CSIRT: dentro de 3 días hábiles según Ley 21.663 Art. 17

### 3. Contención

- Deshabilitar cuenta comprometida
- Revocar tokens Sanctum del usuario afectado
- Aislar servidor si es necesario
- Preservar evidencias (logs, copias de BD)

### 4. Erradicación

- Identificar causa raíz
- Aplicar parches o correcciones
- Rotar claves y credenciales afectadas
- Verificar integridad de datos

### 5. Recuperación

- Restaurar desde backup si es necesario
- Validar funcionamiento del sistema
- Monitorear comportamiento anómalo por 72 horas

### 6. Lecciones aprendidas

- Documentar incidente en el repositorio de bitácora
- Actualizar matriz de riesgos
- Actualizar plan de respuesta

## Contactos

| Rol | Nombre | Contacto |
|---|---|---|
| Encargado de ciberseguridad | [Por completar con Dorian] | — |
| Administrador del sistema | [Por completar con Dorian] | — |
| CSIRT nacional | — | [Por completar con Dorian] |

## Notificación legal

Plazo máximo: **3 días hábiles** desde la detección del incidente (Ley 21.663, Art. 17).
