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
| `rem_data` | **420.427** (recontado 2026-09-11 en microauditoría A30, ver checkpoint abajo; sube por cargas de prueba locales posteriores al cierre de REM A, no afecta reglas/bindings/estructura/certificación) |
| `uploads` | **152** (incluye `upload_id=187`; recontado 2026-09-11) |

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
- **`A30/C pattern_id=1`** — único `MISMATCH` de calibración en toda la Serie A. Columnas J/K/L nuevas (bloque "Modalidad", Nivel Primario) sin decisión histórica — requiere calibración funcional de Estadística APS desde la interfaz ordinaria de ATHENEA, no una decisión de este asistente. **Confirmado vigente por microauditoría 2026-09-11** (ver checkpoint "MICROAUDITORÍA — 2026-09-11" en "Próximo paso vigente"): el `pattern_fingerprint` canónico v2 almacenado (`fpv2_5c40135e1604294b`, respondido bajo estructura histórica 52) difiere del actual contra 67/v35 (`fpv2_c07217a0385bd413`); `mismatch-resolution-audit.json` lo clasifica `human_review`, no resuelto. **Cobertura funcional 7/7 ≠ cierre técnico del patrón** — el resumen agregado (`rem:calibration_summary`) puede mostrar la sección como "completada" igual, por un gap de diseño ya documentado (mismo checkpoint) en `buildStructureCalibrationSummary()`.
- **75 secciones `NO_UTILIZADA`** (hojas A21, A24, A25, A30AR, A34) — fuera de alcance de cualquier campaña mientras Estadística APS no las reactive vía `rem:set-sheet-usage-status`.
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

- `A09/I` fila 333 / `AR337`, regla 229 offset 333, regla 230 (mapeo ambiguo), reglas 228/233 (combinaciones inexistentes en el template), las 9 reglas origen de A09/I (`226-234`, expansión parcial permanente — no escribir campos nuevos ni cambiar su `status`), `A30/C` P1, `A05/V`, `A30/D`, `A25/B` (354, `no_utilizada`), las 75 secciones `no_utilizada`, las 14 reglas `DUPLICATE`, las reglas `130`/`133`.
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

### ⭐ REANUDAR AQUÍ — PRÓXIMA JORNADA — CIERRE 2026-09-14 (continuación #6), REM BM — BM-11.1 CRITERIO FUNCIONAL BM18/C DETERMINADO (REQUIERE ESTADÍSTICA) + ENGINE_UI_REDUNDANCY PENDIENTE DE AUDITORÍA — leer esto primero, antes que todo lo demás de esta sección

**Veredicto: `BM112_CIERRE_JORNADA_BM_FUNCIONAL_DOCUMENTADO`.** Reemplaza como punto de reanudación inmediato al checkpoint "2026-09-14 (continuación #5), BM-10.1/10.2/10.3..." de abajo (ese sigue vigente para su propio alcance — el fix genérico de preguntas/etiquetas funcionales, ya commiteado y pusheado — pero la campaña avanzó al primer intento real de determinar el criterio de `BM18/C`: se agotó toda la evidencia local disponible, se encontró un hallazgo de UI adicional pendiente de corrección, y la calibración de `BM18/C` sigue sin guardarse — correctamente, porque requiere criterio de Estadística APS). `main` = `origin/main` = **`022077b`** (se actualizará al pushear este checkpoint). **Ningún código, test, ni BD tocados en BM-11.1/11.2 — 100% documental/read-only.**

**PASO 1 — NO volver a auditar BM-1...BM-10.** Ya están cerrados y certificados; releer sus checkpoints abajo solo si se necesita evidencia puntual.

**PASO 2 — Retomar exactamente desde BM-11.3** (no BM-11.1 ni BM-11.2, ya completadas).

**Estado de `BM18/C` — NO calibrada, 0 respuestas guardadas:**

`BM18/C` — PRESCRIPCIONES ADMINISTRADAS EN URGENCIA, fila real **57**, concepto **"URGENCIA SAPU/SAR/SUR"**, captura directa, 1 patrón, **0 reglas técnicas propias** (ni `sum_equals` ni `cross_sheet_equals`). Manual oficial localizado y citado: `recursos-rem/Manual-Series-REM-2026-SERIE-A-BS-BM-DV1.0-2.pdf` (página 626) — define B57 como *"medicamento(s) que se administra(n) al paciente en la atención de SAPU/SAR/SUR o Atención Ambulatoria en Servicio de Urgencia, prescrito(s) previamente por el profesional facultado en el registro de 'Dato Atención de Urgencia'"* y declara explícitamente **"Esta sección no presenta regla de consistencia"** — coincide exactamente con las 0 reglas técnicas ya certificadas, no es un gap.

**Las 4 decisiones funcionales de `BM18/C`, auditadas en BM-11.1 — ninguna guardada, todas `REQUIRES_STATISTICS`:**

1. **Sin datos** (Registrar 0 / Permitir vacío) — evidencia técnica fuerte hacia **"Permitir vacío"**: el template oficial MINSAL (`recursos-rem/SBM_26_V1.1-2.xlsm`) tiene en B57 una validación de datos nativa de Excel `type=whole, ≥0, allowBlank=true`, contrastada contra una celda de fórmula vecina (`D42`, `allowBlank=false`) y confirmada consistente con otra celda de captura directa en otra sección (`BM18/D`, mismo perfil `allowBlank=true`) — la misma validación persiste íntegra en ambos XLSM reales cargados (`102302BM05`: B57=`null`; `102412BM05`: B57=`73`). **Aun con esta evidencia fuerte, la decisión formal sigue sin tomarse** — no guardada.
2. **Severidad** (Error / Advertencia) — hallazgo de código verificado (`ValidateRemUploadJob.php` líneas 220-241): `severity` solo se lee cuando `empty_behavior==='debe_registrar_cero'`; en la rama `puede_quedar_vacio` nunca se consulta. **Si la Decisión 1 se confirma como "Permitir vacío", esta pregunta queda técnicamente inerte** para esta fila — documentado, no decidido.
3. **Aplicación** (Todos los establecimientos / Elegir excepciones) — sin evidencia normativa suficiente; el manual describe el dato, no el universo obligatorio de establecimientos; 2 archivos reales no bastan para generalizar.
4. **Excepciones** (No existen / Existen excepciones) — sin evidencia estructural real de excepciones dentro de la propia sección; no asumido "No existen" por ausencia de evidencia.

**⚠️ Hallazgo nuevo pendiente — `ENGINE_UI_REDUNDANCY` entre Aplicación y Excepciones, NO corregido, auditar antes de guardar `BM18/C`:**

`QuickCalibrationPanel.tsx` maneja `all_est` ("Aplicación") y `exceptions` ("Excepciones") como **dos respuestas completamente independientes**, sin sincronización. El panel de detalle por establecimiento (`centerModes`, donde realmente se definiría qué establecimientos son la excepción) solo se muestra cuando `exceptions==='si'` — **nunca** cuando `all_est==='depende'` ("Elegir excepciones"). Esto permite guardar combinaciones contradictorias o incompletas: `all_est='depende'` sin `exceptions='si'` (se "elige excepciones" pero nunca se puede especificar cuáles), o `all_est='si'` con `exceptions='si'` (contradicción directa). **No corregido — decisión de diseño y alcance del fix pendiente para mañana, PASO 3/4 abajo, antes de guardar cualquier respuesta de `BM18/C`.**

**Preguntas exactas para Estadística APS (lenguaje no técnico, listas para trasladar):**

1. *"En la sección C del REM BM, para 'URGENCIA SAPU/SAR/SUR', cuando un establecimiento no administró medicamentos de urgencia durante el mes, ¿debe registrar 0 o puede dejar la celda vacía?"*
2. *(Solo si la respuesta anterior es "debe registrar 0")* — *"Si dejan la celda vacía en vez de registrar 0, ¿eso debe impedir continuar con la carga del REM o solo mostrar una advertencia?"*
3. *"¿Esta fila debe ser informada por todos los establecimientos, incluso los que no cuentan con SAPU/SAR/SUR, o existen establecimientos a los que esta fila no les aplica?"*
4. *(Pendiente de redactar en el mismo tono, dependiente de la respuesta a la #3)* — sobre excepciones específicas por establecimiento, sin inventar respuesta hoy.

**No confundir**: las **90 reglas técnicas certificadas end-to-end** (BM-6→BM-9) **≠** calibración funcional BM. Calibración funcional BM sigue **0/2 hojas, 0/6 secciones, 6 pendientes, 0 respuestas** — sin cambios desde el cierre BM-9.

**PASO 3 (mañana, antes de guardar nada de `BM18/C`)**: auditar a fondo `ENGINE_UI_REDUNDANCY` (Aplicación/Excepciones/`all_est`/`exceptions`/`centerModes`) — determinar si requiere fix genérico de UI/motor (mismo estándar de BM-9.2/BM-10.2: genérico, sin hardcodes, sin romper Serie A).
**PASO 4**: decidir e implementar ese fix si corresponde, con la misma metodología de reproducir→corregir→testear→regresión→verificación visual→commit/push ya usada en toda esta campaña.
**PASO 5**: obtener del usuario (Dorian) el criterio real de Estadística APS para las 4 decisiones de `BM18/C` (o las que falten tras el PASO 3/4) y registrarlo aquí.
**PASO 6**: solo después, realizar la primera calibración funcional real de `BM18/C` (guardar respuestas), con autorización explícita turno a turno.
**PASO 7**: certificar `BM18/C`.
**PASO 8**: continuar sección por sección, en el orden ya definido (menor a mayor riesgo): `BM18/B` → `BM18/D` → `BM18/A` → `BM18A/A` (incluye la observación de fila 89, pendiente) → `BM18A/B` (incluye la excepción real de fila 178, pendiente de decisión funcional explícita). **No avanzar a la siguiente sección hasta cerrar formalmente la anterior.**

**Recordatorios que se mantienen vigentes:**
- Serie A: **67/v35**, cerrada/certificada, sin cambios.
- BM: **90 reglas técnicas certificadas end-to-end** (BM-6→BM-9), commiteadas y pusheadas.
- BM funcional: **NO calibrada** — `BM18/C` es la primera sección en proceso, sin cerrar.
- `BM18A/A` fila 89 (TOTAL nivel 2 sin regla propia) y `BM18A/B` fila 178 (fila estructuralmente anómala) — ambas observaciones documentadas en BM-9.1/BM-10.1, siguen pendientes, no resolver sin evidencia/autorización.
- Producción: **no sincronizada**, sin deploy — push ≠ deploy.
- Runbook worker: tras cambios PHP que afecten jobs, reiniciar el worker local antes de certificar cualquier flujo E2E real desde la UI.
- Runbook de seguridad BD (BM-10.2): **prohibido usar tinker (u otro comando) para `delete`/`update`/`restore`/`create`/`save` contra la BD real de desarrollo** para reproducir o simular escenarios de test — usar exclusivamente tests/fixtures con BD aislada; confirmar explícitamente el entorno/conexión antes de cualquier comando de investigación con riesgo de escritura.

---

### CIERRE DE JORNADA — 2026-09-14 (continuación #5), REM BM — BM-10.1/10.2/10.3 PRE-CALIBRACIÓN FUNCIONAL BM18/C + FIX GENÉRICO DE PREGUNTAS/ETIQUETAS FUNCIONALES + INCIDENTE LOCAL Y RESTAURACIÓN — leer esto primero, antes que todo lo demás de esta sección

**Veredicto: `BM104_DOCUMENTACION_PRECALIBRACION_FUNCIONAL_CERTIFICADA`.** Reemplaza como punto de reanudación inmediato al checkpoint "2026-09-14 (continuación #4), BM-9.1/9.2/9.3..." de abajo (ese sigue vigente para su propio alcance — el aislamiento cross-sheet/cross-section de `getFunctionalRulesByRow()` — pero la campaña avanzó a la preparación de la primera calibración funcional real: auditoría de `BM18/C`, dos engine gaps genéricos encontrados y corregidos en el motor de calibración compartido con Serie A, y un incidente local de escritura accidental en BD, detectado y revertido en el mismo turno). `main` = `origin/main` = **`1fe829e`**, ahead/behind **0/0**.

**1) BM-10.1 — auditoría read-only de `BM18/C`:**

Sección **C — PRESCRIPCIONES ADMINISTRADAS EN URGENCIA** (`filaHeader=55`, `filaInicioDatos=56` declarado — pero la única fila real de datos es la **57**, ya que 55-56 son el merge del encabezado `A55:A56`/`B55:B56`). Fila 57: concepto **"URGENCIA SAPU/SAR/SUR"**, columna B = captura directa (editable, sin fórmula). **1 patrón** (`pattern_id=1`, `row_fingerprint=rowset_c837649cce43f272`, `canonical_fingerprint=fpv2_dbb0db80d4039cf8`), **0 reglas técnicas propias** de las 90 certificadas (ni `sum_equals` ni `cross_sheet_equals` — `BM18/C` nunca es origen de ninguna de las 37 relaciones cross-sheet). Comparación celda por celda contra ambos XLSM reales (`102302BM05.xlsm`/`102412BM05.xlsm`): **0 diferencias estructurales** (misma etiqueta, mismo merge, mismo estado de protección; única diferencia es de dato real en B57 — `null` en un establecimiento, `73` en el otro). **`BM18/C` todavía NO fue calibrada** — esta auditoría fue exclusivamente preparatoria, sin guardar ninguna respuesta.

**2) Dos engine gaps genéricos encontrados durante la auditoría — problemas del motor/UI compartido, no datos incorrectos del Excel:**

- **Gap A** (frontend, `FunctionalQuestionsPanel.tsx::buildDynamicGeneralQuestions()`): cuando una sección no tenía un `column_group` real tipo `main_rule`, el código caía en un fallback hardcodeado (`total='C'`, `components=['D','E']`, `totalLabel='Ambos sexos'`) que generaba una pregunta describiendo una relación matemática inexistente — para `BM18/C` (que solo tiene columnas A/B) literalmente: *"¿El Ambos sexos (C) debe ser igual a D (D) más E (E)?"*.
- **Gap B** (backend, `SectionCalibrationMatrixService.php`): el array estático `REGLA_FUNCIONAL_LABELS` (pensado exclusivamente para los 4 patrones reales de `A01/A`, `PATRONES_A01_A`) se consultaba por `pattern_id` numérico puro, sin verificar `sheet`/`section` — cualquier sección dinámica cuyo primer/único patrón recibiera localmente el ID 1-4 heredaba una etiqueta ajena. Para `BM18/C` (`mode=direct_input`, sin fórmula): mostraba **"Suma de rango etario estándar = TOTAL"**.

**3) Alcance real descubierto — NO exclusivo de `BM18/C`:** auditadas **387 secciones** (Serie A 67/v35 + BM 72/v1 completas). **606 patrones** con etiqueta heredada potencialmente ajena a su propia evidencia estructural (afectaba secciones reales de Serie A: `A33`, `A34` completa, además de `BM18/A,C,D` + `BM18A/A,B`). **74 secciones** con grupo `complementary` potencialmente mal etiquetado (columnas marcadas "complementarias" sin existir ninguna relación principal — `main_rule`/`age_range` — a la que complementar).

**4) Fix BM-10.2 — genérico, sin hardcodes, identidad preservada:**

`main_rule` (frontend) solo genera su pregunta general cuando existe un `column_group` real de ese tipo, usando exclusivamente sus propias columnas/labels — sin fallback `C=D+E`/"Ambos sexos", sin columnas inventadas. `complementary` (backend, `buildColumnGroups()`) solo se crea cuando existe una relación real (`main_rule` o `age_range`) a la que complementar en la misma sección — de lo contrario, una columna "sobrante" es simplemente el contenido principal de la sección, no algo complementario. `regla_funcional_label` (backend, nuevo método `describeFunctionalRuleLabel()`) se deriva de la evidencia real del propio patrón (`mode`/`formula_template`/`columna_total`) para toda sección que no sea `A01/A` — `pattern_id` deja de definir semántica universalmente. `direct_input` recibe descripción neutra/estructural real (`"Entrada directa en {total}"` o `"Patrón de captura directa"`), nunca una descripción de suma. **Comportamiento legacy de `A01/A` preservado exactamente** (sus 4 etiquetas reales reconfirmadas intactas). **No se modificó**: `pattern_id`, fingerprints, estructura, cell-data, reglas técnicas, bindings, ni ninguna respuesta funcional ya guardada (Serie A incluida).

**5) Resultado final `BM18/C` tras el fix:** tipo **captura directa**, sin consolidación vertical, resumen correcto ("sin reglas matemáticas horizontales, datos ingresados directamente por funcionario"), patrón etiquetado **"Patrón de captura directa"**. Eliminados correctamente: `C=D+E` falso, "Ambos sexos" inventado, rango etario falso, `complementary` engañoso. **Preguntas funcionales reales que quedan pendientes de responder** (BM-11): (1) Sin datos — Registrar 0 / Permitir vacío; (2) Severidad — Error / Advertencia; (3) Aplicación — Todos los establecimientos / Elegir excepciones; (4) Excepciones — No existen / Existen excepciones. **Ninguna de las 4 respondida todavía.**

**6) Verificación visual manual, confirmada por el usuario en ATHENEA local:** Calibración → REM BM → BM18 → Sección C — confirmado visualmente: captura directa, sin consolidación vertical, resumen correcto, sin fórmula falsa, fila 57 limpia (sin decisión propia, sin decisión heredada, sin regla aplicada, sin origen histórico). **No se guardó ninguna respuesta.**

**7) Incidente local durante BM-10.2 — detectado y revertido en el mismo turno:** durante una sesión de debugging (investigando por qué la rama de "sin estructura activa" de un test no se activaba), se ejecutó accidentalmente vía **tinker** — que se conecta a la BD real de desarrollo (`esalud_dev`), no a la BD aislada de test — el comando `RemTemplateStructure::query()->delete()`, soft-eliminando las 36 filas de `rem_template_structures` (incluidas Serie A id=67 y BM id=72, ambas activas). **Detectado de inmediato**; el modelo usa `SoftDeletes`, por lo que se restauraron las 36 filas con `onlyTrashed()->restore()`. Safety gate posterior confirmó: **36 activas, 0 soft-deleted restantes**, Serie A `67/v35 activa, 306/306, 100%`, BM `72/v1 activa, 90/90 rules/bindings`, global `888/841/1745`, upload #197 `181/20`, calibración BM `0/6, 0 respuestas` — **sin pérdida de datos detectada** en ningún campo verificado.

**8) Runbook de seguridad nuevo — vigente desde ahora para toda fase read-only futura:** **prohibido** usar `tinker` (o comando equivalente) para ejecutar `delete`/`update`/`restore`/`create`/`save` sobre modelos de la BD real de desarrollo con el propósito de reproducir o simular un escenario de test — los escenarios destructivos se ejecutan exclusivamente contra tests/fixtures con BD aislada (`RefreshDatabase`, `Storage::fake()`, etc., nunca invocados manualmente vía `tinker` contra la conexión por defecto). Antes de cualquier comando de investigación que pueda escribir, **confirmar explícitamente el entorno/conexión de BD** antes de ejecutarlo.

**9) Tests — BM-10.2/10.3:** `FunctionalRuleServiceRowIsolationTest` + `SectionCalibrationMatrixServiceFunctionalRuleLabelTest`: **14/15** (1 skip esperado — caso `A01/A` real solo verificable con estructura activa, ya reconfirmado en vivo por separado). `Feature/Calibration`+`Feature/RuleEngine`: 483 tests, 447 passed, **35 fallos = baseline histórico exacto por nombre**, 0 nuevos. `Unit/RuleEngine`: **162/162**. Frontend: `tsc -b` limpio, `eslint` 0 errores (1 warning preexistente y ajeno en `RemUploadForm.tsx`, no tocado), `npm run build` exitoso.

**10) Git — commit BM-10.3, ya pusheado:** Commit **`1fe829e`** (`fix(rem): derive functional calibration prompts from structure`) — exactamente **3 archivos**: `SectionCalibrationMatrixService.php` (modificado), `FunctionalQuestionsPanel.tsx` (modificado), `SectionCalibrationMatrixServiceFunctionalRuleLabelTest.php` (nuevo). `main` = `origin/main` = `1fe829e`, push fast-forward normal (`4249cd4..1fe829e`), ahead/behind 0/0. Confirmados fuera del commit, sin tocar: `vite.config.ts`, 2× `Diag*Command.php`, `backend/demo/`.

**11) Siguiente fase — BM-11, no iniciada, requiere autorización explícita:**

**BM-11 — Calibración funcional real de `BM18/C`.** Resolver exclusivamente los 4 criterios reales pendientes (punto 5): sin-datos, severidad, aplicación, excepciones. **No asumir respuestas por estructura cuando el criterio requiere decisión de Estadística APS** — la evidencia disponible (2 establecimientos reales) es insuficiente para varias de estas decisiones (ver auditoría BM-10.1 original). **No avanzar a `BM18/B`** (ni a ninguna otra de las 6 secciones) **hasta cerrar formalmente `BM18/C`** — orden de calibración ya definido: `BM18/C` → `BM18/B` → `BM18/D` → `BM18/A` → `BM18A/A` → `BM18A/B` (de menor a mayor riesgo).

**12) Producción:** no asumida sincronizada. Sin deploy. Push ≠ deploy — ningún commit de BM-9/BM-10 desplegado.

---

### CIERRE DE JORNADA — 2026-09-14 (continuación #4), REM BM — BM-9.1/9.2/9.3 AUDITORÍA FUNCIONAL + FIX DE AISLAMIENTO CROSS-SHEET/CROSS-SECTION CERTIFICADO Y COMMITEADO — leer esto primero, antes que todo lo demás de esta sección

**Veredicto: `BM94_DOCUMENTACION_AISLAMIENTO_FUNCIONAL_CERTIFICADA`.** Reemplaza como punto de reanudación inmediato al checkpoint "2026-09-14 (continuación #3), BM-8 CROSS-SHEET..." de abajo (ese sigue vigente para su propio alcance histórico — las 90 reglas técnicas — pero la campaña avanzó a la siguiente capa: auditoría read-only de calibración funcional BM, un engine gap real encontrado y corregido, y el fix commiteado y pusheado). `main` = `origin/main` = **`a90ff52`**, ahead/behind **0/0**.

**1) BM-9.1 — auditoría funcional read-only de las 6 secciones BM:**

Las 6 secciones de la estructura activa 72/v1: `BM18/A,B,C,D` (filas 13-38, 42-53, 56-57, 60-62) + `BM18A/A,B` (filas 11-119, 122-205). Estado previo (y posterior, sin cambios): **0/2 hojas, 0/6 secciones, 6 pendientes, 0 respuestas funcionales BM** — confirmado con `SectionCalibrationMatrixService::buildStructureCalibrationSummary('BM')` y con 0 claves `BM18*` en `_questions`/row-keys de `reglas-funcionales.json`. Patrones detectados vía `buildPatternMatrix()` (real, en vivo): `BM18/A` 2 patrones (12 filas) · `BM18/B` 0 patrones específicos (solo TOTAL fila 42, sin evidencia de fórmula por fila) · `BM18/C` 1 patrón (1 fila) · `BM18/D` 1 patrón (3 filas) · `BM18A/A` 2 patrones (85 filas) · `BM18A/B` 4 patrones (59 filas) — **160 filas con patrón en total**. Comparación celda por celda (fórmulas + etiquetas literales) entre `102302BM05.xlsm` y `102412BM05.xlsm` en las 6 secciones: **0 diferencias estructurales**.

