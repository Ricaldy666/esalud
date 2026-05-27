# Clasificación de datos

> Fecha: 2026-05-27

## Niveles de clasificación

### Nivel 1: Público

- Descripción: Información que puede ser divulgada sin restricciones
- Ejemplos Esalud: Guías de usuario, nombres de centros de salud públicos
- Control: Sin restricciones

### Nivel 2: Interno

- Descripción: Información de uso interno institucional, no sensible
- Ejemplos Esalud: Reportes agregados de REM sin identificar pacientes, manuales técnicos
- Control: Acceso autenticado, no compartir externamente sin autorización

### Nivel 3: Confidencial

- Descripción: Información sensible que requiere control de acceso estricto
- Ejemplos Esalud: Datos de pacientes en archivos REM, evaluaciones de metas sanitarias, credenciales de acceso
- Control: RBAC, cifrado en reposo, auditoría de accesos

### Nivel 4: Restringido

- Descripción: Información crítica con acceso limitado a roles específicos
- Ejemplos Esalud: Claves de API, configuración de infraestructura, contraseñas de BD, información de segurida
- Control: Mínimo privilegio, cifrado, rotación periódica, acceso solo por administradores

## Matriz de clasificación por tabla

| Tabla | Nivel | Justificación |
|---|---|---|
| users | 3 | Datos personales de funcionarios |
| health_centers | 2 | Datos públicos de centros de salud |
| rem_uploads | 3 | Contiene metadatos de archivos con datos de pacientes |
| rem_data | 3 | Datos clínicos agregados de pacientes |
| health_goals | 2 | Metas institucionales públicas |
| health_goals_evaluations | 3 | Evaluación por centro, datos sensibles |
| library_documents | 2-3 | Según contenido del documento |
| activity_log | 3 | Traza de auditoría, no modificable |
