# Estado al cierre de la sesión — 2026-08-12 (FASE 3 MOTOR DE REGLAS — FASE A PERSISTIDA, FASE B AUDITADA, FASE C EN CURSO)

Handoff de la campaña de reconciliación del motor de reglas REM (Fase 3), posterior al cierre de la calibración funcional Serie A (Fase 2 — ver sección siguiente más abajo, cerrada y **publicada en main el 2026-08-11, commit `f91bae8`**, no releer esa auditoría, ya está cerrada e íntegramente en el historial). Leer esta sección primero antes de continuar cualquier trabajo sobre `rem_rules`, `rem_rule_bindings` o el motor de evaluación de reglas — evita repetir Fase A o Fase B.

## Fase 2 — cerrada y publicada en main

Baseline: commit **`f91bae8`** ("feat(rem): complete Serie A structural and functional calibration"), HEAD de `main` al momento de escribir esto. Toda la campaña de calibración funcional Serie A (303/303 secciones, ver sección de abajo) quedó commiteada y en main antes de arrancar la Fase 3. Fase 3 arranca sobre ese baseline, sin volver a tocarlo.

## Fase A — bindings SAFE_1_TO_1 persistidos hacia estructura 63 (CERRADA, ya en BD real)

Ejecutada vía `php artisan rule:rebind-safe-to-structure --structure=63 --commit` (comando por defecto dry-run; requiere `--reason` y `--by` explícitos; solo agrega bindings nuevos vía `RuleBinding::updateOrCreate`, nunca toca `rem_rules`, nunca borra/desactiva bindings antiguos; reclasifica cada regla una segunda vez inmediatamente antes de persistir y aborta el lote completo si alguna deja de ser segura).

- **115 reglas SAFE_1_TO_1 vinculadas** a la estructura activa 63.
- Estado confirmado en BD real (verificado más de una vez en esta sesión, incluyendo después de la corrida de regresión de hoy): **`rem_rules=764`**, **`rem_rule_bindings=974`**, **bindings activos `bindable_type=structure, bindable_id=63` = 115**.
- `MAX(updated_at)` de `rem_rule_bindings` = `2026-08-11 21:21:04` — marca el momento exacto de esta persistencia; no ha cambiado desde entonces (verificado de nuevo hoy 2026-08-12), confirmando que nada se escribió después.

## Fase B — auditoría de reclasificación completa (CERRADA, solo lectura, nunca persistió nada)

Ejecutada vía `RuleBindingReconciliationService::classifyAllActiveRules()` (clase 100% de lectura — revisada línea por línea, sin un solo `save()`/`create()`/`update()`/`insert` en toda la clase). Clasifica las 6 categorías (`SAFE_1_TO_1`, `REQUIRES_REMAP`, `DUPLICATE`, `ORPHAN`, `BLOCKED_BY_ENGINE_GAP`, `ALREADY_STRUCTURE_AGNOSTIC`) para cada regla `status=active` contra la estructura 63, excluyendo automáticamente las hojas `no_utilizada` del cálculo de candidatos seguros.

**Re-ejecutada en vivo el 2026-08-12 (solo lectura, para verificar el handoff) contra el estado real de la BD** — 753 reglas activas clasificadas (de 764 `rem_rules` totales; las 11 restantes no están `active`):

| Clasificación | Conteo |
|---|---|
| `SAFE_1_TO_1` | 136 (115 candidatas reales tras excluir 21 en hojas `no_utilizada` — coincide exacto con los 115 ya persistidos en Fase A) |
| `REQUIRES_REMAP` | 66 |
| `DUPLICATE` | 22 |
| `BLOCKED_BY_ENGINE_GAP` | 331 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 |
| Total en hojas `no_utilizada` (cualquier categoría, excluidas del cómputo de candidatos) | 80 |

**Desglose funcional de los 66 `REQUIRES_REMAP`** (análisis manual de sesión anterior, no recalculado automáticamente por el servicio — el servicio no distingue niveles de confianza, esto es una subclasificación funcional sobre el mismo universo de 66, cuya suma coincide exacto: 44+2+1+19=66):
- **44 remapeables de alta confianza.**
- **2 de confianza media.**
- **1 obsoleta.**
- **19 requieren decisión funcional** (no automatizable, necesita revisión humana caso por caso).

**Resultado real de duplicados** (verificado en vivo hoy): **22** reglas clasificadas `DUPLICATE` (mismo sheet+sección+columna+tipo que otra regla activa).

## Fase C — auditoría de `sum_equals`/`total_row` (EN CURSO, próximo trabajo a retomar)

Investiga el gap arquitectónico ya documentado como deuda técnica #5 de la campaña de calibración (Serie A): reglas `sum_equals` verticales cuyo `total_row` convencional (`row_to + 1`) puede caer fuera del rango `[row_from:row_to]` que el prefiltro de `RuleEngineService::execute()` aplicaba antes del fix de hoy, o directamente fuera de `rem_data` por ser un TOTAL/subtotal técnico excluido de persistencia (mecanismos #8/#12 de la campaña de calibración).

- **Universo real auditado: 507 reglas verticales** (`sum_equals` con `row_range` y patrón de columna vertical).
- **Causa raíz confirmada**: el prefiltro `[row_from:row_to]` de `RuleEngineService::execute()` descartaba la fila `total_row` antes de que `SumEqualsEvaluator::evaluateVerticalAggregation()` pudiera evaluarla, incluso cuando esa fila sí estaba persistida en `rem_data` — el fix de hoy (ver "Estado local" abajo) agrega `total_row` como excepción explícita al filtro.
- **135 reglas resolubles solo con el fix del evaluador** (el fix del prefiltro basta, la fila TOTAL está persistida y accesible).
- **223 reglas con config incompleto** (falta `total_row` en la config de la regla, o el valor declarado no es válido contra la sección actual — no las arregla el fix del evaluador por sí solo).
- **149 reglas cuyo TOTAL es técnico y queda fuera de `rem_data`** por los mecanismos #8/#12 de exclusión de la campaña de calibración (TOTAL final inmediato / subtotal embebido hacia atrás) — este es exactamente el gap de deuda técnica #5, sigue sin resolución de diseño.
- 135+223+149 = 507, cuadra con el universo auditado.
- **No se ha tomado ninguna acción de escritura sobre reglas o bindings en Fase C todavía** — solo el fix de código en `RuleEngineService.php` (ver abajo, sin commit) y la auditoría de clasificación.

## Estado local del working tree (sin commit, todo Fase 3)

- `backend/app/Domain/RuleEngine/Services/RuleEngineService.php` — **modificado localmente, sin commit**: agrega `total_row` como excepción al filtro `[row_from:row_to]` (fix de causa raíz de Fase C, ver arriba). Escrito el 2026-08-11 17:51, antes de que corriera Fase A (21:21).
- **Archivos nuevos sin commit** (Fase A/B):
  - `backend/app/Console/Commands/RuleRebindSafeToStructureCommand.php`
  - `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php`
  - `backend/tests/Feature/REM/RuleRebindSafeToStructureCommandTest.php`
  - `backend/tests/Feature/RuleEngine/Services/RuleBindingReconciliationServiceTest.php`
  - `backend/tests/Feature/RuleEngine/Services/RuleEngineServiceTotalRowFilterTest.php`
  - `docs/CHECKLIST_VALIDACION_POST_DESPLIEGUE.md`, `docs/arquitectura-modulo-gestion-certificacion-reglas.md`, `docs/certificacion-catalogo-serie-a.md`