**2) Engine gap real encontrado y confirmado — contaminación cross-sheet/cross-section:**

`FunctionalRuleService::getFunctionalRulesByRow($sheet, $section)` indexaba el resultado con la clave `"{$sheet}_{$section}_{$rowKey}"` usando el sheet/section **solicitados**, pero tomaba el **valor** de cualquier registro histórico de `reglas-funcionales.json` cuyo `row` coincidiera numéricamente, sin verificar que ese registro perteneciera realmente a ese sheet/section — el método hermano `getFunctionalRuleByRow()` (singular) sí filtraba correctamente por `sheet`+`section`+`row`, y sirvió de precedente arquitectónico para el fix. **Medido en vivo sobre las 160 filas BM: 36 contaminadas (22,5%)** — filas de `BM18/A,D` y `BM18A/A,B` mostraban respuestas funcionales reales de Serie A (`A06`, `A08`, `A09`, `A11a`, `A19a`) atribuidas falsamente a BM, por pura coincidencia de número de fila. **Sin corrupción de datos persistidos en ningún momento** — el gap era exclusivamente de lectura/visualización (`funcional_por_fila` del panel de calibración); BM siguió 0/6 durante y después del hallazgo.

**3) BM-9.2 — fix local, genérico, sin hardcodes:**

`getFunctionalRulesByRow()` ahora exige que el `sheet`+`section` propios del registro (`$data['sheet']`/`$data['section']`) coincidan con los solicitados antes de incluirlo — mismo filtro que ya usaba `getFunctionalRuleByRow()`, replicado 1:1. **Genérico, multi-serie, multi-hoja, multi-sección — 0 hardcodes de `BM`/`BM18`/`BM18A`/`structure_id=72`/Serie A/`A01`/filas concretas.** Sin cambios de persistencia, fingerprints, formato público de salida, ni semántica de `getFunctionalRuleByRow()` (no tocado). Reproducido el bug con fixture aislada (`Storage::fake('local')`, sin tocar datos reales) antes de corregir, y vuelto a medir después: **160 filas con patrón, 0 evidencia funcional BM, 0 contaminadas** — correcto, porque BM nunca tuvo ninguna respuesta guardada; los 36 falsos positivos desaparecieron sin fabricar ninguna respuesta faltante. Las respuestas legítimas de Serie A (ej. `A08/A.2` fila 25, `A11a/C` fila 27) se reconfirmaron byte-idénticas tras el fix cuando se consultan con su propio sheet/section real.

**4) Tests — `FunctionalRuleServiceRowIsolationTest.php` (nuevo, 8/8 passing):** misma row/sheet/section → evidencia propia; misma row, otra sheet → vacío; misma row, misma sheet, otra section → vacío; misma row en 3 sheets/sections distintos → cada uno recibe solo lo propio; row sin evidencia propia pero existente en otra sección → vacío, no heredado; case-insensitivity preservada; `getFunctionalRuleByRow()` (hermano) no afectado; comportamiento correcto preexistente sin regresión. **Regresión**: `Feature/Calibration`+`Feature/RuleEngine` — 476 tests, 441 passed, **35 fallos = exactamente el baseline histórico por nombre**, 0 nuevos. `Unit/RuleEngine`: **162/162**.

**5) Verificación visual manual, confirmada por el usuario en ATHENEA local:** Calibración → REM BM → BM18 → Sección A → fila 25 — **antes** del fix mostraba falsamente una respuesta histórica de Serie A/`A08`; **después**: "Decisión propia: Sin decisión", "Hereda de: Sin decisión heredada", "Regla aplicada: Sin decisión", "Origen: Sin decisión" — sin autor/fecha/observación heredada falsamente. **No se guardó ninguna respuesta funcional durante toda la campaña BM-9.1/9.2/9.3.**

**6) Excepciones funcionales detectadas en BM-9.1, documentadas, NO resueltas — quedan como pendientes explícitos de BM-10:**
- **`BM18A/A` fila 89** — TOTAL de segundo nivel (`=+C20+C43+C49+C54+C73+C78+C88`, suma de los 7 sub-totales de grupo), capturado correctamente como `rem_technical_totals` (confirmado en upload #197), pero **sin una regla `sum_equals` propia** dentro de las 53 internas certificadas (el catálogo BM-5.1/BM-6 solo cubrió el nivel 1). Observación de alcance, no bloqueante, **no crear regla nueva sin autorización explícita separada**.
- **`BM18A/B` fila 178** — fila estructuralmente anómala real: sin etiqueta en A/B, sin fórmula TOTAL en C (a diferencia de toda fila TOTAL de la sección), pero `D178`/`E178` genuinamente editables/desbloqueadas. Ubicada entre un TOTAL de grupo (177) y un header de sub-sección (179, "ORTOPEDIA Y TRAUMATOLOGÍA"). Análoga a la excepción histórica de Serie A `A01` filas 23-24. **Requiere decisión funcional explícita — no resolver automáticamente, no inventar comportamiento.**

**7) Orden de calibración recomendado para BM-10 (de menor a mayor riesgo, ya evaluado en BM-9.1):** 1) `BM18/C` · 2) `BM18/B` · 3) `BM18/D` · 4) `BM18/A` · 5) `BM18A/A` (incluye fila 89) · 6) `BM18A/B` (incluye fila 178, mayor complejidad estructural).

**8) Baseline reconfirmado, sin desviación en ningún punto de BM-9.1/9.2/9.3:** Serie A **67/v35** · BM **72/v1**, **90 rules** (53 sum_equals + 37 cross_sheet_equals), **90 bindings** · Global **888/841/1745** · Upload **#197**: 181 rem_data, 20 technical_totals, 90/90 passed · Calibración funcional BM: **0/2 hojas, 0/6 secciones, 6 pendientes, 0 respuestas**.

**9) Git — commit y push, autorizados explícitamente por el usuario:** Commit **`a90ff52`** (`fix(rem): isolate functional rules by sheet and section`) — exactamente **2 archivos**, staged uno por uno: `FunctionalRuleService.php` (modificado, +11 líneas) + `FunctionalRuleServiceRowIsolationTest.php` (nuevo). `main` = `origin/main` = `a90ff52`, push fast-forward normal (`2831307..a90ff52`), ahead/behind 0/0. Confirmados fuera del commit, sin tocar: `vite.config.ts`, 2× `Diag*Command.php`, `backend/demo/`.

**10) Siguiente fase — BM-10, no iniciada, requiere autorización explícita:**

**BM-10 — Calibración funcional real de BM.** Primer objetivo: **`BM18/C`** (menor riesgo, 1 fila, sin fórmula). **No calibrar masivamente** — trabajar sección por sección, revisando primero evidencia/preguntas y guardando respuestas **solo cuando exista criterio funcional claro** (nunca inventado ni asumido). Las filas 89 y 178 (punto 6) se abordan explícitamente cuando corresponda a su sección (`BM18A/A` y `BM18A/B` respectivamente, últimas del orden recomendado), con decisión funcional propia, no arrastrada de Serie A.

**11) Producción:** no asumida sincronizada. Último estado conocido histórico: Serie A estructura **19/v33** (no revalidado en esta sesión). Ningún commit de BM-9 desplegado. Cero SSH/deploy/Docker/migrate/seed/cache clear/backfill en toda la campaña BM-9.

---

### CIERRE DE JORNADA — 2026-09-14 (continuación #3), REM BM — BM-8 CROSS-SHEET (37 RELACIONES) CERTIFICADAS END-TO-END Y COMMITEADAS — leer esto primero, antes que todo lo demás de esta sección

**Veredicto: `BM87_DOCUMENTACION_CROSS_SHEET_CERTIFICADA`.** Reemplaza como punto de reanudación inmediato al checkpoint "2026-09-14 (continuación #2), 53 REGLAS INTERNAS..." de abajo (ese sigue vigente para su propio alcance histórico — las 53 reglas internas — pero la campaña avanzó: las 37 relaciones cross-sheet BM18→BM18A quedaron auditadas, los 2 engine gaps que las bloqueaban quedaron corregidos genéricamente, las 37 reglas quedaron realmente importadas en BD local, certificadas end-to-end contra ambos XLSM reales y desde una carga real de la UI, y el código quedó commiteado y pusheado). `main` = `origin/main` = **`f218c58`**, ahead/behind **0/0**.

**1) Estado BM actual — LOCAL, no asumir en producción:**

`rem_template_structures.id=72` (serie=BM, año=2026, `version_number=1`, `status=active`), `rem_template_id=2`. **90 reglas BM activas** (53 `sum_equals` internas + 37 `cross_sheet_equals`), **90 bindings** (1:1, `bindable_type=structure`, `bindable_id=72`, `serie=BM`, `anio=2026`, `active=true`).

Global local (incluye Serie A + BM): `rem_rules=888`, `activas=841`, `rem_rule_bindings=1745`. Serie A: `67/v35`, sin cambios por esta campaña.

**2) Las 37 relaciones cross-sheet — auditoría BM-8.1:**

**37 relaciones reales** `BM18 → BM18A` (**31 DIRECT** + **6 SUM_RANGE**), verificadas idénticas en ambos archivos reales disponibles (`102302BM05.xlsm`, `102412BM05.xlsm`) — misma fórmula exacta en cada celda fuente de `BM18`, en ambos establecimientos. **0 relación inversa** (`BM18A→BM18`, nunca encontrada), **0 duplicados**, **0 mismatches** contra el contenido real de las fórmulas del Excel (verificado formula por formula contra ambos XLSM antes de generar el manifiesto).

**3) Los 2 engine gaps encontrados y resueltos — BM-8.2:**

**Gap 1** — `RuleEngineService::execute()` solo resolvía `_target_rows` de `cross_sheet_equals` desde `rem_data` del upload, sin ningún equivalente al backfill de `rem_technical_totals` que ya tenía `sum_equals` (vía `findTechnicalTotalRow()`). Impacto real medido antes del fix: **29/37 relaciones DIRECT** quedaban `skipped` con `target_cell_not_found`, porque sus filas destino en `BM18A` son TOTALes técnicos (nunca persistidos en `rem_data`, correctamente excluidos por el parser). **Fix**: nuevo método privado `buildCrossSheetTargetRows(int $uploadId, string $targetSheet, Collection $grouped): Collection` — combina `rem_data` + `rem_technical_totals` del **mismo upload** y la **misma hoja destino**; precedencia explícita: `rem_data` siempre gana, una fila técnica solo se agrega si su `row_number` no está ya presente en `rem_data`. Genérico — sin ningún hardcode de `BM`/`BM18`/`BM18A`/`structure_id=72` en el código productivo; mismo patrón conceptual que `findTechnicalTotalRow()` mantenido para `sum_equals` (ninguno de los dos se tocó el uno al otro).

**Gap 2** — `RemRuleManifestImporterService::plan()` invocaba `normalizeConfig()` (diseñado exclusivamente para el formato legado `column/row_range/rule_logic` de `sum_equals`) para **cualquier** `rule_type`, por lo que toda config `cross_sheet_equals` válida quedaba marcada `invalid_config` (nunca tiene `source_letters`/`target_column`). **Fix**: nuevo método `validateCrossSheetConfig()` — validación nativa fail-closed, con las mismas expresiones regulares que usa `CrossSheetEqualsEvaluator` internamente (para no divergir de lo que realmente se ejecutaría): `source.cell` válida, `target.sheet` existente en la estructura activa, `target.cell` **o** `target.range`+`aggregation='sum'` (nunca ambos). Comportamiento de `sum_equals` sin ningún cambio semántico.

`CrossSheetEqualsEvaluator.php` y `SectionDetectorService.php`: **ninguno de los dos tocado** en ningún punto de BM-8.

**4) Manifiesto real — BM-8.3:**

`backend/database/seeders/data/rem-bm-2026-cross-sheet-rules-manifest.json` — **37 reglas**, **37 rule_keys distintas**, **31 DIRECT** + **6 SUM_RANGE**. Deliberadamente **sin** `catalog_rule_id`/`derived_from_rule_id` (a diferencia del manifiesto de las 53 internas — estas 37 provienen de la auditoría de fórmulas reales BM-2.5, no de un catálogo funcional). Trazabilidad por regla: `source=excel_formula`, `origin_sheet`/`origin_cell`, `original_formula` (fórmula real capturada del Excel), `target_sheet`, `relation_type` (`DIRECT`/`SUM_RANGE`), `created_via=bm-2.5-cross-sheet-audit`.

**5) Importación real local — BM-8.4:**

Antes del import: `851 rem_rules / 804 activas / 1708 bindings` (global), BM = `53 rules / 53 bindings`. Después: `888 / 841 / 1745` (global), BM = `90 rules / 90 bindings`. Las 37 cross-sheet quedaron **activas** con **37 bindings** 1:1.

IDs reales verificados en vivo contra `esalud_dev` en este mismo checkpoint (no asumidos del turno anterior): `rem_rules` cross-sheet = **1122–1158** (37 filas), `rem_rule_bindings` cross-sheet = **2443–2479** (37 filas). **No asumir continuidad perfecta de IDs** — los huecos entre el rango de las 53 internas (921-973) y este rango (1122-1158) son benignos, atribuibles al mismo patrón ya documentado de autoincremento InnoDB "quemado" por transacciones simuladas con `DB::rollBack()` durante BM-8.2/BM-8.3 (mismo mecanismo ya explicado para `id=920` en el checkpoint de las 53 internas).

**6) Idempotencia — reconfirmada en este mismo checkpoint:**

Dry-run del importador contra el manifiesto de las 37, ejecutado de nuevo en este turno (sin `--commit`, solo lectura): `would_create=0`, `would_skip=37` (idempotentes, contenido ya idéntico), `conflicts=0`, `invalid=0`, `bindings_would_create=0`. Las 37 reglas persistidas coinciden exactamente con el manifiesto — sin drift.

**7) Certificación de motor contra ambos XLSM reales — BM-8.4:**

Ejecutadas las 90 reglas **realmente persistidas** (no simuladas) contra ambos archivos reales (dentro de transacciones con `DB::rollBack()`, nada escrito por esta verificación):
- `102302BM05.xlsm`: 90 total, **90 passed**, 0 failed, 0 skipped, 0 invalid.
- `102412BM05.xlsm`: 90 total, **90 passed**, 0 failed, 0 skipped, 0 invalid.
Desglose ambos: 53 internas `passed` + 37 cross-sheet `passed`. **0 `missing_total_row`, 0 `target_cell_not_found`.**

**8) Incidente de worker / runbook — BM-8.5:**

Tras aplicar el fix BM-8.2, el worker local (PID 26560, `StartTime` 15:44:45) quedó **STALE** — iniciado antes de los `mtime` de `RuleEngineService.php` (16:04:01) y `RemRuleManifestImporterService.php` (16:04:47), mismo patrón ya documentado en el incidente BM-7. **Reiniciado únicamente el worker** (`queue:restart` graceful + nuevo `queue:work`) — backend y frontend **no reiniciados**. Nuevo worker: **PID 3672**, `StartTime` 16:23:43 (posterior a ambos mtimes) → `WORKER_CURRENT`. Runbook reforzado (ya documentado desde BM-7, ahora confirmado dos veces): **tras cualquier cambio de código PHP consumido por el worker, reiniciar el worker local de forma controlada antes de certificar cualquier flujo real end-to-end** — de lo contrario el resultado observado refleja código desactualizado.

**9) Upload canónico de certificación E2E — #197 (BM-8.5):**

`102412BM05.xlsm`, Posta Caleta Chanavayita, período 2026-5, carga real hecha por el usuario desde la UI de ATHENEA local (no simulada, no por CLI). Parser: `status=success`, 0 errores, **181 rem_data, 20 rem_technical_totals**. Fila 186 (BM18A/B): `technical_total`, `embedded_trailing_total_row`. Fila 206: `technical_total`, `trailing_total_beyond_bounds`. Ejecución de reglas: **90 total, 90 passed, 0 failed, 0 skipped, 0 invalid**. UI reportó: 90 evaluadas, 90 cumplen, 0 incumplen, 100,0% — **coincidencia UI↔backend 1:1**, confirmada explícitamente. `jobs` pendientes = 0, `failed_jobs` relacionados a #197 = 0.

**10) Git — commit y push, autorizados explícitamente por el usuario en este mismo turno:**

Commit **`f218c58`** (`feat(rem): add BM cross-sheet rule support`) — exactamente **5 archivos**, staged uno por uno (nunca `git add .`/`-A`): `RemRuleManifestImporterService.php`, `RuleEngineService.php` (modificados), `rem-bm-2026-cross-sheet-rules-manifest.json`, `CrossSheetEqualsIntegrationTest.php`, `RemRuleManifestImporterServiceTest.php` (nuevos/modificados). Tests focalizados 44/44 (326 assertions). Regresión `Feature/RuleEngine`+`Feature/Calibration`+`Unit/RuleEngine`: 35 fallos = exactamente el baseline histórico por nombre, 0 nuevos. `main` = `origin/main` = `f218c58`, push fast-forward normal (`9087689..f218c58`), ahead/behind 0/0. Confirmados fuera del commit, sin tocar: `vite.config.ts`, 2× `Diag*Command.php`, `backend/demo/`.

**11) Alcance BM cerrado hasta ahora — DONE:** estructura BM 72/v1, template/config BM, parser BM, cell-data BM (6/6), fix de technical totals filas 186/206, las 53 reglas internas + 53 bindings, las 37 cross-sheet + 37 bindings, soporte genérico de target-rows técnicos para cross-sheet (`buildCrossSheetTargetRows()`), importador cross-sheet (`validateCrossSheetConfig()`), manifiesto cross-sheet real, certificación de motor 90/90 contra ambos XLSM, certificación end-to-end real desde la UI (90/90, upload #197), commit/push de BM-8 (`f218c58`).

**12) ⚠️ NO declarar "REM BM completamente calibrado" — la calibración funcional sigue pendiente, sin cambios por esta campaña:**

Estado de calibración funcional BM: **0/2 hojas, 0/6 secciones, 6 pendientes** — `BM18` (secciones A, B, C, D) y `BM18A` (secciones A, B). **Las 90 reglas técnicas/cross-sheet certificadas (BM-6 a BM-8) NO equivalen a calibración funcional completa** — son capas distintas: el motor de reglas verifica consistencia aritmética/estructural (sumas, referencias cross-hoja), mientras que la calibración funcional es la decisión de Estadística APS sobre qué columnas/filas de cada sección deben capturarse, sus excepciones y su comportamiento esperado por fila — igual que ya se documentó y distinguió explícitamente en el checkpoint de las 53 reglas internas.

**13) Siguiente fase — BM-9, no iniciada, requiere autorización explícita:**

**BM-9 — Calibración funcional BM**: calibrar las 6 secciones pendientes (`BM18/A,B,C,D` + `BM18A/A,B`) contra la estructura real activa (72/v1), reutilizando el mismo pipeline ya maduro de Serie A (`cell-data` ya existente, `PatternMigrationScanner`, `FunctionalRuleService`, etc.). **No reabrir las 90 reglas técnicas ya certificadas** salvo evidencia concreta de un problema real. Tras cerrar las 6 secciones: **auditoría canónica final de BM** (fase posterior, aún sin numerar formalmente).

**14) Producción:** no asumida sincronizada. Último estado conocido histórico: Serie A estructura **19/v33** (no revalidado en esta sesión). Ningún commit de BM-8 desplegado. Cero SSH/deploy/Docker/migrate/seed/cache clear/backfill en toda la campaña BM-8.

---

### CIERRE DE JORNADA — 2026-09-14 (continuación #2), REM BM — 53 REGLAS INTERNAS IMPORTADAS Y CERTIFICADAS END-TO-END DESDE LA UI REAL — leer esto primero, antes que el checkpoint "BM-3 A BM-5.5 CERRADAS" de abajo

**Veredicto: `BM72_COMMIT_PUSH_REGLAS_INTERNAS_CERTIFICADO`.** Reemplaza como punto de reanudación al checkpoint "BM-3 A BM-5.5 CERRADAS" de abajo (ese sigue vigente para su propio alcance histórico, pero la campaña avanzó: las 53 reglas internas del catálogo BM 2026 quedaron realmente importadas en BD local, certificadas end-to-end desde una carga real de la UI de ATHENEA — no solo simulada — y el código del importador quedó commiteado y pusheado). `main` = `origin/main` = **`3feb601c1b201f5d011ac1584171adc50a05f37a`** (`3feb601`), ahead/behind **0/0**.

**1) Estado BM actual — LOCAL, no asumir en producción:**

`rem_template_structures.id=72` (serie=BM, año=2026, `version_number=1`, `status=active`), `rem_template_id=2`. **53 reglas internas activas**, **53 bindings** (`bindable_type=structure`, `bindable_id=72`, `serie=BM`, `anio=2026`, `active=true`, 1:1 con cada regla).

IDs reales (no asumir continuidad perfecta — hay un hueco benigno de autoincremento InnoDB en `id=920`, confirmado que nunca existió ni siquiera como fila soft-deleted, sin relación con pérdida de datos): `rem_rules` de BM = **921-973** (53 filas). `rem_rule_bindings` de BM = **2242-2294** (53 filas).

Global local (incluye Serie A + BM): `rem_rules=851`, `activas=804`, `rem_rule_bindings=1708`.

**2) Upload canónico de certificación — #196:**

`102412BM05.xlsm`, Posta Caleta Chanavayita, período 2026-5, carga real hecha por el usuario desde la UI de ATHENEA local (no simulada, no por CLI). Resultado:

```
status=success, errores de parser=0
rem_data=181, rem_technical_totals=20
53 reglas evaluadas → 53 passed, 0 failed, 0 skipped, 0 invalid
0 missing_total_row, 100% cumplimiento
```

Fila 186 (BM18A/B): `technical_total`, `exclusion_reason=embedded_trailing_total_row`. Fila 206: `technical_total`, `exclusion_reason=trailing_total_beyond_bounds`. Este es el **resultado canónico de referencia** para cualquier futura carga real de BM con esta estructura/reglas — reemplaza como evidencia end-to-end la expectativa simplificada de fases anteriores.

**3) Incidente operativo encontrado y resuelto — worker local con código desactualizado en memoria:**

