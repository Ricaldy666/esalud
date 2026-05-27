# Matriz de riesgos

> Fecha: 2026-05-27

| # | Riesgo | Descripción | Probabilidad | Impacto | Nivel | Mitigación |
|---|---|---|---|---|---|---|
| R01 | Acceso no autorizado | Usuario sin permisos accede a datos sensibles de pacientes | Media | Alto | Crítico | RBAC (Spatie Permission), Sanctum, auditoría continua |
| R02 | Pérdida de datos | Fallo en BD o almacenamiento sin backup | Baja | Alto | Alto | Backups automatizados, replicación MySQL, storage redundante |
| R03 | SQL Injection | Inyección SQL a través de formularios | Baja | Alto | Alto | Eloquent ORM (parameter binding), validación de inputs |
| R04 | XSS (Cross-Site Scripting) | Script malicioso en inputs de usuario | Media | Medio | Medio | React escapa HTML por defecto, CSP headers |
| R05 | Archivo REM malicioso | REM con contenido diseñado para explotar el parser | Media | Alto | Alto | Validación de estructura, sandboxing del parser, límites de tamaño |
| R06 | Fuga de información | Exposición de datos por error de configuración | Baja | Alto | Alto | .env fuera del repo, CORS restrictivo, headers de seguridad |
| R07 | Ataque de fuerza bruta | Intentos masivos de autenticación | Alta | Bajo | Medio | Rate limiting (10 rpm login), bloqueo tras 5 intentos |
| R08 | Denegación de servicio (DoS) | Saturar endpoints de subida REM o reportes | Media | Medio | Medio | Rate limiting, cola de procesos, monitoreo Horizon |
| R09 | Incumplimiento normativo | No cumplir con Ley 21.663 | Baja | Alto | Alto | Mapeo de cumplimiento, auditorías periódicas |
| R10 | Fuga por terceros | Acceso no autorizado desde integraciones externas | Baja | Alto | Alto | [Por completar con Dorian] — Política de integraciones seguras |