- **No commit, no push de nada de Fase 3 todavía.** Todo permanece local sobre el baseline `f91bae8`.

## Regresión `Feature/REM` — cerrada y aprobada (2026-08-12)

La corrida quedó inconclusa la noche del 2026-08-11 por un apagón del equipo mientras corría (confirmado por el corte abrupto de `storage/logs/laravel.log` a las 22:01:46, sin proceso `php` colgado). Se volvió a correr completa hoy, **en solitario** (nunca en paralelo con `Feature/RuleEngine`, ver nota operativa de la campaña de calibración más abajo).

- **133 tests / 490 assertions / 129 passed / 4 failed / 0 errors.**
- Los 4 fallos son **exactamente los preexistentes de `StructurePersistenceServiceTest`** (mismos ya documentados en el cierre de la campaña de calibración) — sin regresiones nuevas en ningún otro archivo de la suite, incluyendo los tests nuevos de Fase A (`RuleRebindSafeToStructureCommandTest`).
- **Requiere más de 512 MB de `memory_limit`** para completar sin cortarse — el default de `php.ini` (512M) produce un *fatal error* de memoria a mitad de suite (test 98/133, `StructurePersistenceServiceTest::test_persists_structure_as_new_version`). Pico real observado con memoria ampliada: **~688 MB**. Para correr esta suite completa usar `php -d memory_limit=-1 vendor/bin/phpunit tests/Feature/REM` (flag de proceso, no requiere tocar `php.ini`).
- Reconfirma la nota operativa ya documentada: **no correr `Feature/REM` y `Feature/RuleEngine` en paralelo** (interferencia de `RefreshDatabase` contra el mismo esquema `esalud_testing`).

## BD real intacta tras toda la Fase 3 (Fase A + Fase B + regresión de hoy)

Verificado varias veces, última vez después de la corrida de regresión de hoy: **`rem_rules=764` / `rem_rule_bindings=974` / bindings activos a estructura 63 = `115`**. Ningún test de `Feature/REM` ni la reclasificación de Fase B tocó la BD real (`Feature/REM` usa `RefreshDatabase` contra `esalud_testing`; `RuleBindingReconciliationService` es de solo lectura).

## Próximo trabajo (Fase 3)