Una primera carga real (**upload #195**, mismo archivo, **mismo SHA-256** que #196) dio un resultado distinto e inicialmente inesperado: `182 rem_data`, `18 technical_totals`, `50 passed / 3 skipped` (las 3: `bm18a_b_d_sum_equals_g09`, `bm18a_b_e_sum_equals_g09`, `bm18a_b_f_sum_equals_g09`, con `missing_total_row` para `total_row=206`). Causa raíz identificada con evidencia directa (timestamps): el proceso `queue:work` (PID 1896) llevaba corriendo desde **08:49:55**, **antes** de que el fix BM-5.3 se escribiera en `RemParserService.php` (mtime **12:11:52**) — el worker mantenía en memoria la clase PHP anterior al fix (fila 186 mal clasificada como `rem_data`, fila 206 nunca capturada) y nunca la recargó, porque `queue:work` carga las clases una sola vez al arrancar y las conserva durante toda su vida.

**Resuelto**: reinicio controlado, autorizado explícitamente, **únicamente del worker** (`php artisan queue:restart` — señal graceful, el proceso viejo terminó solo tras su ciclo — seguido de un `queue:work` nuevo, PID **26560**, iniciado **después** del mtime del fix). Backend y frontend **no se tocaron**. Repetida la carga con el mismo archivo (byte-idéntico) → upload #196, resultado limpio (ver punto 2). `#195` se dejó intacto, sin reprocesar, como evidencia histórica del incidente.

**Lección operativa / runbook local, para futuras fases:** `queue:work` (y cualquier proceso PHP de larga duración equivalente) **no recoge cambios de código en caliente**. Después de cualquier cambio que afecte al parser, a los jobs de procesamiento, o a cualquier clase que el worker consuma, **reiniciar el worker local de forma controlada antes de certificar cualquier flujo real end-to-end** — de lo contrario el resultado observado reflejará código desactualizado, no el estado real del repositorio. Esto aplica en general, no solo a este incidente puntual.

**4) Commit de código:**

`3feb601` (`feat(rem): add controlled BM internal rule import`) — `RemRuleManifestImporterService` (transaccional, idempotente, fail-closed, resuelve la estructura dinámicamente por serie/año, nunca hardcodea un ID) + `RemImportRuleManifestCommand` (`rem:import-rule-manifest`, dry-run por defecto, `--commit` explícito) + `RuleManifestImportException` + el manifiesto versionado real (`database/seeders/data/rem-bm-2026-internal-rules-manifest.json`, 53 reglas) + su test suite (14/14). `main`=`origin/main`=`3feb601`, ahead/behind 0/0.

**5) Alcance cerrado hasta ahora (BM parser/config/structure/cell-data/UI/reglas internas/importador) — REM BM como serie NO está terminado:**

DONE: parser/config BM, estructura 72/v1, cell-data (6/6), carga real desde UI, fix de technical totals (BM-5.3/5.5), las 53 reglas internas + sus 53 bindings, certificación end-to-end real desde la UI, herramienta de importación controlada (genérica, reutilizable).

**Pendiente, sin fecha, fases separadas:**
- **37 relaciones cross-sheet BM18→BM18A** (31 referencias directas + 6 rangos `SUM`) — el motor (`rule_type=cross_sheet_equals`, `CrossSheetEqualsEvaluator`) ya existe y está registrado en los 3 entrypoints reales desde el commit `af3bfe9`, pero **0 reglas BM cross-sheet persistidas todavía**. Próxima fase: **BM-8** (diseño/importación/control de esas 37, separado de las 53 internas).
- **Calibración funcional BM**: `0/2` hojas, `0/6` secciones, 6 pendientes (BM18: 4 secciones; BM18A: 2 secciones) — **no confundir con las 53 reglas técnicas**, que ya están certificadas independientemente de que la calibración funcional (decisiones de Estadística APS sobre columnas/comportamiento por fila) no se haya iniciado.
- Auditoría/cierre canónico final de BM.
- Despliegue a producción — no iniciado, no evaluado en esta campaña.

**6) Serie A:** `67/v35`, sin ningún cambio por la campaña BM.

**7) Producción:** no asumida sincronizada. Último estado conocido histórico: Serie A estructura **19/v33** (no revalidado en esta sesión). Ningún commit de esta campaña BM desplegado. Cero SSH/deploy/Docker/migrate/seed/cache clear/backfill en toda la campaña BM-6/BM-7.

---

### CIERRE DE JORNADA — 2026-09-14 (continuación), REM BM — BM-3 A BM-5.5 CERRADAS / CHECKPOINT PREVIO A IMPORTACIÓN DE REGLAS (BM-6) — leer esto primero, antes que el checkpoint "BM-2.6B CERRADA Y RESPALDADA" de abajo

**Veredicto: `BM55_FIX_GENERICO_COMMIT_PUSH_CERTIFICADO`.** Este checkpoint reemplaza como punto de reanudación inmediato al checkpoint "BM-2.6B CERRADA Y RESPALDADA" de abajo (ese sigue vigente para su propio alcance específico — registro del evaluador cross-sheet en los entrypoints del motor — pero la campaña avanzó mucho más allá: estructura/config real de BM, calibración de roles de columna vía `cell-data`, certificación end-to-end desde la UI real, mapeo canónico completo de las 10 reglas del catálogo BM 2026, dos anomalías estructurales reales encontradas y corregidas con un fix genérico del motor de parseo, certificado exhaustivamente contra Serie A, commiteado y pusheado). `main` = `origin/main` = **`67ebe96b95caa5f240e690941f6ed3fbcf5c4bdb`** (`67ebe96`), ahead/behind **0/0**.

**1) Cronología de commits BM, en orden:**

| Commit | Fase | Resumen |
|---|---|---|
| `2c14cd3` | BM-2 | Generalización multi-serie del motor de calibración |
| `1915040` | BM-2.6A | Detección/representación estructural de dependencias cross-hoja |
| `af3bfe9` | BM-2.6B | Registro real de `CrossSheetEqualsEvaluator` en los 3 entrypoints del motor |
| `fcb9f2d` | doc | Cierre documental de BM-2.6B |
| `f200e20` | BM-3.1 | Fix de detección de serie desde nombre de archivo real (`MetadataExtractorService`) |
| `eec2920` | BM-3.5 | `RemTemplateConfigGeneratorService` oficial, versionado, con tests |
| `91474f3` | BM-3.6 | Fix `serieFromRemType()` — preserva series de 2 letras (BM/BS ya no colisionan con B) |
| `67ebe96` | BM-5.5 | Fix genérico: captura de technical totals de cierre más allá del límite declarado de sección |

**2) Estructura BM — estado LOCAL, no asumir en producción:** `rem_template_structures.id=72`, serie=BM, año=2026, `version_number=1`, `status=active`, `rem_template_id=2`, `rem_upload_id=20` (vínculo trazable autorizado en BM-4.2 — misma identidad de archivo confirmada byte a byte antes de escribirlo, nunca un valor inventado). 2 `forms` (hojas BM18, BM18A), 6 secciones totales: **BM18/A, BM18/B, BM18/C, BM18/D, BM18A/A, BM18A/B**.

**3) `RemTemplate` BM — `id=2`:** `config['sheets']` cubre BM18 y BM18A, generado desde la estructura vía `RemTemplateConfigGeneratorService` (BM-3.5). Hojas NOMBRE/Control/macros explícitamente excluidas del alcance parseable, mismo criterio ya usado en Serie A.

**4) Cell-data — 6 artefactos locales, gitignorados** (`storage/app/private/certificacion/cell-data/`): `BM18-A.json`, `BM18-B.json`, `BM18-C.json`, `BM18-D.json`, `BM18A-A.json`, `BM18A-B.json`. **No están en Git** (mismo patrón ya establecido para Serie A) — necesarios para reproducir localmente la resolución de roles de columna, calibración y detección de filas técnicas. Cualquier sincronización futura a producción debe ser controlada y explícita — **nunca copiar la BD local sobre producción**, mismo principio ya vigente para Serie A (ver checkpoint de sincronización pendiente más abajo).

**5) Certificación end-to-end UI real — upload #193 (`102412BM05.xlsm`):** preview confirmado por el usuario desde la UI real — Serie BM, Mayo 2026, Posta Caleta Chanavayita, DEIS 102412. Resultado persistido ORIGINAL (antes del fix BM-5.3): **182 `rem_data`, 18 `rem_technical_totals`, 0 errores de parser**. Resultado READ-ONLY reparseado con el fix actual (nada persistido sobre #193): **181 `rem_data`, 20 `rem_technical_totals`, 0 errores**. La diferencia se explica completamente: fila 186 de BM18A/B (TOTAL genuino, con una columna F genuinamente editable-pero-vacía en el template real que antes bloqueaba su reconocimiento) pasó de persistirse incorrectamente como `rem_data` a clasificarse correctamente como `technical_total`; fila 206 (TOTAL final legítimo, un renglón más allá del `filaFinDatos=205` declarado) antes nunca se alcanzaba y ahora se captura como `trailing_total_beyond_bounds`. **Upload #193 permanece exactamente como quedó persistido en su momento — no se tocó, no se reprocesó.**

**6) Mapeo BM-5.1 — plan interno completo, NO insertado:** catálogo real de **10 reglas conceptuales**, 100% `sum_equals` (2 en BM18, 8 en BM18A). Plan ejecutable canónico, verificado contra fórmulas reales de ambos XLSM (`102302BM05.xlsm`/`102412BM05.xlsm`, estructuralmente idénticos):

| Hoja | `rem_rules` | Detalle |
|---|---|---|
| BM18 | 3 | regla 912 → 2 instancias (13-23 `E+F=D`; 24-37 `F=D`) + regla 913 → 1 instancia (42-51) |
| BM18A/A | 22 | 914 horizontal (1, rango contiguo 13-118) + 915-917 vertical × 7 grupos (21) |
| BM18A/B | 28 | 918 horizontal (1, rango contiguo 124-205, seguro tras el fix de fila 186) + 919-921 vertical × 9 grupos (27 — incluye los grupos 8 y 9, ambos resueltos tras BM-5.3) |
| **Total** | **53** | **53 bindings** (1:1, patrón "hijas" ya usado en Serie A) |

**7) Anomalías estructurales reales — encontradas y resueltas (BM-5.2/BM-5.3):**
- **Fila 186 (BM18A/B)**: TOTAL real (`A186:B186="TOTAL"`, `C186`/`D186` fórmulas `SUM` hacia atrás), pero `F186` genuinamente editable/desbloqueada y vacía — confirmado a nivel de estilo OOXML crudo en ambos XLSM, idéntico. Causaba que se persistiera como `rem_data` en vez de `technical_total`. **Fix**: el chequeo "capturable real" ahora exige evidencia positiva de valor capturado (`valor_bruto` no vacío), no solo permisos de edición — nuevo método privado `celdaTieneValorRealCapturado()`.
- **Fila 206 (BM18A/B)**: TOTAL final legítimo y perfectamente formado, un renglón más allá de `filaFinDatos=205` (excluido deliberadamente por `SectionDetectorService::excludeTrailingTotalRows()`, comportamiento correcto y sin tocar). El mecanismo "trailing-beyond-bounds" (17.48) nunca lo alcanzaba porque `$maxRow` en `RemParserService::parseSheet()` se acotaba al mayor `data_end_row` entre TODAS las secciones de la hoja — suficiente para huecos intermedios entre secciones, pero no para la ÚLTIMA sección de una hoja (sin ninguna sección posterior que extienda el límite). **Fix**: `$maxRow` extendido en `+1`, siempre acotado por `$sheetMaxRow`.

`SectionDetectorService.php` **no modificado** en ninguno de los dos fixes. Estructura 72/v1 permanece válida y sin cambios — el fix es puramente de tiempo de parseo, no estructural.

**8) Impacto en Serie A — auditado y certificado (BM-5.4):** el mismo fix genérico también permite capturar, por primera vez, **A06/L fila 181** y **A33/E fila 74** como `trailing_total_beyond_bounds` (mismo patrón estructural exacto que BM18A/B fila 206 — TOTAL final legítimo, última sección de su hoja, nunca antes alcanzado). Verificado contra upload real **#187** (fixture de certificación 17.54): `rem_data` **idéntico clave por clave** (0 diferencias en ambas direcciones), errores de parser idénticos (2, ajenos, en A25). Simulación real del motor de reglas (dentro de una transacción con ROLLBACK inmediato, nada persistido): **11 reglas de A06/L** (`total_row=181`) pasan de `skipped` (`missing_total_row`) a **`passed`** — validación correcta y genuina, el valor capturado coincide con la suma real de los componentes (ambos 0 en esa carga). Las **3 fallas funcionales reales ya documentadas** (reglas `178`, `714`, `715`) permanecen exactamente iguales. A33/E fila 74 no tiene ninguna regla activa que la referencie — 0 impacto ahí. **No se ejecutó ningún backfill ni reproceso persistente de uploads históricos.**

**9) Nota pendiente para el futuro despliegue a producción — sin decidir:** cuando eventualmente se despliegue este fix a producción, quedará pendiente decidir: **(A)** reprocesar explícitamente uploads históricos de Serie A para que esas 11 reglas de A06/L dejen de aparecer como `skipped`, o **(B)** dejar que el comportamiento correcto se aplique naturalmente solo a cargas nuevas, sin tocar el historial. **Ninguna decisión tomada — no ejecutar ninguna de las dos sin autorización explícita futura.**

**10) Relaciones cross-sheet BM18→BM18A — separadas, sin mezclar con las 53 reglas internas:** **37 relaciones reales** (31 referencias directas + 6 rangos `SUM`), confirmadas idénticas en ambos XLSM (BM-2.5). Capacidad técnica ya implementada y registrada en los 3 entrypoints reales del motor (`rule_type='cross_sheet_equals'`, `CrossSheetEqualsEvaluator`, BM-2.6B) — pero **0 reglas BM cross-sheet reales creadas todavía**. Completamente fuera del conteo de 53 reglas internas — corresponde a una fase posterior (BM-8).

**11) Deuda técnica registrada, sin resolver, sin fecha:**
- **A)** `CellScanOrchestrator::resolveFilePath()` — el fallback global (cuando `rem_upload_id` es null) puede elegir un archivo de OTRA serie si prefiere nombres `SA_*`. Ya mitigado específicamente para la estructura 72 de BM (vínculo trazable de `rem_upload_id`, BM-4.2), pero el gap de diseño genérico en el comando sigue sin corregirse.
- **B)** `rem:approve-structure` usa `--user=1` como default, no portable entre entornos (ya se tuvo que usar `--user=25` explícito en BM-3.3).
- **C)** Backfill de Serie A tras el despliegue futuro del fix BM-5.3/BM-5.5 — decisión explícita pendiente (ver punto 9).

**12) Roadmap BM actualizado:**

```
BM-1 DONE · BM-2 DONE · BM-2.5 DONE · BM-2.6A DONE · BM-2.6B DONE
BM-3.1 DONE · BM-3.2 DONE (solo BD local) · BM-3.3 DONE (solo BD local)
BM-3.4 DONE · BM-3.5 DONE · BM-3.6 DONE · BM-3.7 DONE
BM-4 DONE · BM-4.1 DONE · BM-4.2 DONE · BM-4.3 DONE
BM-5 DONE · BM-5.1 DONE · BM-5.2 DONE · BM-5.3 DONE · BM-5.4 DONE · BM-5.5 DONE
  ↓
BM-6 (SIGUIENTE, no iniciado) — importación controlada de las 53 rem_rules/bindings internas
  ↓
BM-7 — calibración funcional / ejecución real
  ↓
BM-8 — las 37 relaciones cross-sheet
  ↓
BM-9 — auditoría canónica / certificación
```

Numeración de BM-7 a BM-9 sujeta a ajuste — no rígida. Ninguna fase futura se inicia sin autorización explícita.

**13) Producción — reforzado:** **no asumida sincronizada** con nada de esta campaña BM. Último estado conocido de producción: Serie A estructura **19/v33** (histórico, no revalidado en esta sesión — ver checkpoint de sincronización pendiente más abajo en este archivo). Nada de la campaña BM (estructura 72, `RemTemplate` 2, el fix BM-5.3/BM-5.5, ni ningún commit de esta jornada) ha sido desplegado. Cero SSH/deploy/Docker/migrate/seed/cache clear en ninguna fase BM-1 a BM-5.6.

---

### CIERRE DE JORNADA — 2026-09-14, REM BM — BM-2.6B CERRADA Y RESPALDADA — leer esto primero, antes que el checkpoint de 2026-09-11 de abajo

**Veredicto: `BM26B_COMMIT_PUSH_RESPALDADO`.** Cierra el punto pendiente que dejó abierto el checkpoint "2026-09-11, CAMPAÑA BM-1 A BM-2.6B" (de más abajo, todavía vigente para el resto de su contenido): el registro real de `CrossSheetEqualsEvaluator` en los entrypoints del motor, ya commiteado y pusheado. Commit **`af3bfe951d7d073c6c8d3f39aa172830c286d6f6`** (`af3bfe9`, `feat(rem): add cross-sheet rule evaluation`) — `main` = `origin/main` = `af3bfe9`, ahead/behind **0/0**, push fast-forward normal (`1915040..af3bfe9`). Serie A sin cambios (67/v35, 798/751/1655, 306/306, 22/22, distribución canónica idéntica) — reconfirmado en vivo contra `esalud_dev` **después** del push. REM BM sigue sin ninguna persistencia real: `rem_template_structures` serie=BM **0**, `rem_rule_bindings` serie=BM **0**, `rem_templates` id=2 (BM) `config['sheets']` **[]** — reconfirmado en vivo contra `esalud_dev` al cierre de esta jornada.

**Los 11 archivos exactos del commit** (staging explícito uno por uno, nunca `git add .`/`-A`/`-f`, verificado `git diff --cached --name-only` = 11 antes de comitear): `ValidateWithEngineJob.php`, `RuleValidateCommand.php`, `ComparisonReport.php`, `RuleEngineService.php`, `SectionCalibrationMatrixService.php` (modificados) + `CrossSheetEqualsEvaluator.php`, `CrossSheetEqualsIntegrationTest.php`, `CrossSheetEqualsEntrypointRegistrationTest.php`, `SectionCalibrationMatrixServiceCrossSheetDependencyTest.php`, `CrossSheetEqualsEvaluatorBmRealFormulasTest.php`, `CrossSheetEqualsEvaluatorTest.php` (nuevos). **`CLAUDE.md` deliberadamente fuera de este commit** (documental, se actualiza aparte). Confirmados fuera, sin tocar: `vite.config.ts`, 2 `Diag*Command.php`, `backend/demo/`, y `TempValidateFlowCommand.php` (invisible para git por `.git/info/exclude`, imposible de commitear sin `-f`, no usado).

**Auditoría de registries (ampliada respecto a la lista del 2026-09-11)**: se confirmaron los 4 sitios ya sospechados como los únicos entrypoints reales que ejecutan el motor (`registerEvaluator`+`execute()`), y se descartaron explícitamente 3 candidatos adicionales encontrados en la búsqueda (`RuleBindingReconciliationService.php`/`ValidationSummaryService.php` — solo mencionan la clase en comentarios, ninguna instanciación; `RemRebuildStructureCommand.php` — inyecta `RuleEngineService` pero solo llama `resolveRules()`, nunca `execute()`, no necesita evaluador). No existe ningún service provider/factory central — el registro sigue siendo 100% manual y duplicado por sitio, patrón que se mantuvo (no se introdujo ninguna abstracción nueva).

**Cambio aplicado — estrictamente aditivo, 2 líneas por archivo (import + `registerEvaluator`), mismo patrón exacto que `SumEqualsEvaluator`/`RequiredAndLeParentEvaluator` en cada sitio:**
- `backend/app/Domain/RuleEngine/Jobs/ValidateWithEngineJob.php` — el job real de producción (`queue:work`).
- `backend/app/Console/Commands/RuleValidateCommand.php` (`rule:validate`).
- `backend/app/Domain/RuleEngine/Testing/ComparisonReport.php` (respalda `/rule-engine/comparison`).
- `backend/app/Console/Commands/TempValidateFlowCommand.php` (`temp:validate-flow`) — **registrado también, por consistencia, pero ver discrepancia abajo: este archivo nunca puede llegar a un commit.**

Ninguno de los dos evaluadores existentes (`SumEqualsEvaluator`/`RequiredAndLeParentEvaluator`) fue tocado. `CrossSheetEqualsEvaluator::supports()` sigue respondiendo únicamente `rule_type === 'cross_sheet_equals'` — el registro es inerte para Serie A (0 reglas de ese tipo fuera de tests), confirmado también por la regresión completa sin desviación.

**⚠️ Discrepancia real detectada y corregida en el propio turno (no arrastrada a este archivo antes de verificarla): `TempValidateFlowCommand.php` NO está tracked por git.** La auditoría de la mañana lo había calificado de "tracked en git, a diferencia de los `Diag*` excluidos" — **eso era incorrecto**, confirmado con `git ls-files` (vacío para ese path) tras un primer chequeo con lógica de shell defectuosa que dio falso positivo. El archivo está además **excluido localmente vía `.git/info/exclude`** (no committeado, solo en este filesystem), en la misma sección que otros 4 comandos `Temp*Command.php` ya retirados de uso (`TempCheckTemplateCommand`, `TempCreateTemplateConfigCommand`, `TempFindUploadsCommand`, `TempReprocessUploadCommand`) — es decir, es un **5º archivo excluido localmente**, no documentado hasta ahora en la sección "Estado Git" de este `CLAUDE.md` (que solo lista 4: `vite.config.ts`, 2 `Diag*Command.php`, `backend/demo/`). Consecuencia práctica: el registro del evaluador en este archivo **nunca podrá aparecer en `git status`/`git diff`/un commit futuro** sin un `git add -f` explícito (improbable, no se va a hacer) — es auto-cuarentenado por diseño de `.git/info/exclude`, no representa ningún riesgo de contaminar el commit de BM-2.6B, pero **queda documentado aquí como el 5º archivo local excluido**, para no repetir la confusión en el futuro.

**Tests nuevos**: `backend/tests/Feature/RuleEngine/Services/CrossSheetEqualsEntrypointRegistrationTest.php` (4 tests, 12 assertions) — cada uno invoca el entrypoint real de producción tal cual (job `->handle()`, `Artisan::call()` vía `$this->artisan()`, `ComparisonReport::generateReport()`) **sin registrar el evaluador manualmente desde el test**, a diferencia de `CrossSheetEqualsIntegrationTest.php` — si algún entrypoint perdiera su registro, exactamente estos tests fallarían. Cubre: `ValidateWithEngineJob` (regla escrita en `RuleExecutionLog` con `status=passed`, `triggered_by=job`), `rule:validate --write` (idem vía `RuleExecutionLog`), `ComparisonReport` (forzando la rama `engine_only` — `reglaDetectada.tipo=null` produce `TypeError` real en el builder legacy, mismo escenario real de "estructura no soportada por el simulador legacy" — verificado `total_engine_rules=1`, `engine_summary.passed=1`), `temp:validate-flow` (línea de salida `status=passed` para la regla). 100% fixtures sintéticas serie `A` genérica (mismo criterio que `CrossSheetEqualsIntegrationTest`), `RefreshDatabase`, nunca `esalud_dev`.

