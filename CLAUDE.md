# CLAUDE.md — Esalud / ATHENEA (contexto operativo vigente)

> **Este archivo fue reorganizado el 2026-08-31 (Fase 17.56)** para volver a caber bajo el límite automático de contexto (antes: 782.240 caracteres). Todo el detalle histórico se preservó íntegro, sin resumir ni editar, en dos archivos nuevos:
> - `docs/handoffs/rem-a-fase3-detalle-17.1-17.54.md` — detalle punto-por-punto de toda la campaña Fase 3 del motor de reglas (checkpoints 2026-08-12 a 2026-08-31, puntos 1–16 y 17.1–17.55).
> - `docs/handoffs/rem-a-mismatch-y-calibracion-2026-08-11-a-2026-08-26.md` — campaña MISMATCH (2026-08-21/26), Fase A/B/C original, y el cierre de la calibración funcional de la Serie A (2026-08-11).
>
> Lee este archivo primero. Consulta los históricos solo cuando necesites evidencia detallada de una fase específica (rule_ids exactos, fórmulas, conteos antes/después, comandos ejecutados, razón de una prohibición).

## Propósito y reglas críticas del proyecto

**Esalud/ATHENEA** es un sistema (backend Laravel en `backend/`, frontend React+Vite en `frontend/`) para el procesamiento y validación de los formularios REM (Registro Estadístico Mensual) de salud pública chilena, Serie A. Incluye: un parser de plantillas Excel (`RemParserService`/`SectionDetectorService`/dominio `RemParser`), un motor de reglas de validación (`rem_rules`/`rem_rule_bindings`, dominio `RuleEngine`), y un módulo de calibración funcional/estructural de cada sección de cada hoja REM contra la estructura real vigente.

**Reglas críticas, vigentes desde antes de esta campaña (no derogadas por nada de lo documentado abajo):**
- **Freeze en `main`**: no commit, no push, no persistencia (`patch`/`approve`/`activate`/`scan-cells`/`ingest`), no deploy, sin autorización explícita del usuario en el turno correspondiente. Esta política aplica a *todo* el proyecto, no solo a la campaña REM A.
- Ninguna acción de escritura (BD, reglas, bindings, calibraciones, estructura, uploads, template) se ejecuta sin autorización explícita, turno a turno — "autorizado en general" no es "autorizado para esta acción específica".
- Ante cualquier discrepancia entre lo documentado aquí y el estado real (BD, Git, filesystem): **STOP y reportar**, nunca asumir ni forzar una reconciliación silenciosa.
- `Nelson` es el contacto de despliegue a producción (no es desarrollador) — cualquier tema de deploy pasa por él, nunca autoejecutar un deploy.

## ESTADO VIGENTE ÚNICO — REM A cerrado, certificado, commiteado y respaldado en remoto (2026-08-31)

**Veredicto: `REM_A_END_TO_END_CERTIFICADO` + `REM_A_PUSH_COMPLETED`.** La campaña Fase 3 del motor de reglas REM Serie A quedó **oficialmente cerrada**: 474 reglas `SAFE_1_TO_1`, 451 de ellas con binding real a la estructura activa (67/v35), una carga de certificación real (`upload_id=187`) confirmó ejecución real de producción — no solo simulación — para los 9 mecanismos del motor (incluyendo los dos que nunca antes se habían ejercido con datos reales: las 55 reglas "trailing-beyond-bounds" y la regla 461 "leading-formula-based"), y el trabajo completo quedó **commiteado localmente y pusheado al remoto**.

- `main` = `origin/main` = commit **`b659a5ec608b6b975873c74f0bde4790ffffd511`** (`b659a5e`).
- ahead/behind respecto a `origin/main`: **0/0**.
- REM A no requiere ninguna acción adicional de cierre — queda como campaña de referencia; cualquier trabajo futuro sobre `A09/I`/`A30/C`/`DUPLICATE`/`130`/`133` (ver "Pendientes conocidos" abajo) es opcional, sin fecha, y nunca bloqueó el cierre.

### Baseline certificado (BD real, reconfirmado en Fase 17.55/17.56, sin cambios desde entonces)

| Métrica | Valor |
|---|---|
| Reglas activas (`rem_rules.status='active'`) | **751** |
| `SAFE_1_TO_1` | **474** |
| `BLOCKED_BY_ENGINE_GAP` | **65** |
| `DUPLICATE` | **14** |
| `ALREADY_STRUCTURE_AGNOSTIC` | **198** |
| `REQUIRES_REMAP` | **0** |
| `rem_rules` (total, cualquier status) | **798** |
| `rem_rule_bindings` (total) | **1655** |
| Bindings activos a estructura 67 | **451** |
| Estructura activa | **id=67, version=35 (67/v35)** |
| `rem_technical_totals` | **276** (incluye 150 de la carga de certificación 187) |
| `rem_data` | **403.247** |
| `uploads` | **146** (incluye `upload_id=187`) |

### Fases cerradas — no repetir sin autorización explícita