- Retomar Fase C: decidir diseño para las 149 reglas con TOTAL técnico fuera de `rem_data` (deuda técnica #5 de la campaña de calibración) y para las 223 con config incompleto (¿completar `total_row` manualmente por regla, o hay un patrón derivable automáticamente?).
- Decidir si se autoriza el commit del fix de `RuleEngineService.php` (total_row en el prefiltro) y de los archivos nuevos de Fase A/B — **no autorizado todavía**.
- Revisar manualmente los 66 `REQUIRES_REMAP` (44 alta confianza + 2 media + 1 obsoleta + 19 requieren decisión funcional) para decidir remapeo — no iniciado.
- Los 22 `DUPLICATE` y 331 `BLOCKED_BY_ENGINE_GAP` no tienen plan de acción todavía — pendiente de definir en sesión futura.

## Restricciones vigentes — Fase 3

- No repetir Fase A (`rule:rebind-safe-to-structure --commit`) — ya persistida, 115 bindings confirmados.
- No persistir Fase B — sigue siendo auditoría de solo lectura.
- No commit ni push de ningún archivo de Fase 3 (`RuleEngineService.php` modificado, `RuleRebindSafeToStructureCommand.php`, `RuleBindingReconciliationService.php`, tests nuevos, docs nuevos) sin autorización explícita.
- No modificar reglas (`rem_rules`) ni bindings (`rem_rule_bindings`) fuera de lo ya persistido en Fase A.

---

# Estado al cierre de la sesión — 2026-08-11 (CALIBRACIÓN FUNCIONAL SERIE A CERRADA AL 100%)

Handoff de la campaña de auditoría/corrección estructural REM Serie A. Leer este archivo primero antes de continuar cualquier trabajo sobre hojas REM — evita repetir auditorías ya cerradas.

## Cierre definitivo de la calibración funcional Serie A (2026-08-11)

**La calibración funcional de la Serie A queda cerrada: 303/303 secciones aplicables completadas, 0 pendientes, progress_pct=100%.** Auditoría final de cierre ejecutada y verificada (no solo declarada) contra el estado real de la base de datos y del endpoint HTTP real.

- **Estructura activa: ID 63 / versión 31**, confirmada sin alteración durante todo el cierre funcional — `hash_estructura=32e54067dff8857e67b59df921c830af` idéntico entre el backup pre-persistencia y el estado final.
- **Modelo "hojas no utilizadas" implementado y usado para el cierre real** — ver mecanismo #16 abajo. 5 hojas registradas como `no_utilizada` vía `rem:set-sheet-usage-status` (nunca SQL manual): **A21 (15 secciones), A24 (14), A25 (20), A30AR (15), A34 (11) = 75 secciones**, con motivo homogéneo ("Hoja no utilizada por Estadística APS para el proceso REM vigente."), responsable "Estadística APS" y estructura de referencia (ID 63) registrados en cada fila + su historial.
- **2 secciones cerradas como `calibration_applicability.status = not_calibratable`** (mecanismo #14): **A04/N** y **A32/E1** — ambas sin celdas capturables ni fórmulas funcionales, cierre confirmado por un humano (Francisco Arcos, `source_type=manual`, `closure_reason=no_calibratable_data`, `response=no_calibrable`), nunca auto-marcado.
- **A08/B cerrada como calibración normal** (`response=revisada`), no como `no_calibrable` — el pattern-split genuino investigado en la fase anterior (columnas AM66/AN66 con comportamiento real distinto en la fila 66) quedó con sus 2 patrones post-split confirmados **independientemente**, cada uno con `source_type=manual` y `derived_from_fingerprint=null` — se verificó explícitamente que NO se heredó/copió la respuesta histórica del patrón 61-66 anterior al split, tal como exigió el usuario.
- **Backup completo pre-persistencia**: `backups/esalud_dev_backup_20260811_130415.sql` (mysqldump, 3.6 GB, `Dump completed` confirmado) — tomado antes de registrar las 5 hojas no utilizadas, `backups/` está en `.gitignore`-equivalente local (no versionar, contiene datos reales).
- **Auditoría de coherencia sobre las 303 secciones aplicables**: recorrido directo (no solo el agregado cacheado) de `buildPatternMatrix()` por sección — 0 anomalías. Sin pendientes ocultas, sin secciones con `effective_section_reviewed=false` fuera de las ya contadas, sin patrones sin revisar en secciones marcadas completadas, sin cierres históricos regresivos (`historical_section_reviewed=true` con `effective=false`), sin `not_calibratable` con warnings pendientes.
- **Endpoint real verificado** (`GET /api/v1/rule-engine/catalog/calibration-summary`, dispatch interno del kernel HTTP con `auth:sanctum` real, sin levantar servidor): HTTP 200, `no_utilizadas` devuelve exactamente `A21, A24, A25, A30AR, A34`, totales idénticos a la simulación previa y al estado de base de datos.
- **Regresión final** (ver sección de tests más abajo): sin regresiones nuevas atribuibles a este cierre — únicamente los fallos preexistentes ya documentados en sesiones anteriores (4 en `StructurePersistenceServiceTest`, 35 en `RuleEngineIntegrationTest`/`FunctionalRuleEngineCertificationTest`/`RuleEngineServiceTest`, ninguno relacionado con `SectionCalibrationMatrixService` ni con el modelo de hojas no utilizadas).
- **Nota operativa para sesiones futuras**: correr `tests/Feature/REM` y `tests/Feature/RuleEngine` en paralelo (dos procesos `vendor/bin/phpunit` simultáneos) genera errores espurios de "table/column not found" en `esalud_testing` — ambas suites usan `RefreshDatabase` contra el mismo esquema de test y las migraciones de un proceso interfieren con el otro a mitad de corrida. No es una regresión de código: confirmado re-ejecutando `tests/Feature/REM` en solitario, resultado idéntico al run limpio previo. **Correr estas dos suites de forma secuencial, nunca concurrente.**

**No se hizo commit, push, deploy, rebuild, patch estructural, scan-cells ni ingest en ningún momento de este cierre.** Todo el trabajo permanece local.

## Hojas cerradas y aprobadas

- A19a (cerrada)
- A19b (cerrada) — ver deuda técnica de persistencia abajo, no requiere reabrir estructura
- A23 (cerrada)
- A26 (cerrada)
- A27 (cerrada)
- A28 (cerrada) — ver deuda técnica de persistencia abajo, no requiere reabrir estructura
- A29 (cerrada estructuralmente y funcionalmente; usuario confirmó calibración completa. "No realizar más cambios sobre A29 salvo que aparezca un hallazgo futuro.")
- **A30 (cerrada)** — usuario confirmó calibración manual completa de C (filas 99-108, columnas Z:AJ). "No realizar más cambios sobre A30 salvo que aparezca un hallazgo futuro." **A30/B queda cerrada también con su desfase conocido sin corregir** (ver deuda técnica #2 abajo) — no se autorizó tocarla.
- **A31 (cerrada)** — cerrada estructuralmente y funcionalmente, usuario confirmó calibración manual completa de las 5 secciones (A,B,C,D,E). "No realizar más cambios sobre A31 salvo que aparezca un hallazgo futuro."
- **A32 (cerrada)** — cerrada estructuralmente y funcionalmente, usuario confirmó calibración manual completa de las 19 secciones, incluyendo validación en interfaz del nuevo estado `calibration_applicability.status = not_calibratable` en E1 (ver mecanismo #14 abajo). "No realizar más cambios sobre A32 salvo que aparezca un hallazgo futuro."
- **A33 (cerrada estructuralmente)** — usuario aprobó el patch completo (estructura activa ID 63). Pendiente de calibración funcional manual — nunca calibrada, 0 patrones revisados en las 5 secciones (A,B,C,D,E).

### Resumen de A30 (referencia histórica)

6 secciones reales: **A, B, C, E, F, G** (confirmado que no existe "Sección D" en ningún lugar del libro). A, E, F, G corregidas estructuralmente. **C**: 25→36 campos (agregó columnas Z:AJ, bloque de encabezado secundario odontológico sin marcador en filas 95-97), `filaInicioDatos`/`filaFinDatos` sin cambios (81/108). Columnas B:Y reutilizadas conservan la etiqueta del bloque primario (limitación conocida, no resuelta). Patrones 1 y 2 intactos; patrón 3 (filas 99-108) recalibrado manualmente por el usuario para Z:AJ. **B**: nunca corregida, ver deuda técnica #2.

## Hoja cerrada: A31

**Cerrada estructural y funcionalmente** (usuario confirmó calibración manual completa de las 5 secciones). Corregida vía encabezados multinivel + `filaFinDatos` de E + TOTAL final inmediato (mecanismo #8 abajo).

| Sección | filaInicioDatos | filaFinDatos | Campos | Última col |
|---|---|---|---|---|
| A | 12 | 27 (excluye TOTAL final fila 28) | 49 | AW |
| B | 32 | 45 (excluye TOTAL final fila 46) | 32 | AF |
| C | 50 | 65 (excluye TOTAL final fila 66) | 32 | AF |
| D | 71 | 84 (excluye TOTAL final fila 85) | 20 | T |
| E | 89 | 91 (antes null) | 19 | S |

## Hoja cerrada: A32

**Cerrada estructural y funcionalmente** (usuario aprobó el patch completo y confirmó calibración manual de las 19 secciones). 19 secciones reales — las 3 entradas fantasma D/E/F (duplicadas de D1/E1/F1 por el bug de `filterAggregators()`, mecanismo #9) fueron **eliminadas** de la estructura mediante el nuevo modo de eliminación de `rem:patch-sheet-structure` (ver abajo). **E1 es la primera sección real clasificada `calibration_applicability.status = not_calibratable`** (mecanismo #14) — 0 celdas capturables, 0 fórmulas, 0 patrones; usuario confirmó el diagnóstico manualmente desde la interfaz, cerrada como `response='no_calibrable'` (no `'revisada'`).

| Sección | filaInicioDatos | filaFinDatos | Campos | Celdas escaneadas |
|---|---|---|---|---|
| A | 11 | 15 | 26 | 208 |
| B | — | — | — | 35 |
| C | — | — | — | 100 |
| D1 | 35 | 89 (excluye TOTAL fila 90) | 45 | 2655 |
| D.2 | — | — | — | 611 |
| E1 | — | — | — | 138 |
| E2 | — | — | — | 100 |
| F1 | — | — | — | 135 |
| F2 | — | 139 (fila 140 subtotal embebido y fila 151 TOTAL final, ambas excluidas — mecanismo #11 y #8) | — | 1125 |
| G | — | — | — | 385 |
| H | — | — | — | 245 |
| I | — | — | — | 120 |
| J | — | — | — | 28 |
| K | — | — | — | 60 |
| L | — | — | — | 84 |
| M | — | — | — | 105 |
| N | — | — | — | 48 |
| O | — | — | — | 21 |
| P | 216 | 216 (antes null) | 4 | 16 |

(Valores exactos de las secciones marcadas "—" fueron confirmados uno a uno contra la especificación del usuario durante el dry-run definitivo y no se repiten aquí en detalle — ver el informe de cierre entregado en el chat para la tabla completa fila por fila.)

**F2 — confirmación específica**: fila 140 (subtotal técnico embebido hacia atrás, mecanismo #11 nuevo) y fila 151 (TOTAL final, mecanismo #8) ambas permanecen visibles en `cell-data` como referencia técnica, ambas quedan fuera de `rem_data`, patrones y calibración. F2 activa muestra 1 patrón limpio de 20 filas reales de datos profesionales (0 excepciones).

**Matriz final de patrones — advertencias de negocio (solo referencia, no simplificar/fusionar)**: A = 2/1, D1 = 3/2, D.2 = 3/2, H = 2/1, I = 2/1, K = **2/1** (corregido — ver Deuda técnica #5), L = 4/4.

## Hoja cerrada estructuralmente: A33

**Cerrada estructuralmente** (usuario aprobó el patch completo). 5 secciones reales (A,B,C,D,E), sin fantasma/duplicadas/códigos hijo. Corregida vía encabezados multinivel (mecanismo #2: A y B de 3 niveles, D de 2 niveles), TOTAL final (mecanismo #8: fila 74 de E) y el nuevo mecanismo #15 (fila de dato real con concepto fuera de columna A absorbida como encabezado adicional — fila 56 de C ya no se pierde). Pendiente de calibración funcional manual — nunca calibrada, 0 patrones revisados.

| Sección | filaInicioDatos | filaFinDatos | Campos | Última col | Celdas escaneadas |
|---|---|---|---|---|---|
| A | 12 | 34 | 44 | AR | — |
| B | 39 | 51 | 49 | AW | — |
| C | 54 | 55 (excluye TOTAL fila 56) | 4 | D | 16 |
| D | 60 | 67 | 6 | F | — |
| E | 72 | 73 (excluye TOTAL fila 74; antes `filaFinDatos=null`) | 11 | K | 66 |

**Hallazgo A33/C** (mecanismo #15 nuevo): filas 54-55 ("Modalidad remota"/"Modalidad presencial", concepto en columna B, sin fórmula, densidad baja) se tragaban como encabezado adicional por el mismo motivo que ya protegía el mecanismo #3 (sin fórmula descalificante) — pero sus celdas de captura (`C54`/`D54`/`C55`/`D55`) están genuinamente desprotegidas (`unprotected`) en el Excel, a diferencia de una fila de encabezado real (siempre bloqueada). Ver mecanismo #15 abajo.

**Hallazgo A33/E — `filaFinDatos=null` causaba descarte silencioso de la sección completa** (`RemParserService.php`, guard `$start<=0||$end<=0` con `(int)null=0`). Confirmado contra las 70 cargas reales históricas de A33: `row_number` nunca superaba 67 — la sección E (Comités de Cuidados Paliativos) nunca capturó un dato real. No es riesgo de fantasma (nada se persistió), es pérdida de captura silenciosa, ya resuelta con el patch (`filaFinDatos=73`). El cell-data viejo de E estaba contaminado (escaneado hasta la fila 981 en vez de 73, 10043 celdas) — el re-scan post-patch lo reemplazó por uno limpio (66 celdas, filas 69-74 únicamente). **No se limpió ni reprocesó histórico** (instrucción explícita del usuario).

## Estado actual

- Estructura activa: **ID 63 (2026/A, versión 31)** — confirmada sin alteración durante el cierre funcional (hash idéntico antes/después).
- Historial acumulado: 53 → 54 (A29) → 55 (A30: A,C,E,F,G) → 56 (A30/C: columnas Z:AJ) → 57 (A31: A,B,C,D,E) → 62 (A32: 19 secciones, D/E/F eliminadas) → 63 (A33: A,B,C,D,E). Sin cambios estructurales desde A33 — el cierre funcional de la Serie A no tocó la estructura.
- Totales estructura-wide: 27 hojas, 378 secciones, 7744 campos. **Progreso funcional final: 303/303 secciones aplicables (100%)** — 75 secciones (5 hojas) fuera del cálculo por `no_utilizada`, 301 calibradas normalmente + 2 `not_calibratable` = 303 completadas, 0 pendientes.
- No existen patch/approve/activate pendientes. Existen 2 drafts huérfanos preexistentes, **no relacionados con esta campaña**: id=21 (v2) e id=22 (v3), creados 2026-07-20.
- **Nuevo en la DB (persistido, no estructura)**: tabla `rem_sheet_usage_status` con 5 filas (A21, A24, A25, A30AR, A34, todas `no_utilizada`) + `rem_sheet_usage_status_history` con 5 transiciones — ver mecanismo #16 y sección de cierre definitivo arriba.
- No hubo commit ni push en toda la sesión — todos los cambios de código están **locales, sin commitear**:
  - `backend/app/Domain/RemParser/Services/SectionDetectorService.php`
  - `backend/app/Domain/RemParser/Services/ColumnDetectorService.php`
  - `backend/app/Domain/RemParser/Services/EnhancedCellScanner.php`
  - `backend/app/Domain/REM/Services/RemParserService.php`
  - `backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php`
  - `backend/app/Domain/RuleEngine/Controllers/CalibrationViewController.php`
  - `backend/app/Console/Commands/RemPatchSheetStructureCommand.php`
  - `backend/app/Console/Commands/RemSetSheetUsageStatusCommand.php` (nuevo, mecanismo #16)
  - `backend/app/Domain/RuleEngine/Services/RemSheetUsageStatusService.php` (nuevo)
  - `backend/app/Domain/RuleEngine/Models/RemSheetUsageStatus.php`, `RemSheetUsageStatusHistory.php` (nuevos)
  - `backend/database/migrations/2026_08_11_000001_create_rem_sheet_usage_status_table.php` (nueva, ya ejecutada localmente)
  - `frontend/src/features/rule-engine/**` (varios, ver mecanismos #14 y #16)
  - Tests nuevos en `tests/Unit/RemParser/Services/`, `tests/Feature/REM/`, `tests/Feature/RuleEngine/Services/`
  - Tests con expectativas actualizadas (ver mecanismo #8): `RemPatchSheetStructureInsertionTest.php`, `SectionDetectorServiceRealFileRegressionTest.php`
  - `backups/esalud_dev_backup_20260811_130415.sql` — backup local pre-persistencia, no versionar.

## Hallazgos técnicos incorporados al motor (código, no estructura)

Cambios genéricos, no hardcodeados a ninguna hoja/sección específica. Los ítems 1-5 vienen de sesiones anteriores; 6 y 7 son de esta sesión.

1. **Corrección de `filaFinDatos` en la última sección de una hoja** (`SectionDetectorService::findDataEndRow`/`findDataEndRowWithGapDetection`). Evita arrastrar el total general de la hoja dentro de la última sección cuando hay un gap grande de filas vacías (>2 filas).

2. **Soporte de encabezados multinivel** (`SectionDetectorService::findTrailingHeaderRows`, `ColumnDetectorService`). Combina 2-3 filas de encabezado fusionadas cuando la columna A ya tiene texto en la fila superior.

3. **Detección de encabezados por densidad**. Una fila candidata se trata como encabezado adicional si llena >50% del ancho ya establecido con texto plano, incluso con una fórmula de control aislada muy lejos de ese ancho. Para filas con columna A poblada, la densidad >50% es un **requisito duro** (sin la excepción "sobrevive si no hay fórmula" que sí aplica cuando A está vacía) — hallazgo real A28/A.11: sin este requisito duro, 16 filas de datos reales sin fórmula (texto plano puro) se tragaban como encabezado.

4. **Exclusión de fórmulas como etiquetas de columna** (`ColumnDetectorService::cleanLabel`).

5. **Inserción segura de secciones completamente ausentes** (`RemPatchSheetStructureCommand`). **Pendiente de uso, no aplicado todavía**: A05/F, A06/K, A24/A-B, A25/C-D, A32/D.

6. **Exclusión de fila TOTAL líder** (`SectionDetectorService::isLeadingTotalRow`/`formulaReferencesOnlyRowsAfter`, y su equivalente independiente en `RemParserService::isEmbeddedLeadingTotalRow`/`formulaReferencesOnlyRowsAfter`). Una fila con columna A poblada con texto propio pero cuyas demás celdas son EXCLUSIVAMENTE fórmulas que agregan filas posteriores (nunca su propia fila) se excluye tanto de la estructura de referencia como — **esto es la parte nueva e importante** — de la persistencia real en `rem_data`.
   - **Hallazgo crítico**: el mecanismo de `SectionDetectorService` solo afecta la estructura (qué campos/filas quedan declarados); **no protege el pipeline real de `RemParserService::parseSheet()`**, que persiste cualquier fila cuyo `getCalculatedValue()` dé un número válido — y el resultado de una fórmula SUM siempre es un número válido. Confirmado empíricamente contra `rem_data`: esto ya afecta **A19b/A fila 52** ("TOTAL CONSULTAS...", 69 ocurrencias históricas con `total=1704` real) y **A28/B.1 fila 178** ("EGRESOS", 132 ocurrencias), además de A30/C fila 98. Es preexistente, no causado por ninguna corrección de esta sesión — descubierto al investigar el requisito de excluir la fila 98 de A30/C.
   - Fix: nuevo método `isEmbeddedLeadingTotalRow()` en `RemParserService.php`, evaluado antes de decidir persistencia, basado en `cell_data` ya escaneado (`es_formula`/`formula`) en vez de leer la fórmula cruda del Excel (que se carga con `setReadDataOnly(true)`, sin texto de fórmula disponible).
   - **Solo afecta cargas nuevas a partir de ahora** — no se limpiaron los registros fantasma ya persistidos en `rem_data` (69 + 132 + histórico de A30/C), por decisión explícita del usuario ("no limpiar datos históricos").
   - Tests: `tests/Feature/REM/RemParserServiceEmbeddedLeadingTotalRowTest.php` (6 tests: A19b/A, A28/B.1, A30/C, 2 casos negativos, no-alteración retroactiva).

7. **Detección de bloques de encabezado SECUNDARIOS** (`SectionDetectorService::findSecondaryHeaderBlocks`/`rowHasPlainTextBeyondColumn`, wireado a `ColumnDetectorService::detect` vía nuevo parámetro `$bloquesSecundarios`). Detecta, dentro del rango de datos ya aceptado de una sección, una fila con texto propio en columna A que introduce texto plano más allá del ancho ya conocido, sin marcador SECCIÓN que la anuncie. Reutiliza `findTrailingHeaderRows()` sin modificar, lo que de paso aplica también la exclusión de TOTAL líder embebido (#6) dentro del bloque secundario, sin código adicional. Aplicado en A30/C (columnas Z:AJ, ver arriba). Limitación conocida: columnas reutilizadas por el bloque secundario conservan la etiqueta del bloque primario (no se resuelve la doble semántica).
   - Tests: `tests/Unit/RemParser/Services/SectionDetectorServiceSecondaryHeaderBlockTest.php` (5 tests: detección básica, encabezado de 2 niveles propio, exclusión de TOTAL embebido dentro del bloque, 2 casos negativos de falso positivo).

8. **Exclusión de fila TOTAL FINAL inmediata** (hallazgo A31, 2026-08-10, filas 28/46/66/85 — patrón OPUESTO al #6: fórmulas que agregan EXCLUSIVAMENTE filas ANTERIORES de la misma sección, sin gap, al cierre inmediato del rango de datos). Una referencia a la propia fila —ej. `=+C28+D28`, el subtotal horizontal presente también en filas de dato real— es neutral; se exige al menos una referencia hacia atrás como evidencia genuina de TOTAL.
   - **Parte estructural** (`SectionDetectorService::excludeTrailingTotalRows`/`isTrailingTotalRow`, wireado en `findDataEndRow`/`findImplicitDataEndRow`): ajusta `filaFinDatos` para que la fila TOTAL quede fuera del rango de datos/patrones. Para que la fila siga siendo **visible como referencia técnica en cell_data** pese a quedar fuera de `filaFinDatos`, `EnhancedCellScanner::scan()` extiende su propio rango de escaneo con la misma lógica (`extendScanForTrailingTotalRows`) — sin esto, un futuro `rem:scan-cells` dejaría de capturar la fila TOTAL por completo. Aplicado en A31 (A,B,C,D).
   - **Parte de pipeline** (`RemParserService::isTrailingTotalRow`, independiente y basada en `cell_data`, igual que el mecanismo #6): protege la persistencia real incluso cuando la estructura activa de una sección **no fue reabierta** y sigue incluyendo la fila TOTAL en su rango declarado. Confirmado que este patrón **ya afectaba 5 hojas cerradas antes de este fix** — 25 filas TOTAL final ya persistidas como fantasma en `rem_data` histórico: A01 (D:74, H.2:200), A23 (A:23, B:41, E:66, F:73, G:84, H:90, L:130, M.1:142, M.2:153), A26 (A.1:47, G:110, H:120), A28 (A.3:61, A.4:69, A.5:77, A.6:97, A.7:105, A.8:113, B.2:195, B.3:206, B.4:215, B.6:249), A29 (E:132). **Estas 5 estructuras NO fueron reabiertas** — la protección de pipeline ya está activa para cargas nuevas de esas hojas, sin tocar sus estructuras ni limpiar su histórico.
   - 2 tests preexistentes con expectativas obsoletas (calculadas antes de este mecanismo) actualizados: `RemPatchSheetStructureInsertionTest.php` (A26/H: 120→119), `SectionDetectorServiceRealFileRegressionTest.php` (A01/D: 74→73, A01/H.2: 200→199, A09/B: 39→38, A09/C: 67→66, A09/F: 143→142, A09/F.1: 158→157, A09/F.2: 174→173) — todos verificados como positivos verdaderos contra el Excel real antes de actualizar, sin tocar ninguna estructura.
   - Tests nuevos: `tests/Unit/RemParser/Services/SectionDetectorServiceTrailingTotalRowTest.php` (6), `tests/Unit/RemParser/Services/EnhancedCellScannerTrailingTotalRowTest.php` (3), `tests/Feature/REM/RemParserServiceTrailingTotalRowTest.php` (9 — A31 A/B, A01, A23, A26, A28, A29, caso negativo, no-modificación de estructura).

9. **Corrección de ancho de sección en el mecanismo #8** (`SectionDetectorService::findDataEndRow`/`findImplicitDataEndRow`/`excludeTrailingTotalRows`/`isTrailingTotalRow`, hallazgo A32 2026-08-10). El mecanismo #8 original medía el ancho de la fila TOTAL contra `$highestCol` de **toda la hoja**, no contra el ancho real de la sección. Esto causaba falsos positivos en secciones anchas con columnas "control oculto" (100% fórmula, ej. columnas de validación muy a la derecha de A32/D1) — una fila de dato real terminaba excluida como si fuera TOTAL. Fix: se calcula `$anchoSeccion` (ancho real, desde el propio encabezado establecido) ANTES de llamar a `findDataEndRow`, y se propaga por toda la cadena de llamadas en vez de derivar el ancho del `$highestCol` de la hoja completa.

10. **`filterAggregators()` no reconocía códigos hijos sin separador de punto** (`SectionDetectorService::esCodigoSubseccion()`, hallazgo A32/D-D1-E-E1-F-F1, 2026-08-10). El código previo (`str_starts_with($otro, $codigo . '.')`) exigía un punto literal para reconocer "D1" como subsección de "D" — nunca hizo match, dejando "D"/"E"/"F" como entradas duplicadas fantasma junto a "D1"/"E1"/"F1". Fix: nuevo método que exige que el carácter inmediatamente después del prefijo del padre sea `.` o un dígito (nunca una letra cualquiera — evita falsos positivos como "AB" vs "A").

11. **Columna de concepto para fila TOTAL hardcodeada a columna A** (`SectionDetectorService::findConceptColumnForTotalRow`/`pareceEtiquetaTotal`, y sus equivalentes independientes `EnhancedCellScanner::findConceptColumnForTotalRowScan` y el fix en `RemParserService::isTrailingTotalRow` — hallazgo A32/F2 fila 151, 2026-08-10). El mecanismo #8 original solo buscaba el texto "TOTAL" en la columna A. Cuando el concepto vive en otra columna (ej. B, por fusión de encabezado), la fila TOTAL nunca se detectaba. Fix: búsqueda dinámica de izquierda a derecha dentro del ancho real de la sección, exigiendo que el texto encontrado contenga "TOTAL" o "AMBOS SEXOS" (sin este requisito, filas de dato real derivado como A09/I "Altas administrativas" —fórmula hacia atrás genuina, pero SIN etiqueta TOTAL— se detectaban como falsos positivos). **Este mismo fix corrigió un bug preexistente independiente en `RemParserService::isTrailingTotalRow()`** (pipeline real): la versión de pipeline nunca exigió que el concepto dijera "TOTAL", solo que existiera — gap nunca antes descubierto, expuesto al depurar los fixtures de test de este hallazgo.

12. **Subtotal embebido hacia atrás (fila técnica, ni TOTAL líder ni TOTAL final)** (hallazgo A32/F2 fila 140, A26/A.1 fila 41, A26/B fila 59 — 2026-08-10, Opción C aprobada por el usuario). Patrón nuevo: una fila 100% fórmula/bloqueada, con etiqueta tipo TOTAL, cuyas fórmulas agregan EXCLUSIVAMENTE filas anteriores de la misma sección, pero después de la cual **continúan datos reales** (a diferencia del #8, que es el cierre de la sección). Tratamiento (Opción C, no la única posible — ver deuda técnica #5 sobre el problema arquitectónico de fondo que esto NO resuelve): la fila permanece visible en `cell-data` como referencia técnica, pero se excluye de `rem_data`, de patrones y de calibración.
    - `RemParserService::isEmbeddedBackwardSubtotalRow()` (pipeline, basada en cell_data) protege la persistencia real.
    - `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` (independiente, misma lógica) hace que `classifyRow()` devuelva `'total'` en vez de `'data'`, lo que la excluye automáticamente de `buildColumnGroups()`/patrones vía el filtro ya existente `row_type === 'data'`.
    - **Bug encontrado DESPUÉS de persistir la estructura 62** (no capturado por los tests sintéticos, que usaban `esControlOculto: false` en todos sus fixtures): la primera versión de `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` excluía del escaneo las columnas marcadas `esControlOculto`, asumiendo (incorrectamente) que "control oculto" significa "irrelevante". En este código, `esControlOculto` significa "100% fórmula en toda la sección" (`ColumnDetectorService::isControlOculto()`) — para A32/F2, 43 de 45 columnas son así (son agregaciones de la misma fila, no valores crudos capturados), así que excluirlas dejaba CERO columnas donde buscar evidencia de suma hacia atrás, y la fila 140 reapareció en un patrón tras el primer scan-cells real. Fix: se eliminó la exclusión — el método ahora escanea TODOS los campos de la sección. Verificado contra la estructura 62 activada: F2 ahora muestra 1 patrón limpio, filas 140 y 151 ausentes de cualquier patrón.
    - Explícitamente **no resuelve** el problema arquitectónico de fondo de `sum_equals`/`total_row` (ver deuda técnica #5) — tratamiento puntual (Opción C) para esta fila, no una solución general.
    - Tests: `tests/Feature/REM/RemParserServiceEmbeddedBackwardSubtotalRowTest.php` (6), `tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceEmbeddedBackwardSubtotalRowTest.php` (6).

13. **Modo de eliminación en `rem:patch-sheet-structure`** (hallazgo A32, 2026-08-10). Hasta ahora el comando solo sabía REEMPLAZAR (código presente en base y en el parser fresco) o INSERTAR (código nuevo, ausente en base). No existía forma segura de eliminar un código presente en la base pero ya no producido por el parser fresco (caso D/E/F tras el fix del mecanismo #10) — intentarlo vía `--sections` habría insertado `null` en el array de estructura, corrompiéndola. Nuevo tercer modo: un código solicitado presente en base pero ausente del parser fresco se clasifica como ELIMINACIÓN, con una validación de seguridad obligatoria: otro código solicitado en el MISMO patch debe compartir el `filaHeader` original de la sección a eliminar (confirma que "absorbe" su rol) — si no, el comando aborta con "Abortando por seguridad" sin tocar nada. `printReport()` muestra `'ELIMINADA (autorizada)'`; `deepDiffOutsideScope()` exime las eliminaciones autorizadas de su chequeo de "secciones desaparecidas".
    - Tests: `tests/Feature/REM/RemPatchSheetStructureDeletionTest.php` (7 — dry-run reporta ELIMINADA, D removida/D1 actualizada con valores reales, orden final sin huecos, A/B/C/D.2 byte-idénticas, dry-run no persiste, caso negativo de seguridad, otras hojas sin afectar).

14. **Estado `calibration_applicability.status = not_calibratable`** (hallazgo A32/E1, 2026-08-11, capa de calibración funcional — no de estructura/persistencia). Una sección estructuralmente válida puede no tener NINGÚN contenido calibrable: 0 celdas editables, 0 fórmulas funcionales, 0 patrones, con el cell-data ya escaneado y sin advertencias pendientes. Antes de este mecanismo la UI de calibración forzaba 6 decisiones funcionales genéricas sin evidencia real. Genérico, recalculado en vivo en cada carga (`SectionCalibrationMatrixService::sectionHasNoCalibratableContent()`), nunca hardcodeado a una hoja/sección — si una sección recupera contenido calibrable en el futuro, vuelve automáticamente a `requires_calibration` y pide revisión de nuevo, conservando el cierre histórico como antecedente. Criterio (los 6 deben cumplirse a la vez): estructura y cell-data consistentes (sin discrepancia fila/columna, escaneo completo), cell-data disponible, sin warnings pendientes, sin celdas editables, sin fórmulas funcionales, sin patrones calibrables. Cierre requiere confirmación humana explícita desde la UI (`review_status='section_reviewed'`, `response='no_calibrable'`, distinto de `'revisada'` — nunca se auto-marca). **Aplicado y validado en A32/E1** (usuario confirmó manualmente desde la interfaz).
    - Archivos: `SectionCalibrationMatrixService.php` (backend), `CalibrationViewController.php` (valida `closure_reason`), `NotCalibratableSectionPanel.tsx` (frontend, componente compartido entre `QuickCalibrationPanel` y `PatternCalibrationSummary`/vista avanzada), `CalibrationSheetPage.tsx` (badge "No requiere calibración" distinto de "Revisada").
    - Tests: `tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceNoCalibratableContentTest.php` (17 — criterio aislado, caso negativo explícito sin cell-data, ciclo completo de cierre, reversión al recuperar contenido calibrable, sección real no afectada).

15. **Celda desprotegida dentro del ancho de encabezado como evidencia de dato real** (`SectionDetectorService::rowHasUnprotectedCellWithinColumns()`, wireado en `findTrailingHeaderRows()` — hallazgo A33/C, 2026-08-11). Una fila candidata a "encabezado adicional" con columna A vacía, densidad baja y sin fórmula (el mismo perfil que el mecanismo #3 protege del lado de headers reales) puede en realidad ser una fila de dato real cuyo concepto vive fuera de columna A y cuyas celdas de captura están vacías solo porque el archivo de referencia no tiene datos cargados — el chequeo de fórmula no distingue "vacía por ser encabezado" de "vacía por no tener captura todavía". Fix: antes de aceptar la fila, se verifica si existe alguna celda `getProtection()->getLocked() === 'unprotected'` dentro de `1..$anchoEncabezado` (el mismo ancho ya usado para densidad/fórmula) — si existe, la fila deja de aceptarse como header y el escaneo se detiene ahí.
    - **Acotado deliberadamente a `$anchoEncabezado`, nunca al ancho completo de la sección**: verificado empíricamente contra las 138 filas que el método clasifica hoy como encabezado adicional en las 10 hojas cerradas (A19a-A32) — la única con alguna celda `unprotected` es A32/K fila 186 (columna L, un residuo de formato sin relación con captura real, confirmado contra 38 ocurrencias históricas en `rem_data` con valor siempre null), pero esa celda cae FUERA del ancho de encabezado establecido en ese punto del escaneo. Si el chequeo usara el ancho completo de la sección, esa fila (un encabezado real de rango etario) se habría excluido incorrectamente.
    - Tests: `tests/Unit/RemParser/Services/SectionDetectorServiceTrailingHeaderUnprotectedCellTest.php` (8 — A33/C filas 54/55 dejan de ser header, `filaInicioDatos`/`filaFinDatos` resultantes correctos, encabezados multinivel de A33/A y A33/B siguen intactos, caso A32/K reconstruido y caso de borde exacto `anchoEncabezado+1` confirman que la celda fuera de ancho no descalifica).

16. **Modelo de "hojas no utilizadas" (`rem_sheet_usage_status`)** (hallazgo 2026-08-11, capa de negocio — no de estructura/parser/persistencia). Una hoja REM completa puede estar fuera de uso para el proceso vigente (determinación de Estadística APS, no técnica); antes de este mecanismo esas hojas contaban como "pendientes" en el progreso de calibración sin ninguna forma de excluirlas explícita y auditablemente. Diseño: tabla `rem_sheet_usage_status` (clave natural `anio+serie+sheet_name`, **no** `structure_id` — el estado de negocio sobrevive a un repatch de estructura) + `rem_sheet_usage_status_history` (historial completo de transiciones). `status` es **VARCHAR(30)**, nunca ENUM SQL — validado exclusivamente en Laravel (`RemSheetUsageStatusService::ALLOWED_STATUSES`) para no acoplar futuros estados a una migración. Ausencia de fila = `aplicable` (default, nunca se crea fila para hojas que no se apartan del default). Único punto de mutación: `rem:set-sheet-usage-status {sheet} {status} --serie= --year= --reason= --by= [--dry-run]` — exige motivo y responsable, nunca determina el estado automáticamente.
    - `SectionCalibrationMatrixService::computeStructureCalibrationSummary()` excluye por completo las secciones de hojas `no_utilizada` del cálculo de progreso: no cuentan como pendientes/completadas/no_calibratable, `progress_pct` usa `sections_aplicables` (nunca el total bruto de la estructura) como único denominador. Se exponen `sections_total_estructura`, `sections_no_utilizadas`, `sections_aplicables` y el array `no_utilizadas` (hoja, secciones, motivo, responsable, fecha, estructura de referencia) por separado, para que la UI las muestre sin mezclarlas con Pendiente/Revisada/No requiere calibración.
    - Frontend: `NO_UTILIZADA_LABEL`/`NO_UTILIZADA_STYLE` (badge "No utilizada", deliberadamente fuera de `CalibrationProgressStatus`) en Dashboard/Plantilla/Serie.
    - **Aplicado en el cierre real de la Serie A**: A21, A24, A25, A30AR, A34 (75 secciones) — ver sección de cierre definitivo arriba.
    - Tests: `RemSheetUsageStatusServiceTest.php` (7), `SectionCalibrationMatrixServiceSheetUsageStatusTest.php` (3), `RemSetSheetUsageStatusCommandTest.php` (5).

Tests: `tests/Unit/RemParser/Services/SectionDetectorService*Test.php`, `tests/Unit/RemParser/Services/EnhancedCellScanner*Test.php`, `tests/Unit/Console/Commands/RemPatchSheetStructureInsertionLogicTest.php`, `tests/Feature/REM/RemPatchSheetStructureInsertionTest.php`, `tests/Feature/REM/RemPatchSheetStructureDeletionTest.php`, `tests/Feature/REM/SectionDetectorServiceRealFileRegressionTest.php`, `tests/Feature/REM/RemParserServiceEmptyRowPersistenceTest.php`, `tests/Feature/REM/RemParserServiceEmbeddedLeadingTotalRowTest.php`, `tests/Feature/REM/RemParserServiceTrailingTotalRowTest.php`, `tests/Feature/REM/RemParserServiceEmbeddedBackwardSubtotalRowTest.php`, `tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceEmbeddedBackwardSubtotalRowTest.php`, `tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceNoCalibratableContentTest.php`, `tests/Unit/RemParser/Services/SectionDetectorServiceSecondaryHeaderBlockTest.php`, `tests/Unit/RemParser/Services/SectionDetectorServiceTrailingHeaderUnprotectedCellTest.php`. Última corrida completa (cierre de A33): `tests/Unit` completo → 149/149 passed. Barrido exhaustivo de las 10 hojas cerradas (114 secciones, fresh parser vs estructura activa): 32 diferencias, las 32 ya documentadas como deuda técnica preexistente (mecanismo #8 en 5 hojas nunca reabiertas + A30/B) — confirmado con el guard del mecanismo #15 temporalmente neutralizado que son exactamente las mismas 32, el mecanismo #15 no introdujo ninguna diferencia nueva.

## Deuda técnica / hallazgos pendientes (documentados, NO corregidos — requieren autorización futura)

1. **`pattern_fingerprint` solo depende del conjunto de filas, no de columnas ni editabilidad.** Confirmado empíricamente en A30/C tras el patch de columnas Z:AJ: el patrón 3 (filas 99-108) conserva exactamente el mismo `pattern_fingerprint` (`rowset_1dc75cf53d34f667`) de antes del patch, y `reconciliation.effective_section_reviewed` sigue en `true`, pese a que aparecieron 11 columnas nuevas nunca revisadas dentro de esas mismas filas. **Consecuencia real**: el sistema de calibración NO detecta ni alerta automáticamente cuando una estructura ya calibrada gana columnas nuevas sin cambiar su conjunto de filas — cualquier corrección futura de tipo "bloque de encabezado secundario" (mecanismo #7) sobre una sección YA calibrada correrá este mismo riesgo silencioso. Pendiente: extender el fingerprint (o un mecanismo paralelo) para que incluya columnas/campos, no solo filas. No implementado esta sesión por instrucción explícita del usuario ("no corregir eso ahora, solo documentar").

2. **A30/Sección B tiene un desfase estructural preexistente, de bajo riesgo confirmado.** La estructura activa declara `filaInicioDatos=70`, pero el código actual (con los fixes de encabezado multinivel ya aplicados) calcula `filaInicioDatos=72` — las filas 70-71 son encabezado real (rango etario + sexo, columna A vacía, sin datos de negocio), no datos. Confirmado que esto **no ha causado corrupción real**: en la única carga real procesada desde que se activó la estructura de A30 (upload 174), las filas 70/71 no se persistieron como datos de sección B — la validación numérica del parser (`validateCell`, exige entero) y el filtro de `cell_data` (`es_editable`/`esta_bloqueada`) ya actúan como red de seguridad incidental, porque el contenido de esas celdas es texto no numérico, a diferencia del caso de filas TOTAL (donde la fórmula calculada SÍ da un número válido). No autorizado a tocar — usuario explícitamente pidió mantener B intacta durante el patch de C.

3. **Secciones ausentes con mecanismo de inserción ya disponible, no aplicado**: A05/F, A06/K, A24/A-B, A25/C-D, A32/D (sin cambios desde sesiones anteriores).

4. **25 filas TOTAL final ya persistidas como fantasma en `rem_data` histórico de A01, A23, A26, A28, A29** (ver mecanismo #8) — la protección de pipeline ya evita NUEVAS ocurrencias, pero el histórico no fue limpiado (instrucción explícita del usuario). Sus estructuras activas tampoco fueron reabiertas/corregidas (siguen incluyendo la fila TOTAL en su `filaFinDatos` declarado) — pendiente de autorización futura si se decide reabrirlas para el fix estructural completo (igual que A31).

5. **Problema arquitectónico de fondo `sum_equals`/`total_row` NO resuelto** (documentado durante la investigación del Hallazgo 4 de A32, 2026-08-10, por instrucción explícita del usuario de "no resolver esto ahora, documentar como deuda técnica separada"). Convención `total_row = row_to + 1` (`RuleSetTotalRowCommand`): existen reglas `sum_equals` reales (6 confirmadas en A32/F2 columnas AN-AS, `row_range: {from:130, to:139}`) cuyo `total_row` convencional (140) es, precisamente, la fila que el mecanismo #12 excluye de `rem_data`/patrones/calibración por ser un subtotal técnico embebido — dejando esas reglas sin una fila real contra la cual validar la suma en producción. El mecanismo #12 (Opción C) resuelve la **visibilidad/clasificación** de la fila, no este gap arquitectónico de las reglas `sum_equals` que dependen de un `total_row` persistido. Pendiente de diseño futuro (posibles opciones no evaluadas aún: validar contra el valor de `cell-data` sin persistir en `rem_data`, redefinir `total_row` para este tipo de fila, u otra). No tocar sin autorización explícita — puede repetirse en otras hojas aún no auditadas.

6. **`getActiveStructure()` llamado de forma redundante durante el cómputo frío del resumen de calibración** (perfilado 2026-08-11 durante la investigación de las "77 secciones pendientes"): 1134 llamadas SQL redundantes (378 secciones × 3 llamadas independientes — `SectionCalibrationMatrixService::buildMatrix()` y `buildPatternMatrix()` cada una por su lado, más `CertificationService` con su propia copia sin caché) — ~3.85s de los ~60-80s totales del cómputo frío (~7%). El grueso del costo real (~57%) está en el enriquecimiento por fila de `buildPatternMatrix()` (listas `editables`/`bloqueadas`, descripciones de `functional_rules`, matching de `aggregated_rules`) sobre ~15 secciones con cell-data grande (0.9-3.6MB), no en las queries redundantes. El usuario autorizó memoizar `getActiveStructure()` **solo si se podía demostrar transparencia total mediante tests** ("si hay algún riesgo, dejarlo fuera por completo") — no se intentó la implementación en esta sesión por falta de tiempo/prioridad, sigue disponible como optimización de bajo riesgo pendiente de intentar. No se implementó snapshot, cache incremental, colas, ni refactor de `buildPatternMatrix()` (explícitamente fuera de alcance).

## Próximo trabajo

**La calibración funcional de la Serie A está cerrada al 100% (303/303 aplicables).** A33 sigue solo **cerrada estructuralmente** en el sentido de que sus 5 secciones ya fueron calibradas funcionalmente como parte de este cierre (contribuyen a los 301 calibrados). A34 fue registrada como **hoja no utilizada** (mecanismo #16) — ya NO requiere la auditoría estructural completa descrita abajo, salvo que Estadística APS revierta esa determinación en el futuro.

Si en el futuro se reactiva alguna de las 5 hojas no utilizadas (A21, A24, A25, A30AR, A34) vía `rem:set-sheet-usage-status <hoja> aplicable ...`, retoma el mismo procedimiento obligatorio que se usaba para auditar hojas nuevas:

1. Auditoría estructural completa (solo lectura).
2. Comparar Excel real vs parser actual vs estructura activa (ID 63).
3. Identificar: secciones faltantes, encabezados multinivel/3 niveles, encabezados secundarios embebidos (mecanismo #7), filas TOTAL líderes/embebidas/finales/subtotales embebidos hacia atrás (mecanismos #6, #8, #12), códigos de subsección sin punto separador (mecanismo #10), columna de concepto TOTAL fuera de columna A (mecanismo #11), filas de dato real absorbidas como encabezado adicional (mecanismo #15), secciones candidatas a `calibration_applicability.status = not_calibratable` (mecanismo #14), `filaFinDatos` null, truncamientos, columnas auxiliares, diferencias editables/bloqueadas.
4. Entregar informe (inventario, tabla por sección, matriz de patrones, posibles excepciones, conclusión).
5. **No modificar estructura hasta autorización explícita del usuario.**

Decisiones pendientes para sesión futura (no bloquean el trabajo siguiente): si se autoriza corregir A30/B (deuda técnica #2); si se autoriza reabrir A01/A23/A26/A28/A29 para el fix estructural completo de TOTAL final (deuda técnica #4); si se autoriza corregir el `pattern_fingerprint` basado solo en filas (deuda técnica #1); diseño futuro del gap arquitectónico `sum_equals`/`total_row` (deuda técnica #5).

## Restricciones permanentes

No tocar (salvo autorización explícita del usuario en una sesión futura):
- A19a, A19b, A23, A26, A27, A28, A29, A30, A31, A32, A33 (todas cerradas estructural y funcionalmente — incluye A30/B con su desfase conocido sin corregir, ver deuda técnica #2).
- **Toda la calibración funcional de la Serie A (303/303, 100%)** — incluye las 301 secciones calibradas normalmente, las 2 `not_calibratable` (A04/N, A32/E1) y las 5 hojas `no_utilizada` (A21, A24, A25, A30AR, A34). No modificar/recalibrar/revertir ninguna de estas sin autorización explícita.

Hallazgo documentado, no autorizado a tocar todavía:
- A05/F, A06/K, A24/A-B, A25/C-D (secciones ausentes, mismo mecanismo de inserción ya disponible; A24/A25 son además hojas `no_utilizada` — no aplica auditarlas mientras estén en ese estado). A32/D ya NO aplica — resuelto (era la entrada fantasma de D1, eliminada, no una sección realmente ausente).
- Estructuras activas de A01, A23, A26, A28, A29 (deuda técnica #4) — la protección de pipeline ya está activa, pero sus estructuras siguen sin corregir.
- `pattern_fingerprint` basado solo en filas (deuda técnica #1 arriba) — no corregir sin autorización explícita.
- Gap arquitectónico `sum_equals`/`total_row` para subtotales embebidos hacia atrás (deuda técnica #5) — no corregir sin autorización explícita, puede repetirse en hojas futuras.
- `getActiveStructure()` memoización (llamada redundante ~1134 veces en el cómputo frío del resumen de calibración, ~7% del costo total, ver deuda técnica #6 abajo) — evaluada pero deliberadamente NO implementada por riesgo no descartado con certeza.

General:
- No commit, no push, no servidor, no producción.
- No limpiar datos históricos de `rem_data` (filas fantasma de TOTAL ya persistidas antes del fix del mecanismo #6 — quedan como están, el fix solo protege cargas nuevas).
- No modificar/recalibrar A04/N ni A08/B automáticamente — ambas fueron cerradas por decisión humana explícita (Francisco Arcos, vía interfaz), no por el asistente.
- Todo trabajo futuro: local únicamente, hasta que el usuario autorice explícitamente lo contrario.