**Tests focalizados BM-2.6B (33/33, 107 assertions)**: los 29 ya existentes + los 4 nuevos, sin cambios de comportamiento en los 29 originales.

**Regresión Serie A**: `Feature/Calibration`+`Feature/RuleEngine`+`Unit/RuleEngine`+`Unit/RemParser` — **669 tests** (665 baseline + 4 nuevos), **634 passed** (630 + 4 nuevos), **35 failed = exactamente los mismos 35 de siempre por nombre** (1 `RuleEngineIntegrationTest` + 30 `FunctionalRuleEngineCertificationTest` + 4 `RuleEngineServiceTest`, confirmado nombre por nombre contra el baseline documentado), **0 regresiones nuevas**. `Feature/REM`: **284/284 passed**, mismo OOM preexistente de PhpSpreadsheet en `SectionDetectorServiceRealFileRegressionTest` tras completar los 284 (ajeno, ya documentado, no investigado — probado también con `memory_limit` elevado en el proceso padre, sin efecto porque el runner de tests lanza el proceso hijo con su propio límite fijo; no se tocó `phpunit.xml` ni ninguna config de memoria).

**No se tocó producción, no se ejecutó SSH/deploy/Docker/migrate/seed/cache clear, no se creó ninguna estructura/regla/binding/cell-data BM real.**

**Commit y push ejecutados, autorizados explícitamente por el usuario en este mismo turno.** Staging explícito de exactamente los 11 archivos (nunca `git add .`/`-A`/`-f`), verificado `git diff --cached --name-only` = 11 antes de comitear, inspección completa de `git diff --cached` (confirmado: `CrossSheetEqualsEvaluator::supports()` solo responde `cross_sheet_equals`; los 3 entrypoints registran el evaluador; `RuleEngineService` resuelve `_target_rows` exclusivamente para `cross_sheet_equals` y solo desde `$grouped` del mismo `uploadId` — `RemData::where('rem_upload_id', $uploadId)`, nunca cruza uploads; `SectionCalibrationMatrixService` conecta `classifyCrossSheetDependency()` sin alterar `determineCoverage()` para Serie A; cero hardcodes `BM18`/`BM18A` fuera de comentarios/tests). Commit `af3bfe9`, push fast-forward `1915040..af3bfe9`. Post-push: `HEAD=origin/main=af3bfe9`, ahead/behind 0/0, baseline Serie A y BM=0 reconfirmados en vivo contra `esalud_dev`.

**Archivos incluidos en el commit `af3bfe9` (los 11 autorizados):**
```
MODIFICADOS:
 backend/app/Domain/RuleEngine/Jobs/ValidateWithEngineJob.php
 backend/app/Console/Commands/RuleValidateCommand.php
 backend/app/Domain/RuleEngine/Testing/ComparisonReport.php
 backend/app/Domain/RuleEngine/Services/RuleEngineService.php
 backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php

NUEVOS:
 backend/app/Domain/RuleEngine/Evaluators/CrossSheetEqualsEvaluator.php
 backend/tests/Feature/RuleEngine/Services/CrossSheetEqualsIntegrationTest.php
 backend/tests/Feature/RuleEngine/Services/CrossSheetEqualsEntrypointRegistrationTest.php
 backend/tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceCrossSheetDependencyTest.php
 backend/tests/Unit/RuleEngine/Evaluators/CrossSheetEqualsEvaluatorBmRealFormulasTest.php
 backend/tests/Unit/RuleEngine/Evaluators/CrossSheetEqualsEvaluatorTest.php
```
**Fuera del commit, confirmados sin tocar**: `frontend/vite.config.ts`, `backend/app/Console/Commands/DiagCheckAdminPasswordCommand.php`, `backend/app/Console/Commands/DiagResetAdminPasswordCommand.php`, `backend/demo/`, y `TempValidateFlowCommand.php` (físicamente invisible para git por `.git/info/exclude` — ver discrepancia arriba; su registro del evaluador vive solo en el filesystem local, nunca en el índice de git ni en ningún commit). `CLAUDE.md` tampoco viajó en este commit (documental, se actualiza aparte, como siempre).

**Punto exacto de reanudación**: BM-2.6B queda **cerrada y respaldada en `origin/main`**. Próximo paso: **BM-3** (estructura/config real de BM), sin iniciar todavía — requiere autorización explícita del usuario en el turno correspondiente, como el resto del roadmap BM.

---

### CIERRE DE JORNADA — 2026-09-11, REM BM — CAMPAÑA BM-1 A BM-2.6B — leer esto primero, antes que todo lo demás de esta sección

**Veredicto: `BM26B_IMPLEMENTADA_LOCAL_NO_COMMITEADA_PENDIENTE_REVISION`.** Este checkpoint reemplaza como punto de reanudación inmediato al checkpoint "CALIBRACIÓN REM SERIE A 100% CERRADA" de más abajo (ese sigue vigente y correcto para el estado de Serie A — ver referencia rápida al final de este bloque — pero la campaña REM BM avanzó mucho más allá de donde terminaba el checkpoint anterior y es lo que hay que retomar mañana).

**REM Serie A — sin cambios, sigue certificada tal como el checkpoint de abajo la describe:**
- Estructura activa **67/v35** · `rem_rules` **798** (activas **751**) · `rem_rule_bindings` **1655** · **306/306** secciones aplicables · **22/22** hojas.
- Distribución canónica: `AUTO_MIGRATE` **304** · `NO_UTILIZADA` **75** · `NOT_CALIBRATABLE` **2** · `QUICK_CONFIRMATION` **0** · `MISMATCH` **0** · `NEW_SECTION` **0** · `FULL_REVALIDATION` **0**.
- Producción: **NO asumir sincronizada** con este avance local — no tocar sin autorización futura explícita (ver auditoría de sincronización pendiente, documentada en el checkpoint de abajo, todavía no iniciada).

**REM BM — avance acumulado, en orden:**

- **BM-1 (auditoría inicial)** — **CERRADA**.
- **BM-2 (generalización multi-serie del motor de calibración)** — **CERRADA Y RESPALDADA**. Commit `2c14cd33f75c50faa641fab0c52e1ad8068f2e8e`.
- **BM-2.5 (investigación técnica cross-hoja y encabezados, 100% read-only)** — **CERRADA**. Hallazgo real: **37 fórmulas** `BM18 → BM18A` en los 2 XLSM reales disponibles (`102302BM05.xlsm`, `102412BM05.xlsm`), idénticas estructuralmente entre ambos establecimientos. Ejemplos: `BM18!E14 = BM18A!D20` (referencia directa), `BM18!E22 = SUM(BM18A!D92:D113)` (rango). Encabezado de dos filas de `BM18` (filas 11-12): **soportado** por el mecanismo ya existente (`SectionDetectorService::findTrailingHeaderRows()`, diseñado en auditorías A19a/A30, verificado por trazado completo contra los valores reales). `BM18A`: **estructuralmente soportada** (2 SECCIONES internas, 14 grupos con sus propios TOTAL, patrón ya análogo a Serie A). Único gap real identificado: el parser de dependencias de fórmulas (`EnhancedCellScanner::extractDependencies()`) no reconocía referencias cross-hoja — leía el nombre de la hoja destino como si fuera una coordenada local (`"BM18A!D20"` → `["BM18","D20"]`, columna fantasma). Veredicto: `BM25_ENGINE_GAP_REQUIERE_BM26`.
- **BM-2.6A (detección/representación estructural de dependencias cross-hoja)** — **CERRADA Y RESPALDADA**. Commit `19150407c04118e1b3689000e92f789269d9476a`. `EnhancedCellScanner::extractDependencies()` corregido (referencias cross-hoja se extraen y remueven ANTES del regex same-sheet — el array `dependencias` legacy queda vacío en vez de corrupto para una celda 100% cross-hoja, comportamiento byte-idéntico para Serie A que no tiene ninguna). Campo aditivo nuevo `EnhancedCellDTO::$dependenciasCrossHoja` (`dependencias_cross_hoja` en `toArray()`) con representación estructurada `{hoja, tipo, celda|celda_inicio+celda_fin}` — soporta hoja con/sin comillas, celda/rango, con/sin marcadores `$`. Nuevo método autónomo `SectionCalibrationMatrixService::classifyCrossSheetDependency()` (constante `CROSS_SHEET_DEPENDENCY_REASON='cross_sheet_dependency'`) — en esa fase deliberadamente NO conectado a ningún flujo real todavía. **37/37** fórmulas reales de BM18 reconocidas correctamente, 0 dependencias fantasma (verificado contra el XLSM real, sin persistir nada). Serie A: regresión completa sin desviación.

**BM-2.6B (integración y ejecución de reglas cross-sheet) — IMPLEMENTADA LOCALMENTE Y TESTEADA, `NO COMMIT, NO PUSH`, pendiente de revisión final mañana antes de cerrarla.**

Diseño: nuevo `rule_type = 'cross_sheet_equals'` + nuevo evaluador `CrossSheetEqualsEvaluator implements RuleEvaluatorInterface` (mismo patrón pluggable que `SumEqualsEvaluator`/`RequiredAndLeParentEvaluator`, ninguno de los dos tocado). **Sin migración** — `rem_rules.rule_type` es `VARCHAR(50)` sin ENUM/CHECK, `rem_rules.config` es JSON sin schema físico. Config conceptual:
```json
{"sheet": "BM18", "section": "A", "source": {"cell": "E14"}, "target": {"sheet": "BM18A", "cell": "D20"}}
```
Para rango: `"target": {"sheet": "BM18A", "range": "D92:D113", "aggregation": "sum"}`. Semántica numérica: épsilon `0.00001`, duplicado deliberado de `SumEqualsEvaluator::validateNumericValue()` (mismo patrón de duplicación ya usado en el proyecto por `MismatchResolutionAuditService`, para no tocar el archivo certificado) — null/null→skip, target vacío→skip (nunca asumido 0), source vacío→tratado como 0 (igual que un componente ausente en sum_equals), no numérico→fail, numérico→comparación con épsilon. `RuleEngineService::execute()` resuelve `_target_rows` (filas de la hoja destino) desde el **mismo upload** SOLO cuando `rule_type==='cross_sheet_equals'` (mismo patrón ya usado para `_functional_rules`/`_cell_metadata`/`_section_bounds`) — **nunca cruza uploads** (verificado con test explícito). `SectionCalibrationMatrixService::buildMatrix()` ahora conecta `classifyCrossSheetDependency()` al flujo real: una fila cuya única fuente de valor en una columna-formula es cross-hoja queda con `cobertura='cross_sheet_dependency'` (nueva clave `cross_sheet_dependency` en el array de fila), nunca cae en `determineCoverage()` local ni genera columnas fantasma.

**Tests nuevos: 29/29 passing, 95 assertions** — unitarios de `CrossSheetEqualsEvaluator` (simple==simple, simple==SUM(rango), 0/0, null/null, vacío/0, strings numéricas, hoja/celda inexistente, rango vacío/inválido, config inválida), integración real vía `RuleEngineService::execute()` completo (incluye confirmación explícita de que nunca lee `_target_rows` de otro upload), integración con `SectionCalibrationMatrixService::buildMatrix()`, y **fórmulas BM reales** (`BM18!E14=BM18A!D20`, `BM18!E22=SUM(BM18A!D92:D113)`, parseadas con el parser real de BM-2.6A, evaluadas con `calc_value` real del XLSM — 0 en ambos archivos de muestra — PASS correcto, variantes negativas FAIL correcto).

**Regresión Serie A tras BM-2.6B**: baseline (67/v35, 798/751/1655, 306/306, 22/22, 304/75/2/0/0/0/0) sin desviación. `Feature/Calibration`+`Feature/RuleEngine`+`Unit/RuleEngine`+`Unit/RemParser`: 665 tests, 630 passed, **35 failed = exactamente el baseline preexistente ya documentado**, 0 regresiones nuevas. `Feature/REM`: 284/284 passed (mismo OOM preexistente de PhpSpreadsheet en el test pesado, ajeno, ya documentado en checkpoints anteriores).

**⚠️ Por qué BM-2.6B NO está cerrada todavía — punto pendiente explícito, primer paso de mañana:** el evaluador/capacidad quedó implementado y probado, pero **deliberadamente NO se registró todavía** en ninguno de los puntos reales donde se instancian los evaluadores del motor (`$engine->registerEvaluator(new ...)`), es decir:
- `app/Domain/RuleEngine/Jobs/ValidateWithEngineJob.php`
- `app/Console/Commands/RuleValidateCommand.php`
- `app/Console/Commands/TempValidateFlowCommand.php`
- `app/Domain/RuleEngine/Testing/ComparisonReport.php`
- (revisar si existe cualquier otro registry real no listado aquí)

Sin ese registro, `cross_sheet_equals` no es todavía ejecutable en ningún pipeline real (los tests lo registran manualmente, igual que cualquier test de evaluador existente) — hoy esto es intencional y correcto (no se creó ninguna regla BM real que lo necesite), pero **antes de considerar BM-2.6B cerrada** hay que decidir explícitamente, con auditoría previa (no conectar a ciegas), en cuáles de esos registries corresponde agregar `CrossSheetEqualsEvaluator` para que el `rule_type` sea realmente utilizable cuando en el futuro (BM-6) existan reglas BM reales.

**Archivos exactos de BM-2.6B, confirmados con `git status --short` al cierre de esta jornada (verificar de nuevo mañana antes de nada — no confiar en esta lista si el estado real difiere):**
```
MODIFICADOS:
 M backend/app/Domain/RuleEngine/Services/RuleEngineService.php
 M backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php

NUEVOS (untracked):
?? backend/app/Domain/RuleEngine/Evaluators/CrossSheetEqualsEvaluator.php
?? backend/tests/Feature/RuleEngine/Services/CrossSheetEqualsIntegrationTest.php
?? backend/tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceCrossSheetDependencyTest.php
?? backend/tests/Unit/RuleEngine/Evaluators/CrossSheetEqualsEvaluatorBmRealFormulasTest.php
?? backend/tests/Unit/RuleEngine/Evaluators/CrossSheetEqualsEvaluatorTest.php
```
**Históricos excluidos, sin cambios, nunca mezclar con BM-2.6B:** `frontend/vite.config.ts` (modificado), `backend/app/Console/Commands/DiagCheckAdminPasswordCommand.php` y `DiagResetAdminPasswordCommand.php` (untracked), `backend/demo/` (untracked).

**Estado BM en BD — debe seguir exactamente así mañana, reconfirmar antes de cualquier cosa:** `rem_template_structures` (serie=BM) = **0** · `rem_rule_bindings` (serie=BM) = **0** · `rem_templates.config['sheets']` (id=2, BM) = **[]**. No se ha creado estructura BM, bindings BM, calibración BM, ni cell-data BM persistente en ningún momento de toda la campaña BM-1→BM-2.6B.

**Producción**: no fue tocada en ningún punto de la campaña BM. Prohibiciones vigentes sin cambio: no SSH, no deploy, no Docker, no `migrate`, no `seed`, no cache clear, no sincronización local→producción, no copiar BD, no modificar `uploads`/`rem_data` reales.

**Secuencia exacta para mañana — RETOMAR EN: "BM-2.6B — REVISIÓN FINAL / REGISTRO DEL EVALUADOR":**
1. Leer este `CLAUDE.md` completo antes de tocar nada.
2. `git status --short` — confirmar que coincide con la lista de arriba (si difiere: **STOP y reportar**, no asumir).
3. Confirmar `HEAD` = `19150407c04118e1b3689000e92f789269d9476a` (último commit real, de BM-2.6A — BM-2.6B sigue sin commitear).
4. Distinguir con claridad los archivos BM-2.6B (lista de arriba) de los 4 históricos excluidos.
5. Auditar TODOS los registries/entrypoints reales de `RuleEvaluatorInterface` (los 4 listados arriba + cualquier otro que aparezca en una búsqueda fresca — no confiar ciegamente en que la lista de hoy siga completa).
6. Determinar, con criterio explícito y documentado (no a ciegas), en cuáles corresponde registrar `CrossSheetEqualsEvaluator`.
7. Completar únicamente lo estrictamente necesario para esa decisión.
8. Ejecutar los tests focalizados de BM-2.6B (29 actuales + los que se agreguen).
9. Regresión completa de Serie A (mismas suites y baseline de arriba) — cualquier desviación nueva bloquea el cierre.
10. Gate pre-commit (mismo patrón ya usado en BM-2/BM-2.6A: separar archivos BM-2.6B de históricos, `git diff --check`, buscar hardcodes/rutas rotas, confirmar baseline).
11. **Solo después** de todo lo anterior, pedir autorización explícita para commit/push de BM-2.6B.

**No comenzar BM-3 (estructura/config real de BM) hasta cerrar formalmente BM-2.6B.** Roadmap BM restante, sin fecha, no iniciado: BM-3 (estructura/config) → BM-4 (cell scan) → BM-5 (patrones) → BM-6 (reglas) → BM-7 (calibración funcional) → BM-8 (auditoría canónica) → BM-9 (certificación).

---

### CIERRE — 2026-09-11, CALIBRACIÓN REM SERIE A 100% CERRADA (mecanismo `human_review`/full-review) — leer esto primero, antes que todo lo de abajo

**Veredicto: `REM_SERIE_A_CALIBRACION_100_POR_CIENTO_CERRADA`.** Cierra la cadena de checkpoints del mismo día (microauditoría A30/C → escaneo A05/V → diseño e implementación del mecanismo `human_review` → resolución real de A30/C → auditoría canónica completa → cierre de los 10 `QUICK_CONFIRMATION` de A11a). Reemplaza como estado vigente de calibración todo lo dicho antes sobre A05/V y A30/C pendientes.

**Gap de diseño que motivó todo esto (ya explicado en el checkpoint de microauditoría, resumido aquí)**: `buildStructureCalibrationSummary()` (el agregado que alimenta el dashboard) decide "completada" con la reconciliación v1 (solo compara conjunto de filas) — puede mostrar 100% aunque el fingerprint canónico v2 de un patrón siga desalineado con la estructura vigente. Por eso el cierre real de hoy se verificó con **`PatternMigrationScanner::scanSection()`/`scanAllSections()`** (clasificación canónica, no el agregado), tal como exigió el usuario en cada paso.

**Evidencia del cierre — auditoría canónica final, 381 combinaciones sección/patrón de la estructura activa 67/v35:**

| Categoría | Cantidad |
|---|---|
| `AUTO_MIGRATE` | **304** |
| `NO_UTILIZADA` | 75 |
| `NOT_CALIBRATABLE` | 2 |
| `QUICK_CONFIRMATION` | **0** |
| `MISMATCH` | **0** |
| `NEW_SECTION` | **0** |
| `FULL_REVALIDATION` | **0** |

Agregado (`buildStructureCalibrationSummary()`, ya no como única fuente sino como confirmación adicional): **306/306 secciones aplicables, 100%, 22/22 hojas completas**.

**Qué se cerró hoy, en orden:**
1. **A05/V** — sección nueva (`NEW_SECTION`, cero cell-data), escaneada vía `rem:scan-cells A05 V` (990 celdas reales), respondida `debe_registrar_cero` vía el flujo ordinario (`saveQuestions()`). Resultado: `AUTO_MIGRATE`, `agrees=true`.
2. **A30/C `pattern_id=1`** — único `MISMATCH` real de la Serie A, clasificado `human_review` desde el 2026-08-26 (columnas J/K/L nuevas — bloque "Modalidad", Nivel Primario — genuinamente editables, sin evidencia histórica de captura, sin decisión formal contra la estructura activa). **No existía ningún mecanismo del sistema capaz de resolver esto formalmente** (`applyQuickRevalidation()` está deliberadamente bloqueado para `human_review` por el controlador — verificado en código, no supuesto). Se diseñó, implementó, probó y ejecutó un mecanismo nuevo (`resolveHumanReviewPattern()` + endpoint `full-review`, detalle abajo). Baseline funcional autorizado por el usuario: `debe_registrar_cero` para las 5 preguntas del patrón, **J/K/L permanecen genuinamente editables, sin bloquear, sin marcar no-aplicables** — decisión explícitamente revisable después por Estadística APS. Resultado: `AUTO_MIGRATE`, `agrees=true`.
3. **10 `QUICK_CONFIRMATION` de la hoja A11a** (secciones A, C, E, F, G, H, I, J, K, N, `pattern_id=1` cada una) — patrones legacy (nunca migrados a fingerprint v2) cuyas filas coinciden exactamente con la versión histórica pero la estructura cambió desde entonces. **Mecanismo distinto de `human_review`**: no requieren tag de auditoría (`MismatchResolutionAuditService::getTag()` da `null` para los 10, correcto — ese gate es exclusivo de la categoría `MISMATCH`, no de `QUICK_CONFIRMATION`). Confirmados uno por uno vía `confirmQuickRevalidation()`/`applyQuickRevalidation()` (mecanismo ya existente, sin cambios de código) — la respuesta funcional original (Francisco Arcos, 2026-08-06) queda intacta, solo se actualiza metadata técnica. Verificado individualmente con una instancia nueva del scanner antes de continuar con la siguiente. Resultado: los 10 → `AUTO_MIGRATE`, `agrees=true`.

**Mecanismo nuevo `human_review`/full-review — general, reutilizable para BM/BS/D/P:**
- `FunctionalRuleService::resolveHumanReviewPattern()` — reemplaza la decisión funcional del patrón (a diferencia de `applyQuickRevalidation()`, que nunca toca `response`/`reviewed_by`/`reviewed_at`) y escribe junto con ella el fingerprint/filas/versión de estructura **actuales**, calculados siempre por el controlador (nunca confía en el cliente). Preserva la decisión anterior en `_questions_history` (`fingerprint_before`/`fingerprint_after`, `structure_version_before`/`after`, tipo `human_review_resolution`). `mismatch-resolution-audit.json` nunca se toca desde aquí.
- `CalibrationViewController::confirmHumanReviewResolution()` — `POST .../patterns/{patternId}/mismatch-resolution/full-review`. Exige categoría en vivo `MISMATCH` + tag de auditoría `human_review` exacto (rechaza `safe_reconfirm`/`structural_row_exclusion`/`structural_review`/sin tag, cada uno con su mensaje), identidad histórica resuelta, y el conjunto de preguntas enviado debe coincidir **exacto** (ni de más ni de menos) con las existentes del patrón — sin eso, 409/422, sin escribir nada.
- **`PROTECTED_V2_FIELDS` de `saveQuestions()` permanece exactamente igual, sin relajar** — el mecanismo nuevo es un tercer método separado, no una excepción al guardado ordinario.
- **`reconcileLiveCanonical()` sigue inactivo** — cero llamadas fuera de tests, confirmado de nuevo hoy.
- Frontend: `FunctionalQuestionsPanel.tsx` detecta por sí mismo (vía `migration-plan` + `mismatch-resolution` por patrón, ambas consultas ya existentes en el proyecto) qué patrones están en `MISMATCH`+`human_review` y muestra un panel/botón dedicado ("Guardar revisión funcional completa"), sin tocar `saveMutation`/`handleSave`/`markPatternReviewed`/`markSectionReviewed` — la calibración normal de cualquier otra sección sigue exactamente igual.
- Tests nuevos: `backend/tests/Feature/RuleEngine/HumanReviewResolutionTest.php`, 16/16 (caso feliz, rechazo de `safe_reconfirm`/`structural_review`/sin tag, atomicidad, aislamiento de otros `pattern_id`, reclasificación `MISMATCH→AUTO_MIGRATE` solo tras resolución correcta).