- **17.53 — Rebind real a estructura 67**: ejecutado (`rule:rebind-safe-to-structure --structure=67 --commit`), 451 bindings nuevos creados, post-check exacto. Incluye: la regla 461, las 55 reglas "trailing-beyond-bounds", las 25 hijas de expansión B2 (`868–892`), las 9 hijas de expansión B3/CategoríaF (`911–919`), y las 8 reglas de los Grupos 4/5 (`570–577`, gate de identidad). **No re-ejecutar el comando ni modificar/revertir ninguno de estos 451 bindings.**
- **17.54 — Certificación end-to-end**: `upload_id=187` (copia trazable de `upload 186`, `102302A05.xlsm`) procesada por el flujo real completo (parser → cola real → `ValidateWithEngineJob`, recogido por un `queue:work` real, nunca invocado manualmente). Las 9 piezas del motor (normal, Categoría A, Categoría C, `source_rows`, hijas B2/B3-F, trailing-beyond-bounds, regla 461, `ALREADY_STRUCTURE_AGNOSTIC` vía binding `serie`) dieron `passed` con componentes/totales verificados; prueba negativa real (3 fallas genuinas: reglas `178,714,715`) diagnosticada correctamente. Integridad total confirmada: 0 cambios a reglas/config/calibraciones/estructura/template, `upload 186` byte-idéntico. **`upload 187` es un fixture de certificación — no eliminar, no reprocesar, no modificar sin autorización explícita.**
- **17.55 — Cierre técnico REM A / auditoría Git**: 100% read-only. Reconfirmó el baseline exacto, auditó todo el working tree y clasificó cada archivo (ver sección "Estado Git" abajo). Veredicto: `REM_A_READY_FOR_CLEAN_COMMIT`.
- **17.56 — Saneamiento de `CLAUDE.md`**: 100% documental, sin tocar BD/reglas/bindings/calibraciones/estructura/uploads. Separó el historial en los dos archivos referenciados arriba, preservado verbatim y verificado con `sha256sum` (ver esos archivos para el hash exacto de cada uno).
- **17.57 — Commit local limpio de REM A**: staging explícito (nunca `git add .`/`-A`) de los 53 archivos confirmados, revisión completa de `diff --cached` (sin secretos/credenciales/dumps/logs/binarios), commit único creado: **`b659a5ec608b6b975873c74f0bde4790ffffd511`** (`b659a5e`, `feat(rem-a): certify rule engine and structure 67 end-to-end`). Los 4 excluidos (`vite.config.ts`, 2 `Diag*`, `backend/demo/`) confirmados fuera del commit. Sin push en esta fase.
- **17.58 — Auditoría pre-push de los 7 commits locales**: 100% read-only. Identificó y revisó los 7 commits (`62de69c`→`b659a5e`) que se enviarían — todos coherentes con ATHENEA/REM A, sin secretos/credenciales/datos sensibles/binarios, sin experimentos rotos. Veredicto: **`PUSH_READY`**.
- **17.59 — Push controlado**: `git push origin main` (fast-forward normal, sin `--force` ni reescritura de historial) — `01726e5..b659a5e`. Verificado post-push: `main`=`origin/main`=`HEAD`=`b659a5e`, ahead/behind=0/0, los 7 commits presentes en el remoto, BD sin cambios. Veredicto: **`REM_A_PUSH_COMPLETED`**.

## Pendientes conocidos — no bloqueantes, ninguno con fecha, ninguno resuelto en esta campaña

- **Regla `229`** (`A09/I`, columna AR, offset/`total_row=333`) — bloqueada por `AR337`, una referencia espuria e inocua (matemáticamente = 0) del template Excel de origen a una celda vacía fuera de toda sección. 4 opciones de tratamiento documentadas (archivo histórico Fase 3, punto 17.28.4), ninguna elegida. **No tocar `AR337` ni la fila 333 de `A09/I`.**
- **Regla `230`** (`A09/I`, columna AS) — mapeo disperso ambiguo entre sus 6 posibles combinaciones periódicas (1 completa/limpia ya resuelta, 4 parciales, 1 con término mal referenciado). Requiere decisión funcional de Estadística APS, no resoluble con más evidencia técnica. **No decidir automáticamente su destino.**
- **`A30/C pattern_id=1`** — único `MISMATCH` de calibración en toda la Serie A. Columnas J/K/L nuevas (bloque "Modalidad", Nivel Primario) sin decisión histórica — requiere calibración funcional de Estadística APS desde la interfaz ordinaria de ATHENEA, no una decisión de este asistente.
- **56 secciones `NO_UTILIZADA`** (hojas A21, A24, A25, A30AR, A34) — fuera de alcance de cualquier campaña mientras Estadística APS no las reactive vía `rem:set-sheet-usage-status`.
- **14 reglas `DUPLICATE`** (`24,553,557,558,559,617,585,602,560,618,29,580,126,127`) — 8 son deuda de catálogo confirmada (duplicado exacto o subset/superseded, sin funcionalidad real faltante); 6 (`A01/A/C`: `24,553,557,558,559,617`) son genuinamente ambiguas (rangos de fila solapados, mezcla de proveniencia `csv_catalog`/`vetted_catalog`) y requieren revisión humana opcional, no automatizable.
- **Reglas `130`/`133`** — artefactos autorreferenciales rotos (`Suma(D)=Columna D`, 100% `skipped` en su historial), candidatas a `status=inactive`, no desactivadas, no urgente.
- **Flakiness de tests ya documentada** (punto 17.39.5 del archivo histórico) — 9 fallos "flaky/order-dependent" al correr la suite completa en un solo proceso (no aparecen si el archivo se corre aislado), no atribuibles a ningún cambio de código, no investigados, no bloqueantes.
- **Diseño residual de B2/B3/CategoríaF** (`A09/I`, reglas origen `226,227,228,229,230,231,232,233,234`) — `status=active`, `config` intacta, quedan en "expansión parcial permanente" (sus posiciones limpias ya viven como reglas hijas independientes: 25 de B2 + 9 de B3/CategoríaF). Ninguna decisión de diseño (Opción A/B, `config['aggregations']`, etc.) fue tomada para el residuo — no implementar sin autorización.