**Baseline reconfirmado sin cambios**: `rem_rules=798` (751 activas), `rem_rule_bindings=1655`, estructura activa `67/v35`.

**Regresión**: `HumanReviewResolutionTest` + suite completa de mismatch/quick-revalidation/scanner/reconciliation/matrix — 164/164 (a lo largo de las distintas pasadas del día). `Feature/RuleEngine`+`Unit/RuleEngine`+`Feature/Config` completos: 527 tests, 487 passed, **35 failed byte-idénticos al baseline ya documentado** (mismos tests, mismas líneas, mismos mensajes — confirmado además comparando contra un `git stash` de los archivos backend modificados, mismo resultado exacto con o sin el cambio), 5 skipped (Windows POSIX). Frontend: `tsc --noEmit`/`eslint` limpios, `npm run build` exitoso.

**Cierre Git de esta campaña**: commit `69431c7230629b1db8a756df7b0f868b03557c4e` (`69431c7`, `feat(rem): complete human review workflow and close REM A calibration`) — `main` = `origin/main`, ahead/behind = 0/0, ya **pusheado**. `reglas-funcionales.json`/`cell-data/` (gitignorados) no viajaron con el push — su cierre queda documentado aquí, no versionado; ver sección D del pendiente de sincronización más abajo.

**Pendientes que siguen exactamente igual, sin resolver hoy** (no bloquean el cierre de calibración): reglas `229`/`230` de `A09/I`, `A30/D`, `A25/B` 354, las 75 secciones `no_utilizada`, las 14 reglas `DUPLICATE`, las reglas `130`/`133`, todos los gaps de diseño ya listados en "Prohibiciones vigentes" más abajo.

---

### ESTADO PRODUCCIÓN vs. LOCAL/REPOSITORIO — leer antes de asumir nada sobre el servidor

**LOCAL/REPOSITORIO (lo único que este cierre certifica):**
- REM Serie A queda calibrada/certificada **canónicamente** al 100% — evidencia de `PatternMigrationScanner`, no solo del agregado.
- Estructura **local** activa: **`id=67, version=35` (67/v35)**.
- 381 combinaciones sección/patrón auditadas canónicamente contra esa estructura.
- 306/306 secciones aplicables · 22/22 hojas · `QUICK_CONFIRMATION=0` · `MISMATCH=0` · `NEW_SECTION=0` · `FULL_REVALIDATION=0`.
- A05/V resuelta · A30/C `human_review` resuelto · A11a 10/10 `QUICK_CONFIRMATION` resueltas.
- Mecanismo nuevo `resolveHumanReviewPattern()`/endpoint `full-review` implementado, probado, commiteado y pusheado.

**PRODUCCIÓN — NO se asume sincronizada, NO se declara que posee estos cambios:**
- Última estructura Serie A **conocida** en producción (checkpoint 2026-09-03/07, no reverificada en esta sesión): **`id=19, version=33` (19/v33)**.
- **Los IDs de estructura local (67) y productivo (19) NO tienen que hacerse idénticos nunca** — son entornos que evolucionaron por separado (append-only, cada uno con su propio historial de versiones). La sincronización que corresponda hacer en el futuro es **funcional/canónica** (¿producción alcanza la misma clasificación `AUTO_MIGRATE` en sus propias 381 combinaciones, con su propia estructura activa?), **nunca** copiar/igualar IDs de fila de `rem_template_structures` entre entornos.
- Código: el commit `69431c7` (este cierre) **no ha sido verificado como desplegado en producción** — el último commit confirmado en el servidor en checkpoints previos fue `0fc193c` (2026-09-03), muy anterior a toda esta campaña de `human_review`. **No reconfirmado en esta sesión** — cero conexión al servidor en todo este cierre.
- `reglas-funcionales.json`/`cell-data/` locales (el estado real de A05/V, A30/C, A11a recién cerrados) **nunca viajan por git** — están gitignorados. Aunque el código llegara a producción vía `git pull`/deploy, **eso por sí solo NO deja producción funcionalmente equivalente** a esta calibración: los artefactos de certificación tendrían que transferirse aparte (mismo patrón ya usado el 2026-09-03 con `certification-checksums.sha256`, 392 archivos).

**PENDIENTE POSTERIOR — AUDITORÍA Y SINCRONIZACIÓN CONTROLADA LOCAL → PRODUCCIÓN** (no iniciada, sin fecha, no autorizada todavía):

- **A) Código**: commit real desplegado en producción vs. `HEAD`/`origin/main` actual (`69431c7`) — diferencias reales, migraciones pendientes si existieran, backend y frontend, específicamente si el mecanismo `human_review`/`full-review` (rutas, controlador, servicio, componente) llegó o no.
- **B) Estructura/calibración**: estructura activa productiva (19/v33) vs. estructura local certificada (67/v35) — reglas funcionales, fingerprints canónicos, estado real de A05/V, A30/C, A11a, `cell-data`, historial de mismatch/revalidación, artefactos de certificación.
- **C) Baseline**: comparar `rem_rules`, activas, `rem_rule_bindings`, estructuras, `uploads`/`rem_data` entre ambos entornos — **sin modificar nada inicialmente**.
- **D) Artefactos gitignorados**: recordar explícitamente que Git **no transporta** `reglas-funcionales.json`, `cell-data/`, ni otros artefactos privados de certificación/calibración — un `git pull`/deploy **no basta** por sí solo para dejar producción funcionalmente equivalente a local.
- **E) Seguridad del proceso**: esa futura sincronización **debe empezar con una auditoría 100% READ-ONLY** del servidor. Prohibido de antemano, sin excepción: copiar la BD completa local sobre producción, reemplazar IDs productivos por IDs locales, ejecutar seeders a ciegas, recalibrar producción desde cero, borrar datos productivos, sobrescribir `uploads`/`rem_data`, ejecutar `reconcileLiveCanonical()`, o limpiar caché sin necesidad demostrada.

**Secuencia obligatoria del próximo checkpoint operativo de esta fase (documentada, no ejecutada):**
1. Cerrar Git local/repo — **ya hecho** (`69431c7`, pusheado).
2. Auditar producción READ-ONLY.
3. Determinar el delta exacto: código + datos + artefactos.
4. Preparar plan de sincronización.
5. Respaldar (BD + `storage/app/private`) antes de cualquier escritura.
6. Desplegar únicamente con autorización explícita.
7. Verificar equivalencia canónica (no de IDs) tras desplegar.
8. Certificar producción.
9. Recién después, continuar con despliegues futuros (incluida cualquier serie nueva BM/BS/D/P).

**No se ejecutó ningún paso de esta secuencia en este cierre — cero conexión al servidor.** Queda listada aquí exclusivamente como pendiente documentado.

---

**Próximo objetivo — auditoría inicial READ-ONLY de REM BM** (decisión de roadmap ya registrada el 2026-09-04, ver checkpoint más abajo): entender qué soporte existe hoy en el repositorio para la Serie BM antes de implementar nada. La calibración de Serie A ya no es un prerrequisito pendiente — queda cerrada. **Nota de secuencia**: la auditoría de sincronización producción (arriba) y la auditoría de REM BM son independientes entre sí — ninguna bloquea a la otra; cuál se retoma primero es decisión del usuario en el próximo turno.

### MICROAUDITORÍA — 2026-09-11, `A30/C pattern_id=1` — MISMATCH TÉCNICO CONFIRMADO VIGENTE

**Veredicto: `A30_C_MISMATCH_TECNICO_VIGENTE`.** Microauditoría 100% read-only (sin `Cache::forget`, sin recalibrar, sin tocar código/reglas/bindings/estructura/artefactos de certificación/`reglas-funcionales.json`/`cell-data`/BD/producción — únicas operaciones: `SELECT` y cálculo en memoria vía `buildPatternMatrix()`, que no escribe nada) que cierra la duda dejada abierta por la reconexión de contexto previa (misma fecha) sobre si `A30/C pattern_id=1` seguía técnicamente pendiente o si `CLAUDE.md` había quedado desactualizado. Resultado: **`CLAUDE.md` tenía razón en el fondo — `A30/C pattern_id=1` sigue con un MISMATCH técnico real, sin resolver.** La sospecha de la reconexión previa (de que A30 podría estar cerrada sin documentarse) queda descartada con evidencia.

**Por qué el resumen agregado (`rem:calibration_summary` / dashboard) puede mostrar `A30: 7/7, 100%, completada` y la sección seguir pendiente — no son cifras contradictorias, son dos chequeos distintos, ambos ya existentes en el código:**
- `SectionCalibrationMatrixService::buildStructureCalibrationSummary()` decide "completada" usando `$matrix['reconciliation']['effective_section_reviewed']`, que viene de `PatternReconciliationService::reconcileLive()` (**v1**) — compara **únicamente el conjunto de filas** del patrón. Para `A30/C pattern_id=1` las filas (81–89, 92, 93) no cambiaron → v1 marca "reviewed" → el agregado cuenta la sección como completada.
- El propio `buildPatternMatrix()` calcula además `calibration_applicability` (chequeo más completo, incluye columnas/editabilidad) — para esta misma sección, calculado ahora en vivo contra 67/v35: `status: "requires_calibration"`, `reason: "Existen celdas editables (ej. B81) que requieren calibración funcional."`, `criteria.no_editable_cells: false`. **El agregado (`rem:calibration_summary`) no consume este campo**, solo el de reconciliación v1.
- **`COBERTURA FUNCIONAL 7/7 ≠ CIERRE TÉCNICO DEL PATRÓN.`** Las 6 preguntas del patrón 1 (`patron_1_empty`, `patron_1_all_est`, `patron_1_exceptions`, `patron_1_inconsistency`, `patron_1_formula_confirmation`, y la de aplicabilidad) están `review_status: reviewed` porque responden a la estructura histórica (52/v24); nunca se volvieron a plantear contra las columnas nuevas de la estructura activa (67/v35).

**Evidencia exacta verificada:**

| Campo | Almacenado (respuesta funcional — Francisco Arcos, 2026-08-10, revalidada 2026-08-18) | Actual (calculado en vivo contra 67/v35, 2026-09-11) |
|---|---|---|
| `pattern_fingerprint` (v2, canónico) | `fpv2_5c40135e1604294b` | `fpv2_c07217a0385bd413` — **distinto** |
| `row_fingerprint` (v1, solo filas) | (no almacenado en la pregunta) | `rowset_7190d0f59749249f` — idéntico al histórico, por eso v1 dice "reviewed" |
| `structure_version` de la respuesta | `"52"` | estructura activa **67/v35** |
| Filas del patrón (`pattern_rows`) | 81,82,83,84,85,86,87,88,89,92,93 | idénticas |

- **Columnas nuevas**: `J/K/L` (bloque "Modalidad" — J=Institucional, K=Compra de Servicio/Sistema, L=Compra de Servicio/Extrasistema, Nivel Primario) existen en la estructura activa 67/v35 (`esTotal:false`, `esControlOculto:false`, confirmado leyendo el JSON de la estructura) y **no existían** en la estructura histórica 52/v24 (saltaba de I a M). Verificado también en `cell-data/A30-C.json`: `J81/K81/L81/J93/K93/L93` son **genuinamente editables** (`es_editable:true`, `esta_bloqueada:false`, `es_formula:false`, `formula:null`) — no son fórmulas, totales, subtotales ni columnas auxiliares.
- `mismatch-resolution-audit.json`, entrada `A30_C_rowset_7190d0f59749249f` (`pattern_id: 1`), clasificada **`human_review`** (2026-08-26, "Administrador Esalud") — a diferencia de sus dos vecinos auditados el mismo día (`A30/C pattern_id=2` y `A30/A pattern_id=1`, ambos `safe_reconfirm`, con evidencia de que sus columnas J/K/L equivalentes están bloqueadas o de que no hubo cambio de columnas respectivamente). Cita textual del registro: *"Se requiere decisión funcional explícita de Estadística APS sobre si estas 3 columnas deben capturarse para Nivel Primario."* Se revisaron 138 cargas históricas reales (1518 filas, rango 81–93): la clave J/K/L está ausente del 100% de los valores — no existe evidencia histórica de la que heredar automáticamente una respuesta. **Ningún registro posterior (en `reglas-funcionales.json`, `mismatch-resolution-audit.json` ni `cell-data/`) resuelve esta clasificación.**

**Gap de diseño registrado — solo documentado, NO corregir código sin autorización explícita aparte:** `SectionCalibrationMatrixService::buildStructureCalibrationSummary()` (el método detrás de `rem:calibration_summary` y del resumen agregado del dashboard) decide completitud de sección con `effective_section_reviewed` (reconciliación v1, basada solo en el conjunto de filas) en vez de con `calibration_applicability`/el fingerprint canónico v2 (que sí detecta cambios de columna/editabilidad). Efecto: una sección puede reportarse "100% completada" en el agregado mientras el propio sistema, en el mismo cálculo, ya sabe vía `calibration_applicability` que requiere calibración. Ya ocurrió con `A30/C pattern_id=1`. No se investigó si existen otras secciones con el mismo gap — el alcance de esta microauditoría fue exclusivamente `A30/C`.

**Corrección de continuidad — no declarar la calibración funcional de Serie A como completamente cerrada mientras esto siga así.** Los checkpoints "2026-09-04, DECISIÓN DE ROADMAP" y "2026-09-03, ACTUALIZACIÓN FINAL DEL DÍA" (ambos más abajo) ya listaban `A30/C pattern_id=1` como congelado pendiente de Estadística APS en su punto correspondiente — ahí siguen correctos y no se reescriben. Lo que se corrige aquí es la lectura suelta del **agregado "7/7 / 100% / completada"** de `A30` que esos mismos checkpoints citan de pasada como si fuera evidencia de cierre: no lo es (ver gap de diseño arriba). El `A30 6/7` que muestra el checkpoint de incidente 2026-09-07 (producción, estructura 19/v33) y el `A30 7/7` observado en local (estructura 67/v35) son el mismo indicador agregado con el mismo gap en dos entornos distintos — no una contradicción entre ellos, ni evidencia de que uno de los dos esté "más cerrado". Mientras `A30/C pattern_id=1` mantenga su clasificación `human_review` sin decisión de Estadística APS, la calibración funcional de Serie A **no debe describirse como "terminada" sin esta salvedad** — sigue teniendo 1 pendiente técnico real además de `A05/V`.

**Contadores corregidos en esta pasada** (recontados en vivo 2026-09-11, sin relación causal con el hallazgo de A30 — ver también el baseline al inicio del archivo): secciones `NO_UTILIZADA` **56→75** (5 hojas: A21 15, A24 14, A25 20, A30AR 15, A34 11 — el valor correcto ya aparecía desde el checkpoint 2026-09-04 más abajo; el `56` de "Pendientes conocidos" y de "Prohibiciones vigentes / C" había quedado desactualizado y ya se corrigió ahí); `uploads` **146→152**; `rem_data` **403.247→420.427** (cargas de prueba locales posteriores al cierre de REM A — reglas, bindings, estructura activa y certificación permanecen idénticos, ver baseline).

**`A05/V` no fue objeto de esta microauditoría** — se mantiene como pendiente real independiente, sin cambios, tal como ya documentado.

**Nada de esta microauditoría ni de esta actualización documental tocó código/BD/reglas/bindings/estructura/`reglas-funcionales.json`/`cell-data`/`mismatch-resolution-audit.json`/caché/tests/producción — 100% lectura + esta edición de `CLAUDE.md`.**

### CIERRE DE INCIDENTE — 2026-09-07, `rem:calibration_summary` / permisos `certificacion/cell-data/` (leer esto primero, antes que el checkpoint de 2026-09-04 de abajo)

**Veredicto: `CALIBRATION_SUMMARY_INCIDENT_CLOSED`.** Investigación 100% read-only en producción (excepto una única invalidación controlada y autorizada de la clave `rem:calibration_summary`, sin ningún otro efecto). Sin cambios de código, sin deploy, sin `chmod`/`chown` nuevos, sin migraciones/seeders, sin tocar reglas/bindings/estructura/archivos certificados/`reglas-funcionales.json`/`cell-data`.

**Causa raíz confirmada**: `storage/app/private/certificacion/cell-data/` se creó originalmente con el modo de fábrica de Flysystem para directorios "privados" cuando `config/filesystems.php` no declaraba `permissions` — `0700`, solo el propietario (`orion`, UID 1000, quien ejecutó el primer escaneo desde consola). PHP-FPM corre como `www-data` — ni owner ni miembro del grupo del directorio en ese estado — por lo que no podía leer/listar su contenido. `computeStructureCalibrationSummary()` calculaba, **correctamente dado ese filesystem roto**, "falta evidencia de celdas escaneadas" para las secciones afectadas (A04, A05, A11, A11a, A28, A32, entre otras). El valor guardado en `rem:calibration_summary` no era una entrada corrupta o desincronizada — era el resultado fiel de un cálculo que ya nacía incorrecto por el filesystem, no un defecto del mecanismo de caché.

**Qué se descartó explícitamente, con evidencia, no por suposición:**
- **Condición de carrera de invalidación de caché**: `StructureApprovalService::activate()` (el único punto de invalidación que corre dentro de una transacción DB real, incluido el flujo completo de `CertifiedStructurePromotionService::commit()`) ya usa `DB::afterCommit()` en vez de `Cache::forget()` directo desde antes de esta semana — el callback solo se ejecuta cuando la transacción MÁS EXTERNA hace commit, nunca antes. Confirmado leyendo el código fuente completo de ambos archivos dos veces en la misma sesión, sin ningún cambio pendiente sobre este mecanismo. **Decisión técnica: se mantiene tal cual, sin modificar.**
- Los otros 3 puntos que invalidan la misma clave (`FunctionalRuleService::saveQuestions()`/`applyQuickRevalidation()`, `RemSheetUsageStatusService::setStatus()`) se auditaron y **no son vulnerables al mismo patrón**: los dos primeros escriben a un archivo (`reglas-funcionales.json`), no a una transacción DB abierta; el tercero no usa `DB::transaction()` en ningún punto de su cadena y solo se invoca vía comando artisan manual.
- Otro proceso/contenedor escribiendo en la misma tabla `cache`, otra instalación de ATHENEA apuntando a la misma BD `atenea`, divergencia de configuración/OPcache entre PHP-FPM y CLI, filesystem distinto entre el endpoint web y el cálculo de verificación — los 6 se descartaron con evidencia directa (inventario de contenedores del host, permisos/config leídos en vivo, un único par `esalud-backend`/`esalud-worker` activo).

**Reproducción controlada en producción (2026-09-07), ciclo completo verificado:**
1. Estado previo de `rem:calibration_summary` leído (ya correcto en ese momento).
2. `Cache::forget('rem:calibration_summary')` — únicamente esa clave.
3. Recomputo real vía `SectionCalibrationMatrixService::buildStructureCalibrationSummary()` (el mismo método público que invoca `CalibrationViewController::calibrationSummary()`, línea 42 — mismo `Cache::remember()` real).
4. Nueva clave leída y deserializada inmediatamente.
5. Comparada contra un cálculo fresco independiente (`computeStructureCalibrationSummary()` vía reflection, bypass total de caché) del mismo instante.

Resultado: **las 7 hojas verificadas (A04, A05, A11, A11a, A28, A30, A32) coinciden exactamente** entre la clave nueva y el cálculo fresco — A04 23/23, A05 25/26, A11 15/15, A11a 14/14, A28 20/20, A30 6/7, A32 19/19. `A05/V` y `A30/D` siguen como las únicas 2 secciones pendientes reales de Serie A, ya documentadas y congeladas (pendientes de decisión de Estadística APS, no tocadas).

**Persistencia de la corrección de permisos, verificada en el host (no solo dentro del contenedor)**: `certificacion/cell-data/` es un bind-mount real (`docker-compose.yml`, mapeado a `/var/www/esalud/backend/storage/app/private/certificacion` en el host, compartido por `esalud-backend` y `esalud-worker`), con permisos actuales `orion:www-data 775` (directorios) / `664` (archivos) confirmados directamente en el filesystem del host — no en una capa efímera del contenedor. Esta ruta está gitignoreada (`backend/storage/app/private/.gitignore`), nunca la toca un `git pull`/build/deploy. **Un rebuild o redeploy futuro no puede volver a dejar esta carpeta en `0700`** — el `chown` del Dockerfile solo afecta la capa interna de la imagen, sombreada en runtime por el bind-mount real.

**Impacto real**: exclusivamente visual/de resumen agregado del dashboard. `rem:calibration_summary` nunca es entrada de ninguna escritura — no gatea ni bloquea el guardado de respuestas de calibración (`FunctionalRuleService::saveQuestions()` escribe directo a `reglas-funcionales.json`, sin pasar por esta caché) ni afecta la vista de sección individual (`buildPatternMatrix()`, no cacheada, siempre fresca). **Sin pérdida ni corrupción de datos en ningún momento**: reglas, bindings, estructura activa (id=19/v33) y archivos certificados permanecieron intactos durante toda la investigación y el cierre — confirmado en cada verificación de esta semana.

**Estado final**: incidente cerrado. **No se requiere deploy adicional para cerrar esto.** El fix de código ya preparado en LOCAL (`CellDataStorageService::saveCellData()`, con `setVisibility()` explícito para modo exacto `0660`/`0770` inmune al umask, tests incluidos) queda clasificado como **hardening/defensa en profundidad — no como corrección urgente**: el estado actual ya es correcto, persistente y resistente a rebuilds/redeploys incluso sin ese fix desplegado. Pendiente de decisión propia sobre cuándo desplegarlo, sin relación con el cierre de este incidente.

### CIERRE DE JORNADA — 2026-09-04, DECISIÓN DE ROADMAP (leer esto primero, antes que todo lo de abajo)

Este checkpoint reemplaza como fuente de verdad el punto 5 y el punto 7 del checkpoint "2026-09-03, ACTUALIZACIÓN FINAL DEL DÍA" (más abajo) — esos puntos contenían una afirmación incorrecta ("A09 pendiente, continuar calibración hoja por hoja") ya corregida hoy. El resto de ese checkpoint (bug F5/logout, despliegue, promoción certificada, validación en producción) permanece vigente y verificado, sin cambios.

**1) REM Serie A — hechos comprobados hoy (evidencia, no inferencia):**

- **Etapa de calibración de desarrollo de Serie A: CERRADA.** No hay desarrollo de calibración pendiente en Serie A.
- Cobertura medida en vivo contra la estructura activa real (67/v35) vía `SectionCalibrationMatrixService::buildStructureCalibrationSummary()`: **302/304 secciones aplicables (99%)**, **20/22 hojas al 100%**.
- **A09 = 14/14 secciones, 100%, `completada`.** No hay que volver a A09 — no es trabajo pendiente de ningún tipo.
- No usar la frase "Serie A 100% técnica" — la matriz actual mide **99%**, no 100%, por los 2 casos siguientes.

**2) Ítems congelados por dependencia externa (NO son trabajo de desarrollo/calibración a continuar):**

- **`A05` / sección `V`** — congelada, pendiente de decisión funcional de Estadística APS.
- **`A30` / sección `C`, `pattern_id=1`** — congelada, único `MISMATCH` de calibración de toda la Serie A, pendiente de decisión funcional de Estadística APS.
- Ninguno de los dos se resuelve con más trabajo técnico ni se retoma "mañana" como si fuera desarrollo propio — quedan en espera de un tercero (Estadística APS), sin fecha.
- **`A01`, filas 23/24** (Recién nacido hasta 10 días de vida, Médico/a y Matrona/ón) — **conocimiento/excepción ya incorporada a la calibración certificada**, no pendiente: solo columna TOTAL habilitada, sin heredar `sum_equals` general.

**3) Producción — todo lo cerrado hoy permanece documentado, sin tocar:**

- Serie A certificada y promovida a producción; artefactos de certificación transferidos; **392/392** hashes SHA-256 verificados; estructura productiva promovida (id=19/v33 en producción); carga REM real probada en producción; 2FA probado en producción; bug F5/logout diagnosticado y resuelto; producción **estable**.
- Commit de código desplegado en el servidor: **`0fc193c`**.
- Checkpoint documental posterior (previo a esta corrección): **`9e12e4d`**.
- Nada de esto se modifica en este turno — 100% documental.

**4) Decisión de planificación tomada hoy — orden del roadmap:**

```
REM A (etapa de calibración/certificación cerrada)
  ↓
REM BM
  ↓
REM BS
  ↓
REM D
  ↓
REM P
  ↓
Reglas de consistencia externa / inter-REM / saltos
  ↓
resto del roadmap funcional (comparativos, metas, GES, YAPS/IAPS,
paneles/reportes, epidemiología, No REM, biblioteca, auditoría/
seguridad, automatizaciones)
```

**Razón arquitectónica (registrada, no solo la decisión):** las reglas de consistencia externa relacionan datos entre hojas y/o series distintas. Es preferible construirlas cuando **todas** las series involucradas ya tengan estructuras conocidas, calibradas, certificadas y **versionadas** — construirlas antes arriesgaría anclarlas a referencias estructurales de series (BM/BS/D/P) todavía inestables/no calibradas, obligando a rehacerlas.

**5) Requisito de versionamiento para las series nuevas (BM/BS/D/P) — no copiar Serie A "a ciegas":**

Cada serie nueva reutiliza el **pipeline y arquitectura ya maduros de Serie A**, pero pasando por las mismas etapas reales, no por copia mecánica de datos/reglas:

```
plantilla oficial
  → detección/interpretación estructural
  → calibración
  → patrones/excepciones
  → reglas internas
  → análisis de mismatches
  → resolución
  → tests
  → certificación
  → artefactos/manifest
  → versionamiento
  → promoción controlada (cuando corresponda, con autorización explícita)
```

Trazabilidad obligatoria a preservar en el diseño, para cada serie: **año**, **serie**, **versión de plantilla**, **versión de estructura**, **reglas/versiones** (`rem_rule_versions`), **bindings**, **certificación**. Una plantilla futura (p. ej. 2027) **no debe destruir ni sobrescribir** la interpretación/reglas certificadas de una plantilla anterior (p. ej. 2026) — el mismo principio "append-only para estructuras" ya usado en `CertifiedStructurePromotionService` (ver checkpoint 2026-09-03 más abajo) es el patrón de referencia, no algo a reinventar por serie.

**6) Punto exacto de reanudación mañana:**

**Mañana NO:**
- investigar el servidor de nuevo;
- volver a A09;
- reabrir la calibración de Serie A;
- implementar todavía reglas de consistencia externa/inter-REM;
- hacer experimentos en producción.

**Mañana SÍ — primer bloque: REM BM.** Antes de tocar código, la primera tarea es una **auditoría READ-ONLY**:

> **"Auditoría inicial REM BM y plan de calibración/versionamiento reutilizando el pipeline certificado de Serie A."**

Objetivo de esa auditoría: entender qué soporte existe hoy en el repositorio para la Serie BM (plantillas, parser, estructuras, reglas, calibración si la hay) y qué piezas de la arquitectura de Serie A son reutilizables tal cual, cuáles requieren adaptación, y cuáles no aplican — **primero entender BM, después implementar.** Ningún cambio de código, BD, ni servidor en esa auditoría sin autorización explícita posterior.

Secuencia general al abrir la sesión de mañana (ya vigente, sin cambios): leer `CLAUDE.md` + `docs/ESTADO_ACTUAL_PROYECTO.md` → `git status --short` → verificar rama/`HEAD`/`origin/main` → recordar los 4 archivos locales excluidos (`vite.config.ts`, 2 `Diag*Command.php`, `backend/demo/`) → levantar entorno local → smoke test corto si hace falta → **auditoría READ-ONLY de REM BM** (arriba) → no tocar producción → commit/push/deploy solo tras terminar/probar/certificar un bloque, con autorización explícita turno a turno.

---

### CIERRE DE JORNADA — 2026-09-03, ACTUALIZACIÓN FINAL DEL DÍA (leer esto primero, antes que el checkpoint de más abajo)

**Veredicto: `F5_LOGOUT_BUG_RESOLVED_AND_DEPLOYED` + `PRODUCTION_UPDATED_AND_VALIDATED`.** Después del checkpoint de sesión/2FA de más abajo (mismo día), se investigó y cerró un bug crítico adicional, y **el servidor de producción fue actualizado y validado** — la primera actualización real de producción desde el cierre de REM A el 31 de agosto.

**1) Bug crítico resuelto: Login → Dashboard → F5 → expulsión a `/login`.**

Causa raíz confirmada — no solo inferida, **reproducida con HTTP real y procesos aislados** —: `backend/bootstrap/app.php` registraba manualmente `EncryptCookies`, `AddQueuedCookiesToResponse` y `StartSession` en el prepend del grupo `api`, pero `Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful` **ya ejecuta esas mismas tres internamente** (más CSRF) para tráfico frontend stateful — duplicación real del pipeline de sesión/cookies en cada request.

Metodología: un primer intento de reproducir el bug con el harness de PHPUnit **no lo detectó** (falso negativo) — el harness de Laravel reutiliza el mismo `SessionManager`/`Store` cacheado entre "requests" simuladas dentro de un mismo método de test, algo que **nunca ocurre entre un login y un F5 posterior en un despliegue real** (cada request HTTP real es un ciclo de aplicación nuevo). Corregido levantando `php artisan serve` real y usando `curl` en **procesos completamente independientes**, contra `esalud_testing` con `SESSION_DRIVER=database` (igual que producción):
- **Con la duplicación restaurada**: la cookie de sesión enviada en una request posterior producía, al descifrar el ID real (no comparar el texto cifrado, que cambia siempre por el IV aleatorio de AES), un **ID de sesión distinto** — `SON LA MISMA SESIÓN: NO`.
- **Con el fix aplicado**: el ID de sesión real permanece **idéntico** entre dos procesos `curl` independientes — `SON LA MISMA SESIÓN: SÍ`.

Corrección: `bootstrap/app.php` deja únicamente `EnsureFrontendRequestsAreStateful` en el prepend de `api`. `tests/TestCase.php` agrega `Referer`/`Origin` por defecto a **todos** los tests (evaluado explícitamente si limitarlo solo a `Feature/Auth`; se decidió mantenerlo global porque el 100% del tráfico real de esta app es SPA stateful vía Sanctum, y la regresión completa confirmó cero diferencia de comportamiento fuera de `Feature/Auth`). Nuevo `StatefulSessionMiddlewareTest.php` (5 tests, con reenvío real de cookie capturada, no continuidad de proceso).

**Commit**: `0fc193c65f8cf172f76ea6838f8424fa8cc1f2a7` (`fix(auth): remove duplicated stateful session middleware`) — exactamente 3 archivos: `backend/bootstrap/app.php`, `backend/tests/TestCase.php`, `backend/tests/Feature/Auth/StatefulSessionMiddlewareTest.php`. `Feature/Auth`: **74/74 passed** (389 assertions). Pusheado: `main`=`origin/main`=**`0fc193c`**, ahead/behind=**0/0**.

**Falso hallazgo durante el diagnóstico, no confundir con un defecto**: una petición `curl` artificial sin header `Referer`/`Origin` contra `/auth/session` dio HTTP 500 — es el comportamiento **esperado**: `EnsureFrontendRequestsAreStateful::fromFrontend()` exige uno de esos headers coincidente con `SANCTUM_STATEFUL_DOMAINS` para aplicar su pipeline de sesión; cualquier petición real del navegador (fetch/XHR) los envía siempre. **No revertir el fix por esta prueba artificial que omite el contexto de frontend.**

**2) Producción actualizada — primera actualización real desde el cierre de REM A.**

Servidor: `orion@192.168.1.158`, proyecto en `/var/www/esalud` (`backend/` dentro), Docker Compose en `/home/orion/docker-setup/esalud`, dominio `http://atenea.cormudesi.cl` (HTTP, sin TLS todavía). Contenedores: `esalud-backend`, `esalud-worker`, `esalud-redis` (backend expone `8083`→PHP-FPM `9000`; Redis `6379`; `restart: unless-stopped` verificado/agregado en `esalud-backend`).

Secuencia ejecutada: `git fetch origin main` (antes: servidor en `790910d`, `origin/main` en `0fc193c`) → `git merge --ff-only origin/main` → fast-forward correcto → servidor en `0fc193c`, `HEAD=origin/main`, ahead/behind=0/0 → `docker compose build esalud-app` → recreados `esalud-backend`/`esalud-worker` con la imagen reconstruida → los 3 contenedores en `running`.

**Configuración Laravel productiva confirmada:**

| Variable | Valor |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `SESSION_DRIVER` / `SESSION_COOKIE` | `database` / `esalud-session` |
| `SESSION_DOMAIN` / `SESSION_PATH` | `atenea.cormudesi.cl` / `/` |
| `SESSION_LIFETIME` / `SESSION_EXPIRE_ON_CLOSE` | `120` / `true` |
| `SESSION_SECURE_COOKIE` | `false` (**correcto mientras se sirva por HTTP** — no es un descuido) |
| `SANCTUM_STATEFUL_DOMAINS` | `atenea.cormudesi.cl` |
| `DB_CONNECTION` / `DB_DATABASE` | `mysql` / `atenea` |
| `APP_URL` | `http://atenea.cormudesi.cl` |

**Pendiente técnico, no bloqueante**: cuando se implemente HTTPS, revisar como mínimo — certificado TLS, Nginx/proxy, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, caches de Laravel, contenedores, y repetir la regresión completa de login/F5/logout/2FA.

**Validación manual real en producción (Chrome), confirmada por el usuario:** Login → Dashboard → F5 → **el usuario permanece autenticado** (antes del fix, esto expulsaba a `/login`). Network mostró una sola petición a `/auth/session`: `HTTP 200`, `data.authenticated=true`, `data.requires_2fa=false`, `data.user` correcto, `message="Sesión activa"`, `errors=null`. También verificado manualmente: login, dashboard, logout, 2FA, y **una carga REM real completa** — todo funcionando correctamente. **`BUG F5/LOGOUT: RESUELTO Y VALIDADO EN PRODUCCIÓN.`**

**3) Certificación REM promovida al servidor, verificada por checksum.** `certification-checksums.sha256` (generado en local; cubre `reglas-funcionales.json`, `mismatch-resolution-audit.json`, `serie-a-catalogo.json`, `certification-manifest.json` + todo `cell-data/*.json` — **392 archivos** en total) transferido a `/var/www/esalud/backend/storage/app/private/certificacion/`. En el servidor: `sha256sum -c certification-checksums.sha256` → **392 verificados, sin errores**.

Estado post-promoción confirmado en producción (mecanismo `rem:promote-certified-structure` del checkpoint de abajo, ejecutado ahí contra la BD real de producción): **estructura activa id=19/v33**, **798 reglas** (751 activas), **1425 bindings** (los 974 preexistentes de producción + los 649 del paquete certificado — ninguno de los dos conjuntos se perdió, exactamente el diseño "append-only" ya documentado en el punto 3 del checkpoint de abajo). No coincide con el `1655` de la BD local certificada porque producción conservó sus propios bindings previos — comportamiento esperado, no una discrepancia.

**4) Elementos locales del servidor — NO tocar sin revisar antes.** `git status` en el servidor muestra sin rastrear: `.dockerignore`, `backend/esalud_dev`, `frontend/dist_predeploy_20260813_081741/`, `frontend/node_modules_predeploy_20260812_181301/`. **No borrarlos automáticamente, no agregarlos a Git automáticamente** — revisar su propósito primero si alguna vez se decide limpiarlos.

**5) Aclaración de estado funcional — CORREGIDA el 2026-09-04 tras detectar una afirmación errónea agregada en la primera versión de este checkpoint (ver nota de corrección al final del archivo).** Que producción esté desplegada y funcionando **no significa que ATHENEA esté terminado como producto** — persisten items funcionales puntuales sin resolver (ver abajo) y las demás series REM (BM/BS/D/P) no han comenzado. Pero **la calibración funcional de la Serie A SÍ está terminada**, no es trabajo pendiente:

- Cerrada al 100% (303/303 secciones aplicables) el 2026-08-11, commit `f91bae8` (`docs/handoffs/rem-a-mismatch-y-calibracion-2026-08-11-a-2026-08-26.md`).
- **Reverificado en vivo hoy (2026-09-04) contra la estructura activa real (67/v35)**, no solo citado del histórico: `SectionCalibrationMatrixService::buildStructureCalibrationSummary()` da **302/304 secciones aplicables completadas (99%)** — 22 hojas, de las cuales **20 están al 100%** (incluida **A09: 14/14 secciones, 100%, `completada`** — A09 NO está pendiente, fue certificada como el resto), y solo **2 hojas en `en_revision`** con exactamente **1 sección pendiente cada una**: **A05** (sección `V`) y **A30** (sección `C`, `pattern_id=1`, el único `MISMATCH` de calibración de toda la Serie A) — ambas **ya documentadas desde antes** en "Pendientes conocidos" arriba, congeladas porque requieren una decisión funcional de Estadística APS, no desarrollo técnico. 5 hojas (`A21,A24,A25,A30AR,A34`, 75 secciones) siguen `no_utilizada` por decisión de Estadística APS, fuera de alcance.
- **No existe ninguna hoja de Serie A sin calibrar o "en progreso" en el sentido de desarrollo pendiente** — todo lo que no está al 100% son exactamente esos 2 casos puntuales, congelados a la espera de un tercero, ya conocidos.
- El catálogo certificado, `reglas-funcionales.json`, `mismatch-resolution-audit.json`, `serie-a-catalogo.json` y `cell-data/` (392 archivos) son precisamente los artefactos de este cierre — los mismos que se promovieron y verificaron por checksum en producción hoy (ver punto 3).

**Terminado, en orden**: (1) calibración/certificación funcional de Serie A — 2026-08-11; (2) certificación end-to-end del motor de reglas sobre esa calibración (REM A) — 2026-08-31; (3) promoción de la Serie A certificada a producción — 2026-09-03/04 (punto 3 arriba); (4) corrección del bug de sesión/F5 y su despliegue — 2026-09-03/04 (punto 1 arriba).

**Excepción de A01, filas 23 y 24** (**Recién nacido hasta 10 días de vida**, **Médico/a y Matrona/ón**) — **conocimiento ya incorporado a la calibración certificada, NO trabajo pendiente**: solo la columna **TOTAL** está habilitada para esas dos filas, las columnas de rangos etarios/no aplicables están bloqueadas, y no heredan la regla general `sum_equals` de las filas anteriores. Se documenta aquí como recordatorio de un patrón real ya calibrado correctamente, por si se audita o se toca A01 en el futuro — no como algo por hacer.

**6) Fotografía del dashboard al cierre de hoy** (snapshot puntual, no valores permanentes — cambiarán con cada carga futura): cargas REM totales **28**, reglas activas **751**, bindings activos **1402**, cargas con motor ejecutado **16 de 28**, logs con error **0**.

**7) Punto exacto de reanudación mañana — SUPERSEDIDO por el checkpoint "2026-09-04, DECISIÓN DE ROADMAP" al inicio de esta sección.** Ese checkpoint es ahora la fuente de verdad para la reanudación (incluye la decisión de roadmap REM A→BM→BS→D→P→consistencias externas, el requisito de versionamiento, y la primera tarea exacta de mañana: auditoría READ-ONLY de REM BM). No repetir aquí la secuencia antigua para evitar que quede desactualizada en dos lugares — leer el checkpoint de 2026-09-04.

**No empezar investigando el servidor de nuevo.** La etapa de deploy de hoy queda **cerrada**; producción debe considerarse **base estable validada**. El trabajo de mañana vuelve al **entorno local**. Recordatorios que siguen vigentes sin cambios: los 4 archivos locales excluidos de siempre (`frontend/vite.config.ts`, los 2 `Diag*Command.php`, `backend/demo/`); no tocar producción para experimentar; commit/push/deploy solo tras terminar/probar/certificar un bloque, con autorización explícita turno a turno.

**Corrección 2026-09-04 — nota de continuidad:** una versión anterior de este mismo checkpoint afirmaba erróneamente "A01–A08 calibradas, A09 pendiente, continuar calibración hoja por hoja". Esa afirmación era incorrecta (arrastrada de contexto histórico de una fase de certificación de reglas ya cerrada, no de la calibración funcional) y fue corregida — ver el checkpoint "2026-09-04, DECISIÓN DE ROADMAP" al inicio de esta sección para el estado correcto y la decisión de roadmap vigente.

**Regla de continuidad, válida de aquí en adelante: producción es base estable, el desarrollo nuevo se hace en local.** Nunca desarrollar ni experimentar directamente en producción. Flujo normal: analizar → implementar/calibrar → probar → certificar → revisar diff → commit → push → desplegar cuando corresponda, con autorización explícita en cada etapa.

---

### CHECKPOINT DE CIERRE DE JORNADA — 2026-09-03 (mismo día, checkpoint anterior al de arriba)

**Veredicto: `SESSION_REMEMBER_ME_POLICY_CLOSED` + `SESSION_STATUS_ENDPOINT_CLOSED`.** Dos cierres relacionados, mismo día, ambos validados con tests y con pruebas manuales del usuario en ATHENEA local. **Servidor sin tocar** — cero conexión, cero comando ejecutado ahí.

**Commiteado y pusheado el mismo día** (ver detalle abajo, sección "Estado Git" no repetida aquí): `main`=`origin/main`=**`48ef640`**, dos commits — `b699239` (seguridad/sesión, los 12 archivos de los puntos 1/2 de abajo) y `48ef640` (Paso 7A, solo documental). **El mecanismo de promoción REM A del punto 3 (más abajo) es trabajo posterior a ese push, todavía sin commitear.**

**1) Hallazgo real de seguridad + corrección — "recordar sesión" eliminado por completo.** Durante un diagnóstico de sesión/2FA se confirmó (leyendo `Illuminate\Auth\SessionGuard` en vendor, no solo inferido) que `AuthController::login()` forzaba `Auth::attempt(..., true)` para **todo** usuario — el checkbox "Recordar sesión" del frontend era cosmético — y que eso abría un bypass real de 2FA: una cookie "recaller" de Laravel puede reautenticar una sesión sin pasar nunca por `AuthController::login()`, por lo que el gate `TwoFactorSession::markPending()` nunca se activaba. Decisión de producto: **sin remember-me, para nadie, sin excepción.**

- `AuthController::login()`: `Auth::attempt()` ya sin segundo argumento (remember=false siempre).
- `AuthServiceProvider::preventRememberedReauthentication()` (nuevo): listener del evento `Login` — cualquier login con `remember=true` (dado el cambio anterior, solo puede originarse en la resurrección vía cookie recaller, verificado con `grep` de todo `app/`) se revierte de inmediato con `Auth::guard($guard)->logout()`, dentro del mismo ciclo síncrono, antes de que `auth:sanctum`/`2fa.verified` lleguen a tratar la request como autenticada (confirmado leyendo `Laravel\Sanctum\Guard::__invoke()`, que llama `Auth::guard('web')->user()` internamente). Efecto colateral deseado: cualquier cookie "recaller" previa a este cambio se auto-invalida (cicla `remember_token`, olvida la cookie) la primera vez que alguien intenta usarla — **sin migración ni `UPDATE` masivo sobre `users`**.
- `backend/.env` (local) y `.env.example`: `SESSION_EXPIRE_ON_CLOSE=true` — la sesión no sobrevive al cierre completo del navegador. **`.env` de producción NO tocado.**
- Auditoría dedicada del listener (orden de ejecución, recursión, otros guards, efectos sobre sesión válida) — veredicto `SEGURO PARA CONSERVAR`, sin ajustes.
- Tests nuevos: `SessionRememberPolicyTest` (9/9) — incluye el escenario que motivó el cambio: una cookie recaller creada *antes* de esta política (simulada reproduciendo a mano el formato de `queueRecallerCookie()`, sin pasar por el listener) no consigue saltarse 2FA ni autenticar a un usuario sin 2FA.

**2) Ruido de `401` esperado en `/auth/me` durante la carga de `/login` sin sesión — resuelto con endpoint de estado separado.** `useAuthInit` (raíz de la SPA, corre en toda carga incluida `/login`) llamaba `/auth/me` (protegida, `401` si no hay sesión) — semánticamente correcto pero generaba una petición roja en DevTools en el estado más normal de la app. Auditado (comparando mantener `/auth/me` vs. endpoint de estado vs. convertir `/auth/me` a 200-siempre) y resuelto con la alternativa correcta:

- `GET /auth/session` (nuevo, público, fuera de `auth:sanctum`, siempre `200`): `data: {authenticated, requires_2fa, user}`. `/auth/me` **sin cambios de contrato** (sigue `401` sin sesión — verificado con test de regresión explícito; sigue siendo el que usa `TwoFactorSettingsPanel.tsx` donde un 401 real sí es señal).
- Lógica de sesión/2FA extraída a `AuthController::resolveSessionState()` (privado), compartida por `me()` y `session()` — no puede divergir entre ambos.
- Frontend: `useAuthInit.ts` ahora llama `authService.session()` (nuevo) en vez de `.me()`; `authService.me()` intacto. Tipos: `SessionStatus` nuevo en `types.ts` (superset de `LoginResult` + `'unauthenticated'`).
- Tests nuevos: `SessionStatusEndpointTest` (7/7) — incluye 2FA pendiente, challenge vencido, y la misma cookie recaller antigua contra este endpoint nuevo (confirma que el listener del punto 1 aplica igual aquí, por estar atado al guard, no a la ruta).