## Estado Git — REM A commiteado y pusheado (cerrado en 17.57/17.58/17.59)

Rama `main`. `main` = `origin/main` = **`b659a5ec608b6b975873c74f0bde4790ffffd511`** (`b659a5e`). Ahead/behind = **0/0**. Los 53 archivos de la campaña REM A (auditados uno por uno en 17.55, staged explícitamente en 17.57 — nunca `git add .`/`-A`) están en el commit `b659a5e` y ya viven en `origin/main` desde 17.59. Los 7 commits de toda la ventana de trabajo (`62de69c`→`b659a5e`) fueron auditados en 17.58 (`PUSH_READY`, sin secretos/credenciales/datos sensibles) antes de pushearse.

**Working tree local, sin cambios desde entonces — únicamente los 4 excluidos, deliberadamente fuera del cierre REM A, sin stage:**
- `frontend/vite.config.ts` — fix de puerto proxy local (8080→8000), incidente de entorno ajeno a REM.
- `backend/app/Console/Commands/DiagCheckAdminPasswordCommand.php` y `DiagResetAdminPasswordCommand.php` — comandos de diagnóstico temporal del mismo incidente de login local, auto-documentados como "borrar cuando termine el diagnóstico".
- `backend/demo/` (1 archivo, `calibracion-fila-12.php`) — preexistente, no relacionado con esta campaña.

Ninguno de los 4 debe tocarse, comitearse ni borrarse sin autorización explícita — su destino sigue sin decidirse, pero ya no bloquea nada (REM A está cerrado).

## Prohibiciones vigentes (destiladas 2026-08-31 — condensado de todo el historial; ver los archivos de `docs/handoffs/` para la razón/evidencia completa de cada una)

### A. Generales (aplican a toda la campaña, sin excepción)

1. No commit ni push de nada de esta campaña sin autorización explícita.
2. No modificar reglas (`rem_rules`/`config`/`status`), bindings, calibraciones (`response`/`reviewed_by`/`reviewed_at`/`review_status`), `reglas-funcionales.json`, `mismatch-resolution-audit.json`, `rem_data`, `rem_technical_totals`, la estructura activa (67/v35), o cualquier upload (incluido `upload 187`) sin autorización explícita, turno a turno.
3. No re-ejecutar ningún comando de activación/expansión/rebind/reset sobre reglas o bindings ya cerrados (ver listas de IDs abajo).
4. No modificar de nuevo ningún archivo de motor/parser/clasificador ya implementado y validado (lista completa en "Estado Git" arriba) sin autorización explícita — cada uno tiene tests de regresión que dependen de su comportamiento exacto actual.
5. No reprocesar ningún upload histórico (el 186 u otro cualquiera) para forzar captura retroactiva de filas técnicas — requiere autorización de backfill separada, nunca concedida.
6. No investigar/corregir la flakiness de tests documentada sin autorización explícita.
7. No conectar `rem_technical_totals` ni ningún mecanismo del motor a un flujo de UI/exportación/certificación fuera del uso interno del `RuleEngineService` sin autorización explícita.

### B. Ítems ya cerrados — no revertir, no re-ejecutar el comando que los creó