**Regresión y validación técnica:** `Feature/Auth` completo **69/69**; regresión ampliada (`Auth`+`Calibration`+`Unit`) **310/310**; `RuleEngine` **331/366**, las 35 fallas son exactamente las mismas ya documentadas como flakiness preexistente (mismos nombres, confirmado antes y después de tocar `routes/api.php`, sin relación). Frontend: `tsc --noEmit`/`npm run lint`/`npm run build` limpios (mismo warning preexistente y ajeno en `RemUploadForm.tsx`). `php artisan route:list` confirma la ruta nueva registrada sin duplicados.

**Validación manual del usuario en ATHENEA local (2026-09-03), confirmada explícitamente:** `/login` sin sesión carga limpio, sin `401` en consola/Network; login correcto; Dashboard carga; recarga con sesión válida restaura la sesión; módulo Usuarios carga; logout vuelve a `/login` correctamente.

**Dos hallazgos de diagnóstico cerrados sin ningún cambio de código:** el `TypeError: Cannot read properties of undefined (reading 'session')` reportado antes quedó confirmado como causado por la extensión de Chrome "Library Sniffer" — desapareció al desactivarla, no era de ATHENEA. Un error transitorio de `startTime` no se reprodujo tras recargar y no fue investigado más — no se modifica ATHENEA por ninguno de los dos.

**3) Preparación del traslado de la calibración REM A certificada a producción — mecanismo nuevo, todavía sin commitear.** Producción quedó identificada corriendo un código muy anterior (`01726e5`, previo a toda la campaña REM A/Seguridad/UX-UI) con estructura activa 18/v32, 764 reglas, 974 bindings — muy por detrás del estado certificado local (67/v35, 798 reglas, 1655 bindings). Se auditó `RemConfigurationSeeder` (el seeder ya existente) como mecanismo de traslado y se **descartó explícitamente como inseguro para producción**: matchea `rem_template_structures` por `(anio, serie, version_number)`, una clave que no identifica el mismo contenido entre entornos que divergieron de forma independiente — habría sobrescrito silenciosamente la estructura activa real de producción (v32) con una revisión local ya superada.

Se diseñó e implementó en su lugar un mecanismo de promoción dedicado, **append-only para estructuras** (nunca sobrescribe una fila histórica, solo agrega una nueva con `StructureVersioningService::resolveNextVersion()` + `StructureApprovalService::approve()`/`activate()`, el mismo camino ya certificado que usa el resto de la app) y **upsert por clave estable para el catálogo de reglas** (`rule_key`, que sí es significativa entre entornos):

- `rem:export-certified-promotion` — exporta *solo* el estado final certificado (1 estructura, no las 34 intermedias locales) + catálogo completo de reglas (798) + bindings en alcance auditado (451 ligadas a la estructura certificada + 198 estructura-agnósticas = 649; las 1006 restantes, ligadas a estructuras locales intermedias, se excluyen explícitamente y se reportan, nunca en silencio). Los bindings de estructura llevan un marcador (`bindable_target=certified_structure`), nunca el ID/clave local — se resuelven contra el ID que la estructura reciba en el entorno destino.
- `CertifiedStructurePromotionService` + `rem:promote-certified-structure` — `--dry-run` por defecto, `--commit` explícito, transacción única, rechaza (sin escribir nada) si el hash certificado ya existe en destino (evita duplicar) o si algún `binding`/`rule_version` referencia una `rule_key` ausente del propio paquete.
- **Hallazgo real durante la auditoría, investigado a fondo, NO corregido en los datos**: `RuleVersion` id=79/80 (regla 529, `a32_f_b_sum_equals`) comparten `version="1.0.0"` pero tienen `config` distinto. Confirmado contra `activity_log` (ids 1420/1421, con `restored_from_version_id:79` explícito) que son dos snapshots de auditoría **legítimos e intencionales** de dos operaciones reales del 2026-08-27 (`rule:remap-section` seguido de `rule:restore-config-version`) — `(rule_id, version)` nunca fue una clave única by design de esos comandos. **Ambas filas se dejaron intactas.** La corrección real fue en el mecanismo: el matching de `rem_rule_versions` ahora exige también `config` idéntico — si `(rule_id, version)` coincide pero el contenido difiere, se trata como snapshot adicional (se crea, nunca sobrescribe). Se agregó además la misma detección temprana de `rule_key` huérfanas en `plan()`/dry-run que ya tenían los bindings (antes solo abortaba dentro de la transacción).
- **Simulación final tipo producción** (en `esalud_testing`, nunca en `esalud_dev` ni en el servidor): estructura v32 con hash distinto, 764 reglas (700 coincidentes con contenido divergente + 64 exclusivas de "producción"), 974 bindings — dry-run y `--commit` reales confirmaron: estructura histórica byte-idéntica tras la promoción (status→superseded, contenido sin tocar), nueva estructura v33 activa, 98 reglas nuevas + 700 actualizadas al contenido certificado, las 64 exclusivas de producción **intactas**, 649 bindings del paquete resueltos contra el nuevo ID, los bindings previos de producción (974) permanecen — un segundo dry-run posterior detectó el hash ya promovido y abortó sin duplicar nada.
- **Tests**: 19/19 (13 servicio + 2 export + 4 comando). Regresión secuencial completa (`Auth`+`Calibration`+`REM`+`Unit`): 374/374, mismo techo de memoria preexistente y ajeno de siempre en el test pesado de PhpSpreadsheet.
- **Archivos nuevos, sin commitear**: `app/Console/Commands/RemExportCertifiedPromotionCommand.php`, `app/Console/Commands/RemPromoteCertifiedStructureCommand.php`, `app/Domain/RemParser/Services/CertifiedStructurePromotionService.php`, `app/Domain/RemParser/Exceptions/PromotionAbortedException.php`, `database/seeders/data/rem-certified-promotion.json` (paquete generado), 3 archivos de test en `tests/Feature/REM/`.
- **Pendiente antes de poder llevar esto a producción real**: transferir aparte (fuera de BD) `reglas-funcionales.json`, `cell-data/*.json`, y decidir sobre `mismatch-resolution-audit.json`/`serie-a-catalogo.json` (manifiesto con SHA-256 ya generado en `storage/app/private/certificacion/certification-manifest.json`, no versionado). Actualizar primero el CÓDIGO de producción (incluye migraciones, ej. columnas 2FA) — la promoción de datos por sí sola no alcanza.

**Archivos tocados hoy, no comiteados** (ninguno de REM/RuleEngine/calibración/estructura/uploads *productivos* — todo lo de arriba es sobre datos certificados locales o simulados en `esalud_testing`): los del punto 3 recién listados. Los 4 excluidos de siempre (`vite.config.ts`, 2 `Diag*Command.php`, `backend/demo/`) siguen fuera, sin tocar.

**Próximo paso exacto:** autorización explícita del usuario para comitear el mecanismo de promoción (punto 3) → decidir alcance del commit → push → **recién después** retomar el Paso 7B (diagnóstico READ-ONLY del servidor), nunca antes. La actualización real de producción (código + datos + artefactos) sigue sin iniciarse.

---

### CHECKPOINT DE CIERRE DE JORNADA — 2026-09-01 (leer primero al retomar)

- **Campaña UX/UI: terminada, validada, commiteada y pusheada.** `main` = `origin/main` = **`e7cda9c`**, ahead/behind = **0/0**. Paso 6 (commit/push de UX/UI) **cerrado**.
- **Paso 7 (despliegue controlado) todavía NO iniciado.** Hoy **no se modificó el servidor ATHENEA** — cero conexión, cero comando ejecutado ahí.
- Auditoría READ-ONLY del Paso 7 detectó que la documentación de deploy (`DEPLOYMENT.md`, `ESTADO_ACTUAL_PROYECTO.md`, `CHECKLIST_DESPLIEGUE_PRODUCCION.md`) estaba desactualizada frente al estado real — **Paso 7A (actualización documental) ya ejecutado y cerrado** (ver más abajo), cifras corregidas y verificadas en vivo contra la BD local.
- Se preparó y corrigió (tras un primer intento truncado por formato) la lista de comandos READ-ONLY para inspeccionar el servidor — lista final en un solo bloque, un comando por línea, namespaces/modelos ya verificados contra el código real. **Todavía no ejecutada.**
- **Próximo paso exacto: Paso 7B — diagnóstico READ-ONLY del servidor en `/var/www/esalud`.** Al retomar: primero ejecutar solo el bloque de Git (commit/rama/`git status`), revisar esa salida antes de seguir, y **solo después** continuar por bloques con migraciones, configuración REM en BD, `storage/app/private/certificacion/`, dependencias y servicios — no todo de una vez.
- **Prohibido hasta nueva autorización explícita:** `git pull`, migraciones, seeders (incluido `RemConfigurationSeeder` — sus fixtures JSON versionados datan de `3d790c2`/2026-08-06 y quedaron desactualizados frente a la certificación final de REM A, no deben sembrarse sin resolver antes esa discrepancia), cambios de `.env`, reinicios de servicios, backups, deploy.
- **Referencia de comparación para el diagnóstico de mañana:** estructura activa **67/v35**, **798** `rem_rules` (751 activas, 474 `SAFE_1_TO_1`), **1655** `rem_rule_bindings` totales (451 a la estructura 67) — baseline local/certificado, ya reverificado en vivo.
- **Secuencia obligatoria antes de cualquier actualización real del servidor:** diagnóstico READ-ONLY (Paso 7B) → análisis de diferencias → plan incremental → backups → autorización explícita → deploy. Ninguna etapa se salta.
- Estado Git local al cierre de hoy: working tree con `CLAUDE.md` + los 3 documentos de deploy modificados (Paso 7A, sin commitear, pendientes de tu revisión) y los 4 excluidos de siempre (`vite.config.ts`, 2 `Diag*Command.php`, `backend/demo/`) sin tocar. Sin stage, sin commit, sin push de esta subfase todavía.

---

**REM A** queda oficialmente cerrado (certificado end-to-end, commiteado y respaldado en `origin/main`). **Seguridad/2FA** (hardening `auth:reset-admin` + rate limiting + TOTP RFC 6238 completo) también queda **oficialmente cerrada, commiteada y pusheada**:

- `main` = `origin/main` = commit **`624519f57ad65f1db0aa501d9c764f39620df255`** (`624519f`, `feat(security): harden auth and implement TOTP 2FA`), ahead/behind = **0/0**.
- Probado manualmente end-to-end por el usuario en ATHENEA local antes del commit. 45/45 tests nuevos en `backend/tests/Feature/Auth`. Cero usuarios reales enrolados en 2FA durante desarrollo/pruebas (`admin@esalud.cl` sin 2FA).
- Auditoría pre-push (Fase Seguridad 5) confirmó `2FA_PUSH_READY`: sin secretos/passwords/tokens reales en el diff, migración simétrica (4 columnas nullables en `users`), lockfiles solo agregan `pragmarx/google2fa`+`qrcode.react` (sin extras), sin dumps/logs/binarios. Push ejecutado (`b659a5e..624519f`, fast-forward normal). Post-push: REM A reconfirmado intacto (`rem_rules=798`, `activas=751`, `bindings=1655`, `bindings_a_67=451`), `usuarios_con_2fa=0`.
- **Servidor ATHENEA todavía NO actualizado** con nada de lo hecho desde el cierre de REM A (ni 2FA, ni lo que venga de UX/UI) — no hacer `git pull` ni deploy sin autorización explícita de Nelson/usuario.

**UX/UI — Paso 6: CERRADO, commit creado y pusheado (2026-09-01).** Toda la campaña UX/UI (tokens/primitivos, `PageHeader`, migración a `DataTable`, normalización de headers/breadcrumbs, tipografía/espaciado, retiro de Prime/`CriteriosFuncionalesPage`, manejo visual 404, piloto A+B de tablas densas con su fix de `UsersTable.tsx`) quedó consolidada en un único commit, ya en el remoto:

- `main` = `origin/main` = **`54dc5cd30e4da176128c2f9cb1dd64ab405b1258`** (`54dc5cd`, `feat(ui): normalize ATHENEA UX/UI, refine rule-engine tables, retire legacy Prime integration`), 47 archivos (44 modificados, 5 borrados de Prime, 1 `NotFoundPage.tsx` nuevo). Un commit documental de seguimiento (`e7cda9c`, solo `CLAUDE.md`, registrando este mismo cierre) se hizo y pusheó justo después — ver más abajo. **Ahead/behind = 0/0.**
- Clasificación previa al stage: 47 archivos incluidos (todos de la campaña UX/UI), **4 explícitamente excluidos y confirmados sin tocar**: `frontend/vite.config.ts`, los 2 `Diag*Command.php`, `backend/demo/` — ningún archivo dudoso encontrado, siguen presentes solo en el working tree local, no incluidos en el commit ni en el push.
- Stage explícito por ruta (nunca `git add .`/`-A`). Diff revisado (`--stat`/`--name-status`) y escaneado por patrones de secretos/credenciales antes de comitear — sin hallazgos reales.
- `tsc --noEmit`/`npm run lint`/`npm run build` limpios antes y después del commit (el hook de pre-commit del proyecto corrió `eslint --fix`+`prettier --write` sobre los archivos staged y los re-agregó automáticamente al commit — comportamiento normal del repo, sin cambios funcionales; revalidado post-commit).
- Push ejecutado (`git push origin main`, fast-forward normal `624519f..54dc5cd`). Verificado post-push: `main`=`origin/main`=`54dc5cd`, ahead/behind=0/0.
- Sin deploy, sin tocar servidor, sin migraciones — nada de esto forma parte de este paso.

**UX/UI — commit documental de cierre del Paso 6 (`e7cda9c`).** Solo `CLAUDE.md` (registro del cierre de arriba), stage/commit/push separado del commit de código, mismos 4 excluidos confirmados fuera. `main` = `origin/main` = **`e7cda9c4d144d78777e0468a91b1f5028d78e2a4`**, ahead/behind = 0/0. Este es el commit vigente actual del repositorio.

**Paso 7A — actualización documental de despliegue, CERRADA (2026-09-01, 100% documental, sin tocar servidor/código).** La auditoría READ-ONLY del Paso 7 confirmó que `docs/handoff/DEPLOYMENT.md`, `docs/ESTADO_ACTUAL_PROYECTO.md` y `docs/CHECKLIST_DESPLIEGUE_PRODUCCION.md` (preparados 2026-08-04/05, commit `d02e88e`) tenían cifras y el commit objetivo obsoletos frente al estado real. **Cifras verificadas en vivo contra la BD local `esalud_dev`** (no solo copiadas de este archivo): estructura activa `id=67`, `version_number=35`, `rem_rules` total=**798**, activas=**751**, `SAFE_1_TO_1`=**474** (recalculado en vivo vía `RuleBindingReconciliationService::classifyAllActiveRules()`, no un valor cacheado), `rem_rule_bindings` total=**1655**, bindings activos a estructura 67=**451** — las 6 cifras coinciden exactamente con el baseline ya documentado en este archivo.

Actualizados los 3 documentos: commit objetivo `d02e88e`→`e7cda9c`, estructura `v15/id=36`→`v35/id=67`, `764 reglas/859 bindings`→`798 reglas (751 activas, 474 SAFE_1_TO_1)/1655 bindings (451 a estructura 67)` en cada tinker de verificación, tabla de datos y checklist. Agregado explícitamente: (a) el dump de calibración de agosto **nunca se generó** y no debe reutilizarse, hay que generar uno nuevo contra el estado actual antes de cualquier deploy; (b) recordatorio de respaldo (`mysqldump`+`tar` de `storage/app/private`) antes de restaurar nada; (c) HTTPS reforzado como condición previa a confirmar con Nelson, no una suposición; (d) nuevo punto de checklist "Validación funcional ampliada" (login, persistencia de sesión, 2FA/TOTP, Dashboard, Cargas REM, Calibración REM, Matriz, Patrones y fórmulas, Motor de Reglas, Catálogo de Reglas, Comparación, manejo 404, ausencia de Criterios funcionales/Prime legacy) — ninguno de estos se validaba en la versión de agosto porque no existían todavía.

**Otros documentos encontrados con las mismas cifras obsoletas, reportados pero NO tocados** (fuera de los 4 archivos autorizados en esta subfase): `docs/CHECKLIST_VALIDACION_POST_DESPLIEGUE.md` (directamente relacionado con deploy, recomendado actualizar en una subfase futura autorizada aparte), `docs/MANUAL_TECNICO.md` y `SISTEMA.md` (documentación general con una mención de paso, menor prioridad). **No tocados, y correctamente no tocados por ser archivos históricos congelados** (convención ya establecida en este mismo `CLAUDE.md`): `docs/handoffs/rem-a-*.md`, `docs/checkpoints/ATHENEA_CHECKPOINT_2026-08-24.md`, `docs/handoff/CONTINUE.md`.

Condiciones previas que **siguen sin confirmar con Nelson**, documentadas como bloqueantes explícitas antes de cualquier deploy real: (1) HTTP vs. HTTPS en `atenea.cormudesi.cl` — condiciona `SESSION_SECURE_COOKIE`; (2) credenciales MySQL reales de producción; (3) decisión sobre `MemoryProbe` en producción; (4) generar el dump de calibración nuevo (nadie lo ha hecho, ni el de agosto ni uno actualizado). Sin código modificado, sin conexión al servidor, sin `git pull`/`composer install`/migraciones/`npm ci`/`npm run build`/reload de Nginx/restart de Supervisor/`queue:restart`/dumps reales/SSH/cambios de `.env` — ninguno de estos se ejecutó, tal como se instruyó. Sin commit, sin push de esta subfase todavía — pendiente de tu revisión del diff documental.

**CHECKPOINT DE CIERRE DE JORNADA — 2026-08-31.** Campaña UX/UI en curso desde 2026-08-31. Resumen compacto (detalle punto-por-punto de cada fase disponible en el historial de conversación, no reincorporado aquí para no volver a inflar este archivo):

**Fases CERRADAS y validadas visualmente por el usuario, en orden:**
1. **UX/UI 1** — auditoría: `index.css` no declaraba tokens `@theme`, primitivos (Button/Badge/Input/etc.) sin estilo real.
2. **UX/UI 2** — fundación de tokens en `index.css` (paleta slate/blue/rose vía `var(--color-*)` de Tailwind, sin dark mode).
3. **UX/UI 3** — ajuste Button/Badge/Input/Card/ConfirmDialog; fix real: `border-t` sin color en `alert-dialog.tsx`/`card.tsx`/`dialog.tsx` (heredaba `currentColor`, casi negro) → `border-t border-border`. Rojo sólido de `ConfirmDialog` destructivo mantenido a propósito.
4. **UX/UI 4** — `PageHeader` creado/adoptado en `DashboardPage`/`UsersPage`/`SecurityPage`/`ComingSoonPage`; KPIs reales en Dashboard vía hooks ya existentes (`useRuleEngineHealth`, `useRemUploads`), cero endpoint nuevo.
5. **UX/UI 5A+5A.1 (Nivel A, 8/8)** y **5B (Nivel B, 4/4)** — migración de tablas manuales a `DataTable`. `DataTable.tsx` extendido una vez con `onRowClick?`/`getRowClassName?` (opcionales, retrocompatibles). Migrados: `RuleEngineDashboardPage`, `ComparisonDiffTable`, `StructureDetailPage`, `RemValidationModal`, `SeccionRevisionPage`, `RuleCatalogTable`, `SectionRulesTable`, `FormBreakdownTable`, `RuleDetailPage`, `SpecialColumnsPanel`, `RowFunctionalDecisionTable`, `PatternCalibrationGroup`. **Excluidas deliberadamente, sin fecha**: `RuleCertificationCard.tsx`, `SectionCalibrationTable.tsx`, `StructureTreeNode.tsx`.
6. **UX/UI 6A (normalización de encabezados/breadcrumbs, 8/8 pantallas) — CERRADA COMPLETA** (6A.1+6A.2+6A.3). `PageHeader` extendido una vez: `title: string`→`ReactNode`, `breadcrumb?: {label, onClick}[]` (opcional, retrocompatible con los 21 consumidores previos). Migradas: `CalibrationSeriesPage`, `CalibrationSheetPage`, `CalibrationTemplatePage`, `UploadValidationErrorsPage` (6A.1); `RuleSectionPage`, `CalibrationSectionPage`, `UploadValidationSummaryPage` (6A.2, con Alternativa 2: consolidó el doble mecanismo de retorno de `UploadValidationSummaryPage` en un solo breadcrumb); `SeccionRevisionPage` (6A.3, recibió prop `embedded?: boolean` porque se usa también embebida sin props dentro de `CalibrationSectionPage` vía coincidencia de nombres de `useParams()` — sin esto, migrar su header habría duplicado el de `CalibrationSectionPage`).
7. **UX/UI 6B.1** — `EmptyState.tsx` (`gray-*`→`slate-*`, beneficia 20 pantallas con `DataTable`); `RuleDetailPage.tsx` como piloto (única inconsistencia real encontrada: `space-y-5/4/3/3` sin criterio → unificado a `space-y-4`; migración a `Card` evaluada y descartada por no ser trivial — `Card` usa `ring-foreground/10`+`py-4`+`gap-4`, no `border-slate-200`+`p-6`+`space-y-N`).

**UX/UI 6B.2 — CERRADA Y VALIDADA VISUALMENTE POR EL USUARIO** (2026-09-01: `RuleDetailPage` revisada, título "INFORMACIÓN TÉCNICA" coherente con los demás bloques, sin problemas de alineación/desbordamiento/comportamiento). De los 7 archivos con el patrón de título de sección en `text-xs`, 6 resultaron ser subtítulos anidados legítimos o un rol distinto (`MetricCard`) — no tocados. **Único caso real corregido: `TechnicalInfo.tsx`** línea 26, `text-xs`→`text-sm` (una sola clase, confirmado por diff — nada más en el archivo ni en ningún otro tocado). Impacta las 7 pantallas que usan `TechnicalInfo`: `RuleDetailPage`, `StructureDetailPage`, `ComparisonPage`, `BindingDetailPage`, `ExecutionLogDetailPage`, `FeatureFlagsPage`, `ValidationErrorsTable`. `tsc`/`lint`/`build` limpios.