- `upload 187`, los 451 bindings a estructura 67, la regla 461 (`total_row=123`), las reglas 529 (`inactive`) y 530 (viva, sin tocar), las 34 reglas de la Tanda 1 + regla 344 (`inactive`, no limpiar sus 54 registros históricos), las 11 reglas de Fase 2 (`50,51,53,72,73,110,111,187,429,430,431`), las 171+55 reglas de Categoría A y las 29 de Categoría C (Fase 3C-1A/1B/2), las 12 reglas de `source_rows` (`208,214,393-402`), las 25 hijas B2 (`868-892`), las 9 hijas B3/CategoríaF (`911-919`), el gate full-signature de identidad (17.37), y `A09/I pattern_id=23` (filas 332/334/335/336, vía `structural_row_exclusion`/mecanismo #12).

### C. Ítems congelados, sin resolver, sin fecha (ver "Pendientes conocidos" arriba para el detalle)

- `A09/I` fila 333 / `AR337`, regla 229 offset 333, regla 230 (mapeo ambiguo), reglas 228/233 (combinaciones inexistentes en el template), las 9 reglas origen de A09/I (`226-234`, expansión parcial permanente — no escribir campos nuevos ni cambiar su `status`), `A30/C` P1, `A05/V`, `A30/D`, `A25/B` (354, `no_utilizada`), las 56 secciones `no_utilizada`, las 14 reglas `DUPLICATE`, las reglas `130`/`133`.
- Gaps de diseño documentados sin corregir: guard de `rule:remap-section` (colisión post-remap), rangos `{N,0}`/invertidos del clasificador, heurístico de etiqueta `pareceEtiquetaTotalMatrix()` (mecanismo #6), uso de los campos diagnósticos de Fase 1 fuera de los comandos auditados, validación estricta de `discoverTotalRowCandidate()`, y el diseño residual de múltiples agregaciones (`config['aggregations']`/listas de filas) para B2/B3/CategoríaF.

### D. Fuera del working tree del motor (Fase 17.55/17.56)

- `vite.config.ts`, los 2 comandos `Diag*`, y `backend/demo/` — identificados como excluidos del commit REM A, no tocar/borrar/mezclar sin decisión aparte del usuario.

## Auditoría de autenticación y diseño 2FA (2026-08-31, 100% READ-ONLY, nada implementado)

**Veredicto: `2FA_REQUIRES_PREREQUISITE`.**

### ⚠️ Hallazgo crítico (reportado, no corregido)

`backend/app/Console/Commands/ResetAdminCommand.php` (comando `auth:reset-admin`, **tracked en git desde el commit fundacional `1ae35bf`**, sin guard de entorno, sin confirmación): resetea `admin@esalud.cl` a la contraseña literal **`'password'`** + rol **Superadmin**, incondicionalmente, en cualquier entorno donde se invoque. Es una ruta real y existente capaz de modificar el password de un usuario — relevante para el incidente de `admin@esalud.cl` (no se determinó causa, solo se confirma que esta ruta existe y produce exactamente el síntoma descrito). Distinto de `DiagResetAdminPasswordCommand.php` (uno de los 4 excluidos del cierre REM A — ese sí pide password nuevo oculto con confirmación, es la herramienta legítima creada durante el incidente). **No tocado, no desactivado — solo documentado.**

### Mapa de autenticación actual (resumen)

Sesión/cookie vía Sanctum SPA (nunca Bearer tokens). `AuthController::login()` usa `Auth::attempt(['email','password'], true)` (remember-me siempre forzado, el checkbox del frontend es cosmético). CSRF correctamente implementado (verificado en vendor: `EnsureFrontendRequestsAreStateful` wirea `ValidateCsrfToken` para dominios stateful; frontend usa `withXSRFToken`+`/sanctum/csrf-cookie`). Roles vía Spatie Permission (`Superadmin`, `Administrador`, `Lector`, roles de negocio), autorización 100% vía Policies (`UserPolicy`), sin middleware HTTP de rol (`app/Http/Middleware/` no existe todavía). `is_active` existe pero **no se enforcea en el login** (usuario desactivado puede seguir entrando). **Sin flujo de recuperación de password por email** (`password_reset_tokens` es scaffolding sin usar). **Sin rate limiting en ningún punto** (ni `throttle:`, ni `RateLimiter::for()`, sin Fortify/Jetstream). Sin auditoría de eventos de login/logout (solo se auditan cambios de datos del usuario). Stack: Laravel 13.8/PHP 8.3, Sanctum ^4.3, Spatie Permission ^7.4/Activitylog ^4.12 — nada de 2FA instalado.

### Riesgos ordenados por severidad

1. **Crítico** — `auth:reset-admin` (ver arriba).
2. **Alto** — sin rate limiting en `/auth/login` (crítico también para el futuro `/auth/2fa/verify`, dado el espacio pequeño de un TOTP de 6 dígitos).
3. Medio — `is_active=false` no bloquea login.
4. Medio — sin recuperación de password por email (dependencia total de admin o del hallazgo #1).
5. Bajo — sin auditoría de eventos de autenticación.
6. Bajo — password mínimo 8 sin requisitos de complejidad (`Rules\Password` no usado).
7. Informativo — checkbox "recordar sesión" cosmético (backend siempre aplica remember-me).
8. Informativo — verificar manualmente `SESSION_SECURE_COOKIE=true` en producción (no leído el `.env` real).

### Diseño 2FA propuesto (no implementado)

**TOTP por app autenticadora (RFC 6238), sin SMS** (sin gateway SMS en el stack, costo/dependencia/debilidad frente a SIM-swap sin justificación técnica). Enrolamiento: secreto cifrado (`encrypted` cast, `APP_KEY`) en `users.two_factor_secret`, QR (`otpauth://` URI, renderizado en frontend) + entrada manual, confirmación con el primer código antes de activar, 8 códigos de recuperación de un solo uso cifrados en `two_factor_recovery_codes`, `two_factor_confirmed_at` como flag de "2FA activo". Login: password válido + 2FA activo → `Auth::login()` + `session(['auth.2fa_pending'=>true])`, **ninguna ruta protegida alcanzable** hasta `POST /auth/2fa/verify` (nuevo middleware `EnsureTwoFactorVerified`, primer archivo de `app/Http/Middleware/`). Obligatorio para `Superadmin`/`Administrador` (gracia de un login para enrolar, nunca bloqueo duro); opcional para el resto. Pérdida de autenticador: código de recuperación, o un admin fuerza `disable-2fa` (evento auditado vía `LogsActivity`, mismo patrón que `User`). Rate limiting nuevo y obligatorio (`throttle:login`, `throttle:2fa-verify`). Migración única, aditiva (3 columnas nullable en `users`), rollback vía flag de enforcement (no-op) o `migrate:rollback` sin pérdida de datos de negocio. Detalle completo (archivos exactos, plan de pruebas, flujo paso a paso) entregado en el chat de esta sesión — no reincorporado aquí para no volver a inflar este archivo.

### Por qué `2FA_REQUIRES_PREREQUISITE` (histórico — ver cierre abajo)

Implementar 2FA sin resolver el hallazgo #1 daría falsa sensación de seguridad — `auth:reset-admin` bypasea el login por completo desde consola/SSH, dejando el 2FA irrelevante para ese vector. Resolver #1 y añadir rate limiting (#2) debían decidirse antes o junto con el inicio de la implementación.

### Fase Seguridad 1 — hardening previo a 2FA, CERRADA (2026-08-31)

**Veredicto: `2FA_PREREQUISITES_CLOSED`.**

**Qué se cambió y por qué:**
- **`ResetAdminCommand.php` (`auth:reset-admin`) reescrito por completo.** Ya no fuerza la password literal `'password'`; ya no asigna `Superadmin` automáticamente; ahora aborta fuera de `local`/`testing`; exige confirmación explícita + entrada oculta con doble verificación (patrón ya validado en `DiagResetAdminPasswordCommand.php`); si el usuario ya existe, **nunca toca sus roles** (solo la password); si es nuevo, exige `--role=` explícito (sin default) y una confirmación adicional específica si ese rol es `Superadmin`; deja un registro en `activity_log` (`causer`=comando, `subject`=usuario, `action`=`password_reset`/`user_created`) — antes no quedaba ningún rastro.
- **Rate limiting nativo de Laravel agregado** (`AppServiceProvider::configureRateLimiting()`, nunca existió antes): limitador `login` (5/min por `email+IP`, con respuesta 429 propia) aplicado a `POST /auth/login`; limitador `sensitive-user-write` (30/min por usuario autenticado) aplicado solo a `store`/`update` de `/api/v1/users` (vía `Route::apiResource(...)->middlewareFor([...], ...)`, `index`/`show`/`destroy` sin tocar — no había ningún endpoint de 2FA/reset-por-email que proteger porque ninguno existe todavía; el criterio queda documentado en el código para reutilizarse cuando se implementen).
- **Hallazgo adicional evaluado, no corregido**: `database/seeders/AdminUserSeeder.php` también hardcodea `'password'`, pero usa `firstOrCreate` (nunca sobreescribe un admin ya existente) — riesgo mucho menor, patrón común de seeding, fuera del alcance explícito de esta fase (el pedido fue específicamente `auth:reset-admin`). Queda anotado como residual, no bloqueante.

**Tests nuevos** (`backend/tests/Feature/Auth/`, 20/20 passing): `ResetAdminCommandTest.php` (12 — entorno no permitido aborta sin escribir nada; crear usuario nuevo exige `--role`; rol nunca es Superadmin por defecto; Superadmin requiere confirmación extra y puede declinarse; resetear un usuario existente jamás toca sus roles; la password conocida `'password'` ya no queda forzada; confirmación declinada/passwords no coinciden/password corta abortan sin escribir; activity log queda registrado; rol inválido aborta sin crear nada) + `LoginRateLimitTest.php` (8 — login válido sigue funcionando; login inválido da 422 sin autenticar; 6 intentos fallidos disparan 429; el límite sigue bloqueando aunque el intento 6 use la password correcta; el límite es por email — otro usuario en la misma IP no se ve afectado; ciclo completo login→me→logout→me sin regresión; `users` store/update funcionan con normalidad bajo el nuevo throttle; 31 requests a `store` sí disparan 429).

**Hallazgo de metodología de testing (no un bug de la app)**: el primer intento del test de logout falló (`/me` seguía devolviendo 200 tras logout) — diagnosticado directamente (`Auth::guard('web')->check()` confirmó `false` justo después del logout real), la causa fue que el arnés de pruebas in-process de Laravel cachea el guard `sanctum` resuelto (`RequestGuard` cachea el usuario en memoria) entre llamadas simuladas dentro de un mismo test — algo que no puede ocurrir en producción real (cada request HTTP reconstruye el contenedor). Corregido en el test con `$this->app['auth']->forgetGuards()` antes de la verificación final, sin tocar código de la app. `AuthController::logout()` en sí es y era correcto.

**Regresión ejecutada**: suite completa `Feature`+`Unit` (940 tests) — **901 passed, 39 failed, exactamente los mismos 39 fallos preexistentes ya documentados en el historial de campañas anteriores** (4 `StructurePersistenceServiceTest` + 1 `RuleEngineIntegrationTest` + 30 `FunctionalRuleEngineCertificationTest` + 4 `RuleEngineServiceTest`, mismos nombres exactos, sin relación con Auth/rutas/`AppServiceProvider`). **Cero regresiones nuevas.** `php artisan route:list` confirma las 83 rutas de la app cargan sin error tras los cambios.

**Riesgos residuales** (documentados, no bloqueantes): `AdminUserSeeder.php` (ver arriba, riesgo bajo); verificar manualmente `SESSION_SECURE_COOKIE=true` en producción (ya señalado en la auditoría original); `is_active` sigue sin enforcearse en el login (fuera de alcance de esta fase, no era parte de los 3 puntos pedidos); sin flujo de recuperación de password por email (idem).

**Archivos**: modificados `backend/app/Console/Commands/ResetAdminCommand.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`; nuevos `backend/tests/Feature/Auth/ResetAdminCommandTest.php`, `backend/tests/Feature/Auth/LoginRateLimitTest.php`. Ninguno de REM A tocado. Sin commit, sin push, sin `.env`, sin migraciones, sin paquetes 2FA instalados, sin servidor tocado.

### Fase Seguridad 2 — implementación TOTP/2FA, CERRADA (2026-08-31)

**Veredicto: `2FA_IMPLEMENTED_AND_VALIDATED`.** Incremental sobre el diseño ya auditado, sin debilitar password/sesión/CSRF/roles/rate limiting existentes.

**Arquitectura implementada**: TOTP (RFC 6238) vía `pragmarx/google2fa` (backend) + `qrcode.react` (QR renderizado 100% client-side desde la URI `otpauth://`, sin llamar a ningún servicio externo). Sesión-cookie Sanctum sin cambios de fondo: tras password correcta, `Auth::login()` se ejecuta igual que siempre (necesario para CSRF/Sanctum), pero si `two_factor_confirmed_at` no es null la sesión queda marcada "pendiente" (`TwoFactorSession`, claves en la sesión de servidor, TTL 5 min) — **ninguna ruta protegida es alcanzable mientras está pendiente**, salvo `/auth/logout`, `/auth/me` (devuelve `requires_2fa:true`, nunca el usuario) y `/auth/2fa/verify` (la única que la resuelve). Nuevo middleware `EnsureTwoFactorVerified` (alias `2fa.verified`) aplicado a **todo** el grupo de rutas protegidas (`users`, `health-centers`, `roles`, `activity-log`, `rem-uploads`, `rule-engine`, gestión de 2FA propia) — verificado con test explícito de bypass contra 6 endpoints distintos.

**Anti-bypass/replay**: `verifyKeyNewer()` de Google2FA exige timestep TOTP estrictamente creciente (columna `two_factor_last_totp_timestamp`, consumo atómico) — un código válido no puede reutilizarse. Recovery codes: 8 por lote, **hasheados individualmente con bcrypt** (mismo patrón que `password`, nunca reversibles), un solo uso (se eliminan del array al consumirse). Secreto TOTP cifrado en reposo (cast `encrypted`, `APP_KEY`). Acciones sensibles (`enroll`/`disable`/`regenerate-recovery-codes`) exigen `current_password` re-verificada server-side. Rate limiting nuevo `2fa-verify` (5/min por usuario, mismo criterio que `login`) en `/auth/2fa/verify` y `/auth/2fa/confirm`; `enroll`/`disable`/`regenerate` bajo `sensitive-user-write` (30/min) ya existente de Fase Seguridad 1. Nada de esto (secreto, código recibido, recovery code) se imprime nunca en `activity_log` — solo metadatos de la acción.

**Migración** (`2026_08_31_163841_add_two_factor_columns_to_users_table`, ya ejecutada contra `esalud_dev`): 4 columnas nullable en `users` (`two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `two_factor_last_totp_timestamp`) — aditiva, sin downtime, sin default forzado. **Confirmado tras migrar: 0/3 usuarios reales con 2FA, `admin@esalud.cl` explícitamente `two_factor_confirmed_at=null`** — ningún usuario existente quedó enrolado automáticamente ni bloqueado.

**Transición para usuarios existentes**: sin activación automática, sin enforcement duro. `UserResource` expone `must_enroll_two_factor` (evidencia: `hasAnyRole(['Superadmin','Administrador'])` sin 2FA confirmado — mismos 2 roles ya identificados en la auditoría original por su alcance en `UserPolicy`) — el frontend muestra un banner no bloqueante ("se recomienda activar 2FA") solo a esos roles, nunca impide el login ni el uso normal.

**Frontend** (mismo estilo visual existente, sin rediseño): `LoginPage` alterna `LoginForm`/`TwoFactorChallengeForm` según `authStore.twoFactorPending` (persiste correctamente ante recarga de página vía `/auth/me`). `TwoFactorChallengeForm`: código TOTP o recovery code, maneja 419 (expirado → vuelve a password) y 429 (throttled) explícitamente. `TwoFactorSettingsPanel` (nueva página `/security`, link en el menú lateral para cualquier usuario autenticado): flujo completo password → QR+secreto manual → confirmar primer código → revelar 8 recovery codes una única vez (con checkbox de confirmación obligatorio antes de cerrar) → estado activo con botones desactivar/regenerar (cada uno con su propio modal de contraseña).

**Tests nuevos** (`backend/tests/Feature/Auth/`, 25 tests, todos passing): `TwoFactorEnrollmentTest.php` (11 — password requerida, secreto no habilita 2FA hasta confirmar, secreto cifrado en BD verificado byte a byte, código incorrecto no activa, código correcto activa y revela 8 codes, no se puede re-enrolar con 2FA ya activo, desactivación exige password y limpia todo el estado, regeneración invalida los códigos anteriores, nada sensible queda en `activity_log`) + `TwoFactorLoginChallengeTest.php` (14 — sin 2FA sigue entrando igual, password incorrecta rechazada, password sola nunca autentica una cuenta con 2FA, **bypass directo probado contra 6 rutas protegidas distintas**, TOTP correcto/incorrecto, challenge expirado fuerza nuevo login, 6 intentos fallidos disparan 429, recovery code válido autentica, recovery code reutilizado rechazado, logout limpia el estado pendiente, ciclo completo, roles intactos tras completar 2FA, enrolamiento incompleto nunca dispara un challenge). Sumado a los 20 de Fase Seguridad 1: **45/45 en `Feature/Auth`**.

**Hallazgo de metodología de testing (no un bug de la app), 2do de la campaña**: `Illuminate\Auth\Middleware\Authenticate::authenticate()` llama `Auth::shouldUse($guard)` tras autenticar con éxito, dejando ese guard como default para el resto del contenedor in-process — dentro de un mismo test que primero pega a una ruta `auth:sanctum` y luego llama `Auth::attempt()` (vía `/auth/login`) sin resetear, esto rompe con `RequestGuard::attempt does not exist` (nunca ocurre en producción real: cada request HTTP es un proceso nuevo). Corregido en los tests con `forgetGuards()`+`shouldUse('web')` antes de cada login subsiguiente, sin tocar código de la app.

**Regresión**: suite completa `Feature`+`Unit` (965 tests) — **926 passed, 39 failed, exactamente los mismos 39 preexistentes de siempre**, cero regresiones nuevas. `tsc --noEmit` y `npm run lint` limpios en frontend (0 errores, 2 warnings preexistentes en archivos no tocados). `npm run build` exitoso.

**Dependencias nuevas**: `pragmarx/google2fa ^8.0` (backend, TOTP puro RFC 6238, sin llamadas de red) — 0 vulnerabilidades reportadas por `composer audit` sobre este paquete. `qrcode.react ^4.2.0` (frontend, render 100% client-side) — 0 vulnerabilidades de `npm audit` sobre este paquete (las 8 preexistentes del proyecto son ajenas).

**Riesgos residuales** (documentados, ninguno bloqueante): endpoint admin para forzar `disable-2fa` de OTRO usuario (recuperación por pérdida total de dispositivo + códigos) **no implementado** — hoy solo autoservicio; queda como extensión natural futura. `AdminUserSeeder.php` sigue con password hardcodeada (Fase Seguridad 1, riesgo bajo, sin tocar). `SESSION_SECURE_COOKIE` en producción — sigue pendiente de verificación manual durante deployment. `is_active` sigue sin enforcearse en el login — analizado en esta fase: **no constituye un bypass de 2FA** (un usuario inactivo que además tuviera 2FA activo seguiría pasando por el challenge igual que cualquiera; el gap es previo e independiente al 2FA, no une riesgo nuevo) — comportamiento sin cambiar, según lo instruido. Recuperación de password por email: **no implementada**, sigue sin existir, tal como se instruyó no inventarla en esta fase.

**Archivos**: nuevos `TwoFactorController.php`, `TwoFactorAuthenticationService.php`, `TwoFactorSession.php`, `EnsureTwoFactorVerified.php`, 3 `FormRequest`, la migración, 2 archivos de test; modificados `AuthController.php`, `User.php`, `UserResource.php`, `AppServiceProvider.php`, `bootstrap/app.php`, `routes/api.php`, `composer.json/.lock`. Frontend: nuevos `TwoFactorChallengeForm.tsx`, `TwoFactorSettingsPanel.tsx`, `twoFactorService.ts`, `useTwoFactorChallenge.ts`, `SecurityPage.tsx`; modificados `authStore.ts`, `authService.ts`, `types.ts`, `useLogin.ts`, `useAuthInit.ts`, `LoginForm.tsx`, `LoginPage.tsx`, `AppLayout.tsx`, `router/index.tsx`, `package.json/-lock`. **REM A, reglas, bindings, calibraciones, uploads, estructura 67, template, servidor: ninguno tocado.** Sin commit, sin push, sin deploy.

### Fase Seguridad 3 — auditoría final pre-commit, CERRADA (2026-08-31)

**Veredicto: `2FA_READY_FOR_CLEAN_COMMIT`.** 100% read-only (salvo esta documentación); nada de código/BD/reglas/bindings/calibraciones/uploads/estructura tocado.

**Reconfirmación funcional**: 45/45 `Feature/Auth` reconfirmado. Regresión completa corrida 3 veces en esta fase — 2 de 3 dieron **926 passed/39 failed, byte-idéntico al baseline** (mismos nombres exactos); **1 corrida dio 44 failed (+5)** en archivos de REM Parser (`RemParserServiceEmbeddedBackwardSubtotalRowTest`, `RemParserServiceLeadingFormulaBasedTotalTest`, `RemParserServiceTechnicalSectionContextBeyondBoundsTest`, `RemTechnicalTotalsPersistenceTest`) — **investigado, no ignorado**: los 4 archivos corridos en aislamiento dan 37/37 limpio, y la siguiente corrida completa volvió a dar exactamente 39/39 — confirma **flakiness no determinista de orden/aislamiento entre tests** (mismo fenómeno ya documentado en el punto 17.39.5 de este archivo, ahora en un subconjunto distinto de tests, probablemente por los 25 tests nuevos de Auth alterando el orden de ejecución), **no una regresión real** — ningún archivo de REM/RuleEngine fue tocado por Seguridad 1/2/3. `tsc --noEmit`/`npm run lint` limpios, `npm run build` exitoso. BD reconfirmada: `rem_rules=798`, `activas=751`, `rem_rule_bindings=1655`, bindings a 67=`451`, `upload 187` intacto; `0/3` usuarios con 2FA, `admin@esalud.cl` sin secreto/sin 2FA.

**Auditoría Git — inventario exacto (43 archivos)**: **39 `SECURITY_2FA_CONFIRMED`** (14 modificados de Seguridad 1+2 + 5 manifiestos de dependencias + `CLAUDE.md` + 18 nuevos: `TwoFactorController.php`, 3 `FormRequest`, 2 `Services/`, `EnsureTwoFactorVerified.php`, la migración, 4 tests backend, 5 archivos frontend nuevos) — **2 `PREEXISTING_OR_OTHER_WORK`** (`vite.config.ts`, `backend/demo/calibracion-fila-12.php`) — **2 `DIAGNOSTIC_TEMPORARY`** (los 2 `Diag*Command.php`) — **0 `UNCERTAIN`**. Los 4 elementos previamente excluidos del cierre REM A reconfirmados fuera y sin mezclarse. 0 archivos staged.

**Seguridad del diff**: 0 secretos TOTP reales, 0 recovery codes reales, 0 passwords, 0 `.env`, 0 claves privadas, 0 dumps/logs/binarios, 0 QR persistidos, 0 datos sensibles de usuarios — confirmado con múltiples patrones de grep sobre el diff completo (tracked) y los 17 archivos nuevos (untracked) por separado. Secreto TOTP confirmado cifrado en reposo y recovery codes confirmados hasheados individualmente — vía los propios tests ya passing (`test_secret_is_encrypted_at_rest`, verificación de hash en `TwoFactorEnrollmentTest`), no solo por inspección de código. 0 usuarios reales quedaron configurados durante los tests (`RefreshDatabase` contra `esalud_testing`, nunca `esalud_dev`).

**Migración**: `--pretend` de `migrate:rollback --step=1` confirma que revierte exactamente las 4 columnas nuevas (`drop two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at, two_factor_last_totp_timestamp`), nada más — simétrica, aditiva, sin default peligroso, sin activar 2FA a nadie.

**Dependencias**: `pragmarx/google2fa` `v8.0.3` exacto (+ transitiva `paragonie/constant_time_encoding` `v3.1.3`, esperada) en `composer.lock`; `qrcode.react` `4.2.0` exacto en `package-lock.json` (+10 líneas, consistente con 1 paquete sin dependencias). Confirmado que `composer.json`/`package.json` solo agregaron esas 2 líneas cada uno — ninguna dependencia adicional innecesaria.

**Riesgos residuales documentados, ninguno resuelto en esta fase**: `SESSION_SECURE_COOKIE` — **gate obligatorio de deployment**, verificar `true` en producción antes de desplegar. `is_active` no enforced en login — deuda de seguridad independiente y previa a 2FA; **no bloquea este commit** (un usuario inactivo con 2FA seguiría pasando el challenge igual, el gap no interactúa con 2FA) pero **debe evaluarse si se resuelve antes del deploy a producción**, decisión pendiente, no tomada aquí. `AdminUserSeeder.php` — password hardcodeada, riesgo bajo, sin tocar. Recuperación de password por email — sigue sin existir. Admin recovery/force-disable de 2FA de otro usuario — no implementado, autoservicio únicamente.

**Propuesta de mensaje de commit** (no ejecutado): `feat(security): harden auth:reset-admin, add rate limiting, implement TOTP 2FA` — cuerpo resume Seguridad 1 (hardening + rate limiting) y Seguridad 2 (TOTP/2FA completo), cierra con conteo de tests/regresión y confirmación de que ningún usuario real quedó enrolado.

## Próximo paso vigente

**REM A queda oficialmente cerrado** (certificado end-to-end, commiteado y respaldado en `origin/main`). Los siguientes ítems de REM A quedan **abiertos pero no bloqueantes**, sin fecha, ninguno con prioridad automática — solo se retoman si el usuario lo autoriza explícitamente:

1. Decidir el futuro de `AR337`/fila 333 de `A09/I` (4 opciones documentadas, ninguna elegida) — habilitaría crear las combinaciones `total_row=333` restantes de B2/B3.
2. Decidir si se autoriza revisión humana de los 6 casos ambiguos `A01/A/C` y/o desactivación de `130`/`133`.
3. Decidir qué hacer con la regla `230` (requiere insumo de Estadística APS, no solo técnico).
4. Calibración funcional de `A30/C` P1 — corresponde a Estadística APS desde la interfaz ordinaria, no a este asistente.
5. Decidir si se autoriza backfill histórico sobre las cargas existentes (ninguna autorización concedida hasta ahora).
6. Decidir el destino de los 4 archivos excluidos (`vite.config.ts`, 2 `Diag*`, `backend/demo/`) — sin decidir, sin bloquear nada.

### Roadmap de campañas siguientes (orden acordado — ninguna posterior a la actual iniciada, no comenzar por iniciativa propia)

1. **Seguridad de autenticación / 2FA** — auditoría (`2FA_REQUIRES_PREREQUISITE`) → prerrequisitos cerrados (`2FA_PREREQUISITES_CLOSED`) → TOTP implementado y validado (`2FA_IMPLEMENTED_AND_VALIDATED`) → **auditoría final pre-commit cerrada** (`2FA_READY_FOR_CLEAN_COMMIT`, Fase Seguridad 3, ver abajo). Sin commit, sin push, sin deploy todavía — pendiente de autorización explícita.
2. Pruebas locales de 2FA.
3. Commit/push de 2FA.
4. Mejora controlada UX/UI.
5. Pruebas de UX/UI.
6. Commit/push de UX/UI.
7. **Despliegue controlado al servidor ATHENEA** — el servidor **todavía no debe actualizarse** con nada de lo hecho desde el cierre de REM A; no hacer `git pull` ni deploy sin autorización explícita.
8. Smoke tests en servidor.
9. **REM BM** → **REM BS** → **REM D** → **REM P** — en ese orden.
10. Tras completar las series REM: **versionamiento transversal** → **salto de celdas** → **pruebas integrales / cierre**.

**REM A queda cerrado — no reabrirlo salvo defecto comprobado.** Los 4 cambios locales excluidos del cierre REM A (`vite.config.ts`, 2 comandos `Diag*`, `backend/demo/`) deben permanecer separados, sin mezclarse accidentalmente con el trabajo de 2FA ni de ninguna campaña posterior.

No iniciar ninguna fase futura de esta lista sin instrucción explícita. Si al reanudar el estado real (BD/Git) difiere de lo documentado aquí: **STOP y reportar la discrepancia antes de escribir nada.**