**UX/UI 6B.3 — CERRADA Y VALIDADA VISUALMENTE POR EL USUARIO** (2026-09-01: verificado en A01/Sección A → Patrones y fórmulas → "Preguntas funcionales por patrón", fila de `SummaryStat` correcta, sin desbordamientos/solapamientos/desalineaciones). Resultado de la auditoría: 32 casos en `SectionCalibrationTable.tsx` y 1 en `StructureTreeNode.tsx` sin revisar en detalle (archivos excluidos permanentes); 14 casos en `PatternCalibrationGroup.tsx`/`ComparisonDiffTable.tsx`/`RowFunctionalDecisionTable.tsx` son metadata de celda/badges bajo "preservar exactamente" de fases 5A/5A.1/5B — no tocados; los ~20 restantes resultaron ser badges/pills o texto de ayuda subordinado a un campo — decisiones de diseño legítimas. **Único candidato real, implementado**: `FunctionalQuestionsPanel.tsx`, función `SummaryStat` — `text-[11px]`→`text-xs`, alineado con `MetricCard.tsx`. `tsc`/`lint`/`build` limpios.

**UX/UI 6B.4 — CERRADA Y VALIDADA VISUALMENTE POR EL USUARIO** (2026-09-01: los 4 archivos revisados, incluyendo `ComparisonPage` con una comparación real ejecutada — estructura 67, upload 189 — tarjetas "Sistema Anterior"/"Motor Actual" y tabla "Diferencias" sin overflow/cortes/deformaciones). Auditoría por rol semántico (no por archivo) de las 17 tarjetas manuales de la auditoría original de Fase 1: `p-6` confirmado como convención dominante real para "tarjeta de sección completa" y "tarjeta que envuelve una tabla" (16 instancias en 5 archivos vs. 7 en `p-5` vs. 2 en `p-4`). Implementado, exactamente 4 archivos / 6 clases: `ExecutiveSummaryCard.tsx` (`p-5`→`p-6`, 1), `ComparisonPage.tsx` (`p-5`→`p-6` en tarjetas "Sistema Anterior"/"Motor Actual", 2), `UploadValidationSummaryPage.tsx` (`p-4`→`p-6` en "Errores por severidad"/"Desglose por formulario", 2), `UploadValidationErrorsPage.tsx` (`p-4`→`p-6` en el wrapper de `ValidationErrorsTable`, 1). **Deliberadamente NO tocado, con justificación**: KPI tiles (`ComparisonPage`/`StructureDetailPage`, ambos en `p-4`, reimplementan a mano un rol casi idéntico a `MetricCard.tsx` que usa `p-5` — problema de duplicación de componente, no solo de padding, decisión aparte); filtros/formularios (muestra insuficiente); loading/error/skeleton (roles no comparables); `AsistenteRevision.tsx` (panel de asistente a pantalla completa, contexto visual distinto). `tsc`/`lint`/`build` limpios.

**Auditoría de consolidación de KPI-tiles — CERRADA, decisión: NO consolidar (deuda técnica evaluada, no bug UX)** (2026-09-01). `ComparisonPage.tsx`/`StructureDetailPage.tsx` (8 tiles ad-hoc) y `MetricCard.tsx` (2 consumidores: `DashboardPage`, `RuleEngineDashboardPage`) quedan **sin tocar, deliberadamente**. Motivo: similitud conceptual pero sin equivalencia visual real — `ComparisonPage` usa color dinámico por estado del dato + círculo de color; `StructureDetailPage` usa iconografía decorativa estática; `MetricCard` usa una composición horizontal distinta a ambas. Duplicación pequeña (8 tiles, 2 archivos) y localizada, no genera inconsistencia visible. No crear `variant="centered"` ni ningún componente nuevo.

**UX/UI — Retiro de integración Prime legacy (Plan A) + manejo visual 404 — CERRADO Y VALIDADO VISUALMENTE POR EL USUARIO** (2026-09-01). Origen: la investigación de los contadores en 0 de `SeccionRevisionPage` determinó, vía auditoría READ-ONLY, una integración externa ("Prime", `/api/functional-criteria`) nunca funcional, desconectada de los datos reales de ATHENEA, con una **credencial hardcodeada en frontend** (hallazgo crítico, nunca reproducido ni usado). El usuario autorizó Plan A: retiro total.

Eliminados (6 archivos): `shared/services/apiPrime.ts`, `features/rule-engine/services/criteriaService.ts`, `features/rule-engine/hooks/useFunctionalCriteria.ts`, `features/rule-engine/pages/SeccionRevisionPage.tsx`, `features/rule-engine/components/AsistenteRevision.tsx`, `features/rule-engine/pages/CriteriosFuncionalesPage.tsx` (Opción C, redundante con `/rule-engine/catalog`). Modificados: `app/router/index.tsx`, `features/rule-engine/index.ts` (rutas/exports huérfanos), `CalibrationSectionPage.tsx` (tab `functional` eliminado de `TabView`, tab inicial siempre `matrix` para todos los roles), `shared/components/layout/AppLayout.tsx` (entrada de menú retirada), `shared/components/DataTable.tsx` (piloto `stickyHeader` revertido en su totalidad, `onRowClick`/`getRowClassName` de 5A.1 intactos), `FunctionalErrorDetail.tsx` y `DashboardPage.tsx` (2 referencias huérfanas adicionales encontradas y corregidas durante la búsqueda exhaustiva).

**Manejo visual 404**: navegar a `/criterios-funcionales` u otra ruta inexistente mostraba la pantalla técnica por defecto de react-router ("Unexpected Application Error!"). Causa: `app/router/index.tsx` (react-router-dom v7.15.1) no definía `errorElement` ni ruta comodín; cero loaders/actions en toda la app. Implementado: nuevo `pages/NotFoundPage.tsx` (patrón `PageHeader`+`EmptyState`, botón "Volver al Dashboard" → `/`) + `{ path: '*', element: <NotFoundPage /> }` como último hijo de `ProtectedRoute` — hereda `AppLayout` automáticamente, sin tocar `ProtectedRoute.tsx`/`RoleProtectedRoute.tsx`/`App.tsx`. **`errorElement` (blindaje de errores de render inesperados) queda anotado como mejora futura separada, no bloqueante, no implementada.**

Validado por el usuario en la interfaz real: `/criterios-funcionales` y URLs inventadas muestran la pantalla "Página no encontrada" integrada al layout normal (sin mensaje técnico); botón "Volver al Dashboard" funcional; menú lateral sin "Criterios funcionales"; tab "Revisión funcional" eliminada, `Matriz` como tab inicial; `Patrones y fórmulas`, catálogo y detalle de reglas funcionando con normalidad; `stickyHeader` revertido; credencial legacy ausente de `src` y del bundle generado (verificado byte a byte). `tsc`/`lint`/`build` limpios en ambas subfases. Sin commit, sin push, sin deploy. Origen: la investigación de los contadores en 0 de `SeccionRevisionPage` (antes listada como pendiente sin fecha, ver abajo) determinó la causa raíz vía auditoría READ-ONLY: dependía de una integración externa ("Prime", `/api/functional-criteria`) nunca funcional, desconectada de los datos reales de ATHENEA (`rem_rules`/`RuleEngineService`), y contenía además una **credencial hardcodeada en frontend** (`admin@esalud.cl` + password literal) — hallazgo crítico reportado, nunca reproducido ni usado. El usuario decidió Plan A: retiro total de Prime.

Eliminados (6 archivos): `shared/services/apiPrime.ts`, `features/rule-engine/services/criteriaService.ts`, `features/rule-engine/hooks/useFunctionalCriteria.ts`, `features/rule-engine/pages/SeccionRevisionPage.tsx`, `features/rule-engine/components/AsistenteRevision.tsx`, `features/rule-engine/pages/CriteriosFuncionalesPage.tsx` (retirada también, decisión del usuario — Opción C, redundante con `/rule-engine/catalog`). Modificados: `app/router/index.tsx` y `features/rule-engine/index.ts` (rutas/exports huérfanos retirados); `CalibrationSectionPage.tsx` (tab `functional` eliminado de `TabView`; tab inicial ahora siempre `matrix` para todos los roles, antes variaba por rol); `shared/components/layout/AppLayout.tsx` (entrada de menú "Criterios funcionales" retirada); `shared/components/DataTable.tsx` (piloto `stickyHeader` de la fase responsive **revertido en su totalidad** — `SeccionRevisionPage` era su único consumidor; `onRowClick`/`getRowClassName` de 5A.1 intactos). La búsqueda de huérfanos detectó y corrigió 2 referencias no listadas en la autorización original: enlace muerto en `FunctionalErrorDetail.tsx` y acceso directo muerto en `DashboardPage.tsx` (ambos retirados).

Validado: `tsc --noEmit`/`npm run lint`/`npm run build` limpios (build corrido 2 veces). Búsqueda exhaustiva confirma cero referencias a Prime (`apiPrime`/`loginPrime`/`prime_token`/`functional-criteria`/`criterios-funcionales`/`stickyHeader`) en `src` ni en el `dist/` recién generado; la credencial literal no aparece en el bundle; único hallazgo de `admin@esalud.cl` en el bundle es el placeholder inocuo del campo de email del login (sin password, verificado byte a byte). Sin commit, sin push. **Pendiente: validación visual del usuario en la interfaz real antes de considerar esta fase cerrada.**

**Piloto UX/UI — legibilidad y overflow horizontal de tablas densas (A+B) — CERRADO, VALIDADO TÉCNICA Y VISUALMENTE POR EL USUARIO** (2026-09-01). Origen: durante la regresión visual del paso 5 se detectó que en tablas densas (ejemplo: "Decisiones funcionales por fila") las columnas de la derecha (p. ej. "Acción rápida") quedan fuera del viewport sin ninguna señal de que existe scroll horizontal. Auditoría READ-ONLY previa confirmó: `overflow-x-auto` ya existía a nivel de primitivo (`ui/table.tsx`), el problema real era la falta de affordance visual, no la ausencia de scroll. Tablas más afectadas identificadas: `RowFunctionalDecisionTable.tsx` (11 columnas), `SectionRulesTable.tsx` (11), `StructuresPage.tsx` (11), `PatternCalibrationGroup.tsx`/`ComparisonDiffTable.tsx` (10-12, bajo exclusión "preservar exactamente"). `ValidationErrorsTable.tsx` resultó no ser una `DataTable` (es una lista de tarjetas), sin overflow horizontal aplicable.

**Implementado, solo en los 2 componentes compartidos, sin tocar ningún consumidor:**
- `shared/components/ui/table.tsx` (**A**): `Table` convertido a `React.forwardRef<HTMLDivElement, ...>` exponiendo el contenedor de scroll (única forma de medir `scrollWidth`/`clientWidth` reales, ya que ese primitivo es usado exclusivamente por `DataTable.tsx`, ningún otro archivo lo importa directo). `TableHeader` con `bg-muted/50` (mismo token semántico ya usado en `TableFooter`) + borde inferior reforzado (`border-slate-300`, con mayor especificidad CSS que el `border-slate-200` de `TableRow`, así que no requirió tocar `TableRow`). Sin zebra striping (deliberado). Divisores/hover de filas evaluados y confirmados suficientes sin cambios. **Validado visualmente en la primera ronda** (header distinguible, bordes claros, sin afectar `SpecialColumnsPanel`/"Columnas especiales U:AH").
- `shared/components/DataTable.tsx` (**B**): `useRef` al contenedor de scroll + `useState` de overflow izquierdo/derecho, recalculado con `ResizeObserver` + listener de `scroll` + `useEffect` sobre `[data, columns, loading]`, con cleanup correcto. Overlays `pointer-events-none` en los bordes. **Iteración post-validación**: la primera versión (degradado sutil `w-8`/`black-10` únicamente) se validó como técnicamente presente pero visualmente insuficiente ("un funcionario podía interpretar que la tabla terminaba en Observación"). Auditoría READ-ONLY confirmó que no era un bug de renderizado/stacking/clipping, sino de intensidad y ausencia de señal explícita. Corregido: degradado derecho reforzado a `w-10`/`from-black/20` (izquierdo sin cambios, `w-8`/`black-10`) + indicador explícito `ArrowRightToLine` + texto "Más columnas", mostrado solo con `overflow.right === true`, reutilizando el mismo estado (sin lógica paralela). Ajuste visual final: el indicador pasó de texto suelto a pill (`rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs`), agrupado con la tabla en `space-y-1.5` para quedar visualmente integrado, sin superponerse nunca al contenido de la tabla (vive fuera del área con `overflow-x-auto`). Sin sticky (ni horizontal ni vertical), sin ocultar columnas, sin modificar anchos de columna, sin zebra.

`tsc --noEmit`/`npm run lint`/`npm run build` limpios en las 3 rondas de implementación (0 errores, mismo warning preexistente ajeno en `RemUploadForm.tsx`). `git status` confirma que únicamente esos 2 archivos cambiaron en todo el piloto. **Validado visualmente por el usuario en navegador real**: indicador visible con overflow real, pill integrado, degradado perceptible, ambos desaparecen juntos al llegar al extremo derecho, sin superposición sobre encabezados/controles, sin regresión de scroll. Sin commit, sin push.

**PUNTO EXACTO PARA RETOMAR:** con 6A, 6B.1-6B.4, la evaluación de KPI-tiles, el retiro de Prime, el manejo visual 404, el piloto A+B de tablas densas (incluidos sus 3 hallazgos de regresión, #1 corregido y validado, #2/#3 registrados como no bloqueantes) y el paso 5 completo (técnico + regresión visual) cerrados y validados, la campaña UX/UI no tiene ningún ítem visual abierto en curso. La auditoría de **responsive/sticky de tablas** (sticky de columnas, ocultar columnas por prioridad) sigue **sin implementar**, deliberadamente fuera de este piloto — el piloto solo cubrió indicación de overflow + estilo de header, no sticky ni responsive. El piloto `stickyHeader` vertical anterior sigue revertido; retomar cualquier variante de sticky requeriría una nueva decisión explícita. Siguiente paso natural del roadmap: commit/push de todo lo acumulado de UX/UI (paso 6), sujeto a autorización explícita.

**Pendientes conocidos, sin resolver, sin fecha, registrados para no perderlos:**
- `errorElement` en el router — blindaje contra errores inesperados de render (distinto del 404 de ruta no encontrada, ya resuelto). Mejora futura documentada, **no bloqueante**, no implementada.
- Normalización de los 17 wrappers manuales tipo-Card pendiente de auditoría específica (encontrados en la auditoría original de 6B, no intervenidos).
- Estrategia responsive para tablas densas (prioridad/ocultamiento de columnas en mobile, sin implementar).
- Encabezados `sticky` para tablas con scroll vertical largo — piloto anterior revertido por completo (ver arriba); no implementado, `DataTable`/`ui/table.tsx` no lo soportan hoy.
- Exclusiones permanentes vigentes: `SectionCalibrationTable.tsx`, `StructureTreeNode.tsx`, `RuleCertificationCard.tsx` (y `RuleCertificationDetailPage`, atada a la restructuración pendiente de esa card).
- 8 páginas de `rule-engine` con `PageHeader` ya normalizado pero cuyo *contenido interno* (tablas densas, jerarquía, densidad) sigue siendo la deuda visual de fondo que motivó toda la campaña 6B.

**Servidor ATHENEA sigue sin actualizar** con nada de esta campaña (ni 2FA ni UX/UI) — no `git pull` ni deploy sin autorización explícita de Nelson/usuario. Sin commit, sin push de nada de UX/UI todavía.

Ítems de REM A abiertos pero no bloqueantes (sin fecha, solo se retoman si el usuario lo autoriza explícitamente):

1. Decidir el futuro de `AR337`/fila 333 de `A09/I` (4 opciones documentadas, ninguna elegida) — habilitaría crear las combinaciones `total_row=333` restantes de B2/B3.
2. Decidir si se autoriza revisión humana de los 6 casos ambiguos `A01/A/C` y/o desactivación de `130`/`133`.
3. Decidir qué hacer con la regla `230` (requiere insumo de Estadística APS, no solo técnico).
4. Calibración funcional de `A30/C` P1 — corresponde a Estadística APS desde la interfaz ordinaria, no a este asistente.
5. Decidir si se autoriza backfill histórico sobre las cargas existentes (ninguna autorización concedida hasta ahora).
6. Decidir el destino de los 4 archivos excluidos (`vite.config.ts`, 2 `Diag*`, `backend/demo/`) — sin decidir, sin bloquear nada.

Riesgos residuales de Seguridad/2FA (documentados, ninguno bloqueante para el cierre, a evaluar antes del deploy): `SESSION_SECURE_COOKIE=true` en producción — **gate obligatorio de deployment**, verificar manualmente. `is_active` no enforced en login (deuda independiente y previa a 2FA, no interactúa con el gap de 2FA). `AdminUserSeeder.php` con password hardcodeada (riesgo bajo). Recuperación de password por email — no implementada. Admin force-disable de 2FA de otro usuario — no implementado, solo autoservicio.

### Roadmap de campañas siguientes (orden acordado — ninguna posterior a la actual iniciada, no comenzar por iniciativa propia)

1. ~~Seguridad de autenticación / 2FA~~ — **CERRADA, commiteada y pusheada** (`624519f`). Ver arriba.
2. ~~Pruebas locales de 2FA~~ — hecho por el usuario antes del commit (Fase Seguridad 4).
3. ~~Commit/push de 2FA~~ — hecho (Fase Seguridad 4/5).
4. **Mejora controlada UX/UI — sin ítems abiertos en curso.** Ver "Próximo paso vigente" arriba para el checkpoint detallado. Fases 1–5B, 6A, 6B.1–6B.4, KPI-tiles, retiro de Prime y manejo visual 404 cerradas y validadas. El diseño funcional del 2FA ya certificado se mantiene igual — solo mejoras visuales. Pendientes reales sin fecha: ver lista de "Pendientes conocidos" arriba (ninguno bloqueante).
5. ~~**Pruebas de UX/UI**~~ — **CERRADA, regresión general validada (2026-09-01).** `tsc --noEmit`/`npm run lint`/`npm run build` limpios (0 errores; único warning preexistente y ajeno en `RemUploadForm.tsx`) en todas las rondas de esta fase. Auditoría READ-ONLY de rutas/imports (Prime/404): cero referencias a los 6 archivos de Prime eliminados, cero imports rotos, wildcard `*` confirmado como único fallback sin capturar rutas válidas, todas las rutas principales de ATHENEA siguen registradas en `app/router/index.tsx`. Durante el checklist visual el usuario detectó el problema de legibilidad/overflow de tablas densas, resuelto en el **piloto A+B** (ver arriba, cerrado y validado). Auditoría READ-ONLY adicional de regresión sobre 12 pantallas representativas con `DataTable` (`/users`, `/health-centers`, `/audit`, `/rem-uploads`, "Decisiones funcionales por fila", `/rule-engine/catalog`, `/rule-engine/structures`, `/rule-engine/rules`, `/rule-engine/logs`, `/rule-engine/bindings`, `/rule-engine/comparison`, `SectionRulesTable`) identificó 3 hallazgos, ninguno bloqueante: **#1** (`UsersTable.tsx` duplicaba el fondo del header con un `HeaderCell` local preexistente al piloto) — **corregido y validado visualmente por el usuario** (se retiraron `bg-slate-50`/`-mx-2`/`px-2`, redundantes tras el fondo compartido de `TableHeader`; `HeaderCell` se conservó por su rol real de alineación/centrado de "Estado"/"Acciones"). **#2** (columna "Centro" de `RemUploadsPage.tsx` sin `max-width`/truncate, a diferencia de "Archivo") y **#3** (esquinas/radius: el fondo del header puede asomar por sub-píxeles en tablas sin wrapper `overflow-hidden` propio) quedan **registrados como observaciones no bloqueantes, explícitamente sin modificar**. Checklist visual del usuario completo para `/users`; el resto de las 12 pantallas quedó cubierto por la auditoría de código (metodología ya usada y aceptada durante toda la campaña) más la validación en vivo directa de "Decisiones funcionales por fila" (piloto A+B).
6. ~~Commit/push de UX/UI~~ — **CERRADO** (`54dc5cd`, pusheado a `origin/main`, ahead/behind 0/0).
7. ~~Despliegue controlado al servidor ATHENEA~~ — **CERRADO Y VALIDADO (2026-09-03)**. Servidor actualizado a `0fc193c` (`git fetch`+`merge --ff-only`, fast-forward), contenedores reconstruidos y en `running`, certificación REM promovida y verificada por checksum (392/392). Ver el checkpoint **"CIERRE DE JORNADA — 2026-09-03, ACTUALIZACIÓN FINAL DEL DÍA"** al inicio de esta sección para el detalle completo. **Producción ya no está pendiente de actualización — es base estable validada.**
8. ~~Smoke tests en servidor~~ — **CERRADO (2026-09-03)**. Validación manual real en Chrome contra producción: login, Dashboard, F5 (con sesión persistente — el bug que motivó este paso quedó resuelto), logout, 2FA, y una carga REM real completa — todo correcto.
9. **REM BM** → **REM BS** → **REM D** → **REM P** — en ese orden (decisión de roadmap registrada 2026-09-04, ver checkpoint "DECISIÓN DE ROADMAP" arriba). **Todavía no iniciado; primera tarea: auditoría READ-ONLY de REM BM (ver checkpoint arriba), no implementación directa.** La calibración funcional de Serie A ya está terminada (99%, solo A05/V y A30/C P1 congelados, ver checkpoint arriba) — no es un prerrequisito pendiente para empezar este bloque. Cada serie nueva reutiliza el pipeline maduro de Serie A (plantilla→detección→calibración→patrones→reglas→mismatches→resolución→tests→certificación→artefactos→versionamiento→promoción) preservando trazabilidad por año/serie/versión de plantilla/versión de estructura/reglas-versiones/bindings/certificación — nunca copia ciega de datos/reglas de Serie A. Cuando se autorice, desarrollar en **entorno local**, nunca en producción.
10. **Reglas de consistencia externa / inter-REM / saltos** — deliberadamente después de completar BM/BS/D/P, no antes (razón arquitectónica: evitar anclar relaciones entre series a estructuras todavía inestables/no calibradas — ver checkpoint "DECISIÓN DE ROADMAP" arriba). **No iniciado.**
11. Tras eso: **versionamiento transversal** → **salto de celdas** → **pruebas integrales / cierre** → resto del roadmap funcional (comparativos, metas, GES, YAPS/IAPS, paneles/reportes, epidemiología, No REM, biblioteca, auditoría/seguridad, automatizaciones).

**REM A, Seguridad/2FA, y el despliegue a producción quedan cerrados — no reabrirlos salvo defecto comprobado.** La calibración funcional de Serie A también está terminada (ver punto 5 del checkpoint arriba) — no es trabajo en curso. Esto **no significa que ATHENEA esté terminado como producto**: las demás series REM (BM/BS/D/P) ni siquiera han comenzado, y persisten los items puntuales congelados ya documentados (A05/V, A30/C P1, reglas 229/230, `DUPLICATE`, `130`/`133`). Los 4 cambios locales excluidos (`vite.config.ts`, 2 comandos `Diag*`, `backend/demo/`) deben permanecer separados, sin mezclarse accidentalmente con ninguna campaña posterior.

No iniciar ninguna fase futura de esta lista sin instrucción explícita. Si al reanudar el estado real (BD/Git) difiere de lo documentado aquí: **STOP y reportar la discrepancia antes de escribir nada.**
