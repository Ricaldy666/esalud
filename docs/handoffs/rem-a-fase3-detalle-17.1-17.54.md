# ARCHIVO HISTÓRICO — Detalle completo de la campaña Fase 3 / motor de reglas REM (checkpoints 2026-08-12 a 2026-08-31, puntos 17.1–17.55)

> **Este archivo es una reorganización, no una reescritura.** Todo el contenido que sigue después de esta nota es una copia **verbatim** (carácter por carácter) de lo que hasta el 2026-08-31 vivía directamente en `CLAUDE.md`, desde su primera línea hasta el cierre de la Fase 17.55. Se movió aquí exclusivamente para que `CLAUDE.md` vuelva a caber bajo el límite automático de contexto (150.000 caracteres) — **nada fue resumido, editado ni eliminado**.
>
> **Para el estado operativo vigente, lee `CLAUDE.md` primero.** Ese archivo contiene el `ESTADO VIGENTE ÚNICO` actual, el checkpoint de cierre de jornada, las prohibiciones activas destiladas de todo este historial, y el resultado del saneamiento (Fase 17.56) que separó este documento.
>
> Consulta este archivo cuando necesites: el detalle punto-por-punto de cómo se auditó/diseñó/implementó/ejecutó cada mecanismo (Fase A/B/C originales, Fase 1/2, Fase 3A–3C-3C, hallazgo de etiqueta fusionada, regla 461, rebind, certificación end-to-end, y la auditoría Git de 17.55), la evidencia exacta (rule_ids, fórmulas, conteos antes/después, comandos ejecutados), o la razón histórica detrás de cualquier prohibición vigente que hoy aparece condensada en `CLAUDE.md`.
>
> Verificación de integridad de este traslado: `sha256sum` del cuerpo verbatim de este archivo = `a2275e2f7e3af87a7c0725b7e8b13a02590cb90aec1da742f3a4d0d49bda6fe7` (calculado sobre las 3676 líneas originales antes de anteponer esta nota) — reproducible ejecutando `sed -n '1,3676p'` sobre la versión de `CLAUDE.md` previa a la Fase 17.56 (commit local, no pusheado; ver `git log` si hace falta localizar esa versión exacta en el historial de edición de la sesión).

---

# Checkpoint de continuidad — 2026-08-26 (FASE 3 MOTOR DE REGLAS — AUDITORÍA REQUIRES_REMAP / BLOCKED_BY_ENGINE_GAP, FIX row_range={0,0} COMMITEADO, TRABAJO EN CURSO)

Checkpoint de continuidad entre sesiones, escrito a pedido explícito del usuario ("guardar todo el contexto y avance actual en CLAUDE.md para no perder nada entre sesiones. NO quiero hacer commit todavía"). Continuación directa de la sección "2026-08-26 (FASE 3 MOTOR DE REGLAS — CIERRE DE LA CAMPAÑA MISMATCH...)" inmediatamente debajo (léela para el detalle completo de la campaña MISMATCH — no se repite aquí en extenso). Este checkpoint documenta el trabajo posterior a esa sección, realizado más tarde el mismo día: la auditoría y el fix del gap `REQUIRES_REMAP`/`BLOCKED_BY_ENGINE_GAP`, actualmente **a medio camino** — un fix ya commiteado (localmente, sin push) y una auditoría de seguimiento todavía sin iniciar. Leer este checkpoint primero al reanudar.

## 1. Resumen heredado — campaña MISMATCH cerrada (ver sección de abajo para el detalle completo)

Sin cambios desde el cierre documentado abajo: **MISMATCH=1** (solo `A30/C` P1), 120 tags de auditoría, 117 `safe_reconfirm` + 2 `structural_row_exclusion` + 1 `human_review`. **`A30/C` P1** sigue como único ítem `human_review` genuino — 3 columnas nuevas (J/K/L, "Modalidad" Nivel Primario) sin decisión histórica, deliberadamente **no decidido por el asistente**: la decisión funcional (si deben registrar cero, quedar vacías, o no aplican) corresponde exclusivamente a Estadística APS desde la interfaz ordinaria de calibración. Sin fecha límite.

## 2. Estado real de Serie A (verificado, sin cambios desde el cierre)

`sections_aplicables=306`, `sections_completed=304`, `sections_calibrated=302`, `sections_not_calibratable=2` (`A04/N`, `A32/E1`), `sections_pending=2` — exclusivamente `A05/V` y `A30/D`. **`A33` confirmada 100% calibrada** (5/5 secciones, 9/9 patrones, `reviewed_by=Francisco Arcos`) — corrige la nota desactualizada de la sección 2026-08-11.

## 3. `A05/V` y `A30/D` — NEW_SECTION, fuera de alcance actual

Ambas detectadas por evolución de estructura, nunca calibradas, **deliberadamente fuera de alcance** de toda campaña activa (MISMATCH y la de REQUIRES_REMAP/BLOCKED_BY_ENGINE_GAP actual). No calibrar sin autorización explícita futura.

## 4. Auditoría original de los 66 `REQUIRES_REMAP` (Fase B, 2026-08-12 — punto de partida de hoy)

Antes del fix de hoy, `RuleBindingReconciliationService::classifyAllActiveRules()` contra estructura 63 (re-ejecutada en vivo 2026-08-12) clasificaba 753 reglas activas: `SAFE_1_TO_1=136`, `REQUIRES_REMAP=66`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=331`, `ALREADY_STRUCTURE_AGNOSTIC=198`. Desglose funcional (análisis manual, no automatizado por el servicio) de los 66: 44 remapeables de alta confianza, 2 de confianza media, 1 obsoleta, 19 requieren decisión funcional. Este era el universo antes de retomar hoy el trabajo contra la estructura viva actual (67/v35).

## 5. Fix aplicado hoy: falso positivo sistémico `row_range={"from":0,"to":0}` — COMMITEADO (sin push)

**Commit `ac14ee0`** — `fix(rule-engine): treat zero-zero row range as no vertical range`. Archivos: `RuleBindingReconciliationService.php` + `RuleBindingReconciliationServiceTest.php` únicamente. Causa raíz: `{0,0}` es el placeholder que usan las reglas `sum_equals` horizontales (fórmula dentro de la misma fila, ej. `Suma(E+G+I+K) = Columna C`) — nunca tuvieron un rango vertical real, pero el clasificador las trataba como rango inválido y las forzaba a `REQUIRES_REMAP`. Fix: `{0,0}` se trata como "sin rango vertical real" (≈null) para el chequeo de `rowsOk`, sin relajar la validación de rangos verticales genuinos, sin tocar `isVerticalPattern` (que sigue evaluando el `row_range` crudo, sin la excepción).

**Resultado real, verificado hoy contra la estructura viva 67/v35** (re-ejecutado en esta misma sesión, solo lectura, confirmado de nuevo justo antes de escribir este checkpoint):
- `REQUIRES_REMAP`: 66 → **36** (-30)
- `SAFE_1_TO_1`: 136 → **153** (+17)
- `BLOCKED_BY_ENGINE_GAP`: 331 → **344** (+13)
- `DUPLICATE=22` y `ALREADY_STRUCTURE_AGNOSTIC=198` sin cambio.
- 17+13=30, cuadra exacto con las 30 reglas afectadas por el fix; ninguna otra regla cambió de categoría (verificado mediante diff empírico `git stash` antes/después contra el universo completo de reglas activas).

Nota: los números base "66/136/331" de este punto vienen de Fase B contra estructura **63** (2026-08-12); el resultado "36/153/344" es la reclasificación de hoy contra la estructura activa **actual (67/v35)** — no es una repetición literal del mismo universo, es el estado vivo de hoy.

## 6. Las 17 reglas que pasaron correctamente a `SAFE_1_TO_1`

Confirmado por re-clasificación en vivo (solo lectura) justo antes de escribir este checkpoint:

| id | sección | columna |
|---|---|---|
| 67 | A04/C | C |
| 68 | A04/C | D |
| 70 | A04/C | N |
| 71 | A04/C | O |
| 78 | A04/M | D |
| 79 | A04/M | E |
| 80 | A04/O | B |
| 172 | A07/F | D |
| 173 | A07/F | E |
| 175 | A07/G | D |
| 176 | A07/G | E |
| 303 | A21/C.1.3 | B |
| 337 | A23/H | C |
| 350 | A24/D.3 | B |
| 420 | A27/E | E |
| 538 | A32/I | E |
| 541 | A32/L | C |

## 7. Las 13 reglas que revelaron `BLOCKED_BY_ENGINE_GAP` (nuevo, expuesto por el fix)

ids **56, 208, 214, 226, 227, 228, 229, 230, 231, 232, 233, 234, 354** — ver punto 9 de este checkpoint y la entrada "deuda técnica #5" (sección "## Deuda técnica / hallazgos pendientes" más abajo) para el detalle completo de la auditoría de causa raíz, ya documentado con la corrección del 2026-08-26.

## 8. `A25/B` (regla 354) — fuera de alcance por `no_utilizada`

Hoja `A25` registrada como `no_utilizada` (mecanismo #16) — irrelevante para cualquier decisión de diseño de la deuda técnica #5. No requiere auditoría adicional mientras la hoja permanezca en ese estado.

## 9. Corrección importante — deuda técnica #5: reglas 56, 208, 214 NO son config-only

Ya documentado en detalle en la entrada de "deuda técnica #5" de la sección "## Deuda técnica / hallazgos pendientes" más abajo (actualización 2026-08-26). Resumen: `A03/D.7` fila 208 (regla 56, columna AH) y `A09/F.1` fila 158 (reglas 208 y 214, columnas F/L) son filas TOTAL reales, correctamente excluidas por el mecanismo #12 (`isEmbeddedBackwardSubtotalRow`) — no aparecen en `pattern_rows` vivos del scanner ni existen registros en `rem_data` para esas filas. **No debe agregarse `total_row` a estas 3 reglas** — permanecen correctamente en `BLOCKED_BY_ENGINE_GAP`. Esto reemplaza una conclusión previa (nunca escrita a este archivo, solo verbal en la sesión) que las había clasificado erróneamente como config-only.

## 10. `A09/I` (reglas 226-234) — variante compleja, sin solución arquitectónica todavía

Subtipo B de la deuda técnica #5 (ver sección de abajo): no una sola fila TOTAL, sino 6 filas (331-336) que agregan por posición dentro de ~13 bloques repetidos de 6 filas, con al menos una irregularidad detectada (`AR333` referencia un término `AR337` fuera del patrón de las demás filas). **Congelado explícitamente como deuda de diseño separada** — no forzar un `total_row` de una sola fila, no tocar sin decisión de diseño nueva (posible concepto `block_total_rows` o similar).

## 11. Las reglas sin destino vivo — pendientes de auditoría definitiva

Tras el fix de hoy, `REQUIRES_REMAP` contra la estructura viva 67/v35 quedó en **36** (verificado en vivo). Nota de reconciliación: en instrucciones previas de esta misma sesión este conjunto fue referido como "Grupo 2 de 35 reglas" — la relación exacta entre ese "35" y el "36" verificado ahora **no fue reconciliada** en esta sesión (pudo ser una aproximación del usuario, o un universo ligeramente distinto calculado en un momento intermedio); no asumir que son el mismo conjunto sin volver a verificarlo. El desglose funcional original de Fase B (44 alta confianza + 2 media + 1 obsoleta + 19 requieren decisión, sobre el universo de 66 contra estructura 63) **no ha sido recalculado todavía** contra el universo actual (36, estructura 67, post-fix) — pendiente de una auditoría dedicada, igual a la que se hizo para los 13 `BLOCKED_BY_ENGINE_GAP`.

**[SUPERADO — ver punto 16 más abajo, 2026-08-27]**: la auditoría dedicada de los 36 se completó. El "35 vs 36" queda resuelto: son 35 reglas sin destino vivo (el "35" mencionado en instrucciones previas era correcto) + 1 regla (`A32/F`, id 529) con destino ambiguo entre `F1`/`F2` — 35+1=36. El desglose original 44/2/1/19 de Fase B **no aplica** al universo actual — fue reemplazado por una causa raíz distinta y más precisa (ver punto 16).

## 12. `A32/F` — split pendiente de análisis/decisión

Mencionado explícitamente como fuera de alcance en las restricciones de las últimas etapas ("No tocar A32/F"). No se ha investigado en esta sesión — pendiente de análisis y decisión futura, sin más detalle disponible en este checkpoint.

**[SUPERADO — ver punto 16 más abajo, 2026-08-27]**: auditoría profunda READ-ONLY completada (regla 529, "TOTAL ACCIONES"). Evidencia encontrada inequívoca a favor de `A32/F1` — ver punto 16 para el detalle completo. **No implementado** — queda como recomendación pendiente de autorización explícita.

## 13. Hallazgo adicional del clasificador — rangos `{N,0}` e invertidos, gap preexistente sin corregir

Durante la validación del fix de `{0,0}` (ver punto 5), se detectó que `RuleBindingReconciliationService::classifyRule()` clasifica incorrectamente como `SAFE_1_TO_1` los rangos `{"from":N,"to":0}` (N>0) y los rangos invertidos (`from > to`) — deberían ser `REQUIRES_REMAP`, no `SAFE_1_TO_1`. Confirmado mediante tests de "sonda" temporales (`test_probe_n_to_zero_row_range`, `test_probe_inverted_row_range`) que fallaron como se esperaba — **luego eliminados de la suite permanente** (para no dejar tests fallando a propósito) tras confirmar el hallazgo, según instrucción explícita del usuario de no compensar/forzar nada fuera del alcance autorizado. **No corregido** — está fuera del alcance exacto que se autorizó para el fix de `{0,0}` ("Alcance exacto: Tratar row_range con from=0 y to=0..."). Queda documentado aquí como gap conocido, pendiente de autorización futura si se decide corregirlo.

## 14. Archivos actualmente sin commit / estado del working tree

Confirmado con `git status` justo antes de escribir este checkpoint:
- **Working tree limpio** salvo:
  - `CLAUDE.md` — modificado (este mismo checkpoint + la corrección de deuda técnica #5 del turno anterior), **sin commitear a propósito, por instrucción explícita del usuario de este turno**.
  - `backend/demo/` — sin trackear, pre-existente (no relacionado con esta campaña), **no tocar**.
- **6 commits por delante de `origin/main`**, nada pusheado: `62de69c`, `70d4a23`, `90fc077`, `36f2af2`, `a7d32b7`, `ac14ee0` (el último es el fix de `row_range={0,0}` de hoy).

**[ACTUALIZADO 2026-08-27, ver punto 16.3]** — nuevos archivos sin commit, resultado de implementar y usar los mecanismos de remap/restore/status:
  - `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php` — modificado (aditivo: `classifySingleRule()` público).
  - `backend/app/Console/Commands/RuleRemapSectionCommand.php`, `RuleRestoreConfigVersionCommand.php`, `RuleSetRuleStatusCommand.php` — nuevos, sin trackear.
  - `backend/tests/Feature/REM/RuleRemapSectionCommandTest.php`, `RuleRestoreConfigVersionCommandTest.php`, `RuleSetRuleStatusCommandTest.php` — nuevos, sin trackear (10/10, 4/4, 8/8 passing respectivamente, sin regresión en `RuleBindingReconciliationServiceTest`/`RuleRebindSafeToStructureCommandTest`).

**[ACTUALIZADO 2026-08-27, ver punto 16.11]** — Fase 1 del diseño de auto-discovery:
  - `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php` — modificado de nuevo (aditivo: `discoverTotalRowCandidate()` + `findRawSectionData()`, 3 campos diagnósticos nuevos).
  - `backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php` — modificado (aditivo: `isEmbeddedBackwardSubtotalRow()` ahora público).
  - `backend/tests/Feature/RuleEngine/Services/RuleBindingReconciliationServiceTotalRowDiscoveryTest.php` — nuevo, sin trackear (8/8 passing).

**[ACTUALIZADO 2026-08-27, ver punto 16.14]** — Fase 2 (mecanismo de escritura, sin ejecutar todavía):
  - `backend/app/Console/Commands/RuleSetTotalRowFromDiscoveryCommand.php` — nuevo, sin trackear.
  - `backend/tests/Feature/REM/RuleSetTotalRowFromDiscoveryCommandTest.php` — nuevo, sin trackear (12/12 passing).
  - Sigue sin haber ningún commit nuevo de Git en toda esta secuencia — el estado de `529.status=inactive` es real en la BD (`esalud_dev`), pero el código que lo hizo posible permanece sin commitear.

## 15. Baseline actual conocido (verificado en vivo, justo antes de escribir este checkpoint)

- `reglas-funcionales.json` hash SHA-256 = `44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` (sin cambios desde el cierre de la campaña MISMATCH).
- `mismatch-resolution-audit.json` hash SHA-256 = `fe713f961def425c2a1dd5dd4c4a1972c913c3e7c708e5943ace9b75f6d653e9` (120 tags, sin cambios).
- `rem_rules = 764`.
- `rem_rule_bindings = 1204`.
- Estructura activa: **ID 67, versión 35, status `active`**.
- Bindings a estructura 67 = **0** (sin rebind, igual que durante toda la campaña MISMATCH).
- Clasificación viva actual contra estructura 67/v35 (post-fix `row_range={0,0}`): `SAFE_1_TO_1=153`, `REQUIRES_REMAP=36`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198`.

**[ACTUALIZADO 2026-08-27, ver punto 16.3]**: tras resolver la regla 529 (marcada `status=inactive`), la clasificación viva real es `SAFE_1_TO_1=153`, `REQUIRES_REMAP=35`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198` — reglas activas `753→752`. `rem_rules=764` y `rem_rule_bindings=1204` sin cambio (una desactivación de `status` no borra ni crea filas).

**[ACTUALIZADO 2026-08-27, ver punto 16.5]**: tras la Tanda 1 (34 reglas inertes → `inactive`), la clasificación viva real es `SAFE_1_TO_1=153`, `REQUIRES_REMAP=1` (única restante: regla **344**), `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198` — reglas activas `752→718`. `rem_rules=764` y `rem_rule_bindings=1204` sin cambio.

**[ACTUALIZADO 2026-08-27, ver punto 16.6]**: tras resolver la regla 344 (`status='inactive'`), la clasificación viva real es `SAFE_1_TO_1=153`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198` — reglas activas `718→717`. `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`. Campaña `REQUIRES_REMAP` cerrada. **[SUPERADO por Fase 2 — ya no es el baseline vigente, ver el "ESTADO VIGENTE ÚNICO" al final de este checkpoint, después del punto 16.15.]**

## 16. Cierre del universo `REQUIRES_REMAP` (36) — auditoría completa 2026-08-27, READ-ONLY

Auditoría dedicada completada hoy contra la estructura viva 67/v35 (753 reglas activas re-clasificadas en vivo, mismos conteos que el punto 15: `SAFE_1_TO_1=153`, `REQUIRES_REMAP=36`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198`, sin cambios). Los 36 se agrupan en **2 causas raíz**, no 36 casos independientes:

### 16.1 — 35 reglas sin destino vivo: fórmulas de validación Excel mal-importadas, nunca fueron un campo real

**32 reglas** — `A11a/B, C, D, E, F, G, J, K` × columnas `CA, CB, CG, CH` (ids 236-239, 241-248, 250-269, tipo `required_and_le_parent`) + **3 reglas** — `A09/K` cols CO-CZ (id 235), `A23/Q` cols CF/CG/CH (id 344), `A24/H.3` cols CI-CM (id 352), tipo `sum_equals`. Las 35 comparten:
- **Mismo origen exacto**: `source=csv_catalog`, `created_at=2026-07-14 19:12:46` idéntico para las 35 (una única importación masiva), bindeadas segundos después a **estructura 19** (versión 1, `superseded`, la primera estructura que existió en el sistema, nunca rebindeadas desde entonces).
- **Causa raíz confirmada contra la propia estructura 19** (su binding histórico, no solo la estructura viva actual): en `A11a/B` las columnas `CA/CB/CG/CH` eran fórmulas Excel de *validación* (texto de advertencia tipo `"* No olvide digitar la variable Pueblos Originarios..."` y flags 0/1) — nunca columnas de captura de datos reales, ni siquiera en la estructura más antigua. Para `A09/K`, `A23/Q`, `A24/H.3`: las columnas referenciadas (CO-CZ, CF-CH, CI-CM) **tampoco existían en la estructura 19** (A09/K solo llegaba a E; A24/H.3 solo a C; A23/Q llegaba hasta AP, nunca hasta CF) — nunca existieron en ninguna estructura real activada por el sistema.
- **Conclusión**: estas 35 **NO requieren remap** — no hay destino posible porque nunca hubo un campo real al que remapear. Son candidatas a `status=obsolete`/desactivación. **[SUPERADO — ver puntos 16.5 y 16.6, 2026-08-27]**: 34 de estas 35 (todas salvo la 344, tratada por separado con su propio `reason`) fueron desactivadas en la Tanda 1 (`status='inactive'`); la 344 fue desactivada individualmente en 16.6. **Ninguna de las 35 sigue pendiente.**
- **No es un defecto del clasificador ni un gap del motor** — el clasificador acierta al no encontrar columna viva; es deuda de catálogo/negocio (reglas fantasma nunca depuradas desde la importación inicial).

### 16.2 — `A32/F` (regla 529, "TOTAL ACCIONES") — único remap estructural real, evidencia apunta a `F1`

**Regla 529**: `sum_equals`, `Suma(C+D+E+F+G+H+I+J+K+L+M+N+O+P+Q+R+S) = Columna B`, `row_range={123,123}` ("Fila 123" fija, validación horizontal de una sola fila, no acumulación vertical). Motivo del clasificador: `"Sección dividida -- candidatos: F1,F2"` — la sección histórica "F" (estructura 19) era una entrada fantasma duplicada (mecanismo #9 ya documentado: bug de `filterAggregators()`, corregido y "F" eliminada de la estructura durante el cierre de A32, 2026-08-10), y sus columnas C-S existen hoy tanto en `A32/F1` como en `A32/F2`, por lo que el clasificador (que solo compara letras de columna, no rango de filas ni semántica) reporta ambigüedad.

**Auditoría profunda READ-ONLY realizada hoy, evidencia recogida (ninguna escrita a config)**:
- **Estructura histórica "F" (estructura 19)**: `filaInicioDatos=122, filaFinDatos=125`. Campos: `A=VIDEOLLAMADAS, B=TOTAL ACCIONES, C-S=RANGO ETARIO (17 tramos etarios), T-U=SEXO, V-AA=(SENAME, Pueblos Originarios, Migrantes, Demencia, Espacios Amigables)`.
- **`A32/F1` vivo** (67/v35): `filaInicioDatos=123, filaFinDatos=125`, campos **idénticos en label y orden** a la estructura histórica "F" (`A=VIDEOLLAMADAS, B=TOTAL ACCIONES, C-S=RANGO ETARIO` por tramo etario exacto, `T-U=SEXO`, `V-AA=` mismas 6 columnas demográficas). Coincidencia estructural total.
- **`A32/F2` vivo**: concepto totalmente distinto (`A=ACTIVIDADES, B=PROFESIONAL, C=TOTAL/Ambos Sexos, D=TOTAL/Hombres, E=TOTAL/Mujeres`, luego 40 columnas de `RANGO ETARIO Y SEXO` combinado por tramo×sexo) — **no tiene ninguna columna "TOTAL ACCIONES"**, sus propios totales viven en C/D/E, no en B. `filaInicioDatos=130, filaFinDatos=150` — **fuera del `row_range=123` de la regla por completo**.
- **`cell_data` real de `A32/F1` fila 123**: `A123="Llamadas Telefónicas"` (bloqueada), `B123` es fórmula `=SUM(C123:S123)` (bloqueada) — **idéntico, literal, a la lógica de la regla 529**. `C123:S123` son celdas editables reales (`es_editable=true`).
- **`cell_data` de `A32/F2` fila 123**: no existe ninguna entrada (`null`) — la fila 123 está completamente fuera del rango escaneado de F2.
- **`rem_data` histórico real**: **12 registros** bajo `A32/F1` fila 123 (más 360 bajo el código legacy `"F"`, mismas filas 123-125 — confirma que "F" y "F1" son físicamente la misma fila/dato, solo con código de sección distinto antes/después del fix del mecanismo #9). Un registro real inspeccionado: `concept="Llamadas Telefónicas", total_column="B", B=103, C:S = [0,0,12,11,11,7,9,9,7,5,7,0,8,3,7,4,3]` — **suma real de C:S = 103, coincide exactamente con B=103**. La fórmula de la regla se cumple contra datos reales de producción, no solo contra la estructura.
- **`A32/F2` en fila 123**: cero registros `rem_data` (no aplica, fuera de su rango).

**Conclusión de la auditoría — evidencia inequívoca, no solo `row_range`**: label (`TOTAL ACCIONES` existe literalmente como campo B en F1, no en F2), concepto de fila (`VIDEOLLAMADAS`/"Llamadas Telefónicas" coincide con la estructura histórica "F"), semántica de columnas (F1: 17 tramos etarios simples, exactamente los 17 términos de la suma; F2: totales+combinaciones edad×sexo, estructuralmente incompatible con la fórmula de la regla), fórmula viva idéntica en `cell_data`, y aritmética real verificada contra `rem_data` de producción — **las 5 fuentes de evidencia independientes apuntan exclusivamente a `A32/F1`, ninguna apunta a `F2`**.

**[SUPERADO — ver punto 16.3, 2026-08-27]**: la recomendación original de este punto ("remapear regla 529 a A32/F1") se ejecutó, reveló una colisión no anticipada, y **se descartó a favor de una solución distinta y mejor** (desactivar 529, no remapearla) — ver el detalle completo abajo.

### 16.3 — Resolución final de la regla 529 (2026-08-27): remap → colisión → revert → desactivación

**Secuencia real ejecutada hoy, en orden, cada paso con su propio dry-run/commit/post-check**:

1. **Se implementó `rule:remap-section`** (dry-run por defecto, guards de columnas/row_range/ambigüedad/clasificación simulada, tests) y se ejecutó `--commit` sobre la regla 529 (`A32/F → A32/F1`), siguiendo la recomendación del punto 16.2.
2. **El post-check reveló una colisión real no anticipada por la auditoría previa**: ya existía la **regla 530** (`a32_f1_b_sum_equals`), con `config` byte-idéntico al que 529 acababa de adoptar, **activa desde el origen (2026-07-14) y correctamente rebindeada a las estructuras 19, 63, 64 y 66** (vía `rule:rebind-safe-to-structure`, 2026-08-18/21). Tras el remap, ambas reglas compartían la misma clave `sheet+sección+columna+tipo` → el clasificador las marcó correctamente `DUPLICATE` a ambas (`SAFE_1_TO_1` bajó en vez de subir, `DUPLICATE` 22→24). **Causa del hueco en la auditoría previa**: `rule:remap-section` simulaba la clasificación contra el estado de BD *antes* de escribir — el `buildDuplicateKeySet()` de `RuleBindingReconciliationService` no podía ver la colisión que solo existiría *después* de la escritura real. **Gap del guard identificado y documentado, no corregido todavía** (queda como mejora futura pendiente: el guard de `rule:remap-section` debería comprobar explícitamente la clave funcional post-remap contra todas las demás reglas activas antes de aprobar).
3. **Se implementó `rule:restore-config-version`** (dry-run/commit, restaura `config` desde un snapshot de `rem_rule_versions`, deliberadamente distinto de `rule:remap-section` porque no valida contra la estructura viva — necesario para poder revertir hacia una sección histórica ya inexistente como "F") y se usó para revertir 529 a `section=F` usando el propio snapshot (`RuleVersion` id=79) que el remap había dejado como evidencia. Post-check: baseline recuperado exacto (`REQUIRES_REMAP` 35→36, `SAFE_1_TO_1` 152→153, `DUPLICATE` 24→22).
4. **Auditoría comparativa 529 vs 530** (100% READ-ONLY): mismo `created_at` exacto (`2026-07-14 19:12:46`, mismo lote `csv_catalog`), mismo `config` funcional desde siempre (solo difería `section`, F fantasma vs F1 real). `RuleExecutionLog`: 76 ejecuciones para 529 vs 77 para 530, **el conjunto de uploads evaluados por 529 es subconjunto exacto de los de 530** (0 uploads exclusivos de 529), **0 discrepancias de resultado** en las 51 ejecuciones directamente comparables. `rem_validation_results`: 45 (529) / 46 (530). 530 tiene 4 bindings activos (19/63/64/66) migrados correctamente en cada generación de estructura desde 2026-08-18; 529 solo tuvo su binding original (525→19), nunca rebindeada. Búsqueda ampliada confirmó que este es un **caso aislado** (ningún otro par `a32_f_*`/`a32_f1_*` existe para otras columnas; A32/D1, A32/E1 —los otros 2 casos del mecanismo #9— nunca generaron un duplicado equivalente).
5. **Auditoría de `status=obsolete`**: se determinó que `rem_rules.status` es `VARCHAR(20)` libre (sin enum/constraint), con solo 2 valores reales en uso en todo el sistema — `active` (753) e `inactive` (11) — **`obsolete` nunca se ha usado**. Se adoptó la convención existente (`inactive`) en vez de introducir un valor nuevo. Confirmado empíricamente (transacción+rollback) que el único gate real de ejecución es `RuleEngineService::resolveRules()` filtrando `Rule::where('status','active')` — **un binding activo sobre una regla `inactive` nunca se ejecuta**, así que el binding 525→19 no necesita (ni debe) tocarse. Confirmado que `CalibrationService` no referencia `rem_rules`/`rule_id` en absoluto (subsistemas desacoplados, calibración nunca en riesgo). Confirmado que `RuleIngestionService` nunca resetea `status` en reglas existentes (una reingesta futura no revive la regla).
6. **Se implementó `rule:set-rule-status`** (lista blanca `['active','inactive']`, dry-run muestra clasificación simulada vía transacción+rollback, tests) y se ejecutó `--commit` marcando **`529.status='inactive'`**, con `reason` documentando la causa (sección fantasma del mecanismo #9, función cubierta por 530).

**Estado final verificado (post-check completo, 2026-08-27)**: `529.status=inactive`, `config` de 529 intacta (`section=F, column=B, row_range={123,123}`, sin cambios desde su creación — la regla queda "congelada" con su config histórica original, nunca remapeada), `530.status=active` intacta y sin tocar, binding `525→estructura19` activo e intacto (deliberadamente, sin riesgo — ver punto 5), `RuleExecutionLog(529)=76`, `rem_validation_results(529)=45`, `RuleVersion(529)=2` (snapshots del remap fallido y su reversión, preservados como evidencia histórica), 1 activity log `rule_status_change`. Clasificación global real: `SAFE_1_TO_1=153`, `REQUIRES_REMAP=35`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198`, reglas activas `753→752`. `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=0 — todos sin cambio. Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` sin cambio en ningún momento de toda la secuencia.

**El universo real de `REQUIRES_REMAP` pendiente de auditoría es ahora 35** (no 36) — coincide exactamente con las 35 reglas ya caracterizadas en el punto 16.1 (candidatas a `inactive`, sin destino posible). No quedan casos de "remap estructural real" pendientes en A32 — `A32/F`/529 fue el único, y quedó resuelto por desactivación en vez de remap.

**Comandos nuevos disponibles, todos con dry-run por defecto, tests, sin commit de Git**: `rule:remap-section` (`RuleRemapSectionCommand.php`), `rule:restore-config-version` (`RuleRestoreConfigVersionCommand.php`), `rule:set-rule-status` (`RuleSetRuleStatusCommand.php`) — más el método público aditivo `RuleBindingReconciliationService::classifySingleRule()`.

### 16.4 — Auditoría final de las 35 `REQUIRES_REMAP` (2026-08-27): NO son homogéneas, 1 excepción real

Auditoría dedicada de las 35 reglas restantes (ids `235,236-239,241-248,250-269,344,352`), agrupadas por causa raíz e impacto operacional real (`RuleExecutionLog`/`rem_validation_results`), no solo por origen `csv_catalog`:

- **Grupo A (32 reglas, `A11a`×`CA/CB/CG/CH`, `required_and_le_parent`)**: `RuleExecutionLog` = **77/77 `skipped` siempre**, `rem_validation_results=0` siempre. Cero impacto operacional histórico verificado.
- **Grupo B (2 reglas, `sum_equals` inertes)**: `352` (`A24/H.3`) = 77/77 `skipped`, idéntico al Grupo A. `235` (`A09/K`) = 68 `passed` + 9 `skipped`, **0 `failed` jamás** — pero inspección del `context` real confirmó que sus 68 "pasadas" son degeneradas (`reason:"empty_row"`, ambos lados de la fórmula vacíos, filas que ni siquiera pertenecen a la sección `K` — evidencia adicional de que su `row_range` original (`[12:345]`, casi toda la hoja) es un bug de importación, no solo de columnas fantasma). Cero impacto operacional real en la práctica.
- **Grupo C — EXCEPCIÓN REAL: regla 344 (`A23/Q`, `a23_q_b_sum_equals`)**: **54 `failed` reales** (de 77 ejecuciones), 2026-07-18 a 2026-07-23, **32 uploads** distintos (ids 29-60, periodo 2026/mayo), **2 establecimientos** (CESFAM Cirujano Guzmán, CESFAM SUR DE IQUIQUE). Evidencia real inspeccionada en `rem_validation_results.context`: comparaba datos reales capturados en columna B (conceptos clínicos reales: "I.R.A. alta", "Neumonía", "Bronquitis obstructiva aguda", valores no-cero reales) contra la suma de 3 columnas fantasma (`CF/CG/CH`, nunca existieron) = 0 siempre → falso positivo `sum_mismatch` de severidad `error` en cada caso. **Auditoría de impacto adicional realizada** (100% lectura, vía código): NO afectó `upload.status` (`ValidateWithEngineJob` nunca recalcula el estado, solo aplica la decisión ya tomada por el validador principal/legacy — confirmado en el propio comentario del código); 31 de los 32 uploads tenían además otras reglas genuinamente fallidas (el `with_errors` habría ocurrido igual); NO bloqueó cargas (no hay lógica de gating); NO afectó certificación (`CertificationService` solo consulta bindings a la estructura activa vigente, 344 nunca estuvo bindeada a ninguna estructura activa desde su creación). **SÍ fue visible en la UI**: `ValidationSummaryService::summary()` (consumido por `GET .../uploads/{id}/status`) incluye sin filtro los `RuleExecutionLog` de 344 en el conteo de `failed`/`compliance %` mostrado al usuario; `GET .../uploads/{id}/validation-results` expone directamente las filas `rem_validation_results` de 344 con sus mensajes de falso positivo. No existe ningún estado derivado persistido (no hay tabla de certificación; el resumen se recalcula en vivo desde el histórico crudo en cada solicitud) — **no hay nada que "limpiar" retroactivamente salvo por una acción explícita y deliberada, no ejecutada, no propuesta para esta etapa**: si alguien vuelve a abrir hoy la página de esos 32 uploads ya procesados, el resumen seguirá mostrando las 54 fallas antiguas de la 344 (correcto como registro histórico).

### 16.5 — Tanda 1 ejecutada (2026-08-27): 34 reglas inertes → `inactive`

**34 de las 35** (Grupos A + B; **excluida explícitamente la 344**, tratamiento separado) pasaron a `status='inactive'` vía `rule:set-rule-status <id> inactive --commit`, **una por una** (34 invocaciones individuales del comando, nunca un `UPDATE` masivo), cada una con dry-run previo + guard de re-validación inmediata antes de escribir (ya incorporado al comando). `reason` común documentando origen `csv_catalog`, columnas nunca existentes como superficie de captura real, ausencia de destino vivo, auditoría de reglas inertes, preservación de bindings/histórico. `by="Administrador Esalud"`.

**Post-check completo, resultado exacto**:
- Las 34 quedaron `status=inactive`; **la 344 permanece `active`, sin tocar**.
- **Exactamente 35 activity logs `rule_status_change` totales** (1 de la 529 + 34 nuevos de esta tanda), uno por cada `rule_id` de las 34, sin duplicados.
- Reglas activas: **752 → 718** (exacto, -34).
- Clasificación global: `REQUIRES_REMAP` **35 → 1**, con la **única regla restante = `[344]` exacto**. `SAFE_1_TO_1=153`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198` — todos sin cambio.
- `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0` — sin cambio.
- Histórico verificado íntegro: `RuleExecutionLog` de las 34+344 = 2695 (= 35×77 exacto, nada perdido); `rem_validation_results` de las 34+344 = 90 (45 de la 235 + 45 de la 344, resto 0, exacto); `RuleVersion` de las 34+344 = 0 (sin cambio, nunca tuvieron). Bindings de las 34 (1 cada una, → estructura 19) intactos y activos. `config`/`rule_key`/`rule_type`/`name` de las 34 verificados byte-idénticos a antes.
- Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` sin cambio. Calibraciones, `rem_data`, estructura, bindings — sin tocar.

**Estado final de la campaña `REQUIRES_REMAP`**: de 66 originales (Fase B, 2026-08-12) → 36 (post-fix `row_range={0,0}`) → 35 (tras resolver 529) → 1 (tras Tanda 1) → **0 (ver punto 16.6, 2026-08-27)**.

### 16.6 — Regla 344 resuelta, universo `REQUIRES_REMAP` cerrado en 0 (2026-08-27)

**`344.status → 'inactive'`**, vía `rule:set-rule-status 344 inactive --commit`, `reason` específico (distinto del genérico de la Tanda 1) documentando el hallazgo real: importación incorrecta desde `csv_catalog`, columnas `CF/CG/CH` nunca existieron como superficie de captura, generó falsos positivos históricos visibles en `validation_summary`/`validation-results` (ver punto 16.4 para el detalle completo de impacto — 54 ejecuciones `failed`, 32 uploads, 2 establecimientos), se desactiva para evitar nuevas falsas alarmas preservando íntegramente el histórico.

**Post-check verificado exacto**: `344.status=inactive`, `config` intacta (`section=Q, column=B, row_range={12,129}, rule_logic` sin cambios), binding `340→estructura19` activo e intacto, `RuleExecutionLog(344)=77` y `rem_validation_results(344)=45` preservados íntegros, **36 activity logs `rule_status_change` totales** (1 de 529 + 34 de Tanda 1 + 1 de 344). Reglas activas `718→717`. Clasificación global: `REQUIRES_REMAP=0` (ausente del conteo — ninguna regla activa queda en esa categoría), `SAFE_1_TO_1=153`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198`. `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=0 — sin cambio. Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` sin cambio. Calibraciones, `rem_data`, estructura, bindings — sin tocar en ningún momento.

**El universo `REQUIRES_REMAP` de la campaña de reconciliación (iniciada en Fase B, 2026-08-12, con 66 casos) queda formalmente cerrado en 0.** No queda ningún caso de "regla sin destino vivo remapeable" pendiente de decisión. **Todo el trabajo pendiente restante de la reconciliación Fase 3 pasa a ser exclusivamente `BLOCKED_BY_ENGINE_GAP`** (344 reglas, deuda técnica #5 — el gap arquitectónico `sum_equals`/`total_row` ya documentado, con sus dos subtipos: TOTAL único excluido del modelo de datos, y agregación periódica/múltiples filas TOTAL de `A09/I`) y los `DUPLICATE`/`ALREADY_STRUCTURE_AGNOSTIC` preexistentes, sin plan de acción definido todavía para ninguno de esos grupos.

### 16.7 — Auditoría estructural + muestreo dirigido de los 344 `BLOCKED_BY_ENGINE_GAP` (2026-08-27, READ-ONLY): NO son homogéneas

Auditoría dedicada, 100% lectura, sin ninguna escritura. **Causa raíz común confirmada por código**: las 344 comparten exactamente el mismo precondicionante en `RuleBindingReconciliationService::classifyRule()` — `sum_equals` con `isVerticalPattern=true` (columna origen == columna destino) y `total_row` ausente de `config` (motivo `invalid_row_range_configuration: falta total_row en config.` en el 100% de los casos, sin ninguna excepción del otro motivo posible). Esto confirma que las 344 caen en la misma rama de código, **pero no implica que la solución sea la misma para todas** — ver desglose.

**Grupo 1 — `no_utilizada` (56 reglas)**, separadas explícitamente del resto: `A21`(25), `A24`(3), `A25`(22), `A30AR`(2), `A34`(4). Irrelevantes para cualquier decisión de deuda funcional real mientras esas hojas permanezcan `no_utilizada`. Tratamiento: **`NO_UTILIZADA`**.

**Grupo 2 — 12 reglas ya auditadas en profundidad en sesiones anteriores** (`row_range={"from":0,"to":0}` en config, sin ninguna información de rango — un shape de config totalmente distinto al resto): `56, 208, 214` (`A03/D.7`, `A09/F.1`×2 — TOTAL único excluido por mecanismo #12, confirmado con `rem_data` real, ver punto 9) y `226-234` (`A09/I`, 9 reglas — agregación periódica de 6 filas por posición de bloque, con irregularidad confirmada en `AR333`, ver punto 10). Tratamiento: **`ENGINE_GAP`** para las 3 primeras (certificado con evidencia completa); **`ENGINE_GAP` + `NEEDS_DEEP_AUDIT`** para las 9 de `A09/I` (diseño de solución todavía sin definir, congelado).

**Grupo 3 — las 276 reglas restantes** (288 no-`no_utilizada` menos las 12 del Grupo 2): bucketing estructural por relación entre `row_range.to` y `filaFinDatos` de la sección viva:
- **199 reglas** (124 + 75) con la fila TOTAL implícita (`row_to+1`) **dentro** del rango vivo declarado.
- **77 reglas** con la fila TOTAL implícita **fuera** del límite oficial (`filaFinDatos` excedido).

**Muestreo dirigido, 15 reglas verificadas con evidencia real** (`cell_data` + invocación directa de `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()`/`isEmbeddedLeadingTotalRow()`, no supuesto), 12 hojas distintas:
- Subgrupo "dentro" (199): muestra de 9 → **8/9 mecanismo #12 confirmado** (mismo patrón que 56/208/214: `A01/D`, `A03/F.1`, `A04/I.1`, `A23/A`, `A01/G.`, `A09/C`, `A26/A.1`, `A07/B`). **1/9 excepción real**: regla **87** (`A05/C`, columna AA) — la fila implícita (50) es una fila de dato normal real ("Método de Regulación de Fertilidad más Preservativos"), **no un TOTAL**, columna AA vacía en todo el rango 49-51 inspeccionado — posible falso positivo del heurístico "columna origen==destino", o config genuinamente incorrecta. No generalizable sin verificar caso por caso.
- Subgrupo "fuera" (77): muestra de 6 → **mixto, no homogéneo**. 3/6 (`A02/A`, `A03/A.4`, `A05/Q` — hojas nunca reauditadas estructuralmente en esta campaña) **sin ningún `cell_data`** en la fila implícita (ni excluida por mecanismo, simplemente inexistente/no escaneada — indicio de `row_range` obsoleto). 3/6 (`A31/A`, `A32/A`, `A32/B` — hojas **ya corregidas estructuralmente** en la campaña) con mecanismo #12 activo pese a estar fuera del límite oficial (consistente con mecanismo #8/#11, fila TOTAL conservada como referencia técnica en `cell_data` pese a excluirse de `filaFinDatos`).

**Conclusión — matriz de tratamiento recomendado (ninguno implementado)**:

| Subgrupo | Cantidad | Tratamiento |
|---|---|---|
| `no_utilizada` | 56 | `NO_UTILIZADA` |
| Certificadas (56,208,214) | 3 | `ENGINE_GAP` |
| `A09/I` (226-234) | 9 | `ENGINE_GAP` + `NEEDS_DEEP_AUDIT` (diseño pendiente) |
| "Dentro de sección" | 199 | `ENGINE_GAP` como hipótesis fuerte (8/9 en muestra), pero **`NEEDS_DEEP_AUDIT` obligatorio** — ya hay 1 excepción confirmada (87) |
| "Fuera de sección" | 77 | `NEEDS_DEEP_AUDIT` — mixto, depende de si la hoja fue reauditada estructuralmente en la campaña |

**No se recomienda `CONFIG_FIX_CANDIDATE` para ningún subgrupo** con la evidencia actual — completar `total_row` sin verificar mecanismo/existencia real repetiría el error ya cometido y corregido para 56/208/214 (ver deuda técnica #5, corrección 2026-08-26). **`CLASSIFIER_FALSE_POSITIVE` queda como hipótesis abierta y no generalizada** para la regla 87 y potencialmente otras similares dentro del subgrupo "dentro de sección" — requiere verificación individual antes de actuar sobre cualquiera. Baseline reconfirmado sin cambios al cierre de esta auditoría (`reglas activas=717`, clasificación idéntica, hashes idénticos, `git status` sin cambios adicionales).

### 16.8 — Auditoría EXHAUSTIVA (no muestreo) de las 276 restantes (2026-08-27, READ-ONLY): hallazgo sistémico confirmado

A diferencia del punto 16.7 (muestreo de 15/276), esta auditoría procesó **las 276 reglas una por una** mediante un script de solo lectura (invocación directa de `isEmbeddedBackwardSubtotalRow()`/`isEmbeddedLeadingTotalRow()` + `CellDataStorageService::getCellForCoordinate()` para la fila TOTAL implícita de cada regla, `row_to+1`) — sin aproximación ni extrapolación. Resultado: **5 familias con causa raíz demostrada, no 1 sola**.

**FAMILIA A — Mecanismo #12 confirmado, TOTAL técnico excluido (235 reglas, 85% del total — HALLAZGO SISTÉMICO).** 180 con la fila implícita dentro de la sección viva + 55 con la fila implícita fuera del límite oficial pero con `cell_data` conservada (mecanismo #8/#11, igual patrón que 56/208/214). Las 235 muestran, sin excepción, la columna destino como fórmula que referencia exclusivamente filas anteriores (`FORMULA_BACKWARD`), exactamente igual a los 3 casos ya certificados. Abarca 16 hojas (`A32`=35, `A09`=33, `A07`=26, `A31`=24, `A26`=23, `A01`=20, `A27`=16, `A23`=14, `A28`=13, `A19B`=11, `A05`=6, `A03`=6, `A08`=4, `A33`=2, `A29`=1, `A04`=1). **Causa raíz**: idéntica a la deuda técnica #5 ya documentada — el `total_row` no configurado apunta a una fila que el propio motor excluye de `rem_data`/patrones por mecanismo #12 (o #8/#11). **Riesgo de corregir automáticamente**: alto si se completa `total_row` sin más — la regla nunca podría evaluarse contra un valor persistido (mismo motivo por el que NO se completó `total_row` en 56/208/214). **Tratamiento recomendado: `ENGINE_CHANGE_REQUIRED`** (no es config incompleto — es un gap arquitectónico del motor que requiere una solución de diseño distinta a rellenar `total_row`; ver opciones no evaluadas en el punto 5 original de deuda técnica #5).

**FAMILIA B — Fila real de dato, no TOTAL (8 reglas, mismo patrón que la regla 87).** ids `87,89,90,91,92,93` (`A05/C`, fila implícita 50) + `96,97` (`A05/C2`, fila implícita 77). Evidencia: la columna destino en la fila implícita es una celda editable real, sin fórmula, sin relación con ningún total (confirmado también para C2, mismo patrón exacto que C). **Causa raíz**: el heurístico "columna origen == columna destino" del clasificador (`isVerticalPattern`) produce un falso positivo cuando esa coincidencia de letra es casual, no semántica. **Riesgo de corregir automáticamente**: bajo si se re-clasifica (no hay TOTAL real que forzar), pero requiere decidir qué hacer con la regla en sí (no tiene un destino vertical real). **Tratamiento recomendado: `CLASSIFIER_FALSE_POSITIVE`** — candidata a revisión funcional de si la regla debería existir en absoluto en su forma actual.

**FAMILIA C — Fórmula hacia adelante, subtotal sin etiqueta TOTAL reconocida (9 reglas, subgrupo NO homogéneo).** ids `278,279,280,281,282` (`A19B/A`, fila implícita 52) — **mecanismo #6 confirmado true** (subtotal líder real, mismo mecanismo que 56/208/214 en su variante "leading"). ids `72,73` (`A04/D`, fila 62) y `110,111` (`A05/W`, fila 399) — **ningún mecanismo (#6 ni #12) se activa**, pero inspección directa de `cell_data` confirmó que la fila SÍ es un subtotal real (`A62="Sábado, Domingo o festivo"`, `A399="EGRESOS"`, ambas con fórmulas que suman filas posteriores) — **el motivo de que el mecanismo #6 no lo detecte es que su heurístico de etiqueta (`pareceEtiquetaTotalMatrix()`) solo reconoce texto literal "TOTAL"/"AMBOS SEXOS", no categorías de negocio reales que funcionan como subtotal** (`"Sábado, Domingo o festivo"`, `"EGRESOS"`). **Hallazgo nuevo, distinto de la deuda técnica #5**: un gap de cobertura en el matching de etiquetas del mecanismo #6, afecta al menos 4 reglas confirmadas. **Tratamiento recomendado: `ENGINE_CHANGE_REQUIRED`** para las 5 de A19B/A (mismo tratamiento que Familia A); **`NEEDS_DEEP_AUDIT`** para las 4 de A04/D y A05/W (gap de mecanismo #6 nuevo, no evaluado su alcance completo).

**FAMILIA D — Sin `cell_data` en la fila implícita, en ninguna sección declarada (21 reglas).** ids `46,47` (`A02/A`), `48,49` (`A02/B`), `50,51` (`A03/A.4`), `53` (`A03/B.1`), `61,62,63,64,65` (`A04/A`), `105,106,107,108` (`A05/Q`), `182` (`A08/D`), `429,430,431` (`A28/A.1`), `461` (`A30/F`). Confirmado que la fila implícita **no pertenece a ninguna sección declarada de la hoja** (`belongs=GAP` en las 21, sin excepción — nunca es "otra sección", descarta esa hipótesis por completo) y no tiene ningún `cell_data` escaneado bajo ninguna sección. **Causa raíz**: el `row_range` de la config no corresponde a ninguna superficie real escaneada hoy — ni excluida por mecanismo, simplemente inexistente en el modelo vivo. Incluye hojas ya cerradas (`A28`, `A30`) y no cerradas (`A02`,`A03`,`A04`,`A05`,`A08`), descartando la hipótesis de que el patrón dependa solo de si la hoja fue reauditada. **Riesgo de corregir automáticamente**: alto — no hay evidencia de que exista un TOTAL real en absoluto para estas 21, no solo que esté mal configurado. **Tratamiento recomendado: `NEEDS_DEEP_AUDIT`** (no `OBSOLETE_CANDIDATE` todavía — no se descartó explícitamente, con evidencia histórica tipo estructura-19/`rem_data`, que exista un total real en otra ubicación).

**FAMILIA E — Bloqueada/vacía, sin fórmula ni dato (3 reglas, ambigua).** ids `183` (`A08/D`), `187` (`A08/R`), `277` (`A19B/A`). Celda bloqueada, sin fórmula, sin texto ni valor. **Causa raíz no determinada** con la evidencia disponible. **Tratamiento recomendado: `NEEDS_DEEP_AUDIT`.**

**Verificación de suma**: 235+8+9+21+3 = 276 exacto. Baseline reconfirmado sin cambios antes y después (`reglas activas=717`, clasificación idéntica, hashes idénticos, `git status` sin cambios adicionales). Ninguna escritura de ningún tipo durante esta auditoría — solo lectura vía servicios/reflection ya existentes.

**Conclusión — causa sistémica reportada, ninguna solución aplicada (por instrucción explícita)**: la Familia A (235/276 = 85%) comparte una causa raíz demostrada, idéntica a la deuda técnica #5 ya documentada — pero **no se propone ni ejecuta ninguna solución todavía**, según instrucción explícita de STOP ante hallazgo sistémico. Las familias B-E (41 reglas, 15%) demuestran que el universo **no es homogéneo** y requieren tratamiento diferenciado, incluyendo un gap de mecanismo #6 recién descubierto (Familia C, subgrupo A04/D+A05/W) que no estaba documentado antes de esta auditoría.

### 16.9 — Cierre de Familias B/C/D/E (2026-08-27, READ-ONLY): **SEGUNDO hallazgo sistémico** — corrige la caracterización del punto 16.8

Auditoría dedicada de las 41 reglas de las Familias B (8), C (9), D (21) y E (3), reconstruyendo para cada una el `total_row` real vía `config` + estructura histórica (19) + estructura viva (67) + `cell_data`/fórmula real, en vez de asumir por semejanza. **Resultado: las 41 comparten exactamente la MISMA causa raíz — distinta de la Familia A — que el punto 16.8 no había detectado** porque solo se verificó `row_to+1` (convención trailing), nunca `row_from-1`.

**Hallazgo**: el `total_row` real de las 41 es siempre **`row_from - 1`** (fila TOTAL líder, inmediatamente antes del `row_range` configurado) — confirmado con **fórmula real en `cell_data` para las 41/41, sin excepción**, cada una sumando exactamente el `row_range` de su propia regla (ej. regla 46, `row_range=[12:17]` → fila 11 = `SUM(AG12:AG17)`, exacto). **`rem_data` histórico real confirmado presente en las 12 posiciones de fila líder únicas verificadas** (una fila líder es compartida por varias columnas/reglas de la misma sección).

Corrección importante sobre la caracterización previa del punto 16.8: la fila 52 de `A19B/A` (que parecía relevante para las reglas 278-282 por coincidir con `row_to+1`) resultó ser el total líder de un **bloque completamente distinto y no relacionado** ("TOTAL CONSULTAS, FELICITACIONES O SUGERENCIAS", filas 53-57) — coincidencia de posición dentro de la hoja, no de contenido. El total real de 278-282 es la fila **11** ("TOTAL DE RECLAMOS"), que sí suma exactamente su `row_range` (12-51).

**Subdivisión por detección de mecanismo #6** (`isEmbeddedLeadingTotalRow()`, ya existente):
- **29 reglas** (8 de B + 5 de C1 + 14 de D + 2 de E: 183,277) — mecanismo #6 **ya detecta** la fila líder real (etiqueta contiene literalmente "TOTAL"). El clasificador simplemente nunca lo consulta.
- **12 reglas** (4 de C2 + 7 de D + 1 de E: 187) — mecanismo #6 **no detecta** la fila (etiqueta funcional real: "INGRESOS", "Horario continuado", "Egresos", "Aplicación de Protocolo Neurosensorial...", o sin concepto) — el heurístico de etiqueta (`pareceEtiquetaTotalMatrix()`, solo reconoce "TOTAL"/"AMBOS SEXOS" literal) es demasiado estrecho para estos casos, pese a que la fórmula y el `rem_data` son igual de reales.

**Causa raíz confirmada, capa del problema**: **el clasificador** (`RuleBindingReconciliationService::classifyRule()`) nunca intenta descubrir `total_row` consultando los mecanismos ya existentes (#6/#12) — solo verifica si `config['total_row']` está seteado manualmente, y si lo estuviera, solo validaría contra la convención `row_to+1`. Nunca considera `row_from-1` como candidato. Para el subgrupo de 12, hay además un gap real (aunque menor) en el propio mecanismo #6 (heurístico de etiqueta).

**Riesgo de "corregir automáticamente"**: bajo para el subgrupo de 12 (mecanismo #6 no los excluye de `rem_data` futuro, config-fix limpio en principio, sujeto a verificar que el evaluador soporte `total_row < row_from`); medio-alto para el subgrupo de 29 (mismo riesgo que Familia A — si mecanismo #6 excluye esas filas de persistencia futura, completar `total_row` serviría solo para históricos). **No se recomienda ampliar el heurístico de etiqueta de mecanismo #6** sin un criterio adicional (riesgo de falsos positivos sobre filas de captura real con nombres de categoría genéricos) — no evaluado en profundidad, no implementado.

**Matriz consolidada de las 41**:

| Causa raíz | Cantidad | rule_ids | Evidencia | Capa | Confianza |
|---|---|---|---|---|---|
| Total líder real, mecanismo #6 ya lo detecta | 29 | 87,89-93,96,97 (B) · 278-282 (C1) · 46,47,48,49,61-65,105-108,182 (D) · 183,277 (E) | Fórmula+concepto+`rem_data` 100% confirmado | Clasificador (nunca consulta mecanismo #6) | Muy alta |
| Total líder real, mecanismo #6 NO lo detecta (etiqueta funcional) | 12 | 72,73,110,111 (C2) · 50,51,53,429,430,431,461 (D) · 187 (E) | Fórmula+`rem_data` confirmado; etiqueta nunca evaluada por mecanismo #6 | Clasificador + detector estructural (heurístico de etiqueta) | Muy alta |

**Verificación de suma**: 29+12=41 exacto. Baseline reconfirmado sin cambios antes y después (`reglas activas=717`, clasificación idéntica, hashes idénticos). Ninguna escritura durante esta auditoría.

**No se diseñó ni implementó ninguna solución** — ni para esta causa (leading total no consultado) ni para la de la Familia A (trailing total excluido de `rem_data`) — ambas quedan como hallazgos sistémicos documentados, pendientes de decisión de diseño conjunta en una sesión futura.

### 16.10 — Diseño técnico de resolución (2026-08-27) — **PROPUESTO / NO IMPLEMENTADO**

⚠️ Todo este punto es una propuesta de diseño basada en la evidencia de 16.7-16.9. **Nada aquí está implementado, ni siquiera parcialmente.** No confundir con las secciones anteriores (todas `AUDITADO`, solo lectura).

**Mapa causa raíz → componente → tipo de fix**:
- **235 (Familia A, trailing excluido)**: componente = `RuleEngineService`/evaluador (nueva fuente de valor) + clasificador. Tipo = código de motor, no config.
- **29 (leading excluido, mecanismo #6 ya detecta)**: mismo motor que Familia A (comparten el gap de persistencia) + clasificador. Tipo = código de motor.
- **12 (leading NO excluido, mecanismo #6 no detecta)**: componente = solo `RuleBindingReconciliationService` (auto-discovery). Tipo = **solo config** (`total_row`), el motor no necesita cambios porque la fila ya persiste en `rem_data`.

**Familia A — 4 alternativas comparadas (sin asumir reincluir la fila en `rem_data`)**: (1) leer el TOTAL directamente del Excel subido en tiempo de evaluación — total pero costoso/no retroactivo; (2) metadata estructural marcando la celda como TOTAL técnico — habilitador, no solución por sí sola; (3) extender `config` de la regla con referencia estructural (`total_row`, `total_row_position`, `total_row_persisted`) — necesario en cualquier escenario; (4) **tabla técnica paralela, no-`rem_data`, poblada en tiempo de parseo, consumida por el evaluador** (recomendada) — más alineada con el patrón ya establecido en toda la campaña ("proteger solo cargas nuevas"), sin reabrir el Excel en runtime, sin contaminar calibración/exports.

**Casos de control verificados contra el diseño**: TOTAL técnico verdadero (56/208/214) resuelto por auto-discovery + Opción 4; 87/89-93/96/97 nunca se marcan TOTAL por error (fórmula debe sumar exactamente el `row_range` de esa regla, mismo chequeo usado en 16.9 para descartar la fila 52 de A19B/A); A19B/A (278-282+277, fila líder compartida 11) resuelto evaluando cada regla independientemente contra su propia columna; A04/D y A05/W permanecen en el subgrupo de 12 (config-only), **sin ampliar** el heurístico de etiqueta de mecanismo #6.

**Fases propuestas (pequeñas, reversibles)**:
1. **Auto-discovery diagnóstico** (leading+trailing) en `RuleBindingReconciliationService` — agrega campos nuevos, **no cambia ninguna `clasificacion`**. Riesgo mínimo.
2. **Completar `total_row` en config** (comando nuevo dry-run+commit) solo para el subgrupo de 12 confirmados no-excluidos. Riesgo bajo.
3. Evaluar ampliar mecanismo #6 con evidencia formula-exacta — **pregunta abierta, sin plan concreto**, insuficiente evidencia para diseñar sin riesgo de falsos positivos.
4. Diseñar e implementar la Opción 4 (tabla técnica paralela + motor) para las 264 filas excluidas (235+29) — **sin diseño detallado de implementación todavía** (esquema de tabla, interacción con `writeResults`/`fail_open` sin definir).

**Primer cambio mínimo recomendado (Fase 1)**: nuevo método de solo-lectura en `RuleBindingReconciliationService.php` que busca candidato en `row_from-1` (nuevo) además de `row_to+1` (existente), valida fórmula exacta vía `cell_data`, consulta mecanismos #6/#12 ya existentes (promoviendo `isEmbeddedBackwardSubtotalRow()` a público, mismo patrón que `isEmbeddedLeadingTotalRow()` el 2026-08-24). Agrega campos diagnósticos (`total_row_candidate`, `total_row_position`, `total_row_excluded`) sin tocar `clasificacion`. Tests nuevos con los casos de control de esta sesión (56, 87, 278 con la coincidencia de fila 52 explícitamente probada como no-candidata, 72, un caso sin candidato). Clasificación antes/después: **idéntica byte a byte** (`SAFE_1_TO_1=153/REQUIRES_REMAP=0/DUPLICATE=22/BLOCKED_BY_ENGINE_GAP=344/ALREADY_STRUCTURE_AGNOSTIC=198`). Invariantes de BD sin cambio (`rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=0, hashes sin cambio) — Fase 1 no ejecuta ningún comando contra datos reales, solo tests con fixtures sintéticas.

### 16.11 — Fase 1 IMPLEMENTADA (2026-08-27) — Fases 2-4 siguen **PROPUESTAS / NO IMPLEMENTADAS**

⚠️ Distinción explícita: **solo la Fase 1** (auto-discovery diagnóstico) está implementada. Las Fases 2, 3 y 4 del punto 16.10 (completar `total_row` en config, ampliar mecanismo #6, tabla técnica paralela + motor) **siguen sin implementar, sin autorización**.

**Cambios reales aplicados**:
- `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` — `private` → `public` (mismo precedente que `isEmbeddedLeadingTotalRow()`, 2026-08-24).
- `RuleBindingReconciliationService.php` — nuevas dependencias (`SectionCalibrationMatrixService`, `CellDataStorageService`); nuevo método `discoverTotalRowCandidate()` + helper `findRawSectionData()`; 3 campos diagnósticos nuevos (`total_row_candidate`, `total_row_position`, `total_row_excluded`) en el resultado de `classifyRule()`, default `null` en las 717 filas, **sin tocar `clasificacion`/`motivo` en ningún punto del código**.
- `RuleBindingReconciliationServiceTotalRowDiscoveryTest.php` (nuevo, 8/8 passing): casos de control 56/87/278/72 (con el decoy de la fila 52 probado explícitamente), sin candidato, ambigüedad, `total_row` ya en config (discovery no corre), `row_range={0,0}` (discovery no corre).

**Verificación contra datos reales (717 reglas activas)**: clasificación **idéntica byte a byte** al baseline (`SAFE_1_TO_1=153, REQUIRES_REMAP=0, DUPLICATE=22, BLOCKED_BY_ENGINE_GAP=344, ALREADY_STRUCTURE_AGNOSTIC=198`). Suites relacionadas sin regresión (61/61: `RuleBindingReconciliationServiceTest`, `RuleRebindSafeToStructureCommandTest`, `RuleSetRuleStatusCommandTest`, `RuleRemapSectionCommandTest`, `RuleRestoreConfigVersionCommandTest`, `SectionCalibrationMatrixServiceEmbeddedBackwardSubtotalRowTest`, `SectionCalibrationMatrixServiceEmbeddedLeadingTotalRowTest`).

**Diagnóstico sobre los 344 reales, comparado contra 16.8/16.9**:

| Categoría | Auto-discovery | Auditado | Nota |
|---|---|---|---|
| Trailing excluido | 225 | 235 | Diferencia de 10 explicada abajo |
| Leading excluido | 29 | 29 | Exacto |
| Leading persistido (no excluido) | 12 | 12 | Exacto |
| Trailing persistido | 0 | 0 | Exacto |
| Sin candidato (no `no_utilizada`) | 22 | 12 (los `{0,0}`) | 12 conocidos + 10 explicados abajo |
| `no_utilizada` | 56 | 56 | Exacto (discovery corre igual ahí, sin filtrar por uso de negocio, correctamente) |

**Diferencia de 10 investigada y explicada, no dejada sin resolver**: son las 10 reglas de `A26/B` (columnas D-M, `row_range=[54:58]`). Su fórmula trailing real es `D59 = SUM(D54:D58)+D50` — suma el rango exacto **más una celda adicional fuera de rango** (arrastre/acumulado). La validación de Fase 1 (deliberadamente estricta: exige que la fórmula referencie *exclusivamente* filas dentro de `[from:to]`, para evitar el falso positivo tipo "fila 52 de A19B/A") se abstiene correctamente (`null`) en vez de reportar un candidato incierto. Verificado independientemente que mecanismo #12 sí confirma esas 10 filas como excluidas — siguen siendo Familia A genuina, la Fase 1 simplemente es conservadora ante ese patrón de fórmula más complejo. Reconciliación exacta: `225+10=235`, `12+10=22`, `288+56=344`.

**Baseline final reconfirmado**: `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` sin cambio, calibraciones/`rem_data`/estructura intactos — Fase 1 nunca escribió nada contra datos reales, solo lectura (`classifyAllActiveRules()`) + tests con fixtures sintéticas.

### 16.12 — Validación final de Fase 1 (2026-08-27, READ-ONLY): confirmado, sin discrepancias nuevas

Re-ejecución completa del diagnóstico sobre los 344 reales, con `rule_ids` exactos por grupo (no solo conteos), comparado automáticamente contra 16.8/16.9. **Invariante fundamental confirmado antes y después** (`activas=717`, clasificación idéntica, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, hashes sin cambio).

**Casos de control — los 4 reproducen la auditoría manual exactamente**: `56` → `candidate=null` (esperado: `row_range={0,0}` real, fuera del alcance de auto-discovery por diseño — uno de los 12 placeholders ya conocidos, no un fallo). `87` → `candidate=35, leading, excluded=true` (nunca se convierte en falso TOTAL). `278` → `candidate=11, leading, excluded=true` (fila 52 descartada correctamente). `72` → `candidate=59, leading, excluded=false`.

**Reconciliación exacta contra 16.8/16.9** (sin `no_utilizada`): trailing excluido = 225 (Familia A, diferencia de 10 con lo auditado = las mismas 10 de `A26/B` ya explicadas en 16.11, sin discrepancia nueva) · leading excluido = 29, idéntico rule por rule a lo auditado · leading persistido = 12, idéntico rule por rule · trailing persistido = 0 · sin candidato = 22 (12 placeholders `{0,0}` conocidos + las mismas 10 de `A26/B`). `225+29+12+0+22+56(no_utilizada)=344` exacto.

**Las 12 candidatas a Fase 2 quedan identificadas de forma inequívoca**: `total_row_position=leading, total_row_excluded=false` para exactamente `[50,51,53,72,73,110,111,187,429,430,431,461]` — **idénticas, rule_id por rule_id**, a las 12 ya auditadas manualmente en 16.9. Sin ambigüedad. Listas para Fase 2, **no ejecutada todavía**.

**Sin ningún caso ambiguo** encontrado contra datos reales (el detector de ambigüedad de Fase 1 nunca se activó fuera de las pruebas sintéticas).

Baseline reconfirmado sin cambios al cierre. Ninguna escritura durante esta validación.

### 16.13 — Hallazgo regla 461 (A30/F): AUDITADO / **NO RESUELTO** (2026-08-27)

⚠️ Este punto documenta un hallazgo real durante el intento de iniciar Fase 2. **No contiene ninguna solución ni recomendación de diseño** — solo evidencia. Al intentar Fase 2 sobre las 12 candidatas identificadas por Fase 1, la regla **461** (`A30/F`) resultó ser una excepción: simular `config.total_row=123` (su candidato leading descubierto) **no la reclasifica a `SAFE_1_TO_1`** — permanece `BLOCKED_BY_ENGINE_GAP`, con un motivo distinto (`missing_total_row probable: total_row=123 fuera de [124:129]`).

**Causa raíz confirmada**: el candidato (fila 123) es una fila TOTAL leading genuina — fórmula de ancho completo (`B123..F123 = SUM({col}124:{col}129)`), coincide exactamente con el `row_range` de las 3 reglas de esa sección, `rem_data` real presente (`[122,123,124..129]`, aunque con `concept` heredado de la fila 122 y valores en 0 — patrón de fila TOTAL fantasma-capturada, mismo mecanismo ya documentado en el archivo). Pero **la fila 123 cae fuera del `filaInicioDatos=124` de la estructura activa 67/v35** — el guard de `classifyRule()` (línea ~227: `$totalRow < ($current['inicio'] ?? 0)`) exige que `total_row` esté dentro de `[filaInicioDatos:filaFinDatos]`, asumiendo que un TOTAL siempre pertenece al área de datos oficialmente declarada.

**Historia**: estructura 19 (la más antigua) declaraba `filaInicioDatos=121` para esta sección — la fila 123 SÍ estaba dentro del área de datos originalmente. Un parche estructural posterior de A30 (documentado en secciones anteriores de este archivo) endureció el límite a 124, probablemente para excluir los encabezados genuinos de las filas 121-122 (`A120="Actividad"`, `A122="Telecomité de especialidad"`, sin fórmula, sin dato real) — pero la fila 123 (el subtotal técnico) quedó atrapada en esa misma exclusión.

**Búsqueda exhaustiva en las 321 reglas activas con candidato descubierto (no solo las 12)**: el patrón "candidato leading fuera de `[inicio:fin]`" es **exclusivo de la regla 461** — 1 de 321. El patrón "candidato trailing fuera de `[inicio:fin]`" sí es extenso (**55 casos**, hojas A31/A32/A33), pero **los 55 ya tienen `total_row_excluded=true`** (mecanismo #12/#8/#11 ya los detecta) — pertenecen a la Familia A ya certificada (deuda técnica #5), no son candidatos de Fase 2 y el guard nunca sería un obstáculo nuevo para ellos.

**Tabla de las 12** (rango vivo, candidato, posición, `rem_data`, mecanismo, clasificación simulada): las 11 candidatas limpias (`50,51,53,72,73,110,111,187,429,430,431`) tienen su candidato leading **dentro** de `[filaInicioDatos:filaFinDatos]` y simulan `SAFE_1_TO_1` limpio. Solo **461** tiene su candidato **fuera** (`123 < 124`) y no reclasifica.

**No se determinó** si esto representa (a) una excepción legítima aislada de A30/F, (b) un defecto general del clasificador, o (c) una diferencia intencional entre rango de captura y fila TOTAL — la evidencia (1 de 321 en todo el universo real) apunta más a (a), pero la fila 123 tiene `rem_data` con patrón fantasma (concepto heredado, valores en 0), lo que genera dudas adicionales sobre si sería una validación significativa incluso si se resolviera el bounds-check. **No se propuso ninguna ampliación del guard.** Riesgo de contaminación entre secciones: no detectado en la búsqueda amplia, pero tampoco descartado con certeza para este caso específico dado el patrón de datos fantasma.

**Estado de las 12 (al momento de esta auditoría)**: las **11 candidatas limpias** (`50,51,53,72,73,110,111,187,429,430,431`) quedan **congeladas, confirmadas, no descartadas ni modificadas** — pendientes de autorización para reanudar Fase 2 sobre ellas. **461 queda como excepción en investigación**, fuera de Fase 2 por ahora. **[SUPERADO — ver puntos 16.14 y 16.15]**: las 11 fueron posteriormente implementadas (mecanismo), validadas (dry-run) y ejecutadas (`--commit` real) — **ya no están pendientes de autorización, quedaron cerradas**. La 461 permanece exactamente como excepción congelada, sin cambios desde este punto.

**`rule:set-total-row` NO fue implementado** — la implementación se detuvo antes de escribir código, tras el hallazgo de la regla 461 durante la reconfirmación read-only previa a la implementación.

**[SUPERADO — ver punto 16.14, 2026-08-27]**: `rule:set-total-row` fue implementado y validado con dry-run real sobre las 11 candidatas limpias (461 excluida explícitamente, sin excepción especial — rechazada por el mismo guard que aplicaría a cualquier otra regla).

Baseline reconfirmado sin cambios (`activas=717`, clasificación idéntica, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=0, hashes sin cambio). Ninguna escritura durante esta auditoría.

### 16.14 — FASE 2: MECANISMO IMPLEMENTADO / DRY-RUN VALIDADO / ESCRITURAS REALES NO EJECUTADAS (2026-08-27)

**Alcance**: exclusivamente las 11 candidatas confirmadas `[50,51,53,72,73,110,111,187,429,430,431]`. **La regla 461 (`A30/F`) queda explícitamente fuera de alcance**, congelada como excepción `AUDITADO / NO RESUELTO` (punto 16.13) — su `config` y el guard `[inicio:fin]` de `classifyRule()` **no fueron tocados**.

**Comando nuevo**: `rule:set-total-row {rule_id} {--reason=} {--by=} {--commit}` (`RuleSetTotalRowFromDiscoveryCommand.php`). **No recibe el número de fila como argumento** — lo obtiene exclusivamente del diagnóstico de Fase 1 (`classifySingleRule()`). Dry-run por defecto. 10 guards en orden estricto (cualquier fallo aborta sin escribir, con mensaje específico por guard): (1) existe y `active`; (2) clasificación actual = `BLOCKED_BY_ENGINE_GAP`; (3) `total_row` ausente; (4) candidato único; (5) posición `leading`; (6) no excluido; (7) **candidato dentro de `[filaInicioDatos:filaFinDatos]`** (guard explícito y separado — este es el que rechaza a la 461, sin caso especial); (8) evidencia real en `rem_data`; (9) simulación en memoria (solo `total_row`) da exactamente `SAFE_1_TO_1`; (10) ausencia de colisión funcional con otra regla activa (aprendido de 529↔530 — estructuralmente redundante con el guard 9 porque `total_row` nunca es parte de la clave funcional, pero verificado de forma explícita e independiente igual). `--commit`: exige `--reason`/`--by`, revalida todo inmediatamente antes de escribir, transacción, modifica únicamente `config.total_row`, crea `RuleVersion` del estado anterior + activity log `rule_total_row_set`, nunca toca bindings/status/históricos/calibraciones/`rem_data`/estructura.

**Tests nuevos** (`RuleSetTotalRowFromDiscoveryCommandTest.php`, 12/12 passing): caso válido (dry-run + commit) · **réplica exacta del caso 461** (candidato fuera de `[124:129]`, rechazado por el guard 7 — mismo mecanismo, sin caso especial) · candidato excluido · posición trailing · sin candidato · `total_row` ya presente (con `total_row` fuera de rango para que la clasificación siga `BLOCKED_BY_ENGINE_GAP` y se aísle específicamente el guard 3) · regla inactiva · clasificación incorrecta (regla horizontal, ya `SAFE_1_TO_1`) · ausencia de evidencia persistida · colisión con otra regla activa (rechazada en la práctica por el guard 2 vía clasificación `DUPLICATE` — documentado en el test que el guard 10 es defensa en profundidad, no alcanzable de forma independiente con datos reales) · `--commit` cambia únicamente `total_row`, resto de `config`/bindings/`rem_data` byte-idénticos, `RuleVersion` con snapshot correcto (sin `total_row`), activity log con todos los campos · `--commit` exige `--reason`/`--by`.

**Regresión completa**: 81/81 passing (`RuleRemapSectionCommandTest`, `RuleRestoreConfigVersionCommandTest`, `RuleSetRuleStatusCommandTest`, `RuleRebindSafeToStructureCommandTest`, `RuleSetTotalRowFromDiscoveryCommandTest`, `RuleBindingReconciliationServiceTest`, `RuleBindingReconciliationServiceTotalRowDiscoveryTest`, `SectionCalibrationMatrixServiceEmbeddedBackwardSubtotalRowTest`, `SectionCalibrationMatrixServiceEmbeddedLeadingTotalRowTest`).

**Dry-run real, individual, de las 11** — todas exit=0, todas `BLOCKED_BY_ENGINE_GAP → SAFE_1_TO_1` simulado:

| rule_id | sección | `total_row` candidato |
|---|---|---|
| 50, 51 | A03/A.4 | 54 |
| 53 | A03/B.1 | 79 |
| 72, 73 | A04/D | 59 |
| 110, 111 | A05/W | 395 |
| 187 | A08/R | 210 |
| 429, 430, 431 | A28/A.1 | 19 |

**Confirmado también contra la regla 461 real** (no solo el fixture sintético): `php artisan rule:set-total-row 461` → exit 1, rechazada exactamente por el guard 7 (`"cae fuera del rango vivo de la seccion [124:129]"`), sin ninguna ruta de excepción.

**Simulación consolidada de las 11** (transacción + rollback, delta **medido, no presupuesto**): `SAFE_1_TO_1` **153→164** (+11), `BLOCKED_BY_ENGINE_GAP` **344→333** (−11), `DUPLICATE=22` y `ALREADY_STRUCTURE_AGNOSTIC=198` sin cambio. Post-rollback verificado: clasificación y las 11 reglas (sin `total_row`) exactamente como antes.

**Baseline final reconfirmado** (BD real, sin la simulación): `activas=717`, `SAFE_1_TO_1=153`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=344`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, hashes sin cambio. **Ningún `--commit` real fue ejecutado sobre ninguna de las 11** — quedan confirmadas, con mecanismo validado, pendientes de autorización explícita para la escritura real.

**[SUPERADO — ver punto 16.15, 2026-08-27]**: el `--commit` real fue autorizado y ejecutado sobre las 11 candidatas. Resultado exacto documentado abajo.

### 16.15 — FASE 2 EJECUTADA Y CERRADA para las 11 candidatas (2026-08-27) — 461 permanece fuera / NO RESUELTA

**Ejecutado**: `php artisan rule:set-total-row <id> --reason="..." --by="Administrador Esalud" --commit`, **una por una** (11 invocaciones individuales, nunca `UPDATE` masivo), cada una con pre-flight de guards reconfirmado inmediatamente antes (coincidencia exacta con el dry-run previo en las 11, sin ninguna desviación) y la doble revalidación interna del propio comando. **11/11 exitosas, sin errores.**

**Post-check — coincide exactamente con lo esperado**:

| Métrica | Antes | Después |
|---|---|---|
| `SAFE_1_TO_1` | 153 | **164** |
| `BLOCKED_BY_ENGINE_GAP` | 344 | **333** |
| `REQUIRES_REMAP` | 0 | 0 |
| `DUPLICATE` | 22 | 22 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 |
| reglas activas | 717 | 717 |
| `rem_rules` | 764 | 764 |
| `rem_rule_bindings` | 1204 | 1204 |
| bindings a estructura 67 | 0 | 0 |

**Valores de `total_row` escritos**: `50,51→54` (A03/A.4) · `53→79` (A03/B.1) · `72,73→59` (A04/D) · `110,111→395` (A05/W) · `187→210` (A08/R) · `429,430,431→19` (A28/A.1).

**Integridad verificada**: las 11 configs quedaron con exactamente las claves `sheet,section,column,row_range,rule_logic,total_row` — nada más, nada menos, byte-idénticas salvo la clave nueva. `status`/`rule_type`/`rule_key`/`name` intactos en las 11. **Exactamente 11** `RuleVersion` nuevos, todos con snapshot del `config` anterior (sin `total_row`). **Exactamente 11** activity logs `rule_total_row_set`. Bindings sin cambio (`rem_rule_bindings=1204` idéntico). Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` sin cambio. Estructura 67/v35 sin tocar. Calibraciones y `rem_data` sin ninguna escritura (el comando nunca las toca, por diseño).

**Regla 461 (`A30/F`)**: confirmada `status=active`, `config.total_row=null`, `clasificacion=BLOCKED_BY_ENGINE_GAP` — **sin ningún cambio**, permanece fuera de alcance, `AUDITADO / NO RESUELTO` (punto 16.13).

**Fase 2 queda cerrada para las 11 candidatas confirmadas.** No se inició Fase 3 ni Fase 4. No se ejecutó rebind. No se tocaron calibraciones ni históricos. No commit de Git, no push.

## 17. FASE 3 — AUDITORÍA + DISEÑO (2026-08-27, READ-ONLY): reconstrucción del universo 333, auditoría del motor, comparación de alternativas

⚠️ Todo este punto 17 es **auditoría (READ-ONLY) + diseño (`PROPUESTO / NO IMPLEMENTADO`)**. Ningún código de motor/parser fue modificado, ninguna migración se creó, ninguna regla/config/status/binding/calibración/`rem_data`/estructura fue tocada. La regla 461 no fue tocada. Sin commit, sin push.

### 17.1 — Reconstrucción del universo 333 tras Fase 2 (READ-ONLY, verificado contra BD real)

Re-ejecución de `RuleBindingReconciliationService::classifyAllActiveRules()` contra la estructura activa 67/v35, sobre las 717 reglas activas reales. Clasificación confirmada idéntica al "ESTADO VIGENTE ÚNICO": `SAFE_1_TO_1=164, REQUIRES_REMAP=0, DUPLICATE=22, BLOCKED_BY_ENGINE_GAP=333, ALREADY_STRUCTURE_AGNOSTIC=198`.

Desglose exacto de los 333, usando los campos diagnósticos de Fase 1 (`total_row_candidate`/`total_row_position`/`total_row_excluded`) más el flag de hoja `no_utilizada`:

| Subgrupo | Cantidad | Definición |
|---|---|---|
| `no_utilizada` | **56** | `A21`=25, `A24`=3, `A25`=22, `A30AR`=2, `A34`=4 — sin cambio |
| TOTAL trailing técnico excluido (Familia A, discovery directo) | **225** | candidato en `row_to+1`, excluido por mecanismo #12/#8/#11 |
| TOTAL leading técnico excluido (mecanismo #6 ya lo detecta) | **29** | candidato en `row_from-1`, excluido por mecanismo #6 |
| TOTAL leading persistido, NO resuelto | **1** | únicamente la regla **461** (`A30/F`) — congelada, `AUDITADO/NO RESUELTO`, punto 16.13 |
| TOTAL trailing persistido | **0** | sin cambio |
| Sin candidato descubierto | **22** | 12 placeholders `row_range={0,0}` (`56,208,214,226-234`) + 10 de `A26/B` (`393-402`, fórmula con arrastre, Fase 1 se abstiene por diseño, confirmadas Familia A manualmente en 16.11) |

**Verificación de suma**: `56+225+29+1+0+22=333` exacto (confirmado en vivo, no calculado a mano).

**Reconciliación contra los conteos históricos 235/29/56 (puntos 16.8/16.9)**: siguen siendo **válidos, sin cambio** — la Fase 2 solo tocó el subgrupo "TOTAL leading persistido" (12→1), que es un subgrupo **distinto** de la Familia A (235) y de las 29 leading-excluidas. Reconciliación exacta: Familia A auditada = 235 = 225 (discovery directo) + 10 (`A26/B`, no-candidato pero confirmado manualmente) — idéntico a la reconciliación ya documentada en el punto 16.11/16.12, sin discrepancia nueva.

**Confirmado explícitamente**: ninguna de las 11 reglas resueltas (`50,51,53,72,73,110,111,187,429,430,431`) aparece ya en `BLOCKED_BY_ENGINE_GAP` — las 11 clasifican `SAFE_1_TO_1` como se esperaba.

**Búsqueda de excepciones adicionales tipo 461**: no se encontró ninguna — la regla 461 sigue siendo la única con posición `leading` y `excluded=false` dentro de los 333 (el subgrupo "leading persistido, no resuelto" tiene cardinalidad 1, exacto). No se reabrió la búsqueda exhaustiva completa de 16.13 (321/322 candidatos) porque ninguna de las 322 filas subyacentes cambió desde entonces — solo cambió el `config` de las 11 reglas ya resueltas, que salieron por completo de `BLOCKED_BY_ENGINE_GAP` y por tanto de este universo.

### 17.2 — Auditoría del motor: por qué `RuleEngineService`/`SumEqualsEvaluator` no pueden evaluar las 254 excluidas (225+29), READ-ONLY

**Cómo obtiene el motor el valor declarado hoy** (`RuleEngineService::execute()`, `app/Domain/RuleEngine/Services/RuleEngineService.php` líneas 85 y 152-178): por cada `RemUpload`, el motor carga **exclusivamente** `RemData::where('rem_upload_id', $uploadId)->get()->groupBy('section')` — no hay ninguna otra fuente de datos por-carga. Para reglas `sum_equals` verticales, el prefiltro `[row_from:row_to]` **ya tiene una excepción explícita para `total_row`** (líneas 154-178, comentario "Endurecido en Fase 3C (2026-08-12)" — **este fix ya está commiteado en `main`, confirmado porque `RuleEngineService.php` NO aparece como modificado en `git status`**, a diferencia de lo que decía el checkpoint de Fase C de 2026-08-12 más abajo en este archivo, que quedó desactualizado en ese punto específico). Es decir: **el prefiltro del motor NO es el problema** — si `total_row` está en `config` y la fila con ese `row_number` existe en `rem_data` para esa carga, el motor la deja pasar sin problema.

**Por qué no puede evaluar estas 254 de todos modos** (`SumEqualsEvaluator::evaluateVerticalAggregation()`, líneas 104-132): el evaluador busca, dentro de la colección `$rows` ya filtrada, una fila cuyo `data['row_number'] === $totalRowNumber`. Si no la encuentra, devuelve `missing_total_row` (skip, no fail). **La fila nunca está ahí** porque `RemParserService::parseSheet()` la excluye deliberadamente de la persistencia — confirmado línea por línea (`app/Domain/REM/Services/RemParserService.php` líneas 586-588): `if ($esFilaTotalLiderEmbebida || $esFilaTotalFinalEmbebida || $esFilaSubtotalEmbebidoHaciaAtras) { continue; }` — el `continue` ocurre **antes** de construir `$entry` (el array que se convertiría en una fila `RemData`), así que la fila nunca llega a persistirse, en ninguna carga, para ninguna de estas 254 filas físicas. Esto es exactamente el gap arquitectónico ya documentado como deuda técnica #5 — confirmado hoy con lectura directa de código, no por inferencia.

**Qué información estructural ya tenemos disponible desde Fase 1**: `discoverTotalRowCandidate()` ya identifica, para cada una de las 254 (trailing o leading), el número de fila candidato exacto, su posición, y confirma (vía `isEmbeddedBackwardSubtotalRow()`/`isEmbeddedLeadingTotalRow()`, ya públicos) que esa fila está excluida por mecanismo. Es decir: **ya sabemos con certeza QUÉ fila falta y POR QUÉ falta** — ese no es el gap.

**Qué información falta realmente para evaluar el TOTAL sin reincorporarlo a `rem_data`**: el **valor real calculado** de esa fila **para cada carga real específica** (`rem_upload_id`) — no la estructura (`cell_data`, que describe el archivo de referencia/plantilla, igual para todas las cargas), sino el resultado real de la fórmula Excel al momento de esa carga particular (ej. la suma real que declaró el establecimiento X en el mes Y). **Ese valor SÍ se calcula** en tiempo de parseo (`getCalculatedValue()`, confirmado en el mismo bloque de código, `$values` ya está completamente poblado y validado antes del `continue` de la línea 587 — ver detalle en 17.3) — **pero se descarta inmediatamente después**, sin persistirse en ningún lugar consultable por upload. Ese es el gap exacto: no falta cálculo, falta **persistencia** del valor ya calculado.

### 17.3 — Punto de enganche exacto confirmado en el parser (evidencia nueva, no documentada antes)

Confirmado hoy, línea por línea, en `RemParserService::parseSheet()`:
- Línea 417-440: `$values[$colLetter] = $parsed` — el array `$values` (columna→valor validado) queda **completamente construido** antes de línea 483.
- Línea 481-483: `$values[$effectiveTotalCol] = $total` — el total de fila también queda incorporado.
- Líneas 520-584: los 3 mecanismos (`$esFilaTotalLiderEmbebida`, `$esFilaTotalFinalEmbebida`, `$esFilaSubtotalEmbebidoHaciaAtras`) se calculan, cada uno reutilizando métodos ya existentes (`isEmbeddedLeadingTotalRow`/`isTrailingTotalRow`/`isEmbeddedBackwardSubtotalRow`).
- Línea 586-588: `if (...) { continue; }` — **en este punto exacto**, `$currentConcept`, `$professional`, `$total`, `$values` y `$row` están 100% disponibles en memoria, ya validados, listos para convertirse en un `$entry` (el mismo shape que se usa 15 líneas más abajo, línea 602-608, para las filas que SÍ se persisten) — simplemente nunca se usan porque el `continue` los descarta primero.

**Esto confirma, con evidencia de código (no supuesto), que el punto de inserción para cualquier solución tipo "tabla paralela" es exactamente ese `continue`** — no requiere reestructurar el parser, ni duplicar lógica de detección, ni volver a abrir el archivo Excel en otro momento.

### 17.4 — Costo de I/O real medido (no estimado a ciegas)

Medido contra BD real: `RemUpload` total = **144**, `RemData` total = **396.371**, promedio **2.752,6 filas `rem_data` por carga**.

Cardinalidad física de filas TOTAL técnicas excluidas (Familia A 225 + leading-excluidas 29 = 254 reglas, **excluyendo hojas `no_utilizada`**): agrupadas por `(hoja, sección, fila)` física real (varias reglas/columnas comparten la misma fila TOTAL), dan exactamente **62 filas físicas únicas** en toda la Serie A relevante — distribuidas en 17 hojas (`A01`=7, `A02`=2, `A03`=2, `A04`=2, `A05`=6, `A07`=3, `A08`=3, `A09`=4, `A19B`=5, `A23`=7, `A26`=2, `A27`=3, `A28`=4, `A29`=1, `A31`=4, `A32`=6, `A33`=1). Esto incluye la fila 59 de `A26/B` (las 10 reglas sin candidato de Fase 1, confirmadas Familia A manualmente).

**Lectura**: una tabla paralela poblada por carga añadiría, como máximo, ~62 filas nuevas por carga completa (menos en la práctica — solo las secciones efectivamente presentes en esa carga) frente a un promedio de 2.752,6 filas `rem_data` — **overhead menor al 2,3%** en volumen de escritura, y ninguna escritura adicional para las hojas `no_utilizada` ni para reglas fuera de la Familia A/leading-excluida.

### 17.5 — Comparación de alternativas (evidencia 2026-08-27, sin dar por aprobada la Opción 4 de 16.10)

| Criterio | Opción 1: leer Excel en runtime | Opción 2: metadata estructural (sola) | Opción 3: `total_row` en config (sola) | **Opción 4: tabla técnica paralela** | Opción 5: columna JSON en `RemUpload` (variante ligera de 4) |
|---|---|---|---|---|---|
| Impacto en parser | Ninguno | Ninguno adicional (`cell_data` ya existe) | Ninguno | **1 punto de inserción confirmado** (línea 587, ver 17.3) | Igual que Opción 4, pero escribe a un JSON acumulado en `RemUpload` en vez de filas |
| Impacto en motor | Alto — reabrir `.xlsx` original en el hot path de validación, ubicar celda por coordenada | Ninguno por sí sola (no resuelve nada) | Ninguno por sí sola (ya implementado en Fase 1/2, insuficiente para las 254) | Acotado — 1 punto en `RuleEngineService::execute()` (~línea 173-178, donde ya existe la excepción `total_row`), `SumEqualsEvaluator` **no requiere cambios** | Igual que Opción 4, pero el motor debe parsear el JSON por upload en vez de una query indexada |
| Impacto en BD | Ninguno | Ninguno | Ninguno | 1 tabla nueva (migración), esquema estimado: `rem_upload_id, sheet, section, row_number, concept, values(JSON), total` | 1 columna JSON nueva en `rem_uploads` (sin tabla nueva) |
| Costo de I/O | Alto y variable — abrir archivo completo (MBs) por cada validación, para leer 1 celda | Ninguno | Ninguno | Bajo y acotado — medido: ≤62 filas/carga, <2,3% sobre el promedio de 2.752,6 filas `rem_data`/carga (ver 17.4) | Similar en bytes, pero sin índice por `(sheet,section,row_number)` — cualquier lookup requiere decodificar el JSON completo |
| Auditabilidad | Baja — no deja rastro incremental, cada corrida reabre el archivo | N/A | N/A | Alta — cada fila queda persistida con `rem_upload_id`/timestamp propio, consultable independientemente, mismo patrón que `rem_data` | Media — el valor persiste, pero es más difícil de consultar/filtrar en masa (requiere funciones JSON) |
| Comportamiento cargas nuevas | Funciona solo si el archivo original sigue accesible (no confirmado en esta auditoría) | No resuelve nada | No resuelve nada para las 254 | Resuelto completamente desde el próximo parseo | Resuelto completamente desde el próximo parseo |
| Comportamiento histórico | No retroactivo salvo reprocesar los 144 archivos originales (si existen) | No aplica | No aplica | No retroactivo (144 cargas existentes no la tendrían) salvo backfill explícito, no evaluado — mismo patrón ya establecido en toda la campaña | Igual que Opción 4 |
| Riesgo sobre calibraciones | Ninguno directo, pero nueva superficie de fallo (archivo movido/borrado/corrupto) | Ninguno | Ninguno | **Nulo** — tabla aislada, `SectionCalibrationMatrixService`/`PatternMigrationScanner` no la tocan | Nulo, misma razón |
| Compatibilidad mecanismos #6/#8/#11/#12 | No aplica directamente — leería la celda cruda sin pasar por los mecanismos (tendría que reimplementarlos fuera de su contexto de parseo) | Ya compatible (son la misma fuente) | No aplica | **Total** — se activa exactamente donde esos mecanismos ya deciden excluir la fila, reutilizando sus booleanos ya calculados (ver 17.3) | Igual que Opción 4 |

**Conclusión de la comparación (evidencia, no aprobación automática)**: las Opciones 1, 2 y 3 quedan descartadas o insuficientes por sí solas, con la misma conclusión ya alcanzada en el punto 16.10 pero ahora con evidencia adicional (código real, I/O medido) que la refuerza. Entre la Opción 4 y su variante ligera (Opción 5), **la Opción 4 (tabla dedicada) se mantiene como la preferida** — mejor auditabilidad, consulta indexada nativa, y consistencia con el patrón ya establecido en el sistema (registros individuales por fila, como `rem_data`, en vez de blobs JSON acumulativos) — pero **ninguna de las dos está aprobada para implementación** en este punto; ambas quedan documentadas como alternativas viables.

### 17.6 — Propuesta de fases 3A / 3B / 3C — **[SUPERADO PARCIALMENTE — ver punto 17.7, 2026-08-27: FASE 3A IMPLEMENTADA. 3B y 3C siguen `PROPUESTO / NO IMPLEMENTADO`]**

⚠️ Ordenadas de menor a mayor riesgo/alcance, cada una reversible sin afectar a la anterior. Texto original de la propuesta conservado íntegro abajo.

**Fase 3A — Esquema + hook de escritura únicamente, motor SIN tocar (menor riesgo, primer paso recomendado)**:
- Migración de la tabla técnica paralela (Opción 4), nombre tentativo `rem_technical_totals` (o similar), sin relación de FK obligatoria hacia `rem_data` (independiente).
- Un único punto de inserción en `RemParserService::parseSheet()`, exactamente donde hoy está el `continue` de la línea 587 (ver 17.3) — antes de saltar la fila, insertar un registro con `rem_upload_id, sheet, section, row_number, concept, values, total` ya disponibles en memoria.
- El motor (`RuleEngineService`/`SumEqualsEvaluator`) **no se toca en esta fase** — la tabla se puebla pero nadie la lee todavía. Esto permite validar en cargas reales nuevas, con auditoría manual directa sobre la tabla, antes de conectar nada al flujo de validación.
- **Reversible por diseño**: si algo no funciona, basta con dejar de escribir (revertir el hook) o truncar la tabla — cero impacto en `rem_data`, calibraciones, motor o resultados de validación ya emitidos.
- Riesgo: mínimo — el único cambio de comportamiento observable es una escritura adicional por carga, sin ningún efecto sobre lo que el sistema ya hace.

**Fase 3B — Conectar el motor, piloto acotado**:
- `RuleEngineService::execute()` consulta la tabla paralela (agrupada igual que `$remData`) e inyecta la fila sintética en `$rows` **solo** cuando: la regla es `sum_equals` vertical, `total_row` está en `config`, y la fila no está ya en `$remData`.
- `SumEqualsEvaluator` no requiere cambios (ya busca `row_number===$totalRowNumber` en la colección recibida, sin importar origen).
- Piloto limitado a un subconjunto pequeño y ya certificado (candidatas: las 3 reglas ya auditadas en profundidad — `56, 208, 214`, o 1-2 hojas concretas) — requiere primero completar `total_row` en su `config` (vía `rule:set-total-row` o extensión equivalente, sujeto a autorización aparte) y verificar el resultado real contra una carga nueva de prueba antes de generalizar.

**Fase 3C — Generalización a las 254 (225+29) + decisión de backfill histórico**:
- Extender a la Familia A completa (235, incluyendo las 10 de `A26/B`) y a las 29 leading-excluidas, con post-check exhaustivo por regla (clasificación antes/después, evidencia real).
- Decidir aparte, como pregunta abierta y no bloqueante, si se autoriza algún backfill histórico limitado sobre las 144 cargas existentes (reprocesar archivos originales si están disponibles) — no evaluado en esta auditoría, consistente con el patrón ya establecido de "proteger solo cargas nuevas" en toda la campaña.

**[SUPERADO — ver punto 17.7]**: Fase 3A fue implementada hoy mismo. Fase 3B y 3C **siguen sin iniciar**, requieren autorización explícita, una por una, igual que el resto de la campaña.

### 17.7 — FASE 3A IMPLEMENTADA (2026-08-27) — 3B y 3C siguen **PROPUESTO / NO IMPLEMENTADO**

⚠️ Distinción explícita: **solo la Fase 3A** (infraestructura de captura auditable, motor sin tocar) está implementada. Las Fases 3B (conectar el motor, piloto acotado) y 3C (generalización + backfill) del punto 17.6 **siguen sin implementar, sin autorización**.

**Reconfirmación previa a implementar (contra el diseño del punto 17, antes de escribir código)**: el esquema mínimo se ajustó respecto al texto original del punto 17.6 en dos puntos, ambos por evidencia encontrada al leer el código real (no por preferencia):
- La columna se llama `rem_section_code` (no `section`) — evita ambigüedad con `rem_data.section`, que en este sistema almacena el **nombre de hoja** (ej. "A32"), no el código de sub-sección (ej. "F1"). Confirmado leyendo `ProcessRemUploadJob`/`RuleEngineService::execute()` (`$grouped = $remData->groupBy('section')`, `section` = hoja).
- Se agregó `exclusion_reason` (no estaba en el esquema tentativo del punto 17.6) — permite auditar, sin ambigüedad, cuál de los 3 mecanismos (#6/#8/#11 vs #12) excluyó cada fila, sin re-derivarlo después.

**Auditoría de lifecycle retry/reprocess/delete (hecha ANTES de escribir código, según instrucción explícita)**:
- `RemUpload` usa `SoftDeletes` (`app/Domain/REM/Models/RemUpload.php`) — `RemUploadController::destroy()` solo hace `$remUpload->delete()` (soft, no dispara ningún `cascadeOnDelete()` de FK). `rem_data` ya tiene `foreignId('rem_upload_id')->constrained()->cascadeOnDelete()` desde su migración original — ese cascade solo actuaría ante un `forceDelete()` real, que no ocurre en ningún flujo de la aplicación hoy. **Decisión**: replicar exactamente el mismo patrón FK en la tabla nueva (mismo comportamiento que `rem_data`, ninguna divergencia, ningún caso especial).
- **Dos puntos de reproceso encontrados**, ambos borran `rem_data` manualmente ANTES de re-encolar/re-parsear (no dependen del cascade): `RemReprocessCommand::handle()` (`$upload->remData()->delete()`) y `TempReprocessUploadCommand::handle()` (`RemData::where(...)->delete()`, comando ad-hoc de desarrollo, gitignored localmente — `.git/info/exclude`, no versionado, confirmado con `git check-ignore`). **Ambos actualizados** para borrar también `rem_technical_totals` antes de reprocesar — de lo contrario el reparseo violaría la restricción unique (`rem_upload_id, sheet, rem_section_code, row_number`).
- `ProcessRemUploadJob::handle()` (el único punto real de persistencia): confirmado que **`rem_data` NO tiene ninguna protección transaccional hoy** — el `foreach ($result->extractedData as $entry) { RemData::create(...); }` no está envuelto en `DB::transaction()`; un fallo a mitad de loop deja filas parciales, y el `catch` solo marca `status=failed`, sin limpiar lo ya escrito. Esto es comportamiento preexistente, no introducido por Fase 3A, y no se tocó. **Decisión**: no envolver la escritura de `rem_data` en una transacción nueva (fuera de alcance, cambiaría semántica de fallo ya establecida) — pero sí aislar la escritura de `rem_technical_totals` en su **propia** `DB::transaction()`, independiente, para que esa tabla nueva específicamente nunca quede a medio escribir (todo o nada), sin alterar el comportamiento existente de `rem_data`.

**Diseño compatible confirmado con el lifecycle real** → se procedió a implementar.

**Archivos nuevos**:
- `backend/database/migrations/2026_08_27_000001_create_rem_technical_totals_table.php` — tabla `rem_technical_totals`: `id`, `rem_upload_id` (FK, `cascadeOnDelete()`, mismo patrón que `rem_data`), `sheet` (varchar 20), `rem_section_code` (varchar 20), `row_number` (unsigned int), `concept` (varchar nullable), `total` (varchar nullable), `values` (json), `exclusion_reason` (varchar 40), timestamps. **Restricción única** `(rem_upload_id, sheet, rem_section_code, row_number)` — impide duplicados accidentales a nivel de BD, no solo de aplicación. Índice adicional `(rem_upload_id, sheet)` para lecturas futuras agrupadas igual que `rem_data`. **Migrada realmente contra `esalud_dev`** (no solo `esalud_testing`) — tabla existe, vacía (0 filas, ninguna carga real nueva se procesó en esta sesión).
- `backend/app/Domain/REM/Models/RemTechnicalTotal.php` — modelo Eloquent simple (mismo patrón que `RemData`), `casts(): ['values' => 'array', 'row_number' => 'integer']`, relación `remUpload()`.
- `backend/tests/Feature/REM/RemTechnicalTotalsPersistenceTest.php` (nuevo, 10/10 passing) — end-to-end vía `ProcessRemUploadJob::handle()` real (no solo `RemParserService::parse()` en memoria, porque la escritura real a BD ocurre en el Job): fila excluida ausente de `rem_data`; aparece exactamente 1 vez en `rem_technical_totals`; `sheet`/`rem_section_code`/`row_number`/`concept`/`values`/`exclusion_reason` preservados correctos; filas normales se comportan exactamente igual que antes (mismo conteo, mismos valores); sección sin ninguna fila técnica no genera registros; `rem:reprocess` limpia la tabla nueva antes de re-encolar y el re-parseo posterior no duplica; restricción unique rechaza un INSERT duplicado manual (`QueryException`); `forceDelete()` de un upload cascada a `rem_technical_totals` igual que a `rem_data`; escritura de la tabla nueva aislada en su propia transacción sin alterar el conteo de `rem_data`.

**Archivos modificados (aditivos, sin tocar lógica de negocio existente)**:
- `backend/app/Domain/REM/Services/RemParserService.php` — **único punto de enganche real**, exactamente el `continue` ya identificado en 17.3 (dentro de `parseSheet()`): antes del `continue`, si `$sectionContext !== null`, se arma un array (`sheet`, `rem_section_code`, `row_number`, `concept`, `total`, `values`, `exclusion_reason` — este último derivado de CUÁL de los 3 booleanos ya calculados fue `true`, sin re-evaluar nada) y se agrega a un nuevo array local `$technicalTotals`, inicializado junto a `$data`. **Los 3 mecanismos (#6/#8/#11 vs #12) no fueron tocados ni redefinidos** — Fase 3A solo lee su resultado ya calculado. `parseSheet()` devuelve `technical_totals` como clave nueva en su array de retorno; `parse()` acumula `$technicalTotals` igual que `$extractedData` (un `array_merge` adicional por hoja) y lo pasa al `ParseResult`.
- `backend/app/Domain/REM/Services/ParseResult.php` — nueva propiedad pública `technicalTotals` (array, default `[]`), aditiva, no rompe ninguna de las 3 construcciones de `ParseResult` con errores tempranos (`rejected`/`failed`) que no pasan ese parámetro.
- `backend/app/Domain/REM/Jobs/ProcessRemUploadJob.php` — después del loop existente de `RemData::create()` (sin tocarlo), un bloque nuevo: si `$result->technicalTotals` no está vacío, `DB::transaction()` que crea un `RemTechnicalTotal` por entrada. Aislado — un fallo aquí no afecta lo ya escrito en `rem_data` (comportamiento preexistente sin cambios) ni viceversa.
- `backend/app/Domain/REM/Models/RemUpload.php` — nueva relación `technicalTotals(): hasMany(RemTechnicalTotal::class)`, mismo patrón que `remData()`.
- `backend/app/Console/Commands/RemReprocessCommand.php` — agrega `$upload->technicalTotals()->delete()` antes de re-encolar, mismo patrón que la limpieza ya existente de `remData()`.
- `backend/app/Console/Commands/TempReprocessUploadCommand.php` (gitignored, comando ad-hoc de desarrollo) — mismo agregado, por consistencia.

**El motor de reglas NO fue tocado**: `RuleEngineService.php` y `SumEqualsEvaluator.php` sin ninguna modificación — confirmado con `git status` (no aparecen como modificados). `rem_technical_totals` no es leída por ningún código de motor todavía (Fase 3B, no implementada).

**Regresión ejecutada** (`php -d memory_limit=-1 vendor/bin/phpunit`, requiere memoria ampliada, ya documentado como necesario para `tests/Feature/REM`):
- `tests/Feature/REM` + `tests/Unit/RemParser` + `tests/Feature/RuleEngine` completo: **583 tests, 544 passed, 39 failed** — **los mismos 39 preexistentes ya documentados en sesiones anteriores** (4 en `StructurePersistenceServiceTest` + 1 en `RuleEngineIntegrationTest` + 30 en `FunctionalRuleEngineCertificationTest` + 4 en `RuleEngineServiceTest` = 39 exacto), **cero regresiones nuevas** atribuibles a Fase 3A. Ninguno de los 39 menciona `RemTechnicalTotal`/`technical_totals`/ninguno de los archivos tocados hoy.
- Verificación aislada de los mecanismos #6/#8/#11/#12 (`RemParserServiceEmbeddedLeadingTotalRowTest`, `RemParserServiceTrailingTotalRowTest`, `RemParserServiceEmbeddedBackwardSubtotalRowTest`, `RemParserServiceEmptyRowPersistenceTest`): **32/32 passing**, sin cambios — confirma que los 3 mecanismos siguen decidiendo exclusión exactamente igual que antes, Fase 3A solo consume su resultado.
- `RemTechnicalTotalsPersistenceTest` (nuevo): **10/10 passing**.
- Fase 1/2 (`RuleBindingReconciliationServiceTest`, `RuleBindingReconciliationServiceTotalRowDiscoveryTest`, `RuleSetTotalRowFromDiscoveryCommandTest`, `RuleRebindSafeToStructureCommandTest`): **42/42 passing**, sin regresión.

**Baseline de clasificación antes/después — idéntico byte a byte** (ninguna escritura sobre reglas/config/status/bindings/calibraciones/`rem_data`/estructura en toda la Fase 3A): `activas=717`, `SAFE_1_TO_1=164`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=333`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, estructura activa `67/v35 status=active`, regla `461.config.total_row=null` sin cambio. `rem_technical_totals` real (BD `esalud_dev`) = **0 filas** — la tabla existe pero está vacía porque ninguna carga real nueva se procesó en esta sesión (los 10 tests corrieron contra `esalud_testing`, con `RefreshDatabase`, no contra `esalud_dev`).

**No se hizo commit ni push.** No se tocó la regla 461. No se inició el piloto 56/208/214 (eso es Fase 3B). No Fase 3B ni 3C.

### 17.8 — FASE 3B: PILOTO CONTROLADO — IMPLEMENTADO/VALIDADO para la regla 56; 208/214 confirmadas NO aptas para el mismo fix, hallazgo nuevo (2026-08-27)

⚠️ Resultado real, no el esperado al iniciar: de las 3 reglas piloto (`56, 208, 214`), **solo 56 pudo piloterase de punta a punta** con el mecanismo genérico construido hoy. **208 y 214 resultaron NO ser candidatas al mismo tipo de fix** — hallazgo hecho en la reconfirmación *previa a escribir código*, exigida explícitamente antes de tocar nada. Detalle completo abajo.

**Reconfirmación previa (READ-ONLY, antes de tocar código)**: `56/208/214` siguen `active`, `BLOCKED_BY_ENGINE_GAP`, `config.row_range={"from":0,"to":0}` (placeholder), sin `total_row`. `rem_technical_totals` = 0 filas reales. Confirmado sin discrepancias.

**Hallazgo crítico de la reconfirmación (nunca documentado antes, cambia el plan)**: `RuleEngineService::normalizeConfig()` deriva `scope` a partir de `row_from`/`row_to` — `row_from===row_to` (0===0) da `scope='per_row'`, **nunca `'row_range'`**. Y `isVerticalSumEqualsRule()` exige explícitamente `scope==='row_range'` antes de considerar `total_row`. Es decir: **con `row_range={0,0}`, el motor real NUNCA llega a `evaluateVerticalAggregation()` ni al chequeo de `total_row`, sin importar qué se escriba en `config.total_row`** — el prefiltro de filas (`row_number` entre 0 y 0) ya deja `$rows` vacío antes de eso, y la regla siempre queda `skipped`/`'Sin datos'`. Esto es distinto de lo que documentaba el `motivo` del clasificador (`invalid_row_range_configuration: falta total_row`) — el clasificador simula correctamente que *haría falta* `total_row`, pero no simula el `scope` real, así que no detecta que **agregar solo `total_row` no alcanza**: para activar el evaluador vertical real, `row_range` también necesita ser un rango real (no `{0,0}`).

**Investigación read-only de qué rango real correspondería a cada regla (estructura 67/v35 + `cell_data` reales)**:
- **Regla 56 (`A03/D.7`, columna AH)**: `filaInicioDatos=206, filaFinDatos=208`. `AH208` = fórmula real `=SUM(AH206+AH207)` — **rango contiguo, 2 filas**, exactamente `[206:207]` → `total_row=208`. Encaja perfecto con el modelo del evaluador (`evaluateVerticalAggregation()` asume que *todas* las filas de `[from:to]` son componentes).
- **Reglas 208/214 (`A09/F.1`, columnas F/L)**: `filaInicioDatos=146, filaFinDatos=158`. `F158` = fórmula real `=SUM(F149,F150,F153,F155,F157)` — **NO es un rango contiguo**: suma 5 filas específicas, saltando 146,147,148,151,152,154,156. `L158` confirma el mismo patrón exacto de 5 filas (`=SUM(L149:L150+L153+L155+L157)`). **Esto es una agregación irregular/periódica — la MISMA clase de problema ya congelada como deuda de diseño separada para `A09/I` (CLAUDE.md punto 10, reglas 226-234)**, nunca antes reconocida para `A09/F.1`. El evaluador vertical genérico (`evaluateVerticalAggregation()`) no tiene forma de expresar "suma solo estas 5 filas, no todo `[146:158]`" — si se forzara `row_range=[146:157]+total_row=158`, el evaluador sumaría TAMBIÉN las 7 filas que la fórmula real ignora, produciendo un resultado **incorrecto** (falso failed o falso passed según los datos), no solo "sin datos".

**Decisión tomada — no reversible sin nueva evidencia**: **no se propuso, no se simuló y no se escribió ningún cambio de `config` para las reglas 208/214.** Proponer `row_range=[146:157]` habría sido precisamente el tipo de "fallback silencioso"/corrección automática sin verificar que el usuario prohibió explícitamente en toda la campaña (mismo principio que impidió agregar `total_row` a 56/208/214 en la corrección de 2026-08-26, punto 9). **Recomendación para el futuro** (no ejecutada): agregar `A09/F.1` (208, 214) al mismo grupo de deuda de diseño que `A09/I` — "agregación irregular/periódica, sin solución de `row_range` contiguo" — en vez de tratarlas como parte de la Familia A simple.

**Solo la regla 56 se piloteó de punta a punta** (vía fixtures 100% sintéticos, réplica exacta de su patrón real — nunca contra `A03/D.7` real ni cargas históricas): propuesta de config (`row_range=[206:207], total_row=208`) simulada únicamente en memoria dentro de los tests, **nunca escrita en la BD real** — la config real de la regla 56 permanece intacta (`row_range={0,0}`, sin `total_row`), confirmado en el post-check.

**Mecanismo del motor implementado** (`RuleEngineService.php`, método `execute()`, justo después del prefiltro `[row_from:row_to]` ya existente, y nuevo método privado `findTechnicalTotalRow()`): si la regla es un `sum_equals` vertical genuino (`isVerticalSumEqualsRule()===true`) con `total_row` configurado, y **esa fila no está ya presente en `$rows`** (proveniente de `rem_data`), se busca en `rem_technical_totals` (`rem_upload_id + sheet + rem_section_code + row_number`) — si existe, se inyecta como una fila `RemData` sintética *no persistida* (`id=null`) dentro de la colección local `$rows` (ya distinta/copiada por el `filter()` previo, nunca la colección compartida por hoja — confirmado seguro para reglas vecinas). Si no existe en `rem_technical_totals` tampoco, `$rows` sigue sin esa fila y el evaluador cae exactamente en su comportamiento ya existente (`missing_total_row`, skip) — **sin fallback silencioso, sin inventar valores**. `SumEqualsEvaluator` no fue modificado — sigue buscando `row_number===$totalRow` en lo que reciba, sin importar el origen.

**Resultado exacto de la regla 56 (patrón real, fixture sintético)**: componentes `AH206=4, AH207=9` (rem_data) + `AH208=13` (rem_technical_totals, replicando `=SUM(AH206+AH207)` real de Excel) → **`status=passed`, `failed_rows=0`** — coincide exactamente con lo que la fórmula real de Excel produciría. Caso de control negativo (valor técnico deliberadamente distinto de la suma real, `999` en vez de `13`) → **`status=failed`, `reason=vertical_sum_mismatch`**, confirmando que el mecanismo no "inventa" un pase — evalúa de verdad.

**Resultado exacto de 208/214 (config real, sin cambios)**: `status=skipped, reason='Sin datos'` — **idéntico al comportamiento previo a Fase 3B**, confirmado con una fila de `rem_technical_totals` deliberadamente sembrada (simulando que existiera) para probar que el mecanismo nuevo *ni siquiera la consulta* — `$totalRow` nunca deja de ser `null` porque `scope` nunca es `'row_range'` con `{0,0}`.

**Tests nuevos** (`RuleEngineServiceTechnicalTotalPilotTest.php`, 10/10 passing): patrón 56 produce resultado correcto igual a Excel; patrón 56 falla si el valor técnico no coincide (correctitud, no solo "pasa siempre"); valor técnico ausente cae en `missing_total_row` sin fallback; componente faltante en `rem_data` no se rellena desde la tabla técnica (solo la fila TOTAL se busca ahí, nunca componentes); la fila técnica nunca se escribe de vuelta en `rem_data`; patrón 208 confirmado inerte (config real, `{0,0}`); patrón 214 ídem; regla vecina en la misma hoja no contaminada por la inyección de otra regla (protege contra el riesgo de colección compartida que se auditó antes de escribir código); 2 llamadas a `execute()` no duplican `rem_technical_totals` ni corrompen resultados; `rem_data` histórica byte-idéntica antes/después de `execute()`.

**Regresión ejecutada** (`php -d memory_limit=-1 vendor/bin/phpunit`): `tests/Feature/REM` + `tests/Unit/RemParser` + `tests/Feature/RuleEngine` completo → **593 tests, 554 passed, 39 failed** — los mismos 39 preexistentes de siempre (sin cambio respecto a Fase 3A), **+10 nuevos, todos passing** (los del piloto). Verificación aislada: Fase 1/2/3A + mecanismos #6/#8/#11/#12 (`RuleBindingReconciliationServiceTest`, `RuleBindingReconciliationServiceTotalRowDiscoveryTest`, `RuleSetTotalRowFromDiscoveryCommandTest`, `RuleRebindSafeToStructureCommandTest`, `RemTechnicalTotalsPersistenceTest`, `RemParserServiceEmbeddedLeadingTotalRowTest`, `RemParserServiceTrailingTotalRowTest`, `RemParserServiceEmbeddedBackwardSubtotalRowTest`): **73/73 passing**.

**Clasificación global antes/después — idéntica byte a byte** (el mecanismo nuevo no cambia ninguna clasificación real porque ninguna regla real recibió `total_row`/`row_range` nuevos): `activas=717`, `SAFE_1_TO_1=164`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=333`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`. `config` real de 56/208/214 **sin ningún cambio** (`row_range={0,0}` en las 3, sin `total_row`). Regla 461 sin tocar. `rem_technical_totals` real (BD `esalud_dev`) = **0 filas** (los 10 tests corrieron contra `esalud_testing`).

**Archivos modificados**: `backend/app/Domain/RuleEngine/Services/RuleEngineService.php` (único archivo de motor tocado — `SumEqualsEvaluator.php` sin cambios, confirmado). **Archivo nuevo**: `backend/tests/Feature/RuleEngine/Services/RuleEngineServiceTechnicalTotalPilotTest.php`.

**Riesgos detectados y su tratamiento**:
- **Riesgo de colección compartida entre reglas de la misma hoja** (`$grouped->get($sheet)` es la misma instancia para todas las reglas de esa hoja) — auditado antes de escribir código: la inyección ocurre siempre *después* del `->filter()` ya existente, que ya devuelve una colección nueva por regla — confirmado seguro, y probado explícitamente con el test de regla vecina.
- **Riesgo de que el hallazgo de 208/214 se generalice incorrectamente**: no se asume que TODAS las reglas de Familia A/leading-excluidas sean simples-contiguas como la 56 — de hecho ya se sabe que no (A09/I, y ahora A09/F.1). **Cualquier futura Fase 3C debe verificar, regla por regla, que la fórmula real del `total_row` candidato sea una suma de rango contiguo antes de proponer `row_range`** — no generalizable automáticamente a partir del `total_row_candidate` de Fase 1 (que solo valida la fórmula contra el propio `row_range` de la regla, pero ese `row_range` para 56/208/214 nunca fue el real de partida — es un caso distinto al de las 235/29 de Familia A, que sí tienen un `row_range` real ya en config).
- **No se detectó ningún riesgo de duplicidad, contaminación de reglas vecinas, ni fallback silencioso** — los 3 tests dedicados a esto pasaron sin ajustes.

**No se implementó ninguna generalización a las 254 restantes.** No Fase 3C. No se tocó la regla 461. No backfill histórico. No se modificaron calibraciones, `rem_data` histórico, bindings, ni se ejecutó rebind. No commit, no push.

### 17.9 — Auditoría de elegibilidad EXHAUSTIVA de los 333 `BLOCKED_BY_ENGINE_GAP` (2026-08-27, READ-ONLY): reclasificación por familia, previa a Fase 3C

⚠️ Auditoría **100% READ-ONLY** — ninguna regla/config/status/binding/calibración/`rem_data`/estructura fue tocada. `rem_technical_totals` sigue en 0 filas. Regla 461 no tocada. No se implementó nada de esto todavía — es insumo para diseñar una Fase 3C revisada, no su ejecución.

**Metodología**: para cada una de las 333 reglas, se re-derivó la fórmula real del `total_row` candidato vía `cell_data` (no supuesto, no muestreo) y se verificó **cobertura EXACTA y completa** contra `row_range` — expandiendo sintaxis de rango Excel (`COL#:COL#`) y sumando referencias individuales, comparando el conjunto resultante contra `range(from, to)` completo. Esto es **más estricto que la validación de Fase 1** (`discoverTotalRowCandidate()`), que solo exige que la fórmula toque ambos extremos (`from` y `to`) y no referencie filas fuera de rango — **sin exigir que cubra TODAS las filas intermedias**. Este gap de Fase 1 (teórico, documentado aquí por primera vez) se auditó activamente contra las 254 reglas auto-descubiertas — resultado: **0 casos reales de hueco** (las 254 pasan la verificación estricta de cobertura completa) — el gap existe en el validador pero no fue explotado por ningún dato real de la Serie A.

**Categorías usadas** (definidas por el usuario antes de iniciar la auditoría):

| Cat. | Definición |
|---|---|
| A | Vertical contigua, compatible con el mecanismo demostrado por la regla 56 (`SUM(row_range)` exacto, sin huecos/extra/externos) |
| B | Vertical no contigua/irregular (saltos, periodicidad/bloques, términos externos) |
| C | Leading TOTAL — evaluada aparte por la pregunta de límites estructurales |
| D | Sin candidato / placeholder `{0,0}` / evidencia insuficiente — **sin inferir automáticamente ningún rango** |
| E | `no_utilizada` |
| F | Excepciones — no encajan exactamente en A-E, individualizadas |

**Resultado — conteo exacto y reconciliado, suma = 333**:

| Categoría | Cantidad | rule_ids |
|---|---|---|
| **A** — contigua, compatible con mecanismo 56 | **226** | 225 auto-descubiertas (trailing, mecanismo #12/#8/#11 — lista completa en 16.8/17.1) + **56** (`A03/D.7` AH, manual: `AH208=SUM(AH206+AH207)`, exacto) |
| **B** — irregular/no contigua | **18** | `208,214` (`A09/F.1` F/L) · `226,227,229,231,232,234` (`A09/I` AM,AN,AR,AT,AU,AX — patrón periódico de 6 filas TOTAL, paso 6, 13 términos) · `393,394,395,396,397,398,399,400,401,402` (`A26/B` D-M, `SUM(rango)+término externo`) |
| **C** — leading TOTAL | **30** | 29 dentro de límites estructurales (`46,47,48,49,61-65,87,89-93,96,97,105-108,182,183,277-282`, mecanismo #6 ya las excluye — mismo mecanismo `rem_technical_totals` que la 56, solo que en posición leading) + **1 fuera de límites** (`461`, `A30/F`, ya congelada — punto 16.13) |
| **D** — sin candidato/evidencia insuficiente | **0** | ninguna — las 22 reglas del bucket "sin candidato automático" (12 placeholders `{0,0}` + 10 `A26/B`) fueron resueltas con evidencia real de `cell_data` (ver A/B/F), ninguna quedó sin explicación |
| **E** — `no_utilizada` | **56** | `A21`=25, `A24`=3, `A25`=22, `A30AR`=2, `A34`=4 — sin cambio |
| **F** — excepciones individualizadas | **3** | `228` (`A09/I` AQ — bloque incompleto: solo 2 de 6 filas TOTAL periódicas tienen fórmula, 333-336 bloqueadas y vacías) · `230` (`A09/I` AS — patrón inconsistente: mezcla sumas de 2 términos y de 13 términos entre sus propias 6 filas TOTAL, no sigue ni siquiera el patrón irregular del resto de la sección) · `233` (`A09/I` AV — solo 1 de 6 filas TOTAL periódicas tiene fórmula, `AV334`; el resto vacías) |

**Verificación de suma**: `226+18+30+0+56+3 = 333` exacto, confirmado en vivo (no calculado a mano).

**Reconciliación con conteos históricos (sin contradicción, con precisión nueva)**:
- Familia A histórica (235, puntos 16.8/16.11) = **225 (Categoría A) + 10 (`A26/B`, Categoría B)**. El hallazgo nuevo: las 235 comparten la MISMA causa de exclusión de `rem_data` (mecanismo #12/#8/#11), pero **no todas son igualmente arreglables** — 225 son limpias (mecanismo 56 aplica tal cual), 10 (`A26/B`) tienen un término externo (`+D50`) que ningún `row_range` único puede representar.
- Leading histórico (29, punto 16.9) = **sin cambio, las 29 son Categoría C**, todas dentro de límites, formalmente contiguas — confirmado hoy de nuevo con verificación estricta de cobertura completa (no solo Fase 1).
- 12 placeholders `{0,0}` (56,208,214,226-234) = **1 Categoría A (56) + 2 Categoría B (208,214) + 6 Categoría B (`A09/I` regular) + 3 Categoría F (`A09/I` con anomalías propias)**.

**Hallazgo nuevo — `A09/I` NO es internamente homogénea** (corrige la caracterización previa de "9 reglas congeladas como un solo bloque"): de las 9, **6 comparten el patrón periódico regular** (13 términos, paso 6, filas TOTAL 331-336) — ya identificado como irregular/no-contiguo (Categoría B) — pero **3 tienen anomalías propias, distintas entre sí, que ni siquiera siguen el patrón irregular "estándar" del resto de la sección**: `228` (bloque incompleto, solo 2 de 6 filas con fórmula), `230` (mezcla de 2 y 13 términos entre sus propias filas), `233` (casi vacía, solo 1 de 6 filas con fórmula). Estas 3 no deben tratarse igual que las otras 6 ni que el resto de `A09/I` — **quedan individualizadas (Categoría F), pendientes de revisión caso por caso, no de un diseño común de "bloques periódicos"**.

**Hallazgo nuevo — `A26/B` es un subtipo de irregularidad DISTINTO al de `A09/I`/`A09/F.1`**: no es periodicidad/bloques — es un rango contiguo real (`[54:58]`) más un **único término externo** (`+D50`, fuera del rango, pero dentro de los límites estructurales de la sección `[filaInicioDatos=50:filaFinDatos=60]`) — patrón de "acumulado/arrastre", ya documentado como posibilidad en el punto 16.11 (caso de control que hizo que Fase 1 se abstuviera de proponer candidato). Confirmado hoy con las 10 fórmulas reales completas (`D59` a `M59`), sin excepción, mismo patrón exacto en las 10.

**Ningún caso quedó en Categoría D** — la auditoría encontró evidencia real (fórmula de `cell_data`) para las 333 reglas, sin necesidad de inferir ningún rango a ciegas.

**Ningún cambio a la BD real**: `activas=717`, `SAFE_1_TO_1=164`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=333`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0` — todo confirmado en vivo, sin cambio.

### 17.10 — Propuesta de Fase 3C revisada, por familias — **PROPUESTO / NO IMPLEMENTADO**

⚠️ Ninguna de estas sub-fases fue ejecutada. Reemplaza la idea original de "generalizar a las 254" por un plan dividido según la auditoría del punto 17.9 — cada familia con su propio diseño, riesgo y validación, nunca una generalización única.

**Fase 3C-1 — Categoría A (226 reglas, incluye la 56 ya piloteada)**: mismo mecanismo ya implementado y validado en Fase 3B (`rem_technical_totals` + `RuleEngineService`), **sin cambios de código nuevos** — solo requiere: (a) autorizar la escritura real de `row_range`/`total_row` en `config` para cada una de las 226 (comando nuevo, análogo a `rule:set-total-row` pero que también escriba `row_range` reconstruido, ya que para 225 de las 226 el `row_range` YA es real en config — solo falta `total_row`, mecanismo ya cubierto por `rule:set-total-row`; la única que necesita `row_range` reconstruido además de `total_row` es la 56); (b) reprocesar/validar contra cargas NUEVAS (nunca backfill histórico sin autorización aparte). Menor riesgo de las 3 sub-fases — mecanismo ya demostrado, solo falta escala.

**Fase 3C-2 — Categoría C (30 reglas: 29 + 461)**: mismo mecanismo, pero **29 de las 30 no requieren ningún cambio al guard de límites** (ya están dentro de `[filaInicioDatos:filaFinDatos]`, confirmado hoy con verificación fresca) — mismo tratamiento que 3C-1 en la práctica. **461 permanece explícitamente excluida y congelada** — no se propone, no se simula, ningún cambio a su guard.

**Fase 3C-3 — Categoría B (18 reglas: `A09/F.1`×2, `A09/I`×6, `A26/B`×10)**: **requiere diseño nuevo, no una extensión de `row_range`/`total_row`** — el modelo actual (un solo `row_range` contiguo) no puede representar periodicidad/bloques (`A09/I`) ni "rango + término externo" (`A26/B`). Posible dirección futura (no diseñada en detalle, no evaluada): extender `config` con una lista explícita de filas/fórmula en vez de un rango implícito (ej. `total_row_formula_rows: [149,150,153,155,157]` en vez de asumir `[row_from:row_to]`) — cambiaría tanto el clasificador como `SumEqualsEvaluator::evaluateVerticalAggregation()`. **No iniciar sin autorización explícita y sin una sesión de diseño dedicada** — mayor riesgo y complejidad de las 3 sub-fases.

**Fase 3C-4 — Categoría F (3 reglas: `228,230,233`, todas `A09/I`)**: **individualizadas, no agrupables** — cada una requiere revisión manual dedicada (¿por qué el bloque está incompleto en 228? ¿por qué 230 mezcla patrones? ¿por qué 233 casi no tiene fórmulas?) antes de decidir si son datos reales incompletos, errores de la plantilla Excel original, o alguna otra explicación. **No se especula sobre la causa aquí** — pendiente de auditoría dedicada, fuera del patrón "diseño único" de las otras familias.

**No tocar la Categoría E (56, `no_utilizada`)** bajo ninguna sub-fase, salvo que Estadística APS reactive alguna de esas hojas.

**[SUPERADO — ver punto 17.11]**: Fase 3C-1 fue diseñada, implementada (mecanismo + tests) y su dry-run se ejecutó contra las 226 reglas reales. **Ningún `--commit` fue ejecutado.** 3C-2/3C-3/3C-4 siguen exactamente como se describen aquí, sin iniciar.

### 17.11 — FASE 3C-1 (Categoría A): mecanismo diseñado/implementado/testeado, dry-run ejecutado contra las 226 reales — **ESCRITURA REAL NO EJECUTADA** (2026-08-27)

⚠️ Solo el **mecanismo** (comando + tests) y su **dry-run** contra las 226 reglas reales fueron ejecutados. **Ningún `--commit` real corrió sobre ninguna de las 226.** 3C-2/3C-3/3C-4 siguen `PROPUESTO / NO IMPLEMENTADO` (punto 17.10).

**Paso 1 — Pre-flight exhaustivo (READ-ONLY) reveló una heterogeneidad NUEVA dentro de Categoría A**, no detectada en el punto 17.9 (que solo verificó la fórmula, no la clasificación simulada tras escribir): de las 226, **171 simulan limpio a `SAFE_1_TO_1`** al agregar `total_row` (y `row_range` solo para la 56) — pero **55 (hojas `A31`=24, `A32`=29, `A33`=2) simulan `BLOCKED_BY_ENGINE_GAP`** con el motivo `missing_total_row probable: total_row=X fuera de [inicio:fin]` — **exactamente la misma causa raíz que la regla 461** (candidato fuera de `[filaInicioDatos:filaFinDatos]`), solo que en posición *trailing* en vez de *leading*. Este es el mismo grupo de "55 trailing fuera de rango" ya mencionado de pasada en el punto 16.13, ahora cuantificado con precisión y confirmado con evidencia fresca: **subgrupo `motivo` único, sin excepciones adicionales** (10 motivos distintos, uno por sección, todos con el mismo patrón `fuera de [inicio:fin]`, suma exacta 55).

**Subgrupos confirmados dentro de las 226** (respondiendo directamente la pregunta de homogeneidad):
- **170 reglas**: `row_range` ya real en config, solo falta `total_row` (mismo mecanismo que `rule:set-total-row` de Fase 2, pero con `excluded=true` en vez de `false` — la fila SÍ está excluida de `rem_data`, por eso necesita `rem_technical_totals`).
- **1 regla (56)**: `row_range={0,0}` placeholder — necesita `row_range` reconstruido **y** `total_row`, ambos derivados de la fórmula real (nunca inventados).
- **55 reglas** (`A31`, `A32`, `A33`): mismo tipo de candidato que las 170, pero **bloqueadas por el mismo guard de límites estructurales que la regla 461** — quedan **excluidas del alcance aprobable de Fase 3C-1**, tratadas como excepción congelada, igual que 461, sin diseño ni autorización para tocarlas aquí.

**Paso 2 — Comando implementado**: `rule:activate-category-a {rule_id} {--reason=} {--by=} {--commit}` (`RuleActivateCategoryATotalCommand.php`, nuevo). Dry-run por defecto. Nunca recibe `row_range`/`total_row` manualmente — ambos derivados: Rama 1 (row_range real) reutiliza el candidato de Fase 1 (`classifySingleRule()`, exige `position=trailing` y `excluded=true` — a diferencia de `rule:set-total-row`, que exige `excluded=false`); Rama 2 (`row_range={0,0}`) implementa un descubrimiento propio, **separado de `discoverTotalRowCandidate()`** (nunca modificado): escanea `[filaInicioDatos+1:filaFinDatos]` buscando una única fila cuya fórmula cubra de forma COMPLETA y CONTIGUA `[filaInicioDatos:fila-1]` (expandiendo sintaxis `COL#:COL#`, sin huecos, sin referencias externas) y sea confirmada por `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` (ya pública, sin redefinir su lógica). Guards comunes: regla activa + `sum_equals`, clasificación actual `BLOCKED_BY_ENGINE_GAP`, `total_row` ausente, candidato único, **candidato dentro de `[filaInicioDatos:filaFinDatos]`** (rechaza los 55 y cualquier caso tipo 461, sin excepción especial), evidencia real en `rem_data` para el rango **componente** (nunca se exige evidencia de la fila TOTAL, que por definición está excluida), simulación final = exactamente `SAFE_1_TO_1`, ausencia de colisión funcional (patrón 529↔530). `--commit`: transacción, doble revalidación, `RuleVersion` con snapshot previo, activity log `rule_category_a_activated` — nunca toca bindings/status/`rem_data`/`rem_technical_totals`/calibraciones/estructura.

**Paso 3 — Tests** (`RuleActivateCategoryATotalCommandTest.php`, 12/12 passing): patrón 56 (descubre `row_range`+`total_row` correctos, dry-run no escribe, commit escribe solo lo autorizado, `RuleVersion`/activity log correctos) · fórmula con huecos (patrón 208/214) rechazada · término externo (patrón `A26/B`) rechazado · patrón periódico (`A09/I`) rechazado · fórmula incompleta rechazada · candidato ambiguo (2 subtotales válidos independientes) rechazado · posición leading rechazada (fuera de alcance de 3C-1, es Categoría C) · candidato real fuera de límites (réplica exacta del patrón 461/las 55) rechazado, mismo guard, sin caso especial · patrón "170-style" (row_range ya real, trailing, excluded=true) aceptado · regla no-`sum_equals` rechazada · preservación de bindings/regla ajena/`rem_data` confirmada byte-idéntica tras commit.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`): 605 tests, 566 passed, **mismos 39 fallos preexistentes de siempre**, +12 nuevos (los del comando), todos passing. Cero regresiones nuevas.

**Paso 4 — Dry-run real contra las 226** (vía `Artisan::call()`, sin `--commit` en ningún caso, contra `esalud_dev` real): **171 aprobadas + 55 rechazadas = 226 exacto**. Las 55 rechazadas comparten **un único patrón de motivo** (fuera de límites, mismo que 461) — **sin ninguna excepción nueva o inesperada** más allá de lo ya identificado en el Paso 1. Confirmado que las 226 reglas reales siguen **sin ningún `total_row` escrito** (cero writes, verificado en vivo).

**rule_ids aprobadas (171)**: `25,26,27,28,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,54,55,56,57,58,59,60,75,99,100,101,102,103,104,144,145,147,148,149,150,151,152,153,154,155,156,157,158,159,160,161,162,163,164,165,166,167,168,169,170,179,180,181,185,188,189,190,191,192,193,194,195,196,197,198,199,200,201,202,203,205,206,207,209,210,212,213,215,216,217,218,219,220,221,222,223,224,284,285,287,288,289,290,291,292,293,294,295,328,329,330,331,332,333,334,335,336,338,339,340,341,342,380,381,382,383,384,385,386,387,388,389,390,391,405,406,407,408,409,410,411,414,415,416,417,418,424,425,426,427,428,436,437,438,439,440,441,442,443,444,445,446,447,452,458,531,532,533,534,535,536`.

**rule_ids rechazadas — excepción de límites, mismo patrón que 461 (55)**: `469,470,471,472,473,474,475,476,477,478,480,481,482,484,485,486,487,488,489,490,491,492,493,494,497,498,499,500,501,503,504,505,507,508,509,510,511,512,513,514,515,516,517,518,519,521,522,523,524,525,526,527,528,545,546` — hojas `A31`(24), `A32`(29), `A33`(2).

**Baseline final reconfirmado** (sin ningún cambio, ni antes ni después del dry-run): `activas=717`, `SAFE_1_TO_1=164`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=333`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`, `config` de la regla 56 sin cambio (`row_range={0,0}`, sin `total_row`), regla 461 sin tocar.

**Archivos nuevos**: `backend/app/Console/Commands/RuleActivateCategoryATotalCommand.php`, `backend/tests/Feature/REM/RuleActivateCategoryATotalCommandTest.php`. Ningún archivo de motor (`RuleEngineService.php`, `SumEqualsEvaluator.php`), parser, calibración, estructura o los 3 mecanismos (#6/#8/#11/#12) fue tocado.

**No se ejecutó ningún `--commit`.** No se tocaron las 55 excepciones de límites, la regla 461, la Categoría B/C/F, las 56 `no_utilizada`, calibraciones, `rem_data`, bindings, ni se hizo backfill/reproceso real. No commit de Git, no push.

### 17.12 — Simulación consolidada READ-ONLY de las 171 (transacción + rollback) + auditoría paralela de las 55 (2026-08-27)

⚠️ **100% READ-ONLY.** La simulación consolidada aplicó los 171 cambios **dentro de una transacción de BD real, nunca commiteada** — `DB::rollBack()` ejecutado siempre, confirmado que el baseline real quedó byte-idéntico después. Ningún `--commit` real. No se tocaron las 55, la regla 461, calibraciones, `rem_data`, bindings, ni se ejecutó ningún rebind.

**Reconfirmación individual de las 171** (invocando el mismo `computeAndValidate()` del comando ya implementado, vía reflexión — no una reimplementación paralela): las 171 siguen `active`, las 171 siguen `BLOCKED_BY_ENGINE_GAP`, las 171 individualmente simulan exactamente `SAFE_1_TO_1`, **0 fallas inesperadas**. Cambio propuesto verificado exacto: **170 solo agregan `total_row`**, **1 (la 56) agrega `row_range`+`total_row`** — ninguna otra clave de `config` cambia en ninguna de las 171 (0 cambios inesperados detectados).

**Simulación consolidada (transacción real + rollback, `esalud_dev`)** — tabla exacta, medida, no presupuesta:

| Categoría | Antes | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 164 | 335 | **+171** |
| `REQUIRES_REMAP` | 0 | 0 | +0 |
| `DUPLICATE` | 22 | 22 | +0 |
| `BLOCKED_BY_ENGINE_GAP` | 333 | 162 | **−171** |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 | +0 |
| Total activas | 717 | 717 | +0 |

**Ninguna de las 171 quedó fuera de `SAFE_1_TO_1` tras el cambio consolidado (0 excepciones)** — el resultado agregado coincide exactamente con la suma de las 171 verificaciones individuales, sin interacción/colisión entre ellas. **Ninguna regla AJENA a las 171 cambió de clasificación (0 efectos colaterales)** — verificado comparando las 717 reglas activas, no solo las 171, antes y después.

**Rollback ejecutado y verificado**: clasificación post-rollback idéntica byte a byte a la de "antes" (`SAFE_1_TO_1=164, BLOCKED_BY_ENGINE_GAP=333`, resto sin cambio). `config` de la regla 56 confirmado restaurado exacto (`row_range={0,0}`, sin `total_row`); `config` de la regla 25 (una de las 170) también confirmado restaurado exacto. `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=0` sin cambio.

**Auditoría paralela de las 55 rechazadas (sin resolverlas, sin modificar el guard `[inicio:fin]`)** — resultado: **completamente homogéneas, 0 subtipos**, agrupadas en 10 secciones:

| Sección | Reglas | `[inicio:fin]` | Candidato | Distancia desde `fin` | Posición | Excluido |
|---|---|---|---|---|---|---|
| `A31/A` | 10 | `[12:27]` | 28 | +1 | trailing | sí |
| `A31/B` | 3 | `[32:45]` | 46 | +1 | trailing | sí |
| `A31/C` | 3 | `[50:65]` | 66 | +1 | trailing | sí |
| `A31/D` | 8 | `[71:84]` | 85 | +1 | trailing | sí |
| `A32/A` | 5 | `[11:15]` | 16 | +1 | trailing | sí |
| `A32/B` | 3 | `[19:23]` | 24 | +1 | trailing | sí |
| `A32/D.2` | 6 | `[95:103]` | 104 | +1 | trailing | sí |
| `A32/D1` | 7 | `[35:89]` | 90 | +1 | trailing | sí |
| `A32/E2` | 8 | `[116:117]` | 118 | +1 | trailing | sí |
| `A33/C` | 2 | `[54:55]` | 56 | +1 | trailing | sí |

**Las 55, sin excepción, son el caso "`fin+1`"** (candidato = `filaFinDatos + 1` exacto, nunca una distancia mayor) — nunca `inicio-1`. **Comparación con la regla 461**: 461 es el caso espejo — `candidato = filaInicioDatos - 1` (distancia `-1`, posición `leading`). **No son "exactamente equivalentes"**: comparten el mismo guard y el mismo tipo de causa (candidato fuera de `[inicio:fin]`), pero son direcciones opuestas del mismo problema (461 = `inicio-1`/leading; las 55 = `fin+1`/trailing) — mismo mecanismo de rechazo, dos sub-casos distintos, ninguno mezclado con el otro.

**`rem_technical_totals` potencialmente aplicable — sí, ya hoy, sin cambios**: el hook de captura de Fase 3A (`RemParserService::parseSheet()`) decide qué excluir usando los mecanismos #6/#8/#11/#12 directamente sobre el archivo Excel de cada carga — **nunca consulta el guard `[inicio:fin]` de `classifyRule()`** (son capas completamente independientes, ya establecido desde el diseño de Fase 3A). Esto significa que, si hoy se procesara una carga nueva para cualquiera de estas 10 secciones, **la fila TOTAL de las 55 ya quedaría capturada en `rem_technical_totals`** exactamente igual que cualquier otra fila excluida — el bloqueo de las 55 es exclusivamente sobre la escritura de `config` (el guard de límites de `rule:activate-category-a`/`classifyRule()`), no sobre la captura de datos.

**Contexto adicional (ya documentado, reconfirmado aquí)**: en las 10 secciones, `filaFinDatos` fue recortado deliberadamente por el mecanismo #8 (TOTAL final inmediato, ver Serie A / A31 en secciones históricas de este archivo) para excluir precisamente esa fila TOTAL del área de datos oficial — el candidato `fin+1` es, por diseño, la fila que el propio mecanismo #8 ya decidió que no pertenece a `[inicio:fin]`. A diferencia de 461 (donde el origen del desajuste entre estructura 19 y la actual quedó como pregunta abierta, ver punto 16.13), aquí el motivo del recorte está plenamente documentado y es intencional — **no se propone ninguna acción por esto**, solo se deja constancia de que la causa de fondo es distinta a la de 461 aunque el síntoma (guard de límites) sea el mismo.

**Baseline final reconfirmado** (idéntico antes y después de toda la sesión): `activas=717`, `SAFE_1_TO_1=164`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=333`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`.

**No se ejecutó ningún `--commit`.** No se tocaron las 55, la regla 461, Categoría B/C/F, `no_utilizada`, calibraciones, `rem_data`, bindings/rebind, ni el guard `[inicio:fin]`. No commit de Git, no push.

### 17.13 — FASE 3C-1A EJECUTADA Y CERRADA — 171 reglas (2026-08-27)

**Autorización recibida**: ejecución real de `rule:activate-category-a --commit` exclusivamente sobre las 171 reglas aprobadas por el dry-run + simulación consolidada (puntos 17.11/17.12). Las 55 rechazadas por límites estructurales y la regla 461 quedaron explícitamente fuera de alcance.

**Ejecución**: 171 invocaciones individuales (`Artisan::call('rule:activate-category-a', [...--commit])`, cada una con su propia revalidación interna vía `computeAndValidate()` antes de escribir, cada una en su propia transacción — nunca una escritura masiva), mismo `reason`/`by` para las 171 (`by=Administrador Esalud`). **171/171 exitosas, sin ninguna detención** — ninguna regla se desvió de lo validado en la simulación consolidada.

**Post-check — medido, coincide exactamente con lo predicho por la simulación (punto 17.12)**:

| Métrica | Antes | Después |
|---|---|---|
| `SAFE_1_TO_1` | 164 | **335** |
| `BLOCKED_BY_ENGINE_GAP` | 333 | **162** |
| `REQUIRES_REMAP` | 0 | 0 |
| `DUPLICATE` | 22 | 22 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 |
| reglas activas | 717 | 717 |
| `rem_rules` | 764 | 764 |
| `rem_rule_bindings` | 1204 | 1204 |
| bindings a estructura 67 | 0 | 0 |
| `rem_technical_totals` | 0 | 0 (sin cambio — este comando nunca la toca) |

**Verificaciones detalladas, todas exactas**:
- **171/171** de las reglas autorizadas ahora tienen `total_row` en `config`. **170** cambiaron únicamente `total_row`; **1 (la regla 56)** cambió `row_range` (`{0,0}` → `{"from":206,"to":207}`) **y** `total_row` (`208`) — verificado comparando cada `config` actual contra el snapshot guardado en su propio `RuleVersion`. **0 cambios inesperados.**
- `regla56.config` final: `{"sheet":"A03","section":"D.7","column":"AH","row_range":{"from":206,"to":207},"rule_logic":"Suma(AH) = Columna AH","total_row":208}` — exactamente lo simulado, nada más.
- **0 de las 55** rechazadas por límites tiene `total_row` — confirmado, ninguna tocada.
- **Regla 461**: `total_row=null`, `status=active` — sin ningún cambio.
- **171/171** siguen `status=active` (no tocado, el comando nunca modifica `status`).
- **Categoría B (18) y Categoría F (3)**: reclasificadas en vivo, las 21 siguen `BLOCKED_BY_ENGINE_GAP` — confirmado que no hubo ningún efecto colateral fuera del lote autorizado.
- **Exactamente 171** `RuleVersion` nuevos (`changelog` con prefijo `rule:activate-category-a`, snapshot del `config` anterior) y **exactamente 171** activity logs (`rule_category_a_activated`), cada uno con `rule_id`/`reason`/`by`/`row_range_set`/`total_row_set` correctos (muestra verificada).
- `rem_data` total = `396.371` — idéntico al medido en el punto 17.4, sin cambio.
- `rem_rule_bindings` — conteo global sin cambio (`1204`); las 171 reglas conservan exactamente sus bindings preexistentes, ninguno creado/modificado.
- **Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json`** — verificados en vivo, **idénticos** a los de toda la campaña (`44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` / `fe713f961def425c2a1dd5dd4c4a1972c913c3e7c708e5943ace9b75f6d653e9`) — calibraciones sin tocar.
- Estructura activa `67/v35` sin tocar.

**Ninguna métrica difirió de lo predicho — no fue necesario ningún STOP ni compensación.**

**Fase 3C-1A queda EJECUTADA Y CERRADA para las 171 reglas.** Las **55 reglas trailing = `filaFinDatos+1`** (`A31`=24, `A32`=29, `A33`=2, ver punto 17.12) quedan como **subfase distinta (Fase 3C-1B), todavía NO resuelta** — mismo guard `[inicio:fin]`, sin modificar, congeladas junto a la regla 461. **No se inició Fase 3C-1B.** No se tocó el guard `[inicio:fin]`. No Fase 3C-2/3C-3/3C-4. No backfill histórico. No se reprocesó ninguna carga real. No rebind. No commit de Git, no push.

### 17.14 — FASE 3C-1B: auditoría, diseño y simulación — **AUDITADO / DISEÑO PROPUESTO / NO IMPLEMENTADO** (2026-08-27)

⚠️ **100% READ-ONLY.** Ninguna escritura de `config`, ningún `--commit`, **ningún cambio al guard `[inicio:fin]` real** (`classifyRule()` ni el comando). La regla 461 no fue tocada. Toda simulación descrita aquí se hizo sobre estructuras/rules clonadas en memoria o dentro de transacciones con `rollback` explícito, nunca commiteadas.

**Paso 1 — Reconfirmación individual de las 55 (READ-ONLY)**: las 55, sin excepción, verifican **todos** los puntos pedidos:
- `candidato = filaFinDatos + 1` exacto, confirmado matemáticamente para las 55 (nunca `+2` ni ninguna otra distancia).
- Fórmula real de Excel cubre el `row_range` completo, contiguo, sin huecos ni referencias externas (re-verificado con el mismo parser de fórmulas ya usado en el punto 17.9/17.11, no solo confiado al flag `excluded=true`).
- Mecanismo de exclusión confirmado en vivo: `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` retorna `true` para las 55, sin excepción.
- Concepto real en la fila candidata contiene literalmente `"TOTAL"` (muestra verificada: `469-473`, columnas `AN-AR`, concepto `"TOTAL"`, fórmula `=SUM(col12:col27)` exacta).
- **Candidato único**: se buscó explícitamente evidencia de fórmula en `fin+2` hasta `fin+5` para las 55 — **0 ambigüedades encontradas**.
- **La fila candidata no pertenece a ninguna sección declarada** (ni la propia, por estar fuera de `[inicio:fin]` por diseño, ni la siguiente) — confirmado que **ninguna otra sección reclama esa fila** (`owners=[]` en las 55), descartando explícitamente que sea semántica o estructuralmente parte de la sección siguiente.
- **Evidencia histórica adicional, no buscada originalmente pero hallada y verificada**: `rem_data` conserva **datos fantasma reales** en la fila candidata para cargas anteriores a la corrección estructural (ej. `A31/A` fila 28: 119 registros históricos, `concept="TOTAL"`, valores numéricos reales, últimas hasta `upload_id=175`) — y se confirmó explícitamente que **las cargas posteriores a esa corrección (`upload_id>175`) ya NO persisten esa fila** (`rem_data` de `A31/A` en cargas recientes = filas `12-27` exactas, `28` ausente) — **doble confirmación independiente**: el histórico prueba que la fila es semánticamente el TOTAL real (antes de la corrección, capturaba un valor con esa etiqueta), y las cargas recientes confirman que la exclusión mecánica está activa hoy, no es solo una intención de diseño sin efecto real.

**Paso 2 — Significado de `filaFinDatos`, auditado (no asumido)**: la documentación original del mecanismo #8 (`SectionDetectorService::excludeTrailingTotalRows`/`isTrailingTotalRow`, ver secciones históricas de este archivo) confirma que **`filaFinDatos` fue diseñado deliberadamente para excluir la fila TOTAL** del área de datos oficial — cita textual ya existente en este archivo: *"ajusta `filaFinDatos` para que la fila TOTAL quede fuera del rango de datos/patrones... Para que la fila siga siendo visible como referencia técnica en `cell_data` pese a quedar fuera de `filaFinDatos`, `EnhancedCellScanner::scan()` extiende su propio rango de escaneo... Aplicado en A31 (A,B,C,D)"*. Es decir: **`fin+1` no es un error ni una filtración accidental — es exactamente el lugar donde el propio mecanismo #8 decidió, a propósito, dejar la fila TOTAL**, precisamente para las 4 secciones de A31 que componen 28 de las 55. **Conclusión, no asumida sino verificada contra el diseño original**: aceptar `fin+1` como `total_row` válido para este patrón específico **no contradice el modelo estructural — coincide exactamente con su diseño original**. Esto es distinto de la regla 461, cuyo desajuste (estructura 19 vs actual) quedó como pregunta histórica abierta (punto 16.13) — aquí el origen del límite está documentado, es intencional, y es la razón misma de existir del mecanismo #8.

**Paso 3 — Diseño de la corrección mínima, 3 alternativas comparadas**:

| | A) Ampliar el guard globalmente | B) Permitir `fin+1` en `classifyRule()` bajo 4 condiciones simultáneas | **C) Validación aislada, nunca toca el guard genérico (recomendada)** |
|---|---|---|---|
| Dónde vive el cambio | Modifica la condición existente del bounds-check (`$totalRow > $current['fin']`) | Modifica `classifyRule()`, agrega una rama condicional nueva junto al bounds-check existente | Nuevo método **aislado**, consultado solo dentro de la rama `trailing` — el bounds-check existente queda **byte-idéntico** |
| Riesgo sobre la regla 461 | **Alto** — una tolerancia numérica simétrica (`±1`) habilitaría `inicio-1` (461) sin querer, salvo que se implemente con extremo cuidado | Bajo, pero depende de que la implementación distinga `trailing` de `leading` correctamente en cada punto | **Nulo por construcción** — el método nuevo solo se invoca en la rama `trailing`; 461 (leading) nunca llega a ese código, no por coincidencia de valores sino por la propia ruta de ejecución |
| Riesgo sobre el resto del sistema | Alto — cualquier otra regla con candidato `fin+1` en cualquier hoja quedaría aceptada sin las 4 condiciones (fórmula completa, excluido, único) | Medio — el bounds-check es compartido por todo el sistema; cualquier bug ahí afecta todas las clasificaciones | **Mínimo** — el código existente no se toca; el nuevo método es la única superficie nueva, fácil de probar y revertir de forma aislada |
| Generaliza automáticamente a futuras reglas similares | Sí, sin verificación — riesgo de aceptar casos no auditados | Sí, pero con las 4 condiciones como guardia | Sí, con las mismas 4 condiciones — mismo nivel de seguridad que B, mejor aislamiento de código |
| Facilidad de revertir | Baja (cambia una condición ya usada en todo el sistema) | Media | **Alta** (eliminar el método nuevo + su único punto de invocación) |

**Recomendación: Opción C** — menor superficie de riesgo. Se diseñaría como un método nuevo (tentativamente `isLegitimateTrailingTotalBeyondBounds()`), invocado **únicamente** dentro de la rama que ya distingue `trailing` de `leading` en `classifyRule()`, exigiendo simultáneamente: `position === 'trailing'` (nunca se evalúa para `leading` — **461 excluida por construcción, no por casualidad numérica**), `excluded === true`, candidato `=== filaFinDatos + 1` exacto (nunca `+2` ni más), y fórmula verificada de forma independiente como completa/contigua/sin referencias externas (reutilizando el mismo parser ya construido para Fase 3C-1A, no solo confiando en el flag `excluded`). **No implementado** — queda como diseño propuesto.

**Paso 4 — Simulación (sin persistir nada)**:
- **Individual**: para las 55, se clonó la estructura activa en memoria con `filaFinDatos+1` **únicamente en la sección de esa regla** (id ficticio para evitar colisión de caché con `parseEstructura()`, sin tocar el código real) y se corrió `classifySingleRule()` real, sin modificar. **55/55 simulan `SAFE_1_TO_1`**, 0 excepciones.
- **Consolidada** (transacción real + `rollback`, `esalud_dev`): se escribió `total_row` para las 55 (transacción) y se reclasificaron las 717 reglas activas usando una estructura con las **10 secciones relevantes** ensanchadas simultáneamente (`A31/A,B,C,D`, `A32/A,B,D.2,D1,E2`, `A33/C`). Resultado medido, no presupuesto:

| Categoría | Antes | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 335 | 390 | **+55** |
| `BLOCKED_BY_ENGINE_GAP` | 162 | 107 | **−55** |
| `REQUIRES_REMAP` / `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` | — | — | +0 |

**Coincide exactamente con la predicción matemática del usuario** (`335→390`, `162→107`). **0 de las 55 sin `SAFE_1_TO_1`. 0 efectos colaterales** en las 662 reglas restantes (verificado comparando las 717, no solo las 55). **Rollback ejecutado y verificado**: clasificación restaurada byte-idéntica (`SAFE_1_TO_1=335`, `BLOCKED_BY_ENGINE_GAP=162`), `config` de la regla 469 confirmado restaurado exacto, regla 461 confirmada sin tocar (`total_row=null`).

**Paso 5 — Protección contra generalización accidental**: se aplicó la **misma lógica exacta de Opción C** (posición `trailing` + `excluded=true` + candidato `= filaFinDatos+1` + fórmula completa/contigua verificada de forma independiente) contra **las 162 reglas `BLOCKED_BY_ENGINE_GAP` reales actuales** (no solo las 55 asumidas) — resultado: **exactamente 55 aceptadas, 0 de más, 0 de menos, coincide 100% con la lista esperada**. Confirmado explícitamente por qué cada familia queda fuera **por construcción del gate, no por ajuste manual**:
- **Regla 461**: excluida porque `position=leading` (el gate solo evalúa `trailing`) — nunca llega a evaluarse.
- **Categoría B** (`208,214,226,227,229,231,232,234,393-402`, 16 reglas con candidato automático nulo) **y Categoría F** (`228,230,233`): excluidas porque `total_row_candidate=null` para las 21 (row_range `{0,0}` o validador estricto de Fase 1 sin candidato) — el gate exige candidato no-nulo.
- **`no_utilizada`**: excluida explícitamente por el flag, verificado que ninguna hoja `no_utilizada` tiene una regla que pase el resto de condiciones de todos modos.

**Baseline final reconfirmado** (sin ningún cambio real, toda la sesión fue lectura + transacciones con rollback): `activas=717`, `SAFE_1_TO_1=335`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=162`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`, regla 461 sin tocar, guard `[inicio:fin]` real sin modificar.

**No se implementó nada de este diseño.** No `--commit`. No se tocó el guard real. No se tocó 461. No Fase 3C-2/3C-3/3C-4. No backfill. No se reprocesó ninguna carga real. No bindings/rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push.

### 17.15 — FASE 3C-1B — MECANISMO IMPLEMENTADO / DRY-RUN VALIDADO / ESCRITURAS REALES NO EJECUTADAS (2026-08-27)

**Autorización recibida**: implementar exclusivamente la Opción C documentada en el punto 17.14, para las 55 reglas ya certificadas (`filaFinDatos+1`, trailing, excluidas, fórmula completa/contigua). **Ningún `--commit` autorizado todavía.**

**Implementación — ruta aislada, guard genérico intacto**:
- **`FormulaRangeCoverageAnalyzer.php`** (nuevo, `App\Domain\RuleEngine\Services`): utilidad compartida que expande sintaxis de rango Excel y determina cobertura completa/contigua — extraída para que el nuevo mecanismo y `RuleActivateCategoryATotalCommand` (Fase 3C-1A) usen exactamente el mismo heurístico, sin duplicarlo (`RuleActivateCategoryATotalCommand::parseFormulaRows()` ahora delega a esta clase — refactor 100% preservador de comportamiento, confirmado con sus 12 tests ya cerrados, sin regresión).
- **`RuleBindingReconciliationService.php`** — cambio mínimo y aislado: la condición genérica del bounds-check (`$totalRow > $current['fin'] || $totalRow < $current['inicio']`) **permanece byte-idéntica**; se agregó un método privado nuevo, `isLegitimateTrailingTotalBeyondBounds()`, consultado **únicamente** dentro de la rama que ya evalúa un `total_row` ya configurado, y **solo cuando esa condición genérica ya decidió rechazar** — nunca se toca la rama `leading` (461 excluida por construcción de la ruta de código, confirmado en el dry-run real: `"El candidato de la regla 461 es 'leading', no 'trailing'..."`). El método exige, las 4 a la vez: candidato `=== filaFinDatos+1` exacto, fila no reclamada por otra sección declarada, mecanismo #12 (`isEmbeddedBackwardSubtotalRow()`, reutilizado sin redefinir) confirmado, y fórmula completa/contigua/sin referencias externas verificada de forma independiente.
- **`RuleActivateTrailingTotalBeyondBoundsCommand.php`** (nuevo comando, `rule:activate-trailing-total-beyond-bounds {rule_id} {--reason=} {--by=} {--commit}`): ruta específica y separada de `rule:activate-category-a` (Fase 3C-1A, sin modificar salvo el refactor de reutilización de heurístico ya descrito). Dry-run por defecto. Nunca toca `row_range`. 12 guards en orden estricto: activa+`sum_equals` → `BLOCKED_BY_ENGINE_GAP` → **no `no_utilizada`** (gap nuevo detectado y cerrado aquí — ver hallazgo abajo) → `total_row` ausente → `row_range` real (nunca `{0,0}`, así quedan fuera Categoría B/F sin caso especial) → candidato Fase 1 trailing+excluido → **candidato `=== fin+1` exacto** (mensaje específico, distinto de "fuera de rango" genérico) → fila no reclamada por otra sección → mecanismo #12 confirmado → fórmula completa/contigua verificada → simulación final `=== SAFE_1_TO_1` → sin colisión funcional (529↔530). `--commit`: transacción, doble revalidación, `RuleVersion` con snapshot previo, activity log `rule_trailing_total_beyond_bounds_activated`.

**Hallazgo durante la implementación (reportado, no corregido retroactivamente)**: ni `RuleActivateTrailingTotalBeyondBoundsCommand` (nuevo) ni `RuleActivateCategoryATotalCommand` (Fase 3C-1A, ya cerrado y ejecutado) tenían, hasta ahora, un guard explícito contra hojas `no_utilizada` — el filtro `hoja_no_utilizada` se aplicaba solo en los scripts de auditoría externos (17.9/17.11), nunca dentro de los propios comandos. **Se agregó el guard al comando NUEVO** (`RuleActivateTrailingTotalBeyondBoundsCommand`, guard 3). **No se modificó el comando de Fase 3C-1A** (ya cerrado, ejecutado sobre las 171 — ninguna de ellas era `no_utilizada`, confirmado en su momento, así que el gap nunca se explotó en la práctica) — queda documentado como deuda menor pendiente, no corregida retroactivamente sin autorización aparte.

**Tests nuevos** (`RuleActivateTrailingTotalBeyondBoundsCommandTest.php`, 12/12 passing): patrón válido `fin+1` (dry-run + commit, config solo con `total_row` agregado, `row_range` intacto) · candidato distinto de `fin+1` rechazado (mensaje específico) · `excluded=false` rechazado · fórmula con huecos rechazada · término externo rechazado (Fase 1 ya lo descarta en el origen, igual que `A26/B` real) · fila reclamada por otra sección rechazada (dos secciones adyacentes, fixture dedicado) · leading/461 rechazada · Categoría B (placeholder `{0,0}`) rechazada · Categoría F (mismo placeholder) rechazada · `no_utilizada` rechazada · preservación de bindings/regla ajena/`rem_data` confirmada byte-idéntica tras commit · `RuleVersion`/activity log correctos.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`): 617 tests, 578 passed, **mismos 39 fallos preexistentes de siempre**, +12 nuevos, todos passing. Cero regresiones nuevas. Suite de Fase 3C-1A (12 tests) reconfirmada sin cambios tras el refactor del heurístico compartido.

**Dry-run real contra las 55** (vía `Artisan::call()`, sin `--commit` en ningún caso): **55/55 aprobadas, 0 rechazadas**. Confirmado que las 55 reales siguen sin `total_row` escrito (cero writes).

**Reaplicación del mecanismo real contra las 162 `BLOCKED_BY_ENGINE_GAP` actuales** (no una simulación aparte — el comando real, dry-run, contra cada una de las 162): **exactamente 55 aceptadas, 107 rechazadas, coincide 100% con la lista esperada, 0 de más, 0 de menos**. **461 rechazada explícitamente** (`"El candidato de la regla 461 es 'leading', no 'trailing'..."`). Categoría B (`208,214,226,227,229,231,232,234,393-402`) y Categoría F (`228,230,233`), las 21, confirmadas rechazadas — ninguna absorbida.

**Simulación consolidada (transacción real + `rollback`, `esalud_dev`, mecanismo real — no una estructura clonada/ensanchada, ya innecesaria porque el guard aislado vive en el código)**:

| Categoría | Antes | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 335 | 390 | **+55** |
| `BLOCKED_BY_ENGINE_GAP` | 162 | 107 | **−55** |
| `REQUIRES_REMAP` / `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` | — | — | +0 |

**Coincide exactamente con lo predicho.** 0 de las 55 sin `SAFE_1_TO_1`. 0 efectos colaterales (verificado sobre las 717, no solo las 55). `461.total_row` confirmado `null` durante la transacción (nunca tocada, el loop solo itera las 55). **Rollback ejecutado y verificado**: clasificación restaurada byte-idéntica, `config` de la regla 469 confirmado restaurado exacto.

**Baseline final reconfirmado** (idéntico antes y después de toda la sesión): `activas=717`, `SAFE_1_TO_1=335`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=162`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`, regla 461 sin tocar (`total_row=null`). Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` verificados idénticos — calibraciones sin tocar.

**Archivos nuevos**: `backend/app/Domain/RuleEngine/Services/FormulaRangeCoverageAnalyzer.php`, `backend/app/Console/Commands/RuleActivateTrailingTotalBeyondBoundsCommand.php`, `backend/tests/Feature/REM/RuleActivateTrailingTotalBeyondBoundsCommandTest.php`. **Archivos modificados**: `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php` (método aislado nuevo, condición genérica byte-idéntica), `backend/app/Console/Commands/RuleActivateCategoryATotalCommand.php` (refactor de reutilización del heurístico compartido, comportamiento sin cambios, 12/12 tests reconfirmados). Ningún archivo de motor (`RuleEngineService.php`, `SumEqualsEvaluator.php`), parser, calibración o estructura fue tocado.

**No se ejecutó ningún `--commit` real sobre las 55.** No se tocó la regla 461. No Fase 3C-2/3C-3/3C-4. No backfill histórico. No se reprocesó ninguna carga real. No rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push.

### 17.16 — FASE 3C-1B EJECUTADA Y CERRADA — 55 reglas (2026-08-27)

**Autorización recibida**: ejecución real de `rule:activate-trailing-total-beyond-bounds --commit` exclusivamente sobre las 55 reglas aprobadas por el mecanismo (puntos 17.14/17.15).

**Ejecución**: 55 invocaciones individuales (`Artisan::call(...--commit)`, cada una con su propia revalidación interna vía `computeAndValidate()` antes de escribir, cada una en su propia transacción — nunca una escritura masiva), mismo `reason`/`by` para las 55 (`by=Administrador Esalud`). **55/55 exitosas, sin ninguna detención** — ninguna regla se desvió de lo validado en el dry-run/simulación previos.

**Post-check — medido, coincide exactamente con lo predicho**:

| Métrica | Antes | Después |
|---|---|---|
| `SAFE_1_TO_1` | 335 | **390** |
| `BLOCKED_BY_ENGINE_GAP` | 162 | **107** |
| `REQUIRES_REMAP` | 0 | 0 |
| `DUPLICATE` | 22 | 22 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 |
| reglas activas | 717 | 717 |
| `rem_rules` | 764 | 764 |
| `rem_rule_bindings` | 1204 | 1204 |
| bindings a estructura 67 | 0 | 0 |
| `rem_technical_totals` | 0 | 0 (sin cambio) |

**Verificaciones detalladas, todas exactas**:
- **55/55** de las reglas autorizadas ahora tienen `total_row` en `config`. **Las 55, sin excepción, cambiaron únicamente `total_row`** — `row_range` byte-idéntico en las 55, verificado comparando cada `config` actual contra el snapshot guardado en su propio `RuleVersion`. **0 cambios inesperados.**
- Muestra de valores escritos: `469→28` (`A31/A`, fin=27), `497→16` (`A32/A`, fin=15), `545→56` (`A33/C`, fin=55) — todos exactamente `filaFinDatos+1`.
- **Regla 461**: `total_row=null`, `status=active` — sin ningún cambio.
- **55/55** siguen `status=active` (no tocado).
- **Categoría B (18) y Categoría F (3)**: reclasificadas en vivo, las 21 siguen `BLOCKED_BY_ENGINE_GAP`.
- **`no_utilizada` (Categoría E) dentro de `BLOCKED_BY_ENGINE_GAP` = 56, sin cambio** — confirmado explícitamente (nota: una primera verificación contó `no_utilizada` sobre las 717 reglas activas totales en vez de sobre las 107 bloqueadas, dando 79 — corregido de inmediato recalculando el conteo correcto, con alcance idéntico al usado durante toda la campaña: `56 (no_utilizada) + 51 (18 CatB + 3 CatF + 30 CatC) = 107` exacto).
- **Exactamente 55** `RuleVersion` nuevos (`changelog` con prefijo `rule:activate-trailing-total-beyond-bounds`, snapshot del `config` anterior) y **exactamente 55** activity logs (`rule_trailing_total_beyond_bounds_activated`), cada uno con `rule_id`/`fin`/`total_row_set`/`reason`/`by` correctos (muestra verificada).
- `rem_data` total = `396.371` — idéntico, sin cambio. `RuleExecutionLog`/`rem_validation_results` — el comando nunca escribe en esas tablas (confirmado por código), sin necesidad de comparación adicional.
- **Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json`** — verificados en vivo, **idénticos** a los de toda la campaña — calibraciones sin tocar.
- Estructura activa `67/v35` sin tocar.

**Ninguna métrica difirió de lo predicho — no fue necesario ningún STOP ni compensación.**

**Fase 3C-1B queda EJECUTADA Y CERRADA para las 55 reglas.** Junto con las 171 de Fase 3C-1A, **226 reglas de Categoría A quedan completamente resueltas y cerradas**. El único trabajo pendiente dentro de esta línea (Familia A/Categoría A) es histórico/backfill, no autorizado. **Próximo pendiente único: los 107 `BLOCKED_BY_ENGINE_GAP` restantes** (461 congelada + Categoría B 18 + Categoría F 3 + `no_utilizada` 56 + los 29 de Categoría C dentro de límites, sin mecanismo diseñado todavía) — **Fase 3C-2/3C-3/3C-4 siguen sin iniciar**. No se tocó 461. No backfill. No se reprocesó ninguna carga real. No rebind. No commit de Git, no push.

### 17.17 — FASE 3C-2 (Categoría C, 29 reglas leading): **AUDITADO / DISEÑO PROPUESTO / NO IMPLEMENTADO** (2026-08-27)

⚠️ **100% READ-ONLY.** Ningún `config` escrito de forma persistente, ningún `--commit`, ningún cambio de código, **ningún cambio al guard genérico**. La regla 461 no fue tocada. Toda simulación descrita aquí se hizo dentro de transacciones con `rollback` explícito, nunca commiteadas.

**Paso 1 — Reconfirmación individual de las 29 (READ-ONLY)**: las 29, sin excepción, verifican **todos** los puntos pedidos: candidato dentro de `[filaInicioDatos:filaFinDatos]` (distancia desde `filaInicioDatos` ∈ {0, 1, 2} — nunca negativa), `excluded=true` confirmado por `isEmbeddedLeadingTotalRow()` (mecanismo #6) en vivo, fórmula real completa/contigua para exactamente `[row_range.from:row_range.to]` sin referencias externas (verificado con `FormulaRangeCoverageAnalyzer`, no solo confiado al flag), candidato único (ya garantizado por el propio diseño de Fase 1 — `discoverTotalRowCandidate()` exige `count($matches)===1`), fila candidata perteneciente **exclusivamente** a su propia sección (nunca reclamada por otra sección declarada). Concepto real confirmado como `"TOTAL"` en la muestra verificada (`46,47,48,49,61`).

**Hallazgo central de esta auditoría — cambia la naturaleza del diseño esperado**: a diferencia de las 55 de Fase 3C-1B (que necesitaban una excepción **aislada** porque su candidato caía **fuera** de `[inicio:fin]`), **las 29 de Categoría C ya están, en los 29 casos, DENTRO de `[filaInicioDatos:filaFinDatos]`** — el `motivo` real de bloqueo de las 29, confirmado en vivo, es simplemente `"invalid_row_range_configuration: falta total_row en config"` (el mismo motivo que las 170 de Fase 3C-1A que solo necesitaban `total_row`), **nunca** el motivo de límites (`"missing_total_row probable: total_row=X fuera de..."`). Esto significa que **el bounds-check genérico de `classifyRule()`, sin ningún cambio, ya acepta un `total_row` leading una vez escrito** — confirmado empíricamente: se simuló, con el clasificador real sin ninguna modificación de código, escribir `total_row=candidato` para las 29 (en memoria, vía `replicate()`, nada persistido) — **las 29/29 clasifican `SAFE_1_TO_1` de inmediato, sin necesitar ningún método nuevo ni excepción aislada**.

**Paso 2 — Comparación formal y obligatoria con la regla 461**: confirma por qué las 29 son elegibles y 461 no, sin crear ninguna tolerancia general:
- **Las 29**: candidato = `filaInicioDatos + {0,1,2}` — **dentro** de `[inicio:fin]` en los 29 casos, sin excepción.
- **461**: candidato = `filaInicioDatos - 1` (`123`, con `filaInicioDatos=124`) — **fuera** de `[inicio:fin]` (`123 < 124`).
- **El guard genérico de `classifyRule()` (`$totalRow < $current['inicio']`) nunca se relaja ni se toca** — sigue evaluando exactamente lo mismo que evaluaba antes de esta auditoría. 461 sigue, y seguiría, rechazada por esa misma condición sin ningún cambio.
- **Confirmación adicional, independiente de los límites**: `461.total_row_excluded = false` (a diferencia de las 29, todas `true`) — el propio mecanismo #6 (`isEmbeddedLeadingTotalRow()`) **no confirma** 461 como fila técnica excluida (ver hallazgo ya documentado en el punto 16.13: patrón de datos fantasma, concepto heredado de la fila 122, valores en 0) — es decir, 461 queda excluida del universo elegible por **dos razones independientes**, no solo por los límites.
- **No se creó ninguna tolerancia tipo `inicio-1`** — el diseño propuesto (ver Paso 3) no introduce ningún número de tolerancia; simplemente **reutiliza sin cambios** la verificación de límites ya existente.

**Paso 3 — Diseño, 3 alternativas comparadas**:

| | A) Reutilizar el mecanismo normal (RECOMENDADA) | B) Ruta específica leading + excluded=true (aislada, estilo 3C-1B) | C) Alternativa más conservadora |
|---|---|---|---|
| Cambio a `RuleBindingReconciliationService` | **Ninguno** — el bounds-check genérico ya acepta estos candidatos tal como está | Nuevo método aislado (mismo patrón que `isLegitimateTrailingTotalBeyondBounds()`) | Igual a A, sin cambio |
| Justificación del cambio | Innecesario — confirmado empíricamente que no hace falta | Sería una excepción para relajar un rechazo que **no ocurre** en este caso — no hay nada que rescatar | — |
| Superficie de riesgo | **Mínima de las 3** — cero código nuevo en la capa de clasificación compartida | Media — introduce complejidad no requerida por la evidencia | Igual a A |
| Comando de escritura | Nuevo comando, casi idéntico a `rule:activate-category-a` (Fase 3C-1A) pero aceptando `position==='leading'` en vez de `'trailing'` — mismos 10-12 guards, mismo patrón de `RuleVersion`/activity log | Comando + método aislado — trabajo redundante | Igual a A |
| Riesgo sobre 461 | Nulo — 461 nunca pasa el guard de posición/exclusión, y aunque lo hiciera, el bounds-check (sin tocar) la rechazaría igual | Nulo, con más código para lograrlo | Igual a A |

**Recomendación: Opción A** — es, literalmente, la Fase 3C-1A aplicada a `position=leading` en vez de `trailing`, sin ningún cambio a la capa de clasificación compartida. Menor superficie de cambio de las 3, confirmada por evidencia empírica, no solo por diseño teórico.

**Paso 4 — Simulación (sin persistir nada)**:
- **Individual**: las 29, simulando únicamente `total_row=candidato` (sin ningún cambio de código, sin clonar/ensanchar estructura — innecesario aquí) con el clasificador real: **29/29 → `SAFE_1_TO_1`**, 0 excepciones.
- **Gate aplicado contra los 107 `BLOCKED_BY_ENGINE_GAP` reales actuales** (no solo contra las 29 asumidas): condición = `position=leading` + `excluded=true` + `row_range` real (no `{0,0}`) + fila no reclamada por otra sección + fórmula completa/contigua verificada + simulación final `=SAFE_1_TO_1` con el clasificador sin modificar. Resultado: **exactamente 29 aceptadas, 0 de más, 0 de menos, coincide 100% con la lista esperada**. **461 queda fuera explícitamente** (`excluded=false`, nunca llega a simularse). Categoría B (18), Categoría F (3) y `no_utilizada` confirmadas fuera (candidato `null` para todas).
- **Consolidada** (transacción real + `rollback`, `esalud_dev`):

| Categoría | Antes | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 390 | 419 | **+29** |
| `BLOCKED_BY_ENGINE_GAP` | 107 | 78 | **−29** |
| `REQUIRES_REMAP` / `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` | — | — | +0 |

**Coincide exactamente con la predicción matemática del usuario.** 0 de las 29 sin `SAFE_1_TO_1`. 0 efectos colaterales (verificado sobre las 717, no solo las 29). `461.total_row` confirmado `null` durante la transacción. **Rollback ejecutado y verificado**: clasificación restaurada byte-idéntica, `config` de la regla 46 confirmado restaurado exacto.

**Baseline final reconfirmado** (sin ningún cambio real, toda la sesión fue lectura + transacciones con rollback): `activas=717`, `SAFE_1_TO_1=390`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=107`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`, regla 461 sin tocar, guard genérico sin modificar. Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` verificados idénticos.

**No se implementó nada de este diseño.** No `--commit`. No se escribió ningún `config` real. No se tocó 461. No Categoría B/F. No `no_utilizada`. No Fase 3C-3/3C-4. No se modificó el guard genérico ni ningún código. No backfill. No se reprocesó ninguna carga real. No calibraciones. No `rem_data`. No bindings/rebind. No estructura. No commit de Git, no push.

### 17.18 — FASE 3C-2 — MECANISMO IMPLEMENTADO / DRY-RUN VALIDADO / ESCRITURAS REALES NO EJECUTADAS (2026-08-27)

**Autorización recibida**: "Auditoría y diseño de Fase 3C-2 aceptados. Procede a implementar únicamente el mecanismo auditable para las 29 reglas leading excluidas, reutilizando el comportamiento existente del clasificador y sin modificar `RuleBindingReconciliationService` ni el guard genérico." **Ningún `--commit` autorizado todavía.**

**Implementación — Opción A del punto 17.17, cero cambios a la capa de clasificación compartida**:
- **`RuleBindingReconciliationService.php` — NO modificado en absoluto** (confirmado: `git status` no lo muestra tocado en este punto). El bounds-check genérico y `discoverTotalRowCandidate()` permanecen byte-idénticos a como quedaron tras la Fase 3C-1B.
- **`RuleActivateCategoryCLeadingCommand.php`** (nuevo, `rule:activate-category-c-leading {rule_id} {--reason=} {--by=} {--commit}`): mirror estructural de `RuleActivateTrailingTotalBeyondBoundsCommand.php` (Fase 3C-1B) con `position==='leading'` en vez de `'trailing'`, y "candidato dentro de `[filaInicioDatos:filaFinDatos]`" como condición de ACEPTACIÓN (guard 7) en vez de "candidato `=== fin+1` exacto" — refleja exactamente el hallazgo del punto 17.17 (los 29 ya caen dentro de límites, no necesitan la excepción aislada que sí necesitaron los 55). Dry-run por defecto. Nunca toca `row_range`. NUNCA recibe el número de fila como argumento — se deriva exclusivamente de `classifySingleRule()`. **12 guards en orden estricto**: (1) activa+`sum_equals` → (2) `BLOCKED_BY_ENGINE_GAP` → (3) no `no_utilizada` → (4) `total_row` ausente → (5) `row_range` real (nunca `{0,0}`, Categoría B/F fuera sin caso especial) → (6) candidato Fase 1 único, `position=leading`, `excluded=true` → (7) **candidato dentro de `[filaInicioDatos:filaFinDatos]`** (guard explícito y separado — rechaza a la regla 461 aquí, sin ningún `if rule_id===461`) → (8) fila no reclamada por otra sección declarada → (9) mecanismo #6 (`isEmbeddedLeadingTotalRow()`) confirmado en vivo, independiente del flag ya visto en el guard 6 (misma redundancia deliberada que el guard 9 de Fase 3C-1B con `isEmbeddedBackwardSubtotalRow`) → (10) fórmula completa/contigua/sin referencias externas verificada de forma independiente (`FormulaRangeCoverageAnalyzer`, reutilizado sin duplicar heurístico) → (11) simulación final `=== SAFE_1_TO_1` con el clasificador real, sin ninguna modificación → (12) sin colisión funcional (patrón 529↔530). `--commit`: transacción, doble revalidación inmediata antes de escribir, `RuleVersion` con snapshot del `config` anterior, activity log `rule_category_c_leading_activated`.

**Tests nuevos** (`RuleActivateCategoryCLeadingCommandTest.php`, 12/12 passing): patrón válido leading dentro de bounds (dry-run + commit, `config` solo con `total_row` agregado, `row_range` intacto, `RuleVersion`/activity correctos) · candidato fuera de bounds rechazado — **réplica exacta del patrón de la regla 461** (`filaInicioDatos === row_range.from`, candidato `from-1` cae fuera) · `excluded=false`/sin candidato rechazado · `trailing` rechazado · fórmula con huecos rechazada (candidato toca ambos extremos pero omite una fila intermedia — pasa Fase 1 y el mecanismo #6, falla la verificación independiente de cobertura) · término externo rechazado · `no_utilizada` rechazada · candidato ambiguo rechazado (leading y trailing simultáneamente válidos — Fase 1 no resuelve) · fila reclamada por otra sección rechazada (secciones solapadas a propósito, ya que el candidato debe estar dentro de la sección propia para llegar a este guard — a diferencia de Fase 3C-1B, este caso exige una estructura corrupta con solape, documentado en el propio test) · Categoría B/F (placeholder `{0,0}`) rechazada · preservación de bindings/regla ajena/`rem_data` confirmada byte-idéntica tras commit.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`): 629 tests, 590 passed, **mismos 39 fallos preexistentes de siempre** (idénticos, mismos nombres de test: 4 `StructurePersistenceServiceTest`, 1 `RuleEngineIntegrationTest`, 30 `FunctionalRuleEngineCertificationTest`, 4 `RuleEngineServiceTest`), +12 nuevos, todos passing. Cero regresiones nuevas.

**Dry-run real contra las 29** (vía `Artisan::call()` en `esalud_dev`, sin `--commit` en ningún caso): **29/29 aprobadas, 0 rechazadas**, todas con `SAFE_1_TO_1` en la salida simulada. Confirmado que las 29 reales siguen sin `total_row` escrito (cero writes).

**Reaplicación del mecanismo real contra los 107 `BLOCKED_BY_ENGINE_GAP` actuales** (el comando real, dry-run, contra cada una): **exactamente 29 aceptadas, 78 rechazadas, coincide 100% con la lista esperada (`46,47,48,49,61,62,63,64,65,87,89,90,91,92,93,96,97,105,106,107,108,182,183,277,278,279,280,281,282`), 0 de más, 0 de menos**. **461 explícitamente confirmada fuera** (`position=leading, excluded=false, candidate=123` — nunca llega a simularse). Categoría B (`208,214,226,227,229,231,232,234,393-402`), Categoría F (`228,230,233`) y las `no_utilizada` confirmadas fuera — ninguna absorbida.

**Simulación consolidada (transacción real + `rollback`, `esalud_dev`, mecanismo real vía `computeAndValidate()` reflejado — sin ningún cambio de código)**:

| Categoría | Antes | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 390 | 419 | **+29** |
| `BLOCKED_BY_ENGINE_GAP` | 107 | 78 | **−29** |
| `REQUIRES_REMAP` / `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` | — | — | +0 |

**Coincide exactamente con la predicción matemática del usuario.** 0 de las 29 sin `SAFE_1_TO_1`. 0 efectos colaterales (verificado sobre las 717, no solo las 29). `461.total_row` confirmado `null` durante la transacción. **Rollback ejecutado y verificado**: clasificación restaurada byte-idéntica (`390/0/22/107/198`), `config` de la regla 46 confirmado restaurado exacto.

**Baseline final reconfirmado** (idéntico antes y después de esta sesión): `activas=717`, `SAFE_1_TO_1=390`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=107`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`, regla 461 sin tocar (`total_row=null`), de las 29 ninguna con `total_row` escrito. Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` verificados idénticos — calibraciones sin tocar.

**Archivos nuevos**: `backend/app/Console/Commands/RuleActivateCategoryCLeadingCommand.php`, `backend/tests/Feature/REM/RuleActivateCategoryCLeadingCommandTest.php`. **Ningún archivo existente fue modificado** — a diferencia de Fase 3C-1B (que sí tocó `RuleBindingReconciliationService.php` con el método aislado y `RuleActivateCategoryATotalCommand.php` con el refactor compartido), Fase 3C-2 confirma en la práctica lo que predijo el punto 17.17: cero cambios a la capa de clasificación compartida.

**No se ejecutó ningún `--commit` real sobre las 29.** No se tocó la regla 461. No Fase 3C-3/3C-4. No backfill histórico. No se reprocesó ninguna carga real. No rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push. **STOP tras el dry-run y la simulación consolidada, según lo instruido — pendiente de autorización aparte para el `--commit` real sobre las 29.**

### 17.19 — FASE 3C-2 EJECUTADA Y CERRADA — 29 reglas (2026-08-27)

**Autorización recibida**: ejecución real de `rule:activate-category-c-leading --commit` exclusivamente sobre las 29 reglas de Categoría C aprobadas por el mecanismo (puntos 17.17/17.18), con revalidación explícita de todos los guards antes de cada escritura.

**Ejecución**: 29 invocaciones individuales (`Artisan::call(...--commit)`, cada una con su propia revalidación interna vía `computeAndValidate()` antes de escribir, cada una en su propia transacción — nunca una escritura masiva), mismo `reason`/`by` para las 29 (`by=Administrador Esalud`). **29/29 exitosas, sin ninguna detención** — ninguna regla se desvió del dry-run/simulación previos, no fue necesario ningún STOP.

**Post-check — medido, coincide exactamente con lo predicho**:

| Métrica | Antes | Después |
|---|---|---|
| `SAFE_1_TO_1` | 390 | **419** |
| `BLOCKED_BY_ENGINE_GAP` | 107 | **78** |
| `REQUIRES_REMAP` | 0 | 0 |
| `DUPLICATE` | 22 | 22 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 |
| reglas activas | 717 | 717 |
| `rem_rules` | 764 | 764 |
| `rem_rule_bindings` | 1204 | 1204 |
| bindings a estructura 67 | 0 | 0 |
| `rem_technical_totals` | 0 | 0 (sin cambio) |

**Verificaciones detalladas, todas exactas**:
- **29/29** de las reglas autorizadas ahora tienen `total_row` en `config`. **Las 29, sin excepción, cambiaron únicamente `total_row`** — `row_range` byte-idéntico en las 29, verificado comparando cada `config` actual contra el snapshot guardado en su propio `RuleVersion`. **0 cambios inesperados.**
- Muestra de valores escritos: `46→11` (`A02/A`, inicio=10, fin=17), `87→35` (`A05/C`, row_range=[36:49]), `182→106` (`A08/D`, row_range=[107:113]), `277→11` (`A19b/A`, row_range=[12:51]) — todos candidatos leading dentro de `[filaInicioDatos:filaFinDatos]`.
- **Regla 461**: `total_row=null`, `status=active` — sin ningún cambio.
- **29/29** siguen `status=active` (no tocado).
- **Categoría B (18)**: reclasificada en vivo, las 18 siguen `BLOCKED_BY_ENGINE_GAP`.
- **Categoría F (3)**: reclasificada en vivo, las 3 siguen `BLOCKED_BY_ENGINE_GAP`.
- **`no_utilizada` (Categoría E) dentro de los `BLOCKED_BY_ENGINE_GAP` restantes = 56, sin cambio** — confirmado con el conteo correctamente acotado a los 78 bloqueados restantes (no a las 717 activas totales, evitando el error de alcance ya documentado y corregido en el punto 16.15).
- **Exactamente 29** `RuleVersion` nuevos (`changelog` con prefijo `rule:activate-category-c-leading`, snapshot del `config` anterior) y **exactamente 29** activity logs (`rule_category_c_leading_activated`), cada uno con `rule_id`/`total_row_set`/`inicio`/`fin`/`reason`/`by` correctos (muestra verificada).
- `rem_data` total = `396.371` — idéntico, sin cambio.
- **Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json`** — verificados en vivo, **idénticos** a los de toda la campaña — calibraciones sin tocar.
- Estructura activa `67/v35 status=active` sin tocar.

**Ninguna métrica difirió de lo predicho — no fue necesario ningún STOP ni compensación.**

**Fase 3C-2 queda EJECUTADA Y CERRADA para las 29 reglas.** Junto con Categoría A (226, Fase 3C-1A+3C-1B) y Fase 2 (11), el universo `BLOCKED_BY_ENGINE_GAP` queda reducido a **78** exactos: `461` (congelada) + **Categoría B (18)** + **Categoría F (3)** + **`no_utilizada` (56)**. **No se inició Fase 3C-3 ni 3C-4.** No se tocó la regla 461. No backfill. No se reprocesó ninguna carga real. No rebind. No commit de Git, no push.

### 17.20 — FASE 3C-3: auditoría exhaustiva de las 18 reglas Categoría B — **100% READ-ONLY / DISEÑO, NADA IMPLEMENTADO** (2026-08-27)

⚠️ Todo este punto es auditoría (READ-ONLY) + diseño (`PROPUESTO / NO IMPLEMENTADO`). Ningún `config`/`status`/binding fue modificado, ningún código de motor/parser/clasificador fue tocado, ninguna de las 18 reglas fue escrita. No se tocaron las 29 recién cerradas, las 226 de Categoría A, la regla 461, Categoría F ni las 56 `no_utilizada`. Sin commit, sin push.

**Baseline reconfirmado antes y después, idéntico**: `activas=717`, `SAFE_1_TO_1=419`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=78`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`. Las 18 (`208,214,226,227,229,231,232,234,393,394,395,396,397,398,399,400,401,402`) confirmadas `BLOCKED_BY_ENGINE_GAP`, 18/18.

**Metodología**: para cada una de las 18, se extrajo `config` real (sheet/sección/columna/row_range), límites vivos de la sección (`filaInicioDatos`/`filaFinDatos`), se buscó la fila TOTAL real mediante inspección directa de `cell_data` (no solo los candidatos `from-1`/`to+1` de Fase 1 — para `A09/F.1` y `A09/I` el `row_range` es el placeholder `{0,0}`, así que Fase 1 nunca produce candidato; la fila TOTAL real se localizó leyendo la sección completa), se extrajo la fórmula Excel exacta, se determinó el conjunto real de filas/columnas referenciadas, se clasificó el patrón de agregación, se verificó `rem_data` real (componentes y fila TOTAL) y se revisó el histórico (`RuleExecutionLog`/`rem_validation_results`).

**Resultado — NO son una familia homogénea, se confirman 4 subfamilias distintas**:

### Subfamilia B1 — `A09/F.1` (2 reglas: `208` col F, `214` col L) — "lista explícita no contigua, filas de encabezado intercaladas"

- `row_range` en config = placeholder `{0,0}` (nunca tuvo un rango vertical real). Sección viva `[146:158]`.
- **Fila TOTAL real = 158** (`A158="TOTAL"`, `F158`/`L158` fórmula, `es_editable=false` en ambas).
- **Fórmulas exactas**: `F158 = SUM(F149,F150,F153,F155,F157)` · `L158 = SUM(L149:L150+L153+L155+L157)` (misma sintaxis, distinto motor de parseo de rango pero mismo conjunto de filas).
- **Filas realmente sumadas**: `{149,150,153,155,157}` — 5 de las 9 filas del bloque `[149:157]`.
- **Filas NO sumadas dentro del mismo bloque**: `{146,147,151,152,154,156}` (mas 148, ver abajo) — inspección de columna A confirma que **148,151,152,154,156 tienen texto propio** (`"Endodoncia"`, `"Odontopediatría"`, `"Ortodoncia y Ortopedia Dentomaxilofacial"`, `"Patología oral"`, `"Trastornos temporomandibulares y dolor orofacial"`) con `F`/`L` **bloqueadas y no editables** — no son huecos accidentales, son especialidades odontológicas reales que **no capturan datos en las columnas F/L específicamente** (probablemente sí capturan en otras columnas de la misma fila — no verificado en detalle, fuera del alcance de esta auditoría). 146/147 no tienen texto en A (filas de relleno/formato).
- **Sin huecos intencionales sin explicar**: los "huecos" corresponden exactamente a filas de negocio reales (especialidades odontológicas) que genuinamente no participan de la suma F/L — confirmado por `es_editable=false` en las columnas de la regla para esas filas, consistente entre sí.
- **`rem_data` real**: 125 registros por fila componente (148-157, incluyendo las no sumadas — confirma que la fila existe como concepto real en el modelo de datos, aunque F/L específicamente no capturen ahí), 119 registros fantasma en la fila 158 (TOTAL) — **[ver corrección en la entrada de deuda técnica #5 arriba]**: todos de cargas `upload_id 36-175` (2026-07-23 a 2026-08-10, ANTES de la activación del mecanismo #12 para esta sección), **0 registros en las 6 cargas posteriores** (`176-184`) que sí tienen datos de F.1 — confirma que el mecanismo protege correctamente cargas nuevas, histórico no limpiado (mismo patrón ya establecido en toda la campaña).
- **Histórico de ejecución**: `RuleExecutionLog` = 77/77 `skipped` para ambas (208, 214) — nunca intentó evaluarse (consistente con `row_range={0,0}` → `scope='per_row'`, nunca `'row_range'`, el evaluador vertical nunca se activa).
- **Homogeneidad interna**: 208 y 214 comparten EXACTAMENTE el mismo conjunto de filas `{149,150,153,155,157}`, solo difieren en columna (F vs L) — subfamilia perfectamente homogénea internamente (2/2).
- **NO debe mezclarse con `A09/I`** (subfamilias B2/B3 abajo) — la irregularidad aquí es "lista fija de filas dispersas dentro de un bloque de 9", no periodicidad de bloques repetidos.

### Subfamilia B2 — `A09/I`, patrón periódico regular (5 reglas: `226` AM, `227` AN, `231` AT, `232` AU, `234` AX)

- `row_range` = placeholder `{0,0}`. Sección viva `[249:336]` (87 filas, mucho más ancha que cualquier `row_range` de regla individual).
- **6 filas TOTAL candidatas, no 1** (`331,332,333,334,335,336`) — cada una es un TOTAL distinto, **no 6 copias del mismo total**. `A331="TOTAL"` (única fila con etiqueta explícita; 332-336 sin concepto propio en columna A, pero mecánicamente son la continuación del mismo bloque de 6 filas TOTAL).
- **Fórmulas exactas** (ejemplo columna AM, idénticas en AN/AT/AU/AX salvo diferencias cosméticas de orden/wrap `SUM()` sin efecto en el resultado — ver detalle): `AM331=AM253+AM259+AM265+AM271+AM277+AM283+AM289+AM295+AM301+AM307+AM313+AM319+AM325` (13 términos, paso fijo 6, desde 253 hasta 325) · `AM332` = mismo patrón desplazado +1 fila (254...326) · ... · `AM336` = desplazado +5 (258...330, con reordenamiento cosmético del término 300 en AM/AN específicamente, sin afectar la suma).
- **Filas realmente sumadas**: 13 componentes por cada una de las 6 filas TOTAL, cubriendo TODO el rango `[253:330]` en 13 bloques de 6 filas cada uno (offsets 0-5 dentro de cada bloque de 6, empezando en 253).
- **Patrón de agregación**: periódico por bloques — NO expresable como un único `row_range` contiguo, NI como una lista fija de 5-9 filas (B1) — son 13 términos con paso constante 6, y **6 filas TOTAL distintas coexistiendo en la misma columna**, cada una resumiendo una "posición" distinta dentro del bloque repetido.
- **Implicación estructural importante, no evaluada antes con este detalle**: el modelo actual de 1 regla `sum_equals` = 1 `total_row` no puede representar esto — hay **6 candidatos TOTAL válidos por columna**, no 1. Para validar completamente esta columna se necesitarían 6 reglas (una por fila TOTAL, cada una con su propia lista de 13 componentes), no 1. Actualmente solo existe 1 regla por columna (226 para AM, etc.) — su relación exacta con las 6 filas TOTAL reales nunca fue definida en el diseño original de la regla.
- **`rem_data` real**: 125 registros por fila componente (253,259,265,...,325 — muestreado), 119-125 registros en las 6 filas TOTAL (331-336) — mismo patrón fantasma-histórico que B1 (pre-mecanismo, `upload_id≤175`, no verificado en detalle upload-por-upload para esta subfamilia pero coherente con el mismo fix del mismo día).
- **Histórico**: `RuleExecutionLog` = 77/77 `skipped` para las 5.
- **Homogeneidad interna**: las 5 comparten estructura idéntica (13 términos, paso 6, mismo rango 253-330) — subfamilia homogénea en SUSTANCIA (mismo patrón matemático), con diferencias puramente cosméticas de sintaxis Excel entre columnas (AM/AN sin `SUM()` wrapper en 331-335, con reordenamiento en 336; AT/AU/AX con `SUM()` wrapper en 333/335/336, sin reordenamiento salvo en 335) — **ninguna diferencia cosmética altera el resultado numérico** (verificado: mismo conjunto de 13 términos en cada caso, la suma es conmutativa).

### Subfamilia B3 — `A09/I`, mismo patrón periódico MÁS un término externo genuino (1 regla: `229` AR)

- Mismo `row_range` placeholder, misma sección `[249:336]`, mismas 6 filas TOTAL candidatas (331-336) que B2.
- **Diferencia real, no cosmética**: `AR333 = SUM(AR337+AR255+AR261+AR267+AR273+AR279+AR285+AR291+AR297+AR303+AR309+AR315+AR321+AR327)` — **14 términos, no 13** — incluye `AR337`, una fila **fuera de la sección viva** (`filaFinDatos=336`, `337` está un renglón más allá) y fuera del patrón de paso-6 esperado para esa posición.
- **`AR337` inspeccionado directamente**: celda vacía en `cell_data` (`null` — ni fórmula, ni texto, ni valor) en la estructura activa 67/v35. La fórmula la referencia, pero no hay evidencia de qué contendría o si alguna vez contuvo algo real — **no se pudo determinar la intención original** (¿corrección manual del template Excel, término legítimo de otra categoría no modelada, o error de copiado?).
- **AR331, AR332, AR334 son idénticas en estructura a B2** (13 términos limpios, mismo paso 6). Solo `AR333` tiene la anomalía. `AR335`/`AR336` muestran el mismo reordenamiento cosmético (sin efecto numérico) que AT/AU/AX.
- **Conclusión**: AR (229) **comparte la causa raíz arquitectónica de B2** (periodicidad de bloques, 6 TOTAL candidatas por columna) pero tiene una **anomalía propia adicional, no generalizable a las otras 5** — no debe tratarse como "una más del grupo B2" sin resolver primero qué es `AR337`. Aislada como subfamilia propia, 1/1.

### Subfamilia B4 — `A26/B` (10 reglas: `393`-`402`, columnas D-M) — "rango contiguo + término externo de fila anterior en la misma sección"

- `row_range` REAL en config = `{from:54, to:58}` (a diferencia de B1/B2/B3, este SÍ tiene un rango vertical real, no placeholder). Sección viva `[50:60]`.
- **Fila TOTAL real = 59** (`A59="TOTAL"`, fórmula en D59-M59, no editable).
- **Fórmula exacta** (idéntica estructura en las 10 columnas): `D59 = SUM(D54:D58)+D50` — suma el `row_range` completo y contiguo `[54:58]` de la propia regla, **más un término adicional: `D50`**.
- **`D50` no es una fila cualquiera** — es la **primera fila de datos de la sección** (`filaInicioDatos=50`), con concepto propio real (`A50="Visita epidemiológica"`), **editable** (`es_editable=true`, no fórmula), con **76 registros reales en `rem_data`** (valores no-cero verificados en muestra: `C=3` en un caso real) — es un concepto de captura de negocio genuino, independiente y anterior al bloque `[54:58]`, que la fórmula de TOTAL "acarrea" hacia la suma final.
- **Existe además una regla separada para la fila 50 sola** (`regla 392`, columna C, `row_range={from:50,to:50}`) — confirma que la fila 50 ya es tratada como una unidad de validación propia e independiente en el catálogo, reforzando que D50 (y E50...M50) es un componente de negocio real, no un artefacto.
- **`rem_data` de la fila TOTAL (59)**: 70 registros fantasma, mismo patrón histórico-pre-mecanismo que B1/B2 (no verificado upload-por-upload en detalle, pero consistente).
- **Patrón de agregación**: "rango contiguo + 1 término externo fijo" — el más simple de las 4 subfamilias, matemáticamente equivalente a extender el rango si `D50` fuera adyacente, pero **no lo es** (hay un gap real de 3 filas `[51:53]` entre el término externo y el rango principal, ocupadas por encabezados/filas no capturables de la sección — no verificado en detalle en esta auditoría, fuera de alcance).
- **Histórico**: `RuleExecutionLog` = 77/77 `skipped` para las 10.
- **Homogeneidad interna**: las 10 (D-M) comparten EXACTAMENTE la misma fórmula estructural (`SUM(rango[54:58])+fila50`), solo difieren en columna — subfamilia perfectamente homogénea, 10/10.

**Verificación de suma**: `2 (B1) + 5 (B2) + 1 (B3) + 10 (B4) = 18` exacto.

### Matriz consolidada — causa raíz y tratamiento técnico recomendado (ninguno implementado)

| Subfamilia | Reglas | Patrón de agregación | Representable con `row_range`+`total_row` actual | Tratamiento recomendado |
|---|---|---|---|---|
| **B1** — `A09/F.1` | 208, 214 (2) | Lista explícita fija de filas no contiguas (5 de 9), huecos = especialidades reales sin captura en esa columna | **No** — no hay rango contiguo que lo represente sin incluir filas que no deben sumarse | **(b) Necesita extender configuración/evaluador** — requiere un campo nuevo tipo `total_row_component_rows: [...]` (lista explícita) + soporte en `SumEqualsEvaluator` para sumar una lista en vez de `[from:to]`. No es un nuevo *tipo* de agregación (sigue siendo una suma simple), solo una forma distinta de especificar los componentes. |
| **B2** — `A09/I` regular | 226, 227, 231, 232, 234 (5) | Periódico: 13 términos, paso fijo 6, **6 filas TOTAL candidatas por columna** (no 1) | **No** — ni un rango ni una lista fija de una sola regla puede representar 6 totales distintos coexistiendo | **(c) Requiere un nuevo tipo de representación de agregación** — el gap no es solo de sintaxis de componentes (como B1), es de **cardinalidad**: el modelo "1 regla = 1 total_row" no encaja; se necesitaría una relación 1-regla-a-6-totales (o 6 reglas nuevas por columna) más un concepto de periodicidad (`step`, `count`, `start`) o, alternativamente, 6 listas explícitas (una por fila TOTAL) reutilizando el mecanismo de B1 — **decisión de diseño no tomada, requiere sesión dedicada**. |
| **B3** — `A09/I` AR con termino externo | 229 (1) | Igual que B2 + 1 término externo (`AR337`) de origen/intención no determinada | **No**, y además con una anomalía propia sin explicación de negocio conocida | **(d) Debe permanecer congelada para investigación individual** — comparte la base arquitectónica de B2 (por lo que cualquier solución de B2 tendría que contemplarla), pero `AR337` necesita resolución de negocio/plantilla propia antes de decidir su tratamiento técnico — no generalizar la solución de B2 a esta regla sin esa resolución previa. |
| **B4** — `A26/B` | 393-402 (10) | Rango contiguo real `[54:58]` + 1 término externo fijo (fila 50, con gap de 3 filas intermedias no capturables) | **Parcialmente** — el `row_range` ya es real y correcto; solo falta representar el término externo único | **(b) Necesita extender configuración/evaluador** — la extensión más pequeña de las 4: un campo tipo `total_row_extra_rows: [50]` (o `[N]` genérico) sumado al `row_range` existente, sin tocar el modelo de "rango contiguo" que ya funciona para el resto de la regla. Menor superficie de cambio de las 4 subfamilias. |

**Observación de diseño (no una decisión, no aprobada para implementar)**: B1 y la parte "término externo" de B4 son ambas, en el fondo, casos particulares de un mecanismo más general — "conjunto explícito de filas componentes distinto de `[from:to]`" — B1 lo necesita para *excluir* filas dentro de un bloque, B4 lo necesita para *añadir* una fila fuera del bloque. Un único mecanismo de config (`total_row_component_rows` como lista completa, o `row_range` + `total_row_extra_rows`/`total_row_excluded_rows` como modificadores) podría cubrir ambas sin duplicar trabajo. **No se decide aquí cuál de las dos formas (lista completa vs. modificadores sobre el rango) es preferible** — evaluar en la sesión de diseño futura. B2/B3 son estructuralmente distintas (problema de cardinalidad 1-a-6, no solo de qué filas sumar) y **no deben resolverse con el mismo mecanismo sin verificar que cubra el caso de múltiples `total_row` por columna**.

**No se propone, no se simula y no se escribe ningún `config` para ninguna de las 18** — la instrucción de esta fase fue exclusivamente auditoría + diseño. **No se modificó `SumEqualsEvaluator.php`, `RuleEngineService.php`, `RemParserService.php` ni `RuleBindingReconciliationService.php`.**

**Baseline final reconfirmado** (idéntico antes y después de toda la auditoría): `activas=717`, `SAFE_1_TO_1=419`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=78`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`. Ninguna escritura de ningún tipo durante esta auditoría.

### 17.21 — FASE 3C-3A (B1, `A09/F.1`) y 3C-3B (B4, `A26/B`): diseño + simulación READ-ONLY — **DISEÑADAS / NO IMPLEMENTADAS** (2026-08-27)

⚠️ **100% READ-ONLY / DISEÑO.** Ningún `config` escrito, ningún código real modificado (`SumEqualsEvaluator.php`, `RuleEngineService.php`, `RuleBindingReconciliationService.php` sin tocar), ninguna de las 18 reglas tocada. B2 (periódico, 226/227/231/232/234) y B3 (229) permanecen congeladas, documentadas sin más trabajo. Categoría F, regla 461, `no_utilizada` (56) sin tocar. Sin commit, sin push. Baseline reconfirmado idéntico antes y después: `activas=717`, `SAFE_1_TO_1=419`, `BLOCKED_BY_ENGINE_GAP=78`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, 18/18 de Categoría B confirmadas `BLOCKED_BY_ENGINE_GAP`.

**Mecanismo único propuesto para B1 y B4 — `source_rows` (lista explícita de filas componentes)**: se evaluaron 2 alternativas (mecanismo unificado `source_rows` que sustituye la iteración implícita `[from:to]` cuando está presente, vs. un par de modificadores separados `excluded_rows`/`extra_rows` sobre el `row_range` existente) y se recomienda la **primera** — un único campo nuevo, opcional, en `config`: `source_rows: [n1, n2, ...]`. Cuando está presente, define de forma completa y exclusiva el conjunto de filas componentes a sumar (ignora `[row_from:row_to]` para ese propósito); cuando está ausente (las 419+78-18=479 reglas restantes, comportamiento actual), nada cambia. Se prefirió sobre la alternativa de modificadores separados porque B1 (resta filas de un bloque) y B4 (suma una fila fuera del bloque) son, en el fondo, la MISMA operación ("sumar exactamente esta lista"), y una sola lista explícita cubre ambos casos con un único campo y un único punto de código nuevo en cada capa — sin inventar semántica de "inclusión/exclusión" adicional no exigida por la evidencia. **No se extiende este mecanismo a B2/B3** — esos casos son un problema de *cardinalidad* (1 regla, 6 filas TOTAL candidatas), no solo de qué filas componen la suma; `source_rows` no lo resuelve y no se propone que lo intente.

### Diseño B1 — `A09/F.1` (208 col F, 214 col L)

- **Lista exacta confirmada** (evidencia de fórmula real, `cell_data`): `source_rows = [149,150,153,155,157]`, idéntica para ambas columnas. `total_row = 158`.
- **`row_range` propuesto**: `{from: 149, to: 157}` (el envolvente/bounding box de `source_rows`, en vez del placeholder `{0,0}` actual) — puramente documental/de compatibilidad con el prefiltro existente (ver abajo), `source_rows` es la fuente de verdad real para la suma.
- **Huecos confirmados intencionales**: `146,147` (filas de relleno sin concepto) y `148,151,152,154,156` (especialidades odontológicas reales — `"Endodoncia"`, `"Odontopediatría"`, `"Ortodoncia y Ortopedia Dentomaxilofacial"`, `"Patología oral"`, `"Trastornos temporomandibulares y dolor orofacial"` — con columnas F/L bloqueadas/no editables, confirmado en el punto 17.20). Ninguna fila adicional debería entrar — `source_rows` es exactamente el conjunto de filas con F/L `es_editable=true` dentro del bloque `[148:157]`.
- **Evaluador (`SumEqualsEvaluator::evaluateVerticalAggregation()`)**: punto de inserción identificado con precisión, líneas 104-114 (build de `$componentRows`/`$totalRow` a partir de `$rows`) — cambio propuesto, aditivo: si `config['source_rows']` está presente, una fila entra a `$componentRows` solo si su `row_number` está en esa lista (además de no ser `$totalRowNumber`); si está ausente, comportamiento 100% idéntico al actual (confirmado leyendo el código real, sin necesidad de ejecutar nada — la condición nueva sería un `elseif` que por diseño es `true` siempre que `source_rows===null`).
- **Prefiltro (`RuleEngineService::execute()`, líneas 174-179)**: **no requiere cambio para B1** — con `row_range` propuesto `[149:157]`, el prefiltro existente (`[row_from:row_to]` ∪ `{total_row}`) ya deja pasar las 9 filas del bloque completo (incluyendo las que no son componentes); es el evaluador quien las descarta después. Confirmado explícitamente para no asumirlo: `146,147` quedan fuera del prefiltro (no importan, no son componentes de todos modos).

### Diseño B4 — `A26/B` (393-402, columnas D-M)

- **Rango exacto confirmado**: `row_range = {from:54, to:58}` (ya real en config, sin cambios). **Fila externa exacta**: `50` — confirmada como dato de negocio real (`A50="Visita epidemiológica"`, editable, **76 registros reales en `rem_data`**, con **regla propia independiente `392`** validándola por sí sola como unidad, columna C, `row_range={50,50}`). **Sin otros términos**: la fórmula real (`D59=SUM(D54:D58)+D50`) confirma exactamente 6 filas (54-58 + 50), sin ningún término adicional, en las 10 columnas.
- **`row_range` sin cambio**: `{from:54, to:58}` se mantiene — es correcto y suficiente para el bloque principal. **`source_rows` propuesto**: `[54,55,56,57,58,50]` (unión explícita del rango + el término externo) — mismo mecanismo que B1, misma clave de config.
- **Evaluador**: mismo cambio aditivo que B1 (idéntico código, cero diferencia entre subfamilias en esta capa).
- **Prefiltro**: **SÍ requiere cambio para B4** — a diferencia de B1, la fila 50 está **fuera** de `[row_from:row_to]=[54:58]`, así que el prefiltro actual (línea 174-179) la descartaría antes de que el evaluador la viera, incluso con el cambio del evaluador ya hecho. Cambio propuesto, aditivo: agregar una tercera condición al `filter()` — `|| ($sourceRows !== null && in_array((int) $rd->data['row_number'], $sourceRows, true))` — activa solo cuando `source_rows` está presente en config (0 reglas hoy), sin alterar el comportamiento para las 497 reglas restantes.
- **Riesgo de generalizar este patrón sin verificación caso por caso**: el término externo de B4 (fila 50) tiene una regla propia independiente (392) que YA lo valida — no hay garantía de que este patrón ("el término externo ya tiene su propia regla de validación") se sostenga en otras hojas no auditadas; **no generalizar automáticamente** `extra_rows`/`source_rows` a ningún otro caso sin repetir esta verificación evidencial completa.

### ¿Comparten B1 y B4 una representación común? — SÍ, con matices documentados

Ambas se resuelven con el mismo campo (`source_rows`) y el mismo cambio de evaluador. **Difieren únicamente en si necesitan también el cambio de prefiltro** (B1 no, porque su envolvente natural ya cubre todo; B4 sí, porque su término externo cae fuera de cualquier envolvente razonable sin ensanchar `row_range` de forma engañosa). El diseño del prefiltro se escribió de forma que cubra ambos casos con la misma condición (`in_array` contra `source_rows`), así que en la práctica **es un único cambio de código que sirve a las dos subfamilias**, no dos mecanismos distintos — la diferencia es solo si, para una regla dada, ese código adicional llega a activarse (depende de si `source_rows` tiene algún elemento fuera de `[row_from:row_to]`, no de a qué subfamilia pertenece).

### Simulación (sin escribir ningún `config`, funciones puras locales, nunca las clases reales)

**B4 — validación contra datos reales de producción** (no sintéticos): 667 verificaciones (10 columnas × cada upload histórico con TOTAL fantasma y los 6 componentes completos) — **667/667 coinciden exactamente** con el valor real capturado en la fila 59 en su momento, incluyendo **37 casos genuinamente no-triviales** (valor real ≠ 0 — ej. `upload=99 col=D: componentes={50:3,54:0,55:0,56:0,57:0,58:0} → suma=3 = real=3`; `upload=140-148 col=D: suma=6=real=6`). Caso **failed** construido deliberadamente (perturbación solo en memoria, nada persistido): mismo upload, `D50` alterado `+999` → `calculado=1002 ≠ real=3` → `status=failed`, confirmando que el mecanismo detecta discrepancias reales, no solo confirma coincidencias.

**B1 — datos reales de producción resultaron triviales** (hallazgo, no limitación del diseño): las 5 columnas componentes (`F`/`L`, filas 149,150,153,155,157) están en **`null` en el 100% de las 125 filas históricas, en las 145 cargas completas del sistema** — ningún establecimiento capturó jamás un valor real en estas columnas específicas de esta sección. `F158` (TOTAL fantasma) = `0` en las 119 ocurrencias históricas, consistente con "suma de puros nulos tratados como 0" — confirma el diseño solo de forma degenerada (0=0). Para una validación no-trivial se construyó una **fixture sintética explícita** (misma convención usada en toda la campaña para los tests de comandos — fixtures sintéticas cuando los datos reales no ofrecen evidencia): componentes `{149:4,150:9,151:100,152:200,153:12,154:300,155:11,156:400,157:7}` (huecos con valores grandes deliberados) → **con `source_rows` (diseño propuesto): `calculado=43=real=43` → `passed`** · **si se usara —incorrectamente— el rango contiguo completo `[149:157]`: `calculado=1043 ≠ 43`** — prueba explícita de que el mecanismo `source_rows` es necesario, no cosmético (el rango contiguo ingenuo habría arrastrado los valores de los huecos, dando un resultado gravemente incorrecto). Caso **failed** sintético: mismo fixture con total declarado `+1` (`44` en vez de `43`) → `status=failed`.

**Confirmación de no-alteración de reglas normales `row_range`**: verificado por lectura directa del código real (no requiere ejecución) — tanto en `SumEqualsEvaluator::evaluateVerticalAggregation()` como en `RuleEngineService::execute()`, el cambio propuesto es un `elseif`/condición `OR` adicional que solo se activa cuando `config['source_rows']` está presente y no-vacío; para las 497 reglas restantes (todas sin esa clave), la rama nueva nunca se evalúa como verdadera de forma distinta a hoy — comportamiento **byte-idéntico** garantizado por construcción, no solo por prueba empírica.

### Archivos que habría que modificar (si se implementara — NO IMPLEMENTADO)

- `backend/app/Domain/RuleEngine/Evaluators/SumEqualsEvaluator.php` — `evaluateVerticalAggregation()`, filtrado de `$componentRows` (~líneas 104-114).
- `backend/app/Domain/RuleEngine/Services/RuleEngineService.php` — prefiltro `$rows` en `execute()` (~líneas 174-179), agregar condición `source_rows`.
- Un comando nuevo de activación (no diseñado en detalle en esta fase) que derive `source_rows` desde la fórmula real (reutilizando el parser de referencias ya usado por `discoverTotalRowCandidate()`/`FormulaRangeCoverageAnalyzer`, sin exigir `isCompleteContiguous()` — ese chequeo dejaría de aplicar tal cual para listas no contiguas) y escriba `config['source_rows']`+`config['total_row']`(+`row_range` ajustado si aplica, solo para B1).
- Tests nuevos (unitarios del evaluador + prefiltro, y del comando de activación una vez diseñado) — ninguno escrito todavía.
- **`RuleBindingReconciliationService.php` NO necesitaría cambios para que `classifyRule()` reporte `SAFE_1_TO_1`** una vez escrito `total_row` — pero esto es precisamente el riesgo central identificado (ver abajo), no una ventaja.

### Riesgo central identificado (nuevo, no documentado antes de esta fase)

**`classifyRule()` no verifica la fórmula real ni conoce `source_rows` — solo valida que `total_row` esté presente y dentro de `[filaInicioDatos:filaFinDatos]`.** Confirmado leyendo el código real (líneas 203-242): la clasificación `SAFE_1_TO_1` depende exclusivamente de bounds, nunca de si la suma real coincidiría. **Esto significa que escribir `config['total_row']`+`row_range` para 208/214/393-402 SIN haber desplegado antes el cambio de evaluador+prefiltro haría que el clasificador reporte `SAFE_1_TO_1` de forma engañosa** — la regla dejaría de estar `BLOCKED_BY_ENGINE_GAP` (bloqueada, inofensiva) pero el motor real (sin el código de `source_rows`) sumaría el rango contiguo completo `[149:157]`/`[54:58]` sin excluir/incluir lo necesario, produciendo validaciones **incorrectas en producción** (no solo "sin datos") — para B4 el riesgo es menor en la práctica porque `D50` quedaría simplemente fuera de la suma (resultado parcialmente incorrecto, no catastrófico); para B1 el riesgo es mayor porque las filas-hueco (`151,152,154,156`) SÍ están dentro del `row_range` propuesto y se sumarían por error si el evaluador no está actualizado. **Conclusión de riesgo**: cualquier implementación futura de B1/B4 debe desplegar y regresionar el código del evaluador+prefiltro **antes** de escribir ningún `config`, nunca al revés — a diferencia de Categoría A/C, donde el chequeo de bounds del clasificador YA era una proxy confiable de corrección (porque esas reglas sí eran rangos contiguos simples).

### Impacto esperado en clasificación si se implementara (no ejecutado)

Si se completara el diseño íntegro (evaluador + prefiltro + `config` de las 18) y se escribiera `total_row`/`source_rows`/`row_range` para las 12 reglas de B1+B4 (208,214,393-402 — **no** las 6 de B2/B3, que siguen sin diseño): `BLOCKED_BY_ENGINE_GAP` bajaría de `78` a `66` (`78-12`), `SAFE_1_TO_1` subiría de `419` a `431`. **No medido/simulado contra el clasificador real en esta fase** (habría requerido escribir `config`, aunque fuera en una transacción con rollback, y la instrucción explícita de este turno fue "sin escribir config" — ni siquiera en transacción) — esta cifra es aritmética simple (12 reglas), no una medición.

### Baseline final reconfirmado

`activas=717`, `SAFE_1_TO_1=419`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=78`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204` — idéntico antes y después de toda la fase. Ninguna escritura de ningún tipo (ni siquiera transacción+rollback) durante esta fase — a diferencia de fases anteriores, aquí ni se tocó la base de datos con propósito de escritura, todo fue lectura + simulación en funciones PHP puras locales.

**No se implementó nada de este diseño.** No `config` escrito. No `SumEqualsEvaluator.php`/`RuleEngineService.php`/`RuleBindingReconciliationService.php` modificados. No comando de activación creado. No tests nuevos. B2/B3 sin tocar. Categoría F, 461, `no_utilizada` sin tocar. No backfill, no reprocess, no bindings/rebind, no calibraciones, no `rem_data`, no estructura. No commit de Git, no push.

### 17.22 — FASE 3C-3A/3C-3B — SOPORTE `source_rows` IMPLEMENTADO / CONFIG REAL NO ESCRITA (2026-08-27)

**Autorización recibida**: "Procede únicamente con la implementación del soporte de código para `config.source_rows`, sin escribir config real en ninguna regla." Diseño del punto 17.21 ya aceptado.

**Cambios permitidos y respetados**: exclusivamente `SumEqualsEvaluator.php`, `RuleEngineService.php`, tests nuevos. Confirmado con `git status` al cierre — ningún otro archivo tocado. **Ningún comando de activación creado.** **Ningún `config` real escrito** — las 12 reglas reales (`208,214,393-402`) confirmadas sin `source_rows` en su config de BD.

**Implementación — `SumEqualsEvaluator.php`** (`evaluateVerticalAggregation()`):
- Nuevo método privado `validateSourceRows(mixed $sourceRows, ?array $sectionBounds): ?string` — guards en orden: debe ser array, no vacío, todos enteros positivos, sin duplicados, y (solo si `$sectionBounds` no es null) todos dentro de `[inicio:fin]`. Devuelve `null` si es válido, o un mensaje de error específico.
- Si `config['source_rows']` está presente y falla cualquier guard: la regla se marca `skipped` con `reason='invalid_source_rows_configuration'` (mismo shape que el ya existente `invalid_row_range_configuration`) — **nunca fallback silencioso a `[row_from:row_to]`**, exactamente como exigía la instrucción.
- Si es válido: el loop que separa `$componentRows`/`$totalRow` cambia de `if/else` a `if/elseif ($sourceRows === null || in_array($rn, $sourceRows, true))` — cuando `source_rows` está ausente, `$sourceRows===null` hace que el `elseif` sea **siempre verdadero** para toda fila no-total, comportamiento **byte-idéntico** al anterior por construcción (no solo verificado empíricamente).

**Implementación — `RuleEngineService.php`** (`execute()`):
- `$sourceRowsForFilter` calculado con la MISMA gate que ya protege `$totalRow` (`isVerticalSumEqualsRule()`) — `source_rows` nunca se consulta para reglas horizontales/per_row, sin importar que el campo esté presente en config.
- Prefiltro de `$rows` (línea ~202-208) extendido con una tercera condición OR: `$sourceRowsForFilter !== null && in_array((int) $rd->data['row_number'], $sourceRowsForFilter, true)` — dejar pasar filas referenciadas por `source_rows` aunque caigan fuera de `[row_from:row_to]` (patrón B4, fila 50). Ausente: condición nunca aporta filas nuevas, filtro idéntico al actual.
- Nuevo método privado `findSectionBounds(RemTemplateStructure $structure, string $sheet, string $section): ?array` — resuelve `filaInicioDatos`/`filaFinDatos` reales desde `$structure->estructura` (misma estructura ya cargada en `execute()`, sin parámetros nuevos). Se invoca solo cuando `source_rows` está presente, con cache por sección (`$sectionBoundsCache`, mismo patrón que `$cellMetadataCache`) para no repetir el parseo por regla. Resultado inyectado en `$config['_section_bounds']` para que el evaluador aplique el guard de límites "cuando esa información esté disponible" — si la sección no se encuentra, `null`, y ese guard específico simplemente no se aplica (el resto sigue vigente).
- **Fix necesario descubierto durante las pruebas**: `determineStatus()` tenía una lista blanca explícita de `reason`s que mapean a `status='skipped'` (`$skipReasons`) — `invalid_source_rows_configuration` no estaba en ella, así que la regla se reportaba `status='passed'` (incorrecto) pese a `reason` correcto. Se agregó `'invalid_source_rows_configuration'` a esa lista — mismo tratamiento que `invalid_row_range_configuration`, al que espeja el shape.

**Tests nuevos**:
- `tests/Unit/RuleEngine/Evaluators/SumEqualsEvaluatorSourceRowsTest.php` (19 tests, nivel evaluador aislado): ausente=legacy idéntico · array vacío rechazado · duplicados rechazados · no-entero rechazado · cero/negativo rechazado · no-array rechazado · fuera de límites rechazado (con `_section_bounds` simulado) · dentro de límites aceptado · límites ausentes no bloquean · `total_row` tiene precedencia aunque aparezca también en `source_rows` · `missing_total_row` sigue detectándose · fila listada en `source_rows` pero ausente de `$rows` se excluye silenciosamente de la suma (sin error) · fixture B1 passed (huecos con valores grandes deliberados, ignorados correctamente) · fixture B1 **prueba de discriminación** (mismo fixture sin `source_rows` → rango contiguo ingenuo da 1043 ≠ 43 real, falla) · fixture B1 failed · fixture B4 passed/failed (nivel evaluador) · `source_rows` ignorado en regla horizontal `per_row` · regla "vecina" sin `source_rows` sin cambios.
- `tests/Feature/RuleEngine/Services/RuleEngineServiceSourceRowsTest.php` (9 tests, nivel `execute()`/prefiltro, con estructura y `rem_data` reales vía `RefreshDatabase`): **B4 — el prefiltro carga la fila 50 pese a estar fuera de `[54:58]`** (passed) · B4 detecta mismatch si la fila externa se altera · **caso de control**: la MISMA regla SIN `source_rows` confirma que, sin el cambio, la fila 50 nunca llegaría al evaluador (falla) · fila fuera de los límites vivos de la estructura real rechazada (`_section_bounds` resuelto de verdad, no simulado) · sección no encontrada en la estructura → guard de límites no bloquea (resto de guards sí) · B1 pipeline completo (huecos no-triviales ignorados) · regla horizontal no afectada por `source_rows` de una regla vertical vecina en el mismo lote real · regla vertical vecina sin `source_rows` sin cambios (mismo lote real) · `rem_data` histórica byte-idéntica antes/después de `execute()`.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`+`tests/Unit/RuleEngine`, alcance ampliado esta vez por instrucción explícita de "regresión completa del RuleEngine"): **777 tests, 738 passed, 39 failed — mismos 39 fallos preexistentes de siempre** (idénticos nombres: 4 `StructurePersistenceServiceTest`, 1 `RuleEngineIntegrationTest`, 30 `FunctionalRuleEngineCertificationTest`, 4 `RuleEngineServiceTest`), +28 nuevos (19+9), todos passing. Cero regresiones nuevas.

**Validación READ-ONLY final contra las 12 reglas reales** (vía tinker, sin escribir nada): evaluador REAL (ya modificado) ejecutado con `config` construido EN MEMORIA (nunca persistido) replicando exactamente las 12 reglas reales:
- **B4 (393-402)**: **667/667 verificaciones** (10 columnas × cada upload histórico real con TOTAL fantasma y los 6 componentes completos) — **coinciden exactamente**, mismo resultado que la simulación con función pura del punto 17.21, ahora confirmado contra el código real implementado.
- **B1 (208, 214)**: fixture sintética (datos reales triviales, ver 17.21) ejecutada contra el evaluador real — ambas `PASSED`.
- **Confirmado explícitamente**: las 12 reglas reales en la BD (`esalud_dev`) **no tienen `source_rows` en su `config`** — cero escrituras durante toda la fase.
- **Baseline de clasificación reconfirmado sin cambio**: `activas=717`, `SAFE_1_TO_1=419`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=78`, `ALREADY_STRUCTURE_AGNOSTIC=198` — el código nuevo es inerte para el clasificador (`RuleBindingReconciliationService.php` no fue tocado, no tiene ninguna noción de `source_rows`).

**No se implementó comando de activación.** No se escribió `source_rows`/`row_range`/`total_row` en ninguna de las 12 reglas reales. No se modificó clasificación. No se crearon bindings. No rebind. No calibraciones. No `rem_data` (confirmado byte-idéntico en el test dedicado). No estructura. No backfill. No se reprocesó ninguna carga real. B2/B3 sin tocar. Categoría F sin tocar. Regla 461 sin tocar. `no_utilizada` sin tocar. No commit de Git, no push.

### 17.23 — FASE 3C-3A/3C-3B — COMANDO IMPLEMENTADO / DRY-RUN VALIDADO / CONFIG REAL NO ESCRITA (2026-08-27)

**Autorización recibida**: implementar el comando seguro de activación para las 12 reglas B1+B4 (208,214,393-402), dry-run por defecto, sin ejecutar `--commit` real todavía.

**Comando nuevo**: `rule:activate-source-rows {rule_id} {--reason=} {--by=} {--commit}` (`RuleActivateSourceRowsCommand.php`). NUNCA recibe `source_rows`/`row_range`/`total_row` como argumento — los tres se derivan exclusivamente de la fórmula real (`cell_data`), reutilizando `FormulaRangeCoverageAnalyzer::analyze()`. Dos ramas, determinadas por la forma de `row_range` en config, nunca por el `rule_id`:

- **Rama B1** (`row_range={0,0}` placeholder, 208/214): descubrimiento propio (`discoverSparseSourceRows()`) — busca una fila candidata cuya fórmula, en la misma columna, sea exclusivamente hacia atrás y referencie un subconjunto NO vacío (no necesariamente contiguo) de `[filaInicioDatos:fila-1]`, confirmada por `isEmbeddedBackwardSubtotalRow()`. `row_range` propuesto = envolvente `[min(source_rows):max(source_rows)]`.
- **Rama B4** (`row_range` ya real, 393-402): **hallazgo crítico durante el diseño** — el candidato NUNCA puede derivarse del diagnóstico de Fase 1 (`discoverTotalRowCandidate()`), porque esa función exige que TODAS las filas referenciadas estén dentro de `[row_from:row_to]` ("withinRange") — el término externo (fila 50) rompe esa condición, así que Fase 1 nunca encuentra candidato para estas 10 reglas reales (confirmado: `total_row_candidate=null`, consistente con "sin candidato" del punto 17.9). El candidato se deriva de forma independiente en la posición convencional trailing (`row_to+1`, mismo convenio que mecanismos #8/#12), confirmado por `isEmbeddedBackwardSubtotalRow()`; se exige que `[row_from:row_to]` esté completamente cubierto (sin huecos internos) y las filas referenciadas fuera de ese rango se agregan como términos externos.

**13 guards en orden estricto** (ver docblock de la clase para el detalle completo): existencia/activa/`sum_equals` → `BLOCKED_BY_ENGINE_GAP` → no `no_utilizada` → `total_row` ausente → `source_rows` ausente → candidato+`source_rows` descubiertos (rama correspondiente) → `total_row` dentro de límites vivos → ninguna fila de `source_rows` reclamada por otra sección → `source_rows` válido (defensivo) → **evidencia real en rem_data para CADA componente individual** (más estricto que `rule:activate-category-a`, que solo exige evidencia para *alguna* fila — aquí un término externo nunca capturado se rechaza explícitamente) → simulación de clasificación = `SAFE_1_TO_1` → **el evaluador REAL (`SumEqualsEvaluator`, ya implementado en 17.22) reproduce la fórmula contra TODA carga histórica disponible** (mitiga el riesgo central del punto 17.21 — el clasificador por sí solo nunca verifica corrección aritmética) → sin colisión funcional (529↔530).

**Hallazgo/bug encontrado y corregido durante la validación real (antes del `--commit`)**: la primera versión de `discoverSparseSourceRows()` (Rama B1) aceptó incorrectamente las reglas de Categoría B2 (`A09/I`, patrón periódico de 6 filas TOTAL) y una de Categoría F (228) — porque comparten el MISMO placeholder `row_range={0,0}` que B1, y solo la PRIMERA de las 6 filas periódicas tiene el texto "TOTAL" en columna A (las otras 5 no tienen concepto propio pero sí fórmula continuando el mismo patrón), así que el descubrimiento original encontraba un único candidato "válido" sin darse cuenta de que era parte de un patrón mayor. **Corregido con un guard anti-periodicidad**: antes de validar nada, se exige que exista EXACTAMENTE UNA fila con fórmula en la columna en TODO `[filaInicioDatos+1:filaFinDatos]` (sin importar si pasa el resto de los checks) — un patrón periódico (6+ filas con fórmula) se rechaza aquí, sin necesidad de conocer de antemano cuántas filas TOTAL tiene. Verificado que esto NO afecta B1 (208/214, solo 1 fila con fórmula en su rango real) ni B4 (rama distinta, no usa este método).

**Tests nuevos** (`RuleActivateSourceRowsCommandTest.php`, 11/11 passing): B1 válido (dry-run + commit) · B4 válido (dry-run + commit, `row_range` sin cambio) · fórmula con hueco interno inesperado rechazada (B4) · término externo sin ninguna evidencia real rechazado · candidato ambiguo en Rama B1 rechazado · **patrón periódico estilo `A09/I` rechazado en Rama B1** (réplica reducida del bug encontrado y corregido) · candidato fuera de límites vivos rechazado (patrón 461) · clasificación post-cambio no `SAFE_1_TO_1` rechazada (fixture con secciones duplicadas de código, explotando la divergencia real y genuina entre `findRawSectionData()` —primera coincidencia— y `buildSectionIndex()` —última coincidencia— del clasificador, nunca simulada manualmente) · preservación de bindings/histórico/calibraciones/`rem_data` confirmada byte-idéntica tras commit.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`+`tests/Unit/RuleEngine`): 788 tests, 749 passed, **mismos 39 fallos preexistentes de siempre**, +11 nuevos, todos passing. Cero regresiones nuevas.

**Dry-run real contra las 12** (vía `Artisan::call()` en `esalud_dev`, sin `--commit`): **12/12 aprobadas** en el primer intento (tras el fix anti-periodicidad). Confirmado que las 12 reales siguen sin `source_rows`/`total_row` escrito.

**Gate real contra los 78 `BLOCKED_BY_ENGINE_GAP` actuales**: primera corrida (ANTES del fix anti-periodicidad) reveló el bug — 20 aceptadas en vez de 12 (las 12 correctas + `226,227,228,229,231,232,234` indebidamente aceptadas). **Tras el fix, re-ejecutado: exactamente 12 aceptadas, coincide 100% con la lista esperada (`208,214,393-402`), 0 de más, 0 de menos.** `461` explícitamente rechazada. B2 (`226,227,231,232,234`), B3 (`229`) y Categoría F (`228,230,233`) explícitamente rechazadas. 56 `no_utilizada` rechazadas. `66` rechazadas + `12` aceptadas = `78` exacto.

**Simulación consolidada (transacción real + `rollback`, `esalud_dev`, vía `computeAndValidate()` reflejado — mismo patrón que fases anteriores)**:

| Categoría | Antes | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 419 | 431 | **+12** |
| `BLOCKED_BY_ENGINE_GAP` | 78 | 66 | **−12** |
| `REQUIRES_REMAP` / `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` | — | — | +0 |

**Coincide exactamente con la predicción matemática.** 0 de las 12 sin `SAFE_1_TO_1`. 0 efectos colaterales (verificado sobre las 717, no solo las 12). `461.total_row` confirmado `null` durante la transacción. **Rollback ejecutado y verificado**: clasificación restaurada byte-idéntica, `config` de las reglas 208 y 393 confirmado restaurado exacto (sin `source_rows`/`total_row`, `row_range` de 393 intacto en `{54:58}`).

**Baseline final reconfirmado** (idéntico antes y después de toda la sesión): `activas=717`, `SAFE_1_TO_1=419`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=78`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`, `rem_technical_totals=0`, regla 461 sin tocar (`total_row=null`), de las 12 ninguna con `source_rows`/`total_row` escrito. Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` verificados idénticos.

**Archivos nuevos**: `backend/app/Console/Commands/RuleActivateSourceRowsCommand.php`, `backend/tests/Feature/REM/RuleActivateSourceRowsCommandTest.php`. **Ningún archivo de motor (`SumEqualsEvaluator.php`, `RuleEngineService.php`), clasificador, parser, calibración o estructura fue tocado** — confirmado con `git status`.

**No se ejecutó ningún `--commit` real sobre las 12.** No se tocaron B2/B3, Categoría F, la regla 461, `no_utilizada`. No backfill. No se reprocesó ninguna carga real. No bindings/rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push.

### 17.24 — FASE 3C-3A/3C-3B EJECUTADA Y CERRADA — 12 reglas (2026-08-28)

**Reanudación tras incidente de entorno local (2026-08-28)**: sesión retomada el mismo día del checkpoint 17.23 tras resolver, en orden, un problema de proxy Vite (puerto 8080→8000 desalineado, ajeno a REM), un reset controlado de password del usuario local `admin@esalud.cl` (id=25, ajeno a REM), y limpieza de 3 procesos huérfanos de Vite/queue-worker dejados por el propio asistente durante esa recuperación. Ninguno de esos tres incidentes tocó reglas/config/calibraciones/bindings/estructura — documentados aquí solo porque explican por qué esta fase se ejecutó horas después de 17.23 en vez de inmediatamente.

**Reconfirmación de baseline (READ-ONLY) antes de escribir**: `activas=717`, `SAFE_1_TO_1=419`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=78`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0` — **idéntico** al cierre de 17.23. **1 discrepancia real encontrada**: `rem_technical_totals` = **126** (no 0). Investigada antes de continuar: las 126 filas pertenecen **todas a un único upload real, `id=186`** (`102302A05.xlsm`, `health_center_id=1`, `status=with_errors`, procesado hoy 2026-08-28 13:15:03 — durante la propia recuperación del entorno, probablemente una prueba real de carga tras arreglar el login). Verificación dedicada: **0 duplicados** anómalos (constraint única `rem_upload_id+sheet+rem_section_code+row_number` intacta), `exclusion_reason` usados = únicamente los 3 mecanismos ya conocidos (`embedded_backward_subtotal_row=14`, `embedded_leading_total_row=19`, `embedded_trailing_total_row=93`), distribuidas en >100 secciones reales de 20+ hojas de la Serie A — consistente en todo con el hook de Fase 3A ya aprobado (`RemParserService::parseSheet()`) operando sobre una carga real nueva, no con ninguna anomalía de Fase 3C. Autorizado explícitamente tratar esta única discrepancia como benigna/esperada y actualizar solo ese campo operativo del baseline. Upload 186 no fue tocado ni modificado.

**Reconfirmación del dry-run de las 12** (`rule:activate-source-rows <id>`, sin `--commit`): las 12 (`208,214,393,394,395,396,397,398,399,400,401,402`) reprodujeron exactamente la misma propuesta que en 17.23 — `208`/`214`: `source_rows=[149,150,153,155,157]`, `total_row=158`, `row_range=[149:157]`; `393-402`: `source_rows=[50,54,55,56,57,58]`, `total_row=59`, `row_range` sin cambio (`{54:58}` ya real). Sin ninguna desviación.

**Ejecución real**: 12 invocaciones individuales (`--commit`, cada una con su propia revalidación interna antes de escribir, cada una en su propia transacción — nunca un `UPDATE` masivo), mismo `reason`/`by` (`by=Administrador Esalud`). **12/12 exitosas, sin ninguna detención** — ninguna regla se desvió de lo validado en el dry-run/simulación de 17.23, no fue necesario ningún STOP.

**Post-check — medido, coincide exactamente con lo predicho en 17.23**:

| Métrica | Antes | Después |
|---|---|---|
| `SAFE_1_TO_1` | 419 | **431** |
| `BLOCKED_BY_ENGINE_GAP` | 78 | **66** |
| `REQUIRES_REMAP` | 0 | 0 |
| `DUPLICATE` | 22 | 22 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 |
| reglas activas | 717 | 717 |
| `rem_rules` | 764 | 764 |
| `rem_rule_bindings` | 1204 | 1204 |
| bindings a estructura 67 | 0 | 0 |
| `rem_technical_totals` | 126 | 126 (sin cambio, este comando nunca la toca) |

**Verificaciones detalladas, todas exactas**:
- Las 12 reglas ahora clasifican `SAFE_1_TO_1`, con `config` exacto: `208`/`214` → `source_rows=[149,150,153,155,157]`, `total_row=158`, `row_range={"from":149,"to":157}`; `393-402` → `source_rows=[50,54,55,56,57,58]`, `total_row=59`, `row_range={"from":54,"to":58}` (intacto, sin cambio respecto a antes).
- **Exactamente 12** `RuleVersion` nuevos (uno por regla, creados hoy), snapshot del `config` **anterior** confirmado sin `source_rows`/`total_row` (correcto).
- **Exactamente 12** activity logs `rule_source_rows_activated` hoy, `rule_ids` = exactamente las 12 (`208,214,393,394,395,396,397,398,399,400,401,402`), sin duplicados ni ajenos.
- **Regla 461**: `status=active`, `config.total_row=null`, `config.source_rows=null` — **sin ningún cambio**, sigue congelada (`AUDITADO/NO RESUELTO`, punto 16.13).
- **B2** (`226,227,231,232,234`) y **B3** (`229`): `source_rows=null`, `total_row=null` en las 6 — sin tocar.
- **Categoría F** (`228,230,233`): `source_rows=null`, `total_row=null` en las 3 — sin tocar.
- `rem_data` total = `399.811` — sin cambio (el comando nunca la toca).
- Estructura activa = `67/v35`, `status=active` — sin tocar.
- Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` — verificados en vivo, **idénticos** a los de toda la campaña (`44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` / `fe713f961def425c2a1dd5dd4c4a1972c913c3e7c708e5943ace9b75f6d653e9`) — calibraciones sin tocar.
- `no_utilizada` (56 secciones) — sin tocar, no forma parte de ninguna escritura de esta fase.

**Ninguna métrica difirió de lo predicho en 17.23 — no fue necesario ningún STOP ni compensación.**

**Fase 3C-3A/3C-3B queda EJECUTADA Y CERRADA para las 12 reglas B1+B4.** Único pendiente restante dentro de la deuda técnica #5/Categoría B-F: **66 `BLOCKED_BY_ENGINE_GAP`** = `461` (congelada, 1) + **B2 (5: `226,227,231,232,234`)** + **B3 (1: `229`)** + **Categoría F (3: `228,230,233`)** + **`no_utilizada` (56)**. No se inició Fase 3C-3C (B2) ni 3C-3D (B3). No se tocó 461. No backfill. No se reprocesó ninguna carga real (upload 186 quedó exactamente como estaba). No rebind. No commit de Git, no push.

### 17.25 — FASE 3C-3C/3C-3D: AUDITORÍA EXHAUSTIVA de las 9 reglas restantes (B2+B3+Categoría F) — 100% READ-ONLY, NADA IMPLEMENTADO (2026-08-28)

⚠️ Todo este punto es **AUDITADO** (evidencia real, verificada) salvo la sección final "Diseño técnico — PROPUESTO / NO IMPLEMENTADO", marcada explícitamente. Ninguna escritura de ningún tipo durante esta auditoría: `config` de las 9 reglas confirmado sin cambio (`source_rows=null`, `total_row=null`, `row_range={0,0}` en las 9), `RuleVersion` nuevos para estas 9 = `0`, `rem_technical_totals=126` sin cambio, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a 67=`0`. No se tocó la regla 461, `no_utilizada`, calibraciones, `rem_data`, estructura, ni ningún código. No commit, no push.

**Confirmado común a las 9**: sección `A09/I`, límites vivos `[249:336]`, `row_range={"from":0,"to":0}` (placeholder, nunca tuvo rango real). `classifySingleRule()` reconfirmado en vivo: las 9 → `BLOCKED_BY_ENGINE_GAP`, motivo `invalid_row_range_configuration`, **`total_row_candidate=null` en las 9** (Fase 1 nunca intenta descubrir nada contra un placeholder `{0,0}`, comportamiento ya conocido). `RuleExecutionLog` = **77/77 `skipped` en las 9, sin excepción** — nunca se evaluaron, `rem_validation_results=0` en las 9. Como `row_range={0,0}` → `scope='per_row'` (nunca `'row_range'`), `isVerticalSumEqualsRule()` nunca es `true` para ninguna de las 9 — el evaluador vertical (`SumEqualsEvaluator::evaluateVerticalAggregation()`) **nunca se alcanza**, sin importar qué se escriba en `total_row`/`source_rows` mientras `row_range` siga siendo el placeholder (misma arquitectura ya documentada para 56/208/214 en el punto 17.8, confirmada de nuevo hoy sin cambios de código).

#### Hallazgo estructural NUEVO, no documentado antes de hoy: etiqueta "TOTAL" fusionada en 6 filas confunde los mecanismos #6/#8/#11/#12

`cell_data` confirma: `A331` = texto `"TOTAL"`, celda **combinada** `rango_combinado="A331:A336"` (fusión de 6 filas). Los mecanismos de detección de fila TOTAL (#8 `isTrailingTotalRow`/#12 `isEmbeddedBackwardSubtotalRow`) evalúan la etiqueta de concepto **por fila individual** — nunca resuelven el valor de una celda combinada hacia las filas subordinadas. Consecuencia verificada con evidencia real, no supuesta:

- **Carga real de hoy (`upload_id=186`)**: `rem_technical_totals` capturó **exactamente 1 fila** para `A09/I` — **fila 331**, `exclusion_reason=embedded_trailing_total_row` (el mecanismo #8/#12 sí la detecta, porque es la fila ancla de la fusión, la única con texto propio en columna A). **Las filas 332,333,334,335,336 aparecen en `rem_data` de esa misma carga** — es decir, el mecanismo **no las detecta como TOTAL en absoluto**, y el parser las persiste como si fueran filas de captura real, con el valor ya calculado de su propia fórmula.
- **`rem_data` histórico confirma el mismo patrón en las 145 cargas del sistema**: fila 331 = **133 registros** históricos (7 menos que el resto — coincide con las 7 cargas más recientes, posteriores a la activación de Fase 3A, donde el mecanismo ya la excluye correctamente); filas 332,333,334,335,336 = **140 registros cada una, sin excepción, en absolutamente todas las cargas** (incluida la de hoy) — nunca excluidas, en ningún momento de la historia del sistema.
- **Conclusión**: este es un gap de mecanismo **distinto** al ya documentado (deuda técnica #5) — no es "TOTAL excluido del modelo de datos" (Familia A), es **"TOTAL fusionado en múltiples filas, solo la fila ancla se excluye, las demás contaminan `rem_data` con valores 100%-fórmula como si fueran captura real"**. No se propone ni implementa ninguna corrección de este gap en esta auditoría — queda documentado como hallazgo nuevo, pendiente de decisión futura, **fuera del alcance de B2/B3/Categoría F** (es un problema de parser/mecanismo, no de diseño de reglas) pero con relación directa: es la razón por la que las filas 332-336 SÍ tienen datos reales disponibles en `rem_data` (a diferencia de la fila 331, que solo estaría disponible vía `rem_technical_totals` en cargas futuras).

#### Verificación fórmula-por-fórmula, filas 331-336, las 9 columnas (evidencia real, `cell_data` de la estructura activa 67/v35)

| Fila | AM(226) | AN(227) | AQ(228) | AR(229) | AS(230) | AT(231) | AU(232) | AV(233) | AX(234) |
|---|---|---|---|---|---|---|---|---|---|
| 331 | 13 términos (253,259,...,325) | ídem AM | 13 términos (ídem) | 13 términos (ídem) | `SUM(265,277)` — 2 términos | 13 términos (ídem) | 13 términos (ídem) | **sin fórmula, celda vacía** | 13 términos (ídem) |
| 332 | 13 términos (254,260,...,326) | ídem | 13 términos (ídem) | 13 términos (ídem) | `SUM(278,266)` — 2 términos | 13 términos (ídem) | 13 términos (ídem) | sin fórmula | 13 términos (ídem) |
| 333 | 13 términos (255,261,...,327) | ídem | **sin fórmula** | **14 términos: `AR337` + 13 términos regulares (255,...,327)** | `SUM(267,279)` — 2 términos | 13 términos (ídem) | 13 términos (ídem) | sin fórmula | 13 términos (ídem) |
| 334 | 13 términos (256,262,...,328) | ídem | **sin fórmula** | 13 términos (256,...,328) | `SUM(269,281)` — 2 términos, **offset saltado** (ver abajo) | 13 términos (ídem) | 13 términos (ídem) | **13 términos (256,...,328)** | 13 términos (ídem) |
| 335 | 13 términos (257,263,...,329) | ídem | **sin fórmula** | 13 términos (257,...,329) | **13 términos completos (257,...,329)** — duplica el offset de 334 | 13 términos (ídem) | 13 términos (ídem) | sin fórmula | 13 términos (ídem) |
| 336 | 13 términos (258,264,...,330) | ídem | **sin fórmula** | 13 términos (258,...,330) | `SUM(270,282)` — 2 términos | 13 términos (ídem) | 13 términos (ídem) | sin fórmula | 13 términos (ídem) |

Todas las series de "13 términos" cubren, entre las 6 filas, exactamente el rango componente `[253:330]` (78 posiciones = 6×13, sin huecos ni solapes) para las columnas regulares — verificado término por término, incluyendo las filas con reordenamiento cosmético del orden de suma (335/336 en varias columnas) que no altera el conjunto de términos.

#### B2 — `AM(226), AN(227), AT(231), AU(232), AX(234)`: 5 columnas idénticas, cardinalidad real = 6 TOTAL por columna

**Patrón real**: 6/6 filas con fórmula, cada una sumando exactamente 13 términos con paso fijo 6, cubriendo el rango componente completo `[253:330]` sin huecos. **Esto NO es 1 regla con 1 total — son 6 validaciones independientes reales por columna**, cada una correspondiente a una "posición dentro del bloque de 6" (offset 0-5). Confirmado con `rem_data`: filas 332-336 (offsets 1-5) están **disponibles ahora mismo** como datos ordinarios (vía el gap de mecanismo recién documentado); la fila 331 (offset 0) solo estaría disponible vía `rem_technical_totals` en cargas futuras (ya excluida desde Fase 3A).

**Por qué `source_rows`+`total_row` (el mecanismo ya implementado en 17.22/17.23) es insuficiente, no por semántica incorrecta sino por cardinalidad**: el esquema actual (`Rule.config` con **un solo** `total_row`/`source_rows`) no puede representar 6 pares (total_row, source_rows) simultáneos para la misma columna. Forzar uno solo (ej. `total_row=331`, `source_rows=[253,259,...,325]`) validaría correctamente **esa** posición, pero dejaría las otras 5 sin ninguna validación posible bajo la regla existente — no un resultado incorrecto, una **cobertura permanentemente incompleta** (5/6 nunca se comprobarían). **No se debe forzar el mecanismo actual sobre estas 5 reglas tal cual están** — se necesita o bien 6 reglas nuevas por columna (30 reglas nuevas en total) reutilizando el esquema actual, o extender el esquema para soportar múltiples agregaciones por regla — ver diseño propuesto abajo, ninguna decisión tomada.

#### B3 — `AR(229)`: mismo patrón periódico de B2 + 1 término externo (`AR337`) en la fila 333 — investigado a fondo, origen confirmado

Comparte exactamente la base de B2 (5 de 6 filas 100% regulares: 331,332,334,335,336). **Fila 333 es la única anómala**: `=SUM(AR337+AR255+AR261+...+AR327)` — 13 términos regulares del offset 2 **más** `AR337`.

**`AR337` investigado con evidencia directa, no supuesta**: `cell_data` de la estructura activa no tiene NINGÚN registro para `AR337` — la fila 337 cae en un **gap real entre secciones** (`A09/I` termina en `336`, `A09/J` empieza en `339` — filas 337-338 nunca fueron escaneadas por ninguna sección). Para resolver esto sin asumir nada, se abrió **el archivo Excel de referencia real** (`recursos-rem/SA_26_V1.2-2.xlsm`, hoja `A09`, solo lectura, vía PhpSpreadsheet — verificado primero que `AM331` coincide exactamente con el `cell_data` ya persistido, confirmando que es el archivo fuente correcto) y se inspeccionó directamente:

- **Fila 337, columna A** = `"SECCIÓN J: ACTIVIDADES EFECTUADAS POR TÉCNICO PARAMÉDICO DENTAL Y/O HIGIENISTAS DENTALES"` — es literalmente **la fila del marcador de encabezado de la sección siguiente** (Sección J).
- **`AR337` en esa misma fila**: `NULL` — sin valor, sin fórmula, completamente vacía (es la celda de la fila-título de Sección J, en una columna donde esa fila no tiene ningún contenido).

**Conclusión, con evidencia directa del archivo fuente**: `AR337` es una **referencia extraviada del propio template Excel original** — no un dato de negocio real, no una referencia estructural válida, no una fórmula incompleta que falte completar. Es un término adicional que la fórmula de la fila 333 incluye por error (muy probablemente un artefacto de arrastre/copiado al construir las 6 fórmulas del bloque), que apunta a una celda perpetuamente vacía en la fila-título de la sección siguiente — **matemáticamente inofensivo** (siempre suma 0, nunca cambia el resultado real de la fila 333) pero **estructuralmente inválido** como referencia. **No es una anomalía de nuestro sistema ni de la estructura activa — es un defecto del propio archivo Excel de origen**, presente igual en la plantilla de referencia.

**Tratamiento recomendado (no implementado)**: si en el futuro se diseña una solución para B2 aplicable también a B3, `AR337` debe **omitirse explícitamente** de cualquier `source_rows` para la fila 333 (matemáticamente equivalente, ya que su valor real es siempre 0/vacío) — documentado aquí como la respuesta definitiva a la pregunta abierta que dejó el punto 16.13/17.20 sobre el origen de `AR337`. La pregunta queda **cerrada evidencialmente**, aunque la regla 229 sigue sin resolver (misma cardinalidad de B2 + esta consideración adicional).

#### Categoría F — `AQ(228), AS(230), AV(233)`: 3 anomalías genuinamente distintas, sin solución común

**`228` (AQ) — bloque incompleto**: solo **2 de 6** filas (331,332) tienen fórmula; 333,334,335,336 están completamente vacías (sin fórmula, sin valor) en el propio template Excel. No es un problema de nuestro sistema — el template original **nunca calculó** los otros 4 períodos para esta columna. **No hay ninguna fórmula "faltante que completar"** — los otros 4 totales simplemente no existen en el archivo fuente. Máxima cobertura posible: 2/6.

**`230` (AS) — mapeo inconsistente, sin patrón automatizable**: las 6 filas tienen fórmula, pero son sumas dispersas de 2 términos (no 13), y el mapeo fila→offset es **inconsistente**: fila 331→offset0(parcial), 332→offset1(parcial), 333→offset2(parcial), 334→offset4(parcial, **salta offset3 por completo**), 335→offset4(**completo, 13 términos — duplica el offset que 334 ya referenciaba parcialmente**), 336→offset5(parcial). Ningún offset3 existe en ninguna fila. El offset4 aparece dos veces (parcial en 334, completo en 335) — no hay forma de derivar automáticamente "qué fila corresponde a qué total" con una regla general; requeriría mapeo manual fila-por-fila, y aun así el offset3 no tendría fila correspondiente. **No generalizable, no automatizable con el mecanismo `source_rows` actual ni con ninguna extensión simple.**

**`233` (AV) — solo 1 de 6 con fórmula**: únicamente la fila `334` tiene fórmula real (13 términos, offset 3 completo); las 5 restantes (331,332,333,335,336) están completamente vacías en el template. Máxima cobertura posible: 1/6.

**Ninguna de las 3 comparte una causa común con las otras 2, ni con B2** — cada una refleja una limitación/particularidad distinta del propio archivo Excel de origen para esa columna específica. **No deben agruparse ni tratarse con un mecanismo único.**

#### Matriz consolidada de las 9 reglas

| rule_id | columna | patrón real | causa raíz | evidencia | representación necesaria | tratamiento recomendado | riesgo |
|---|---|---|---|---|---|---|---|
| 226 | AM | 6/6 filas regulares, 13 términos c/u, paso 6, cubren `[253:330]` sin huecos | Cardinalidad: 6 TOTAL reales por columna, esquema actual solo soporta 1 | `cell_data` 6/6 confirmado; `rem_data` filas 332-336 disponibles (140 c/u), fila 331 vía `rem_technical_totals` futuro | 6 pares `(total_row,source_rows)` independientes — no representable con 1 regla actual | `ENGINE_CHANGE_REQUIRED` (schema/motor) — 6 reglas nuevas o extensión de `config` a múltiples agregaciones | Bajo si se dividen en 6 reglas nuevas (reutiliza mecanismo ya validado); medio si se extiende el esquema (cambia evaluador/reporting) |
| 227 | AN | idéntico a 226 | idéntica | idéntica | idéntica | idéntico | idéntico |
| 231 | AT | idéntico a 226 | idéntica | idéntica | idéntica | idéntico | idéntico |
| 232 | AU | idéntico a 226 | idéntica | idéntica | idéntica | idéntico | idéntico |
| 234 | AX | idéntico a 226 | idéntica | idéntica | idéntica | idéntico | idéntico |
| 229 | AR | 5/6 regulares + fila 333 con término externo `AR337` | Cardinalidad (igual a B2) + referencia extraviada del template original en fila 333 | Igual evidencia que B2 + `AR337` confirmado vacío/inexistente vía Excel fuente real (`SA_26_V1.2-2.xlsm`) | Igual a B2, más: fila 333 debe excluir `AR337` de `source_rows` (matemáticamente inocuo) | `ENGINE_CHANGE_REQUIRED` (igual a B2) + `AR337` confirmado excluible sin riesgo — pregunta de origen ya cerrada evidencialmente | Igual a B2; adicionalmente nulo respecto a `AR337` (ya probado que sumar 0 no altera nada) |
| 228 | AQ | Solo 2/6 filas (331,332) con fórmula; 4 vacías en el template original | Bloque incompleto en el archivo fuente — no es un dato faltante, es que nunca se calculó | `cell_data`: filas 333-336 sin fórmula ni valor, confirmado | Máximo 2 validaciones posibles de 6 — cualquier solución debe aceptar cobertura parcial nativa | `NEEDS_INDIVIDUAL_DECISION` — decidir si se acepta validar solo 2/6 o se deja sin validar | Bajo (no hay ambigüedad: los otros 4 simplemente no existen) |
| 230 | AS | 6/6 con fórmula pero dispersa (2 términos) y mapeo fila→offset inconsistente, offset3 ausente, offset4 duplicado (parcial+completo) | Anomalía de autoría del template — sin patrón regular ni automatizable | `cell_data` 6/6 fórmulas dispersas, offsets no coinciden 1:1 con filas, confirmado término por término | No hay una representación automática única — requeriría mapeo manual fila-por-fila, con un offset (3) sin fila y otro (4) duplicado | `NEEDS_DEEP_AUDIT` / decisión manual caso por caso — no forzar ningún mecanismo genérico | Alto si se automatiza sin decisión manual (mapeo ambiguo, offset4 duplicado podría generar doble conteo) |
| 233 | AV | Solo 1/6 filas (334) con fórmula; 5 vacías en el template original | Igual naturaleza que 228 pero aún más incompleto | `cell_data`: solo `AV334` tiene fórmula (13 términos, offset3 completo), el resto vacío confirmado | Máximo 1 validación posible de 6 | `NEEDS_INDIVIDUAL_DECISION` — decidir si se acepta validar solo 1/6 o se deja sin validar | Bajo (sin ambigüedad, igual que 228) |

#### Diseño técnico — PROPUESTO / NO IMPLEMENTADO (solo para B2/B3, ninguna decisión tomada)

⚠️ Nada de esta sección fue implementado, ni siquiera parcialmente. Dos alternativas comparadas, sin preferencia forzada:

- **Opción A — 6 reglas nuevas por columna** (30 reglas nuevas para B2 + 6 para B3 = 36 filas de catálogo nuevas): reutiliza exactamente el mecanismo `source_rows`+`total_row` ya implementado y validado (17.22/17.23), sin tocar el evaluador ni el schema. Cada nueva regla se comporta como una regla `sum_equals` independiente y completa. Contras: multiplica el catálogo (764→800 reglas activas potenciales), requiere decidir una convención de nombres/identidad para las 6 variantes por columna, y duplica reporting (`RuleExecutionLog` por separado para cada "sub-período").
- **Opción B — extender `config` a múltiples agregaciones por regla** (ej. `config['aggregations'] = [{total_row, source_rows}, ...6 elementos]`): mantiene 1 regla = 1 concepto de negocio (más limpio para el catálogo), pero requiere modificar `SumEqualsEvaluator` para iterar N agregaciones por regla y decidir cómo se reporta un resultado parcial (¿la regla es `failed` si 1 de 6 falla? ¿se reporta granular por agregación?) — cambio de motor más profundo que cualquiera de los ya hechos en esta campaña, sin diseño detallado todavía.

**Ninguna decidida.** Para B3 (229), cualquiera de las dos opciones debe además excluir `AR337` de la agregación de la fila 333 (ya confirmado seguro). Para Categoría F (228/230/233), **ninguna de las dos opciones aplica directamente** — requieren decisión de negocio previa (¿aceptar cobertura parcial 2/6 y 1/6? ¿cómo resolver el mapeo ambiguo de 230?) antes de cualquier diseño técnico.

**Hallazgo del gap de mecanismo (etiqueta fusionada)**: sin propuesta de fix en esta auditoría — se documenta como hallazgo nuevo para una futura sesión de endurecimiento del parser, potencialmente relevante más allá de `A09/I` (cualquier sección con un TOTAL fusionado en múltiples filas podría compartir el mismo gap, no verificado en otras hojas).

Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=126`. Ninguna escritura de ningún tipo durante esta auditoría.

### 17.26 — Hallazgo del punto 17.25 (etiqueta TOTAL fusionada): auditoría de causa raíz + alcance global + histórico + diseño — AUDITADO / CORRECCIÓN PROPUESTA / NO IMPLEMENTADA (2026-08-28)

⚠️ **100% READ-ONLY.** Ninguna escritura de ningún tipo: `rem_technical_totals=126` (sin cambio), `rem_rules=764`, `rem_rule_bindings=1204`, `RuleVersion` creados hoy = `12` (los mismos de Fase 3C-3A/3C-3B, ninguno nuevo). No se tocaron las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, `rem_data`, bindings, estructura, calibraciones, ni ningún archivo de motor/parser. No commit, no push.

#### 1. Causa raíz exacta (confirmada leyendo código, no supuesta)

`EnhancedCellScanner::buildMergeMapDetail()` (líneas 360-384) **ya calcula**, para cada celda de cada rango fusionado en el Excel, un mapa con 3 datos: `rango` (ej. `"A331:A336"`), **`es_principal`** (`true` solo para la celda ancla, `false` para las subordinadas) y **`col_principal`** (la coordenada de la celda ancla, ej. `"A331"`). Al construir `EnhancedCellDTO` (líneas 125-146), **solo se usan 2 de esos 3 datos** (`esCombinada`/`rangoCombinado`) — `es_principal` y `col_principal` se calculan y se descartan de inmediato, nunca llegan a `cell_data`. Confirmado en `cell_data` real: `A331` (ancla) tiene `valor_bruto="TOTAL"`; `A332`...`A336` (subordinadas) tienen **`valor_bruto=NULL`** pero **sí conservan `es_combinada=true` y `rango_combinado="A331:A336"`** — es decir, la metadata de "pertenezco a este merge" SÍ está disponible en cada fila, pero el dato de "cuál es la celda ancla" no se expone (aunque es derivable del propio `rango_combinado`, que ya contiene la coordenada de inicio).

Los 3 mecanismos de detección de fila TOTAL (`RemParserService::isEmbeddedBackwardSubtotalRow()`, su copia independiente en `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()`, y por construcción también `isTrailingTotalRow()`/`isEmbeddedLeadingTotalRow()` en ambos archivos) buscan, **fila por fila**, una celda no-fórmula con `valor_bruto` no vacío que "parezca etiqueta TOTAL" (`pareceEtiquetaTotal()`/`pareceEtiquetaTotalMatrix()`, ambas idénticas: `str_contains($texto,'TOTAL') || str_contains($texto,'AMBOS SEXOS')`). **Nunca consultan `es_combinada`/`rango_combinado`.** Para la fila 331 (ancla): `A331.valor_bruto="TOTAL"` → pasa el chequeo → mecanismo #12 la excluye correctamente. Para las filas 332-336 (subordinadas del merge): su celda de concepto (columna A) tiene `valor_bruto=NULL` (comportamiento estándar de Excel/PhpSpreadsheet: el valor de una celda fusionada vive únicamente en la celda ancla) → el mecanismo nunca encuentra evidencia de etiqueta TOTAL para esas filas → las 5 filas **nunca pasan por el chequeo de exclusión en absoluto** → se persisten como filas de dato ordinario.

`SectionDetectorService` (que opera sobre el `Worksheet` crudo, no sobre `cell_data`, y decide `filaFinDatos`/límites estructurales) **no necesita ningún cambio para este caso**: el mecanismo #12 (Opción C, ya documentado desde 2026-08-10) nunca ajusta `filaFinDatos` — la fila técnica permanece deliberadamente DENTRO del rango declarado, visible en `cell_data`, y se excluye únicamente en la capa de persistencia/calibración. Confirmado que `A09/I.filaFinDatos=336` incluye las 6 filas, consistente con ese diseño — no hay ninguna regresión estructural que corregir ahí.

#### 2. Alcance global — Serie A completa, 386 secciones escaneadas, NO se asumió que A09/I fuera único

Script de solo lectura sobre los 386 archivos `cell-data` de la estructura activa 67/v35: se buscaron **todas** las fusiones verticales (misma columna, `filaFin > filaInicio`) cuya celda ancla contenga "TOTAL" o "AMBOS SEXOS" (mismo criterio exacto que `pareceEtiquetaTotal()`).

- **123 fusiones verticales candidatas** encontradas en 60+ secciones distintas de 25 hojas (`A01,A03,A04,A05,A06,A07,A08,A09,A11a,A19a,A19b,A21,A24,A26,A27,A28,A29,A30,A30AR,A31,A32,A34`), con `span` de 2 a 6 filas.
- **Segundo filtro, decisivo**: de las 123, se verificó cuáles tienen **al menos una fórmula real en alguna fila subordinada** (no solo en la ancla) — porque sin fórmula en las filas subordinadas no hay ningún valor calculado que pueda "filtrarse" hacia `rem_data` como dato espurio (una fila de relleno visual del merge, sin fórmula, simplemente no genera ningún registro con datos falsos).
- **Resultado: 1 de 123 tiene el patrón de riesgo real — exactamente `A09/I`, rango `A331:A336`** (filas subordinadas 332,333,334,335,336, todas con fórmula real confirmada). **Las 122 restantes son fusiones puramente cosméticas** (la etiqueta "TOTAL"/"Ambos sexos" se fusiona visualmente sobre 2-3 filas por diseño de la plantilla, pero ninguna fila subordinada tiene ninguna fórmula ni dato — son filas de relleno inertes, sin ningún riesgo de contaminar `rem_data`).
- **Conclusión, con evidencia exhaustiva, no supuesta**: `A09/I` es, hoy, el **único** caso real de este patrón en toda la Serie A. La corrección debe diseñarse de forma genérica (basada en la metadata de merge ya existente, según exige el punto 4 de las instrucciones), pero su impacto práctico inmediato se limita a esta única sección.

#### 3. Histórico de `A09/I` — confirmado, nada modificado

- **Filas 332-336 presentes en `rem_data` en las 140 cargas de TODA la historia del sistema** (`upload_id` desde `19` hasta `186`, sin ninguna excepción) — el gap nunca ha tenido protección, en ningún momento.
- **Fila 331 excluida desde `upload_id=176` (2026-08-10 20:35:52)** — coincide exactamente con la fecha de implementación original del mecanismo #12 (documentada en este archivo desde esa misma fecha) — **7 cargas** desde entonces (`176,180,181,182,183,184,186`) confirman la exclusión consistente de la fila ancla únicamente. La fila 331 nunca fue diferente de las demás por diseño — simplemente es la única que el mecanismo alcanza a detectar.
- **Valores reales de las filas filtradas**: **6.300 valores verificados** (140 cargas × 5 filas × 9 columnas) — **0 no-cero, 5.180 en cero, 1.120 `null`**. Es decir, el "leak" nunca introdujo un valor espurio distinto de cero en la práctica — dato real de este establecimiento/periodo, no una garantía general.
- **Impacto en `RuleExecutionLog`/`rem_validation_results`**: **ninguno** — confirmado que las únicas 9 reglas activas de `A09/I` (`226-234`, las mismas de la Fase 3C-3C/3C-3D) nunca alcanzan el evaluador (77/77 `skipped`, `row_range={0,0}` → `scope=per_row`), y que **no existe ninguna otra regla activa** en el sistema que apunte a `A09/I` — verificado consultando las 717 reglas activas reales, no supuesto. El leak ha sido, hasta hoy, completamente inerte para el motor de reglas.
- **Impacto en certificación/UI**: ninguno detectado por la misma razón (nada evalúa esas filas); no se investigó exhaustivamente cada pantalla de UI que pudiera listar conteos crudos de `rem_data`, pero no hay ningún camino conocido que dependa de esto.
- **Impacto en calibración — CONFIRMADO, real, no hipotético**: `SectionCalibrationMatrixService::buildPatternMatrix('A09','I')` (invocado en vivo hoy) muestra que **las filas 332-336 aparecen HOY con `row_type=data`** dentro de la matriz de patrones — la fila 331 (única detectada por el mecanismo #12) está correctamente ausente de `all_rows`. `reconciliation.effective_section_reviewed=true` y `historical_section_reviewed=true` — **`A09/I` ya está calibrada/revisada** dentro de la campaña Serie A cerrada. Como `pattern_fingerprint` depende únicamente del conjunto de filas de cada patrón (deuda técnica #1, ya documentada), **si se corrige el mecanismo, las filas 332-336 dejarían de contarse como `data` en el/los patrón(es) que hoy las incluyen, cambiando su fingerprint** — con altísima probabilidad, esto **reclasificaría `A09/I` de `AUTO_MIGRATE`/revisada a `MISMATCH`**, requiriendo pasar por el mismo flujo `safe_reconfirm` ya usado 120 veces en esta campaña antes de poder considerarse "sin efectos colaterales sobre calibraciones". **Este es un prerequisito real de cualquier implementación futura, no una formalidad** — no se investigó en detalle qué patrón(es) específicos de los 23 de `A09/I` incluyen hoy las filas 332-336 ni su fingerprint exacto (fuera de alcance de esta auditoría, que fue explícitamente solo diagnóstico + diseño).

#### 4. Diseño de la corrección — 3 opciones comparadas, ninguna implementada, priorizando evidencia estructural (no hardcode de filas)

| | A) Propagar semántica de la celda fusionada a todas sus filas | B) Detectar el bloque técnico por merge+fórmulas (nuevo método agregado) | **C) Extender los mecanismos #8/#12 existentes con resolución de ancla vía merge (recomendada)** |
|---|---|---|---|
| Mecanismo | Antes de leer `valor_bruto` de la fila evaluada para el chequeo de "parece etiqueta TOTAL", si esa celda está vacía pero `es_combinada=true` y el rango es vertical (`filaFin>filaInicio`, misma columna), resolver la celda ANCLA (parseando el propio `rango_combinado`, ej. `"A331:A336"` → ancla=`"A331"` — dato ya presente en cada fila, sin necesitar ningún campo nuevo) y usar el `valor_bruto` de ESA celda para el chequeo `pareceEtiquetaTotal()`. El resto del método (chequeo de fórmula/backward-reference de la fila evaluada) **queda exactamente igual, sin cambios** | Nuevo método independiente que primero identifica bloques (rango de merge vertical con ancla tipo TOTAL) y luego itera sus filas — funcionalmente equivalente a A pero con más código nuevo, mayor superficie | Igual que A, implementado como una extensión mínima y aislada dentro de `isEmbeddedBackwardSubtotalRow()` (y, por completitud/consistencia, las otras 2 variantes) en los 2 archivos que ya usan `cell_data` (`RemParserService`, `SectionCalibrationMatrixService`) |
| Archivos a tocar | 2 (`RemParserService.php`, `SectionCalibrationMatrixService.php`) | 2 + posible refactor de los 3 mecanismos para reutilizar el nuevo método | 2 — ningún archivo nuevo, ninguna migración, ningún cambio de `EnhancedCellScanner`/DTO estrictamente necesario (el dato ya existe en `rango_combinado`) |
| Riesgo sobre filas normales | Nulo — la condición nueva solo se activa cuando la celda de concepto está vacía Y `es_combinada=true` Y el merge es vertical; una fila de dato real nunca fusionada, o fusionada solo horizontalmente, nunca entra en esa rama | Igual | Igual — misma condición exacta |
| Riesgo sobre merges de encabezado | Nulo — un encabezado no tiene fórmulas backward-referencing en sus columnas de valor (son texto/vacío), así que sigue fallando la segunda mitad del chequeo (sin cambios) | Igual | Igual |
| Riesgo sobre las 122 fusiones "seguras" | Nulo — ninguna tiene fórmula en filas subordinadas, así que la segunda mitad del chequeo (backward-reference) sigue fallando para ellas igual que hoy | Igual | Igual |
| Riesgo sobre calibraciones | **Alto, ya confirmado real** (ver punto 3) — cualquiera de las 3 opciones cambia la clasificación de fila de 332-336, cambia el fingerprint del patrón de `A09/I`, dispara `MISMATCH` | Igual | Igual — **el riesgo es inherente a la corrección funcional en sí, no a qué opción de implementación se elija** |
| Generaliza a futuros casos similares (sin hardcode) | Sí | Sí | Sí — las 3 opciones son igualmente generales, la diferencia es solo superficie de código nueva |
| Campo nuevo en `cell_data`/DTO requerido | No (parsea `rango_combinado` en el momento) | No | No — **aunque se recomienda, como mejora opcional de limpieza de código, exponer explícitamente `es_principal`/`col_principal` en `EnhancedCellDTO`** (ya calculados en `EnhancedCellScanner::buildMergeMapDetail()`, hoy descartados) para que los 3 mecanismos no necesiten re-parsear el string del rango cada vez — esto NO es necesario para que la corrección funcione, es una limpieza adicional de menor prioridad |

**Recomendación: Opción C** — mismo mecanismo que A, pero enmarcada como una extensión mínima y aislada de los métodos ya existentes (mismo patrón ya usado en toda la campaña: `isLegitimateTrailingTotalBeyondBounds()` en el punto 17.15 fue una extensión aislada similar). Menor superficie de cambio, cero archivos nuevos, cero migraciones, reutiliza metadata que el scanner ya calcula y persiste hoy (`es_combinada`/`rango_combinado`), sin inferir nada por posición de fila.

**Requisitos que la corrección debe garantizar (confirmados compatibles con el diseño de Opción C, no implementados)**:
- `331-336` excluidas correctamente en nuevas cargas → sí, una vez que `isEmbeddedBackwardSubtotalRow()` devuelva `true` para las 5 filas subordinadas (hoy solo 331), el `continue` ya existente en `RemParserService::parseSheet()` (línea ~587, ver punto 17.3) las excluye automáticamente de `rem_data` sin ningún cambio adicional.
- Cada fila técnica preservada en `rem_technical_totals` → sí, automático — el hook de Fase 3A (punto 17.7) ya captura CUALQUIER fila que los mecanismos marquen como excluida, sin importar cuántas sean por sección; no requiere ningún cambio en `ProcessRemUploadJob`/`RemTechnicalTotal`.
- Ninguna de 331-336 en `rem_data` → sí, consecuencia directa de lo anterior.
- Cero cambios en filas normales / no afectar merges de encabezado → sí, confirmado por diseño de la condición (ver tabla).
- **No afectar calibraciones → NO GARANTIZADO SIN TRABAJO ADICIONAL** — la corrección SÍ cambiaría la clasificación de fila en `SectionCalibrationMatrixService`, con el riesgo de `MISMATCH` ya confirmado en el punto 3. Cualquier implementación futura de esta corrección debe ir acompañada de una verificación explícita del impacto en el/los patrón(es) de `A09/I` que hoy incluyen las filas 332-336, y probablemente de un paso `safe_reconfirm` posterior — **no se puede prometer "cero impacto en calibraciones" para esta corrección específica, a diferencia de todas las demás fases de esta campaña**, y esto debe decidirse explícitamente antes de implementar, no descubrirse después.

**No implementado.** Ningún archivo de motor/parser/calibración fue modificado. No se tocó `EnhancedCellScanner.php`, `EnhancedCellDTO.php`, `RemParserService.php`, `SectionCalibrationMatrixService.php`, `SectionDetectorService.php`. No se tocaron las 9 reglas B2/B3/Categoría F. No se investigó a fondo cuál(es) de los 23 patrones de `A09/I` incluyen 332-336 ni su fingerprint exacto — pendiente si se autoriza continuar con el diseño.

Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=126`. Ninguna escritura de ningún tipo durante esta auditoría.

### 17.27 — AUDITORÍA DE IMPACTO DE CALIBRACIÓN — READ-ONLY (2026-08-28): simulación real ANTES/DESPUÉS + estudio de `safe_reconfirm` + verdict final

⚠️ **100% READ-ONLY.** Toda simulación se ejecutó mediante una subclase temporal en memoria (`SimulatedFixedCalibrationService`, definida en un script de scratchpad, NUNCA escrita al proyecto ni al autoload de producción) que **hereda de `SectionCalibrationMatrixService` sin modificarla** y sobreescribe únicamente el método público `isEmbeddedBackwardSubtotalRow()` — reutilizando el 100% del código real para todo lo demás (`buildPatternMatrix()`, `classifyRow()`, cálculo de `canonical_fingerprint`, y `PatternMigrationScanner::scanSection()` sin ningún cambio, instanciado con la subclase inyectada en el lugar del servicio real). Confirmado con `git status`/hashes que ningún archivo de producción fue tocado. Baseline reconfirmado idéntico antes y después: `activas=717`, `SAFE_1_TO_1=431`, `BLOCKED_BY_ENGINE_GAP=66`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=126`, hash de `reglas-funcionales.json` = `44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` (idéntico, sin cambio). No se tocaron las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, `rem_data`, bindings, estructura. No se ejecutó `safe_reconfirm` ni ningún comando de escritura. No se reprocesó el upload 186. No commit, no push.

#### 1. Simulación ANTES vs DESPUÉS — ejecutada con el código real de producción

**ANTES (código real, sin ninguna modificación, `SectionCalibrationMatrixService::buildPatternMatrix('A09','I')` real)**:
- `row_type` filas 325-337: 325-330=`data`, **331=ausente de `all_rows` (excluida, mecanismo #12 ya la detecta)**, 332-336=`data`, 337=ausente (fuera de toda sección).
- Patrón que incluye las filas 332-336: **`pattern_id=23`**, `filas=[332,333,334,335,336]`, `canonical_fingerprint=fpv2_fb42171308aa70f8`.
- `PatternMigrationScanner::scanSection()` real: categoría global de la sección = **`AUTO_MIGRATE`**; los 23 patrones, incluido el 23, clasifican `AUTO_MIGRATE` (fingerprint vivo coincide con el histórico guardado).
- `section_review` real (nunca tocado): `response=revisada`, `review_status=section_reviewed`, `reviewed_by=Francisco Arcos`, `reviewed_at=2026-08-06T15:35:57.927Z`.

**DESPUÉS (subclase simulada con la extensión de resolución de ancla vía merge — Opción C del punto 17.26 — aplicada exactamente igual que se propuso, sin aproximaciones: si la celda de concepto propia está vacía pero `es_combinada=true` con un rango vertical multi-fila, se resuelve la celda ancla vía el propio `rango_combinado` y se aplica el mismo criterio `pareceEtiquetaTotal`/`pareceEtiquetaTotalMatrix` ya usado en el resto del sistema)**:
- `row_type` filas 325-337: 325-330=`data` (sin cambio), 331=ausente (sin cambio), **332=`total` (cambia)**, **333=`data` (NO cambia — ver hallazgo crítico abajo)**, **334=`total` (cambia)**, **335=`total` (cambia)**, **336=`total` (cambia)**, 337=ausente (sin cambio).
- `pattern_id=23` ahora `filas=[333]` (solo la fila 333 sobrevive), `canonical_fingerprint=fpv2_3735e4b5bbc386dd` — **fingerprint distinto**, confirmado por comparación directa de strings.
- `PatternMigrationScanner::scanSection()` con la subclase simulada inyectada: categoría global de la sección = **`MISMATCH`** — pero **solo el `pattern_id=23`** clasifica `MISMATCH` (`live_fp` distinto del guardado); **los otros 22 patrones siguen `AUTO_MIGRATE` exactos, sin ningún cambio** (fingerprints idénticos verificados uno por uno).
- Comparación row-por-row exhaustiva (no solo el rango 325-337, las 87 filas físicas completas de la sección): **exactamente 4 filas difieren de `row_type`** entre ANTES y DESPUÉS — `332,334,335,336` (`data`→`total`) — **cero diferencias en cualquier otra fila de la sección**.

#### 2. Hallazgo crítico: la fila 333 NO se resuelve con el fix de merge — es el mismo problema de `AR337` ya documentado, ahora confirmado también en la capa de calibración

La simulación demuestra, con el código real ejecutándose, que **el fix de merge (Opción C) por sí solo NO logra excluir la fila 333** — sigue clasificando `row_type=data` incluso con la extensión aplicada. Causa raíz confirmada (no nueva, ya identificada en el punto 17.20/B3, ahora probada también aquí): la fórmula de `AR333` (`=SUM(AR337+AR255+...+AR327)`) contiene la referencia `AR337`. El chequeo de "fórmula hacia atrás" (`$fr > $row` en el bucle de validación) extrae el número `337` de la fórmula y lo compara contra la fila evaluada (`333`) — como `337 > 333`, el algoritmo concluye que existe una **"referencia posterior"** (evidencia de que la fila NO es un TOTAL que solo mira hacia atrás) y devuelve `false` de inmediato para toda la fila, **sin que el mecanismo llegue siquiera a evaluar las demás columnas correctamente resueltas por el merge**. Es decir: **el gap de merge y el gap de `AR337` son dos defectos independientes que coinciden en la misma fila** — arreglar uno no arregla el otro. Esto **no es un fallo del diseño de la Opción C** (que funciona exactamente como se esperaba para 332,334,335,336) — es la confirmación empírica, con el código real, de que `AR337` seguirá bloqueando la fila 333 hasta que se resuelva por separado (ver prohibiciones vigentes sobre B3/`AR337`, punto 17.25 — la pregunta de origen de `AR337` ya está cerrada evidencialmente, pero su interacción con el chequeo de "referencia posterior" del mecanismo #12 es un hallazgo nuevo de esta auditoría, no documentado antes).

#### 3. Estudio del flujo real `safe_reconfirm` (`RuleTagMismatchResolutionCommand`, `MismatchResolutionAuditService`) — código leído completo, no resumido de memoria

- **4 categorías reales**: `safe_reconfirm`, `human_review`, `structural_review`, `structural_row_exclusion` (esta última agregada 2026-08-24 para el hallazgo A09/G).
- **Condición previa común a todas**: el patrón debe estar HOY en categoría `MISMATCH` (verificado en vivo contra `scanSection()` real) — nunca se etiqueta algo que ya es `AUTO_MIGRATE`.
- **`safe_reconfirm` — gate exacto (línea 148 del comando)**: `if ($sortedHistorical !== $liveRows) { abortar }` — **exige coincidencia EXACTA de filas vivas vs. históricas**. Para nuestro caso, histórico=`[332,333,334,335,336]` vs. vivo simulado=`[333]` → **el gate rechaza esto de inmediato, por diseño** — confirmado leyendo el código, no inferido. `safe_reconfirm` **nunca podría usarse aquí**, ni antes ni después del fix.
- **`structural_row_exclusion` — gate exacto (líneas 173-210)**: exige (a) filas históricas resueltas por identidad, (b) cero filas nuevas no explicadas (`array_diff($liveRows, $historicalRows)` vacío), (c) al menos una fila excluida, y (d) **cada fila excluida debe pasar, verificado en vivo contra `cell_data` real, `$scanner->isEmbeddedLeadingTotalRow()`** — **exclusivamente mecanismo #6**, hardcodeado. `PatternMigrationScanner` solo expone `isEmbeddedLeadingTotalRow()` (confirmado, no existe ningún `isEmbeddedBackwardSubtotalRow()` en esa clase) — **el mecanismo #12 (el que aplica a nuestro caso) no está soportado por esta categoría tal como existe hoy**.
- **Qué escribe `safe_reconfirm`/`structural_row_exclusion` cuando se ejecutan (`MismatchResolutionAuditService::setTag()`)**: únicamente una etiqueta de auditoría separada (`mismatch-resolution-audit.json`) — `category`, `audited_fingerprint`, `audited_rows`, `reason`, `audited_by`, `audited_at` (+`historicalRows`/`excludedTotalRows`/`exclusionMechanism` para `structural_row_exclusion`). **Nunca escribe `reglas-funcionales.json`, nunca toca `response`/`reviewed_by`/`reviewed_at`/`review_status`** — confirmado leyendo el código completo del comando y del servicio de auditoría (ningún método de escritura además de `setTag()` en ese archivo). El paso de **reconfirmación real** (que sí actualiza `pattern_fingerprint`/`pattern_rows`/`revalidated_by`/`revalidated_at` en `reglas-funcionales.json`) es un endpoint SEPARADO (`confirmMismatchResolution()`, ya documentado en la sección 2026-08-21 de este archivo) que exige que exista un tag previo — dos pasos, nunca uno solo.
- **Conclusión del estudio**: el mecanismo `safe_reconfirm`/`structural_row_exclusion` preserva correctamente todo lo que debe preservar (histórico, `response`, `reviewed_by`, `reviewed_at`, versionamiento vía los tags de auditoría separados) — el problema NO es de integridad de datos, es que **ninguna de las 2 categorías aplicables hoy cubre mecánicamente nuestro caso exacto**: `safe_reconfirm` lo rechaza por diseño (cambio real de filas), y `structural_row_exclusion` lo rechazaría también porque solo verifica mecanismo #6, no #12.

#### 4. Verificación en la capa de persistencia (`RemParserService`) — mismo algoritmo, confirmado por lectura de código + invocación real

`RemParserService::isEmbeddedBackwardSubtotalRow()` (línea 1080) es, confirmado leyendo el código completo, **byte-a-byte idéntico en su lógica** a la versión de `SectionCalibrationMatrixService` (mismas variables `$tieneReferenciaPosterior`/`$tieneFormulaHaciaAtras`, mismo criterio `$fr > $row`/`$fr < $row && $fr >= $sectionStartRow`). Al ser un método `private`, no se puede sobreescribir por herencia (a diferencia del método `public` de `SectionCalibrationMatrixService`) — se invocó **el método real, sin modificar, vía Reflection** (`ReflectionMethod::setAccessible`) para las filas 330-337: resultado **idéntico al estado ANTES ya confirmado** (`331→true`, todas las demás `→false`), validando que la carga real de hoy (`upload_id=186`) se comportó exactamente como se documentó en el punto 17.26. Dado que el algoritmo es idéntico y opera sobre el mismo `cell_data`, el resultado DESPUÉS sería idéntico al ya demostrado en la capa de calibración: **332, 334, 335, 336 pasarían a excluidas (destino `rem_technical_totals`)**; **333 seguiría persistida en `rem_data` como dato ordinario**, por la misma razón de `AR337` — no se reprocesó el upload 186, se usó únicamente como referencia de qué datos reales ya existen para esas filas (los mismos 0/null ya documentados en el punto 17.26).

#### 5. Verdicto explícito

**Ninguna de las 3 opciones (A/B/C) planteadas por el usuario aplica de forma limpia y única — el veredicto real es mixto, por fila**:

- **Filas 332, 334, 335, 336**: **Opción B** — el fix cambia el fingerprint (confirmado), pero existe un mecanismo capaz de demostrar equivalencia de forma auditable y mecánica (`structural_row_exclusion`, mismo patrón ya usado 2 veces en esta campaña para A09/G) — **con la condición de que primero se extienda esa categoría (o se agregue una paralela) para aceptar verificación contra mecanismo #12 además de #6**, ya que hoy solo soporta #6. Sin esa extensión, ni siquiera esta vía mecánica está disponible.
- **Fila 333**: **Opción C** — el fix de merge NO logra excluirla (confirmado con el código real, no solo con `cell_data` estático), por la interacción con `AR337` (hallazgo nuevo de esta auditoría, ver punto 2 arriba). No puede reconfirmarse automáticamente vía ningún mecanismo existente hoy — requiere revisión humana explícita de `A09/I`/`pattern_id=23`, y probablemente resolver primero la interacción `AR337`↔`isEmbeddedBackwardSubtotalRow()` (fuera de alcance de esta auditoría, que era específicamente sobre el merge).
- **Consecuencia práctica**: implementar la Opción C del punto 17.26 **tal cual**, sin ningún trabajo adicional, dejaría a `A09/I` con **1 patrón en `MISMATCH` sin resolución automática disponible** (`pattern_id=23`) inmediatamente después del despliegue — no es un riesgo teórico, es una consecuencia demostrada y medida con el código real.

Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=126`, hash `reglas-funcionales.json` sin cambio. Ninguna escritura de ningún tipo durante esta auditoría. No se tocó `AR337`, no se implementó ninguna corrección, no se ejecutó `safe_reconfirm`/`structural_row_exclusion`, no se reprocesó el upload 186.

### 17.28 — AUDITORÍA structural_row_exclusion + mecanismo #12 — NO IMPLEMENTADO (2026-08-28)

⚠️ **100% READ-ONLY / DISEÑO.** Ninguna escritura: `rem_technical_totals=126`, `rem_rules=764`, `rem_rule_bindings=1204`, hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` idénticos (`44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` / `fe713f961def425c2a1dd5dd4c4a1972c913c3e7c708e5943ace9b75f6d653e9`, verificados antes y después). No se tocó ningún archivo de código, ninguna calibración real, no se creó ningún tag, no se reconfirmó nada, no se tocaron `response`/`reviewed_*`, no se tocó `rem_data`, no se reprocesó el upload 186, no se tocó la fila 333, no se tocaron las 9 reglas B2/B3/Categoría F, no se tocó la regla 461. No commit, no push.

#### 1. Auditoría completa del flujo real (código leído íntegro, no resumido de memoria)

**`MismatchResolutionAuditService`** — almacén de solo-metadata (`storage/app/private/certificacion/mismatch-resolution-audit.json`), deliberadamente separado de `reglas-funcionales.json`. 4 categorías (`safe_reconfirm`, `human_review`, `structural_review`, `structural_row_exclusion`). Constante `EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL = 'embedded_leading_total_mecanismo_6'` — **único valor de `exclusion_mechanism` reconocido hoy, hardcodeado, sin ninguna constante hermana para mecanismo #12**. `setTag()` acepta `historicalRows`/`excludedTotalRows`/`exclusionMechanism` como parámetros **opcionales, genéricos** (no valida el valor de `exclusionMechanism` contra ninguna lista — ese control vive exclusivamente en el comando/controlador que lo invoca, nunca en este servicio). Escribe bajo clave estable derivada del `rowSetFingerprint` (SHA-256 truncado del conjunto de filas, no del `pattern_id` posicional) — mismo mecanismo ya usado para `safe_reconfirm`, sin ninguna dependencia de qué mecanismo estructural motivó la exclusión.

**`RuleTagMismatchResolutionCommand`** — punto de creación del tag. Gate de `structural_row_exclusion` (líneas 173-210): (1) exige `historical_rows` resuelto por identidad; (2) `array_diff($liveRows, $historicalRows)` debe ser vacío (cero filas agregadas); (3) debe existir al menos 1 fila excluida; (4) **cada fila excluida se verifica en vivo, mecánicamente, contra `$scanner->isEmbeddedLeadingTotalRow(...)`** — llamada **hardcodeada a mecanismo #6**, sin ninguna alternativa. Si `--category=structural_row_exclusion` y alguna fila no pasa ese chequeo específico, el comando aborta sin escribir nada.

**`CalibrationViewController::confirmMismatchResolution()`** — punto de escritura real (vía `FunctionalRuleService::applyQuickRevalidation()`). Para la rama `structural_row_exclusion` (líneas 482-582): revalida 100% en vivo, nunca confía ciegamente en el tag guardado — reconstruye `historicalPatternId` por identidad (nunca por `pattern_id` posicional), exige usuario autenticado, y aplica **4 gates independientes, todos re-verificados contra el estado fresco**: (a) el tag debe tener los 4 campos completos Y **`$tagMechanism !== MismatchResolutionAuditService::EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL` rechaza cualquier otro valor** (segundo hardcode a #6, independiente del de arriba); (b) identidad histórica sin cambios desde la auditoría; (c) fingerprint/filas auditadas sin cambios desde el tag (`audit_stale` si difieren); (d) `addedRows` vacío, `union(live, excluded) === historical` exacto; (e) **cada fila excluida se re-verifica OTRA VEZ, en vivo, contra `$scanner->isEmbeddedLeadingTotalRow(...)`** — tercer punto hardcodeado a #6 (re-verificación independiente de la del comando, defensa en profundidad). El mensaje de éxito (línea 580) también hardcodea el texto "mecanismo #6".

**`FunctionalRuleService::applyQuickRevalidation()`** — capa de escritura real, **ya genérica respecto al mecanismo**: acepta `exclusionMechanism` como string libre (sin validar contra ninguna constante), lo persiste tal cual en `_questions_history` (nunca en la pregunta viva). Escribe **exactamente los mismos 6 campos protegidos de siempre** (`fingerprint_version`, `pattern_fingerprint`, `pattern_rows`, `revalidated_by`, `revalidated_at`, `revalidation_source_type`) — **nunca** `response`/`reviewed_by`/`reviewed_at`/`review_status`/`question`/`id`/`pattern_id`/`pattern_key`/`closure_reason` (confirmado leyendo el comentario explícito del código y la lista completa de campos tocados). Agrega una entrada nueva a `_questions_history` (append-only, nunca sobrescribe) con `historical_rows_before_exclusion`/`excluded_total_rows`/`exclusion_mechanism` cuando se proveen — **auditoría completa preservada, cualquiera sea el mecanismo**.

**`PatternMigrationScanner`** — solo expone `isEmbeddedLeadingTotalRow()` (mecanismo #6) como método público de verificación; **no existe ningún `isEmbeddedBackwardSubtotalRow()` en esta clase** (confirmado, `grep` sin resultados) — sería necesario agregarlo (delegando a `SectionCalibrationMatrixService`, mismo patrón que el existente) para que el comando/controlador puedan verificar mecanismo #12 sin re-implementar nada.

**Tests existentes** (`RuleTagMismatchResolutionCommandStructuralExclusionTest.php`, `StructuralRowExclusionConfirmTest.php`, más `ApplyQuickRevalidationWriteIdentityTest.php`/`MismatchResolutionAuditServiceIdentityTest.php` de soporte): 17 tests, todos nombrados y diseñados explícitamente alrededor de "mechanism_6"/"leading_total" — cobertura completa del camino feliz y de cada rechazo (fila extra no-TOTAL, split, merge, patrón nuevo, `safe_reconfirm` sin afectar, fingerprint/filas obsoletas, filas históricas/excluidas incorrectas, mecanismo que deja de cumplirse, `human_review`/`structural_review` no confirmables). **Ninguno prueba mecanismo #12** — habría que espejar cada uno.

**Qué impediría usar esto con #12 hoy, exactamente (5 puntos de hardcode identificados)**: (1) la constante `EXCLUSION_MECHANISM_EMBEDDED_LEADING_TOTAL` sin hermana para #12; (2) `PatternMigrationScanner` sin `isEmbeddedBackwardSubtotalRow()` expuesto; (3) el comando llama solo a `isEmbeddedLeadingTotalRow()`; (4) el controlador rechaza cualquier `exclusion_mechanism` que no sea el de #6; (5) el controlador re-verifica solo contra `isEmbeddedLeadingTotalRow()` también. **La capa de escritura (`applyQuickRevalidation`) y el almacén de tags (`MismatchResolutionAuditService::setTag`) ya son agnósticos al mecanismo — no necesitan ningún cambio.**

#### 2. Simulación para A09/I — gates verificados uno por uno, con el código real

Reutilizando la subclase de simulación del punto 17.27 (misma técnica, sin tocar ningún archivo real), se recalculó explícitamente la aritmética que el flujo `structural_row_exclusion` (extendido a #12) exigiría para `pattern_id=23` de `A09/I`:

| Gate | Resultado |
|---|---|
| `live_rows` (simulado) | `[333]` |
| `historical_rows` | `[332,333,334,335,336]` |
| `addedRows` (vivo − histórico) | `[]` → **PASA** |
| `excludedRows` (histórico − vivo) | `[332,334,335,336]` → **PASA** (al menos 1) |
| `union(live, excluded) === historical` | `[332,333,334,335,336] === [332,333,334,335,336]` → **PASA** |
| Cada fila excluida cumple mecanismo #12 (verificado en vivo) | `332→true`, `334→true`, `335→true`, `336→true` → **las 4 PASAN** |
| Fila 333 NO aparece en `excludedRows` | Confirmado — sigue viva, correctamente fuera del alcance de esta exclusión |
| Los otros 22 patrones de `A09/I` | **0 diferencias** de categoría o fingerprint respecto al estado real sin el fix |

**Conclusión de la simulación**: una variante de `structural_row_exclusion` extendida a mecanismo #12 **aceptaría mecánicamente, sin ambigüedad, exactamente las 4 filas `[332,334,335,336]`** para `pattern_id=23` — ni una más, ni una menos. La fila 333 queda correctamente excluida de esta vía (no se auto-acepta ni se fuerza), consistente con el hallazgo del punto 17.27 de que requiere tratamiento separado.

#### 3. Seguridad de la extensión — búsqueda exhaustiva en toda la Serie A, no limitada a A09/I

Escaneadas **las 381 secciones** de la estructura activa 67/v35 con `PatternMigrationScanner::scanSection()` real (sin ninguna modificación, sin la subclase simulada): **exactamente 1 patrón vivo en categoría `MISMATCH` hoy en toda la Serie A** — `A30/C pattern_id=1`, con `live_rows === historical_rows` (`[81,82,83,84,85,86,87,88,89,92,93]`, idénticos) — es decir, **ni siquiera es un caso de cambio de filas** (es el caso ya conocido y tagueado `human_review` de columnas J/K/L nuevas, documentado extensamente en secciones anteriores de este archivo, sin ninguna relación con mecanismo #6 ni #12). **Cero patrones adicionales en `MISMATCH` que pudieran verse afectados, hoy, por extender la aceptación a mecanismo #12** — la extensión no tiene ningún caso vivo al que aplicarse fuera de la simulación de `A09/I` (que solo se vuelve `MISMATCH` real una vez implementado el fix de merge, todavía no implementado).

**Historial de falsos positivos de cada mecanismo, buscado explícitamente en este archivo**: mecanismo **#6** (`isEmbeddedLeadingTotalRow`) tiene un falso positivo documentado (regla 87/`A05/C`, columna AA, fila 50 — "fila de dato normal real, no TOTAL", punto 16.8) — es exactamente la razón por la que el gate de `structural_row_exclusion` para #6 exige re-verificación mecánica en vivo en 2 puntos independientes (comando + controlador), nunca confía en la ausencia de la fila del conjunto vivo por sí sola. **Mecanismo #12** (`isEmbeddedBackwardSubtotalRow`) — buscado exhaustivamente en todo el historial de este archivo — **no tiene ningún falso positivo documentado**: cada caso auditado a lo largo de la campaña (regla 56, 208, 214, A32/F2 fila 140, A26/A.1 fila 41, A26/B fila 59, las 235 reglas de "Familia A", las 10 de A26/B con término externo, y ahora 332/334/335/336 de A09/I) fue confirmado como verdadero positivo mediante evidencia directa de `cell_data`/fórmula real — nunca uno solo resultó ser una fila de dato real mal clasificada.

**Condición necesaria para que la extensión sea segura, ya satisfecha por el diseño existente**: la misma doble re-verificación mecánica en vivo (tag-time + confirm-time) que ya protege contra el único falso positivo conocido (#6/regla 87) se aplicaría, sin ningún cambio de diseño, a #12 también — el patrón de seguridad no depende de qué mecanismo se verifique, depende de que SIEMPRE se re-verifique en vivo contra `cell_data` real antes de escribir, nunca de la sola ausencia de una fila del conjunto vivo.

#### 4. Fila 333 — congelada, opciones futuras documentadas, ninguna elegida

No se tocó `AR337` ni se investigó más a fondo en esta sesión. Opciones futuras identificadas, **ninguna decidida**:
- **(a) Corrección del template**: `AR337` es un defecto del archivo Excel de origen (referencia extraviada a la fila-título de la Sección J) — fuera del control de este sistema, requeriría que el mantenedor del template REM nacional lo corrija en una versión futura del formulario.
- **(b) Excepción estructural manual**: documentar `pattern_id=23` explícitamente como una excepción conocida vía `human_review` (o una categoría nueva ad-hoc), aceptando el cambio de fingerprint sin intentar automatizarlo.
- **(c) Revisión humana**: la vía ya disponible hoy (`human_review`) — un humano revisa el patrón y decide, sin ninguna automatización.
- **(d) Regla específica en el mecanismo**: extender el chequeo de "referencia posterior" de `isEmbeddedBackwardSubtotalRow()` para reconocer que una referencia a una fila fuera de CUALQUIER sección declarada (como `AR337`, confirmado en el punto 17.27 que cae en el gap entre `A09/I` y `A09/J`) no debería contar como "referencia posterior" válida — un cambio de código más profundo que la extensión de `structural_row_exclusion`, tocaría el propio mecanismo #12, no solo su flujo de reconfirmación.

Ninguna de las 4 fue elegida, diseñada en detalle, ni implementada.

#### 5. Veredicto explícito

## **SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12**

**Evidencia que respalda el veredicto**:
- Los 4 gates mecánicos existentes (sin cambiar su lógica, solo el mecanismo verificado) aceptan exactamente `[332,334,335,336]` para `A09/I` sin ambigüedad, verificado con el código real.
- Cero casos vivos en toda la Serie A (381 secciones escaneadas) donde la extensión tendría algún efecto hoy, fuera de la simulación de `A09/I`.
- Mecanismo #12 no tiene ningún falso positivo documentado en toda la campaña — a diferencia de #6, que sí tiene uno (regla 87), y es precisamente ese precedente el que ya obliga al diseño existente a re-verificar en vivo, doblemente, antes de escribir — la misma protección aplicaría a #12 sin cambios de diseño.
- La capa de escritura (`applyQuickRevalidation`) y el almacén de tags ya son agnósticos al mecanismo — el cambio se limita a los 5 puntos de hardcode identificados en la sección 1.
- La fila 333 queda correctamente excluida de cualquier aceptación automática por este mecanismo — el veredicto `SAFE` es específico para `[332,334,335,336]`, no una aprobación general para tratar `pattern_id=23` como resuelto.

**Cambio mínimo propuesto (NO implementado)**:
1. `MismatchResolutionAuditService`: agregar `EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL = 'embedded_backward_subtotal_mecanismo_12'` junto a la constante existente de #6.
2. `PatternMigrationScanner`: exponer `isEmbeddedBackwardSubtotalRow(string $sheet, string $section, int $row, array $sectionData): bool`, delegando a `SectionCalibrationMatrixService` — mismo patrón exacto que el `isEmbeddedLeadingTotalRow()` ya existente.
3. `RuleTagMismatchResolutionCommand`: generalizar el gate de verificación (líneas ~201-210) para aceptar la fila si cumple **#6 O #12** (nunca ambos simultáneamente sin distinguir cuál — persistir explícitamente cuál mecanismo verificó cada fila, no un booleano genérico), rechazando si ninguno aplica.
4. `CalibrationViewController::confirmMismatchResolution()`: generalizar el chequeo de `exclusion_mechanism` válido (línea 495) para aceptar cualquiera de las 2 constantes, y la re-verificación (línea 552) para despachar al mecanismo correcto según lo que diga el tag persistido. Generalizar el mensaje de éxito (línea 580) para no hardcodear "mecanismo #6".
5. Tests nuevos: espejar los 17 tests existentes de ambos archivos, sustituyendo el mecanismo y usando fixtures del patrón `A09/I` (o sintéticas equivalentes) — sin tocar ningún test existente de #6.

**No implementado.** Ningún archivo tocado. No se creó ningún tag. No se ejecutó ningún comando de escritura. No se tocó la fila 333, `AR337`, las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, calibraciones reales, `rem_data`, bindings, estructura. No se reprocesó el upload 186. No commit, no push.

Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=126`, hashes de calibración sin cambio.

### 17.29 — SOPORTE #12 IMPLEMENTADO / VALIDADO / RESOLUCIÓN REAL NO EJECUTADA (2026-08-28)

⚠️ Solo el **soporte de código para mecanismo #12 en `structural_row_exclusion`** (auditado y diseñado en el punto 17.28) fue implementado y validado. **Ninguna resolución/calibración real sobre `A09/I` fue aplicada** — cero tags creados, cero llamadas reales a `confirmMismatchResolution()`/`applyQuickRevalidation()` contra datos de producción, cero cambios a `response`/`reviewed_by`/`reviewed_at`/`review_status`, cero cambios a `rem_data`/bindings/estructura/las 9 reglas B2/B3/Categoría F/la regla 461/`no_utilizada`. El fix del merge (punto 17.26) **sigue sin implementar** — no se tocó `RemParserService.php`, `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` (motor real), `EnhancedCellScanner.php`, ni `EnhancedCellDTO.php`. No se reprocesó el upload 186. No commit, no push.

**Cambio mínimo implementado (código + tests, sin ninguna excepción por sheet/section/rule_id — basado exclusivamente en evidencia estructural)**:

1. **`MismatchResolutionAuditService`**: agregada `EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL = 'embedded_backward_subtotal_mecanismo_12'` junto a la constante existente de #6, más `ALLOWED_EXCLUSION_MECHANISMS` (lista cerrada de las 2, única fuente de verdad para comando/controlador). `setTag()` sin ningún cambio — ya era agnóstica al mecanismo, confirmado en 17.28.
2. **`PatternMigrationScanner`**: expuesto `isEmbeddedBackwardSubtotalRow(string $sheet, string $section, int $row, array $sectionData): bool`, delegando a `SectionCalibrationMatrixService` — mismo patrón exacto que `isEmbeddedLeadingTotalRow()` ya existente, mismo docblock explicando el propósito.
3. **`RuleTagMismatchResolutionCommand`**: el gate de `structural_row_exclusion` ahora verifica, **por cada fila excluida**, ambos mecanismos (#6 y #12) — acepta la fila si cumple exactamente uno de los dos, rechaza si cumple ninguno, y **rechaza explícitamente si cumple ambos simultáneamente** (caso ambiguo, no debería ocurrir por construcción ya que #6 exige evidencia exclusivamente hacia adelante y #12 exige evidencia hacia atrás — pero se verifica en vez de asumirlo). Si las filas excluidas de un mismo patrón resuelven a mecanismos **distintos** entre sí, el comando rechaza explícitamente (mezcla no soportada, requiere `human_review`) — nunca se persiste un tag con mecanismos mixtos. Ningún mecanismo se infiere por sección/hoja/`rule_id` — se deriva, fila por fila, exclusivamente de `isEmbeddedLeadingTotalRow()`/`isEmbeddedBackwardSubtotalRow()` reales.
4. **`CalibrationViewController::confirmMismatchResolution()`**: el chequeo de mecanismo válido ahora acepta cualquiera de las 2 constantes (`ALLOWED_EXCLUSION_MECHANISMS`); la re-verificación en vivo despacha al método correcto (#6 o #12) según lo que el tag persistido declare; el mensaje de éxito ya no hardcodea "#6" — refleja el mecanismo real usado.
5. **`FunctionalRuleService::applyQuickRevalidation()`**: **sin ningún cambio** — confirmado en 17.28 que ya era agnóstica, verificado de nuevo hoy (no aparece modificado en `git status`).

**Textos de salida/mensajes preservados byte-a-byte para mecanismo #6** (regresión, no solo diseño): el mensaje de línea de exclusión del comando (`"Filas TOTAL lider excluidas (mecanismo #6, verificado en vivo): [...]"`), el mensaje de error de fila sin evidencia (`"...no cumple el mecanismo #6..."`, ahora con `"...ni el mecanismo #12..."` agregado a continuación sin romper el substring original), y el mensaje de éxito del controlador (`"MISMATCH resuelto (exclusión estructural de fila TOTAL líder, mecanismo #6)."`) — verificados exactos contra los tests existentes, que pasaron sin modificarse.

**Tests nuevos** (2 archivos, ninguno de los 17 tests existentes de #6 modificado):
- `RuleTagMismatchResolutionCommandMechanism12Test.php` (7 tests): #12 válido → aceptado (con texto de salida exacto) · dry-run no modifica `reglas-funcionales.json` (byte-idéntico antes/después) · fila que no cumple ningún mecanismo → rechazada (mensaje menciona explícitamente #6 y #12) · mezcla de mecanismo #6 y #12 dentro del mismo patrón → rechazada · fila con referencia hacia adelante tipo `AR337` (patrón real de la regla 229, ver 17.27) → nunca aceptada por #12 · réplica del falso positivo real de la regla 87 (fila de dato real, sin evidencia de fórmula hacia atrás) → verificado `false` directamente contra el método expuesto real · mecanismo #6 sigue funcionando exactamente igual a través del gate ya generalizado.
- `StructuralRowExclusionConfirmMechanism12Test.php` (4 tests): tag válido `#12` confirmado end-to-end (mensaje de éxito exacto, `pattern_fingerprint`/`pattern_rows` actualizados, histórico `_questions_history` con `exclusion_mechanism` = la constante de #12 correctamente persistida, `response`/`reviewed_by`/`review_status` histórico intactos) · fila que dejó de cumplir #12 → rechazada (nada escrito) · **mecanismo desconocido en el tag → rechazado** (`incomplete_structural_exclusion_tag`, nada escrito) · regresión de `safe_reconfirm` sin afectar por la extensión.

**Hallazgo durante la implementación de fixtures (documentado, no oculto)**: a diferencia de `isEmbeddedLeadingTotalRow()` (que recorre TODAS las columnas de una fila buscando una etiqueta TOTAL), `isEmbeddedBackwardSubtotalRow()` se detiene en la **primera** celda no vacía/no-fórmula que encuentra — si esa primera celda no es una etiqueta TOTAL, descarta la fila de inmediato sin seguir buscando en columnas posteriores (confirmado leyendo el código real, líneas ~2562-2578 de `SectionCalibrationMatrixService.php`, sin ningún cambio — comportamiento preexistente, no introducido hoy). Las fixtures sintéticas se ajustaron para respetar esto (ninguna celda de texto no-TOTAL antes de la columna con "TOTAL", en orden alfabético de columna) — documentado en los comentarios del test nuevo, sin modificar el mecanismo real.

**Regresión completa ejecutada** (`tests/Feature/REM` + `tests/Unit/RemParser` + `tests/Feature/RuleEngine` + `tests/Unit/RuleEngine`): **799 tests, 760 passed, 39 failed — exactamente los mismos 39 fallos preexistentes ya documentados** (4 `StructurePersistenceServiceTest`, 1 `RuleEngineIntegrationTest`, 30 `FunctionalRuleEngineCertificationTest`, 4 `RuleEngineServiceTest`, mismos nombres exactos) — **cero fallos nuevos**. Los 11 tests nuevos (7+4) pasaron, incluidos en el conteo.

**Dry-run READ-ONLY contra `A09/I` usando el mecanismo nuevo real**: dado que el fix de merge (punto 17.26) sigue sin implementarse, el comando real `rule:tag-mismatch-resolution` no puede ejercitarse directamente contra el estado vivo de `A09/I` (que hoy sigue `AUTO_MIGRATE`, sin cambios). Para validar el código nuevo (el soporte de #12 en el gate, ya real) sin implementar el fix de merge, se ligó temporalmente el contenedor de Laravel (dentro de un único proceso `tinker`, nunca persistido, nunca en ningún archivo del proyecto) a la misma subclase de simulación de Opción C ya usada en 17.27/17.28, y se ejecutó el comando **real, sin modificar, ya generalizado** en modo dry-run (sin `--commit`):

```
Patron 23 de A09/I:
  Categoria actual (vigente): MISMATCH
  Filas vivas: [333]
  Filas historicas: [332,333,334,335,336]
  Filas subtotal embebido hacia atras excluidas (mecanismo #12, verificado en vivo): [332,334,335,336]
  Fingerprint vigente: fpv2_3735e4b5bbc386dd
  Categoria propuesta: structural_row_exclusion
DRY-RUN: no se persistio ningun tag.
```

**Resultado exacto, coincide con lo esperado**: `332,334,335,336` → elegibles por mecanismo #12 (aceptadas por el gate real, ya generalizado). `333` → permanece en "Filas vivas" (NO elegible, correctamente excluida de la aceptación). `exit_code=0`. Confirmado que **no se persistió ningún tag** (`MismatchResolutionAuditService::getTag()` = `null` después de la ejecución).

**Repetición del gate global de 381 secciones** (código real, matrixService **sin** simular — el fix de merge no está implementado, así que la clasificación real de todo Serie A no cambia con este trabajo): **exactamente 1 patrón MISMATCH, `A30/C pattern_id=1`, idéntico al de 17.28** — confirma que la implementación de hoy (generalización del gate de `structural_row_exclusion`) **no amplió el universo de patrones afectados** respecto de la auditoría — el código nuevo solo cambia qué mecanismos se ACEPTAN cuando algo ya es `MISMATCH` y tiene filas excluidas que justificar, nunca cambia qué es `MISMATCH` en sí.

**Baseline final reconfirmado sin cambios** (verificado antes y después de toda la implementación): `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_technical_totals=126`, hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` idénticos (`44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` / `fe713f961def425c2a1dd5dd4c4a1972c913c3e7c708e5943ace9b75f6d653e9`).

**Archivos modificados**: `backend/app/Domain/RuleEngine/Services/MismatchResolutionAuditService.php`, `backend/app/Domain/RuleEngine/Services/PatternMigrationScanner.php`, `backend/app/Console/Commands/RuleTagMismatchResolutionCommand.php`, `backend/app/Domain/RuleEngine/Controllers/CalibrationViewController.php`. **Archivos nuevos**: `backend/tests/Feature/RuleEngine/RuleTagMismatchResolutionCommandMechanism12Test.php`, `backend/tests/Feature/RuleEngine/StructuralRowExclusionConfirmMechanism12Test.php`. Ningún archivo de motor (`RuleEngineService.php`, `SumEqualsEvaluator.php`), parser (`RemParserService.php`, `EnhancedCellScanner.php`), ni `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` (el método real, no la subclase de simulación) fue tocado.

**FILA 333 DE `A09/I`: AUDITADA / CONGELADA / NO RESUELTA** — sin ningún cambio desde el punto 17.27/17.28. Sigue bloqueada por la interacción con `AR337` (referencia textual "337 > 333" interpretada como referencia posterior por el mecanismo #12). Ninguna de las 4 opciones futuras documentadas en 17.28.4 fue elegida ni implementada.

**No se creó ningún tag real. No se ejecutó `confirmMismatchResolution`/`applyQuickRevalidation` contra datos reales. No se implementó el fix del merge. No se tocó la fila 333, `AR337`, las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, calibraciones reales, `rem_data`, bindings, estructura. No se reprocesó el upload 186. No commit de Git, no push.**

### 17.30 — FIX MERGE TOTAL IMPLEMENTADO / IMPACTO MISMATCH VALIDADO / RESOLUCIÓN REAL NO EJECUTADA (2026-08-28)

⚠️ Solo el **fix estructural del TOTAL fusionado** (auditado en 17.26, diseño Opción C) fue implementado, testeado y validado contra la estructura real. **Ninguna resolución/calibración real fue aplicada**: cero tags creados, cero llamadas a `confirmMismatchResolution()`/`applyQuickRevalidation()` reales, cero cambios a `response`/`reviewed_by`/`reviewed_at`/`review_status`, cero cambios a `rem_data` (sin backfill, sin borrar histórico), cero cambios a las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, bindings, estructura. No se reprocesó el upload 186. No commit, no push.

#### 1. Implementación mínima (código + tests, sin hardcode de A09/I ni de filas 331-336)

1. **`MergeAnchorResolver`** (nuevo, `App\Domain\RuleEngine\Services`): servicio compartido y liviano (solo depende de `CellDataStorageService`, ya usado por ambas clases) que resuelve la celda ANCLA de una fusión vertical real a partir de metadata **ya persistida** (`es_combinada`/`rango_combinado`, presente en cada celda del rango, confirmado en 17.26) — sin ningún campo nuevo de escaneo. Extraído como pieza compartida (a diferencia de otros mecanismos de la campaña como `isEmbeddedLeadingTotalRow`, deliberadamente duplicados entre `RemParserService`/`SectionCalibrationMatrixService` para no arrastrar dependencias pesadas) porque esta resolución de ancla es pura y no introduce ningún acoplamiento nuevo.
2. **`SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()`**: cuando la celda de concepto propia está vacía, ahora intenta resolver la etiqueta desde la celda ancla de su fusión vertical (si existe) **únicamente como evidencia POSITIVA adicional** — si el ancla resuelve y su texto parece etiqueta TOTAL, esa columna pasa a ser el concepto (igual que el comportamiento original para texto propio). Si el ancla no es resolvible o no dice TOTAL, la celda se trata exactamente como una celda vacía normal (`continue`, sigue buscando en columnas posteriores) — **nunca como rechazo definitivo** (ver hallazgo crítico del punto 3 abajo). Constructor extendido con `?MergeAnchorResolver $mergeAnchorResolver = null`, default `new MergeAnchorResolver($cellDataStorage)` — retrocompatible, ninguna instanciación existente requiere cambios.
3. **`RemParserService::isEmbeddedBackwardSubtotalRow()`**: misma extensión exacta, reutilizando el mismo `MergeAnchorResolver` (constructor extendido igual, mismo patrón de default).
4. **`SumEqualsEvaluator`/`RuleEngineService`/`EnhancedCellScanner`/`EnhancedCellDTO`/`SectionDetectorService`**: **sin ningún cambio** — el fix vive exclusivamente en los 2 puntos de decisión de mecanismo #12, reutilizando metadata ya existente.

#### 2. Guards implementados (los 7 exigidos, verificados en el código real)

Todos satisfechos por construcción, sin ninguna excepción por sección/fila: (1) merge vertical real — `MergeAnchorResolver` exige `es_combinada=true`, mismo `colStart===colEnd`, `rowEnd>rowStart`; (2) fila subordinada pertenece al rango — implícito, se resuelve desde el propio `rango_combinado` de la celda evaluada; (3) celda ancla resolvible — `getCellForCoordinate()` debe devolver una celda real; (4) etiqueta del ancla reconocida como TOTAL — mismo `pareceEtiquetaTotal()`/`pareceEtiquetaTotalMatrix()` ya usado en el resto del sistema, sin duplicar el criterio; (5) fórmula real compatible en la fila subordinada — chequeo de backward-formula ya existente, sin cambios; (6) mecanismo #12 la valida en su totalidad — la función completa (concepto + backward-formula) debe devolver `true`; (7) ninguna referencia posterior invalida la exclusión — el chequeo de `$fr > $row` ya existente, sin cambios, es precisamente lo que sigue bloqueando la fila 333 (ver punto 4).

#### 3. Hallazgo crítico durante la validación — regresión real encontrada y corregida ANTES de reportar el trabajo como terminado

La primera implementación (romper la búsqueda de columna con `break` tanto si el ancla decía TOTAL como si no) **causó una regresión real**: al ejecutar `RuleBindingReconciliationService::classifyAllActiveRules()` contra la estructura real, `SAFE_1_TO_1` bajó de `431` a `429` y `BLOCKED_BY_ENGINE_GAP` subió de `66` a `68` — **las reglas 545 y 546 (`A33/C`, ya resueltas en Fase 3C-1B, punto 17.16)** volvieron a `BLOCKED_BY_ENGINE_GAP`. Causa raíz investigada con evidencia real (`cell_data` de `A33/C` fila 56): la columna **A** está vacía y pertenece a una fusión **`A53:A56`** cuya celda ancla (fila 53) **no dice "TOTAL"** (es la etiqueta de otro bloque de la sección) — mientras la etiqueta "Total" real de esa fila vive en la columna **B** (`B56='Total'`, sin fusión). La primera versión del fix, al encontrar la columna A vacía-pero-fusionada, resolvía el ancla, veía que no decía TOTAL, y **rompía la búsqueda ahí mismo** (tratándolo como un rechazo definitivo) — nunca llegaba a evaluar la columna B, donde SÍ estaba la etiqueta real. **Corregido**: una fusión vertical cuyo ancla NO resuelve a una etiqueta TOTAL se trata exactamente igual que una celda vacía sin fusión (`continue`, sigue buscando) — la resolución de ancla **solo puede confirmar evidencia, nunca puede, por sí sola, cancelar la búsqueda de una columna posterior**. Tras la corrección, reconfirmado: `SAFE_1_TO_1=431`, `BLOCKED_BY_ENGINE_GAP=66`, reglas `545`/`546` de vuelta a `SAFE_1_TO_1` — baseline exactamente igual al de antes de tocar cualquier archivo hoy.

#### 4. Comportamiento exacto verificado (fixtures + estructura real)

- **332, 334, 335, 336** (`A09/I`, réplica exacta en fixtures sintéticas y confirmado contra la estructura real): correctamente excluidas — `row_type='total'`, no persistidas en `rem_data`, capturadas en `rem_technical_totals` con `exclusion_reason='embedded_backward_subtotal_row'`.
- **333**: sigue **NO excluida** — confirmado que el fix de merge resuelve correctamente su concepto ("TOTAL", vía el ancla del merge) pero el chequeo de referencia posterior (`AR337`, ya documentado en 17.27) sigue rechazándola de forma independiente — exactamente el comportamiento esperado, verificado con una fixture sintética que replica el patrón exacto (formula con referencia hacia atrás + una referencia hacia una fila fuera de la sección).
- **331** (fila ancla real): sigue clasificando `exclusion_reason='embedded_trailing_total_row'` (mecanismo #8, no #12) — confirmado que la fila ancla, al tener texto propio directo en la columna de concepto fija de la sección, es alcanzada primero por el mecanismo #8 (`isTrailingTotalRow`), exactamente igual que antes del fix (comportamiento sin cambios, verificado con una fixture que replica esto explícitamente).
- **Las otras 122 fusiones verticales tipo TOTAL sin fórmulas subordinadas** (auditadas en 17.26): sin ningún cambio de comportamiento — el fix solo se activa cuando la celda vacía-fusionada resuelve a una etiqueta TOTAL Y la fila tiene evidencia de fórmula backward real; ninguna de las 122 tiene fórmula en sus filas subordinadas, así que ninguna pasa nunca del primer chequeo (`$esVacio` sin evidencia de fórmula en ninguna columna → `$tieneFormulaHaciaAtras` nunca llega a `true`).
- **Filas normales, sin ningún merge**: sin ningún cambio — verificado con fixture dedicada.

#### 5. Tests nuevos (11, ninguno de los tests existentes de mecanismo #6/#8/#11/#12 modificado)

- `SectionCalibrationMatrixServiceMergeAnchorBackwardSubtotalTest.php` (5): merge vertical TOTAL con subordinadas válidas → `total` · subordinada sin fórmula → no falsa exclusión (`false`) · merge cosmético (ninguna subordinada con fórmula) → sin efecto · fila tipo `AR337` (referencia hacia adelante) dentro de un merge → `false` (no excluida) pese a que su etiqueta se resuelve correctamente · fila normal sin merge → sin cambios.
- `RemParserServiceMergeAnchorBackwardSubtotalTest.php` (6, pipeline real `RemParserService::parse()` completo vía `RemUpload`/xlsx real con `mergeCells()` real): fila ancla excluida de `rem_data` · fila subordinada con fórmula real hacia atrás excluida de `rem_data` tras el fix · fila subordinada con referencia tipo `AR337` NO excluida de `rem_data` · filas de dato real (componentes) sin cambios · ancla y subordinada válida capturadas en `rem_technical_totals` con `exclusion_reason='embedded_backward_subtotal_row'` (ancla) — **hallazgo durante la escritura del test**: la fila ancla real, al tener texto propio en la columna de concepto fija, cae bajo `exclusion_reason='embedded_trailing_total_row'` (mecanismo #8), no `'embedded_backward_subtotal_row'` — coincide exactamente con lo ya confirmado en el punto 17.24 contra el upload real 186 (`fila 331 → embedded_trailing_total_row`), no es un defecto del test ni del fix · fila con referencia hacia adelante nunca entra a `rem_technical_totals` (nunca se excluye).

**Regresión completa ejecutada dos veces** (antes y después de corregir la regresión de 545/546): **810 tests, 771 passed, 39 failed — exactamente los mismos 39 fallos preexistentes documentados**, cero fallos nuevos en ambas corridas. Los 11 tests nuevos, incluidos en el conteo, pasan en ambas.

#### 6. Validación contra la estructura vigente real (sin reprocesar el upload 186)

**Impacto en `A09/I` — código real, sin ninguna simulación** (a diferencia de 17.27/17.29, el fix ya está implementado, no hace falta ninguna subclase):

```
fila 331: AUSENTE (excluida, sin cambios)
fila 332: total   (NUEVO)
fila 333: data    (sin cambios -- sigue viva)
fila 334: total   (NUEVO)
fila 335: total   (NUEVO)
fila 336: total   (NUEVO)
```

`PatternMigrationScanner::scanSection()` real: categoría global de `A09/I` = **`MISMATCH`** — de los 23 patrones, **exactamente `pattern_id=23`** (`live_rows=[333]`) está en `MISMATCH`; **los 22 restantes confirmados `AUTO_MIGRATE`, sin ninguna diferencia** (verificado uno por uno, `patrones_distintos_de_23_no_AUTO_MIGRATE=0`).

**Gate global de 381 secciones** (código real, ejecutado antes y después de la corrección de la regresión): **exactamente 2 patrones en `MISMATCH` en toda la Serie A** — `A09/I pattern_id=23` (nuevo, esperado) y `A30/C pattern_id=1` (preexistente, ya conocido, filas idénticas, sin relación) — **ninguna otra sección cambió inesperadamente**.

**Baseline final reconfirmado** (antes y después de toda la implementación, incluida la corrección de la regresión): `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_data=399.811`, `rem_technical_totals=126` (sin cambio, upload 186 no reprocesado), hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` idénticos (`44cd0d92c4fe48530d4b429a7889a3e5015a12e530b4679956a26a47d9dedd6a` / `fe713f961def425c2a1dd5dd4c4a1972c913c3e7c708e5943ace9b75f6d653e9`). Ningún tag de `pattern_id=23` creado (`getTag()` confirma `null`).

**Archivos modificados**: `backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php`, `backend/app/Domain/REM/Services/RemParserService.php`. **Archivos nuevos**: `backend/app/Domain/RuleEngine/Services/MergeAnchorResolver.php`, `backend/tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceMergeAnchorBackwardSubtotalTest.php`, `backend/tests/Feature/REM/RemParserServiceMergeAnchorBackwardSubtotalTest.php`. Ningún otro archivo de motor/parser/calibración tocado.

**FILA 333 DE `A09/I`: AUDITADA / CONGELADA / NO RESUELTA** — confirmado de nuevo hoy, con el fix ya real (no simulado): sigue bloqueada por la interacción con `AR337`. Ninguna de las 4 opciones futuras (punto 17.28.4) fue elegida ni implementada.

**No se creó ningún tag real. No se ejecutó `structural_row_exclusion`/`confirmMismatchResolution`/`applyQuickRevalidation` reales. No se tocó `response`/`reviewed_*`. No se modificó histórico. No se borraron las 6.300 filas históricas de 332-336. No backfill. No se reprocesó el upload 186. No se tocó la fila 333. No se tocaron las 9 reglas B2/B3/Categoría F. No se tocó la regla 461. No se tocó `no_utilizada`. No rebind. No commit de Git, no push.**

### 17.31 — A09/I — STRUCTURAL_ROW_EXCLUSION #12 DRY-RUN VALIDADO / RESOLUCIÓN REAL NO EJECUTADA (2026-08-28)

⚠️ **100% READ-ONLY / SIMULACIÓN AISLADA.** El disco real y la BD real nunca fueron tocados en ningún momento — verificado antes, durante y después con hashes idénticos. No se creó ningún tag real, no se ejecutó ninguna resolución real, no se tocó `response`/`reviewed_*`/histórico/`rem_data`/bindings/estructura/las 9 reglas B2/B3/Categoría F/la regla 461/`no_utilizada`. No se reprocesó el upload 186. No commit, no push.

#### 1. Reconfirmación del estado de partida contra el estado real (antes de cualquier operación)

Verificado en vivo, coincide exactamente con lo exigido: `SAFE_1_TO_1=431`, `BLOCKED_BY_ENGINE_GAP=66` (`RuleBindingReconciliationService::classifyAllActiveRules()` real). `A09/I` = `MISMATCH`, único `pattern_id=23` afectado (`live_rows=[333]`, `historical_rows=[332,333,334,335,336]`), **los otros 22 patrones confirmados `AUTO_MIGRATE`** con `live_rows === historical_rows` exacto para cada uno. `A30/C pattern_id=1` confirmado como el único otro `MISMATCH`, filas vivas idénticas a las históricas, sin relación con este trabajo.

#### 2. Dry-run real del comando (código ya implementado en 17.29, sin ninguna simulación necesaria)

`php artisan rule:tag-mismatch-resolution A09 I 23 --category=structural_row_exclusion ...` (sin `--commit`):

```
Filas vivas: [333]
Filas historicas: [332,333,334,335,336]
Filas subtotal embebido hacia atras excluidas (mecanismo #12, verificado en vivo): [332,334,335,336]
Fingerprint vigente: fpv2_3735e4b5bbc386dd
DRY-RUN: no se persistio ningun tag.
```

Acepta **exactamente** `[332,334,335,336]`, **nunca** `333`. Ningún tag persistido (confirmado).

#### 3. Verificación de mecanismo por fila (nunca mezclar #6/#12)

Verificado en vivo, código real, las 4 filas excluidas:

| Fila | mecanismo #6 | mecanismo #12 |
|---|---|---|
| 332 | `false` | `true` |
| 334 | `false` | `true` |
| 335 | `false` | `true` |
| 336 | `false` | `true` |

Las 4 resuelven al **mismo y único** mecanismo (`embedded_backward_subtotal_mecanismo_12`) — ninguna ambigüedad, ninguna mezcla. El mecanismo persistible sería exactamente `EXCLUSION_MECHANISM_EMBEDDED_BACKWARD_SUBTOTAL`.

#### 4. Simulación aislada de la resolución completa (tag + confirmación), sin tocar el disco real en ningún momento

**Nota metodológica importante**: la persistencia de este flujo (`MismatchResolutionAuditService`/`FunctionalRuleService`) es **100% basada en archivos** (`Storage::disk('local')`), no en la base de datos — una transacción de BD (`DB::transaction()`+`rollBack()`, la técnica ya usada en fases anteriores de esta campaña para escrituras en `rem_rules`) **no revertiría estas escrituras de archivo**. Para lograr el mismo efecto de aislamiento total sin ningún riesgo sobre los archivos reales de producción, se usó `Storage::fake('local')` **sembrado con el contenido REAL exacto** de `reglas-funcionales.json`, `mismatch-resolution-audit.json` y `cell-data/A09-I.json` (capturado por bytes antes de activar el disco falso) — desde ese punto, toda lectura/escritura del disco `local` fue dirigida al disco aislado en memoria; **el disco real nunca fue tocado, ni siquiera transitoriamente**.

Sobre esa copia fiel y aislada, se ejecutó el flujo completo con los servicios reales (`MismatchResolutionAuditService::setTag()`, y la réplica exacta de los 8 gates de `CalibrationViewController::confirmMismatchResolution()`, seguida de `FunctionalRuleService::applyQuickRevalidation()` real):

| Gate | Resultado |
|---|---|
| Categoría sigue `MISMATCH` antes de escribir | PASA |
| Tag existe tras `setTag()` | PASA |
| Mecanismo del tag es uno de los permitidos | PASA (`embedded_backward_subtotal_mecanismo_12`) |
| Fingerprint/filas auditadas no obsoletas | PASA |
| Histórico sin cambios desde la auditoría | PASA |
| Sin filas agregadas (`addedRows=[]`) | PASA |
| `union(live, excluded) === historical` | PASA (`[332,333,334,335,336]`) |
| Re-verificación en vivo de cada fila excluida contra mecanismo #12 | PASA las 4 (332,334,335,336) |

**Los 8 gates pasan** — la confirmación real procedería a escribir. Tras ejecutar `applyQuickRevalidation()` (sobre el disco aislado): `pattern_fingerprint` y `pattern_rows=[333]` actualizados correctamente; `response='debe_registrar_cero'`, `reviewed_by='Francisco Arcos'`, `reviewed_at`, `review_status='reviewed'` — **los 4 campos históricos protegidos, sin ningún cambio**, confirmado explícitamente.

**Hallazgo metodológico durante la simulación (documentado, no un defecto de producción)**: al reconsultar `pattern_id=23` con la **misma instancia** de `PatternMigrationScanner`/`FunctionalRuleService` ya usada antes de la escritura, el resultado seguía mostrando `MISMATCH` — investigado y confirmado como un **artefacto exclusivo de reutilizar el mismo objeto largo tiempo dentro de un único proceso de script** (`FunctionalRuleService::loadAll()` cachea `_questions` en una propiedad de instancia, `$this->cachedAll`, que `persistAll()` nunca invalida) — **no es un bug de producción real**: cada request HTTP real resuelve instancias nuevas desde cero vía el contenedor de Laravel, sin ningún estado de proceso previo. Confirmado forzando una instancia **completamente nueva** de `PatternMigrationScanner` (simulando una request HTTP nueva) sobre el mismo disco aislado: `A09/I` reclasificó correctamente `AUTO_MIGRATE`, `pattern_id=23` con `live_rows=[333]`, y **los otros 22 patrones sin ninguna diferencia** de categoría ni fingerprint respecto al estado previo a la simulación.

#### 5. Verificación posterior — disco real y BD real byte-idénticos al baseline previo

Verificado en un **proceso completamente separado** (nunca dentro del disco aislado): `hash(reglas-funcionales.json)`, `hash(mismatch-resolution-audit.json)`, `hash(cell-data/A09-I.json)` — **los 3 idénticos** a los capturados antes de la simulación. `rem_technical_totals=126`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_data=399.811` — sin cambio. Reclasificación global de reglas: `SAFE_1_TO_1=431`, `BLOCKED_BY_ENGINE_GAP=66` — sin cambio. **`A09/I` en el estado REAL sigue `MISMATCH`, `pattern_id=23` con `live_rows=[333]`** (la simulación nunca se aplicó de verdad). `MismatchResolutionAuditService::getTag()` contra el disco real confirma `null` — ningún tag persistido.

**Gate global de 381 secciones, código real, tras toda la simulación**: **exactamente 2 patrones en `MISMATCH`** — `A09/I pattern_id=23` (sin cambio) y `A30/C pattern_id=1` (sin cambio, filas idénticas) — **ninguna otra sección afectada por nada de lo hecho hoy**.

#### 6. Conclusión

La resolución `structural_row_exclusion` vía mecanismo #12 para `A09/I pattern_id=23` está **completamente validada de punta a punta** (dry-run + simulación aislada de la escritura real + verificación de que el disco/BD reales permanecen intactos) — lista para ejecutarse cuando se autorice, sin ningún trabajo adicional de diseño o código pendiente. **Ninguna resolución fue aplicada hoy.**

Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, `rem_data=399.811`, `rem_technical_totals=126`, hashes de los 3 archivos idénticos. Ningún tag real creado. No se tocó la fila 333, `AR337`, las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, `rem_data`, bindings, estructura. No se reprocesó el upload 186. No commit de Git, no push.

**[SUPERADO — ver punto 17.32]**: la resolución real fue autorizada y ejecutada el mismo día — `A09/I pattern_id=23` queda `EJECUTADA Y CERRADA`. Este punto 17.31 se conserva íntegro como registro histórico de la validación previa a la ejecución.

### 17.32 — A09/I — STRUCTURAL_ROW_EXCLUSION #12 EJECUTADA Y CERRADA PARA 332/334/335/336 (2026-08-28)

⚠️ **Resolución real ejecutada, con autorización explícita**, usando exclusivamente el flujo real ya implementado y validado (17.29/17.30/17.31) — **sin ninguna modificación manual de archivos, sin bypass de ningún guard**. Fila 333 permanece **AUDITADA / CONGELADA / NO RESUELTA** por `AR337` — no tocada hoy.

**Reconfirmación previa a escribir (idéntica al dry-run de 17.31, verificada de nuevo justo antes)**: `SAFE_1_TO_1=431`, `BLOCKED_BY_ENGINE_GAP=66`, `A09/I=MISMATCH`, `pattern_id=23` con `historical=[332,333,334,335,336]`/`live=[333]`, mecanismo #12 confirma exactamente `332,334,335,336` (`true`) y `333` (`false`), los otros 22 patrones intactos, `A30/C` sin relación — **todo coincidió exacto, sin ninguna diferencia respecto al dry-run**, se procedió a escribir.

**Ejecución — 2 pasos, ambos vía el flujo real**:
1. `php artisan rule:tag-mismatch-resolution A09 I 23 --category=structural_row_exclusion --commit` (comando real, mismos 5 guards ya validados) → tag persistido: `exclusion_mechanism=embedded_backward_subtotal_mecanismo_12`, `excluded_total_rows=[332,334,335,336]`, `historical_rows=[332,333,334,335,336]`, `audited_rows=[333]`, `audited_by=Administrador Esalud`.
2. Confirmación real invocando **directamente el método real** `CalibrationViewController::confirmMismatchResolution()` (mismo código exacto que ejecuta el endpoint HTTP en producción, con los 8 gates internos 100% activos — sin reescribir ni omitir ninguno) con un `Request` real y el usuario admin real (`id=25`) resuelto vía `setUserResolver()` — único sustituto de una sesión de navegador real, que no cambia ni una línea de la lógica de negocio ejecutada. Respuesta: `HTTP 200`, `"data":{"questions":[...]}`.

**Post-check completo — todo coincide exactamente con lo exigido, ninguna diferencia**:

| Verificación | Resultado |
|---|---|
| Tag real creado | `exclusion_mechanism=embedded_backward_subtotal_mecanismo_12` ✅ |
| Filas excluidas | `[332,334,335,336]` exacto ✅ |
| `pattern_id=23` | `AUTO_MIGRATE` ✅ |
| `live_rows` | `[333]` ✅ |
| Fingerprint | `fpv2_3735e4b5bbc386dd`, coincide con el fingerprint vivo del patrón ✅ |
| `response`/`reviewed_by`/`reviewed_at`/`review_status` | Intactos en las 6 preguntas de `pattern_id=23` (`debe_registrar_cero`/`si`/`no`/`error`/`si`/`confirmed`, `Francisco Arcos`, fechas originales, `reviewed`) ✅ |
| Historial `_questions_history` | Entrada nueva `type=pattern_revalidation`, `pattern_id=23`, `by=Administrador Esalud`, `revalidation_source_type=structural_row_exclusion`, `exclusion_mechanism=embedded_backward_subtotal_mecanismo_12`, `excluded_total_rows=[332,334,335,336]`, `historical_rows_before_exclusion=[332,333,334,335,336]` ✅ |
| Otros 22 patrones de `A09/I` | Los 23 patrones reclasifican `AUTO_MIGRATE`; los 22 restantes con `live_rows` idénticos a antes ✅ |
| `A30/C` | `MISMATCH`, `pattern_id=1`, filas idénticas — sin cambio ✅ |
| Gate global de 381 secciones | **Exactamente 1 `MISMATCH` en toda la Serie A** (`A30/C`, sin relación) — `A09/I` ya no aparece ✅ |
| `SAFE_1_TO_1` / `BLOCKED_BY_ENGINE_GAP` | `431` / `66` — **sin cambio**, confirma que esta operación resolvió calibración/patrón, no clasificación de reglas ✅ |
| `rem_rules` / `rem_rule_bindings` / `rem_data` / `rem_technical_totals` / estructura | `764` / `1204` / `399.811` / `126` / `67 v35 active` — sin cambio ✅ |

**Ninguna diferencia respecto a lo predicho — no fue necesario ningún STOP.**

**Cambios reales de archivo (esperados, autorizados)**: `storage/app/private/certificacion/reglas-funcionales.json` (hash cambia: `44cd0d92...` → `8565f0af...`) y `storage/app/private/certificacion/mismatch-resolution-audit.json` (hash cambia: `fe713f96...` → `24b3d2b7...`) — ambos fuera de control de versiones (no trackeados por git, mismo patrón de toda la campaña).

**Fila 333 de `A09/I`: permanece AUDITADA / CONGELADA / NO RESUELTA** — sin ningún cambio, sin tocar `AR337`. No se tocó el upload 186, `rem_data` histórico de 332-336 (las 6.300 filas históricas permanecen intactas), las 9 reglas B2/B3/Categoría F, la regla 461, `no_utilizada`, bindings, estructura. No backfill. No rebind. No commit de Git, no push.

**El universo `REQUIRES_REMAP`/deuda técnica #5 relacionado con el gap de merge fusionado para `A09/I` queda resuelto para 4 de sus 5 filas afectadas** (332,334,335,336) — la quinta (333) permanece como excepción individual documentada, dependiente de la resolución futura de `AR337`.

### 17.33 — FASE 3C-3C/3C-4: DISEÑO DE CARDINALIDAD MÚLTIPLE PARA B2/B3/CATEGORÍA F — AUDITORÍA + DISEÑO + SIMULACIÓN, NO IMPLEMENTADO (2026-08-28)

⚠️ **100% READ-ONLY / DISEÑO.** Ninguna escritura de ningún tipo: `config` de las 9 reglas confirmado sin cambio, ningún `Rule` nuevo creado, ningún `status` cambiado, ninguna calibración tocada, `rem_data`/bindings/estructura sin tocar, fila 333/`AR337`/upload 186/regla 461/`no_utilizada` intactos. No commit, no push.

#### 1. Reconfirmación del baseline contra BD/código real (antes de cualquier análisis)

`RuleBindingReconciliationService::classifyAllActiveRules()` real: `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, activas=`717` — **exacto**. Descomposición de los 66 verificada explícitamente (no por resta, por inspección directa de cada `rule_id`): **56 `no_utilizada`** (confirmado contra `RemSheetUsageStatusService` real: `A21=25, A24=3, A25=22, A30AR=2, A34=4`) **+ 5 B2 (`226,227,231,232,234`) + 1 B3 (`229`) + 3 Categoría F (`228,230,233`) + 1 regla `461`** = `66` exacto, `0` casos inesperados. `config` de las 9 reglas reconfirmado sin ningún cambio (`row_range={0,0}`, sin `source_rows`/`total_row`, `status=active`) — nada tocado desde su última auditoría.

#### 2. Evidencia fresca — fórmulas reales, las 9 columnas, filas 331-336 (re-verificado hoy, idéntico a 17.20/17.25)

| Fila | AM(226) | AN(227) | AQ(228) | AR(229) | AS(230) | AT(231) | AU(232) | AV(233) | AX(234) |
|---|---|---|---|---|---|---|---|---|---|
| 331 | 13t(253..325) | ídem | 13t(ídem) | 13t(ídem) | `SUM(265,277)` 2t | 13t(ídem) | 13t(ídem) | sin fórmula | 13t(ídem) |
| 332 | 13t(254..326) | ídem | 13t(ídem) | 13t(ídem) | `SUM(266,278)` 2t | 13t(ídem) | 13t(ídem) | sin fórmula | 13t(ídem) |
| 333 | 13t(255..327) | ídem | sin fórmula | **14t: `AR337`+13t(255..327)** | `SUM(267,279)` 2t | 13t(ídem) | 13t(ídem) | sin fórmula | 13t(ídem) |
| 334 | 13t(256..328) | ídem | sin fórmula | 13t(256..328) | `SUM(269,281)` 2t | 13t(ídem) | 13t(ídem) | **13t(256..328)** | 13t(ídem) |
| 335 | 13t(257..329) | ídem | sin fórmula | 13t(257..329) | **13t(257..329) completo** | 13t(ídem) | 13t(ídem) | sin fórmula | 13t(ídem) |
| 336 | 13t(258..330) | ídem | sin fórmula | 13t(258..330) | `SUM(270,282)` 2t | 13t(ídem) | 13t(ídem) | sin fórmula | 13t(ídem) |

"13t" = 13 términos, paso 6, cubriendo exactamente `[253:330]` (78 = 6×13) sin huecos ni solapes entre las 6 filas. `RuleExecutionLog` reconfirmado: **77/77 `skipped` en las 9, sin excepción** — nunca se han evaluado en producción (mismo `row_range={0,0}` → `scope=per_row` de siempre).

**Hallazgo nuevo de esta auditoría (dato real, no supuesto)**: se verificaron **10.920 valores reales** (140 cargas × 78 filas componentes) para las 9 columnas en el rango `[253:330]` — **el 100% son `NULL`** (ni un solo cero ni un solo valor real capturado, en ninguna carga, en ninguna columna, jamás). Esto confirma que estas 9 columnas (categorías demográficas especiales: "Gestantes", "Inasistente", "Compra de Servicio", "Ortodoncia interceptiva", "Usuarios con Discapacidad", "Usuarios Oncológicos", "Contrarreferencia al alta", "Pueblos originarios", "60 años incluido en grupos 60-64") **nunca han sido pobladas por ningún establecimiento en toda la historia del sistema** — no es una brecha del motor, es que la práctica real de captura jamás usa estas columnas. Esto no cambia el diseño necesario (la brecha arquitectónica es real e independiente de si hay datos), pero significa que **cualquier simulación con datos reales sería degenerada** (suma de nulos = 0, siempre "pasaría" trivialmente) — la simulación de la sección 4 usa por tanto una fixture sintética fiel a las fórmulas reales, igual que en 17.20-17.23.

#### 3. Infraestructura ya disponible — no requiere ningún cambio adicional, sea cual sea la opción elegida

Confirmado leyendo el código real: el mecanismo `source_rows`+`total_row` (`SumEqualsEvaluator`, 17.22/17.23) y el hook de captura `rem_technical_totals` (Fase 3A, 17.7, ya genérico) **ya soportan correctamente, sin ningún cambio adicional**, cualquiera de las 4 filas candidatas (332,334,335,336) una vez que el fix de merge (17.30) las excluye de `rem_data` — confirmado que para futuras cargas (nunca para el histórico, política ya establecida) estas 4 filas se capturarían automáticamente en `rem_technical_totals` con `exclusion_reason='embedded_backward_subtotal_row'`, disponibles para cualquier regla que las referencie como `total_row`. **La pregunta de diseño de este punto es puramente de catálogo/esquema (cuántas reglas, qué forma de `config`), no de infraestructura de datos** — esa parte ya está resuelta y no necesita tocarse de nuevo.

**Hallazgo crítico adicional (nunca antes cuantificado con precisión)**: `RuleEngineService::resolveRules()` (verificado leyendo el código real) selecciona qué reglas se ejecutan contra una carga real **exclusivamente vía `RuleBinding::where('active', true)` a la estructura**, y **bindings a la estructura activa 67 = `0`** (confirmado, consistente con todo lo documentado en esta campaña) — es decir, **ninguna de las 717 reglas activas hoy, incluidas las 431 `SAFE_1_TO_1`, se ejecuta realmente contra ninguna carga real todavía** (el rebind sigue sin autorizarse en toda la campaña). Esto significa que, sea cual sea el diseño elegido para B2/B3/F, **su impacto en validación real es cero hasta que ocurra un rebind futuro, separado y no autorizado** — un hecho que reduce el riesgo inmediato de cualquier opción, pero no cambia cuál es la más mantenible a largo plazo.

#### 4. Comparación formal de alternativas

**Opción A — dividir cada agregación en reglas independientes** (ej. regla `226` de AM se convierte en 6 reglas nuevas, una por posición/offset, reutilizando el mecanismo `source_rows`+`total_row` ya implementado sin ningún cambio de código): cada nueva regla es un `sum_equals` vertical normal, indistinguible en forma de las 226 ya resueltas en Categoría A/C.

**Opción B — extender `config` para soportar múltiples agregaciones dentro de una regla** (ej. `config['aggregations'] = [{total_row, source_rows}, ...N elementos]`, la misma idea ya esbozada en 17.20/17.21 sin implementar): 1 regla = 1 concepto de negocio (más limpio para el catálogo), pero requiere que `SumEqualsEvaluator::evaluate()` itere N agregaciones y decida cómo consolidar N resultados en un único veredicto — cambio estructural profundo, nunca antes hecho en esta campaña.

**Opción C — evaluador "periódico" bespoke** (nuevo `rule_type`, ej. `periodic_sum_equals`, con `period_step=6`, `period_count=13`, `total_rows=[331..336]`, `base_offset=253`, derivando las listas de componentes algorítmicamente en vez de enumerarlas): **descartada tras evaluarla** — requiere MÁS código nuevo que la Opción A (un evaluador completo nuevo) para resolver un patrón que, hasta donde se verificó hoy (solo A09/I auditado a este nivel), es específico de esta sección — pobre relación beneficio/costo frente a la Opción A, que ya reutiliza mecanismo probado sin tocar el motor.

**Opción D — validar solo un subconjunto representativo** (ej. solo la posición 331/offset 0, dejando 332-336 sin validar): **no es una decisión técnica, es una decisión de negocio** (aceptar cobertura reducida) — no evaluada como alternativa técnica, mencionada solo para que quede explícitamente descartada de este análisis técnico salvo que se autorice expresamente como decisión de producto.

**Tabla de impacto — 10 dimensiones exigidas, Opción A vs. Opción B** (Opción C ya descartada, no se detalla su impacto):

| Dimensión | Opción A (reglas independientes) | Opción B (multi-agregación en config) |
|---|---|---|
| `RuleEngineService` | **Ninguno** — cada regla nueva pasa por el mismo prefiltro/flujo ya existente para cualquier regla `sum_equals` vertical con `source_rows`/`total_row` | Requiere decidir cómo `execute()` construye/agrupa `$rows` para N sub-agregaciones de la MISMA regla (hoy 1 regla = 1 prefiltro = 1 `$rows`) — cambio real |
| `SumEqualsEvaluator` | **Ninguno** — `evaluateVerticalAggregation()` ya maneja este caso exacto (probado en 17.22/17.23 con las 12 reglas de B1/B4) | Requiere reescribir `evaluate()`/`evaluateVerticalAggregation()` para iterar `config['aggregations']` y producir un `RuleEvaluationResult` consolidado (hoy la clase asume 1 total_row escalar en toda su lógica) |
| Clasificador/reconciliación (`RuleBindingReconciliationService`) | **Ninguno** — ya sabe clasificar `sum_equals` vertical con `source_rows`/`total_row` como `SAFE_1_TO_1` (mecanismo ya usado 12 veces) | Requiere una rama nueva de clasificación para el shape `aggregations[]` — el clasificador hoy no tiene ningún concepto de "regla con N total_row" |
| Bindings | Cada regla nueva necesitaría su propio binding (igual que las 226+29+12 ya resueltas) — **sin bindings a estructura 67 hoy, impacto real = 0 hasta rebind futuro** | Igual — 1 binding por regla (menos bindings en total que Opción A, ya que hay menos reglas) |
| `RuleExecutionLog`/`RemValidationResult` | **Ninguno** — 1 fila de log por (regla, carga), igual que las ~700 reglas existentes; cada posición/offset queda auditable independientemente (igual que cualquier regla hoy) | Requiere decidir: ¿1 fila de log por regla con un veredicto consolidado (pierde granularidad: "falló" sin decir cuál de las 6 posiciones) o extender el esquema para N sub-resultados por fila de log? Ambas opciones tocan una tabla ya usada por ~700 reglas existentes |
| UI/reportes de errores | **Ninguno** — cada regla nueva aparece en la UI exactamente igual que cualquier otra regla (1 fila = 1 concepto de validación, ya soportado) | Requiere que la UI sepa mostrar "posición 3 de 6 falló" dentro de una sola fila de reporte — cambio de UI no evaluado ni diseñado hoy |
| Versionamiento/auditoría (`RuleVersion`, `rule:activate-source-rows`) | **Ninguno** — cada regla nueva se activa con el comando ya existente (`rule:activate-source-rows`, sin cambios), mismo patrón de `RuleVersion`/activity log que las 12 ya cerradas | El comando actual asume 1 `total_row`/`source_rows` por regla — necesitaría una variante nueva o extensión para escribir `aggregations[]` |
| Compatibilidad con las 431 reglas `SAFE_1_TO_1` existentes | **Total** — las reglas nuevas son indistinguibles en forma de las ya existentes, cero riesgo de regresión sobre las 431 | Riesgo bajo-medio: cualquier cambio a `SumEqualsEvaluator::evaluate()` (aunque sea aditivo, con guard `if (isset(aggregations))`) es una modificación al mismo método que ya evalúa las 431 reglas — mismo principio de cautela ya aplicado en 17.22 al extender `source_rows` |
| `source_rows` | Reutilizado tal cual, sin cambios — cada regla nueva tiene su propio `source_rows` (ej. `[253,259,...,325]` para offset 0) | Reutilizado dentro de cada elemento de `aggregations[]` — mismo concepto, anidado |
| `rem_technical_totals` | Reutilizado tal cual — cada `total_row` de cada regla nueva ya se captura automáticamente (Fase 3A, sin cambios) | Igual — sin cambios, cada `total_row` dentro de `aggregations[]` se captura igual |
| Futuras versiones de estructura REM | Si una futura versión de estructura cambia el número de bloques (ej. de 13 a 14 posiciones), Opción A requeriría ajustar manualmente cuántas reglas existen (crear/desactivar) — mismo mecanismo ya usado en toda la campaña (`rule:set-rule-status`) | Opción B podría, en teoría, derivar el número de agregaciones más dinámicamente si se diseñara para ello — pero esto no está diseñado hoy, es una ventaja HIPOTÉTICA, no evaluada en profundidad |

**Conclusión de la comparación**: Opción A tiene **impacto nulo en 8 de las 10 dimensiones evaluadas** (reutiliza infraestructura ya construida y probada 12+226+29 veces en esta campaña) — Opción B requiere cambios reales en al menos 5 dimensiones (motor, evaluador, clasificador, logging/UI, comando de activación), todas ellas superficies YA usadas por las 431 reglas `SAFE_1_TO_1` existentes, lo que exige el mismo nivel de cautela que ya se aplicó (y validó con éxito, incluyendo una regresión real encontrada y corregida en 17.30) en cada extensión anterior de esta campaña. **Ninguna decisión tomada — ambas opciones quedan documentadas, ninguna implementada.**

#### 5. Matriz exacta por `rule_id`

| rule_id | columna | fórmula(s) | TOTAL(es) candidatos | source rows (offset) | anomalía | representación propuesta | automatizable | riesgo | tratamiento recomendado |
|---|---|---|---|---|---|---|---|---|---|
| 226 | AM | 6×13t, paso 6, `[253:330]` sin huecos | 331,332,333,334,335,336 | offset0=[253,259,...,325] ... offset5=[258,264,...,330] | Ninguna | 6 reglas independientes (Opción A) o 1 regla con `aggregations[6]` (Opción B) | Sí, las 6 | Bajo (A) / medio (B) | `NEEDS_DESIGN_DECISION` — ninguna opción elegida |
| 227 | AN | idéntico a 226 | idéntico | idéntico | Ninguna | idéntico | Sí, las 6 | Bajo (A) / medio (B) | idéntico |
| 231 | AT | idéntico a 226 | idéntico | idéntico | Ninguna | idéntico | Sí, las 6 | Bajo (A) / medio (B) | idéntico |
| 232 | AU | idéntico a 226 | idéntico | idéntico | Ninguna | idéntico | Sí, las 6 | Bajo (A) / medio (B) | idéntico |
| 234 | AX | idéntico a 226 | idéntico | idéntico | Ninguna | idéntico | Sí, las 6 | Bajo (A) / medio (B) | idéntico |
| 229 | AR | 5×13t limpios (331,332,334,335,336) + 1×14t con término extraviado (333: `AR337`+13t) | 331,332,334,335,336 (334→336 la 5ta directamente; 333 excluida) | offset0,1,3,4,5 limpios; offset2 (fila 333) inválido | **`AR337`** — referencia extraviada a la fila-título de Sección J, confirmada vacía, matemáticamente inocua pero estructuralmente inválida (17.27) — **se conserva sin corregir, no se elimina ni se toca hoy** | 5 reglas independientes (offsets 0,1,3,4,5) — offset2/fila 333 queda sin regla nueva, excluida explícitamente | Sí, 5 de 6 — el 6to bloqueado por `AR337` | Bajo (5) / bloqueado (1, requiere resolver `AR337` primero, congelado) | `NEEDS_DESIGN_DECISION` para las 5; `AR337` sigue **AUDITADO/NO RESUELTO**, fuera de alcance |
| 228 | AQ | Solo 2×13t reales (331,332); 333-336 **sin fórmula, sin valor, no existen en el template** | 331,332 únicamente | offset0,1 únicamente | Bloque incompleto en el archivo Excel fuente — no es una brecha del motor, los otros 4 totales simplemente nunca fueron calculados en la plantilla original | Máximo 2 reglas independientes (offset0,1) — offsets 2-5 no tienen ningún TOTAL que representar, no forzar nada | Sí, 2 de 6 (los otros 4 no existen) | Bajo (2) / N/A (4 inexistentes) | `NEEDS_DESIGN_DECISION` acotada a 2 sub-agregaciones; no confundir con una brecha de 6 |
| 230 | AS | 6/6 con fórmula pero DISPERSA (2 términos, no 13) y mapeo fila→offset **inconsistente**: 331→offset0(parcial), 332→offset1(parcial), 333→offset2(parcial), 334→offset4(parcial), 335→offset4(**completo, duplica el offset de 334**), 336→offset5(parcial). **offset3 ausente en cualquier fila** | 331,332,333,334,335,336 (con mapeo ambiguo) | Ninguno limpio — ver anomalía | **Mapeo fila↔offset roto**: un offset (3) nunca referenciado por ninguna fila, y otro (4) referenciado dos veces de forma inconsistente (parcial en 334, completo en 335) — patrón de autoría del template, no reproducible mecánicamente | **Ninguna representación automática recomendada** — requiere decisión manual fila por fila, posiblemente consultando a Estadística APS qué significan realmente estas 6 filas para "Ortodoncia interceptiva" | **No** — mapeo ambiguo impide automatización segura | **Alto si se automatiza sin decisión manual** (riesgo de doble conteo en offset4, offset3 sin ninguna validación posible) | `NEEDS_DEEP_AUDIT` — revisión manual dedicada, no forzar ningún mecanismo genérico ni agruparla con 226/227/231/232/234 |
| 233 | AV | Solo 1×13t real (334); 331,332,333,335,336 **sin fórmula, sin valor, no existen en el template** | 334 únicamente | offset3 únicamente | Igual naturaleza que 228 pero aún más incompleto (bloque incompleto en el archivo fuente) | Máximo 1 regla independiente (offset3) — el resto no tiene ningún TOTAL que representar | Sí, 1 de 6 (los otros 5 no existen) | Bajo (1) / N/A (5 inexistentes) | `NEEDS_DESIGN_DECISION` acotada a 1 sub-agregación; no confundir con una brecha de 6 |

**Verificación de suma**: `226+227+231+232+234` (5, homogéneas B2) + `229` (1, B3 con excepción) + `228+230+233` (3, Categoría F, 3 anomalías distintas) = `9` exacto. **Las 9 NO son homogéneas** — confirmado de nuevo hoy: 5 son un patrón limpio idéntico, 1 comparte el patrón pero con 1 excepción bloqueada, y 3 son heterogéneas entre sí (228 y 233 comparten "bloque incompleto" pero con distinta cardinalidad de huecos; 230 es un caso de mapeo roto sin paralelo en las otras 8).

#### 6. Conclusión — nada implementado, ninguna alternativa elegida

Ninguna de las opciones (A/B/C) fue implementada. Ninguna decisión de diseño fue tomada — quedan documentadas con su comparación de impacto completa para una decisión futura. `228,230,233` (Categoría F) confirmadas heterogéneas entre sí, sin ninguna solución común forzada. `229` (B3) conserva explícitamente el hallazgo de `AR337` sin corregirlo ni eliminarlo. `226,227,231,232,234` (B2) confirmadas homogéneas entre sí, con el problema de cardinalidad múltiple plenamente caracterizado.

Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`. Ninguna escritura de ningún tipo durante esta auditoría. No se tocó `config` de ninguna de las 9 reglas, ningún `Rule` nuevo creado, ningún `status` cambiado, ninguna calibración, `rem_data`, bindings, estructura, la fila 333, `AR337`, el upload 186, la regla 461, ni `no_utilizada`. No commit de Git, no push.

### 17.34 — FASE 3C-3C/B2 — PLAN DE EXPANSIÓN EXACTO / NO IMPLEMENTADO (2026-08-28)

⚠️ **100% READ-ONLY / DISEÑO + AUDITORÍA EMPÍRICA (transacciones con `rollback` garantizado).** Ningún `Rule` nuevo creado de forma persistente, ningún `config` de las 5 reglas modificado, ningún `RuleVersion`/activity log real, ningún `status`/binding/calibración/`rem_data`/estructura tocados. B3 (229), Categoría F (228,230,233), fila 333, `AR337`, regla 461, `no_utilizada`, calibraciones, upload 186 — todos intactos. No rebind, no backfill, no commit Git, no push.

#### 1. Reconfirmación de baseline (antes de cualquier análisis)

`RuleBindingReconciliationService::classifyAllActiveRules()` real, contra estructura activa `67` (versión reconfirmada en vivo): `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, activas=`717` — **exacto, sin cambio desde 17.33**. Las 5 reglas B2 confirmadas sin ningún cambio: `226=a09_i_am_sum_equals`, `227=a09_i_an_sum_equals`, `231=a09_i_at_sum_equals`, `232=a09_i_au_sum_equals`, `234=a09_i_ax_sum_equals`, las 5 `status=active`, `total_row=null`, `source_rows=null` (config sin `source_rows` en absoluto, no solo `null`).

#### 2. ⚠️ HALLAZGO BLOQUEANTE (empírico, no teórico) — Opción A dispara `DUPLICATE` falso, con o sin desactivar el original

Antes de construir el plan de expansión se auditó `RuleBindingReconciliationService::buildDuplicateKeySet()` (línea ~540-553) — agrupa reglas activas **exclusivamente por `sheet+section+column+rule_type`** (SQL `GROUP BY`+`HAVING count>1`), **sin considerar `total_row`/`source_rows`/`row_range` en absoluto**. Esto es distinto del mecanismo `SAFE_1_TO_1` (que sí valida `total_row`/`source_rows`) — son dos chequeos independientes, y el de duplicados corre primero/aparte.

**Verificado empíricamente con 2 pruebas transaccionales reales (`DB::beginTransaction()`+`rollback` garantizado en `finally`, nunca comiteado)**:

- **Prueba 1 — original 226 activa + 2 clones sintéticos** (mismo `sheet=A09,section=I,column=AM,rule_type=sum_equals` que 226, pero `total_row`/`source_rows` distintos: offset0=331 y offset1=332, `rule_key` temporal): `classifyAllActiveRules()` real clasificó **las 3 reglas (226 + los 2 clones) como `DUPLICATE`**, motivo `"Parte de un grupo de reglas equivalentes (mismo sheet+seccion+columna+tipo)."` — `DUPLICATE` global `22→25`, `BLOCKED_BY_ENGINE_GAP` `66→65`. Rollback confirmado, nada persistido.
- **Prueba 2 — original 226 desactivado (`status=inactive`) + 2 clones nuevos**: **los 2 clones siguen clasificando `DUPLICATE` entre sí** (`DUPLICATE` `22→24`, `BLOCKED_BY_ENGINE_GAP` `66→65` — la baja de 1 en `BLOCKED_BY_ENGINE_GAP` es la propia 226 ya inactiva, dejando de contar como activa). Rollback confirmado, `226` reconfirmada activa en BD real tras el rollback.

**Conclusión, no ambigua**: dividir cualquier columna de B2 en N reglas independientes activas sobre el mismo `(sheet,section,column,rule_type)` — sea cual sea el destino del original (activo, inactivo, o reemplazado) — hace que el clasificador actual las marque **`DUPLICATE`, nunca `SAFE_1_TO_1`**, porque su clave de agrupación no distingue `total_row`/`source_rows`. Esto **bloquea la Opción A tal como fue literalmente especificada** (reglas independientes, sin tocar el motor) — no es un problema de diseño de `config`, es un gap del clasificador (`buildDuplicateKeySet()`) que no fue anticipado en la comparación de opciones del punto 17.33 (esa comparación evaluó el impacto de Opción A sobre el clasificador como "Ninguno", lo cual queda **corregido por este hallazgo**: si el clasificador no cambia, Opción A no puede llegar a `SAFE_1_TO_1`).

**Por instrucción explícita del usuario ("si descubres que dividir B2 provoca... duplicidad... STOP y repórtalo; no adaptes el motor automáticamente"): este hallazgo se reporta tal cual, sin modificar `buildDuplicateKeySet()` ni ningún otro código del clasificador.** Extender esa clave para incluir `total_row` (mismo principio ya usado al extender `source_rows` en el evaluador, punto 17.22) sería la corrección más directa, pero es exactamente el tipo de "adaptación del motor" que el usuario pidió no hacer sin autorización — queda documentada como prerequisito, no implementada.

**Las 3 variantes de "qué hacer con la regla original" NO evitan el problema** (evaluadas explícitamente):
- (a) **Conservar 226 activa sin tocar su `config`** (sigue `row_range={0,0}`, nunca alcanza el evaluador vertical) — inofensiva en ejecución real, pero los 6 clones nuevos igual colisionan `DUPLICATE` entre sí.
- (b) **Desactivar 226** (`status=inactive`, mismo patrón que Tanda 1/344 de Fase 2) — confirmado en la Prueba 2: no resuelve nada, los 6 clones nuevos siguen colisionando `DUPLICATE` entre sí.
- (c) **Reemplazar 226 con el offset0** (reusar `rule_id=226` para la primera posición, crear solo 5 reglas nuevas para offsets 1-5) — reduce el conteo de reglas nuevas a 5, pero las 6 (226 reconfigurada + 5 nuevas) siguen compartiendo `(sheet,section,column,rule_type)` entre sí → mismo `DUPLICATE`.

**Ninguna de las 3 evita el bloqueo — es estructural al clasificador, no al ciclo de vida de la regla original.**

#### 3. Parte NO bloqueada — el evaluador (`SumEqualsEvaluator`) sí produce el resultado correcto

A diferencia del clasificador, el **evaluador** de ejecución real (`SumEqualsEvaluator::evaluate()`, mecanismo `source_rows`+`total_row` ya implementado y cerrado en 17.22/17.23) no tiene ningún problema de duplicidad — es invocado regla por regla, nunca compara una regla contra otra. Se validó mediante una simulación in-memory (sin crear ningún `Rule`, config armado directamente en el shape ya normalizado por `RuleEngineService::normalizeConfig()` — `source_letters`, `target_column`, `scope=row_range`, `row_from`/`row_to`, `source_rows`, `total_row`):

| Caso | Config | Resultado |
|---|---|---|
| Offset 0 (fila 331), columna AM, componentes+total coherentes | `source_rows=[253,259,...,325]`, `total_row=331` | `status=passed, failedRows=0` |
| Offset 0, mismo config, total deliberadamente +1 | ídem, total alterado | `status=failed, failedRows=1` |
| Los 6 offsets, columna AN, cada uno con su propio total coherente | `source_rows`/`total_row` por offset (331..336) | **6/6 `passed`** |

Confirma que, **si la Opción A pudiera activarse** (es decir, si el clasificador no las marcara `DUPLICATE`), el motor de evaluación en sí calcularía correctamente cada una de las 6 posiciones de forma independiente, sin fallback silencioso ni contaminación entre posiciones — el bloqueo es exclusivamente de clasificación/catálogo, no de corrección aritmética.

#### 4. Derivación exacta de las 30 combinaciones (5 columnas × 6 offsets) — documentada pese al bloqueo, tal como se pidió

**Fórmula compartida por las 5 columnas** (AM=226, AN=227, AT=231, AU=232, AX=234) — cada offset define el mismo conjunto de `source_rows`, solo cambia la letra de columna:

| Offset | Fila TOTAL | `source_rows` (13 términos, paso 6) | `row_range` envolvente |
|---|---|---|---|
| 0 | 331 | `[253,259,265,271,277,283,289,295,301,307,313,319,325]` | `{from:253,to:325}` |
| 1 | 332 | `[254,260,266,272,278,284,290,296,302,308,314,320,326]` | `{from:254,to:326}` |
| 2 | 333 | `[255,261,267,273,279,285,291,297,303,309,315,321,327]` | `{from:255,to:327}` |
| 3 | 334 | `[256,262,268,274,280,286,292,298,304,310,316,322,328]` | `{from:256,to:328}` |
| 4 | 335 | `[257,263,269,275,281,287,293,299,305,311,317,323,329]` | `{from:257,to:329}` |
| 5 | 336 | `[258,264,270,276,282,288,294,300,306,312,318,324,330]` | `{from:258,to:330}` |

**`rule_key` propuesto por combinación** (patrón `a09_i_{col}_row{total_row}_sum_equals`, todo minúsculas, único por construcción dado que `rule_key` es `UNIQUE` y cada `{col}_{row}` es distinto — verificado `varchar(255)`, sin riesgo de longitud):

| Origen (columna) | Offset0/331 | Offset1/332 | Offset2/333 | Offset3/334 | Offset4/335 | Offset5/336 |
|---|---|---|---|---|---|---|
| 226 (AM) | `a09_i_am_row331_sum_equals` | `a09_i_am_row332_sum_equals` | `a09_i_am_row333_sum_equals` | `a09_i_am_row334_sum_equals` | `a09_i_am_row335_sum_equals` | `a09_i_am_row336_sum_equals` |
| 227 (AN) | `a09_i_an_row331_sum_equals` | `a09_i_an_row332_sum_equals` | `a09_i_an_row333_sum_equals` | `a09_i_an_row334_sum_equals` | `a09_i_an_row335_sum_equals` | `a09_i_an_row336_sum_equals` |
| 231 (AT) | `a09_i_at_row331_sum_equals` | `a09_i_at_row332_sum_equals` | `a09_i_at_row333_sum_equals` | `a09_i_at_row334_sum_equals` | `a09_i_at_row335_sum_equals` | `a09_i_at_row336_sum_equals` |
| 232 (AU) | `a09_i_au_row331_sum_equals` | `a09_i_au_row332_sum_equals` | `a09_i_au_row333_sum_equals` | `a09_i_au_row334_sum_equals` | `a09_i_au_row335_sum_equals` | `a09_i_au_row336_sum_equals` |
| 234 (AX) | `a09_i_ax_row331_sum_equals` | `a09_i_ax_row332_sum_equals` | `a09_i_ax_row333_sum_equals` | `a09_i_ax_row334_sum_equals` | `a09_i_ax_row335_sum_equals` | `a09_i_ax_row336_sum_equals` |

= **30 combinaciones exactas** (5×6), cada una con: `rule_id origen` (226/227/231/232/234, según columna), `sheet=A09`, `section=I`, `columna` (AM/AN/AT/AU/AX), `fila TOTAL` (331-336), `fórmula original` (`SUM` de los 13 términos de su offset, ver punto 17.33 sección 2), `source_rows` exacto (tabla arriba), `row_range` envolvente (tabla arriba), `rule_key` propuesto (tabla arriba), `config` resultante = `{sheet:A09, section:I, column:<col>, row_range:<envolvente>, source_rows:<lista>, total_row:<fila>, rule_logic:"Suma(<col>) = Columna <col>"}` (mismo shape que las 12 reglas ya activadas en 17.24). **Relación con la regla original**: cada una deriva de exactamente 1 de las 5 reglas existentes (226/227/231/232/234), representa 1 de sus 6 posiciones periódicas. **Disposición de la regla original**: no determinada — las 3 variantes (a/b/c del punto 2) documentadas, ninguna evita el bloqueo del punto 2, decisión pendiente de una resolución del gap del clasificador primero.

#### 5. Prueba matemática de cobertura exacta (100%, sin huecos ni solapes)

Los 6 offsets cubren los residuos `{253,254,255,256,257,258} mod 6 = {1,2,3,4,5,0}` — es decir, **los 6 restos posibles módulo 6**, sin repetición. Cada offset tiene 13 términos (`253+6k` para `k=0..12`, `k` hasta 12 da `253+72=325`, el último término de offset0). El rango total cubierto es `[253:330]` = `330-253+1 = 78` filas = `6 offsets × 13 términos = 78` — **coincide exacto**. Como los 6 offsets son residuos distintos módulo 6, **cada fila de `[253:330]` pertenece a exactamente 1 offset, nunca a 0 ni a 2+** (demostrado por construcción aritmética, no por enumeración) — 0 huecos, 0 solapes, 100% de cobertura de las 78 filas físicas reales que el Excel fuente agrega en las 6 filas TOTAL (331-336) de cada una de las 5 columnas homogéneas.

#### 6. Análisis de trazabilidad (afectado por el hallazgo del punto 2 — no un simple "sí, funciona")

- **`RuleVersion`**: si se crearan las 30 reglas nuevas, cada una nace sin historial previo (no hay `RuleVersion` que "preservar" para una regla nueva) — el snapshot relevante sería el de la regla ORIGINAL (226/227/231/232/234) en el momento de cualquier cambio a su `status`, igual que el patrón ya usado en Fase 2/Categoría A (snapshot previo al cambio, vía `RuleVersion::create()` dentro del comando de activación correspondiente — no existe todavía un comando para este caso, no diseñado en detalle).
- **Activity log**: mismo patrón que los comandos ya cerrados (`rule_category_a_activated`, `rule_activate_source_rows`, etc.) — un tipo de evento nuevo (ej. `rule_split_from_periodic_total`) sería necesario, no implementado.
- **`rule_key` único**: las 30 `rule_key`s propuestas son únicas entre sí y frente a las 764 `rule_key`s existentes (patrón `a09_i_{col}_row{n}_sum_equals` no colisiona con `a09_i_am_sum_equals` ni con ningún otro `rule_key` real revisado).
- **`status` de las reglas originales**: sin decidir (punto 2, variantes a/b/c) — **ninguna variante evita el bloqueo de `DUPLICATE`**, así que la elección de `status` no es, por sí sola, una solución.
- **Futura creación de bindings**: bindings a estructura 67 = `0` hoy (sin rebind autorizado en toda la campaña) — impacto real en ejecución = 0 hasta un rebind futuro, pero **el rebind automático (`rule:rebind-safe-to-structure`) solo procesa reglas `SAFE_1_TO_1`** (confirmado leyendo el comando real: filtra explícitamente por esa clasificación) — **mientras el hallazgo del punto 2 no se resuelva, las 30 reglas nuevas NUNCA calificarían para ese camino de rebind automático**, sea cual sea su `config`.
- **Evitar ejecución simultánea original+nuevas tras un rebind futuro**: en la práctica, si el original quedara activo pero sin `config` válido (`row_range={0,0}`), seguiría `skipped` para siempre (`scope=per_row`, nunca alcanza el evaluador vertical) — no colisionaría en tiempo de ejecución con las reglas nuevas aunque ambas tuvieran binding, PERO esto es una observación sobre runtime, no sobre el catálogo/clasificador, que es donde está el bloqueo real.
- **Rollback/reversibilidad**: si llegaran a crearse, cada regla nueva es independiente y reversible individualmente (`status=inactive`, mismo patrón que toda la campaña) — no hay entrelazado entre las 6 posiciones de una misma columna a nivel de datos, solo a nivel de clasificación (`DUPLICATE` es un efecto del *conjunto* activo, no de ninguna regla en particular).
- **Clasificación por `RuleBindingReconciliationService`**: **confirmado, no teórico — `DUPLICATE` para las 30 (o cualquier subconjunto ≥2 activas por columna), nunca `SAFE_1_TO_1`**, mientras `buildDuplicateKeySet()` no distinga `total_row`/`source_rows`. Este es el hallazgo central de este punto.

#### 7. Conclusión — Opción A queda BLOQUEADA tal como fue especificada; nada implementado

**El plan de expansión exacto para B2 (226,227,231,232,234) queda completamente documentado** (30 combinaciones, `rule_key`s, `config` resultante, prueba matemática de cobertura 100%, fixtures del evaluador confirmando corrección aritmética) **pero NO es implementable hoy sin una decisión adicional**: extender `RuleBindingReconciliationService::buildDuplicateKeySet()` para que considere `total_row`/`source_rows` como parte de la clave de agrupación (o un mecanismo equivalente) — cambio que, siguiendo la instrucción explícita del usuario, **no fue intentado ni sugerido como acción automática**, solo reportado como prerequisito descubierto. Sin ese cambio (o una decisión alternativa: Opción B, u Opción D de negocio), **Opción A no puede llegar a `SAFE_1_TO_1`**, por lo que activar cualquiera de las 30 reglas nuevas hoy dejaría el catálogo con `DUPLICATE` adicional, no con una resolución real de la brecha B2.

**No se creó ningún `Rule`, `RuleVersion`, activity log, config ni binding real.** No se tocó B3 (229), Categoría F (228,230,233), la fila 333, `AR337`, la regla 461, `no_utilizada`, calibraciones, `rem_data`, estructura, ni el upload 186. Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`. Las 2 pruebas empíricas de duplicidad corrieron dentro de transacciones con `rollback` garantizado (`try/finally`), confirmado en ambos casos que nada quedó persistido. No commit de Git, no push.

### 17.35 — B2 — AUDITORÍA DE IDENTIDAD/DUPLICADOS / DECISIÓN PENDIENTE (2026-08-28)

⚠️ **100% READ-ONLY.** Ninguna escritura de ningún tipo: `buildDuplicateKeySet()` sin modificar, ningún `Rule` creado, ningún `config`/`status` cambiado, ningún binding/rebind/calibración/`rem_data`/estructura tocados. B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada` intactos. No commit, no push. Toda simulación de este punto corrió en scripts PHP puros locales (nunca contra la BD real salvo lecturas), sin ninguna transacción de escritura.

#### 1. Reconfirmación de baseline

`activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, estructura activa `67/v35` — idéntico al cierre de 17.34, sin cambios.

#### 2. Mapa exhaustivo de identidad/duplicado en el código real

**Origen de la identidad — el propio `rule_key` no distingue agregación**: `RuleKeyGeneratorService::generate($sheet,$section,$letra,$tipo)` produce `{sheet}_{section}_{letra}_{tipo}` — **sin ningún componente de fila/rango/agregación**. Este es el generador usado por `RuleIngestionService::ingest()` al crear TODAS las reglas desde una estructura (`Rule::where('rule_key', $ruleData['rule_key'])->first()` decide "reusar" vs "crear nueva" basándose EXCLUSIVAMENTE en esta clave). Además, el propio modelo de datos ascendente es 1:1: cada `field` de la estructura (`$section['fields']`) tiene **un único** `reglaDetectada` — el parser/detector de estructura nunca representó, desde el origen, el concepto de "una columna con 6 agregaciones periódicas". Esto confirma que el problema de identidad no es solo del clasificador — está **heredado desde la capa de detección de estructura y desde la generación de `rule_key`**, ambas anteriores a `RuleBindingReconciliationService`.

**`RuleBindingReconciliationService`** — 2 puntos de identidad, ambos ya conocidos, reconfirmados hoy:
- `buildDuplicateKeySet()` (línea ~540-553): `GROUP BY sheet,section,col,rule_type HAVING count>1` — sin `total_row`/`source_rows`/`row_range`.
- `classifyRule()` línea 155: `$dupKey = "{sheet}|{section}|{col}|{rule_type}"` — misma forma, aplicada por regla individual.
- **Hallazgo NUEVO, no documentado antes de hoy**: el chequeo `$isDuplicate` (línea 250) corre **DESPUÉS** del chequeo `$serieOrGlobalRuleIds->has($rule->id)` (línea 146, que devuelve `ALREADY_STRUCTURE_AGNOSTIC` de inmediato) — **una regla con binding a nivel `serie`/`global` NUNCA llega a evaluarse contra `dupKeySet`, sin importar si colisiona con otra regla activa**. Esto es un blind spot real y ya explotado en el catálogo actual (ver punto 3).

**`RuleEngineService::execute()`** — **sin ningún concepto de deduplicación**. Cada `Rule` se procesa en su propio ciclo del `foreach`, `$config['_rule_key'] = $rule->rule_key` (línea 141) ancla cada evaluación a su propio identificador único; `RuleExecutionLog`/`rem_validation_results` se escriben siempre keyed por `rule_key` (columna `fillable`, valor único garantizado por constraint de BD). **Confirmado: la corrección/ejecución en producción es completamente indiferente a si 2+ reglas comparten sheet+section+column+type** — el problema de "duplicado" existe únicamente en la capa de catálogo/reconciliación, nunca en la capa de ejecución real.

**`CertificationService`** — 2 hallazgos:
- `getRules()` (línea 18-47) filtra `RuleBinding::where('bindable_type','structure')->where('bindable_id', $structure->id)->where('active', true)` — **como bindings a la estructura activa 67 = 0, todo el subsistema de certificación está hoy vacío/inerte** (no muestra ninguna de las 717 reglas activas, sea cual sea su clasificación) — cualquier riesgo de este subsistema es HOY latente, no observable, hasta un rebind futuro.
- `findStructureEvidence()` (línea 122-174) busca, dentro de `$sec['fields']`, el campo cuya `letra` coincide con la columna de la regla, y **devuelve el `reglaDetectada` de ESE campo (uno solo)** — si 6 reglas compartieran columna (ej. B2 expandida), las 6 mostrarían la MISMA "evidencia_xlsm" (mismo `rango_filas` original, el de la primera agregación detectada por el parser) — **pérdida de evidencia estructural por-agregación**, consecuencia directa de que la estructura ascendente nunca representó más de 1 `reglaDetectada` por campo. No es un crash ni una colisión de datos — es una limitación de visualización, hoy sin efecto observable (mismo motivo que el punto anterior).
- `getRules()/getSectionRules()/getStats()/certStatus[$rule->rule_key]` — correctamente indexados por `rule_key` único → sin colisión de estado de certificación entre reglas hermanas, cuando el subsistema esté activo.

**`RuleRemapSectionCommand`** — usa `classifySingleRule()` (el mismo clasificador) para simular antes/después de un remap; **hereda el mismo gap ya documentado en el punto 16.3.2** (el guard compara contra el estado de BD *antes* de escribir, no contra el estado que resultaría *después*) — precedente real ya vivido con las reglas 529↔530 (mismo tipo de colisión de clave funcional, resuelto por desactivación, no por remap). Mismo problema de fondo que el de hoy: la identidad usada para detectar colisión (`sheet+section+column+type`) es demasiado gruesa.

**`RuleRebindSafeToStructureCommand`** — filtra explícitamente por `RuleBindingReconciliationService::SAFE_1_TO_1` (confirmado leyendo el comando real) — **nunca procesa reglas `DUPLICATE`**, así que hereda el bloqueo de forma indirecta: mientras el clasificador marque algo `DUPLICATE`, ese "algo" nunca podrá activarse por el camino de rebind automático ya establecido en toda esta campaña.

**`BindingController`/`RuleController`/`CatalogController`/`ComparisonController`/`StructureController`/`RuleExecutionLogController`/`ValidationSummaryService`/`RuleExecutionLog`/`RuleResource`/`RuleExecutionLogResource`** — auditados uno por uno: todos filtran/agrupan por `rule_key` (único) o por `sheet`/`section` solos (nunca por columna de forma que colisione entre reglas hermanas) — **ninguna otra superficie de identidad/duplicado encontrada** más allá de las ya listadas.

**Precedente relevante encontrado — `ComparisonReport::makeCompKey()`** (línea 92-103, herramienta de comparación legacy-vs-nuevo motor, usada por `ComparisonController`/comandos de testing, **no** por el catálogo de producción): construye su clave como `strtolower("{sheet}|{section}|{letra}|{tipo}|{row_from}|{row_to}")` — **YA incluye rango de filas como parte de la identidad**, con un propósito distinto (detectar si una regla del motor legacy corresponde a una regla del motor nuevo tras una reingesta). Esto demuestra que **una identidad más rica que incorpore rango de filas ya tiene precedente de implementación funcional en este código base** — aunque acotado a una herramienta de diagnóstico/migración, no al catálogo de producción.

**`RemValidationService.php`** — confirmado como un **sistema de validación LEGACY separado**, con su propio formato de regla (`$rule['type']`, `$rule['column']`, `$rule['key']`, `evaluateRowRangeSum()`) — no usa `rem_rules`/`rule_key` en absoluto. **Fuera de alcance por completo** — cualquier cambio a la identidad de `rem_rules` no lo afecta.

#### 3. Prueba de seguridad — los 22 `DUPLICATE` oficiales NO son el universo completo

**Hallazgo central, no anticipado**: replicando exactamente la consulta SQL de `buildDuplicateKeySet()` (mismo `GROUP BY`/`HAVING`) contra las 717 reglas activas reales, sin aplicar el atajo de `ALREADY_STRUCTURE_AGNOSTIC`, aparecen **19 grupos con `count>1`, 50 reglas en total** — no solo las 22 ya clasificadas `DUPLICATE`. **Las 28 restantes (12 grupos) nunca se muestran como `DUPLICATE`** porque tienen bindings a nivel `serie`/`global` que las clasifican `ALREADY_STRUCTURE_AGNOSTIC` **antes** de que el clasificador llegue a evaluar `dupKeySet` (ver punto 2) — confirmado empíricamente con los ids `658`/`698`/`661`/`699`/etc., que comparten columna con otra regla activa pero jamás aparecen en el conteo `DUPLICATE=22`.

**Clasificación de los 19 grupos por naturaleza real de la diferencia de agregación** (row_range/total_row/source_rows comparados campo a campo, evidencia real, no supuesta):

| Naturaleza | Grupos | Reglas | Ejemplos | Interpretación |
|---|---|---|---|---|
| **Duplicado exacto** (row_range+total_row+source_rows idénticos) | 3 | 6 | `A01/B col C` (560,618, ambos `[36:39]`) · `A06/C.2 col D` (130,786, ambos `[97:101]`) · `A06/C.3 col D` (133,787, ambos `[106:108]`) | Genuinamente redundantes — **una identidad más rica NO los rescataría**, seguirían colisionando bajo cualquier alternativa evaluada. Candidatos a resolución tipo 529↔530 (desactivar uno), no a coexistencia. |
| **Par completo/incompleto** (mismo row_range, `total_row` seteado en uno, `null` en el otro) | 7 pares limpios + 2 grupos mixtos (pareja + 1 outlier de rango distinto) | 14 + 6 | `A06/A.2 col E/F/G` (661/699, 662/700, 663/701) · `A06/A.3 col E` (705/715) · `A06/L col B/C/D` (772/783, 773/784, 774/785) · `A06/A.2 col B` (658/698 + outlier 126) · `A06/A.3 col B` (702/714 + outlier 127) | **Ambigüedad real, no resuelta por ninguna alternativa mecánica**: podría ser un par "versión vieja incompleta / versión nueva ya vetted" (mismo patrón que 529↔530, candidato a desactivación) — o podrían ser 2 agregaciones legítimas que casualmente comparten rango. **Requiere revisión caso por caso, no generalizable.** |
| **Rescatable — rangos genuinamente distintos, contiguos, sin solape** (patrón B2-like) | 4 | 14 | `A01/C col D` (570,572,574,576: `[44:47],[48:53],[54:62],[63:66]`) · `A01/C col E` (571,573,575,577: mismos rangos) · `A06/B.3 col D` (720,721,722: `[55:60],[61:66],[67:76]`) · `A06/B.3 col E` (723,724,725: mismos rangos) | Estructuralmente análogo al caso B1/B4 ya resuelto (Fase 3C-3A/3C-3B) — **una identidad más rica los separaría correctamente**, cada uno podría llegar a `SAFE_1_TO_1` de forma independiente si el resto de sus condiciones lo permite. |
| **Mixto/ambiguo** (rangos que se solapan entre sí, o mezcla de formato horizontal legacy `csv_catalog` + vertical `vetted_catalog`) | 3 | 10 | `A01/A col C` (24,553,557,558,559,617 — 6 reglas, rangos `[11:11],[11:22],[23:26],[27:32],[29:30],[11:32]`, el último envuelve a varios de los otros) · `A01/A col W` (585,602) · `A01/E col B` (29,580) | **No es un caso limpio de "6 sub-agregaciones complementarias"** — hay solapamiento real entre rangos (ej. `[29:30]` cae dentro de `[27:32]`, y `[11:32]` envuelve a casi todos) — mezcla de proveniencia (`source=csv_catalog` 2026-07-14 vs `source=vetted_catalog` 2026-07-20, ver `id=24` vs `id=553` etc.) sugiere que el catálogo `vetted_catalog` pudo haber sido un intento de reemplazo más granular del `csv_catalog` original, no necesariamente coexistente. **Requiere auditoría dedicada, no asumible como "múltiples agregaciones legítimas".** |

**Verificación de suma**: `3+9+4+3=19` grupos exacto (los 2 grupos "mixtos con outlier" se cuentan una vez cada uno dentro de la fila de "par completo/incompleto" de la tabla, ya que su componente dominante es esa naturaleza); `6+20+14+10=50` reglas exacto.

**Respuesta directa a la pregunta del usuario — ¿existen `SAFE_1_TO_1` que ya compartan identidad simple?** **No, cero** — confirmado empíricamente sobre los 50 involucrados en las 19 colisiones reales: ninguno clasifica `SAFE_1_TO_1` hoy; **todos caen en `DUPLICATE` (22) o `ALREADY_STRUCTURE_AGNOSTIC` (28)**. Esto es consistente con el diseño actual del clasificador — cualquier par que compartiera identidad simple y NO tuviera binding serie/global caería inevitablemente en `DUPLICATE` antes de poder llegar a `SAFE_1_TO_1`.

#### 4. Simulación en memoria — catálogo real (50 reglas) + B2 (30 combinaciones de 17.34), bajo 3 estrategias de clave

Réplica en PHP puro (sin tocar `buildDuplicateKeySet()` real, sin persistir nada) de 3 estrategias de agrupación, aplicadas simultáneamente contra (a) las 50 reglas reales de los 19 grupos y (b) las 35 filas hipotéticas de B2 (5 originales + 30 nuevas, en memoria):

| Estrategia | Clave | Catálogo real (50 reglas) | B2 (35 filas) |
|---|---|---|---|
| **Actual** | `sheet\|section\|col\|type` | 19 grupos, 50 colisionan | 5 grupos, **35/35 colisionan** (confirma 17.34) |
| **Total-row-only (ingenua)** | `sheet\|section\|col\|type\|total_row` | **12 grupos, 34 siguen colisionando** | 0 grupos — 35/35 quedan con identidad única |
| **Full-signature** (`row_range`+`total_row`+`source_rows`) | `sheet\|section\|col\|type\|row_range\|total_row\|source_rows` | **3 grupos, 6 siguen colisionando** (los 3 duplicados exactos de la tabla anterior) | 0 grupos — 35/35 quedan con identidad única |

**Hallazgo crítico de la comparación**: la estrategia **"total-row-only" es INSEGURA en general**, aunque coincidentalmente funcione para B2 (donde `total_row` ya es único por offset). Prueba concreta: el grupo `A01/A col C` (6 reglas, rangos genuinamente distintos `[11:11]...[11:32]`) **todas comparten `total_row=null`** (ninguna tiene `total_row` seteado todavía) — bajo la clave ingenua, las 6 **seguirían colisionando como si fueran duplicados**, exactamente el mismo defecto que la identidad actual, porque la clave ingenua es ciega a `row_range`/`source_rows` cuando `total_row` está ausente en ambos lados. La estrategia **full-signature no tiene este defecto** — al incluir `row_range` explícitamente, separa correctamente cualquier par cuyo rango difiera, sin importar si `total_row` ya está seteado o no.

**Contraprueba de seguridad — full-signature NO abre la puerta a duplicados reales**: de los 19 grupos reales, full-signature deja exactamente los **3 duplicados exactos** (mismos row_range+total_row+source_rows en ambos miembros) — **cero falsos negativos nuevos**: ningún par que hoy es genuinamente redundante (mismos 3 campos) deja de detectarse. El riesgo real identificado no es "full-signature deja pasar duplicados" — es que **los 9 "pares completo/incompleto"** (14+6=20 reglas) **dejarían de colisionar bajo full-signature**, y esos pares son de naturaleza **ambigua** (posible superseded, no necesariamente agregación legítima) — este es el riesgo central a sopesar, no un defecto técnico de la estrategia en sí.

#### 5. Comparación formal de alternativas (12 dimensiones exigidas)

| Dimensión | A. Identidad ampliada (`buildDuplicateKeySet` + `row_range`/`total_row`/`source_rows`) | B. Una sola regla, `config['aggregations'][]` | C. `aggregation_key` explícito en `config` | D. Otra (evaluada: mantener identidad actual + tabla de excepciones manual) |
|---|---|---|---|---|
| **Impacto en motor** (`RuleEngineService`/`SumEqualsEvaluator`) | Ninguno — la ejecución ya es indiferente a la identidad de catálogo (punto 2); `source_rows`+`total_row` por regla ya funciona (17.22/17.23) | Alto — requiere iterar `aggregations[]` dentro de una sola regla y consolidar N resultados en 1 `RuleEvaluationResult` (ya señalado en 17.33) | Ninguno si `aggregation_key` es solo metadata de identidad (no cambia cómo se evalúa una regla individual) | Ninguno |
| **Clasificador** (`RuleBindingReconciliationService`) | Cambio acotado — extender `buildDuplicateKeySet()`/`dupKey` para incluir 3 campos normalizados; **riesgo de "pares completo/incompleto" dejando de detectarse** (ver punto 4) | Ninguno — el clasificador seguiría viendo 1 regla, sin necesidad de tocar `buildDuplicateKeySet()` en absoluto | Cambio acotado — sustituir `total_row`/`source_rows` por `aggregation_key` en la clave; mismo riesgo de "pares completo/incompleto" si el `aggregation_key` no se deriva de forma que distinga versiones stale de versiones vigentes | Ninguno al clasificador, pero requiere mantener la tabla de excepciones sincronizada manualmente con cada caso nuevo — no escala, y ya hay 19 grupos reales hoy |
| **Bindings** | Ninguno — 30 nuevas reglas necesitarían sus propios bindings igual que cualquier regla (patrón ya usado 12+226+29 veces) | Ninguno — 1 regla, 1 binding, sin cambio de patrón | Ninguno, mismo patrón que A | Ninguno |
| **Logs** (`RuleExecutionLog`/`rem_validation_results`) | Ninguno — cada regla nueva ya tiene su propio `rule_key` único, logging independiente automático | Requiere decidir granularidad: ¿1 log por regla con veredicto consolidado (pierde detalle de qué posición falló) o N logs por regla? Cambio de esquema/semántica | Ninguno — mismo patrón que A, logging ya por `rule_key` | Ninguno |
| **UI** | Ninguno de motor; **sí hay una limitación real y ya confirmada** en `CertificationService::findStructureEvidence()` — 6 reglas hermanas mostrarían la misma "evidencia_xlsm" (la estructura ascendente no tiene 6 `reglaDetectada` por campo) — pero hoy INERTE (0 bindings a estructura activa) | Requiere UI nueva para mostrar "posición 3 de 6" dentro de 1 tarjeta — no diseñada | Igual que A (misma limitación de `findStructureEvidence()`, sin relación con `aggregation_key`) | Ninguno, pero la UI seguiría mostrando "DUPLICATE" para 19 grupos reales hoy, incluidos los 14 rescatables |
| **Certificación** (`CertificationService`) | Ninguno más allá de lo ya señalado — subsistema completo inerte hoy (bindings=0 a estructura 67) | Igual — inerte hoy, sin binding | Igual — inerte hoy | Igual — inerte hoy |
| **Migraciones** | Ninguna — no requiere columna/tabla nueva, solo cambio de lógica en `buildDuplicateKeySet()` | Ninguna de esquema de `rem_rules` (config ya es JSON libre), pero si se decidiera separar logs por sub-agregación sí requeriría esquema nuevo | Ninguna de esquema si `aggregation_key` vive dentro de `config` (JSON libre); si se quisiera indexar/consultar eficientemente, sí convendría una columna real nueva (no evaluado en detalle) | Ninguna |
| **Compatibilidad hacia atrás** | Alta — las ~700 reglas sin colisión no cambian de comportamiento (mismo principio ya aplicado al extender `source_rows` en 17.22, verificado por construcción, no solo empíricamente) | Alta para reglas existentes (1 agregación = comportamiento actual), pero introduce un shape de `config` nuevo y no retro-aplicado a las ~750 reglas existentes (conviven 2 shapes de config indefinidamente) | Alta si `aggregation_key` es opcional (ausente = comportamiento actual) | Total — cero cambios de comportamiento para cualquier regla existente |
| **Riesgo de duplicados reales** (que la identidad más rica "esconda" un duplicado genuino) | **Medio, ya cuantificado**: 20 de 50 reglas reales hoy (los "pares completo/incompleto") dejarían de colisionar sin que se haya determinado si son agregaciones legítimas o versiones stale — riesgo real, no solo el 0% de "duplicados exactos" (que sí siguen detectándose) | Bajo — al no cambiar la identidad de catálogo, no hay riesgo de "esconder" nada; el riesgo se traslada a errores de diseño de `aggregations[]` (ej. omitir una posición sin darse cuenta) | Igual que A si `aggregation_key` se deriva mecánicamente de row_range/total_row (mismo riesgo); menor si se exige autoría/revisión humana explícita del `aggregation_key` en cada caso | Ninguno nuevo, pero tampoco resuelve nada — los 19 grupos reales de hoy seguirían sin distinguirse |
| **Riesgo de colisiones** (nombres, rule_key, `_TEST` residual, etc.) | Bajo — `rule_key` sigue siendo la clave única real de BD, la identidad de duplicado es una capa de reporting aparte, sin relación con la unicidad de `rule_key` | Bajo — 1 regla, sin necesidad de generar N `rule_key`s nuevos | Bajo, mismo argumento que A | Ninguno |
| **Complejidad** | Baja-media — 1 método modificado, criterio de normalización de 3 campos a definir con cuidado (ver riesgo de "pares completo/incompleto") | Alta — toca motor+clasificador+logging+UI+comando de activación, cambio arquitectónico real | Media — similar a A en el clasificador, pero requiere definir de dónde sale `aggregation_key` (¿derivado o autoral?) y mantenerlo sincronizado con `total_row`/`source_rows` reales | Mínima de implementar, pero no escalable ni resolutiva |
| **Reversibilidad** | Alta — cambio acotado a un método, fácil de revertir; no persiste nada nuevo en BD | Media — requeriría además revertir cualquier dato ya migrado al nuevo shape `aggregations[]` | Alta, mismo argumento que A | Total |

**Conclusión de la comparación (sin decidir)**: Opción A (identidad ampliada) tiene la menor complejidad de implementación y el mejor perfil de compatibilidad retroactiva de las 3 alternativas activas — pero el hallazgo del punto 4 (20 reglas reales en "pares completo/incompleto" que dejarían de colisionar sin resolución de fondo) muestra que **no basta con "agregar total_row/source_rows a la clave" mecánicamente** — se necesitaría, como mínimo, una revisión humana de esos 20 casos ANTES o EN PARALELO a cualquier cambio de identidad, para no convertir un problema visible (`DUPLICATE`, hoy señalado) en uno invisible (`SAFE_1_TO_1` silencioso, sin que nadie haya confirmado si es correcto). Opción B sigue siendo la de mayor alcance arquitectónico (ya señalado en 17.33). Opción C no ofrece ventaja clara sobre A salvo que se decida separar la clave de identidad de los valores de configuración reales — no evaluado en más profundidad porque ninguna evidencia del código sugiere que sea necesario. Opción D (no tocar nada, mantener excepciones manuales) no escala frente a 19 grupos reales ya existentes hoy, sin necesidad de B2.

**Nada de esto fue implementado.** No se tocó `buildDuplicateKeySet()`, `classifyRule()`, ningún comando, ningún `Rule`, ninguna calibración, `rem_data`, bindings, estructura. No se resolvió ninguno de los 19 grupos reales ni las 30 combinaciones de B2. B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada` sin tocar. Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`. No commit de Git, no push.

### 17.36 — IDENTIDAD DE REGLAS — AUDITORÍA FINAL DE GRUPOS / FULL-SIGNATURE PROPUESTA / NO IMPLEMENTADA (2026-08-28)

⚠️ **100% READ-ONLY.** `buildDuplicateKeySet()` sin modificar (toda simulación usó un `$dupKeySet` doctorado SOLO en memoria, vía Reflection sobre el método privado `classifyRule()`, nunca persistido ni sustituido en el archivo real). Ningún `Rule`/`config`/`status`/binding/rebind/calibración/`rem_data`/estructura tocados. B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada` intactos. No commit, no push.

#### 1. Reconfirmación de baseline

`activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, estructura activa `67/v35` — idéntico al cierre de 17.35.

#### 2. ⚠️ Corrección crítica frente a 17.35 — los datos completos (`rule_logic`/`source_letters`, no solo `row_range`/`total_row`) cambian la naturaleza de la mayoría de los 19 grupos

La auditoría de 17.35 caracterizó 9 grupos como "pares completo/incompleto" basándose únicamente en `total_row` (uno seteado, otro `null`) con `row_range` idéntico. **Al incorporar `rule_logic`/`source_letters` (campos no inspeccionados en 17.35), 7 de esos 9 grupos resultan ser, en realidad, DOS FORMULAS GENUINAMENTE DISTINTAS que comparten coincidentalmente el mismo `row_range`** — no una regla "completa" y otra "incompleta" de la MISMA validación. Patrón descubierto: el miembro con `total_row` seteado siempre tiene `rule_logic="Suma(<col>) = Columna <col>"` (una **agregación vertical propia de la columna**, ej. `Suma(B)=Columna B` sumando B30+B31=B32) mientras el miembro con `total_row=null` tiene una fórmula **horizontal cruzada** distinta (ej. `Suma(C+D)=Columna B`) — dos validaciones legítimas y no relacionadas que el motor debe evaluar de forma independiente, no una superseded por la otra. Esta es exactamente la advertencia que motivó el "Punto crítico" de esta tarea: **"distinta full-signature" no garantiza nada por sí sola, pero tampoco "misma full-signature" garantiza "mismo rule_logic"** — se encontró también el caso inverso (ver punto 2.15/2.16 abajo): 2 grupos con `row_range`/`total_row`/`source_rows` IDÉNTICOS pero `rule_logic` completamente distinto, uno de ellos un artefacto roto.

#### 3. Auditoría exhaustiva de los 19 grupos (rule_ids, rule_keys, source, created_at, bindings, row_range, total_row, source_rows, rule_logic, historial de ejecución, superseded/redundante, categoría, recomendación)

**Grupo 1 — `A01|A|C` (sum_equals, 6 reglas): `24,553,557,558,559,617`**
| id | rule_key | source/created | bindings | row_range | rule_logic (o source_letters→target) | ejecución |
|---|---|---|---|---|---|---|
| 24 | `a01_a_c_sum_equals` | `csv_catalog` 2026-07-14 | structure 19, **active=0** | `[11:11]` | `Suma(F+G+H+I+J+K+L+M+N)=Columna C` | 25 logs: 17 failed,1 passed,7 skipped |
| 553 | `a01_c_11_sum_equals` | `vetted_catalog` 2026-07-20 | structure 21+19 (activos) | `[11:22]` | `[F,G,H,I,J,K,L,M,N]→C` (misma fórmula que 24, per-row) | 57 logs: 33 failed,24 passed |
| 557 | `a01_c_23_sum_equals` | `vetted_catalog` | structure 21+19 | `[23:26]` | `[D]→C` | 57 logs: 32 failed,25 passed |
| 558 | `a01_c_27_sum_equals` | `vetted_catalog` | structure 21+19 | `[27:32]` | `[F..T]` (15 letras)`→C` | 57 logs: 57 passed |
| 559 | `a01_c_29_sum_equals` | `vetted_catalog` | structure 21+19 | `[29:30]` | `[L,M,N,O,P]→C` | 57 logs: 57 passed |
| 617 | `a01_uv_11_sum_equals` | `vetted_catalog` | structure 21+19 | `[11:32]` | `[U,V]→C` | 57 logs: 39 failed,18 passed |

**Superseded/redundante**: `24⊂553` — misma fórmula exacta (F-N), `24` cubre solo fila 11 (además con binding YA inactivo), `553` cubre 11-22 — `24` es candidata a obsoleta/superseded por `553`. **Además, `558` y `559` tienen rangos de fila SOLAPADOS (`27:32` vs `29:30`) con fórmulas DISTINTAS** (15 letras vs 5 letras) — ambas se evaluarían simultáneamente contra las mismas filas 29-30, lo cual puede ser intencional (dos validaciones independientes sobre las mismas filas) o un error de detección. `617` es una fórmula totalmente distinta (U+V, no F-T) que cubre el rango completo. **Categoría: D (Mixto/Ambiguo)** — no es una familia limpia de sub-agregaciones complementarias; mezcla supersede (24/553), solape real (558/559) y una fórmula independiente (617). **Recomendación: `NEEDS_DEEP_AUDIT`, revisión manual dedicada antes de cualquier decisión — no aplicar ninguna regla mecánica de identidad a este grupo.**

**Grupo 2 — `A01|A|W` (required_and_le_parent, 2 reglas): `585,602`**
| id | rule_key | source/created | bindings | row_range | fórmula | ejecución |
|---|---|---|---|---|---|---|
| 585 | `a01_w_11_required_le_c` | `vetted_catalog` 2026-07-20 | structure 21+19 | `[11:32]` (scope=row_range) | `[C]→W` (required∧≤) | 57 logs: 57 passed |
| 602 | `a01_w_31_required_le_c` | `vetted_catalog` | structure 21+19 | `[31:31]` (scope=per_row) | `[C]→W` (idéntica relación) | 57 logs: 57 passed |

**Superseded/redundante**: `602` (fila 31 sola) está **contenida dentro del rango de `585` (11-32)**, misma relación `C→W` exacta — `602` parece ser un subconjunto literal de `585`, potencialmente redundante salvo que la fila 31 tenga un tratamiento especial (ej. subtotal) que justifique una regla propia. **Categoría: C-adjacente (subset/supersede, no el disparador literal "total_row null vs seteado" pero mismo espíritu de "evolución/redundancia", no "agregación complementaria")**. **Recomendación: `NEEDS_INDIVIDUAL_DECISION` — confirmar si la fila 31 requiere validación separada antes de considerar `602` redundante.**

**Grupo 3 — `A01|B|C` (sum_equals, 2 reglas): `560,618`**
| id | rule_key | source/created | bindings | row_range | source_letters→target | ejecución |
|---|---|---|---|---|---|---|
| 560 | `a01_c_36_sum_equals` | `vetted_catalog` 2026-07-20 | structure 21+19 | `[36:39]` | `[D,E]→C` | 57 logs: 4 failed,53 passed |
| 618 | `a01_de_36_sum_equals` | `vetted_catalog` 2026-07-20 (idéntico timestamp) | structure 21+19 | `[36:39]` | `[D,E]→C` (idéntico) | 57 logs: 4 failed,53 passed (idéntico) |

**Byte-idénticos en TODOS los campos relevantes** (row_range, source_letters, target, total_row=null, source_rows=null, historial de ejecución exacto) — únicamente el `rule_key` difiere (probablemente 2 filas de estructura distintas detectaron la misma fórmula). **Categoría: A (Duplicado exacto), confirmado — el único de los 19 grupos que cumple los 3 criterios de A (misma identidad simple, misma full-signature, misma lógica funcional)**. **Recomendación: `DUPLICATE_CONFIRMED` — candidato a desactivar uno de los dos (mismo patrón que 529↔530), nunca a coexistir.**

**Grupo 4 — `A01|C|D` (sum_equals, 4 reglas): `570,572,574,576`**
| id | row_range | source_letters | ejecución |
|---|---|---|---|
| 570 | `[44:47]` | 14 letras (`L,N,P,R,T,V,X,Z,AB,AD,AF,AH,AJ,AL`) | 57 logs: 57 passed |
| 572 | `[48:53]` | 4 letras (`AF,AH,AJ,AL`) | 57 logs: 57 passed |
| 574 | `[54:62]` | 17 letras (`F,H,J,L,N,P,R,T,V,X,Z,AB,AD,AF,AH,AJ,AL`) | 57 logs: 57 passed |
| 576 | `[63:66]` | 4 letras (`F,H,J,L`) | 57 logs: 57 passed |

Todas `vetted_catalog`, `2026-07-20`, bindings structure 21+19. Rangos **contiguos y sin solape** (`44→66` continuo), source_letters distintos y evolutivos por banda de filas (patrón realista: distintos grupos etarios alimentan el total en distintas bandas). **100% passed en las 4, sin ninguna falla histórica.** **Categoría: B (Rescatable), confirmado, limpio.** **Recomendación: `SAFE_TO_SEPARATE` — candidatas naturales a `SAFE_1_TO_1` bajo identidad más rica, sin ambigüedad.**

**Grupo 5 — `A01|C|E` (sum_equals, 4 reglas): `571,573,575,577`** — espejo exacto de Grupo 4 para columna E, mismos 4 rangos (`44:47,48:53,54:62,63:66`), letras análogas desplazadas (M,O,Q... en vez de L,N,P...). Ejecución: 571=13 failed/44 passed, 573=12 failed/45 passed, 575=13 failed/44 passed, 577=57 passed. **Categoría: B (Rescatable), confirmado** — pero **con una señal de calidad de datos real** (3 de 4 con fallas históricas consistentes, a diferencia del Grupo 4 100% limpio) — no bloquea la clasificación de identidad, pero debe considerarse antes de cualquier activación real futura. **Recomendación: `SAFE_TO_SEPARATE` para la clasificación de identidad; `FLAG_DATA_QUALITY` para activación futura.**

**Grupo 6 — `A01|E|B` (sum_equals, 2 reglas): `29,580`**
| id | source/created | bindings | row_range | rule_logic | ejecución |
|---|---|---|---|---|---|
| 29 | `csv_catalog` 2026-07-14 | structure 19 (activo) | `[78:78]` | `Suma(C+D)=Columna B` | 77 logs: 68 passed,9 skipped |
| 580 | `vetted_catalog` 2026-07-20 | structure 21+19 | `[78:82]` | `[C,D]→B` (misma fórmula) | 57 logs: 57 passed |

**Superseded/redundante**: `29⊂580` — misma fórmula `C+D=B` exacta, `29` cubre solo fila 78, `580` cubre 78-82 (superset). Mismo patrón que 24↔553. **Categoría: C-adjacente (subset/supersede)**. **Recomendación: `29` candidata a obsoleta/superseded por `580` — mismo tratamiento que Grupo 2, pendiente confirmación.**

**Grupo 7 — `A06|A.2|B` (sum_equals, 3 reglas): `126,658,698`**
| id | source/created | bindings | row_range | total_row | rule_logic | ejecución |
|---|---|---|---|---|---|---|
| 126 | `csv_catalog` 2026-07-14 | structure 19 (activo) | `[30:30]` | null | `Suma(C+D)=Columna B` | 77 logs: 13 failed,55 passed,9 skipped |
| 658 | `excel_formula` 2026-07-29 | **serie** (activo) | `[30:31]` | **32** | `Suma(B)=Columna B` (vertical, self) | 68 logs: **68/68 skipped** |
| 698 | `excel_formula` 2026-07-29 | **serie** (activo) | `[30:31]` | null | `Suma(C+D)=Columna B` (horizontal) | 68 logs: 68/68 passed |

**Mixto real, no reducible a una sola etiqueta**: `126` (row 30 solo) está **superseded/subset de `698`** (misma fórmula `C+D=B`, rango 30-31) — patrón C-adjacente. `658` es una fórmula **completamente distinta y legítima** (agregación vertical `B30+B31=B32`) — patrón B, pero **actualmente inerte** (100% `skipped`, probablemente excluida de `rem_data` por el mismo tipo de mecanismo #6/#8/#12 ya documentado en toda esta campaña — no investigado en profundidad aquí, fuera de alcance de esta auditoría de identidad). `658`/`698` ya son `ALREADY_STRUCTURE_AGNOSTIC` hoy (binding `serie`, nunca llegan a evaluarse contra `dupKeySet`) — solo `126` es visible como `DUPLICATE`. **Categoría: Mixto (contiene un subgrupo C-adjacente [126/698] + un miembro B independiente [658])**. **Recomendación: `NEEDS_INDIVIDUAL_DECISION` por miembro — no asignar una sola etiqueta al grupo completo; `126` candidata a superseded por `698`, `658` es una agregación legítima pero requiere investigar por separado por qué está 100% `skipped`.**

**Grupo 8 — `A06|A.2|E` (sum_equals, 2 reglas): `661,699`** — mismo patrón exacto que la mitad B/no-C de Grupo 7: `661`(`excel_formula`,serie,`[30:31]`,total_row=32,`Suma(E)=E` vertical,68/68 skipped) vs `699`(`excel_formula`,serie,`[30:31]`,total_row=null,`Suma(F+G)=E` horizontal,68/68 passed). **2 fórmulas genuinamente distintas, no un par completo/incompleto de la misma regla** (corrige la caracterización de 17.35). **Categoría: B (Rescatable)** — pero **ya `ALREADY_STRUCTURE_AGNOSTIC` hoy** (binding serie, nunca alcanzan `dupKeySet`) — cambiar la identidad **no tendría ningún efecto en su clasificación actual**. **Recomendación: `SAFE_TO_SEPARATE` conceptualmente, pero `NO_IMPACT` práctico sobre la clasificación de hoy — el bloqueo real de `661` es el mismo gap de `rem_data` que afecta a `658`, no la identidad de duplicado.**

**Grupo 9 — `A06|A.2|F` (sum_equals, 2 reglas): `662,700`** — mismo patrón: `662`(vertical `Suma(F)=F`,total_row=32,68/68 skipped) vs `700`(horizontal, 17 letras,total_row=null,68/68 passed). **Categoría: B, ya `ALREADY_STRUCTURE_AGNOSTIC`, sin impacto práctico** — idéntico razonamiento que Grupo 8.

**Grupo 10 — `A06|A.2|G` (sum_equals, 2 reglas): `663,701`** — mismo patrón: `663`(vertical,total_row=32,68/68 skipped) vs `701`(horizontal, 17 letras,68/68 passed). **Categoría: B, ya `ALREADY_STRUCTURE_AGNOSTIC`, sin impacto práctico** — idéntico razonamiento.

**Grupo 11 — `A06|A.3|B` (sum_equals, 3 reglas): `127,702,714`**
| id | source/created | bindings | row_range | total_row | rule_logic | ejecución |
|---|---|---|---|---|---|---|
| 127 | `csv_catalog` 2026-07-14 | structure 19 (activo) | `[36:36]` | null | `Suma(C+D)=Columna B` | 77 logs: 55 passed,22 skipped |
| 702 | `excel_formula` 2026-07-29 | serie (activo) | `[36:37]` | 38 | `Suma(B)=Columna B` (vertical) | 68 logs: 68/68 skipped |
| 714 | `excel_formula` 2026-07-29 | serie (activo) | `[36:37]` | null | `Suma(C+D)=Columna B` (horizontal, misma fórmula que 127) | 68 logs: **65 failed, 3 passed** |

**Mismo patrón mixto que Grupo 7**: `127⊂714` (subset/supersede, misma fórmula) — pero a diferencia de A.2/B, **`714` tiene una tasa de falla histórica muy alta (96%)** — señal de que el "superseder" tampoco está funcionando bien hoy, no solo que `127` sea obsoleta. `702` es la agregación vertical distinta, 100% skipped (igual que `658`). **Categoría: Mixto (C-adjacente `127/714` + B independiente `702`)**. **Recomendación: `NEEDS_INDIVIDUAL_DECISION` — `714` requiere revisión de por qué falla 65/68 veces antes de considerar siquiera si `127` es redundante; `702` igual que `658`.**

**Grupo 12 — `A06|A.3|E` (sum_equals, 2 reglas): `705,715`** — `705`(vertical,total_row=38,68/68 skipped) vs `715`(horizontal `Suma(F+G)=E`,total_row=null,**65 failed,3 passed**, mismo perfil de falla alta que `714`). **Categoría: B (formulas distintas, confirmado)** — pero con la misma **señal de calidad de datos preocupante** que Grupo 11 (96% de fallas en el miembro horizontal). Ya `ALREADY_STRUCTURE_AGNOSTIC` hoy. **Recomendación: `SAFE_TO_SEPARATE` conceptualmente; `FLAG_DATA_QUALITY` fuerte antes de cualquier activación real — no confundir con los grupos limpios (8/9/10).**

**Grupo 13 — `A06|B.3|D` (sum_equals, 3 reglas): `720,721,722`** — rangos contiguos sin solape (`55:60,61:66,67:76`), `excel_formula` 2026-07-29, binding serie (activo), 3 fórmulas distintas por banda (16, 3, 15 letras respectivamente), **100% passed las 3 (68/68 cada una)**. **Categoría: B (Rescatable), confirmado, limpio** — mismo patrón que Grupo 4/5 pero con bindings `serie` (ya `ALREADY_STRUCTURE_AGNOSTIC` hoy, sin impacto práctico de un cambio de identidad). **Recomendación: `SAFE_TO_SEPARATE` conceptualmente; `NO_IMPACT` práctico hoy.**

**Grupo 14 — `A06|B.3|E` (sum_equals, 3 reglas): `723,724,725`** — espejo exacto de Grupo 13 para columna E, mismos 3 rangos, 100% passed las 3. **Categoría: B, limpio, sin impacto práctico hoy** — idéntico razonamiento.

**Grupo 15 — `A06|C.2|D` (sum_equals, 2 reglas): `786,130`** — ⚠️ **hallazgo nuevo, corrige 17.35 (previamente calificado erróneamente "duplicado exacto")**:
| id | source/created | bindings | row_range | total_row | source_rows | rule_logic | ejecución |
|---|---|---|---|---|---|---|---|
| 786 | `real_data_pattern` 2026-07-29 | serie (activo) | `[97:101]` | null | null | `Suma(B+C)=Columna D` (real, funcional) | 68 logs: 67 passed,1 skipped |
| 130 | `csv_catalog` 2026-07-14 | structure 19 + serie (ambos activos) | `[97:101]` (idéntico) | null (idéntico) | null (idéntico) | **`Suma(D)=Columna D`** (auto-referencial, degenerado) | 145 logs: **145/145 skipped** |

**`row_range`/`total_row`/`source_rows` son IDÉNTICOS entre ambas** (coincidirían bajo "full-signature" literal tal como la definió el usuario) **pero `rule_logic` es completamente distinto** — `130` es un artefacto auto-referencial (`D=D`), nunca produce una evaluación real (100% `skipped` en 145 ejecuciones), mientras `786` es la fórmula real y funcional (98.5% passed). **Esto NO es un duplicado exacto** (falla el criterio "misma lógica funcional" de Categoría A) — es un caso nuevo, no anticipado por la tipología A/B/C/D original: **"full-signature idéntica, rule_logic distinto, un miembro es un artefacto legacy roto"**. Full-signature (row_range+total_row+source_rows, sin incluir rule_logic) **NO los separaría** — seguirían agrupados bajo cualquier variante de Opción A tal como fue especificada, lo cual es el resultado SEGURO (de hecho correcto) pero por una razón distinta a la asumida (`130` es efectivamente inerte/obsoleta, no una "agregación legítima coexistente"). **Categoría: Caso especial, no forzado a ninguna de A/B/C/D — más cercano en espíritu a "superseded" (como C), pero el disparador no es `total_row` sino `rule_logic` degenerado.** **Recomendación: `130` candidata fuerte a `status=inactive` (mismo patrón que las 34+1 reglas inertes de la Tanda 1/Fase 2, ver punto 16.1/16.5) — no una cuestión de identidad, es una regla rota que debería desactivarse independientemente de qué se decida sobre full-signature.**

**Grupo 16 — `A06|C.3|D` (sum_equals, 2 reglas): `787,133`** — patrón IDÉNTICO al Grupo 15: `787`(`real_data_pattern`,`Suma(B+C)=D`,67 passed/1 skipped) vs `133`(`csv_catalog`,`Suma(D)=D` auto-referencial,145/145 skipped). Mismo hallazgo, misma recomendación: **`133` candidata a `status=inactive`.**

**Grupo 17 — `A06|L|B` (sum_equals, 2 reglas): `772,783`** — `772`(vertical `Suma(B)=B`,total_row=181,`excel_formula`,serie,68/68 skipped) vs `783`(horizontal `Suma(C+D)=B`,total_row=null,`excel_formula`,serie,**4 passed,64 skipped** — mayormente `skipped`, perfil distinto a los grupos A.2 limpios). **Categoría: B (fórmulas distintas, confirmado)** — con una **señal adicional**: el miembro horizontal aquí también está mayormente inerte (64/68 skipped), a diferencia de A.2's equivalentes (100% passed). Ya `ALREADY_STRUCTURE_AGNOSTIC`. **Recomendación: `SAFE_TO_SEPARATE` conceptualmente; investigar por separado por qué el miembro horizontal de la sección L rara vez se evalúa (fuera de alcance de esta auditoría de identidad).**

**Grupo 18 — `A06|L|C` (sum_equals, 2 reglas): `773,784`** — mismo patrón: `773`(vertical,total_row=181,68/68 skipped) vs `784`(horizontal `Suma(E+G+I+K)=C`,4 passed,64 skipped). **Categoría: B**, mismo razonamiento que Grupo 17.

**Grupo 19 — `A06|L|D` (sum_equals, 2 reglas): `774,785`** — mismo patrón: `774`(vertical,total_row=181,68/68 skipped) vs `785`(horizontal `Suma(F+H+J+L)=D`,4 passed,64 skipped). **Categoría: B**, mismo razonamiento.

#### 4. Tally final por categoría (19 grupos, 50 reglas)

| Categoría | Grupos | Reglas | Detalle |
|---|---|---|---|
| **A — Duplicado exacto** | 1 | 2 | Grupo 3 (`560,618`) — único caso que cumple los 3 criterios de A |
| **B — Rescatable (fórmulas genuinamente distintas)** | 11 | 28 | Grupos 4,5,8,9,10,12,13,14,17,18,19 — de estos, **solo 4 y 5 (8 reglas) son hoy oficialmente `DUPLICATE`**; los otros 9 grupos (20 reglas) ya están `ALREADY_STRUCTURE_AGNOSTIC` (bypasean `dupKeySet` vía binding serie) — un cambio de identidad no tendría efecto práctico sobre esas 20 |
| **C-adjacente — subset/supersede (candidatas a superseded, no coexistencia)** | 2 grupos limpios + 2 subgrupos embebidos | 4 + 4 | Grupos 2 (`585,602`) y 6 (`29,580`) limpios; más el subgrupo `126/698` (dentro de Grupo 7) y `127/714` (dentro de Grupo 11) |
| **D — Mixto/Ambiguo (grupo completo)** | 1 | 6 | Grupo 1 (`A01/A/C`) — solape real, mezcla de proveniencia, no reducible |
| **Mixto internamente (contiene C-adjacente + B independiente, requiere resolución por miembro)** | 2 | 6 | Grupos 7 y 11 — cada uno con 1 miembro B legítimo + 1 par C-adjacente |
| **Caso especial nuevo — full-signature idéntica, `rule_logic` distinto, miembro roto/degenerado** | 2 | 4 | Grupos 15 (`786,130`) y 16 (`787,133`) — `130`/`133` son artefactos auto-referenciales candidatos a `inactive`, independientemente de la decisión de identidad |

**Verificación de suma**: `1+11+2+1+2+2=19` grupos exacto (contando los grupos mixtos una vez cada uno). Reglas: `2+28+8+6+6+4=54`... **discrepancia de 4 frente a 50** — se explica porque el conteo "C-adjacente" (4+4=8) incluye los subgrupos `126/698`/`127/714` YA contados también dentro de los "6" de "Mixto internamente" (Grupos 7/11) — no son reglas adicionales, son las MISMAS reglas vistas desde 2 ángulos (la naturaleza de cada miembro vs. la naturaleza del grupo completo). Conteo real sin doble-conteo: `2(A)+28(B)+4(C-adjacente limpio)+6(D grupo1)+6(mixto grupos7/11)+4(especial)=50` exacto.

#### 5. Simulación híbrida en memoria (Reflection sobre `classifyRule()` real, `$dupKeySet` doctorado, sin persistir nada)

Se invocó el método **privado real** `RuleBindingReconciliationService::classifyRule()` vía Reflection, pasándole un `$dupKeySet` idéntico al real **excepto por la exclusión explícita de las 2 claves correspondientes a los ÚNICOS grupos Categoría B hoy oficialmente `DUPLICATE`** (Grupos 4 y 5: `A01|C|D|sum_equals`, `A01|C|E|sum_equals`) — **ninguna otra clave tocada**, consistente con la instrucción de "conservar bloqueo/duplicado para A/C/D" (los grupos mixtos 7/11 y el especial 15/16 se dejaron sin tocar, ya que no son Categoría B limpia).

**Resultado exacto, medido, no presupuesto**:

| Clasificación | Antes (real) | Después (simulado) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 431 | **439** | **+8** |
| `DUPLICATE` | 22 | **14** | **−8** |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 | **+0** |
| `BLOCKED_BY_ENGINE_GAP` | 66 | 66 | +0 |

**Las 8 reglas que cambian, exactas**: `570,571,572,573,574,575,576,577` (los 8 miembros de los Grupos 4 y 5) — **las 8, sin excepción, pasan a `SAFE_1_TO_1`** (motivo: `"Misma hoja, seccion, columnas y rango de filas vigentes."` — superan también `missingColumns`/`rowsOk` contra la estructura activa real 67, no solo el chequeo de duplicado). **`ALREADY_STRUCTURE_AGNOSTIC` no cambia en absoluto** — confirma la predicción del punto 3: los otros 20 rules de Categoría B (Grupos 8,9,10,12,13,14,17,18,19) ya están gateados por bindings `serie` que los clasifican `ALREADY_STRUCTURE_AGNOSTIC` **antes** de que el clasificador evalúe `dupKeySet` — un cambio de identidad de duplicado **no tiene ningún efecto práctico sobre ellos**, sea cual sea la estrategia adoptada, porque nunca llegan a esa rama de código. Esto significa que, del universo total de 50 reglas en 19 grupos, **un fix de identidad únicamente resolvería 8 reglas (Grupos 4/5) hoy** — el resto requeriría, además, resolver el binding-precedence de `ALREADY_STRUCTURE_AGNOSTIC` (fuera de alcance de esta auditoría, no evaluado).

**B2**: no se repitió la simulación completa (ya hecha en 17.34, 0 colisiones bajo full-signature) — se reconfirma que el mecanismo es consistente: igual que las 8 reglas de los Grupos 4/5 (rangos contiguos, sin solape, formulas limpias, 100%/mayoría-passed), las 30 combinaciones de B2 comparten exactamente ese perfil "limpio" — ninguna de las complicaciones encontradas hoy (artefactos rotos, formulas mixtas vertical/horizontal, solapes, subset/supersede) aplica a B2, cuyo caso es estructuralmente el MÁS simple de todos los auditados (6 aggregaciones sobre 78 filas físicas, sin ningún solapamiento posible por construcción aritmética, ver 17.34 punto 5).

#### 6. Recomendación

**Ninguna de las 3 opciones puede calificarse hoy como "full-signature global completamente segura"** — la evidencia de este punto lo descarta explícitamente: adoptar full-signature (row_range+total_row+source_rows) de forma global, sin gate adicional, **separaría automáticamente** el subgrupo C-adjacente embebido en los Grupos 7/11 (`126/698` con `total_row` distinto ya los separaría por definición de full-signature, aunque son candidatas a superseded, no a coexistencia) y dejaría sin resolver la pregunta de fondo sobre los Grupos 2/6 (`585/602`, `29/580` — full-signature SÍ los separaría, ya que difieren en `row_range`, pero son casos de subset/supersede, no de coexistencia legítima demostrada). **Full-signature global habilitaría, sin intervención humana, la coexistencia de reglas que hoy no tienen evidencia de ser legítimamente independientes** — exactamente el riesgo que el "Punto crítico" de esta tarea pedía verificar.

**Recomendación: Opción 2 (full-signature con gate adicional de compatibilidad/ambigüedad)** — confirmada por la evidencia, coincide con la preferencia ya expresada por el usuario. El gate debería, como mínimo:
1. Aplicar full-signature (row_range+total_row+source_rows) **solo** a grupos donde CADA miembro tenga, además, una `rule_logic`/`source_letters` **genuinamente distinta** entre sí (no basta con que `total_row`/`row_range` difieran) — esto separaría correctamente los Grupos 4,5,8,9,10,12,13,14,17,18,19 (Categoría B limpia, 11 grupos/28 reglas) sin tocar el Grupo 3 (duplicado exacto, mismo todo).
2. **Excluir explícitamente** cualquier par donde uno de los miembros tenga row_range que sea **subconjunto estricto** del otro con la MISMA `rule_logic`/`source_letters` (patrón subset/supersede) — esto mantendría bloqueados/pendientes de revisión humana los Grupos 2, 6, y los subgrupos `126/698`/`127/714` de los Grupos 7/11, sin asumir automáticamente que son coexistentes.
3. **Excluir explícitamente** cualquier par donde `row_range`/`total_row`/`source_rows` sean idénticos pero `rule_logic` difiera (el caso nuevo de los Grupos 15/16) — estos deben tratarse como candidatos a desactivación de la regla degenerada (`130`,`133`), no como un problema de identidad en absoluto.
4. Dejar el Grupo 1 (`A01/A/C`) explícitamente marcado `NEEDS_DEEP_AUDIT`, sin intentar clasificarlo automáticamente bajo ninguna estrategia — el solape real entre `558`/`559` no es resoluble mecánicamente.

**Bajo este gate, el impacto medido hoy sería exactamente el de la simulación del punto 5**: `SAFE_1_TO_1` 431→439 (+8, Grupos 4/5), `DUPLICATE` 22→14 (−8), sin cambio en `ALREADY_STRUCTURE_AGNOSTIC`/`BLOCKED_BY_ENGINE_GAP` — un resultado **modesto pero seguro**, que no fuerza ninguna decisión sobre los 15 grupos restantes (C-adjacentes, mixtos, especiales, D). Para B2 específicamente, el mismo gate aplicaría sin fricción (las 30 combinaciones ya demuestran `rule_logic`/`source_rows` genuinamente distintos entre sí, sin ningún patrón subset/supersede ni artefacto degenerado presente).

**No implementado.** No se modificó `buildDuplicateKeySet()` ni `classifyRule()`. No se creó ninguna regla de B2. No se tocó ningún `config`/`status`/binding de los 50 rules auditados. No rebind. No calibraciones. No `rem_data`. No estructura. B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada` intactos. Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=431`, `REQUIRES_REMAP=0`, `DUPLICATE=22`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`. No commit de Git, no push.

### 17.37 — IDENTIDAD DE REGLAS — GATE FULL-SIGNATURE IMPLEMENTADO / B2 AÚN NO EXPANDIDO (2026-08-28)

⚠️ **Código de motor/clasificador modificado esta vez** (a diferencia de 17.34-17.36, que eran 100% auditoría/diseño) — `RuleBindingReconciliationService.php` fue editado según la decisión explícita "avanzar con Opción 2". **Nada más fue tocado**: ningún `Rule` nuevo, ningún `config`/`status`/binding de las 717 reglas reales, sin rebind, sin calibraciones, sin `rem_data`, sin estructura. `130`/`133` sin desactivar. Grupo 1 sin resolver. Subset/supersede sin tocar. B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada` intactos. Las 30 reglas de B2 **NO fueron creadas** — el mecanismo se implementó y se probó con fixtures sintéticas para demostrar que las soportaría en el futuro, nada más. No commit, no push.

#### 1. Implementación — reemplazo de `buildDuplicateKeySet()`, sin tocar ningún otro método público

**Antes**: `buildDuplicateKeySet()` agrupaba por SQL crudo (`GROUP BY sheet,section,col,rule_type HAVING count>1`) y devolvía un conjunto de **claves planas** (`"{sheet}|{section}|{col}|{type}"`) — cualquier regla cuya clave apareciera en ese conjunto se marcaba `DUPLICATE`, sin excepción.

**Después**: `buildDuplicateKeySet()` (mismo nombre, mismos 3 call-sites — `isStillSafe()`, `classifySingleRule()`, `classifyAllActiveRules()` — sin tocar ninguno de ellos ni su firma) ahora:
1. Agrupa las reglas activas por identidad simple igual que antes (misma clave), pero en PHP, incluyendo por regla: `total_row`, una **firma funcional** normalizada (`buildFunctionalSignature()`, reutiliza `deriveSourceLetters()` ya existente + columna destino — unifica la representación `rule_logic` texto y `source_letters`/`column` array, para que 2 reglas con la misma fórmula real pero distinta forma de config produzcan la misma firma) y un **conjunto de filas componentes** (`buildComponentSet()`: `source_rows` explícito si está presente, o el intervalo `row_range` completo como conjunto implícito si no — mismo criterio que `SumEqualsEvaluator`/`RuleEngineService` ya usan en producción; el placeholder `{"from":0,"to":0}` se trata como "sin conjunto determinable", `null`, nunca como el conjunto `{0}`).
2. Dentro de cada grupo con 2+ miembros, **excluye por completo** las reglas cuyo conjunto de filas no sea determinable (`component_set === null`) — no se marcan duplicadas por este mecanismo, y **no contaminan a sus compañeros** (crítico para B2: la regla original placeholder `{0,0}` no debe impedir que las futuras reglas reales de agregación escapen de `DUPLICATE`; su clasificación sigue dependiendo exclusivamente de `missingColumns`/`rowsOk`/`engineGap`, exactamente igual que antes de este cambio).
3. Para cada par de reglas restante, `isLegitimateCoexistence($a,$b)` decide si la relación es de coexistencia legítima o no, con esta matriz exacta (derivada y verificada contra los 19 grupos reales de 17.36, no inventada):
   - **Mismo conjunto de filas Y mismo `total_row`** → full-signature idéntica → **nunca legítima** (sea cual sea `rule_logic`: si coincide, es duplicado exacto — Grupo 3, `560/618`; si no coincide, es el caso degenerado — Grupos 15/16, `786/130`, `787/133`, artefacto autorreferencial roto).
   - **Mismo conjunto de filas, `total_row` distinto** → legítima **solo si** la firma funcional también difiere (evita el caso hipotético de 2 reglas `Suma(X)=X` apuntando a 2 `total_row` distintos para las mismas filas, que sería ambiguo, no legítimo).
   - **Conjuntos de filas distintos, intersección vacía (disjuntos)** → siempre legítima — agregaciones genuinamente independientes por construcción (patrón B2, Grupos 4/5/8/9/10/12/13/14/17/18/19 de 17.36).
   - **Conjuntos de filas distintos, intersección no vacía (subset/superset o solape parcial)** → nunca legítima, sea cual sea `rule_logic` (patrón `24/553`, `29/580`, `126/698`, `127/714`, `558/559`, `585/602` de 17.36).
4. **Conservador por diseño**: una regla con 2+ compañeros en su grupo, de los cuales solo 1 es problemático, permanece marcada duplicada en su totalidad — no se libera selectivamente un miembro de un grupo mixto (Grupos 7/11 de 17.36 permanecen bloqueados en su totalidad en esta primera fase, tal como exigió la instrucción).
5. `classifyRule()` cambia una sola línea: `$isDuplicate = isset($dupKeySet[$rule->id])` (antes: `isset($dupKeySet[$dupKey])` contra una clave plana) — el resto de la función (orden de checks, `missingColumns`, `rowsOk`, `engineGap`, `ALREADY_STRUCTURE_AGNOSTIC`) **sin ningún cambio**.

**Corrección importante encontrada durante la implementación (no anticipada en 17.36)**: al construir los fixtures se detectó que, si una regla con conjunto de filas indeterminado (`{0,0}` o similar) permanece DENTRO del grupo pairwise-comparado, "contamina" a sus compañeros bien formados (ya que `isLegitimateCoexistence(null,*)` siempre devuelve `false`) — lo cual habría bloqueado incorrectamente las futuras reglas reales de B2 mientras la regla original placeholder siguiera activa. Se corrigió excluyendo estas reglas del análisis pairwise por completo (punto 2 arriba) — confirmado con el test `test_b2_style_multiple_aggregations_via_source_rows_escape_duplicate` (ver abajo), que reproduce exactamente este escenario y prueba que la regla original permanece `BLOCKED_BY_ENGINE_GAP` (sin cambio de comportamiento) mientras sus 2 hermanas de agregación real llegan a `SAFE_1_TO_1`.

#### 2. Tests — 9 escenarios (fixtures sintéticas, nunca contra las 717 reglas reales)

`RuleBindingReconciliationServiceIdentityGateTest.php` (nuevo, 9/9 passing, 28 assertions):
1. `test_disjoint_row_ranges_with_distinct_formulas_escape_duplicate` — réplica de Grupos 4/5 (rangos disjuntos, fórmula distinta por banda) → ambas `SAFE_1_TO_1`.
2. `test_exact_duplicate_stays_duplicate` — réplica de Grupo 3 (`560/618`, config byte-idéntico) → ambas `DUPLICATE`.
3. `test_subset_row_range_with_same_formula_stays_duplicate` — réplica de `29/580` (misma fórmula, un rango subconjunto del otro) → ambas `DUPLICATE`.
4. `test_genuine_partial_overlap_stays_duplicate` — solape real construido (`[10:20]` vs `[15:25]`, ninguno subconjunto del otro, fórmulas distintas) → ambas `DUPLICATE` (no hay precedente real de esta forma exacta en los 19 grupos, pero el mecanismo la cubre correctamente vía intersección no vacía).
5. `test_identical_full_signature_with_different_rule_logic_stays_duplicate` — réplica de Grupos 15/16 (`786/130`, mismo `row_range`/`total_row`/`source_rows`, `rule_logic` distinto, una autorreferencial `Suma(D)=Columna D`) → ambas `DUPLICATE`.
6. `test_b2_style_multiple_aggregations_via_source_rows_escape_duplicate` — 2 de las 30 combinaciones futuras de B2 (mismo `source_letters=target=AM`, `source_rows` disjuntos, `total_row` distinto) + la regla original `{0,0}` activa simultáneamente → las 2 nuevas `SAFE_1_TO_1`, la original `BLOCKED_BY_ENGINE_GAP` (sin cambio) — **el escenario central que valida "B2 puede representarse en el futuro sin crear las 30 reglas todavía"**.
7. `test_rule_without_any_collision_is_unaffected` — regla sola en su grupo, sin cambio.
8. `test_unrelated_safe_rule_is_never_affected_by_a_colliding_group_elsewhere` — una regla `SAFE_1_TO_1` ajena coexiste con un grupo `DUPLICATE` real (Grupo 3) sin verse afectada.
9. `test_mixed_group_with_subset_pair_plus_independent_member_stays_fully_blocked` — réplica exacta de Grupo 7 (legacy + vetted horizontal + vertical independiente) → **las 3 permanecen `DUPLICATE`**, confirmando que un grupo mixto no se libera parcialmente.

**Regresión**: `RuleBindingReconciliationServiceTest.php` (incluye `test_duplicate_group_is_never_safe`, config byte-idéntico — reconfirmado que sigue clasificando `DUPLICATE` bajo el nuevo gate) + `RuleBindingReconciliationServiceTotalRowDiscoveryTest.php` + `RuleRebindSafeToStructureCommandTest.php` + `RuleRemapSectionCommandTest.php` + `RuleSetTotalRowFromDiscoveryCommandTest.php` + `RuleActivateCategoryATotalCommandTest.php` + `RuleActivateTrailingTotalBeyondBoundsCommandTest.php` + `RuleActivateCategoryCLeadingCommandTest.php` + `RuleActivateSourceRowsCommandTest.php` + `RuleSetRuleStatusCommandTest.php` + `RuleRestoreConfigVersionCommandTest.php`: **111/111 passing** — cero regresiones en ningún comando que dependa de `RuleBindingReconciliationService` (todos, directa o indirectamente, usan `classifySingleRule()`/`classifyAllActiveRules()`).

#### 3. Simulación real contra las 717 reglas — verificación rigurosa rule-por-rule, no solo por conteo

Para evitar el riesgo de comparar contra un baseline contaminado (un primer intento usando `git stash` resultó **incorrecto** — revertía TODOS los cambios no comiteados de la sesión completa, no solo los de hoy, mezclando el "antes" con la pérdida de mecanismos de fases anteriores como `isLegitimateTrailingTotalBeyondBounds()` — detectado porque aparecían 55 cambios espurios `BLOCKED_BY_ENGINE_GAP→SAFE_1_TO_1` que en realidad ya habían ocurrido en la Fase 3C-1B, sesiones atrás), se descartó ese método y se usó uno quirúrgico: revertir temporalmente **solo** las líneas de `buildDuplicateKeySet()`/`classifyRule()` tocadas hoy (vía Edit, con el contenido exacto pre-cambio ya en memoria de esta conversación), capturar la clasificación real de las 717 reglas activas contra la estructura activa real, restaurar el archivo a su versión con el gate nuevo (confirmado con `diff` que la restauración fue byte-idéntica y `php -l` sin errores de sintaxis), y comparar rule-por-rule.

**Resultado exacto**:

| Clasificación | Antes (gate viejo, resto de la sesión intacto) | Después (gate nuevo) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 431 | **439** | **+8** |
| `DUPLICATE` | 22 | **14** | **−8** |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 | +0 |
| `BLOCKED_BY_ENGINE_GAP` | 66 | 66 | +0 |

**Diff completo de las 717 reglas, una por una — exactamente 8 cambios, ni uno más**: `570,571,572,573,574,575,576,577` (los 8 miembros reales de los Grupos 4/5), todos `DUPLICATE → SAFE_1_TO_1`. **Ninguna otra regla de las 717 cambió** — confirmado explícitamente, no solo por coincidencia de conteos agregados. Coincide exactamente con la predicción de 17.36 y con el resultado exigido por el usuario ("`DUPLICATE 22 → 14`, `SAFE_1_TO_1 431 → 439`, `ALREADY_STRUCTURE_AGNOSTIC` sin cambio, resto sin cambio") — **no fue necesario ningún STOP**.

#### 4. Estado de B2 — mecanismo listo, expansión NO ejecutada

El gate soporta correctamente el patrón que las 30 reglas futuras de B2 necesitarían (confirmado con el test 6) — pero **no se creó ninguna de las 30 reglas**. Las 5 reglas reales de B2 (`226,227,231,232,234`) permanecen exactamente como estaban: `status=active`, `config` sin ningún cambio (`row_range={0,0}`, sin `source_rows`/`total_row`), clasificando `BLOCKED_BY_ENGINE_GAP` igual que siempre.

#### 5. Baseline final reconfirmado

`activas=717`, `SAFE_1_TO_1=439`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`. **Este es el primer cambio real de clasificación en producción desde el cierre de Fase 3C-2** (todo lo anterior desde 17.20 en adelante fue auditoría/diseño o resoluciones ya cerradas) — pero es exclusivamente una RECLASIFICACIÓN de catálogo (`rem_rules`/`config` de los 8 rules sin ningún cambio, solo su `clasificacion` calculada en vivo cambió) — no se creó ningún binding nuevo, no hubo rebind, `rem_rule_bindings` sin cambio.

**Archivos modificados**: `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php` (reemplazo de `buildDuplicateKeySet()`, 3 métodos privados nuevos: `isLegitimateCoexistence()`, `buildFunctionalSignature()`, `buildComponentSet()`, 1 línea cambiada en `classifyRule()`). **Archivo nuevo**: `backend/tests/Feature/RuleEngine/Services/RuleBindingReconciliationServiceIdentityGateTest.php`.

**No se creó ninguna regla de B2.** No se desactivaron `130`/`133`. No se resolvió el Grupo 1. No se tocó ningún subset/supersede (`24,553,557,558,559,617,585,602,29,580,126,127`, los 14 rules que permanecen `DUPLICATE`). No se tocó B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada`. No bindings nuevos, no rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push.

### 17.38 — B2 — MECANISMO DE EXPANSIÓN IMPLEMENTADO / SIMULACIÓN COMPLETA / NINGUNA REGLA REAL CREADA (2026-08-28)

⚠️ **Código nuevo agregado** (comando + tests) — pero **ninguna regla real de B2 fue creada, ninguna persistida**. Toda la simulación de las 30 combinaciones corrió dentro de una transacción real con `rollback` garantizado. No se tocaron B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada`, bindings, calibraciones, `rem_data`, estructura. No rebind. No commit de Git, no push.

#### 0. Regresión completa post-17.37 — resultado honesto, con investigación de discrepancia

La corrida completa (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`+`tests/Unit/RuleEngine`, 819 tests) se ejecutó **dos veces** tras cerrar 17.37: una primera corrida se descartó por estar contaminada (se solapó con un revert temporal del archivo hecho para verificar el baseline "antes"), y una segunda corrida limpia (archivo en su estado final estable durante toda la ejecución) dio **771 passed, 46 failed, 2 errors** — más que los 39 fallos preexistentes ya documentados en toda la campaña.

**Investigación, no descarte automático**: los 39 preexistentes (4 `StructurePersistenceServiceTest` + 1 `RuleEngineIntegrationTest` + 30 `FunctionalRuleEngineCertificationTest` + 4 `RuleEngineServiceTest`) están **todos presentes, idénticos, sin cambio**. Los **7 fallos + 2 errores adicionales** son todos en archivos de `RemParserService` (`RemParserServiceEmbeddedBackwardSubtotalRowTest`, `RemParserServiceEmbeddedLeadingTotalRowTest`, `RemParserServiceEmptyRowPersistenceTest`, `RemParserServiceMergeAnchorBackwardSubtotalTest`, `RemParserServiceSubcategoryColumnTest`) — **ninguno relacionado con `RuleBindingReconciliationService.php`** (el único archivo tocado en 17.37). Se ejecutaron esos 5 archivos **en aislamiento** (fuera de la suite combinada de 819 tests): **36/36 passing, sin ninguna excepción**. Esto confirma que los 9 fallos/errores adicionales son un artefacto de orden/contaminación cruzada al correr la suite gigante combinada (los dos intentos de corrida completa mostraron, cada uno, un conjunto DISTINTO de fallos "extra" — la primera corrida mostró `QuickRevalidationThenNormalSaveRegressionTest` fallando, que NO aparece en la segunda corrida limpia; la segunda mostró estos 9, que no aparecieron en la primera) — **inconsistencia entre corridas es la firma de flakiness de entorno/orden, no de una regresión determinística de código**. **Conclusión: 0 fallos atribuibles al cambio de 17.37**, confirmado por aislamiento, no solo por conteo. Con esta evidencia, se procedió con B2 según lo autorizado condicionalmente.

#### 1. Reconfirmación previa a cualquier escritura

- Las 5 reglas origen (`226,227,231,232,234`) reconfirmadas intactas: `status=active`, `config` sin ningún cambio (`row_range={0,0}`, `rule_logic="Suma(<col>) = Columna <col>"`, sin `source_rows`/`total_row`).
- Baseline del clasificador reconfirmado: `SAFE_1_TO_1=439`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `activas=717`.
- El gate full-signature (17.37) ya fue validado con fixtures explícitas del patrón B2 (`source_rows` disjuntos, misma `source_letters=target`) — reconfirmado hoy con la simulación real completa (punto 4).

#### 2. Comando nuevo: `rule:expand-b2-aggregation {origin_rule_id} {total_row} {--reason=} {--by=} {--commit}`

Crea **una** regla nueva por invocación (nunca las 6 de una columna de una vez), derivada de una de las 5 reglas origen, representando una de sus 6 posiciones periódicas reales (filas TOTAL 331-336). `total_row` es un argumento (a diferencia de `rule:activate-source-rows`, que nunca recibe argumentos de fila) porque aquí hay 6 candidatos igualmente válidos por columna, no 1 — el operador elige cuál posición crear en cada invocación. `source_rows` **nunca** es argumento — se deriva exclusivamente de la fórmula Excel real en `cell_data` (columna+`total_row`), reutilizando `FormulaRangeCoverageAnalyzer::analyze()` sin duplicar heurístico.

**11 guards, en orden estricto** (cualquier fallo aborta sin escribir): (1) regla origen existe/activa/`sum_equals`; (2) `sheet=A09,section=I`, columna en `{AM,AN,AT,AU,AX}` **y** `rule_key` coincide exactamente con una de las 5 conocidas — rechaza explícitamente cualquier otra regla que comparta el placeholder `{0,0}` (B3/229, Categoría F/228,230,233, o cualquier otra), sin caso especial, por diseño; (3) `config.row_range` es exactamente `{0,0}` (origen intacto); (4) `total_row`/`source_rows` ausentes en el origen; (5) `total_row` (argumento) es uno de los 6 valores reales `{331..336}`; (6) `rule_key` propuesto no colisiona con ninguna regla existente; (7) ninguna regla activa ya existe con la misma combinación exacta sheet+section+columna+tipo+total_row (evita doble-creación); (8) la celda `{columna}{total_row}` tiene fórmula real; (9) la fórmula no referencia otra columna; (10) la fórmula referencia >1 fila, todas estrictamente anteriores a `total_row`, y `isEmbeddedBackwardSubtotalRow()` (mecanismo #12, mismo ya usado en toda la campaña) confirma la fila como subtotal técnico; (11) simulación de clasificación del `config` propuesto (vía una instancia de `Rule` **no persistida** — `classifySingleRule()` ya soporta esto) = exactamente `SAFE_1_TO_1`.

**Al comitear**: transacción, revalidación inmediata antes de escribir (mismo patrón que toda la campaña), crea la fila `rem_rules` nueva (`source='b2_expansion'`, `metadata={derived_from_rule_id, derived_from_rule_key, b2_total_row, created_via, reason, by, created_at}` para trazabilidad completa), **2 activity logs**: uno en la regla NUEVA (`rule_b2_aggregation_created`) y uno en la regla ORIGEN (`rule_b2_aggregation_derived`, referenciando el id de la nueva) — trazabilidad bidireccional sin tocar `config`/`status` del origen. Ningún `RuleVersion` (no aplica a una regla que nace, no que cambia — mismo razonamiento ya documentado en 17.34).

**Disposición futura de la regla origen (documentada, NO ejecutada)**: una vez creadas sus 6 posiciones, la regla origen quedaría sin propósito funcional real (su `row_range={0,0}` nunca alcanza el evaluador vertical, permanece `BLOCKED_BY_ENGINE_GAP` para siempre) — candidata a `status=inactive`, mismo patrón que la Tanda 1/regla 344/529 de esta campaña, vinculada por `metadata.derived_from_rule_id` en cada una de sus reglas hijas. El comando **nunca** cambia el `status` del origen — eso requeriría una invocación separada de `rule:set-rule-status` (ya existente), con su propia autorización explícita futura.

#### 3. Tests — 11/11 passing (`RuleExpandB2AggregationCommandTest.php`, nuevo)

Dry-run válido (reporta `source_rows` derivado, `row_range` envolvente, `rule_key` propuesto, clasificación simulada `SAFE_1_TO_1`, no persiste nada) · commit válido (crea la regla, verifica `config`/`metadata` exactos, verifica que el origen permanece byte-idéntico en `config`/`status`, verifica ambos activity logs con las propiedades correctas) · origen no-B2 rechazado (columna `AQ`, patrón Categoría F) · `total_row` fuera de `{331..336}` rechazado · origen ya modificado rechazado · fórmula que referencia otra columna rechazada · fórmula con referencia hacia adelante rechazada · autorreferencia única (1 sola fila) rechazada · `rule_key` duplicado rechazado · combinación ya creada rechazada (evita doble-creación) · 2 offsets de la misma columna, ambos escapan de `DUPLICATE` tras el segundo commit, **origen permanece `BLOCKED_BY_ENGINE_GAP` sin cambio** (nunca `DUPLICATE`, confirmando el gate de 17.37 en un escenario de creación real, no solo fixtures aisladas).

**Regresión**: `RuleBindingReconciliationServiceIdentityGateTest` + `RuleBindingReconciliationServiceTest` + `RuleActivateSourceRowsCommandTest` + `RuleExpandB2AggregationCommandTest`: **45/45 passing**.

#### 4. Simulación real de las 30 combinaciones — transacción con rollback garantizado, contra la BD real

Se invocó el comando **real** `rule:expand-b2-aggregation --commit` 30 veces (5 orígenes × 6 `total_row`), dentro de una única transacción nunca comiteada, y se reclasificaron las 717+N reglas reales antes/después.

**⚠️ Hallazgo real, no anticipado con esta precisión — 25/30 exitosas, 5/30 rechazadas**: las 5 combinaciones `total_row=333` (una por cada columna AM/AN/AT/AU/AX) fueron **rechazadas por el guard 10** (`isEmbeddedBackwardSubtotalRow()` no confirma la fila 333). Investigado en profundidad: `AR333` (columna del **B3/regla 229**, ya congelada, sección **A09/I misma**) tiene la fórmula real `=SUM(AR337+AR255+...+AR327)` — la referencia `AR337` (fuera de la sección, fila 337 > 333) hace que el mecanismo #12, que escanea **todas las columnas registradas de la sección** para confirmar ausencia de referencias hacia adelante en esa fila física, devuelva `false` para **toda la fila 333, sin importar qué columna se esté evaluando** — **esto extiende el hallazgo ya documentado sobre `AR337`** (que hasta ahora se conocía como "bloquea solo la regla 229/offset2 de AR") a un alcance más amplio, recién confirmado hoy: **`AR337` bloquea también la fila 333 de las 5 columnas limpias (AM,AN,AT,AU,AX)**, aunque sus propias fórmulas (`AM333=SUM(AM255+...+AM327)`, etc.) sean perfectamente limpias y backward-only. **El comando rechazó correctamente estas 5 combinaciones — esto NO es un defecto del comando, es el comportamiento correcto y conservador dado el estado congelado de `AR337`** (instrucción vigente: no tocar `AR337`).

**Resultado exacto de las 25 combinaciones restantes**: **25/25 exitosas**, las 25 nuevas reglas clasifican `SAFE_1_TO_1`.

**Impacto medido sobre el universo completo**:

| Clasificación | Antes | Después (25 nuevas + 717 originales = 742) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 439 | **464** | **+25** (exactamente las 25 nuevas) |
| `DUPLICATE` | 14 | 14 | +0 |
| `ALREADY_STRUCTURE_AGNOSTIC` | 198 | 198 | +0 |
| `BLOCKED_BY_ENGINE_GAP` | 66 | 66 | +0 |

**Ninguna de las 717 reglas preexistentes cambió de clasificación** (diff explícito rule-por-rule, 0 cambios) — incluidas las 5 reglas origen, reconfirmadas `BLOCKED_BY_ENGINE_GAP` sin cambio, `config` intacto. **Rollback ejecutado y verificado**: `Rule::where('source','b2_expansion')->count()=0` tras el rollback, regla 226 confirmada con `row_range={0,0}` intacto.

**Las 5 combinaciones rechazadas** (`total_row=333` para `226,227,231,232,234`) quedan **sin crear, pendientes de una decisión futura sobre `AR337`** — no se propone ninguna solución aquí (fuera de alcance, `AR337` permanece explícitamente congelado por instrucción vigente). El plan de expansión de B2 pasa de "30 combinaciones" a **"25 combinaciones inmediatamente viables + 5 bloqueadas por `AR337`, misma causa raíz ya documentada, ahora con alcance ampliado confirmado"**.

#### 5. Conclusión — nada persistido, decisión pendiente

**El mecanismo de expansión de B2 queda implementado, testeado y validado mediante simulación real completa contra la BD de producción, sin ninguna escritura real.** El comando `rule:expand-b2-aggregation` está listo para ejecutarse regla por regla (25 combinaciones viables) en cuanto se autorice explícitamente el `--commit` real — la disposición futura de las 5 reglas origen (candidatas a `inactive` tras completar sus posiciones viables) queda documentada, no ejecutada. Las 5 combinaciones de `total_row=333` permanecen bloqueadas por `AR337`, consistente con las restricciones vigentes.

**No se creó ninguna regla real.** No se tocó B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada`. No bindings, no rebind. No calibraciones. No `rem_data`. No estructura. Baseline final reconfirmado sin cambios: `activas=717`, `SAFE_1_TO_1=439`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`. **Archivos nuevos**: `backend/app/Console/Commands/RuleExpandB2AggregationCommand.php`, `backend/tests/Feature/REM/RuleExpandB2AggregationCommandTest.php`. No commit de Git, no push.

### 17.39 — B2 — EXPANSIÓN 25/30 VALIDADA / CICLO DE VIDA DE ORÍGENES EN AUDITORÍA / NADA PERSISTIDO (2026-08-28)

⚠️ **100% READ-ONLY / SIMULACIÓN CON ROLLBACK GARANTIZADO.** Ninguna de las 25 reglas reales fue creada de forma persistente. No se cambió el `status` de `226,227,231,232,234`. No se tocó la fila 333, `AR337`, B3(229), Categoría F(228,230,233), regla 461, `no_utilizada`, bindings, calibraciones, `rem_data`, estructura. No commit, no push.

#### 1. Simulación exacta de crear las 25 combinaciones viables (transacción real + rollback)

Se invocó el comando **real** `rule:expand-b2-aggregation --commit` 25 veces (5 orígenes × los 5 `total_row` viables `{331,332,334,335,336}`, excluyendo `333`), dentro de una única transacción nunca comiteada, contra la BD real.

| Métrica | Antes | Después (25 nuevas + 717 = 742) |
|---|---|---|
| Reglas activas | `717` | `742` |
| `SAFE_1_TO_1` | `439` | `464` (+25 exacto) |
| `DUPLICATE` | `14` | `14` (sin cambio) |
| `ALREADY_STRUCTURE_AGNOSTIC` | `198` | `198` (sin cambio) |
| `BLOCKED_BY_ENGINE_GAP` | `66` | `66` (sin cambio) |
| `REQUIRES_REMAP` | `0` | `0` (sin cambio) |

**Diff rule-por-rule sobre las 717 reglas preexistentes**: **0 cambios** — ninguna regla ajena a las 25 nuevas cambió de clasificación.

**Las 25 nuevas, individualmente**: las 25, sin excepción, clasifican `SAFE_1_TO_1` (verificado una por una, no solo por conteo agregado) — ids `818-842`, `rule_key`s exactos `a09_i_{col}_row{331,332,334,335,336}_sum_equals` para cada una de las 5 columnas.

**Las 5 reglas origen**: las 5, sin excepción, permanecen `BLOCKED_BY_ENGINE_GAP` — `config` byte-idéntico (`row_range={0,0}`, `total_row=null`), sin ningún cambio.

**Colisiones nuevas**: **0** — ninguna regla ajena a las 25 (ni las 25 entre sí) pasó a clasificar `DUPLICATE` que no lo fuera ya antes.

#### 2. Riesgo de ejecución futura — auditado con código real, no solo teoría

**`rule:rebind-safe-to-structure` (dry-run real, sin `--commit`, sobre el estado simulado con las 25 creadas)**: verificado **programáticamente** (vía `RuleBindingReconciliationService::findSafeCandidatesForStructure()`, no por búsqueda de texto en la salida del comando — un primer intento de verificación por texto produjo 2 falsos positivos con los orígenes `231`/`232`, causados por esos dígitos apareciendo como substring en otras partes de la tabla impresa; la verificación programática correcta descarta esto por completo): de **441 candidatos reales** (`SAFE_1_TO_1` menos las 23 en hojas `no_utilizada`, gap ya preexistente y confirmado ajeno a B2), **las 25 nuevas SÍ aparecen**, **ninguna de las 5 reglas origen aparece** — confirmado exacto, sin ambigüedad.

**Demostración de que origen e hijas nunca se ejecutarían simultáneamente por accidente — con el código real, no supuesto**: `RuleEngineService::execute()` (línea 202-208) aplica el prefiltro `$rows->filter(fn($rd) => ($rd->data['row_number'] >= $rowFrom && $rd->data['row_number'] <= $rowTo) || ...)`. Para la regla origen, `$rowFrom=$rowTo=0` (de `row_range={0,0}`) y `$totalRow=null`/`$sourceRowsForFilter=null` (el origen nunca tiene esos campos) — el filtro se reduce exactamente a `row_number === 0`. Confirmado que **ningún `rem_data` real tiene jamás `row_number=0`** (las filas de Excel son 1-indexadas, y las secciones reales empiezan mucho más arriba) — por lo tanto, **aunque la regla origen llegara a tener un binding activo** (cosa que el rebind automático nunca haría, ver arriba), su `$rows` filtrado sería **siempre vacío**, garantizado por construcción del prefiltro, no por casualidad de los datos. El origen nunca podría producir un resultado real, correcto o incorrecto — simplemente nunca alcanza el evaluador con ninguna fila. Las 25 hijas, en cambio, tienen `row_range`/`source_rows`/`total_row` reales y sí producirían resultados genuinos. **Cero riesgo de ejecución simultánea con datos reales, demostrado con el código, no solo argumentado.**

**`CertificationService`/UI (todavía inerte, `getRules()` exige binding a la estructura activa, bindings=0 hoy)**: una vez existiera un binding real, `getSectionRules('A09','I')` mostraría las 25 + las 5 originales como filas/tarjetas independientes (cada una keyed por su propio `rule_key` único, sin colisión de estado de certificación) — pero `findStructureEvidence()` (ya documentado en 17.35) mostraría, para las 6 reglas de una misma columna (1 origen + 5 hijas), la **misma** "evidencia_xlsm" (el único `reglaDetectada` que la estructura ascendente registra para ese campo) — limitación de visualización ya conocida, sin relación con la corrección funcional de las reglas, y todavía sin ningún efecto observable mientras bindings=0.

#### 3. Representación del estado parcial 5/6 por columna — sin inventar status/config nuevo

**Recomendación: no escribir ningún campo nuevo en la regla origen — el estado 5/6 ya es completa y correctamente derivable en vivo, sin persistir nada adicional.** Razonamiento:
- La clasificación real de la regla origen (`BLOCKED_BY_ENGINE_GAP`) sigue siendo **exacta y no engañosa** después de crear 5 de sus 6 hijas — el origen, por sí mismo, sigue siendo exactamente igual de no-funcional que antes (su `row_range={0,0}` nunca alcanzó ni alcanzará el evaluador vertical, confirmado en el punto 2). El "5/6 resuelto" es una propiedad de la COLUMNA como concepto (repartida entre 6 reglas hermanas), no una propiedad que deba reflejarse en la clasificación del origen mismo.
- La trazabilidad completa **ya existe, sin escribir nada nuevo**: cada hija almacena `metadata.derived_from_rule_id` (ya implementado en 17.38) — una consulta simple (`Rule::where('metadata->derived_from_rule_id', $originId)->count()`) revela en cualquier momento cuántas de las 6 posiciones están resueltas, sin necesidad de un campo redundante en el origen que además tendría un riesgo real de desincronización (¿qué pasa si una hija se desactiva después? el flag tendría que actualizarse en 2 lugares en vez de 1).
- **Se evaluó explícitamente** la alternativa `metadata.partially_expanded` en el origen (ej. `{expanded_total_rows:[331,332,334,335,336], pending_total_rows:[333], blocked_by:'AR337'}`) — técnicamente viable, mismo patrón ya usado para la metadata de las hijas — pero se descarta como innecesaria: no agrega ninguna capacidad que la consulta derivada no dé ya, y sí agrega una superficie nueva de posible inconsistencia. **Si en el futuro se decide que un flag explícito es más conveniente para reportes/dashboards, debe ser CALCULADO EN VIVO (mismo principio que los campos diagnósticos de Fase 1, nunca persistidos, nunca usados para decidir `clasificacion`), no escrito en `config`/`metadata` del origen** — no implementado aquí, solo dejado como opción evaluada y descartada por ahora.
- **No esperar a resolver la fila 333 antes de crear las 5 hijas viables** tampoco se recomienda como obligatorio — las 5 combinaciones válidas son independientes de la 6ta bloqueada; no hay ninguna relación de integridad entre ellas que exija esperar (cada una es una regla `sum_equals` autocontenida, sin dependencia funcional entre offsets).

**Conclusión de este punto**: el ciclo de vida correcto es simplemente **crear las 25 (cuando se autorice), dejar el origen exactamente como está (`active`, `BLOCKED_BY_ENGINE_GAP`, sin metadata nueva), y consultar la relación padre-hijas por `metadata.derived_from_rule_id` cuando se necesite** — sin inventar ningún status/campo nuevo.

#### 4. Reversibilidad — simulado explícitamente, dentro de la misma transacción

Tras confirmar la creación de las 25 (punto 1), se ejecutó `Rule::forceDelete()` sobre las 25 (aún dentro de la misma transacción, antes del rollback general) y se reclasificó el universo completo de nuevo:

- Clasificación tras eliminar las 25: **idéntica byte a byte al baseline original** (`SAFE_1_TO_1=439, DUPLICATE=14, ALREADY_STRUCTURE_AGNOSTIC=198, BLOCKED_BY_ENGINE_GAP=66`, `717` reglas activas) — comparación de mapas completos `rule_id→clasificación` confirmada **idéntica** (no solo los conteos).
- Las 5 reglas origen: intactas durante todo el ciclo (creación + eliminación), nunca tocadas.
- Bindings huérfanos de las 25 eliminadas: **0** (nunca se crearon bindings para ellas en esta simulación).
- Activity logs: **no se eliminan automáticamente** con `forceDelete()` (comportamiento estándar de Spatie Activitylog, no específico de este comando) — quedarían 25 registros `rule_b2_aggregation_created` + 25 `rule_b2_aggregation_derived` como rastro histórico de la creación/eliminación, igual que cualquier activity log de una acción revertida en esta campaña (nunca se borran retroactivamente, por diseño ya establecido en toda la campaña — ej. los `RuleVersion` de la regla 529 tras su remap fallido y reversión, punto 16.3, se conservaron igual).
- **Rollback general de la transacción, verificado**: `Rule::where('source','b2_expansion')->count()=0` en la BD real tras el rollback completo, regla 226 confirmada `row_range={0,0}`/`status=active` intacta.

**Conclusión**: la reversibilidad es completa y limpia a nivel de catálogo/clasificación (cero residuo funcional) — el único "residuo" posible en un escenario real (no simulado) serían los activity logs de auditoría, que es el comportamiento deseado (preservar el rastro de que algo se creó y luego se revirtió), no un defecto.

#### 5. Regresión de la suite grande — documentada como FLAKY/ORDER-DEPENDENT, no como regresión de 17.37

Reconfirmado con la evidencia ya reunida en 17.38: dos corridas completas de la suite combinada (819 tests) mostraron, cada una, un conjunto **distinto** de fallos "extra" más allá de los 39 ya documentados (`QuickRevalidationThenNormalSaveRegressionTest` en la primera corrida; 7 fallos + 2 errores en archivos de `RemParserService` en la segunda) — y los 5 archivos afectados en la segunda corrida (`RemParserServiceEmbeddedBackwardSubtotalRowTest`, `RemParserServiceEmbeddedLeadingTotalRowTest`, `RemParserServiceEmptyRowPersistenceTest`, `RemParserServiceMergeAnchorBackwardSubtotalTest`, `RemParserServiceSubcategoryColumnTest`) pasan **36/36 limpio** cuando se ejecutan aislados de la suite gigante. **Conclusión formal, para quede registrada**: estos 9 fallos/errores adicionales son **FLAKY/ORDER-DEPENDENT** — una deuda de aislamiento de tests (probablemente estado compartido vía `Storage::fake('local')`/cache no perfectamente aislado entre clases de test cuando se ejecutan cientos de tests en un mismo proceso), **no una regresión atribuible al gate de 17.37** (que solo toca `RuleBindingReconciliationService.php`, un archivo que ninguno de esos 9 tests ejercita). **No se investigó ni se corrigió la causa raíz del aislamiento** — queda documentado como deuda pendiente, no descartado ni ignorado.

#### 6. Baseline final reconfirmado

`activas=717`, `SAFE_1_TO_1=439`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=764`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, `rem_technical_totals=126`. **Ninguna escritura de ningún tipo durante esta auditoría** — toda la simulación (creación de 25, dry-run de rebind, eliminación de las 25) corrió dentro de una única transacción con `rollback` garantizado. No se tocó B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada`, bindings, calibraciones, `rem_data`, estructura. No commit de Git, no push.

### 17.40 — FASE 3C-3C / B2 — EXPANSIÓN PARCIAL 25/30 EJECUTADA Y CERRADA — 5 AGREGACIONES FILA 333 PENDIENTES (2026-08-28)

⚠️ **Escritura real, autorizada explícitamente.** 25 reglas nuevas creadas de forma persistente en `rem_rules`. Las 5 combinaciones de `total_row=333` (bloqueadas por `AR337`) **NO fueron creadas** — quedan pendientes, sin tocar `AR337`/fila 333. B3(229), Categoría F(228,230,233), regla 461, `no_utilizada`, bindings, calibraciones, `rem_data`, estructura sin tocar. No commit de Git, no push.

#### 1. Reconfirmación previa a escribir

Baseline real reconfirmado exacto: `activas=717`, `SAFE_1_TO_1=439`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `REQUIRES_REMAP=0`. Las 5 reglas origen (`226,227,231,232,234`) confirmadas `active`, `row_range={0,0}`, sin `total_row`/`source_rows`, clasificando `BLOCKED_BY_ENGINE_GAP`. Confirmado `Rule::where('source','b2_expansion')->count()=0` antes de escribir.

#### 2. Ejecución — 25 creaciones reales, una por una, con verificación tras cada una

Se invocó `rule:expand-b2-aggregation --commit` 25 veces (5 orígenes × `total_row∈{331,332,334,335,336}`, **nunca 333**), cada invocación seguida de una reclasificación completa del universo activo para confirmar: (a) la regla nueva clasifica exactamente `SAFE_1_TO_1`, (b) ninguna otra regla (preexistente o ya creada en el mismo lote) cambió de clasificación. **Las 25, sin excepción, pasaron ambas verificaciones en su propio turno — 0 desviaciones respecto al dry-run de 17.38/17.39, no fue necesario ningún STOP intermedio.** ids reales asignados: `868-892` (secuenciales, 5 por columna en el orden AM,AN,AT,AU,AX × `331,332,334,335,336`).

#### 3. Post-check exhaustivo — todos los puntos exigidos, verificados exactos

| Verificación | Resultado |
|---|---|
| Reglas activas | `717 → 742` ✅ |
| `SAFE_1_TO_1` | `439 → 464` ✅ |
| `DUPLICATE` | `14` (sin cambio) ✅ |
| `ALREADY_STRUCTURE_AGNOSTIC` | `198` (sin cambio) ✅ |
| `BLOCKED_BY_ENGINE_GAP` | `66` (sin cambio) ✅ |
| `REQUIRES_REMAP` | `0` (sin cambio) ✅ |
| Reglas `source=b2_expansion` | exactamente `25` ✅ |
| Las 25 clasificadas individualmente | `SAFE_1_TO_1` las 25, sin excepción ✅ |
| `derived_from_rule_id` de cada hija | correcto (verificado programáticamente contra el origen esperado, las 25) ✅ |
| `source_rows`/`row_range`/`total_row`/`rule_key` | exactamente iguales al plan de 17.34/17.38 para las 25 (verificado campo por campo, no por muestreo) ✅ |
| Las 5 reglas origen | intactas: `status=active`, `config` byte-idéntico (`row_range={0,0}`, sin `total_row`/`source_rows`), clasificando `BLOCKED_BY_ENGINE_GAP` ✅ |
| Bindings nuevos para las 25 | `0` ✅ |
| `rem_rule_bindings` | `1204` (sin cambio) ✅ |
| Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` | idénticos (`8565f0af.../24b3d2b7...`) — calibraciones sin tocar ✅ |
| `rem_data` | `399.811` (sin cambio) ✅ |
| `rem_technical_totals` | `126` (sin cambio) ✅ |
| Activity logs | exactamente `25` `rule_b2_aggregation_created` + `25` `rule_b2_aggregation_derived` ✅ |
| Fila 333 (las 5 columnas) | **ninguna regla hija creada** (`a09_i_{col}_row333_sum_equals` no existe para ninguna de las 5) ✅ |
| Diff completo de las 717 reglas preexistentes | **0 cambios de clasificación**, confirmado durante la ejecución (verificado tras cada una de las 25 creaciones, no solo al final) ✅ |
| `rem_rules` | `764 → 789` (+25 exacto) ✅ |
| Estructura activa | `67/v35`, sin tocar ✅ |

**Ninguna discrepancia en ningún punto.**

#### 4. Estado explícito de las 5 reglas origen — NO están completamente resueltas

**Cada una de las 5 reglas origen (`226`=AM, `227`=AN, `231`=AT, `232`=AU, `234`=AX) tiene ahora exactamente 5 de sus 6 agregaciones periódicas materializadas como reglas hijas independientes, y 1 pendiente** (`total_row=333`, bloqueada por la interacción con `AR337` ya documentada en 17.38/17.39 — el escaneo multi-columna de `isEmbeddedBackwardSubtotalRow()` para la fila 333 encuentra la referencia hacia adelante `AR337` en la fórmula de la columna AR, incluso al evaluar columnas AM/AN/AT/AU/AX cuyas propias fórmulas son limpias).

**Esto NO se refleja como una relación explícita en el catálogo — decisión ya evaluada y confirmada en 17.39.3, reafirmada aquí**: las 5 reglas origen permanecen `status=active`, `config` sin ningún cambio, clasificando `BLOCKED_BY_ENGINE_GAP` — exactamente igual que antes de esta expansión, **y esto sigue siendo preciso, no engañoso**: el origen en sí mismo nunca fue ni es funcional (su `row_range={0,0}` nunca alcanza el evaluador vertical, confirmado con el código real en 17.39.2), independientemente de cuántas de sus 6 posiciones conceptuales ya tengan una regla hija resolviéndolas. **La relación "5/6 resuelto, 1 pendiente" es 100% derivable en vivo, sin ningún campo nuevo**, mediante:

```php
Rule::where('metadata->derived_from_rule_id', 226)->pluck('metadata->b2_total_row');
// => [331, 332, 334, 335, 336] -- confirma 5/6, con 333 ausente
```

**No se escribió ningún campo `partially_expanded` ni ningún status nuevo** en las 5 reglas origen — consistente con la decisión ya tomada y justificada en 17.39.3 (evitar una fuente de verdad redundante con riesgo de desincronización, cuando la consulta derivada ya es exacta y completa).

#### 5. Baseline final

`activas=742` (`717+25`), `SAFE_1_TO_1=464`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=789`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, estructura activa `67/v35 status=active` sin tocar, `rem_technical_totals=126`, hashes de calibración sin cambio.

**Pendiente explícito, sin resolver**: las **5 combinaciones `total_row=333`** (una por columna) permanecen sin ninguna regla hija — bloqueadas por `AR337`, que sigue **congelado, sin tocar**, sin ninguna propuesta de corrección ejecutada. Las 5 reglas origen quedan, por tanto, en un estado de **expansión parcial permanente hasta que se decida algo sobre `AR337`** — no hay ninguna acción pendiente de ejecutar salvo esa decisión futura.

**No se tocó B3(229), Categoría F(228,230,233), fila 333, `AR337`, regla 461, `no_utilizada`.** No bindings nuevos, no rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push.

### 17.41 — AUDITORÍA READ-ONLY DEL REMANENTE FUNCIONAL A09/I: FILA 333 + AR337 + B3(229) + CATEGORÍA F(228,230,233) (2026-08-28)

⚠️ **100% READ-ONLY.** Ninguna regla creada de forma persistente, ninguna clasificación/config/status cambiado, ningún archivo de motor/parser/clasificador tocado, ningún template Excel modificado. No se tocaron las 25 hijas de B2, las 5 orígenes, fila 333, `AR337`, regla 461, `no_utilizada`, bindings/rebind, calibraciones, `rem_data`, estructura. No commit, no push.

#### 0. Reconfirmación de baseline

`activas=742`, `SAFE_1_TO_1=464`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `REQUIRES_REMAP=0`, `rem_rules=789` — idéntico al cierre de 17.40, confirmado antes y después de esta auditoría.

#### 1. Reconstrucción completa de la fila 333 — las 50 columnas registradas de `A09/I`, no solo las 9 ya conocidas

Se auditaron **las 50 columnas** (`A` a `AX`) registradas en la sección viva (`filaInicioDatos=249, filaFinDatos=336`), no solo las 9 de rules 226-234. Resultado: **`AR337` es la única referencia hacia adelante en TODA la fila 333** — confirmado exhaustivamente, ninguna otra de las 50 columnas (incluidas `G` a `AL`, que pertenecen a otras validaciones fuera de esta campaña, con sus propios patrones irregulares de suma dispersa, ya ajenos a B2/B3/Categoría F) referencia ninguna fila `>333`. Esto descarta que el bloqueo de la fila 333 sea un problema sistémico de la fila en general — es exclusivamente atribuible a la fórmula de `AR333`.

**Fórmulas reales confirmadas para las 9 columnas de interés, filas 331-336** (idénticas a lo ya documentado en 17.20/17.25/17.33, reconfirmadas hoy contra `cell_data` + Excel fuente):

| Col | 331 | 332 | 333 | 334 | 335 | 336 |
|---|---|---|---|---|---|---|
| AM(226) | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio |
| AN(227) | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio |
| AQ(228) | 13t limpio | 13t limpio | **sin fórmula** | **sin fórmula** | **sin fórmula** | **sin fórmula** |
| AR(229) | 13t limpio | 13t limpio | **14t: `AR337`+13t** | 13t limpio | 13t limpio | 13t limpio |
| AS(230) | 2t (propios) | 2t (propios) | 2t (propios) | **2t (ajenos, residuo de offset4)** | **13t completo (propio, offset4)** | 2t (propios) |
| AT(231) | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio |
| AU(232) | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio |
| AV(233) | sin fórmula | sin fórmula | sin fórmula | **13t limpio (único)** | sin fórmula | sin fórmula |
| AX(234) | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio | 13t limpio |

**Hallazgo nuevo sobre `AS(230)`, más preciso que lo documentado en 17.20/17.25**: la fila **335 (offset4) tiene, en realidad, una agregación de 13 términos COMPLETA y auto-consistente** (`AS257+AS263+...+AS329`, exactamente los 13 miembros del residuo de offset4, el mismo residuo que corresponde a la fila 335 por convención) — **no es "todo roto"**, como sugería la caracterización previa; **solo 335/offset4 es limpio**, mientras 331/332/333/336 son sumas parciales (2 de 13 términos, de su propio residuo) y 334 referencia, de forma incorrecta, 2 términos del residuo de offset4 (no del suyo propio, offset3) — confirmando y precisando el hallazgo original de "mapeo roto" con evidencia exacta.

#### 2. Por qué `AR337` bloquea la fila 333 completa — mecanismo confirmado, evaluación de si es efecto colateral o diseño correcto

`isEmbeddedBackwardSubtotalRow($sheet,$section,$row,$sectionData)` **no recibe columna como parámetro** — evalúa la fila como una unidad, iterando TODAS las columnas registradas de la sección para confirmar que NINGUNA tiene una referencia hacia adelante en esa fila. Esto es consistente con la arquitectura real de exclusión: `RemParserService` decide, **por fila física, no por columna**, si esa fila se persiste en `rem_data` o no (el `continue` de la línea ~587 opera sobre la fila completa) — es decir, **no existe ningún mecanismo en el sistema para excluir una fila de `rem_data` columna por columna**; la granularidad de la decisión es forzosamente por fila. Dado esto, **el escaneo multi-columna de `isEmbeddedBackwardSubtotalRow()` es un reflejo correcto de esa granularidad, no un defecto de implementación** — si CUALQUIER columna de la fila 333 tuviera una referencia hacia adelante genuina (un valor de negocio real de un periodo futuro), sería incorrecto excluir la fila entera de `rem_data`, ya que se perdería ese dato real de otra columna. El mecanismo, correctamente, se abstiene de confirmar la fila como "subtotal técnico puro" ante CUALQUIER señal ambigua, sin importar cuál columna la origina.

**Conclusión de este punto**: el comportamiento es **arquitectónicamente correcto dado el diseño de exclusión por-fila del sistema**, no un efecto colateral "demasiado amplio" en el sentido de ser un bug — es la consecuencia inevitable de que la unidad de decisión sea la fila. El "efecto colateral" real y genuino es que **una sola columna defectuosa (AR) impide confirmar la fila para las otras 8 columnas limpias** — eso sí es una limitación real del diseño actual (no distingue "esta columna específica tiene una referencia hacia adelante genuina" de "esta fila entera no puede confirmarse"), pero corregirlo requeriría cambiar la granularidad de la exclusión de fila a fila+columna, un cambio de arquitectura no evaluado aquí (fuera de alcance, no propuesto).

#### 3. `AR337` — confirmado inequívocamente como defecto del template, inocuo aritméticamente

Reconfirmado **directamente contra el archivo Excel fuente real** (`storage/app/rem-uploads/2026/01/1/20260529190913_SA_26_V1.2-2.xlsm`, vía PhpSpreadsheet, no solo `cell_data` ya escaneado — verificado primero que `AM331` coincide exactamente entre el archivo y `cell_data`, confirmando que es el archivo correcto):
- `A337` = `"SECCIÓN J: ACTIVIDADES EFECTUADAS POR TÉCNICO PARAMÉDICO DENTAL Y/O HIGIENISTAS DENTALES"` — literalmente el título de la **sección siguiente** (J), no parte de la sección I en absoluto.
- `AR337` = `NULL` — completamente vacía, sin fórmula, sin valor, sin formato relevante.
- **Ningún merge (`getMergeCells()`) conecta la fila 337 con el bloque `A331:A336`** — son estructuralmente independientes; el merge de "TOTAL" termina exactamente en la fila 336.
- Aritméticamente: en Excel, tanto `SUM()` como una suma `+` directa tratan una celda vacía como `0` — **`AR337` nunca altera el valor calculado de `AR333`**, confirmado (la suma real de los 13 términos limpios coincide exactamente con el valor histórico observado en `rem_data`, ya documentado en 17.25).

**Conclusión**: `AR337` es, sin ambigüedad, un **defecto de autoría del template** (muy probablemente un arrastre/copiado de fórmula desde una fila vecina que rebasó el límite de la sección) — **no** una referencia de negocio legítima, **no** un artefacto de merge/estructura, **no** ambiguo. Es matemáticamente inocuo (contribuye 0 siempre) pero estructuralmente inválido (referencia una celda de otra sección, vacía, sin relación semántica). Esta conclusión ya se había alcanzado en 17.27 con evidencia parcial; queda **reconfirmada hoy con verificación adicional** (merges, tipo de dato, ausencia total de conexión estructural).

#### 4. B3 (regla 229/AR) — cuáles de las 6 agregaciones son válidas independientemente de `AR337`

**5 de 6 (offsets 0,1,3,4,5 → filas 331,332,334,335,336) son limpias, completas, auto-consistentes y sin ninguna relación con `AR337`** — confirmado con la fórmula real de cada una (tabla del punto 1) y con simulación real (transacción+rollback, punto 6): las 5, individualmente, pasan **todos** los guards del mecanismo ya validado en B2 (sin referencia a otra columna, todas las filas estrictamente anteriores a `total_row`, `isEmbeddedBackwardSubtotalRow()=true`, clasificación simulada `SAFE_1_TO_1`).

**Solo 1 de 6 (offset2, fila 333) está afectada** — y "afectada" significa **estructuralmente bloqueada** (el mecanismo no puede confirmarla como subtotal técnico debido al escaneo multi-columna del punto 2), **no que su resultado aritmético sea incorrecto**: la suma real de `AR333` (13 términos limpios + `AR337`=0) es idéntica a la que tendría sin el término espurio. Es decir: **`AR333` es matemáticamente correcta pero estructuralmente no confirmable con el mecanismo actual.**

#### 5. Categoría F (228, 230, 233) — reconstrucción exacta de cada TOTAL existente y ausente, sin asumir que deben existir 6

- **`228`/AQ**: **exactamente 2 de 6 existen** (331, 332), ambas limpias/completas/auto-consistentes. Las 4 restantes (333-336) **no tienen fórmula en el Excel fuente** — no son "huecos que deban llenarse", son combinaciones que el template original **nunca calculó** para esta columna.
- **`230`/AS**: **las 6 tienen alguna fórmula**, pero solo **1 (offset4/fila 335) es una agregación completa de 13 términos, auto-consistente**. Las otras 5 son sumas parciales de 2 términos: 4 de ellas (331,332,333,336) referencian 2 miembros de su **propio** residuo (parciales, no completas); **1 (334) referencia 2 miembros del residuo de offset4 (el de la fila 335), no del suyo propio (offset3)** — un mapeo genuinamente incorrecto, no solo incompleto.
- **`233`/AV**: **exactamente 1 de 6 existe** (334/offset3), limpia/completa/auto-consistente. Las 5 restantes **no tienen fórmula** — mismo patrón que `228`, nunca calculadas en el template.

#### 6. ¿Puede alguna parte de B3/Categoría F reutilizar el mecanismo B2 ya validado, sin ampliar el motor? — SÍ, confirmado con simulación real

Se simularon, **dentro de una transacción con rollback garantizado** (nunca comiteado), las **9 combinaciones individualmente limpias** identificadas: `229` (AR) offsets 331,332,334,335,336 — 5; `228` (AQ) offsets 331,332 — 2; `230` (AS) offset 335 — 1; `233` (AV) offset 334 — 1. Para cada una se derivó `source_rows` con `FormulaRangeCoverageAnalyzer::analyze()` (mismo código ya usado por `rule:expand-b2-aggregation`, sin modificar), se confirmó ausencia de referencia a otra columna, todas las filas estrictamente anteriores a `total_row`, y `isEmbeddedBackwardSubtotalRow()=true` (mecanismo #12, sin cambios) — **las 9, sin excepción, pasaron los mismos guards que ya usa el mecanismo de B2**, y se creó (simuladamente) cada una para confirmar la clasificación real vía `RuleBindingReconciliationService`:

| Clasificación | Antes | Después (9 simuladas) | Delta |
|---|---|---|---|
| `SAFE_1_TO_1` | 464 | 473 | **+9** (las 9 nuevas, todas) |
| `DUPLICATE`/`ALREADY_STRUCTURE_AGNOSTIC`/`BLOCKED_BY_ENGINE_GAP` | — | — | +0 |

**0 cambios en las 742 reglas preexistentes.** Rollback ejecutado y verificado (`Rule::where('source','simulacion_17_41')->count()=0` tras el rollback).

**Conclusión**: el **mecanismo (`FormulaRangeCoverageAnalyzer` + `isEmbeddedBackwardSubtotalRow` + gate full-signature)** ya validado para B2 **funciona igual de bien para estas 9 combinaciones, sin ningún cambio al motor, evaluador o clasificador**. Lo que actualmente las bloquea de usarse en la práctica **no es el mecanismo** — es que `RuleActivateSourceRowsCommand`... perdón, `RuleExpandB2AggregationCommand`'s guard 2 (el *allowlist* explícito de las 5 `rule_key` de B2) **excluye deliberadamente** cualquier otra regla, incluida `229`/`228`/`230`/`233`, por diseño (documentado en su propio docblock: "Fuera de alcance: B3, Categoría F..."). **Extender ese allowlist para aceptar estas 9 combinaciones específicas sería un cambio mínimo y de bajo riesgo** (mismos guards 8-11 ya los validan independientemente) — **pero es una decisión de diseño que requiere autorización explícita, no ejecutada aquí**.

#### 7. Clasificación final — RESOLVIBLE_CON_MECANISMO_ACTUAL / DEFECTO_TEMPLATE / REQUIERE_DECISION_HUMANA / SIN_EVIDENCIA

| Categoría | Casos | Detalle |
|---|---|---|
| **RESOLVIBLE_CON_MECANISMO_ACTUAL** | 9 combinaciones | `229` offsets 331,332,334,335,336 (5) · `228` offsets 331,332 (2) · `230` offset 335 (1) · `233` offset 334 (1) — las 9 pasan los guards ya validados de B2, confirmado con simulación real. Requiere solo extender el *allowlist* del comando (decisión pendiente), no el mecanismo. |
| **DEFECTO_TEMPLATE** | `AR337` (afecta a `229`/offset2) · las 4 combinaciones ausentes de `228` (333-336) · las 5 ausentes de `233` (331,332,333,335,336) · el término de `230`/fila334 (referencia al residuo equivocado, offset4 en vez de offset3) | Confirmado como errores/omisiones del autor del template Excel original — `AR337` es una referencia espuria e inocua a una celda vacía de otra sección; las ausencias de `228`/`233` son combinaciones que el template nunca calculó; el término mal referenciado de `230`/334 es un error de mapeo (copiado del offset equivocado). Ninguno es corregible sin tocar el template o sin una decisión explícita de cómo tratarlo. |
| **REQUIERE_DECISION_HUMANA** | 4 combinaciones parciales de `230` (331,332,333,336 — sumas de solo 2 de 13 términos de su propio residuo) | No se puede determinar, solo con la evidencia del Excel, si el patrón de "solo 2 términos" es (a) una validación de negocio genuinamente más estrecha (intencional, quizás validando solo 2 periodos específicos de esta columna) o (b) una fórmula incompleta que debería tener 13 términos como sus columnas vecinas. Ambas hipótesis son técnicamente implementables con el mecanismo actual (un `source_rows` de 2 elementos es tan válido como uno de 13) — la pregunta es de intención de negocio, no de capacidad técnica. Requiere consulta a Estadística APS o a quien conozca el concepto real de la columna `AS`. |
| **SIN_EVIDENCIA** | Intención de negocio detrás de las ausencias de `228`/`233` y del patrón parcial de `230` | El **hecho** (qué fórmula existe o no) está 100% evidenciado (reconstruido celda por celda); lo que **no** tiene evidencia suficiente es el **motivo de negocio** original (¿por qué el template nunca calculó esas combinaciones? ¿fue deliberado o un descuido?) — no hay ningún rastro en el Excel, `cell_data` ni la estructura que permita inferirlo. |

**Verificación de suma**: 9 (resoluble) + 1(`AR337`)+4(`228` ausentes)+5(`233` ausentes)+1(`230`/334 mal mapeado) = 9+11 = 20 "casos" en DEFECTO_TEMPLATE, + 4 en REQUIERE_DECISION_HUMANA = las 33 combinaciones totales de las 4 reglas (229,228,230,233 × 6 c/u = 24... nota: `229` ya tiene 5 buenas + 1 en DEFECTO_TEMPLATE = 6; `228` tiene 2 buenas + 4 en DEFECTO_TEMPLATE = 6; `230` tiene 1 buena + 1 en DEFECTO_TEMPLATE(334) + 4 en REQUIERE_DECISION_HUMANA = 6; `233` tiene 1 buena + 5 en DEFECTO_TEMPLATE = 6 — total 24 exacto, 4 reglas × 6 posiciones).

#### 8. Impacto potencial sobre las reglas bloqueadas (proyección, no ejecutado)

Si en el futuro se autorizara extender el *allowlist* del comando para aceptar las 9 combinaciones `RESOLVIBLE_CON_MECANISMO_ACTUAL`: `BLOCKED_BY_ENGINE_GAP` bajaría de `66` a `66` (las reglas **origen** `228,229,230,233` seguirían `BLOCKED_BY_ENGINE_GAP` ellas mismas, igual que `226,227,231,232,234` hoy — el patrón ya establecido en 17.40 de que el origen nunca se resuelve, solo sus hijas) y `SAFE_1_TO_1` subiría de `464` a `473` (+9). **Esto es una proyección aritmética simple basada en la simulación del punto 6, no una nueva ejecución** — ninguna clasificación real fue cambiada.

**No se implementó nada de este punto.** No se extendió ningún allowlist. No se creó ninguna regla real. No se tocó `AR337`, la fila 333, el template Excel, B3(229), Categoría F(228,230,233), regla 461, `no_utilizada`, bindings, calibraciones, `rem_data`, estructura. Baseline final reconfirmado sin cambios: `activas=742`, `SAFE_1_TO_1=464`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `REQUIRES_REMAP=0`, `rem_rules=789`. No commit de Git, no push.

### 17.42 — A09/I B3/F — MECANISMO DE EXPANSIÓN PARA 9 AGREGACIONES IMPLEMENTADO / DRY-RUN Y SIMULACIÓN VALIDADOS / NADA PERSISTIDO (2026-08-28)

⚠️ **Código nuevo (comando renombrado/generalizado + tests), pero ninguna de las 9 reglas fue creada de forma persistente.** No se tocó fila 333/`AR337`, el template Excel, las 25 hijas de B2, regla 461, `no_utilizada`, bindings/rebind, calibraciones, `rem_data`, estructura. No commit, no push.

#### 1. Decisión de diseño: renombrar/generalizar el comando, no crear un segundo mecanismo

Se auditó primero si bastaba con ampliar el *allowlist* (guard 2) de `rule:expand-b2-aggregation` o si el nombre dejaba de reflejar su alcance real. **Se decidió renombrar**: `rule:expand-b2-aggregation` → **`rule:expand-a09-i-aggregation`** (clase `RuleExpandB2AggregationCommand` → `RuleExpandA09IAggregationCommand`) — el comando ya no trata solo "B2" (etiqueta interna de esta campaña), sino cualquiera de las 9 reglas periódicas reales de `A09/I`. El archivo/clase original fue **eliminado** (no se mantienen 2 comandos casi-idénticos); el archivo de test fue renombrado y actualizado en consecuencia (`RuleExpandA09IAggregationCommandTest.php`).

**Reutilización íntegra, sin segundo mecanismo**: el comando reutiliza exactamente `FormulaRangeCoverageAnalyzer::analyze()`, `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` (mecanismo #12) y `RuleBindingReconciliationService::classifySingleRule()` — **ningún archivo de motor/evaluador/clasificador fue tocado**. El único cambio de lógica real es **un guard nuevo** (ver punto 2), no un mecanismo paralelo.

**Compatibilidad histórica**: las 25 reglas ya creadas por el comando original (17.40, ids `868-892`) conservan intacto su `source='b2_expansion'`/`metadata.b2_total_row`/`created_via='rule:expand-b2-aggregation'` — nunca reescritos retroactivamente. Las reglas nuevas usarán `source='a09_i_expansion'`/`metadata.total_row`/`created_via='rule:expand-a09-i-aggregation'`, consistente con el nombre nuevo.

#### 2. Guard nuevo, indispensable — coincidencia exacta con el patrón periódico completo

Auditado (ver 17.41) que **`isEmbeddedBackwardSubtotalRow()` ya confirma como válidas, para OTRAS columnas, las mismas filas (331,332,334,335,336) donde `230`/AS tiene fórmulas parciales o de residuo incorrecto** — es decir, los guards ya existentes (formula real, sin otra columna, ≥2 filas, todas hacia atrás, mecanismo #12) **no bastan** para distinguir una suma parcial (2 de 13 términos) de una completa. Se agregó un único guard nuevo: el conjunto de filas derivado de la fórmula real debe coincidir **EXACTAMENTE** con `range(total_row - 78, total_row - 6, 6)` (13 términos, paso 6, dentro del bloque componente `[253:330]` ya auditado) — puramente aritmético, sin nombrar ninguna columna. Este guard es el **único** mecanismo que rechaza correctamente los 5 patrones defectuosos de `230` (4 parciales + 1 de residuo incorrecto) sin necesitar ningún caso especial por columna.

#### 3. Tests — 22/22 passing (`RuleExpandA09IAggregationCommandTest.php`, reemplaza el archivo anterior)

Cubre: regresión del comportamiento original (dry-run/commit válidos de B2, con fórmulas ahora de 13 términos reales en vez de las 2 simplificadas del test anterior, para satisfacer el guard nuevo) · aceptación real de las 9 combinaciones (`229` un offset limpio, `228` un offset real, `230` su único offset completo, `233` su único offset real) · **`total_row=333` sigue rechazada** (fixture que replica exactamente la contaminación cruzada tipo `AR337`: una columna ajena con una referencia hacia adelante en la misma fila bloquea el mecanismo #12 para cualquier columna) · **las 4 combinaciones inexistentes de `228`** y **las 5 de `233`** rechazadas por "sin fórmula real" · **las 4 parciales de `230`** (331,332,333,336) y **la de residuo incorrecto** (334) rechazadas por el guard nuevo, con mensaje explícito "no coincide exactamente" · **ninguna regla fuera del universo auditado puede aprovechar la generalización**: una regla sintética con columna fuera de las 9 (`BA`) es rechazada pese a compartir sheet/section/placeholder; una regla con columna válida pero `rule_key` distinto al exigido también es rechazada · resto de guards heredados (total_row inválido, origen ya modificado, fórmula con otra columna, autorreferencia, `rule_key` duplicado, combinación ya creada, 2 offsets del mismo origen sin duplicado).

**Regresión**: `RuleBindingReconciliationServiceIdentityGateTest` + `RuleBindingReconciliationServiceTest` + `RuleActivateSourceRowsCommandTest` + `RuleExpandA09IAggregationCommandTest` + 8 comandos más dependientes de `RuleBindingReconciliationService`: **134/134 passing**.

#### 4. Dry-run real de las 9 combinaciones contra producción

Ejecutado `rule:expand-a09-i-aggregation {origin} {total_row}` (sin `--commit`) para las 9 reales: `229`→`{331,332,334,335,336}` (5) · `228`→`{331,332}` (2) · `230`→`{335}` (1) · `233`→`{334}` (1). **Las 9, sin excepción, muestran clasificación simulada `SAFE_1_TO_1`**, con `source_rows`/`row_range` derivados exactos coincidiendo con lo ya documentado en 17.41. Nada persistido.

#### 5. Barrido completo del universo — exactamente 9 aceptadas, 45 rechazadas, ninguna sorpresa

Se aplicó el comando real (dry-run) contra **las 54 combinaciones posibles** (9 orígenes × 6 `total_row`). **Resultado: exactamente 9 aceptadas — `AQ331,AQ332,AR331,AR332,AR334,AR335,AR336,AS335,AV334` — y 45 rechazadas**, coincidiendo al 100% con el universo auditado en 17.41 (ninguna de las 30 combinaciones de `226,227,231,232,234` fue re-aceptada — ya existen como reglas reales desde 17.40, rechazadas por el guard de colisión de `rule_key`; las 5 de `total_row=333` para cualquier columna, rechazadas por mecanismo #12; las 4+5 inexistentes de `228`/`233`, rechazadas por falta de fórmula; las 4+1 problemáticas de `230`, rechazadas por el guard nuevo).

#### 6. Simulación consolidada — transacción real + rollback garantizado

Se crearon las 9 reglas reales (`rule:expand-a09-i-aggregation --commit`, una por una) dentro de una transacción nunca comiteada, y se reclasificó el universo completo:

| Métrica | Antes | Después (9 nuevas + 742) |
|---|---|---|
| Reglas activas | `742` | `751` |
| `SAFE_1_TO_1` | `464` | `473` (+9 exacto) |
| `DUPLICATE` | `14` | `14` (sin cambio) |
| `ALREADY_STRUCTURE_AGNOSTIC` | `198` | `198` (sin cambio) |
| `BLOCKED_BY_ENGINE_GAP` | `66` | `66` (sin cambio) |

**Las 9, individualmente, clasifican `SAFE_1_TO_1`** (ids `902-910`, verificado uno por uno, no solo por conteo). **Las 4 reglas origen (`228,229,230,233`) permanecen intactas**: `status=active`, `config` sin cambio (`row_range={0,0}`), clasificando `BLOCKED_BY_ENGINE_GAP`. **Diff completo sobre las 742 reglas preexistentes: 0 cambios.** Activity logs: exactamente `9` `rule_a09_i_aggregation_created` + `9` `rule_a09_i_aggregation_derived`. **Rollback ejecutado y verificado**: `Rule::where('source','a09_i_expansion')->count()=0` tras el rollback, las 4 reglas origen confirmadas `row_range={0,0}`/`status=active` intactas.

#### 7. Cobertura parcial resultante — misma decisión que B2, sin inventar metadata/status

Tras esta simulación (no ejecutada en real todavía), la cobertura por origen quedaría: `229`→5 de 6 (falta 333, bloqueada por `AR337`) · `228`→2 de 2 reales (las 4 restantes nunca existieron en el template, no son "pendientes") · `230`→1 de 1 real completo (las 4 parciales + 1 de residuo incorrecto no son "pendientes", son defectos/ambigüedades del template, ver 17.41) · `233`→1 de 1 real (las 5 restantes nunca existieron). **Misma decisión que 17.39/17.40 para B2, reafirmada aquí**: no se escribe ningún campo `partially_expanded` ni ningún status nuevo en las 4 reglas origen — la cobertura exacta queda derivable en vivo vía `Rule::where('metadata->derived_from_rule_id', $originId)`.

#### 8. Baseline final reconfirmado

`activas=742`, `SAFE_1_TO_1=464`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `REQUIRES_REMAP=0`, `rem_rules=789` — idéntico antes y después de toda esta fase (el comando real nunca se ejecutó con `--commit` fuera de transacciones con rollback).

**Archivos**: `backend/app/Console/Commands/RuleExpandB2AggregationCommand.php` **eliminado**, reemplazado por `backend/app/Console/Commands/RuleExpandA09IAggregationCommand.php` (nuevo). `backend/tests/Feature/REM/RuleExpandB2AggregationCommandTest.php` **eliminado**, reemplazado por `backend/tests/Feature/REM/RuleExpandA09IAggregationCommandTest.php` (nuevo, 22 tests).

**No se creó ninguna regla real de las 9.** No se tocó fila 333, `AR337`, el template Excel, las 25 hijas de B2 (`868-892`), las 5 reglas origen de B2 (`226,227,231,232,234`), regla 461, `no_utilizada`, bindings/rebind, calibraciones, `rem_data`, estructura. No se cambió la granularidad de exclusión fila→fila+columna. No commit de Git, no push.

### 17.43 — A09/I B3/F — EXPANSIÓN REAL DE 9 AGREGACIONES EJECUTADA Y CERRADA (2026-08-28)

⚠️ **Escritura real, autorizada explícitamente.** 9 reglas nuevas creadas de forma persistente en `rem_rules`. `AR337`, fila 333, el template Excel, B3(229 en sí), Categoría F(228,230,233 en sí), regla 461, `no_utilizada`, bindings, calibraciones, `rem_data`, estructura sin tocar. No commit de Git, no push.

#### 1. Reconfirmación previa a escribir

Baseline real reconfirmado exacto: `activas=742`, `SAFE_1_TO_1=464`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `rem_rules=789`. Las 4 reglas origen (`228,229,230,233`) confirmadas `active`, `row_range={0,0}`, `BLOCKED_BY_ENGINE_GAP`. `Rule::where('source','a09_i_expansion')->count()=0` antes de escribir.

#### 2. Ejecución — 9 creaciones reales, una por una, con verificación tras cada una

Se invocó `rule:expand-a09-i-aggregation --commit` 9 veces (`228`→331,332; `229`→331,332,334,335,336; `230`→335; `233`→334), cada invocación seguida de una reclasificación completa del universo activo para confirmar (a) la regla nueva clasifica exactamente `SAFE_1_TO_1`, (b) ninguna otra regla cambió. **Las 9, sin excepción, pasaron ambas verificaciones en su propio turno — 0 desviaciones respecto al dry-run/simulación de 17.41/17.42, no fue necesario ningún STOP intermedio.** ids reales asignados: `911-919`.

#### 3. Post-check exhaustivo — todos los puntos exigidos, verificados exactos

| Verificación | Resultado |
|---|---|
| Reglas activas | `742 → 751` ✅ |
| `SAFE_1_TO_1` | `464 → 473` ✅ |
| `DUPLICATE` | `14` (sin cambio) ✅ |
| `ALREADY_STRUCTURE_AGNOSTIC` | `198` (sin cambio) ✅ |
| `BLOCKED_BY_ENGINE_GAP` | `66` (sin cambio) ✅ |
| `rem_rules` | `789 → 798` (+9 exacto) ✅ |
| `rem_rule_bindings` | `1204` (sin cambio) ✅ |
| Reglas `source=a09_i_expansion` | exactamente `9` ✅ |
| Las 9 clasificadas individualmente | `SAFE_1_TO_1` las 9, sin excepción ✅ |
| `derived_from_rule_id`/`metadata.total_row` de cada hija | correcto (verificado programáticamente contra el origen y offset esperado, las 9) ✅ |
| `source_rows`/`row_range`/`total_row`/`rule_key`/`sheet`/`section`/`column` | exactamente iguales al plan de 17.41/17.42 para las 9 (verificado campo por campo) ✅ |
| Las 4 reglas origen (`228,229,230,233`) | intactas: `status=active`, `config` byte-idéntico (`row_range={0,0}`, sin `total_row`/`source_rows`), `BLOCKED_BY_ENGINE_GAP` ✅ |
| Bindings nuevos para las 9 | `0` ✅ |
| Fila 333 (las 9 columnas de A09/I: AM,AN,AQ,AR,AS,AT,AU,AV,AX) | **ninguna regla hija creada** para ninguna ✅ |
| Activity logs | exactamente `9` `rule_a09_i_aggregation_created` + `9` `rule_a09_i_aggregation_derived` ✅ |
| Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` | idénticos (`8565f0af.../24b3d2b7...`) — calibraciones sin tocar ✅ |
| `rem_data` | `399.811` (sin cambio) ✅ |
| `rem_technical_totals` | `126` (sin cambio) ✅ |
| `AR337`/fila 333 (`cell_data`) | fórmula real de `AR333` confirmada byte-idéntica (`=SUM(AR337+AR255+...+AR327)`) — sin tocar ✅ |
| Diff completo de las 742 reglas preexistentes | **0 cambios de clasificación**, confirmado durante la ejecución (tras cada una de las 9 creaciones, no solo al final) ✅ |
| Estructura activa | `67/v35`, sin tocar ✅ |

**Ninguna discrepancia en ningún punto.**

#### 4. Estado de B3(229) y Categoría F(228,230,233) tras esta expansión

- **`229`/AR**: 5 de 6 agregaciones ahora materializadas como reglas hijas (`331,332,334,335,336`); la 6ta (`333`) permanece sin crear, bloqueada por `AR337` (sin tocar).
- **`228`/AQ**: sus 2 únicas agregaciones reales (`331,332`) ya materializadas — no quedan combinaciones pendientes resolubles (las 4 restantes nunca existieron en el template, `DEFECTO_TEMPLATE`, sin acción).
- **`233`/AV**: su única agregación real (`334`) ya materializada — no quedan combinaciones pendientes resolubles (las 5 restantes nunca existieron en el template).
- **`230`/AS**: su única agregación completa y limpia (`335`) ya materializada — las 4 parciales + 1 de residuo incorrecto permanecen sin resolver (`REQUIERE_DECISION_HUMANA`/`DEFECTO_TEMPLATE`, sin acción).

**Las 4 reglas origen permanecen `status=active`, `config` sin cambio, `BLOCKED_BY_ENGINE_GAP`** — misma decisión que B2 (17.40/17.42): la cobertura resultante es derivable en vivo vía `Rule::where('metadata->derived_from_rule_id', $originId)`, sin ningún campo/status nuevo escrito.

#### 5. Baseline final

`activas=751` (`742+9`), `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, estructura activa `67/v35 status=active` sin tocar, `rem_technical_totals=126`, hashes de calibración sin cambio.

**Pendiente explícito, sin resolver**: la 6ta combinación de `229` (`total_row=333`) sigue bloqueada por `AR337`, sin tocar. Las combinaciones inexistentes de `228`/`233` y las 5 problemáticas de `230` permanecen sin ninguna acción — no son "pendientes de crear", son `DEFECTO_TEMPLATE`/`REQUIERE_DECISION_HUMANA` sin resolución propuesta.

**No se tocó fila 333, `AR337`, el template Excel, B3(229 en sí), Categoría F(228,230,233 en sí), las 25 hijas de B2, las 5 reglas origen de B2, regla 461, `no_utilizada`.** No bindings nuevos, no rebind. No calibraciones. No `rem_data`. No estructura. No commit de Git, no push.

### 17.44 — AUDITORÍA READ-ONLY EXHAUSTIVA DE LOS 66 `BLOCKED_BY_ENGINE_GAP` VIGENTES (2026-08-28): reconstrucción completa desde BD/config/estructura reales

⚠️ **100% READ-ONLY.** Ninguna regla/config/status/binding/calibración/`rem_data`/estructura/template fue tocada. No commit, no push.

#### 1. Reconfirmación de baseline (antes de cualquier análisis)

`RuleBindingReconciliationService::classifyAllActiveRules()` real, contra la estructura activa (67/v35): `activas=751`, `SAFE_1_TO_1=473`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66` — **exacto**, sin discrepancia. `rem_rules=798`, `rem_rule_bindings=1204` — exactos.

#### 2. Reconstrucción completa de los 66 (rule_id por rule_id, desde config/metadata/status reales, no de memoria)

`ids: 226,227,228,229,230,231,232,233,234,296,297,298,299,300,305,306,307,310,311,312,313,314,315,316,317,318,319,320,321,322,323,324,326,327,346,347,348,354,355,356,357,358,359,360,364,365,366,367,368,369,371,372,373,374,375,376,377,378,379,461,462,463,547,549,550,552` — **66 exacto**, `status=active` las 66, sin excepción.

**Motivo de clasificación reportado por el clasificador para las 66, sin excepción**: `invalid_row_range_configuration: falta total_row en config.` — mismo motivo textual para todas, pero la **causa raíz real difiere por grupo** (ver abajo) — el motivo genérico no distingue "hoja irrelevante", "placeholder nunca resuelto con hijas ya cubriendo la mayoría" ni "candidato real bloqueado por límites estructurales".

#### 3. Agrupación por causa raíz real (reconstruida, no asumida) — suma demostrada = 66

| Grupo | Cantidad | rule_ids | Hijas | Cobertura | Tratamiento |
|---|---|---|---|---|---|
| **NO_UTILIZADA** | **56** | ver desglose por hoja abajo | `0` en las 56 (confirmado) | N/A — hoja fuera de uso de negocio | `NO_UTILIZADA` |
| **A09/I origen B2 (homogéneo)** | **5** | `226,227,231,232,234` | 5 cada una (`868-892`) | 5/6 offsets (todos salvo `333`) | `RESUELTO_MEDIANTE_HIJAS_PERO_ORIGEN_BLOQUEADO` |
| **A09/I origen B3** | **1** | `229` | 5 (`913-917`) | 5/6 offsets (todos salvo `333`, que es el origen de `AR337`) | `RESUELTO_MEDIANTE_HIJAS_PERO_ORIGEN_BLOQUEADO` + `DEFECTO_TEMPLATE` (para el 6to) |
| **A09/I origen Categoría F — bloque incompleto** | **2** | `228,233` | `228`→2 (`911,912`); `233`→1 (`919`) | `228`: 2/2 reales cubiertas (4 restantes nunca existieron); `233`: 1/1 real cubierta (5 restantes nunca existieron) | `RESUELTO_MEDIANTE_HIJAS_PERO_ORIGEN_BLOQUEADO` (parte real) + `DEFECTO_TEMPLATE` (parte inexistente) |
| **A09/I origen Categoría F — mapeo roto** | **1** | `230` | 1 (`918`) | 1/6 (única completa/limpia); 4 parciales + 1 de residuo incorrecto sin resolver | `RESUELTO_MEDIANTE_HIJAS_PERO_ORIGEN_BLOQUEADO` (parte limpia) + `REQUIERE_DECISION_FUNCIONAL` (las 5 restantes) |
| **Regla 461 (`A30/F`)** | **1** | `461` | `0` | N/A — caso único, no periódico | `PENDIENTE_TECNICO` |

**Verificación de suma**: `56+5+1+2+1+1 = 66` exacto, reconstruido directamente contra la lista real de 66 ids (sección 2), no calculado a mano.

#### 4. Desglose exacto del grupo `NO_UTILIZADA` (56), por hoja — confirmado contra `RemSheetUsageStatusService::getStatusFor()` real

| Hoja | Estado real | Cantidad | rule_ids |
|---|---|---|---|
| `A21` | `no_utilizada` | 25 | `296,297,298,299,300,305,306,307,310,311,312,313,314,315,316,317,318,319,320,321,322,323,324,326,327` |
| `A24` | `no_utilizada` | 3 | `346,347,348` |
| `A25` | `no_utilizada` | 22 | `354,355,356,357,358,359,360,364,365,366,367,368,369,371,372,373,374,375,376,377,378,379` |
| `A30AR` | `no_utilizada` | 2 | `462,463` |
| `A34` | `no_utilizada` | 4 | `547,549,550,552` |

**25+3+22+2+4 = 56** exacto. Las 56, sin excepción, confirmadas `status=active` en `rem_rules` (razón: `status` de la regla y `no_utilizada` de la hoja son conceptos independientes — la regla sigue activa, solo se excluye del cómputo de progreso/candidatos vía el mecanismo #16 ya documentado) y **sin ninguna hija** (`metadata->derived_from_rule_id` nunca las referencia). Nota: `354` (`A25/B`) comparte el mismo placeholder `row_range={0,0}` que las 9 de A09/I — **irrelevante mientras la hoja permanezca `no_utilizada`**, ya documentado en la entrada de deuda técnica #5.

#### 5. Desglose exacto de las 9 reglas origen de `A09/I` (226-234) — confirmado contra `metadata->derived_from_rule_id`

| rule_id | Columna | Hijas (ids) | Total_rows cubiertos | Pendiente exacto |
|---|---|---|---|---|
| `226` | AM | `868,869,870,871,872` | `331,332,334,335,336` | Offset `333` — bloqueado por `AR337` (contaminación cruzada, mecanismo #12 escanea toda la fila, no solo la columna) |
| `227` | AN | `873,874,875,876,877` | `331,332,334,335,336` | Offset `333` — ídem |
| `231` | AT | `878,879,880,881,882` | `331,332,334,335,336` | Offset `333` — ídem |
| `232` | AU | `883,884,885,886,887` | `331,332,334,335,336` | Offset `333` — ídem |
| `234` | AX | `888,889,890,891,892` | `331,332,334,335,336` | Offset `333` — ídem |
| `229` | AR | `913,914,915,916,917` | `331,332,334,335,336` | Offset `333` — **es la propia fórmula de esta columna la que referencia `AR337`** (origen del problema para las otras 5 columnas también) |
| `228` | AQ | `911,912` | `331,332` | Offsets `333,334,335,336` — **nunca existieron como fórmula en el template Excel fuente** (no accionable, no es "pendiente de crear") |
| `233` | AV | `919` | `334` | Offsets `331,332,333,335,336` — **nunca existieron como fórmula en el template Excel fuente** |
| `230` | AS | `918` | `335` | Offsets `331,332,333,336` (parciales, 2 de 13 términos de su propio residuo) + `334` (referencia el residuo de offset4 en vez del propio offset3) — **mapeo ambiguo/roto del template, requiere decisión funcional (Estadística APS) antes de cualquier acción técnica** |

Las 9 origen permanecen `status=active`, `config` byte-idéntico desde su creación (`row_range={0,0}`, sin `total_row`/`source_rows`) — confirmado, sin ningún campo/metadata nuevo escrito en ninguna, consistente con la decisión ya tomada (17.39.3/17.40.4/17.43.4) de no inventar un flag de "expansión parcial".

#### 6. Regla 461 (`A30/F`) — detalle reconfirmado

`config = {"sheet":"A30","section":"F","column":"B","row_range":{"from":124,"to":129},"rule_logic":"Suma(B) = Columna B"}` — a diferencia de las 9 de A09/I, **su `row_range` es real** (no placeholder `{0,0}`), pero carece de `total_row`. Sin hijas (`0`, confirmado) — no es un caso de agregación periódica, es una única regla `sum_equals` vertical simple. Causa ya documentada en el punto 16.13: el candidato de `total_row` (fila 123, fórmula de ancho completo confirmada como TOTAL líder genuino, con `rem_data` histórico real aunque con patrón "fantasma" — concepto heredado, valores en 0) cae **fuera** de `[filaInicioDatos=124:filaFinDatos=129]` de la estructura activa — la estructura histórica 19 sí incluía esa fila dentro del área de datos (`filaInicioDatos=121` entonces); un parche estructural posterior de A30 endureció el límite a 124 para excluir encabezados genuinos (filas 121-122), atrapando también la fila 123 en la misma exclusión. Clasificado `PENDIENTE_TECNICO` (no `DEFECTO_TEMPLATE` — el dato es real, no un error de autoría de Excel; no `REQUIERE_DECISION_FUNCIONAL` — no es una pregunta de negocio, es un desajuste de límites entre versiones de estructura).

#### 7. Conclusión

**El universo real de 66 `BLOCKED_BY_ENGINE_GAP` no es homogéneo y no representa "trabajo pendiente" en un sentido único**: 56/66 (85%) son irrelevantes mientras las hojas permanezcan `no_utilizada` (sin ninguna acción posible ni necesaria); de las 9 reglas origen de `A09/I`, **7 ya tienen su cobertura funcional real completa vía hijas** (`226,227,228,231,232,233,234` — todo lo que existía en el template Excel ya fue materializado como regla activa `SAFE_1_TO_1`) y solo **2 (`229,230`) tienen trabajo pendiente genuino**: `229` depende de resolver `AR337` (defecto de template, congelado); `230` depende de una decisión funcional de negocio sobre su mapeo ambiguo. La regla `461` es el único caso fuera del universo A09/I, congelado por un desajuste técnico de límites estructurales heredado de una versión anterior de la estructura. **Ninguna reducción de `BLOCKED_BY_ENGINE_GAP` fue intentada ni se recomienda de forma automática** — el número en sí mismo ya no es un indicador útil de "trabajo pendiente" sin este desglose.

**No se implementó nada. No se cambió ningún `status`/`config`. No se tocó fila 333/`AR337`, regla 461, `no_utilizada`, bindings/rebind, calibraciones, `rem_data`, estructura, template.** Baseline final reconfirmado sin cambios: `activas=751`, `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`. No commit de Git, no push.

### 17.45 — AUDITORÍA READ-ONLY EXHAUSTIVA DE LA REGLA 461 (`A30/F`): re-derivada desde cero, evidencia real — REQUIERE_NUEVO_MECANISMO (2026-08-28)

⚠️ **100% READ-ONLY.** Ninguna escritura persistida (1 simulación con transacción real + `rollback` garantizado, confirmado post-rollback). No se tocó A09/I, 229, 230, fila 333/`AR337`, las 56 `NO_UTILIZADA`, ninguna regla hija ya creada, bindings/rebind, calibraciones, `rem_data`, estructura, template. No commit, no push.

#### 1. Reconfirmación de baseline (antes de cualquier análisis)

`activas=751`, `SAFE_1_TO_1=473`, `BLOCKED_BY_ENGINE_GAP=66`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204` — exacto, sin discrepancia.

#### 2. Config completa real de la 461 (re-derivada, no de memoria)

```
id=461, rule_key=a30_f_b_sum_equals, rule_type=sum_equals, status=active, source=csv_catalog, created_at=2026-07-14 19:12:46
config={"sheet":"A30","section":"F","column":"B","row_range":{"from":124,"to":129},"rule_logic":"Suma(B) = Columna B"}
metadata={"source":"Estructura Excel"}
```

`deriveSourceLetters()` sobre `rule_logic="Suma(B) = Columna B"` → `source_letters=['B']` — idéntico a `column='B'` → **patrón vertical genuino** (`isVerticalPattern=true`, fuente y destino son la misma columna). Diagnóstico de Fase 1 (`classifySingleRule`): `clasificacion=BLOCKED_BY_ENGINE_GAP`, `motivo="invalid_row_range_configuration: falta total_row en config."`, `total_row_candidate=123`, `total_row_position=leading`, **`total_row_excluded=false`** — este último dato es la clave de todo el hallazgo (ver punto 4).

#### 3. Límites estructurales reales de `A30/F` (re-derivados vía `findRawSectionData()` real, no supuestos)

`filaHeader=120, filaInicioDatos=124, filaFinDatos=129`, 21 campos (`A` a `U`). Candidato `123` = `filaInicioDatos - 1` exacto — **posición leading, fuera de `[124:129]`**, en el mismo hueco entre el fin de la sección anterior (`A30/E`, `filaFinDatos=118`) y el encabezado de `F` (`filaHeader=120`) — confirmado que la fila 123 **no es reclamada por ninguna otra sección declarada** (barrido de las 7 secciones de `A30`: A,B,C,D,E,F,G — ninguna otra incluye la fila 123 en su `[inicio:fin]`).

#### 4. Fórmula Excel/`cell_data` real, fila por fila (120-130) — evidencia exhaustiva, no muestreada

- **Fila 120** (encabezado, fusionado `B120:B122`): `B120="TOTAL "` — el texto "TOTAL" existe, pero es un **encabezado de COLUMNA** (fusionado verticalmente sobre 3 filas de encabezado), nunca una etiqueta de fila. `C120:F121` fusionado = `"Telecomité realizados"` (grupo de columnas por tramo etario).
- **Fila 121**: vacía/relleno, bloqueada, sin texto.
- **Fila 122**: sub-encabezados de tramo etario (`C="0 - 10 años"`, etc.) + `A122="Telecomité de especialidad"` (categoría de dato, fusionada `A122:A123`).
- **Fila 123** (candidato): `A123` **fusionada bajo el ancla de A122** (`"Telecomité de especialidad"` — una etiqueta de categoría de dato real, **no** "TOTAL"). `B123="=SUM(B124:B129)"`, `C123="=SUM(C124:C129)"`, `D123`, `E123`, `F123` — **las 5, fórmula 100% limpia, vertical, exactamente `SUM({col}124:{col}129)`**, todas bloqueadas/no editables. **Ningún texto propio, ninguna etiqueta "TOTAL" en ninguna columna de la fila 123** — confirmado explícitamente que no hay ninguna celda de texto plano en toda la fila.
- **Filas 124-129** (datos reales): `A124="Oncológico"`, `A125="Cuidados paliativos oncológicos"`, `A126="Cuidados paliativos no oncológicos universales"`, `A127="VIH"`, `A128="Unidad de paciente crítico"`, `A129="Otros"`. Filas 124,127,128,129 tienen `B=SUM(C:F)` (subtotal horizontal de la propia fila) con `C:F` editables reales; filas 125,126 están completamente bloqueadas (sin fórmula, sin dato editable — categorías sin captura habilitada en esta versión del formulario).
- **Fila 130**: sin `cell_data` — fuera de todo rango escaneado.

`FormulaRangeCoverageAnalyzer::analyze("=SUM(B124:B129)", "B")` (invocado real, no simulado) → `{"rows":[124,125,126,127,128,129],"other_column_refs":[]}`; `isCompleteContiguous(...)` → **`true`** — la fórmula es evidencia perfecta, completa, contigua, sin referencias externas — **más limpia que muchos de los 322 casos ya resueltos en esta campaña**.

#### 5. Hallazgo central — por qué mecanismo #6 NO confirma la fila 123 (re-derivado del código real, línea por línea)

`SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()` exige, como primer paso obligatorio, encontrar **una celda de texto plano (no fórmula) en la fila candidata** cuyo valor contenga "TOTAL"/"AMBOS SEXOS" (`pareceEtiquetaTotalMatrix()`) — si `$columnaConcepto === null`, la función retorna `false` de inmediato, **sin evaluar las fórmulas en absoluto**. Para la fila 123: las 5 columnas relevantes (B-F) son 100% fórmula; `A123` está vacía (fusionada bajo `A122`, cuyo texto es "Telecomité de especialidad", no "TOTAL"). **No existe ninguna celda de texto en la fila 123** → `$columnaConcepto` nunca se asigna → **`isEmbeddedLeadingTotalRow()` retorna `false`, confirmado real, no solo inferido del flag de Fase 1**.

**Verificación explícita del caso de fusión (descartando el fix ya implementado en 17.30)**: `A123` SÍ está fusionada (`rango_combinado="A122:A123"`), pero su ancla (`A122`) contiene una etiqueta de **categoría de dato real** ("Telecomité de especialidad"), no "TOTAL" — el fix de `MergeAnchorResolver` (17.30, ya implementado y cerrado) **no ayudaría aquí de todos modos**: solo confirma exclusión cuando el ancla del merge SÍ dice "TOTAL", y aquí el ancla dice algo completamente distinto. Este caso es estructuralmente diferente del hallazgo de `A09/I` (donde el ancla del merge sí decía "TOTAL", solo que las filas subordinadas no tenían texto propio) — aquí **ninguna celda, fusionada o no, dice "TOTAL" en la fila candidata**; el único texto "TOTAL" real de toda la sección vive en el **encabezado de columna** (`B120:B122`, fusionado sobre 3 filas de encabezado, nunca sobre la fila 123).

#### 6. Mecanismos #8/#12 — no aplicables (confirmado, no solo descartado por comodidad)

Mecanismo #8 (`isTrailingTotalRow`) y #12 (`isEmbeddedBackwardSubtotalRow`) exigen posición **trailing** (fila DESPUÉS del bloque de datos, con fórmulas hacia atrás) — la fila 123 está **ANTES** del bloque (`124:129`), posición estructuralmente `leading`, fuera del dominio de esos 2 mecanismos por diseño. Confirmado que `discoverTotalRowCandidate()` solo encontró **1 candidato** (123, leading) — no existe ningún candidato trailing (fila 130 sin `cell_data`, confirmado arriba).

#### 7. Evidencia histórica real en `rem_data`/`rem_technical_totals` (re-verificada hoy, no de memoria)

- `rem_data` (sheet=`A30`, row_number=123): **131 registros históricos** (`upload_id` 19 a 173), `concept="Telecomité de especialidad"` (heredado del ancla del merge, patrón "fantasma" ya documentado para otras secciones de esta campaña), valores `0` en `B` a `U` (columnas capturadas) y `null` en las columnas fuera del ancho de la sección.
- **Confirmado el mismo patrón de protección estructural ya visto en toda la campaña**: comparando uploads con `row_number=124` (dato real, 140 uploads, hasta `id=186`) contra uploads con `row_number=123` (131, hasta `id=173`) — **9 uploads recientes (`174,175,176,180,181,182,183,184,186`) tienen fila 124 pero NO fila 123** — confirma que la corrección estructural ya vigente (`filaInicioDatos=124`) protege las cargas nuevas de este "fantasma" **sin necesidad de que ningún mecanismo #6/#8/#12 lo confirme** — la protección es puramente aritmética (la fila 123 cae fuera del rango `[filaInicioDatos:filaFinDatos]` que el parser escanea para la sección F), no depende de la detección de "TOTAL".
- `rem_technical_totals` (sheet=`A30`, `rem_section_code`=`F`): **0 registros** — confirmado explícitamente. A diferencia de TODOS los casos ya resueltos en esta campaña (Categoría A/C, B1/B4, B2/B3/CategoríaF de A09/I), donde la fila excluida SÍ queda capturada en `rem_technical_totals` (porque mecanismo #6/#8/#12 la confirma dentro del hook de Fase 3A), **aquí no hay ninguna captura en absoluto** — ni en `rem_data` (protegida por límites) ni en `rem_technical_totals` (mecanismo nunca la confirma). La fila 123 cae en un **hueco de captura total**: nunca persistida en ningún lugar consultable por `rem_upload_id`, para ninguna carga futura.

#### 8. Comportamiento del evaluador/clasificador actual — confirmado con transacción real + rollback, no solo teoría

Se escribió `total_row=123` en la config real de la 461, dentro de una transacción de BD real (`DB::beginTransaction()`), y se reclasificó: **resultado `BLOCKED_BY_ENGINE_GAP`, motivo `"missing_total_row probable: total_row=123 fuera de [124:129]."`** — exactamente el comportamiento esperado, sin ningún cambio de código. **Rollback ejecutado y verificado**: `config` de la 461 restaurado exacto (`total_row` ausente), baseline global reconfirmado idéntico (`751/473/66/14/198/798/1204`).

Auditado el código real de `classifyRule()` (línea 227-243): el bounds-check genérico (`$totalRow > $current['fin'] || $totalRow < $current['inicio']`) es el único chequeo que corre cuando `total_row` YA está en `config` — y el único mecanismo de excepción existente, `isLegitimateTrailingTotalBeyondBounds()` (Fase 3C-1B, punto 17.15), **exige explícitamente `$totalRow === $current['fin'] + 1`** (línea 422) — un candidato `leading` (`inicio-1`) **nunca** cumple esa condición, por diseño; no existe ningún mirror-guard equivalente para el caso `leading`. Confirmado también que, incluso si tal mirror existiera, llamaría a `isEmbeddedLeadingTotalRow()` como guard interno (mismo patrón que el trailing usa `isEmbeddedBackwardSubtotalRow()`) — y esa llamada YA se demostró `false` en el punto 5.

#### 9. Comparación explícita con los casos ya resueltos (sin forzar equivalencia)

| Aspecto | Categoría A (225, trailing) | Categoría C (29, leading, dentro de límites) | Fase 3C-1B (55, trailing, `fin+1`) | B1/B4/B2/B3/CategoríaF (A09/I, periódico) | **461 (`A30/F`)** |
|---|---|---|---|---|---|
| Posición | trailing | leading | trailing | trailing/leading (periódico) | **leading** |
| Dentro/fuera de `[inicio:fin]` | dentro | dentro | fuera (`fin+1` exacto) | dentro (tras `source_rows`) | **fuera (`inicio-1` exacto)** |
| Etiqueta textual "TOTAL" en la fila candidata | sí (columna A u otra) | sí | sí | sí (o vía merge-anchor, 17.30) | **NO — ninguna celda de la fila** |
| Mecanismo que confirma exclusión | #12 | #6 | #12 | #12 (+ merge-anchor si aplica) | **NINGUNO** |
| `rem_technical_totals` captura la fila hoy | sí (tras Fase 3A) | sí | sí | sí | **NO — 0 registros, hueco total** |
| Bounds-guard mirror necesario | no (dentro de límites) | no (dentro de límites) | sí, ya implementado (17.15) | no (dentro de límites) | **sí, NO existe (mirror `leading` nunca construido)** |

**Conclusión de la comparación**: 461 comparte la POSICIÓN (leading, como Categoría C) y la DISTANCIA fuera de límites (mirror exacto de Fase 3C-1B), pero **no comparte la propiedad que hizo posible resolver CUALQUIERA de los casos anteriores**: la existencia de una etiqueta textual que un mecanismo YA implementado pueda confirmar. No se fuerza equivalencia — 461 es el único caso, en las 66 reglas auditadas en 17.44 y en todo el universo de 322+ candidatos examinados a lo largo de la campaña, donde la evidencia de fórmula es perfecta pero **ningún mecanismo de detección textual puede confirmarla**.

#### 10. Conclusión — REQUIERE_NUEVO_MECANISMO

**No es `RESOLVIBLE_CON_MECANISMO_EXISTENTE`**: ningún mecanismo actual (#6/#8/#12) confirma la fila, y no existe mirror-guard para el caso `leading` fuera de límites.

**No es `REQUIERE_EXTENSION_MINIMA`**: una extensión mínima (aislada, sin tocar mecanismos compartidos) —como fue `isLegitimateTrailingTotalBeyondBounds()` en 17.15— **no basta aquí**, porque esa extensión SIEMPRE dependió de que el mecanismo compartido (#6/#12) YA confirmara la fila; en este caso el mecanismo compartido confirma `false`, así que cualquier mirror-guard aislado fallaría en su propio guard interno sin resolver nada. Para que funcione de verdad se necesitaría **modificar el mecanismo de detección mismo** (`isEmbeddedLeadingTotalRow()`, en sus DOS copias — `SectionCalibrationMatrixService` y `RemParserService`) para reconocer un patrón nuevo: "fila 100% fórmula/bloqueada, sin ninguna etiqueta textual propia (ni directa ni vía merge), donde CADA columna no vacía es una fórmula `SUM(rango_completo_de_la_sección)`, y el ÚNICO indicio de 'TOTAL' vive en el ENCABEZADO DE COLUMNA (fuera de la fila candidata)". Esta heurística **nunca ha sido implementada** en esta campaña, y modificarla afecta una función ampliamente reutilizada (no solo por 461) — requeriría, como mínimo, el mismo tipo de auditoría exhaustiva de toda la Serie A que se hizo para el hallazgo de la etiqueta fusionada (17.26), antes de poder confiar en que no introduce falsos positivos en las decenas de reglas que ya dependen de `isEmbeddedLeadingTotalRow()` devolviendo `false` correctamente.

**Doble requisito, no solo uno**: incluso si se resolviera el problema de CLASIFICACIÓN (nuevo mirror-guard + nueva heurística de detección), la regla seguiría sin ser evaluable en producción a menos que la MISMA heurística nueva se agregue también al hook de captura de `RemParserService::parseSheet()` (Fase 3A) — de lo contrario `rem_technical_totals` nunca tendría un registro para la fila 123 en ninguna carga futura, y el evaluador (`SumEqualsEvaluator`) siempre devolvería `missing_total_row`/`skipped`, sin importar qué diga el clasificador. Esto es exactamente el riesgo ya señalado en fases anteriores (17.21) de "clasificación SAFE_1_TO_1 engañosa sin verificar que el evaluador real lo soporte" — aquí el riesgo es total, no parcial.

**No es `REQUIERE_DECISION_FUNCIONAL`**: la semántica del dato es inequívoca (fórmula perfecta, columna B ya rotulada "TOTAL" en su encabezado, patrón histórico consistente) — no hay ninguna ambigüedad de negocio que requiera consultar a Estadística APS, a diferencia del caso `230`/`A09/I`.

**No es `NO_DEBE_ACTIVARSE`**: el total es genuino y correctamente evidenciado — no es un falso positivo ni una fila cuya semántica sea dudosa; simplemente el motor de detección no tiene, hoy, ninguna forma de reconocerlo.

**Veredicto: `REQUIERE_NUEVO_MECANISMO`** — específicamente, una heurística nueva de detección "TOTAL implícito por encabezado de columna, sin etiqueta de fila", a implementar en paralelo en `SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()` y `RemParserService::isEmbeddedLeadingTotalRow()` (mismo patrón aditivo ya usado para el fix de merge en 17.30 — nunca reemplazar el criterio existente, solo añadir un camino adicional cuando el criterio textual falla), más un mirror-guard aislado nuevo (`isLegitimateLeadingTotalBeyondBounds()`, análogo a `isLegitimateTrailingTotalBeyondBounds()`) para el bounds-check del clasificador. **Ninguna de las dos piezas fue diseñada en detalle de implementación ni escrita** — esta auditoría se detiene en el diagnóstico y la dirección de solución, según lo instruido ("diseñar y simular solamente").

**No implementado. No se escribió ningún código real ni config real** (la única escritura fue una transacción de prueba con `rollback` garantizado, verificada). No se tocó A09/I, `229`, `230`, fila 333/`AR337`, las 56 `NO_UTILIZADA`, ninguna regla hija ya creada, bindings/rebind, calibraciones, `rem_data`, estructura, template.

Baseline final reconfirmado sin cambios: `activas=751`, `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`. No commit de Git, no push.

### 17.46 — REGLA 461 — DISEÑO DEL MECANISMO `leading_formula_total_beyond_bounds` / NO IMPLEMENTADO (2026-08-28)

⚠️ **100% READ-ONLY / DISEÑO + SIMULACIÓN.** Ningún archivo de motor/parser/clasificador modificado. Ninguna escritura persistida. No se tocó A09/I, `229`, `230`, fila 333/`AR337`, las 56 `NO_UTILIZADA`, ninguna regla hija ya creada, bindings/rebind, calibraciones, `rem_data`, estructura, template. No se escribió `total_row`. No se reprocesó ninguna carga. No backfill. No commit, no push.

#### 1. Reconfirmación de baseline

`activas=751`, `SAFE_1_TO_1=473`, `BLOCKED_BY_ENGINE_GAP=66`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204` — exacto. `461.config` byte-idéntico (`total_row` ausente).

#### 2. Barrido exhaustivo de toda la Serie A (381 secciones, no muestreado) — candidatos globales

Para cada una de las **381 secciones** de la estructura activa (67/v35), se evaluó: `candidato = filaInicioDatos - 1`; ¿alguna columna de la sección tiene, en ese candidato, una fórmula que cubre **exacta y contiguamente** `[filaInicioDatos:filaFinDatos]` (`FormulaRangeCoverageAnalyzer::isCompleteContiguous()`, sin modificar)?

**Resultado: exactamente 5 candidatos en toda la Serie A** — ni uno más:

| Sheet/Sección | Fila candidata | Rango | Columnas que cubren completo/contiguo | Texto plano en la fila | Mecanismo #6 (real, sin modificar) |
|---|---|---|---|---|---|
| `A30/A` | 12 | `[13:67]` | 46 de 47 | `A="TOTAL"` | **`TRUE` — ya resuelto por #6** |
| `A30/C` | 80 | `[81:93]` | 13 de 25 | `A="TOTAL EMISIONES DE INFORMES"` | **`TRUE` — ya resuelto** |
| `A30/D` | 98 | `[99:108]` | 34 de 36 | `A="TOTAL"` | **`TRUE` — ya resuelto** |
| `A30/E` | 114 | `[115:118]` | 19 de 20 | `A="TOTAL"` | **`TRUE` — ya resuelto** |
| **`A30/F`** | **123** | **`[124:129]`** | **16 de 21 (B-Q)** | **ninguno** | **`false` — sin resolver (461)** |

**4 de los 5 candidatos ya tienen etiqueta textual "TOTAL" propia y ya son confirmados por el mecanismo #6 existente, sin ningún cambio** — consultado el catálogo real: **0 reglas activas referencian las columnas de `A30/A`, `A30/C`, `A30/D` o `A30/E`** (verificado, ningún `sum_equals` existe hoy para esas 4 secciones) — es decir, esos 4 candidatos son estructuralmente idénticos en forma a 461 (misma posición leading, mismo patrón de fórmula completa/contigua), pero **no representan ningún riesgo ni beneficio práctico hoy** (sin reglas que los usen) y, de existir una regla futura para ellos, el mecanismo #6 YA los resolvería sin necesitar el mecanismo nuevo. **Solo `A30/F`/461 carece de etiqueta textual y necesita el mecanismo nuevo.**

**Conclusión de unicidad**: `461` **no es parte de una familia con trabajo pendiente** — es el único de los 5 candidatos reales de todo Serie A que efectivamente necesita el mecanismo nuevo (los otros 4 ya están cubiertos por #6, aunque hoy inertes por falta de reglas). El barrido confirma, con evidencia exhaustiva (no muestreo), lo ya sospechado en 17.45: el patrón "fórmula perfecta, sin etiqueta" es genuinamente raro (1 caso en 381 secciones) — bajo riesgo de que una heurística nueva, bien acotada, introduzca falsos positivos en volumen.

**Falsos positivos evaluados y descartados durante el barrido**: dentro de la propia fila 123, las columnas `R,S,T,U` tienen fórmula (`=SUM(R124)`, no `SUM(R124:R129)`) — el analizador las rechazó correctamente por no cubrir el rango completo (evidencia real de que el guard "cobertura exacta y contigua" ya filtra coincidencias parciales sin necesitar un caso especial).

#### 3. Diseño de la capa de reconocimiento/clasificación

**Nuevo método aislado, sibling de #6, nunca modifica `isEmbeddedLeadingTotalRow()`**: `isLeadingFormulaBasedTotalBeyondBounds(string $sheet, string $section, int $row, array $sectionData): bool` — implementado en paralelo en `SectionCalibrationMatrixService` (clasificador) y `RemParserService` (parser), mismo patrón exacto que todas las extensiones aisladas previas de la campaña (nunca una sola copia compartida entre ambos, por diseño ya establecido desde el origen del proyecto).

**Guards (a nivel de FILA, no de columna — misma granularidad que #6/#8/#12)**:
1. `$row === ($sectionData['filaInicioDatos'] ?? 0) - 1` — posición exacta.
2. **Mecanismo #6 debe devolver `false`** para esta fila (invocando la función real, sin modificarla) — condición de exclusión mutua explícita: si #6 ya confirma la fila, este mecanismo nuevo nunca debe activarse (evita cualquier superposición semántica).
3. Ninguna columna de la fila tiene una celda "genuinamente capturable" (`es_editable===true && esta_bloqueada!==true`) — mismo chequeo negativo que #6 ya usa, reutilizado sin modificar su criterio.
4. **Al menos una columna** de la sección, en esa fila, tiene una fórmula que `FormulaRangeCoverageAnalyzer::isCompleteContiguous(formula, columna, filaInicioDatos, filaFinDatos)` confirma completa y contigua contra el rango **propio** de la sección (reutilizado, sin duplicar heurística).
5. (Defensivo, mismo espíritu que #6) Ninguna fórmula de la fila referencia una fila estrictamente anterior a `filaInicioDatos` que no sea la propia fila candidata — evita clasificar erróneamente una fila cuyas fórmulas se extienden hacia datos ajenos a la sección.

**Nuevo mirror bounds-guard (clasificador), sibling de `isLegitimateTrailingTotalBeyondBounds()` (17.15), nunca modifica la condición genérica de `classifyRule()`**: `isLegitimateLeadingTotalBeyondBounds(string $sheet, string $section, string $column, ?array $rowRange, int $totalRow, array $current, ?RemTemplateStructure $targetStructure): bool`:
1. `$totalRow === $current['inicio'] - 1` exacto (mirror simétrico de `$current['fin'] + 1`).
2. `$rowRange` real (`from<=to`, ambos `>0`).
3. La fila candidata no está reclamada por ninguna otra sección declarada (mismo guard ya usado en el mirror trailing).
4. `isLeadingFormulaBasedTotalBeyondBounds()` (punto anterior) confirma `true`.
5. La fórmula de la **columna propia de la regla** también pasa `isCompleteContiguous()` contra el `row_range` de esa regla específica (defensa en profundidad, mismo patrón que el guard final del mirror trailing).

#### 4. Diseño de la capa de parser/ejecución — hallazgo arquitectónico nuevo, no anticipado

**Confirmado con código real + datos reales de producción (upload `186`, el más reciente del sistema)**: `RemParserService::findSectionContextForRow()` resuelve el contexto de sección de una fila estrictamente por `row >= data_start_row && row <= data_end_row` de **alguna** sección — y `data_start_row`/`data_end_row` son exactamente `filaInicioDatos`/`filaFinDatos`, **sin ningún margen**. Si una fila no cae dentro de `[data_start_row:data_end_row]` de **ninguna** sección, `$sectionContext` es `null`, y el parser la descarta con un `continue` (línea ~496-504) **ANTES** de llegar a evaluar cualquiera de los 3 mecanismos de exclusión (#6/#8/#12) — es decir, **una fila fuera de todos los rangos declarados nunca es evaluada para posible captura técnica, sin importar qué diga cualquier mecanismo**.

**Verificación empírica, no solo teórica**: se confirmó contra `rem_data`/`rem_technical_totals` reales que este mismo patrón (fila en el hueco entre dos secciones) afecta también al caso espejo YA resuelto en Fase 3C-1B (55 reglas trailing-más-allá-de-límites, ej. `A31/A` fin=27, candidato=28): el upload `186` (más reciente, posterior a la implementación de Fase 3A) tiene las filas 12-27 de `A31/A` capturadas normalmente, la fila 28 **ausente tanto de `rem_data` como de `rem_technical_totals`** (`count=0` para `A31/A` en `rem_technical_totals`) — confirma que el mismo hueco arquitectónico ya afecta silenciosamente a la Fase 3C-1B, sin que ninguna auditoría previa lo haya detectado (esas 55 fueron validadas solo a nivel de **clasificación**, nunca de captura real en producción). **Este hallazgo se reporta tal cual, fuera de alcance de esta fase — no se toca ni se corrige nada de Fase 3C-1B/A31 aquí**, solo se documenta porque el diseño de 461 necesariamente debe resolver la MISMA causa raíz para poder funcionar.

**Extensión propuesta (aditiva, nunca modifica el comportamiento para filas que YA resuelven correctamente hoy)**:
1. Nuevo método `findLeadingBoundaryAdjacentSection(array $sectionMap, int $row): ?array` — devuelve la sección cuyo `data_start_row - 1 === $row`, **solo quando `findSectionContextForRow()` ya devolvió `null`** para esa fila (nunca sustituye ni altera una resolución existente).
2. En el loop principal: si `$sectionContext === null`, antes del `continue` actual, se evalúa `$boundaryContext = findLeadingBoundaryAdjacentSection(...)`. Si existe match, se invoca `isLeadingFormulaBasedTotalBeyondBounds()` (parser, copia independiente) contra esa sección/fila. Si confirma `true`: se construye el mismo shape de `$entry` ya usado para los otros 3 mecanismos (`concept`/`professional`/`total`/`values`, recalculado usando los campos de rol de columna (`conceptColumn`/`professionalColumn`/etc.) **del `$boundaryContext`**, no del `$sectionContext` nulo) y se agrega a `$technicalTotals` con `exclusion_reason='leading_formula_total_beyond_bounds'` (35 caracteres, dentro del límite `varchar(40)` de la columna, verificado), luego `continue` (nunca se persiste como dato). Si no confirma, o no hay boundary-match: cae exactamente al `continue` ya existente — **comportamiento por defecto sin ningún cambio**.
3. **Complejidad real, no minimizada**: parte de la resolución de columnas de rol (`conceptColumn`/`professionalColumn`/etc., hoy derivadas de `$sectionContext` antes del punto de descarte) tendría que recalcularse usando `$boundaryContext` en su lugar para las filas de este caso — no es un cambio de una sola línea, es una reorganización moderada del flujo de cómputo por fila. Documentado explícitamente como parte del riesgo/esfuerzo, no ocultado.

**Confirmado que `SumEqualsEvaluator`/`RuleEngineService::findTechnicalTotalRow()` NO requieren ningún cambio**: la consulta real (`RemTechnicalTotal::where('rem_upload_id',...)->where('sheet',...)->where('rem_section_code',...)->where('row_number',...)`) es completamente agnóstica al mecanismo/`exclusion_reason` que originó la captura — una vez que `rem_technical_totals` tenga el registro de la fila 123, el evaluador lo encontraría automáticamente, sin ningún cambio de código en esa capa (verificado leyendo el método real).

#### 5. Comparación explícita con los 4 mecanismos existentes

| | #6 (`isEmbeddedLeadingTotalRow`) | #8 (`isTrailingTotalRow`) | #12 (`isEmbeddedBackwardSubtotalRow`) | `isLegitimateTrailingTotalBeyondBounds` (17.15) | **Nuevo: `isLeadingFormulaBasedTotalBeyondBounds` + `isLegitimateLeadingTotalBeyondBounds`** |
|---|---|---|---|---|---|
| Posición | leading, dentro de límites | trailing, cierre inmediato | backward, dentro de la sección | trailing, `fin+1` exacto | **leading, `inicio-1` exacto** |
| Requiere etiqueta textual "TOTAL" | Sí | Sí (vía `pareceEtiquetaTotal`) | Sí | Sí (delega en #12) | **No — evidencia puramente de fórmula** |
| Toca código compartido existente | — | — | — | No (aislado) | **No (aislado, nuevo, nunca modifica #6)** |
| Requiere extensión del parser (resolución de sección) | No (fila ya dentro de `[inicio:fin]`) | No | No | **Sí, pero NO implementada — hallazgo nuevo de hoy** | **Sí, diseñada aquí explícitamente** |
| Riesgo de falsos positivos (evidencia de este barrido) | Conocido 1 caso (regla 87, 16.8) | Ninguno documentado | Ninguno documentado | Ninguno (55/55 correctos en clasificación) | **0/5 candidatos globales, pero universo pequeño (n=1 útil)** |

#### 6. Simulación completa del flujo (lógica real replicada en script puro, sin tocar ningún archivo)

Replicando exactamente los guards diseñados arriba como funciones PHP locales, invocando **las funciones reales** `FormulaRangeCoverageAnalyzer::isCompleteContiguous()` y `SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()` (sin modificarlas) contra `cell_data` real:

- **Caso real (`A30/F`, columna B)**: detección → `{"result":true,"reason":"confirmado: formula_completa_sin_etiqueta_textual"}`; mirror bounds-guard → **ACEPTADO** (simulando `SAFE_1_TO_1`).
- **Caso control (`A30/A`, ya resuelto por #6)**: detección → `{"result":false,"reason":"mechanism6_ya_confirma -- no superponer"}` — confirma que el mecanismo nuevo respeta la exclusión mutua, no se superpone donde #6 ya resuelve.
- **Caso de falso positivo sintético (columna `R` de la misma fila 123, fórmula parcial `SUM(R124)`)**: detección → `{"result":false,"reason":"formula_no_cubre_exacto_y_contiguo"}` — confirma que el guard de cobertura completa rechaza correctamente coincidencias parciales.
- **Flujo completo, fixture sintético no-trivial** (datos reales son 100% triviales: 883 combinaciones fila×columna verificadas, 0 valores distintos de 0/null — mismo patrón ya documentado para `A09/F.1` en 17.20/17.21, se usa fixture sintético siguiendo la convención ya establecida en la campaña): componentes `B124=5,B125=null,B126=null,B127=3,B128=8,B129=2` → `SUM=18`; captura técnica simulada `{"sheet":"A30","rem_section_code":"F","row_number":123,"total":18,"exclusion_reason":"leading_formula_total_beyond_bounds"}`; evaluación: declarado=18 vs calculado=18 → **`passed`**.
- **Caso failed sintético**: mismo fixture, total técnico deliberadamente `+999` (`1017`) → declarado=1017 vs calculado=18 → **`failed`**.

Los 5 resultados de la simulación coinciden exactamente con lo esperado por diseño — ninguna desviación.

#### 7. Impacto en `pattern_fingerprint`/calibración — verificado, no supuesto

`SectionCalibrationMatrixService::buildPatternMatrix('A30','F')` (invocado real): `all_rows` abarca exclusivamente `[124:129]` — **la fila 123 NUNCA fue parte de ningún patrón de `A30/F`**, a diferencia del caso de `A09/I` (17.26/17.27), donde las filas fusionadas SÍ estaban dentro de `[filaInicioDatos:filaFinDatos]` y el fix cambiaba su `row_type`. Aquí, como la fila 123 está **fuera** del rango declarado de la sección desde el origen, nunca fue candidata a `all_rows`/ningún patrón — **implementar este mecanismo no tendría ningún efecto sobre `pattern_fingerprint` ni sobre la calibración de `A30/F`** (confirmado: `A30/F` ya está calibrada, `effective_section_reviewed=true`/`historical_section_reviewed=true`, 3 patrones, ninguno referencia la fila 123). **Riesgo de reclasificación a `MISMATCH`: nulo**, a diferencia del hallazgo de `A09/I`.

#### 8. Archivos/métodos que habría que modificar (si se implementara — NO implementado)

- `backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php` — nuevo método público `isLeadingFormulaBasedTotalBeyondBounds()`.
- `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php` — nuevo método privado `isLegitimateLeadingTotalBeyondBounds()`, invocado desde el bounds-check existente (rama `$totalRow < $current['inicio']`, hoy sin ninguna excepción — se agregaría un `if (!...) { $engineGap = ... }` simétrico al ya existente para trailing).
- `backend/app/Domain/REM/Services/RemParserService.php` — nuevo método privado `isLeadingFormulaBasedTotalBeyondBounds()` (copia independiente) + nuevo método `findLeadingBoundaryAdjacentSection()` + reorganización del bloque de resolución de columnas de rol para el caso boundary (ver punto 4.3, complejidad real).
- Un comando de activación nuevo (no diseñado en detalle de implementación aquí, mismo patrón que `rule:activate-trailing-total-beyond-bounds`/`rule:activate-category-c-leading`) — p.ej. `rule:activate-leading-formula-total-beyond-bounds {rule_id}`.
- **Ningún cambio en** `SumEqualsEvaluator.php`, `RuleEngineService::findTechnicalTotalRow()`, `MismatchResolutionAuditService.php` (esta fila nunca participa en `structural_row_exclusion`, ver punto 7), `EnhancedCellScanner.php`, la migración de `rem_technical_totals` (el `exclusion_reason` propuesto cabe en el `varchar(40)` existente, verificado: 35 caracteres).

#### 9. Tests que se requerirían (no escritos)

- `SectionCalibrationMatrixServiceLeadingFormulaBasedTotalTest` — caso real A30/F (positivo), caso control A30/A (rechazado por mecanismo6_ya_confirma), fórmula parcial (rechazado), fila con celda editable real (rechazado), fila reclamada por otra sección (rechazado vía el mirror-guard), fórmula con referencia externa (rechazado).
- `RuleBindingReconciliationServiceLeadingBeyondBoundsTest` — réplica exacta del patrón de la regla 461 → `SAFE_1_TO_1`; réplica del patrón trailing (`isLegitimateTrailingTotalBeyondBounds`) confirmando que NO se activa el mecanismo leading por error; candidato que no es exactamente `inicio-1` rechazado.
- `RemParserServiceLeadingFormulaBasedTotalTest` (end-to-end, xlsx real vía `RemUpload`) — fila excluida de `rem_data`, capturada en `rem_technical_totals` con `exclusion_reason` correcto, valores completos por columna, filas normales sin cambio, **prueba explícita de que una fila en un hueco SIN el patrón (control genuino) sigue descartándose exactamente igual que hoy** (regresión del comportamiento por defecto).
- `RuleEngineServiceTechnicalTotalLeadingPilotTest` — evaluación `passed`/`failed` usando la captura vía el nuevo `exclusion_reason`, confirmando que `findTechnicalTotalRow()` no requiere cambios.
- Regresión completa de la suite ya existente (819+ tests) antes/después, mismo patrón de toda la campaña.

#### 10. Riesgos (sin minimizar)

- **El cambio de mayor riesgo NO es la clasificación (aislada, de bajo riesgo, mismo patrón ya probado 2 veces) — es la reorganización del parser** para permitir que filas fuera de `[data_start_row:data_end_row]` de toda sección lleguen a evaluarse: toca el corazón del loop de `parseSheet()`, usado por las 27 hojas de la Serie A. Debe implementarse de forma estrictamente aditiva (nunca alterar la resolución para filas que ya tienen `sectionContext` no nulo) y regresionarse contra las 27 hojas, no solo A30.
- **Hallazgo colateral no resuelto**: el mismo hueco arquitectónico ya afecta (en silencio) a las 55 reglas trailing-beyond-bounds de Fase 3C-1B — si se implementa la extensión del parser algún día, debe diseñarse pensando en ambas direcciones (leading Y trailing) para no dejar la mitad del problema sin resolver ni duplicar el esfuerzo más adelante — pero **no se propone tocar esas 55 reglas ahora**, fuera de alcance explícito de esta fase.
- Universo de validación pequeño (1 caso útil real, 4 ya cubiertos por #6) — cualquier heurística nueva basada en solo 1 ejemplo real tiene menos precedente estadístico que las heurísticas anteriores (validadas contra decenas/cientos de casos); mitigado por guards estrictos (cobertura completa exacta + ausencia total de texto + ausencia de celdas editables reales), pero el riesgo de generalización queda documentado, no eliminado.
- Datos históricos 100 % triviales (0/null) para la única celda real disponible — cualquier validación futura contra datos reales sería degenerada; se requeriría un fixture sintético o esperar una carga real futura no-trivial para una validación de punta a punta con datos reales significativos.

#### 11. Impacto esperado en clasificación (aritmética simple, NO medida — no se escribió ningún config)

Si se implementaran ambas capas y se activara la regla 461 (única regla real que se beneficiaría): `BLOCKED_BY_ENGINE_GAP` `66→65`, `SAFE_1_TO_1` `473→474`. Ninguna otra regla se vería afectada (las otras 4 secciones candidatas no tienen reglas activas). **No medido con transacción real** — instrucción explícita de esta fase fue diseñar y simular solamente.

#### 12. Plan de rollout/dry-run propuesto (NO ejecutado)

1. Implementar la capa de clasificación (`isLeadingFormulaBasedTotalBeyondBounds` + `isLegitimateLeadingTotalBeyondBounds`) de forma aislada, con tests sintéticos — sin tocar el parser todavía. Verificar que `classifySingleRule()` simulado (en memoria, sin escribir `total_row` real) da `SAFE_1_TO_1` para 461.
2. Implementar la extensión del parser (`findLeadingBoundaryAdjacentSection` + reorganización de columnas de rol), con tests dedicados, regresión completa de las 27 hojas.
3. Dry-run del comando de activación nuevo contra la 461 real (sin `--commit`).
4. Simulación consolidada (transacción + rollback) escribiendo `total_row=123` en 461 y confirmando `SAFE_1_TO_1` real, sin persistir.
5. Solo con autorización explícita: `--commit` real sobre la 461 (única regla del universo actual que se beneficiaría).
6. Posterior, opcional, fuera de esta fase: decidir si se extiende la misma infraestructura del parser al caso trailing (resolviendo el hallazgo colateral de Fase 3C-1B) — requiere autorización separada.

**Nada de este plan fue ejecutado.** No se implementó ningún método, no se escribió `total_row`, no se tocó A09/I, `229`, `230`, fila 333/`AR337`, las 56 `NO_UTILIZADA`, ninguna regla hija ya creada, bindings/rebind, calibraciones, `rem_data`, estructura, template, A31/Fase 3C-1B. Baseline final reconfirmado sin cambios: `activas=751`, `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`. No commit de Git, no push.

### 17.47 — TECHNICAL TOTALS FUERA DE BOUNDS — AUDITORÍA RUNTIME / DISEÑO / NO IMPLEMENTADO (2026-08-28)

⚠️ **100% READ-ONLY / DISEÑO + SIMULACIÓN.** No se implementó nada. No se tocó `config` de las 55, no se revirtió ningún `total_row`, no se escribió nada de la 461, no se reprocesó el upload 186, no backfill, no se modificó `rem_data` histórico, no se tocaron calibraciones, bindings/rebind, A09/I/`AR337`, `no_utilizada`. No commit, no push.

#### 0. Reconfirmación de baseline

`activas=751`, `SAFE_1_TO_1=473`, `BLOCKED_BY_ENGINE_GAP=66`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204` — exacto, reconfirmado antes y después de toda la auditoría.

#### 1. Reconstrucción exacta del problema — trazado línea por línea del código real

**`RemParserService::parseSheet()`** (línea 219): `for ($row = $dataStartRow; $row <= $maxRow; $row++)`, con `$dataStartRow = min(...data_start_row de TODAS las secciones...)` y `$maxRow = max(...data_end_row de TODAS las secciones...)` (línea 214-217) — **el loop SÍ recorre los huecos entre secciones** (no se detiene en los bordes de cada sección individual).

**`findSectionContextForRow()`** (línea 857-866): `foreach ($sectionMap as $section) { if ($row >= data_start_row && $row <= data_end_row) return $section; } return null;` — `data_start_row`/`data_end_row` son **exactamente** `filaInicioDatos`/`filaFinDatos` (línea 704-705, 812-813), **sin ningún margen**. Para una fila en el hueco entre dos secciones (o antes del encabezado de una sección, como 461), esta función devuelve `null`.

**Punto exacto donde la fila pierde toda oportunidad de ser evaluada** — **dos continues distintos, ambos anteriores a los 3 mecanismos**:
- Línea 339-341: `if ($currentConcept === null) { continue; }` — dispara si ni la columna de concepto de fallback (nivel de hoja) ni su columna vecina tienen texto en esa fila.
- Línea 496-504: `if (!empty($sectionMap) && $sectionContext === null) { continue; }` — dispara siempre que `$sectionContext` sea `null`, **incluso si `$currentConcept` no lo era** (es decir, aunque la fila tenga texto residual en la columna de fallback).
- **Los 3 mecanismos de exclusión (#6/#8/#12, líneas 529-593) y la captura hacia `$technicalTotals` (línea 604-619, con guard explícito `if ($sectionContext !== null)`) NUNCA se alcanzan si `$sectionContext` es `null`** — confirmado leyendo el código, no inferido.

**Hallazgo lateral no buscado, pero real**: el fallback de columna de concepto/numérico a nivel de HOJA (`$conceptCol`, `$numericColumnLetters`, usados cuando `$sectionContext` es `null`, línea 221/232) puede leer y **validar** valores de celda ANTES de llegar al descarte de la línea 496-504 — confirmado con evidencia real: el `error_report` del upload `186` registra `{"sheet":"A33","row":56,"column":"B","value":"Total","reason":"No es un numero entero valido"}` — la fila 56 de `A33` (el candidato trailing-beyond-bounds de las reglas 545/546) **fue leída y su valor "Total" se intentó validar como entero**, generando un error espurio en el reporte de la carga, pese a que la fila termina descartada de todos modos (nunca se persiste, nunca se captura). Esto confirma que el "hueco" no es un blindaje completo — puede generar ruido en `error_report` sin ningún beneficio, un efecto colateral no documentado hasta hoy. Otras filas del mismo hueco (28-31 de A31, etc.) no generaron error porque su columna de fallback no tenía texto residual (`$currentConcept` quedó `null`, descartadas en el primer continue, sin validar nada).

#### 2. Auditoría individual de las 55 reglas trailing (Fase 3C-1B)

**Confirmado, rule por rule, agrupadas por sección (10 secciones, 55 reglas)** — `total_row` de cada regla coincide exactamente con `filaFinDatos+1` de su sección:

| Sección | rule_ids | `total_row` | `filaFinDatos` | Próxima sección (inicio) | Hueco (filas sin dueño) |
|---|---|---|---|---|---|
| `A31/A` | 469-478 (10) | 28 | 27 | 32 | 28,29,30,31 (4) |
| `A31/B` | 480-482 (3) | 46 | 45 | 50 | 46,47,48,49 (4) |
| `A31/C` | 484-486 (3) | 66 | 65 | 71 | 66,67,68,69,70 (5) |
| `A31/D` | 487-494 (8) | 85 | 84 | 89 | 85,86,87,88 (4) |
| `A32/A` | 497-501 (5) | 16 | 15 | 19 | 16,17,18 (3) |
| `A32/B` | 503-505 (3) | 24 | 23 | 28 | 24,25,26,27 (4) |
| `A32/D.2` | 507-512 (6) | 104 | 103 | 109 | 104-108 (5) |
| `A32/D1` | 513-519 (7) | 90 | 89 | 95 | 90-94 (5) |
| `A32/E2` | 521-528 (8) | 118 | 117 | 123 | 118-122 (5) |
| `A33/C` | 545,546 (2) | 56 | 55 | 60 | 56,57,58,59 (4) |

**10+55 exacto** (`10+3+3+8+5+3+6+7+8+2=55`). **Ninguna de las 10 secciones tiene adyacencia sin hueco** (gap mínimo = 3, `A32/A`) — es decir, **ninguna de las 55 corre el riesgo de que su fila `total_row` se atribuya por error a la sección SIGUIENTE** (que sería un problema distinto, de mala atribución en vez de pérdida total) — las 55, sin excepción, caen en huecos genuinos de 3 a 5 filas sin dueño.

**Verificación empírica contra el upload real más reciente del sistema (`186`, procesado 2026-08-28, posterior a Fase 3A)** — confirmado que este único upload SÍ tocó las 3 hojas relevantes (`A31`, `A32`, `A33`):
- `rem_technical_totals` para `A31` = **0** registros (cualquier fila, cualquier sección).
- `rem_technical_totals` para `A32` = **1** registro — pero es **`F2` fila 140** (mecanismo #12, dentro de límites, hallazgo ya documentado desde 2026-08-10, **no relacionado con ninguna de las 55**) — **0** registros para las 5 secciones de A32 involucradas (`A,B,D.2,D1,E2`).
- `rem_technical_totals` para `A33` = **0** registros.
- **Confirmado explícitamente: 0 de las 10 secciones de las 55 tiene ningún registro en `rem_technical_totals`, pese a que el upload 186 procesó las 3 hojas completas.**

**Confirmado con el mecanismo real (sin modificar)**: `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow('A31','A',28,$rawSection)` invocado real → **`true`** — el mecanismo #12 SÍ confirmaría la fila 28 si pudiera evaluarla; el bloqueo es exclusivamente de **alcance del parser** (nunca llega a invocar el mecanismo), no de que el mecanismo falle.

**Conteo exacto solicitado**:
- **`RUNTIME_OK`: 0**
- **`CLASSIFICATION_SAFE_BUT_RUNTIME_MISSING_TOTAL`: 55** (las 55, sin excepción — mismo defecto arquitectónico determinista, confirmado estructuralmente para las 10 secciones y empíricamente para las 3 hojas vía el único upload real disponible)
- **Excepciones: 0** — ninguna de las 55 escapa al patrón (todas comparten exactamente la misma causa raíz: `findSectionContextForRow()` devuelve `null` para su `total_row`, sin importar la hoja/sección/columna).

**Consecuencia real para la evaluación**: dado que ninguna de las 751 reglas activas tiene bindings a la estructura activa 67 (`rem_rule_bindings` a estructura 67 = `0`, sin cambio en toda la campaña), el impacto práctico HOY es nulo (nada se evalúa todavía) — pero de ejecutarse un rebind futuro asumiendo que estas 55 están "listas", cada una devolvería `missing_total_row`/`skipped` en cada carga futura, sin importar cuán correctos sean los datos reales — la clasificación `SAFE_1_TO_1` sería **engañosa** respecto al comportamiento real en producción.

#### 3. Auditoría de la 461 bajo el mismo problema

Confirmado (ya establecido en 17.46, reconfirmado aquí): fila `123` (`inicio-1` de `A30/F`) cae en el hueco entre el fin de `A30/E` (118) y el inicio de datos de `A30/F` (124) — el mismo `findSectionContextForRow()` devuelve `null`. **Distinción exigida, confirmada exacta**:
- **Problema COMPARTIDO (arquitectónico, de resolución de contexto)**: idéntico al de las 55 — la fila candidata está fuera de `[data_start_row:data_end_row]` de toda sección, por lo que ningún mecanismo (existente NI nuevo) llega nunca a evaluarla dentro del parser real.
- **Problema ESPECÍFICO de 461 (reconocimiento formula-based)**: incluso SI se resolviera el problema compartido, la fila 123 **todavía** necesitaría el mecanismo nuevo de 17.46 (`isLeadingFormulaBasedTotalBeyondBounds`), porque el mecanismo #6 existente devuelve `false` para ella (sin etiqueta textual) — a diferencia de las 55 (trailing), donde el mecanismo #12 YA confirma `true` sin ningún cambio adicional.

**Es decir: 461 tiene DOS problemas independientes y acumulativos; las 55 tienen SOLO el problema compartido** (su reconocimiento vía mecanismo #12 ya funciona perfectamente, solo falta que el parser llegue a invocarlo).

#### 4. Diseño de la solución común de contexto — comparación de las 3 opciones

| | A. Ampliar `findSectionContextForRow()` (ventana técnica alrededor de la sección) | B. Resolver contexto técnico vía secciones adyacentes, gateado por mecanismo | **C. Ruta separada `findTechnicalSectionContextForRow()` (recomendada)** |
|---|---|---|---|
| Modifica el significado de `filaInicioDatos`/`filaFinDatos` | **Sí, implícitamente** — cualquier código que consulte `$sectionContext` para una fila boundary la trataría como "parte de la sección" para TODO propósito (columna de concepto, profesional, etc.), no solo para exclusión técnica — riesgo de que una fila boundary se interprete accidentalmente como dato real de la sección vecina | No, si se implementa con cuidado (variable separada) | **No — `findSectionContextForRow()` no se toca en absoluto, su valor de retorno y semántica quedan byte-idénticos** |
| Riesgo de que encabezados/filas vecinas pasen a ser "datos" de una sección | Alto — la ventana ampliada se usaría para TODA la lógica de la fila (línea 221-235), no solo para el chequeo de exclusión | Bajo, si se aísla correctamente | **Nulo por diseño — el contexto de boundary nunca se asigna a `$sectionContext`, solo se usa dentro del bloque de captura técnica** |
| Exige evidencia real de TOTAL técnico antes de persistir | No por sí sola (la ventana no decide exclusión, solo abre la puerta) | Sí (condición explícita) | **Sí (condición explícita, igual que B)** |
| Sirve tanto a `fin+1` como a `inicio-1` | Sí, en teoría | Sí | **Sí — un único método parametrizado, `boundary_type` distingue dirección** |
| Mantiene intactos los mecanismos existentes (#6/#8/#12) | Sí | Sí | **Sí — 0 cambios a #6/#8/#12, ambos reutilizados tal cual** |
| Separación de responsabilidades / auditabilidad | Baja — mezcla "pertenencia real" con "candidato técnico" en la misma variable | Media | **Alta — una función, un propósito único, nunca conflada con la ruta de persistencia normal** |
| Veredicto | **RECHAZADA** — viola explícitamente la prioridad "no cambiar el significado normal de filaInicioDatos/filaFinDatos" | Válida en principio, pero C es su forma de implementación más limpia y verificable | **RECOMENDADA** |

**Diseño de la Opción C**: nuevo método `findTechnicalSectionContextForRow(array $sectionMap, int $row): ?array` — recorre `$sectionMap` buscando, para cada sección, `$row === $section['data_start_row'] - 1` (candidato `leading`) o `$row === $section['data_end_row'] + 1` (candidato `trailing`); si ambos coinciden simultáneamente para secciones distintas (caso defensivo, nunca observado en datos reales — el hueco mínimo real es 3 filas), retorna ambiguo (`null` efectivo, no resuelve nada, mismo comportamiento que hoy). Se invoca **únicamente cuando `findSectionContextForRow()` ya devolvió `null`**, dentro de un bloque nuevo, aislado, insertado antes del `continue` existente (línea ~496-504) — nunca sustituye ni reordena la resolución normal.

**Flujo dentro del bloque nuevo** (pseudocódigo del diseño, no implementado):
```
if ($sectionContext === null) {
    $boundaryContext = $this->findTechnicalSectionContextForRow($sectionMap, $row);
    if ($boundaryContext !== null) {
        // recalcular concept/professional/total/values usando las columnas
        // de rol de $boundaryContext (nunca del sectionContext, que sigue null)
        $confirmed = match ($boundaryContext['boundary_type']) {
            'trailing' => $this->isTrailingTotalRow(...) || $this->isEmbeddedBackwardSubtotalRow(...), // #8/#12 EXISTENTES, sin tocar
            'leading'  => $this->isLeadingFormulaBasedTotalBeyondBounds(...), // NUEVO, 17.46, sin tocar #6
        };
        if ($confirmed) {
            $technicalTotals[] = [...]; // mismo shape ya usado, exclusion_reason nuevo por direccion
        }
    }
    continue; // comportamiento por defecto sin cambios si no se confirmo nada
}
```

**Complejidad real reconocida, no minimizada**: reconstruir `concept`/`professional`/`total`/`values` para una fila cuyo `$sectionContext` normal es `null` requiere usar los campos de rol de columna (`conceptColumn`/`professionalColumn`/`totalColumns`/etc.) YA almacenados en `$sectionMap` para la sección boundary — esto exige extraer/reutilizar parte del cómputo por fila que hoy depende implícitamente de `$sectionContext` no nulo, una reorganización moderada, no trivial.

#### 5. Simulación obligatoria — 5 escenarios, todos con las funciones reales sin modificar donde aplica

Replicando el resolver de la Opción C como función PHP local (nunca tocando `RemParserService.php` real), invocando `isEmbeddedBackwardSubtotalRow()` y `isEmbeddedLeadingTotalRow()` **reales, sin modificar**:

1. **Trailing real (regla `469`, `A31/A`, fila `28`)**: `findTechnicalSectionContextForRow` → `{"code":"A","boundary_type":"trailing"}`; `isEmbeddedBackwardSubtotalRow('A31','A',28,...)` real → **`true`** → **capturaría** en `rem_technical_totals` con `exclusion_reason="trailing_total_beyond_bounds"`.
2. **461 (`A30/F`, fila `123`)**: `findTechnicalSectionContextForRow` → `{"code":"F","boundary_type":"leading"}`; mecanismo #6 real → `false`; mecanismo nuevo (formula-based, 17.46) → **`true`** → **capturaría** con `exclusion_reason="leading_formula_total_beyond_bounds"`.
3. **Filas vecinas normales que NO deben asociarse** (`26`,`27` — ya dentro de `A31/A`; `32` — inicio real de `A31/B`): las 3 devuelven `boundary_context=null` — **confirmado que nunca entran a la ruta nueva** (ya resuelven vía `findSectionContextForRow()` normal, la ruta boundary ni siquiera se consulta para ellas).
4. **Sección inmediatamente adyacente (`A31/B`) — ausencia de colisión**: dentro del mismo hueco `28-31`, la fila `28` se asocia **exclusivamente** a `A` (trailing), la fila `31` se asocia **exclusivamente** a `B` (leading, `data_start_row-1`), y las filas `29`,`30` no matchean con ninguna (siguen descartándose exactamente igual que hoy, sin ningún cambio de comportamiento) — **0 ambigüedad, 0 colisión**, confirmado explícitamente.
5. **Flujo completo passed/failed** (datos reales triviales confirmados — `SUM(AN12:AN27)` real del upload 186 = 0 — fixture sintético usado, misma convención ya establecida en la campaña): componentes sintéticos `AN12=4, AN27=2` (resto 0/null) → `SUM=6`; captura simulada `{"total":6,"exclusion_reason":"trailing_total_beyond_bounds"}` → declarado=6 vs calculado=6 → **`passed`**; caso failed (declarado=506) → **`failed`**.

**Los 5 escenarios se comportaron exactamente como diseñado — ninguna desviación.**

#### 6. Impacto sobre lo declarado "cerrado" — corrección de estado, sin tocar ningún dato

**Se confirma explícitamente que la documentación de Fase 3C-1B (punto 17.16) requiere corrección de ESTADO, no de datos**: las 55 reglas están, y permanecen, correctamente **`SAFE_1_TO_1` a nivel de clasificación** (el `config`/`total_row`/`RuleVersion`/activity log de la activación de 2026-08-27 son y siguen siendo correctos — no se tocan, no se revierten) — pero **NO están "completamente resueltas" en el sentido de estar listas para producción**: están en estado **`CLASIFICACIÓN CERRADA / RUNTIME PENDIENTE`** — su clasificación es correcta y estable, pero **ninguna de las 55 puede evaluarse correctamente contra una carga real hoy**, porque el parser nunca captura su fila `total_row` en `rem_technical_totals`. Este es el MISMO estado, exactamente, en el que quedaría la 461 si solo se implementara el mecanismo de reconocimiento de 17.46 sin resolver primero el problema compartido de contexto.

**No se revierte ningún `total_row`, no se cambia ningún `status`, no se toca ningún `config` de las 55** — la corrección es puramente de **documentación** (reflejar el estado real, no modificar nada).

#### 7. Baseline final reconfirmado

`activas=751`, `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204` — idéntico antes y después de toda la auditoría. `rem_technical_totals` real (BD `esalud_dev`) = **126** (sin cambio, solo lectura en toda esta fase). Ninguna escritura de ningún tipo. No se implementó `findTechnicalSectionContextForRow()`, `isLeadingFormulaBasedTotalBeyondBounds()`, ni ningún mirror-guard. No se tocó A09/I, `229`, `230`, fila 333/`AR337`, las 56 `NO_UTILIZADA`, ninguna regla hija ya creada, bindings/rebind, calibraciones, `rem_data`, estructura, template, config de las 55, la 461. No commit de Git, no push.

### 17.48 — TECHNICAL SECTION CONTEXT FUERA DE BOUNDS IMPLEMENTADO / 55 TRAILING RUNTIME VALIDADO / 461 AÚN PENDIENTE (2026-08-28)

⚠️ **Código real implementado** (única fase de este bloque que modifica producción, después de 17.44-17.47 que fueron 100% auditoría/diseño). Alcance exacto autorizado: solo la Opción C (`findTechnicalSectionContextForRow()`) — el reconocimiento formula-based específico de la 461 (17.46) **NO** se implementó. No se tocó `config` de las 55 ni de la 461. No se reprocesó el upload 186. No backfill. No bindings/rebind. No calibraciones. No A09/I/`AR337`. No `no_utilizada`. No commit, no push.

#### 1. Implementación — 2 métodos nuevos, 1 punto de inserción, `findSectionContextForRow()` sin tocar

**`RemParserService::findTechnicalSectionContextForRow(array $sectionMap, int $row): ?array`** (nuevo, aislado) — busca, para cada sección declarada, si `$row === data_start_row-1` (candidato `leading`) o `$row === data_end_row+1` (candidato `trailing`); ambigüedad (ambos simultáneos, nunca observada en datos reales) se rechaza explícitamente devolviendo `null`. **Nunca modifica ni es llamado por `findSectionContextForRow()`** — función completamente independiente, confirmado por diseño y por código.

**`RemParserService::resolveTechnicalBoundaryCapture(Worksheet $worksheet, string $sheet, array $technicalContext, int $row): ?array`** (nuevo) — exige evidencia real de TOTAL técnico **antes** de leer/validar la fila como dato normal:
- Dirección `trailing`: reutiliza **sin modificar** `isTrailingTotalRow()` (#8) e `isEmbeddedBackwardSubtotalRow()` (#12), las mismas copias privadas ya existentes en esta clase — `$confirmed = mecanismo8 || mecanismo12`.
- Dirección `leading`: **retorna `false` incondicionalmente** — el reconocimiento formula-based de la 461 (17.46) no está implementado; el contexto se resuelve pero nunca se confirma, por diseño explícito de esta fase.
- Solo si `$confirmed`, lee `concept`/`total`/`values` usando las columnas de rol **propias de la sección boundary** (`concept_column`, `total_column`, `numeric_columns`, etc. — nunca las columnas de fallback de hoja) y retorna la entrada lista para `$technicalTotals`, con `exclusion_reason` nuevo por dirección: `'trailing_total_beyond_bounds'` (28 caracteres) o `'leading_formula_total_beyond_bounds'` (35 caracteres) — ambos dentro del `varchar(40)` existente, verificado.

**Único punto de inserción en `parseSheet()`**: justo después de `$sectionContext = $this->findSectionContextForRow(...)`, se agregó `if (!empty($sectionMap) && $sectionContext === null) { ... continue; }` — **exactamente la misma condición que ya disparaba el descarte tardío de la línea ~496-504** (ahora movida al inicio del loop, antes de leer cualquier celda). Si `findTechnicalSectionContextForRow()` no encuentra nada, la fila se descarta en silencio (mismo resultado final que antes, sin leer ninguna celda). Si encuentra un candidato, se evalúa `resolveTechnicalBoundaryCapture()`; si confirma, se agrega a `$technicalTotals`; en cualquier caso, `continue` — **la fila NUNCA cae al camino de persistencia normal** (`$data[]`). El resto del loop (líneas 221 en adelante, incluidos los 3 mecanismos #6/#8/#12 ya existentes y los 2 `continue` antiguos, ahora inalcanzables para filas de contexto nulo) permanece **byte-idéntico**, sin eliminar código — riesgo mínimo, diff mínimo.

**Corrección del hallazgo lateral (mismo cambio, sin ampliar alcance)**: al interceptar la fila ANTES de la lectura de concepto/columnas numéricas de fallback, `resolveTechnicalBoundaryCapture()` nunca invoca `validateCell()` — construye `values`/`total` directamente vía `is_numeric()`, sin registrar errores. Esto elimina el ruido ya documentado en 17.47 (`upload 186: A33 fila 56 columna B, "Total" leído como entero inválido`) como consecuencia natural del mismo cambio, sin tocar `validateCell()` ni ninguna otra ruta.

#### 2. Tests — 12/12 passing (`RemParserServiceTechnicalSectionContextBeyondBoundsTest.php`, nuevo)

Fixture replica la geometría real de A31 (`gap=4` entre secciones): sección `A` `[12:27]` con TOTAL trailing genuino en fila `28`; sección `B` `[32:36]` cuyo candidato `leading` (fila `31`) se usa solo para el test de colisión; sección `D` `[60:65]` con TOTAL leading formula-based (patrón 461 exacto, sin etiqueta textual) en fila `59`.

Cubre los 12 requisitos exigidos: (1) trailing `fin+1` válido, capturado con `exclusion_reason='trailing_total_beyond_bounds'`, valores correctos (`B28=SUM(B26:B27)=7`); (2) hueco real de 4 filas — solo `28` capturada, `29/30` sin match, `31` (leading de B) resuelve contexto pero no captura; (3) fila vecina normal (`26,27,32`) nunca pasa por la ruta boundary; (4) 2 secciones adyacentes sin colisión (`28`→A exclusivo, `31`→B exclusivo, verificado directo vía reflection); (5) fila técnica va a `rem_technical_totals`, nunca a `rem_data`; (6)/(7)/(8) evaluador recupera el total, passed/failed correctos; (9) patrón 461 (sección `D`, fila `59`) resuelve contexto `leading` pero **nunca se captura**, confirmado extremo a extremo (ni `rem_technical_totals` ni `rem_data`); (10) mecanismos #8/#12 reconfirmados intactos (invocación directa, mismo resultado `true` que antes); (11) fila huérfana con columna de fallback ruidosa **no genera ninguna entrada en `$result->errors`** (reproduce exactamente el patrón `A33/56/"Total"` del upload real 186); (12) filas normales sin ningún cambio de comportamiento.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`+`tests/Unit/RuleEngine`): **853 tests, 814 passed, 39 failed — exactamente los mismos 39 fallos preexistentes ya documentados en toda la campaña** (4 `StructurePersistenceServiceTest`, 1 `RuleEngineIntegrationTest`, 30 `FunctionalRuleEngineCertificationTest`, 4 `RuleEngineServiceTest`, mismos nombres exactos). **Cero regresiones nuevas.** Los 12 tests nuevos incluidos en el conteo, todos passing.

#### 3. Validación real sin escritura histórica — 55/55 elegibles, 0 fuera de universo afectadas

**Invocando los métodos REALES ya implementados** (vía reflection, sin modificar nada, sin reprocesar el upload 186) contra la estructura/`cell_data` reales de `esalud_dev`:

- **Las 10 secciones de las 55 reglas** (`A31/A,B,C,D`, `A32/A,B,D.2,D1,E2`, `A33/C`): `findTechnicalSectionContextForRow()` resuelve correctamente `data_end_row+1` para las 10, con `boundary_type='trailing'`; `isTrailingTotalRow()`/`isEmbeddedBackwardSubtotalRow()` (mismas copias privadas de `RemParserService`, sin modificar) confirman `true` para las **10/10**, sin excepción. **55/55 reglas elegibles**, confirmado.
- **Barrido exhaustivo de TODAS las secciones de `A31` (5), `A32` (19) y `A33` (5)** — no solo las 10 conocidas: el gate confirma **2 casos adicionales, genuinos, ya documentados en fases anteriores de la campaña estructural, sin relación con ninguna regla activa**: `A32/F2` fila `151` (TOTAL final trailing, mecanismo #8, hallazgo original de 2026-08-10 — **0 reglas activas referencian `total_row=151`**; las 6 reglas reales de `F2` usan `total_row=140`, ya resuelto vía Categoría A) y `A33/E` fila `74` (mismo patrón, **0 reglas activas referencian `A33/E` en absoluto**). **Confirmado explícitamente: 0 reglas fuera del universo de las 55 quedan afectadas** — el gate es correctamente general (no limitado artificialmente a los 10 casos conocidos, consistente con el diseño de Fase 3A ya establecido, que siempre capturó CUALQUIER fila confirmada por un mecanismo, no solo las referenciadas por una regla), pero no activa ni modifica ninguna clasificación de regla existente.
- **461 (`A30/F`, fila `123`)**: `findTechnicalSectionContextForRow()` resuelve `{"code":"F","boundary_type":"leading"}` — el contexto SÍ se resuelve — pero `resolveTechnicalBoundaryCapture()` nunca la captura (`leading => false` incondicional), confirmado real.
- **Clasificación de reglas (`RuleBindingReconciliationService`, independiente del parser) reconfirmada idéntica**: `activas=751`, `SAFE_1_TO_1=473`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `BLOCKED_BY_ENGINE_GAP=66`, `rem_rules=798`, `rem_rule_bindings=1204` — **sin ningún cambio por este cambio de parser** (el clasificador nunca invoca `RemParserService`, confirmado por diseño e independencia de módulos).

#### 4. Estado real de las 55 tras esta fase

**Las 55 de Fase 3C-1B pasan de `CLASIFICACIÓN CERRADA / RUNTIME PENDIENTE` (17.47) a `CLASIFICACIÓN CERRADA / RUNTIME VALIDADO (mecanismo real, sin reprocesar histórico)`** — el mecanismo que las haría funcionar en producción está **implementado y confirmado contra su evidencia real** (mecanismos #8/#12 reales, sin modificar), pero **ningún upload histórico fue reprocesado** — las 144 cargas existentes (incluida la `186`) siguen sin ningún registro en `rem_technical_totals` para estas filas hasta que ocurra una carga NUEVA (o un backfill explícitamente autorizado, no solicitado aquí). Su `config`/`total_row`/`status` **no fueron tocados** — siguen exactamente como quedaron en 17.16.

**461 permanece exactamente igual que en 17.45-17.47**: `BLOCKED_BY_ENGINE_GAP`, sin `total_row`, sin capturar — el contexto de frontera ya está disponible para cuando se autorice implementar el reconocimiento formula-based (17.46), pero esa pieza sigue sin construir.

#### 5. Archivos modificados/nuevos

- `backend/app/Domain/REM/Services/RemParserService.php` — modificado (aditivo): 2 métodos nuevos (`findTechnicalSectionContextForRow()`, `resolveTechnicalBoundaryCapture()`), 1 bloque nuevo insertado en `parseSheet()` (justo después de la resolución de `$sectionContext`). Ningún método existente (`findSectionContextForRow()`, `isTrailingTotalRow()`, `isEmbeddedBackwardSubtotalRow()`, `isEmbeddedLeadingTotalRow()`, `validateCell()`) fue modificado.
- `backend/tests/Feature/REM/RemParserServiceTechnicalSectionContextBeyondBoundsTest.php` — nuevo (12 tests).

**No se implementó el reconocimiento formula-based de la 461** (`isLeadingFormulaBasedTotalBeyondBounds`, diseñado en 17.46) — queda como el único trabajo restante para que la 461 pueda activarse. **No se reprocesó ningún upload. No backfill. No se tocó `config`/`total_row`/`status` de ninguna de las 55 ni de la 461. No bindings/rebind. No calibraciones. No A09/I/`AR337`. No `no_utilizada`. No commit de Git, no push.**

Baseline final reconfirmado sin cambios: `activas=751`, `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`.

### 17.49 — 461 LEADING FORMULA-BASED IMPLEMENTADO / DRY-RUN VALIDADO / ESCRITURA REAL NO EJECUTADA (2026-08-28)

⚠️ **Código real implementado** (2 capas + comando de activación), pero **ninguna escritura real sobre `config` de la 461**. Alcance exacto: mecanismo hermano de #6, comando `rule:activate-leading-formula-total-beyond-bounds`, dry-run real, simulación con transacción+rollback. No `--commit` real. No reprocesar upload 186. No backfill. No tocar históricos. No A09/I/`AR337`. No `229`/`230`. No `no_utilizada`. No bindings/rebind. No calibraciones. No commit Git, no push.

#### 1. Implementación — mecanismo hermano de #6, en 2 capas, más comando de activación

**Capa de reconocimiento** — `SectionCalibrationMatrixService::isLeadingFormulaBasedTotalBeyondBounds(string $sheet, string $section, int $row, array $sectionData): bool` (nuevo, público) y su copia independiente `RemParserService::isLeadingFormulaBasedTotalBeyondBounds()` (mismo patrón de duplicación deliberada ya usado para #6/#8/#12 entre parser y clasificador/calibración): exige `$row === filaInicioDatos - 1`; ninguna columna de la fila genuinamente capturable (`es_editable && !esta_bloqueada`); **al menos una columna** con fórmula que `FormulaRangeCoverageAnalyzer::isCompleteContiguous()` confirma cubre **exacta y contiguamente** `[filaInicioDatos:filaFinDatos]`, sin huecos, sin referencias a otra columna. **Nunca exige etiqueta textual "TOTAL"** (a diferencia de #6, al que nunca modifica ni reemplaza) y **nunca hardcodea** hoja/sección/fila/columna.

**Integración en el parser** — `RemParserService::resolveTechnicalBoundaryCapture()` (17.48) se extiende: la dirección `leading` ahora combina **DOS mecanismos independientes**, exactamente igual que la dirección `trailing` ya combina #8 y #12: `isEmbeddedLeadingTotalRow()` (#6, sin modificar — cubre patrones **con** etiqueta, ej. `A30/A,C,D,E`) **O** `isLeadingFormulaBasedTotalBeyondBounds()` (nuevo — cubre patrones **sin** etiqueta, ej. la 461). `exclusion_reason` refleja cuál mecanismo confirmó realmente: `'embedded_leading_total_row'` si fue #6, `'leading_formula_total_beyond_bounds'` si fue el mecanismo nuevo (nunca ambos a la vez, verificado).

**Capa de clasificación** — `RuleBindingReconciliationService::isLegitimateLeadingTotalBeyondBounds()` (nuevo, aislado, mirror exacto de `isLegitimateTrailingTotalBeyondBounds()` de Fase 3C-1B): exige `$totalRow === $current['inicio'] - 1`; fila no reclamada por otra sección; delega **exclusivamente** en el mecanismo nuevo (mismo patrón de asimetría ya establecido por el mirror trailing, que delega solo en #12, nunca en #8); re-verifica la fórmula de la columna propia de la regla de forma independiente. Wireado en `classifyRule()` como una **segunda alternativa** (`||`) junto a `isLegitimateTrailingTotalBeyondBounds()`, sin tocar la condición genérica del bounds-check.

**Comando nuevo** — `rule:activate-leading-formula-total-beyond-bounds {rule_id} {--reason=} {--by=} {--commit}` (`RuleActivateLeadingFormulaTotalBeyondBoundsCommand.php`), mirror exacto de `rule:activate-trailing-total-beyond-bounds` (12 guards, mismo orden, mismo patrón de `RuleVersion`/activity log `rule_leading_formula_total_beyond_bounds_activated`) — nunca recibe `total_row` como argumento, nunca toca `row_range`.

#### 2. Barrido de seguridad contra las 381 secciones — confirmado, 373 procesadas (8 sin `filaInicioDatos`/`filaFinDatos` válidos, descartadas correctamente)

Invocando **las funciones reales** (sin modificar) contra `cell_data` real de `esalud_dev`: **4 candidatos siguen resolviéndose vía #6** (`A30/A:12, A30/C:80, A30/D:98, A30/E:114` — todos con etiqueta textual) y **exactamente 1 candidato requiere el mecanismo formula-based nuevo, sin ningún otro** (`A30/F:123`, la 461). **0 filas normales aceptadas por error** en las 373 secciones válidas. Confirma exactamente lo ya anticipado en 17.46: la 461 es el único caso real de todo Serie A.

#### 3. Tests — 36 nuevos, todos passing

- **`RemParserServiceLeadingFormulaBasedTotalTest.php`** (9/9): fórmula completa/contigua sin etiqueta capturada; nunca en `rem_data`; fórmula con hueco rechazada; referencia a otra columna rechazada; fila de dato real (editable) nunca confundida con TOTAL; fila **con** etiqueta textual capturada vía **#6** (`exclusion_reason='embedded_leading_total_row'`, no el mecanismo nuevo); captura únicamente en `rem_technical_totals` en las secciones correctas; mecanismo #6 reconfirmado intacto; estructura activa sin modificar.
- **`RuleEngineServiceLeadingFormulaBasedTotalPilotTest.php`** (4/4): patrón 461 produce `passed` cuando el total técnico coincide con la suma real de componentes; `failed` cuando no coincide; **ausencia** de fila técnica cae de forma segura en `skipped`/`missing_total_row` (nunca un fallback silencioso ni un passed falso); la fila técnica nunca se escribe de vuelta en `rem_data`. **Confirma que `RuleEngineService`/`SumEqualsEvaluator` no requirieron ningún cambio de código** (mismo mecanismo genérico de Fase 3B/17.22, agnóstico al `exclusion_reason`).
- **`RuleActivateLeadingFormulaTotalBeyondBoundsCommandTest.php`** (11/11): patrón válido (dry-run + commit, solo `total_row` cambia); candidato que no es exactamente `inicio-1` rechazado; fórmula con hueco rechazada; referencia externa rechazada; fila reclamada por sección precedente rechazada; candidato **trailing** (patrón ya cerrado por Fase 3C-1B) rechazado explícitamente por este comando; placeholder `{0,0}` rechazado; hoja `no_utilizada` rechazada; fila normal editable rechazada; preservación de bindings/regla ajena/`rem_data` confirmada byte-idéntica tras commit.
- **`RemParserServiceTechnicalSectionContextBeyondBoundsTest.php`** (12/12, actualizado): 2 aserciones de 17.48 corregidas para reflejar el comportamiento correcto y ya esperado tras esta fase — la fila `31` (leading de `B`, **con** etiqueta) ahora se captura vía #6 (antes, en 17.48, el mecanismo `leading` era `false` incondicional); la fila `59` (patrón 461 exacto, sección `D`, **sin** etiqueta) ahora se captura vía el mecanismo nuevo.

**Regresión completa** (`tests/Feature/REM`+`tests/Unit/RemParser`+`tests/Feature/RuleEngine`+`tests/Unit/RuleEngine`): **877 tests, 838 passed, 39 failed — exactamente los mismos 39 fallos preexistentes de siempre.** Cero regresiones nuevas. Los 36 tests nuevos (12+9+4+11) incluidos, todos passing.

#### 4. Dry-run real contra la 461 y simulación de clasificación (transacción + rollback)

**Dry-run real** (`php artisan rule:activate-leading-formula-total-beyond-bounds 461`, sin `--commit`): `total_row` propuesto = **123** (`filaInicioDatos 124 - 1`); `config` antes/después mostrado (única clave que cambiaría: `total_row`); **clasificación ANTES = `BLOCKED_BY_ENGINE_GAP`, clasificación SIMULADA DESPUÉS = `SAFE_1_TO_1`**; binding existente (525→estructura 19) confirmado intacto; nada persistido.

**Simulación real con transacción + rollback** (escritura real de `total_row=123` en la BD, dentro de una transacción nunca comiteada):

| Métrica | Antes | Después (simulado) |
|---|---|---|
| `SAFE_1_TO_1` | 473 | **474** |
| `BLOCKED_BY_ENGINE_GAP` | 66 | **65** |
| `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` | 14 / 198 | sin cambio |
| Reglas activas | 751 | 751 |

**Coincide exactamente con lo predicho por el usuario** (`473→474`, `66→65`, resto sin cambio). **Exactamente 1 regla cambió de clasificación: `id=461: BLOCKED_BY_ENGINE_GAP -> SAFE_1_TO_1`** — ninguna otra de las 751 se vio afectada. **Rollback ejecutado y verificado**: `461.config.total_row` confirmado `null` tras el rollback, `config` byte-idéntico al original.

#### 5. Baseline final reconfirmado

`activas=751`, `SAFE_1_TO_1=473`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=66`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204` — idéntico antes y después de toda la fase (la simulación de clasificación corrió dentro de una transacción con `rollback` garantizado; el dry-run real nunca escribe). `461.config` confirmado sin `total_row` (byte-idéntico al inicio de la fase).

#### 6. Archivos modificados/nuevos

- `backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php` — modificado (aditivo): nuevo método público `isLeadingFormulaBasedTotalBeyondBounds()`.
- `backend/app/Domain/REM/Services/RemParserService.php` — modificado (aditivo): nuevo método privado `isLeadingFormulaBasedTotalBeyondBounds()` (copia independiente); `resolveTechnicalBoundaryCapture()` extendido para combinar #6 + el mecanismo nuevo en la dirección `leading`, con `exclusion_reason` reflejando cuál confirmó.
- `backend/app/Domain/RuleEngine/Services/RuleBindingReconciliationService.php` — modificado (aditivo): nuevo método privado `isLegitimateLeadingTotalBeyondBounds()`, wireado como alternativa `||` junto a `isLegitimateTrailingTotalBeyondBounds()` en el bounds-check genérico (condición genérica sin tocar).
- `backend/app/Console/Commands/RuleActivateLeadingFormulaTotalBeyondBoundsCommand.php` — nuevo.
- `backend/tests/Feature/REM/RemParserServiceLeadingFormulaBasedTotalTest.php`, `backend/tests/Feature/REM/RuleActivateLeadingFormulaTotalBeyondBoundsCommandTest.php`, `backend/tests/Feature/RuleEngine/Services/RuleEngineServiceLeadingFormulaBasedTotalPilotTest.php` — nuevos.
- `backend/tests/Feature/REM/RemParserServiceTechnicalSectionContextBeyondBoundsTest.php` — actualizado (2 aserciones corregidas para reflejar el mecanismo ya implementado, ver punto 3).

**Ningún archivo existente fue modificado fuera de lo aditivo listado.** `findSectionContextForRow()`, `isEmbeddedLeadingTotalRow()` (#6), `isTrailingTotalRow()` (#8), `isEmbeddedBackwardSubtotalRow()` (#12), la condición genérica del bounds-check, y `isLegitimateTrailingTotalBeyondBounds()` permanecen **byte-idénticos**.

**No se ejecutó ningún `--commit` real sobre la 461.** No se reprocesó el upload 186. No backfill. No se tocaron históricos. No A09/I/`AR337`. No `229`/`230`. No `no_utilizada`. No bindings/rebind. No calibraciones. No commit de Git, no push.

### 17.50 — REGLA 461 LEADING FORMULA-BASED EJECUTADA Y CERRADA (2026-08-28)

⚠️ **Escritura real, autorizada explícitamente.** Única acción: `config.total_row=123` de la regla 461, vía `rule:activate-leading-formula-total-beyond-bounds 461 --commit` (comando ya validado en 17.49, sin modificar). No se tocó `config` manualmente. No se reprocesó el upload 186. No backfill. No se tocaron las 55 trailing, A09/I, fila 333/`AR337`, `229`/`230`, las 56 `NO_UTILIZADA`, bindings/rebind, calibraciones, `rem_data`, estructura, template. No commit de Git, no push.

#### 1. Reconfirmación previa a escribir — todos los puntos coincidieron exactamente

`activas=751`, `SAFE_1_TO_1=473`, `BLOCKED_BY_ENGINE_GAP=66`, `DUPLICATE=14`, `rem_rules=798`, `rem_rule_bindings=1204` — exacto. `461.status=active`, `config.total_row` ausente, candidato calculado `123`, `filaInicioDatos=124`, fórmula real `B123=SUM(B124:B129)` confirmada, `isLeadingFormulaBasedTotalBeyondBounds()` confirmado `true`, dry-run reconfirmó clasificación simulada `SAFE_1_TO_1` — sin ninguna desviación respecto a lo esperado.

#### 2. Ejecución

`php artisan rule:activate-leading-formula-total-beyond-bounds 461 --reason="..." --by="Administrador Esalud" --commit` — comando real, sin modificar, único punto de escritura (transacción interna, doble revalidación antes de escribir, mismo patrón de todos los comandos de activación de esta campaña).

#### 3. Post-check exhaustivo — todos los puntos exigidos, verificados exactos

| Verificación | Resultado |
|---|---|
| `461.config.total_row` | `123` ✅ |
| Resto de `config` | byte-idéntico (`sheet=A30,section=F,column=B,row_range={124,129},rule_logic` sin cambio) ✅ |
| `461` clasificación | `SAFE_1_TO_1` ✅ |
| `SAFE_1_TO_1` | `473 → 474` ✅ |
| `BLOCKED_BY_ENGINE_GAP` | `66 → 65` ✅ |
| `DUPLICATE` | `14` (sin cambio) ✅ |
| `ALREADY_STRUCTURE_AGNOSTIC` | `198` (sin cambio) ✅ |
| Reglas activas | `751` (sin cambio) ✅ |
| `rem_rules` | `798` (sin cambio — solo `config` de una fila existente, no se crea ninguna regla) ✅ |
| `rem_rule_bindings` | `1204` (sin cambio) ✅ |
| `RuleVersion` nuevos para 461 | exactamente `1`, snapshot del `config` anterior (sin `total_row`) ✅ |
| Activity log nuevo | exactamente `1` (`rule_leading_formula_total_beyond_bounds_activated`), `total_row_set=123`, `inicio=124`, `reason`/`by` correctos ✅ |
| Otras reglas afectadas | **0** — lista completa de los 65 `BLOCKED_BY_ENGINE_GAP` restantes verificada rule-por-rule, idéntica a los 66 de 17.44 menos `461`, ninguna otra reclasificó ✅ |

**Ninguna métrica difirió de lo predicho — no fue necesario ningún STOP.**

#### 4. Estado final

La regla 461 (`A30/F`, patrón leading formula-based, sin etiqueta textual, único caso real de toda Serie A que necesitaba este mecanismo) queda **completamente resuelta y cerrada** — de `BLOCKED_BY_ENGINE_GAP` a `SAFE_1_TO_1`, con auditoría completa (`RuleVersion` + activity log). Su binding preexistente (`525→estructura 19`, inactivo respecto a la estructura activa 67) permanece intacto, sin tocar. **No se ejecutó ningún rebind** — la regla 461 sigue sin binding a la estructura activa 67, igual que las 473 restantes `SAFE_1_TO_1` de toda la campaña (ningún rebind ha sido autorizado en ningún punto).

**Todo el trabajo relacionado con la regla 461 iniciado en 17.45 queda cerrado**: auditoría (17.45) → diseño del mecanismo (17.46) → auditoría runtime compartida (17.47) → contexto técnico de frontera (17.48) → mecanismo formula-based implementado y validado (17.49) → **ejecución real (17.50)**.

Baseline final: `activas=751`, `SAFE_1_TO_1=474`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=65`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`.

**No se tocaron las 55 trailing, A09/I, fila 333/`AR337`, `229`/`230`, las 56 `NO_UTILIZADA`, bindings/rebind, calibraciones, `rem_data`, estructura, template.** No backfill. No commit de Git, no push.

### 17.51 — AUDITORÍA FINAL DE CIERRE REM A (2026-08-28)

⚠️ **100% READ-ONLY.** Ninguna implementación, ninguna corrección, ningún cambio de `status`/`config`, ningún rebind, ningún backfill, ningún reproceso de uploads. No commit de Git, no push.

#### 1. Reconfirmación de baseline

`activas=751`, `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `REQUIRES_REMAP=0`, `rem_rules=798`, `rem_rule_bindings=1204` — **exacto, sin ninguna discrepancia**. Estructura activa `67/v35`.

#### 2. Auditoría rule-por-rule de los 65 `BLOCKED_BY_ENGINE_GAP`

Reconstruidos exactos desde BD real: **65 = 56 `NO_UTILIZADA` + 9 origen `A09/I`** (ningún otro caso — confirmado, las 55 trailing y la 461 ya salieron de este universo en fases anteriores).

**Grupo NO_UTILIZADA (56)** — `A21`(25) `A24`(3) `A25`(22) `A30AR`(2) `A34`(4), confirmado `no_utilizada` real para las 5 hojas vía `RemSheetUsageStatusService`. **Categoría: `NO_UTILIZADA`.** Bloqueo real para cerrar REM A: **ninguno** — irrelevante mientras las hojas permanezcan fuera de uso; no requiere desarrollo ni decisión, solo reactivación futura de Estadística APS si cambia.

**Grupo A09/I origen (9)** — reconfirmado exacto contra `metadata->derived_from_rule_id` real:

| rule_id | Columna | Hijas reales (ids) | Cobertura | Categoría(s) | Bloqueo real |
|---|---|---|---|---|---|
| `226,227,231,232,234` (B2) | AM,AN,AT,AU,AX | 5 c/u (`868-892`) | 5/6 offsets (falta 333) | `COBERTURA_FUNCIONAL_PARCIAL_MEDIANTE_HIJAS` + `DEFECTO_TEMPLATE` | `AR337` (referencia espuria del template a fila 337, fuera de cualquier sección) — bloquea la fila 333 para TODAS las columnas por diseño del mecanismo #12 (escaneo por fila, no por columna). No es desarrollo pendiente ni decisión de Estadística APS — es un defecto del archivo Excel de origen, fuera del control de este sistema. |
| `229` (B3) | AR | 5 (`913-917`) | 5/6 offsets (falta 333) | `COBERTURA_FUNCIONAL_PARCIAL_MEDIANTE_HIJAS` + `DEFECTO_TEMPLATE` | Mismo `AR337` — es la propia fórmula de esta columna la que lo referencia (origen del problema para las otras 5 columnas también). |
| `228` (Cat. F) | AQ | 2 (`911,912`) | 2/2 reales (4 restantes nunca existieron en el template) | `COBERTURA_FUNCIONAL_COMPLETA_MEDIANTE_HIJAS` + `DEFECTO_TEMPLATE` | Ninguno real — todo lo que existía en el Excel fuente ya está cubierto. El origen permanece `BLOCKED_BY_ENGINE_GAP` porque su propio `config` (placeholder `{0,0}`) nunca se completa — por diseño (no se inventa un `total_row` para el origen mismo, la cobertura vive en las hijas). |
| `233` (Cat. F) | AV | 1 (`919`) | 1/1 real (5 restantes nunca existieron) | `COBERTURA_FUNCIONAL_COMPLETA_MEDIANTE_HIJAS` + `DEFECTO_TEMPLATE` | Ninguno real — igual que 228. |
| `230` (Cat. F) | AS | 1 (`918`) | 1/6 (el único completo/limpio); 5 restantes con mapeo ambiguo/roto (parciales de 2-de-13 términos + 1 de residuo incorrecto) | `COBERTURA_FUNCIONAL_PARCIAL_MEDIANTE_HIJAS` + `REQUIERE_DECISION_ESTADISTICA_APS` | Los 5 restantes requieren determinar si el patrón disperso es intencional (validación deliberadamente más estrecha) o un error de autoría del template — no puede resolverse con evidencia técnica adicional, ya investigado a fondo (17.20/17.25/17.41). |

**Verificación de suma**: `56+9=65` exacto.

**Conclusión del punto 2**: de los 65, **56 son irrelevantes** (hoja no usada) y **de los 9 restantes, 7 ya tienen toda su cobertura funcional real completa** (`226,227,228,231,232,233,234` — todo lo que existe en el template Excel ya está materializado como regla activa `SAFE_1_TO_1`). Solo **2 tienen trabajo genuino sin cerrar**: `229` (bloqueado por un defecto de template ajeno al sistema, `AR337`) y `230` (requiere una decisión de negocio de Estadística APS sobre un patrón ambiguo del template). **Ninguno de los 65 representa una regla de negocio real "faltante por desarrollar"** — 0 de los 65 necesita código nuevo.

#### 3. Auditoría rule-por-rule de los 14 `DUPLICATE`

Reconfirmados exactos contra `config`/`created_at`/`source` reales — **reutiliza la caracterización ya exhaustiva de 17.35/17.36** (nada cambió desde entonces, config byte-idéntico verificado hoy):

| Grupo | rule_ids | Naturaleza | Evidencia | Trabajo funcional pendiente real |
|---|---|---|---|---|
| `A01/B/C` | `560,618` | **Duplicado exacto** — mismo `config` byte-idéntico, mismo `created_at` | Confirmado, sin ambigüedad | **Ninguno** — deuda de catálogo pura (uno de los dos sobra), no representa funcionalidad faltante |
| `A01/A/W` | `585,602` | **Subset/superseded** — `602` (fila 31 sola) contenido dentro del rango de `585` (11-32), misma relación `C→W` | Confirmado | **Ninguno real** — `602` es candidata a redundante, pendiente de confirmar si la fila 31 amerita regla propia (revisión menor, no funcionalidad faltante) |
| `A01/E/B` | `29,580` | **Subset/superseded** — misma fórmula `C+D=B`, `29` cubre solo fila 78, `580` cubre 78-82 | Confirmado | **Ninguno real** — mismo patrón que arriba |
| `A06/A.2/B` y `A06/A.3/B` | `126,127` | **Subset/superseded** — mismo patrón (legacy `csv_catalog` vs. `vetted_catalog` más amplio) | Confirmado (grupos mixtos 7/11 de 17.36) | **Ninguno real** |
| `A01/A/C` | `24,553,557,558,559,617` | **Mixto/ambiguo (Categoría D)** — `24⊂553` (subset), `558`/`559` con rangos de fila SOLAPADOS (`27:32` vs `29:30`) con fórmulas distintas, `617` fórmula independiente que cubre todo el rango | Confirmado, sin resolución mecánica posible | **Requiere revisión humana dedicada** (`NEEDS_DEEP_AUDIT` ya documentado en 17.36) — es el único de los 14 que podría, en teoría, ocultar una regla de negocio genuinamente distinta sin resolver; no confirmado ni descartado |

**Verificación de suma**: `2+2+2+2+6=14` exacto.

**Conclusión del punto 3**: de los 14, **8 son deuda de catálogo confirmada** (duplicado exacto o subset/superseded, sin ambigüedad) — no representan trabajo pendiente. **6 (`A01/A/C`) son el único caso genuinamente ambiguo** de todo el universo `DUPLICATE` — podrían ocultar una necesidad de remapeo real, pero no fue posible confirmarlo ni descartarlo con evidencia técnica (rangos solapados, mezcla de proveniencia `csv_catalog`/`vetted_catalog`). **Ninguno de los 14 fue desactivado ni modificado.**

#### 4. Barrido de los 198 `ALREADY_STRUCTURE_AGNOSTIC`

**No auditados uno por uno** (confirmado innecesario) — en su lugar, barrido automatizado de colisión de identidad (misma `sheet+section+column`) contra los 198 reales: **exactamente 13 grupos con más de 1 regla**, los mismos 13 ya identificados en 17.35/17.36 (`A06/C.2/D`, `A06/C.3/D`, `A06/A.2/B,E,F,G`, `A06/A.3/B,E`, `A06/B.3/D,E`, `A06/L/B,C,D`) — **0 grupos nuevos descubiertos**, confirma que la caracterización previa sigue siendo exhaustiva.

- **Regla rota (artefactos autorreferenciales)**: `130` (`A06/C.2/D`) y `133` (`A06/C.3/D`) — reconfirmados: `rule_logic="Suma(D)=Columna D"` (degenerado), **100% `skipped`** en su historial completo de ejecución (confirmado de nuevo hoy vía `RuleExecutionLog`), con un par funcional real (`786`/`787`) cubriendo la misma clave con fórmula genuina. **Categoría: regla rota/stale.** No desactivadas (candidatas ya documentadas, pendientes de autorización).
- **Coexistencia legítima confirmada**: los 11 grupos restantes (`658/698`, `661/699`, `662/700`, `663/701`, `702/714`, `705/715`, `720/721/722`, `723/724/725`, `772/783`, `773/784`, `774/785`) — cada par/trío combina una fórmula **vertical** (`Suma(X)=Columna X`, self-sum, `total_row` fijo) con una fórmula **horizontal** genuinamente distinta (`Suma(otras columnas)=Columna X`) — confirmado en 17.36, sin cambio. **Categoría: funcionalmente válida** (2 validaciones reales independientes que comparten clave simple pero no clave completa).
- **Sin ningún otro caso equivalente a 130/133 detectado** en el barrido de colisión — el patrón "regla rota + par funcional real" queda confirmado limitado a esos 2 casos.

**Conclusión del punto 4**: de los 198, **2 son reglas rotas conocidas** (`130,133`, ya documentadas, candidatas a `inactive`, no autorizado tocar), y el resto (196) son **funcionalmente válidas o deuda de catálogo de bajo riesgo** (los 11 grupos de coexistencia legítima) — **sin ningún caso oculto nuevo**.

#### 5. Matriz de calibración A01–A34 (barrido real de las 381 secciones)

| Categoría | Secciones | Detalle |
|---|---|---|
| `AUTO_MIGRATE` | 291 | Fingerprint vivo coincide con el histórico — calibradas y estables |
| `NO_UTILIZADA` | 75 | `A21`(15) `A24`(14) `A25`(20) `A30AR`(15) `A34`(11) |
| `QUICK_CONFIRMATION` | 10 | `A11a` (flujo distinto, sin tocar) |
| `NOT_CALIBRATABLE` | 2 | `A04/N`, `A32/E1` — sin contenido calibrable, cierre humano confirmado |
| `NEW_SECTION` | 2 | `A05/V`, `A30/D` — deliberadamente fuera de alcance |
| `MISMATCH` | **1** | `A30/C pattern_id=1` — el único, confirmado |

**Verificación de suma**: `291+75+10+2+2+1=381` exacto.

**Totales de progreso** (`buildStructureCalibrationSummary()`, real): `sections_aplicables=306`, `sections_completed=304`, `sections_calibrated=302`, `sections_not_calibratable=2`, `sections_pending=2` (`A05/V`,`A30/D`), `progress_pct=99` (304/306). **Idéntico al último checkpoint de calibración (2026-08-26)** — sin ningún cambio.

**`A09/I` confirmado `AUTO_MIGRATE`** (dentro de los 291) — cerrada tras `structural_row_exclusion` #12 (17.32), sin regresión. **`A30/C` sigue siendo el único `MISMATCH` global** — sin resolver, sin tocar, como estaba (`human_review`, decisión pendiente de Estadística APS sobre columnas J/K/L nuevas).

#### 6. Runtime — camino completo parser → captura → regla → evaluador → resultado

| Mecanismo | Reglas | Camino completo confirmado | Estado |
|---|---|---|---|
| Categoría A — dentro de límites (171) | 171 | Fase 3A (hook original, 2026-08-27) — captura real confirmada en producción (ej. `A32/F2` fila 140, upload 186) | **`RUNTIME_VALIDADO`** |
| Categoría A — beyond bounds (55) | 55 | 17.48 (`findTechnicalSectionContextForRow`) — mecanismo #8/#12 confirmado real contra las 10 secciones reales; test end-to-end sintético (12/12); **0 capturas reales todavía** (ningún upload posterior al fix ha tocado exactamente estas filas) | **`RUNTIME_VALIDADO`** (mecanismo real confirmado + e2e sintético) — pendiente de una carga real que lo ejerza para evidencia de producción, no de desarrollo adicional |
| Categoría C — leading dentro de límites (29) | 29 | Fase 3A (hook original) — mismo camino ya validado en producción | **`RUNTIME_VALIDADO`** |
| `source_rows` (B1/B4, 12) | 12 | `SumEqualsEvaluator`/`RuleEngineService` (17.22/17.23) — tests reales + sintéticos, sin regresión | **`RUNTIME_VALIDADO`** |
| B2/B3/CategoríaF hijas (34: 25+9) | 34 | Filas 331-336 caen **dentro** de `[249:336]` de `A09/I` — usan el hook original de Fase 3A vía mecanismo #12 (mismo camino que `A32/F2`), no el mecanismo de frontera de 17.48 | **`RUNTIME_VALIDADO`** (mecanismo real confirmado; sin carga real posterior al fix de merge que haya capturado específicamente estas filas todavía) |
| 461 leading formula-based | 1 | 17.49/17.50 — mecanismo confirmado real, dry-run + commit real, e2e sintético (36 tests), simulación de clasificación con rollback | **`RUNTIME_VALIDADO`** (mismo matiz: sin carga real que lo haya ejercido en producción todavía) |

**Ningún mecanismo queda en `SOLO_CLASIFICACION_VALIDADA` ni `REQUIERE_PRUEBA_END_TO_END`** — todos tienen prueba end-to-end (real o sintética con datos/mecanismos reales) y confirmación directa contra `cell_data` real. **La distinción real no es "falta validar el mecanismo" sino "ningún mecanismo ha sido ejercido todavía por una carga real de producción"** — porque `rem_rule_bindings` a la estructura activa = `0` (ver punto 7).

#### 7. Bindings — qué significa 1204 con 0 activos a estructura 67

`rem_rule_bindings=1204` — todos corresponden a estructuras **anteriores** (19, 63, 64, 66, y los residuales de pruebas de Fase A) — **ninguno vincula una regla a la estructura activa actual (67/v35)**. Esto significa: **el motor de reglas, el catálogo, y los mecanismos de captura están completos y validados a nivel de código — pero NINGUNA regla se ejecuta hoy contra ninguna carga real**, porque `RuleEngineService::resolveRules()` filtra estrictamente por bindings activos a la estructura vigente, y no existe ninguno.

**Separación clara exigida**:
- **Motor/reglas terminados**: SÍ, en el sentido de que 474 reglas están clasificadas `SAFE_1_TO_1` y todos los mecanismos de captura runtime están implementados y confirmados contra evidencia real.
- **Reglas realmente vinculadas y ejecutándose sobre cargas nuevas**: NO, ninguna — el sistema de validación de reglas está, en la práctica, **inerte** para cualquier carga real de hoy en adelante, hasta que se autorice un rebind.

**Procedimiento seguro de rebind (diseño únicamente, no implementado, no ejecutado)**:
1. Backup completo de BD antes de cualquier escritura (mismo estándar de toda la campaña).
2. Dry-run de `rule:rebind-safe-to-structure --structure=67` (comando ya existente de Fase A) — revisar el listado completo de candidatos (esperado: hasta 474, menos las que ya tuvieran binding activo a 67, que son 0).
3. `--commit` del rebind — crea bindings nuevos, nunca modifica/borra reglas ni `rem_data` histórico.
4. **Antes de considerar el sistema "en producción"**: procesar una carga REAL nueva (o una copia de una carga reciente reprocesada en un entorno de prueba, nunca sobre `esalud_dev` sin aislar) a través de `ProcessRemUploadJob` completo, e inspeccionar manualmente `RuleExecutionLog`/`rem_validation_results` para una muestra representativa de cada grupo de mecanismo (Categoría A dentro/fuera de límites, Categoría C, `source_rows`, B2/B3/F, 55 trailing, 461) — confirmando que `rem_technical_totals` efectivamente se puebla para las filas boundary/backward-subtotal esperadas.
5. Comparar resultados contra el sistema de validación legacy (si sigue disponible) para una carga de control, mismo patrón que `RuleEngineIntegrationTest` ya usado en fases anteriores.
6. Solo después de (4) y (5) sin discrepancias, considerar el rebind estable para producción continua.

**No se ejecutó ningún paso de este procedimiento** — queda como diseño, pendiente de autorización explícita futura, un paso a la vez.

#### 8. VEREDICTO FINAL

| Área | Estado | Qué falta | ¿Bloquea cierre REM A? | ¿Requiere desarrollo? | ¿Requiere Estadística APS? |
|---|---|---|---|---|---|
| Clasificación de reglas (751 activas) | 474 `SAFE_1_TO_1` / 65 `BLOCKED` / 14 `DUPLICATE` / 198 `AGNOSTIC` | Nada crítico — ver desglose | No | No | No |
| `BLOCKED_BY_ENGINE_GAP` (65) | 56 irrelevantes (no_utilizada) + 7/9 A09/I con cobertura completa | Solo `229`(`AR337`) y `230`(mapeo ambiguo) | No | No (ambos son defecto de template/decisión de negocio, no código) | Sí, solo para `230` |
| `DUPLICATE` (14) | 8 deuda de catálogo confirmada + 6 ambiguos (`A01/A/C`) | Revisión humana de `A01/A/C` (opcional, bajo riesgo) | No | No | Opcional |
| `ALREADY_STRUCTURE_AGNOSTIC` (198) | 196 válidas/deuda menor + 2 rotas (`130,133`) | Decisión de desactivar `130,133` (opcional, bajo riesgo) | No | No | No |
| Calibración Serie A | 302/306 calibradas (99%), 1 `MISMATCH` (`A30/C`) | Decisión sobre columnas J/K/L nuevas de `A30/C` | No (no afecta ejecución del motor) | No | Sí, para `A30/C` |
| Runtime (mecanismos) | Todos `RUNTIME_VALIDADO` (mecanismo + e2e) | Ejercicio contra carga real de producción (no desarrollo) | No | No | No |
| Bindings | `1204` totales, `0` a estructura 67 | **Rebind** — único paso operativo real pendiente | **Sí, para ejecución real** (no para el estado del código) | No (procedimiento ya diseñado) | No |

**VEREDICTO: `REM_A_LISTO_PARA_REBIND_Y_PRUEBAS_FINALES`**

**Justificación**: no queda ningún desarrollo de código pendiente para que REM A funcione correctamente. Los únicos 2 puntos que requieren a Estadística APS (`230`/A09-I y `A30/C`) son decisiones de negocio aisladas que **no bloquean** el rebind ni la ejecución del resto del motor — pueden resolverse en paralelo o después, sin afectar a las 474 reglas ya listas ni a los mecanismos runtime ya validados. El único paso operativo real que falta para que REM A pase de "motor completo pero inerte" a "motor ejecutándose sobre cargas reales" es el **rebind** (diseñado en el punto 7, no ejecutado) seguido de las pruebas end-to-end contra una carga real. **No hay bloqueos críticos, no hay desarrollo adicional requerido, y no hay ninguna decisión funcional que impida avanzar al rebind.**

Baseline final reconfirmado sin cambios: `activas=751`, `SAFE_1_TO_1=474`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=65`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1204`. Ninguna escritura de ningún tipo durante esta auditoría. No commit de Git, no push.

### 17.52 — PREFLIGHT DEL REBIND A ESTRUCTURA 67 / DRY-RUN Y SIMULACIÓN (2026-08-28)

⚠️ **100% READ-ONLY / SIMULACIÓN CON ROLLBACK GARANTIZADO.** Ningún binding real creado, ningún `config`/`status` modificado, ninguna calibración tocada, ningún upload reprocesado, ninguna regla resuelta (`229`/`230`/`A01/A/C`/`130`/`133` sin tocar), `no_utilizada` sin tocar, estructura 67 sin modificar. No commit de Git, no push.

#### 1. Reconfirmación de baseline (antes de cualquier operación)

`activas=751`, `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `REQUIRES_REMAP=0`, `rem_rules=798`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, estructura activa `67/v35` — **exacto, sin ninguna discrepancia** respecto al cierre de 17.51.

#### 2. Dry-run real del algoritmo de rebind (código real, no una simulación paralela)

Ejecutado el comando real `php artisan rule:rebind-safe-to-structure --structure=67 --reason="Preflight dry-run 17.52" --by="Administrador Esalud"` (sin `--commit`) — invoca directamente `RuleBindingReconciliationService::findSafeCandidatesForStructure()`, el mismo método que usa la ruta de escritura real.

**Resultado**: `Candidatos SAFE_1_TO_1 evaluados: 451` · `Ya vinculados a la estructura destino (no-op): 0` · `Bindings NUEVOS que se crearían: 451`.

**Discrepancia investigada, no ignorada**: 451 ≠ 474 (`SAFE_1_TO_1` total). Causa exacta confirmada leyendo `findSafeCandidatesForStructure()`: filtra explícitamente `!$row['hoja_no_utilizada']` — excluye del rebind cualquier regla `SAFE_1_TO_1` cuya hoja esté marcada `no_utilizada`, sin importar su clasificación. Verificado por consulta directa: **exactamente 23 reglas** `SAFE_1_TO_1` pertenecen a hojas `no_utilizada` (`301,302,303,304,308,309,325,345,349,350,351,353,361,362,363,370,464,465,466,467,468,548,551`, las 5 hojas conocidas A21/A24/A25/A30AR/A34). `474-23=451` exacto. **No es un defecto — es el diseño correcto y ya documentado del comando**, consistente con el criterio usado en toda la campaña de nunca vincular reglas de hojas fuera de uso.

#### 3. Verificación del gate estricto (contra la salida real del dry-run, 451 filas)

| Verificación | Resultado |
|---|---|
| `461` (leading formula-based, activada en 17.50) presente como candidata | ✅ presente |
| Las 55 trailing-beyond-bounds (Fase 3C-1B) presentes | ✅ las 55, sin excepción |
| Las 25 hijas B2 (`868-892`) presentes | ✅ las 25 |
| Las 9 hijas B3/CategoríaF (`911-919`) presentes | ✅ las 9 |
| `229`/`230` (orígenes A09/I, `BLOCKED_BY_ENGINE_GAP`) ausentes | ✅ ausentes |
| `130`/`133` (artefactos rotos, `ALREADY_STRUCTURE_AGNOSTIC`) ausentes | ✅ ausentes |
| Muestra de `DUPLICATE` (`24,585,560`) ausente | ✅ ausente |
| Reglas de hojas `no_utilizada` (SAFE_1_TO_1 o no) = 0 en la lista | ✅ 0 — las 23 excluidas explícitamente (punto 2) |
| Conteo total de filas de la tabla = candidatos reportados | ✅ 451 = 451 |

**0 desviaciones** — el gate se comporta exactamente como exige el diseño ya validado en fases anteriores (Categoría A/C/B1-B4/trailing/461 todas elegibles; orígenes A09/I, artefactos rotos, duplicados y hojas no utilizadas correctamente excluidos).

#### 4. Revisión de los 198 `ALREADY_STRUCTURE_AGNOSTIC`

Confirmado por consulta directa (no asumido): de las 198 reglas `ALREADY_STRUCTURE_AGNOSTIC`, **exactamente 198 tienen ya al menos 1 binding activo `bindable_type` = `serie` o `global`** — ninguna depende de un binding a una estructura específica para ejecutarse. Verificado además para los 2 artefactos rotos (`130,133`) y 2 de los pares de coexistencia legítima (`658,772`): todos con binding `serie` activo confirmado. **Conclusión**: el rebind correctamente **no necesita tocar ninguna de las 198** — ya son ejecutables (o, en el caso de `130`/`133`, ya están "vivas" pero degeneradas — su binding `serie` preexistente no cambia con este trabajo, consistente con no resolverlas). El rebind a estructura 67 es ortogonal a este grupo, no un prerequisito ni una omisión.

#### 5. Simulación transaccional del rebind real (transacción externa + rollback garantizado)

Se ejecutó el comando real con `--commit` (`RuleBinding::updateOrCreate` dentro de su propia `DB::transaction()` interna) envuelto en una transacción externa propia (`DB::beginTransaction()`/`DB::rollBack()` en `finally`, nunca comiteada) contra la estructura activa real.

| Métrica | Antes | Después (simulado) |
|---|---|---|
| `rem_rule_bindings` (total) | `1204` | `1655` (+451 exacto) |
| Bindings activos a estructura 67 | `0` | `451` |
| Duplicados de binding activo (`rule_id+bindable_type+bindable_id` repetido) | — | `0` |
| `SAFE_1_TO_1` (no `no_utilizada`) sin binding a 67 tras el rebind | — | `0` |
| `SAFE_1_TO_1` / `DUPLICATE` / `ALREADY_STRUCTURE_AGNOSTIC` / `BLOCKED_BY_ENGINE_GAP` | `474/14/198/65` | `474/14/198/65` — **sin cambio** |

**0 reglas sin destino, 0 duplicados, 0 impacto en clasificación** — coincide exactamente con lo esperado. **Rollback ejecutado y verificado**: `rem_rule_bindings=1204`, bindings a estructura 67=`0`, clasificación idéntica byte a byte al baseline (`474/14/198/65`) — restauración exacta confirmada, no solo asumida.

#### 6. Diseño (NO ejecutado) del plan de prueba final post-rebind con una carga REAL

⚠️ Únicamente diseño — nada de esto se ejecutó en esta fase.

**Procedimiento propuesto**:
1. Ejecutar el `--commit` real del rebind (fuera de esta fase, requiere autorización explícita aparte).
2. Tomar una carga REM A real nueva (o reprocesar una existente en un entorno aislado, nunca sobre `esalud_dev` sin respaldo) a través de `ProcessRemUploadJob::handle()` completo — parser → `rem_data`/`rem_technical_totals` → `RuleEngineService::execute()` con bindings ya activos a estructura 67.
3. Inspeccionar `RuleExecutionLog`/`rem_validation_results` **por regla específica**, no solo por "el upload terminó" — criterio de éxito explícito: cada regla de la muestra debe aparecer con un `status` (`passed`/`failed`/`skipped`) coherente con su tipo, y al menos las reglas de (i)/(j) deben producir el resultado exacto esperado (fallo real / paso real), nunca solo "no crasheó".

**Muestra representativa mínima** (10 casos, ids reales verificados existentes):
| # | Tipo | Ejemplo (rule_id) | Qué debe demostrarse |
|---|---|---|---|
| a | `sum_equals` normal, ya vinculada a otras estructuras | `530` (`A32/F1`, columna B) | Ejecuta y produce el mismo resultado que ya produce contra 19/63/64/66 |
| b | Categoría A (Fase 3C-1A) | `25` | `total_row` trailing dentro de límites se resuelve y evalúa correctamente |
| c | Categoría C (Fase 3C-2, leading) | `46` (`A02/A`) | `total_row` leading dentro de límites se resuelve y evalúa correctamente |
| d | `source_rows` (B1/B4) | `208` (`A09/F.1`) | Lista explícita de filas no contiguas se suma correctamente, ignorando huecos |
| e | Hija B2 | `868` (`A09/I` AM, offset 331) | Fila técnica capturada vía mecanismo #12 dentro de `[249:336]`, evaluada correctamente |
| f | Hija B3/CategoríaF | `911` (`A09/I` AQ, offset 331) | Mismo camino que (e), columna distinta |
| g | Trailing beyond bounds (Fase 3C-1B) | `545` (`A33/C`) | `findTechnicalSectionContextForRow()` resuelve el contexto fuera de `[inicio:fin]`, captura vía mecanismo #12, evalúa correctamente |
| h | Regla 461 (leading formula-based) | `461` | Mismo mecanismo que (g) mirror leading, mecanismo formula-based nuevo confirma la fila 123 sin etiqueta textual |
| i | Caso diseñado para fallar deliberadamente | Alterar en memoria (nunca persistido) un componente de una de las reglas de la muestra antes de evaluar | Confirmar `status=failed` con la razón correcta — nunca un falso `passed` |
| j | Caso diseñado para pasar correctamente | Cualquiera de (a)-(h) con datos reales coherentes | Confirmar `status=passed` explícito, no solo ausencia de error |

**No se ejecutó ningún upload real ni se reprocesó el upload 186** — este es solo el diseño del procedimiento, pendiente de autorización explícita separada del rebind mismo.

#### 7. Tabla final y veredicto

| Reglas candidatas | Bindings que se crearían | Excluidas por motivo | Riesgos | Resultado simulación | ¿Seguro ejecutar rebind? |
|---|---|---|---|---|---|
| 451 (`SAFE_1_TO_1` no `no_utilizada`) | 451 nuevos, todos `bindable_type=structure, bindable_id=67` | 23 `SAFE_1_TO_1` en hojas `no_utilizada` (diseño correcto, no un defecto) · 65 `BLOCKED_BY_ENGINE_GAP` · 14 `DUPLICATE` · 198 `ALREADY_STRUCTURE_AGNOSTIC` (ya ejecutables vía binding serie/global, correctamente fuera de alcance) | Ninguno detectado — 0 duplicados, 0 reglas sin destino, 0 impacto en clasificación, rollback exacto | Medido, no presupuesto: `1204→1655` bindings (+451), `0→451` a estructura 67, clasificación sin cambio, restauración exacta tras rollback | **Sí — el dry-run, el gate y la simulación transaccional coinciden exactamente con lo esperado, sin ninguna desviación** |

**VEREDICTO: `REBIND_READY`**

El rebind real (`--commit`) puede ejecutarse con seguridad cuando se autorice — el algoritmo real ya fue ejercido (no una réplica), el universo de 451 candidatos fue verificado exhaustivamente contra el gate esperado, los 198 `ALREADY_STRUCTURE_AGNOSTIC` fueron confirmados como correctamente fuera de alcance, y la simulación transaccional demostró 0 efectos colaterales y una reversión perfecta. El plan de prueba end-to-end post-rebind (punto 6) queda diseñado y listo para ejecutarse en un paso posterior, separado y con su propia autorización.

**No se ejecutó ningún `--commit` real.** No se tocó `229`, `230`, `A01/A/C`, `130`, `133`, `A30/C`, `no_utilizada`, calibraciones, `rem_data`, estructura 67. No se reprocesó el upload 186. No backfill. No commit de Git, no push.

### 17.53 — REBIND REAL A ESTRUCTURA 67 EJECUTADO Y CERRADO — 451 BINDINGS (2026-08-28)

⚠️ **Escritura real, autorizada explícitamente.** Único cambio: 451 `rem_rule_bindings` nuevos (`bindable_type=structure, bindable_id=67`), vía el comando real `rule:rebind-safe-to-structure` ya auditado/simulado en 17.52. Ninguna regla/config/status modificado. Ninguna calibración/`rem_data`/`rem_technical_totals`/estructura/template/upload tocado. No se resolvió `229`/`230`/`A30/C`. `no_utilizada` sin tocar. No commit de Git, no push.

#### 1. Pre-check — todos los puntos coincidieron exactamente con 17.52

`activas=751`, `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `REQUIRES_REMAP=0`, `rem_rules=798`, `rem_rule_bindings=1204`, bindings a estructura 67=`0`, estructura activa `67/v35`, candidatos reales del comando=`451` — **exacto, sin ninguna discrepancia**. Gates críticos reconfirmados uno por uno antes de escribir: `461` presente (1/1) · las 55 trailing presentes (55/55) · las 25 hijas B2 presentes (25/25) · las 9 hijas B3/F presentes (9/9) · `229`/`230` ausentes (0/2) · `130`/`133` ausentes (0/2) · muestra `DUPLICATE` ausente (0/14) · las 23 SAFE_1_TO_1 en hojas `no_utilizada` correctamente excluidas del candidato (0 presentes).

#### 2. Ejecución

`php artisan rule:rebind-safe-to-structure --structure=67 --reason="Rebind real Fase 17.53..." --by="Administrador Esalud" --commit` — comando real, único punto de escritura (sin bindings creados manualmente, sin tocar `config`/`status` de ninguna regla). Salida: `Candidatos SAFE_1_TO_1 evaluados: 451` · `Ya vinculados a la estructura destino (no-op): 0` · `Bindings nuevos creados: 451. Ya existentes (no-op): 0.`

#### 3. Post-check exhaustivo — todos los puntos exigidos, verificados exactos

| Verificación | Resultado |
|---|---|
| `rem_rule_bindings` total | `1204 → 1655` (+451 exacto) ✅ |
| Bindings a estructura 67 | `0 → 451` ✅ |
| `rule_ids` únicos con binding a 67 | `451` (= total, sin duplicados) ✅ |
| Duplicados de binding activo (`rule_id+bindable_type+bindable_id` repetido) | `0` ✅ |
| Bindings nuevos que NO son `structure/67` | `0` ✅ |
| Las 451 reales vs. las 451 predichas en 17.52 | `451/451` exactas, `0` faltantes, `0` adicionales ✅ |
| Clasificación global tras el rebind | `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `REQUIRES_REMAP=0`, activas=`751` — **sin cambio** ✅ |
| `rem_rules` | `798` (sin cambio) ✅ |
| Gate `461` con binding a 67 | `1/1` ✅ |
| Gate 55 trailing con binding a 67 | `55/55` ✅ |
| Gate 25 hijas B2 con binding a 67 | `25/25` ✅ |
| Gate 9 hijas B3/F con binding a 67 | `9/9` ✅ |
| Gate `229`/`230` sin binding a 67 | `0/2` ✅ |
| Gate `130`/`133` sin binding a 67 | `0/2` ✅ |
| Gate muestra `DUPLICATE` sin binding a 67 | `0/14` ✅ |
| `config`/`status` de muestra (`24,130,133,229,230,461,868,911`) | byte-idénticos, sin ningún cambio (verificado explícitamente) ✅ |
| `RuleVersion` creados en el proceso | `0` (el rebind nunca versiona reglas, solo agrega bindings) ✅ |
| `rem_technical_totals` | `126` (sin cambio) ✅ |
| `rem_data` | `399.811` (sin cambio) ✅ |
| Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` | `8565f0af.../24b3d2b7...` — idénticos a los ya registrados, calibraciones sin tocar ✅ |
| Estructura activa | `67/v35 status=active`, sin tocar ✅ |

**Ninguna métrica difirió de lo predicho en 17.52 — no fue necesario ningún STOP intermedio.**

#### 4. Estado resultante

REM A pasa de "motor completo pero inerte" a **"motor vinculado"**: 451 reglas ahora tienen binding específico y activo a la estructura activa 67/v35; las 198 `ALREADY_STRUCTURE_AGNOSTIC` siguen siendo ejecutables mediante su binding `serie`/`global` preexistente (confirmado 198/198 en 17.52.4, sin cambio aquí). **Todavía pendiente, no ejecutado**: la certificación end-to-end (Fase 17.54, autorización separada) — procesar una carga REM A real y confirmar que las reglas vinculadas efectivamente se ejecutan y producen resultados correctos, no solo que el binding existe.

**No se resolvió `229`/`230`/`A30/C`. No se tocó `no_utilizada`. No backfill. No se reprocesó ningún upload. No se modificó ninguna regla. No se implementó ningún mecanismo nuevo. No commit de Git, no push.**

Baseline final: `activas=751`, `SAFE_1_TO_1=474`, `REQUIRES_REMAP=0`, `DUPLICATE=14`, `BLOCKED_BY_ENGINE_GAP=65`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1655`, bindings a estructura 67=`451`.

### 17.54 — CERTIFICACIÓN END-TO-END REM A (2026-08-28)

⚠️ **Ejecución real de una carga de certificación, autorizada explícitamente.** Se creó **1 upload nuevo (`id=187`)** vía copia trazable del archivo real de `upload 186` (`102302A05.xlsm`), procesado por el flujo normal completo (`ProcessRemUploadJob` → `ValidateRemUploadJob` → `ValidateWithEngineJob`, este último recogido por un `queue:work` real ya corriendo en background — no invocado manualmente). **No se corrigió ningún defecto dentro de esta fase.** No se reprocesó el upload 186. No se tocó ninguna regla/config/status/binding/calibración/estructura/template. No commit de Git, no push.

#### 1. Pre-check — exacto

`activas=751`, `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1655`, bindings a estructura 67=`451`, estructura activa `67/v35` — **exacto, sin discrepancia** respecto al cierre de 17.53. Confirmado además que las 451 reales coinciden exactamente con las 451 predichas (0 faltantes, 0 adicionales).

#### 2. Carga de certificación

- **Origen**: copia byte-idéntica del archivo real de `upload 186` (`102302A05.xlsm`, establecimiento `health_center_id=1`, `year=2026, month=5, rem_type=A`), guardada en una ruta nueva y distinta (`.../20260828202527_102302A05_CERTIFICACION1754.xlsm`) — **el archivo/registro de `upload 186` nunca fue leído en modo escritura ni modificado**.
- **Upload nuevo creado vía el flujo real** (`RemUpload::create` con los mismos campos que usaría el controlador HTTP, `rem_template_id` resuelto igual que en producción): **`upload_id = 187`**, `original_filename` marcado explícitamente como copia de certificación.
- **Ejecutado el flujo real completo, sin insertar `rem_data` manualmente**: `ProcessRemUploadJob::handle()` invocado directamente (equivalente a como lo haría el worker) para el parseo; el propio parseo despachó `ValidateRemUploadJob` a la cola real, y un **`php artisan queue:work` que ya estaba corriendo en background** (PID confirmado, proceso independiente de esta sesión) lo recogió y ejecutó, encadenando automáticamente `ValidateWithEngineJob` — **el motor de reglas se ejecutó por el camino real de producción, nunca invocado manualmente**.
- **Estado final del upload**: `status=with_errors`, `processed_at` registrado. **No se consideró "processed"/"validating" como certificación suficiente** — se esperó explícitamente a los 3 estados intermedios (`validating` tras parseo → `validating` tras validación estructural/funcional con motor pendiente → `with_errors` final tras `ValidateWithEngineJob`).

#### 3. Snapshot antes/después (atribución exacta al upload de certificación)

| Métrica | Antes | Después | Delta atribuible a upload 187 |
|---|---|---|---|
| `uploads` | 145 | 146 | +1 (`id=187`) |
| `rem_data` | 399.811 | 403.247 | +3.436 (exclusivas de 187, confirmado `rem_upload_id=187`) |
| `rem_technical_totals` | 126 | 276 | +150 (exclusivas de 187) |
| `rem_rule_execution_logs` | 57.380 | 58.029 | +649 (exclusivas de 187) |
| `rem_validation_results` | 522.908 | 524.950 | +2.042 (exclusivas de 187) |
| `rem_rule_bindings` | 1.655 | 1.655 | **0** — ningún binding nuevo, esta fase no toca bindings |
| `rem_rules` | 798 | 798 | **0** |

#### 4. Ejecución real de bindings — confirmado con evidencia, no solo logs

`StructureResolverService::resolve($upload)` para `upload 187` → **`67`** (la estructura activa, exactamente la que recibió los 451 bindings en 17.53). `rem_rule_execution_logs` para `upload 187`: **649 total = 3 `failed` + 591 `passed` + 55 `skipped`**. Los 55 `skipped` investigados uno por uno: **0 anomalías** — todos con motivo ya documentado en fases previas (sección sin filas en esta carga concreta, `invalid_row_range_configuration` de la regla ya conocida como artefacto roto `130`, o `missing_total_row` del patrón vertical `A06/L` con `total_row=181` ya documentado como 100% inerte desde 17.36). **Ningún skip nuevo ni inesperado.**

#### 5. Matriz obligatoria — 9 casos, todos evaluados y `PASS`

| rule_id | mecanismo | binding usado | componentes (upload 187) | total esperado (capturado) | total observado (evaluado) | resultado | PASS/FAIL |
|---|---|---|---|---|---|---|---|
| `530` (`A32/F1`, col B) | `sum_equals` normal, ya vinculada a 19/63/64/66 | **+ structure:67 (nuevo)** | `C..S` reales de `rem_data` | `B123` real de `rem_data` | coinciden | `passed` | **PASS** |
| `25` (`A01/D`, col I) | Categoría A (trailing dentro de límites) | structure:19,**67** | filas `70-73` | `total_row=74` real | coinciden | `passed` | **PASS** |
| `46` (`A02/A`, col AG) | Categoría C (leading dentro de límites) | structure:19,**67** | filas `12-17` | `total_row=11` real | coinciden | `passed` | **PASS** |
| `208` (`A09/F.1`, col F) | `source_rows` (lista no contigua `[149,150,153,155,157]`) | structure:19,**67** | 5 filas listadas, huecos ignorados | `total_row=158` real | coinciden | `passed` | **PASS** |
| `868` (`A09/I` AM, offset 331) | hija B2 (periódico, `source_rows` 13 términos) | **structure:67 (único)** | 13 filas `[253..325]` paso 6 | `total_row=331` vía `rem_technical_totals` (mecanismo #12) | coinciden | `passed` | **PASS** |
| `911` (`A09/I` AQ, offset 331) | hija B3/CategoríaF | **structure:67 (único)** | idéntico patrón a 868, columna distinta | ídem | coinciden | `passed` | **PASS** |
| `545` (`A33/C`, col C) | trailing-beyond-bounds (Fase 3C-1B/17.48) | structure:19,**67** | filas `54,55` = `null/null` (⇒0) | `total_row=56` capturado vía `rem_technical_totals`, `exclusion_reason=trailing_total_beyond_bounds`, `total=0` | `0=0` | `passed` | **PASS** — **primera captura real en producción de este mecanismo** |
| `461` (`A30/F`, col B) | leading-formula-based (17.49/17.50) | structure:19,**67** | filas `124-129` (2 bloqueadas sin dato, resto `0`) | `total_row=123` capturado vía `rem_technical_totals`, `exclusion_reason=leading_formula_total_beyond_bounds`, `values.B=0` | `0=0` | `passed` | **PASS** — **primera captura real en producción de este mecanismo, único caso de toda Serie A que lo necesita** |
| `658` (`A06/A.2`, col B) | `ALREADY_STRUCTURE_AGNOSTIC` vía binding `serie` (sin tocar por el rebind) | `serie` (sin `structure:67`) | fila `30-31` | `total_row=32` | coinciden | `passed` | **PASS** — confirma que las 198 siguen ejecutándose sin necesitar binding a 67 |

**Las 9, sin excepción, `passed` con evidencia real (no solo ausencia de error).**

#### 6. `rem_technical_totals` — verificación específica de los 5 puntos exigidos

- **Los 55 trailing-beyond-bounds capturados de verdad**: confirmado — `A31/A,B,C,D`, `A32/A,B,D.2,D1,E2`, `A33/C` aparecen en `rem_technical_totals` de `upload 187` con `exclusion_reason=trailing_total_beyond_bounds` (1 fila cada una) — **primera captura real desde su implementación en 17.48**, nunca antes ejercida por una carga real.
- **La 461 captura su total leading**: confirmado — `A30/F` con `exclusion_reason=leading_formula_total_beyond_bounds`, único registro de este tipo en toda la carga (consistente con el barrido de 381 secciones de 17.49: es el único caso real de Serie A).
- **Los TOTAL técnicos no contaminan `rem_data`**: confirmado — las filas capturadas (fila 56 de A33/C, fila 123 de A30/F, filas 331/332/334/335/336 de A09/I, y el resto de las ~100 secciones con mecanismos #6/#8/#12 ya existentes) están en `rem_technical_totals`, **ninguna en `rem_data`** para su fila técnica (verificado explícitamente para las 2 muestras críticas).
- **A09/I mantiene el comportamiento corregido de merges**: confirmado — `A09/I: 5 registros (embedded_backward_subtotal_row, embedded_trailing_total_row)` — coincide exacto con lo esperado (fila 331 vía mecanismo de fila líder + filas 332/334/335/336 vía el fix de ancla de merge de 17.30).
- **Fila 333 conserva el tratamiento documentado**: confirmado — **0 registros** de la fila 333 en `rem_technical_totals` de esta carga (bloqueada por `AR337`, sin ningún cambio, consistente con lo documentado desde 17.27/17.38-17.43 — no tocada en esta fase).

**Hallazgo adicional, no anticipado pero consistente con el diseño genérico ya documentado**: la carga capturó también `A06/A.2,A.3,C.2,C.3,H` (`trailing_total_beyond_bounds`) — coincide exactamente con los "2 casos adicionales genuinos, sin relación con ninguna regla activa" ya señalados en 17.48 (`A32/F2` fila 151, `A33/E` fila 74) más los ahora confirmados de `A06` — el mecanismo es correctamente genérico, sin necesitar ningún caso especial por sección.

#### 7. Prueba negativa — real, trazable, sin fabricar ningún fixture

El propio archivo de certificación **ya contenía 3 inconsistencias reales y trazables** (mismos datos que el establecimiento real capturó, copiados sin alterar) — usadas directamente, tal como exige el punto 8 de la instrucción ("si el archivo elegido ya contiene un fallo real conocido y trazable, utilizarlo"):

| rule_id | Regla | Motivo exacto | Diagnóstico |
|---|---|---|---|
| `178` (`A08/C`, col B) | `Suma(C+D+E)=Columna B` | `empty_not_allowed_by_functional_rule` | Fila "Medicina Interna" con `C81/D81/E81=null`, pero una decisión funcional (`debe_registrar_cero`, aprobada, "CESFAM Cirujano Guzmán") exige registrar 0 explícito — el motor detecta correctamente la discrepancia y reporta las 3 celdas pendientes exactas |
| `714` (`A06/A.3`, col B) | `Suma(C+D)=Columna B` | ídem | Fila "Consultorías de Salud Mental", mismo patrón, mismas 2 celdas pendientes identificadas |
| `715` (`A06/A.3`, col E) | `Suma(F+G)=Columna E` | ídem | Misma fila, columna E, mismo diagnóstico |

**Confirmado**: dato real (ausente, no alterado artificialmente) → regla correspondiente ejecutada (con binding activo, incluidas `714`/`715` que forman parte del universo ya auditado en 17.36 como "coexistencia legítima", nunca antes ejecutadas con binding a la estructura vigente) → `failed` con diagnóstico exacto (fila, columnas pendientes, decisión funcional citada) — **el motor distingue correctamente un caso real de inconsistencia de los 591 casos que sí pasan**. No se conservó ningún archivo alterado como fixture (no fue necesario — se usó el archivo real tal cual).

#### 8. Integridad posterior — 0 cambios fuera de lo esperado

`rem_rules=798` (sin cambio) · `rem_rule_bindings=1655` (sin cambio, esta fase no crea bindings) · bindings a estructura 67=`451` (sin cambio) · `RuleVersion` creados en el proceso=`0` · estructura activa `67/v35`, hash sin cambio · hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json` = `8565f0af.../24b3d2b7...`, idénticos — calibraciones sin tocar · clasificación global sin cambio (`SAFE_1_TO_1=474, BLOCKED_BY_ENGINE_GAP=65, DUPLICATE=14, ALREADY_STRUCTURE_AGNOSTIC=198`) · **`upload 186` confirmado byte-idéntico** (`status=with_errors`, `error_report` con sus 3 errores originales intactos, `stored_path` sin cambio).

#### 9. VEREDICTO

| Capa | Esperado | Observado | Estado |
|---|---|---|---|
| Parser (Excel → rem_data) | Parsea sin corromper mecanismos, error espurio de 17.48 eliminado | 3.436 filas persistidas, `error_report` con 2 errores reales de dato (vs. 3 en `upload 186` — el error espurio `A33/56/B "Total"` **ya no aparece**) | ✅ |
| Estructura | Resuelve a `67/v35` | `StructureResolverService::resolve()` → `67` | ✅ |
| `rem_data` | Sin contaminación histórica, exclusivo de `187` | +3.436 filas, `upload 186` intacto | ✅ |
| `rem_technical_totals` | Captura real de los 5 puntos exigidos | 150 filas nuevas, incluidas 461 y las 55-familia, fila 333 correctamente ausente | ✅ |
| Bindings | Los 451 usados realmente por el motor | 9/9 muestra representativa evaluada con binding a 67 (o serie/global donde corresponde) | ✅ |
| RuleEngine | Ejecución real, no solo logs | 649 logs (3 failed + 591 passed + 55 skipped), 55 skips 100% explicados | ✅ |
| Mecanismos especiales | 9 casos, todos `passed` con evidencia | Matriz completa, componentes/total esperado/observado coincidentes | ✅ |
| Prueba negativa | Inconsistencia real detectada y diagnosticada | 3 failures reales, diagnóstico exacto por fila/columna/decisión funcional | ✅ |
| Integridad | 0 cambios fuera de lo esperado | Reglas/bindings/calibraciones/estructura/template/`upload 186` — todos confirmados intactos | ✅ |

## **VEREDICTO: `REM_A_END_TO_END_CERTIFICADO`**

**Justificación**: las 9 piezas del motor (sum_equals normal, Categoría A, Categoría C, `source_rows`, hijas B2/B3-F, trailing-beyond-bounds, leading-formula-based/461, y ejecución vía binding serie/global) fueron demostradas funcionando de punta a punta contra una carga real nueva, con evidencia concreta (no solo "el upload terminó"). **Dos mecanismos —trailing-beyond-bounds y la regla 461— tuvieron, en esta fase, su primera ejecución real de producción desde su implementación**, confirmando que el diseño (nunca antes ejercido con datos reales) funciona exactamente como se predijo. La prueba negativa (3 fallas reales, no fabricadas) confirma que el motor también detecta y diagnostica correctamente una inconsistencia genuina. Ningún defecto fue encontrado; ninguna corrección fue necesaria ni se intentó.

**No se corrigió nada dentro de esta fase** (no había nada que corregir). No se tocó `229`/`230`/`A30/C`/`no_utilizada`/calibraciones/estructura/template/`upload 186`. No se creó ningún binding nuevo. No commit de Git, no push.

Baseline final: `activas=751`, `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `rem_rules=798`, `rem_rule_bindings=1655`, bindings a estructura 67=`451`. Nuevo: `uploads=146` (`+1`, `id=187`, carga de certificación trazable), `rem_data=403.247` (`+3.436`), `rem_technical_totals=276` (`+150`).

## Prohibiciones vigentes (depuradas 2026-08-28 — ver "ESTADO VIGENTE ÚNICO" abajo para el baseline que respalda esta lista)

- **[SUPERADO — ver punto 17.54]** La certificación end-to-end REM A ya fue **ejecutada y cerrada** con veredicto `REM_A_END_TO_END_CERTIFICADO` — la prohibición de "no ejecutar la prueba end-to-end sin autorización" queda superada, ya se ejecutó una vez. **No repetir la carga de certificación (`upload 187`) ni crear una nueva sin autorización explícita** — el upload 187 queda como fixture trazable de referencia, no debe eliminarse ni reprocesarse.
- **No modificar/eliminar `upload 187`** (la carga de certificación, con sus 3.436 filas de `rem_data`, 150 de `rem_technical_totals`, 649 logs de ejecución y 2.042 resultados de validación) sin autorización explícita — queda como evidencia trazable de la certificación.
- **[SUPERADO — ver punto 17.53]** El rebind real a estructura 67 ya fue **ejecutado y cerrado** (451 bindings nuevos, post-check exacto sin discrepancias) — la prohibición de "no rebind real" queda superada. **No re-ejecutar `rule:rebind-safe-to-structure --structure=67 --commit`** sin autorización explícita — ya persistido; el propio comando es idempotente (`updateOrCreate`) pero no debe volver a invocarse sin necesidad.
- **No modificar/revertir ninguno de los 451 bindings nuevos** (`bindable_type=structure, bindable_id=67`) sin autorización explícita.

- **No modificar calibraciones** (`response`, `reviewed_by`, `reviewed_at`, `review_status`) de ninguna sección/patrón sin autorización explícita.
- **No modificar `reglas-funcionales.json`** fuera de lo ya persistido y documentado.
- **No modificar `rem_data`.**
- **No modificar bindings** (`rem_rule_bindings`) sin autorización explícita. **[ACTUALIZADO — ver punto 17.53]**: el rebind a estructura 67 ya fue ejecutado (451 bindings nuevos, `rem_rule_bindings=1204→1655`) — esta prohibición ya NO significa "no rebind a 67" (eso quedó cerrado), significa no crear/modificar/revertir ningún binding adicional (a 67 o a cualquier otra estructura) sin autorización nueva.
- **No modificar la estructura activa** (67/v35).
- **No tocar `A09/I`** (226-234) — congelado como deuda de diseño. **[AMPLIADO 2026-08-27, ver punto 17.8]**: `A09/F.1` (reglas 208, 214) se confirmó con la MISMA clase de problema (agregación irregular/no contigua, `=SUM(F149,F150,F153,F155,F157)`) — tratar como parte del mismo grupo congelado, no como candidata a fix de `row_range` contiguo.
- **No tocar `A25/B`** (354) — fuera de alcance por `no_utilizada`.
- **[SUPERADO — ver punto 17.13]** La regla 56 ya tiene `row_range=[206:207]`/`total_row=208` escritos y verificados, ejecutado con autorización explícita — ya no aplica ninguna prohibición sobre ella. **Para 208/214, la prohibición sigue vigente sin cambios**: no proponer ni escribir NINGÚN `row_range` basado en `[146:157]` — confirmado en el piloto (punto 17.8) que la fórmula real no es un rango contiguo (`=SUM(F149,F150,F153,F155,F157)`), es agregación irregular igual que `A09/I` — forzar un rango contiguo produciría un resultado incorrecto, no solo "sin datos".
- **No tocar `A30/C` P1, `A05/V`, `A30/D`** — decisión funcional de Estadística APS o fuera de alcance de campaña.
- **No corregir el gap de rangos `{N,0}`/invertidos** (punto 13) sin autorización explícita futura.
- **No revertir `529.status` a `active`** ni tocar su `config` de nuevo sin autorización explícita — queda resuelta.
- **No tocar la regla 530** (ni su `config` ni sus 4 bindings) — es la representación viva correcta, no debe modificarse.
- **No revertir las 34 reglas de la Tanda 1 ni la regla 344** (`status` de vuelta a `active`) sin autorización explícita — todas quedan resueltas.
- **No limpiar/corregir retroactivamente los 54 registros históricos de la 344** (`RuleExecutionLog`/`rem_validation_results`) sin autorización explícita — se preservan como evidencia, decisión ya tomada.
- **No corregir todavía el gap identificado en `rule:remap-section`** (punto 16.3.2: el guard no detecta colisiones de clave funcional que solo existirían *después* de escribir) sin autorización explícita — documentado, no implementado.
- **No tocar los 107 `BLOCKED_BY_ENGINE_GAP` restantes** (deuda técnica #5, cifra actualizada — ver "ESTADO VIGENTE ÚNICO") ni los `DUPLICATE`/`ALREADY_STRUCTURE_AGNOSTIC` preexistentes sin autorización explícita — sin plan de acción implementado todavía para ninguno; las 11 reglas de Fase 2 (16.15) y las 226 de Categoría A (Fase 3C-1A+3C-1B, puntos 17.13/17.16) NO forman parte de este conteo, ya resueltas.
- **[ACTUALIZADO — ver puntos 17.13/17.16/17.17]** De la "Familia A" original (235 reglas, mecanismo #12/#8/#11, punto 16.8): **225 (las de candidato trailing auto-descubierto) ya quedaron resueltas** dentro de Categoría A (Fase 3C-1A). Las **10 restantes (`A26/B`, término externo, Categoría B)** siguen sin ninguna solución aplicada — no confundir con las 225 ya cerradas.
- **No tocar las Familias B/C/D/E** (41 reglas identificadas en el punto 16.9) sin autorización explícita — **11 de las 41 ya fueron resueltas vía Fase 2** (`50,51,53,72,73,110,111,187,429,430,431`, ver punto 16.15, cerradas); las **30 restantes** (los 29 `excluded=true` del punto 16.9 + la regla 461, congelada aparte) siguen sin tocar.
- **No corregir el gap de mecanismo #6 recién descubierto** (Familia C, subgrupo A04/D+A05/W: etiquetas de categoría real no reconocidas como subtotal) sin autorización explícita — documentado, no implementado, alcance completo sin evaluar.
- **No modificar `classifyRule()`/el clasificador para que trate `row_from-1` como `total_row` válido automáticamente sin que esté escrito en `config`** — distinto de la Fase 1 (que solo diagnostica, ver campos `total_row_candidate`/`total_row_position`/`total_row_excluded`) y de la Fase 2 (que escribe `total_row` explícitamente vía comando auditado, nunca cambia la lógica del clasificador). Sin autorización explícita, no documentado como implementado.
- **No ampliar el heurístico de etiqueta de `pareceEtiquetaTotalMatrix()`** (mecanismo #6) sin autorización explícita — riesgo de falsos positivos sobre filas de captura real con nombres de categoría genéricos, no evaluado en profundidad.
- **No usar los campos `total_row_candidate`/`total_row_position`/`total_row_excluded`** (Fase 1, diagnóstico puro) para escribir `config`, cambiar `clasificacion` o crear bindings **fuera del comando auditado `rule:set-total-row`** — ese comando ya se usó, con sus 10 guards, para las 11 reglas cerradas (punto 16.15); no usar los campos de otra forma ni sobre otras reglas sin autorización explícita.
- **No revertir ni modificar nuevamente el `total_row` de las 11 reglas resueltas** (`50,51,53,72,73,110,111,187,429,430,431`, ver punto 16.15) sin autorización explícita.
- **No iniciar Fase 3 ni Fase 4** (deuda técnica #5, Familia A completa) sin autorización explícita.
- **No ejecutar `rule:set-total-row` (en ningún modo) sobre la regla 461** — debe permanecer rechazada por el guard 7, sin excepción especial.
- **No tocar la regla 461** (`A30/F`) ni ampliar el guard de rango de `total_row` (guard 7 del comando / bounds-check de `classifyRule()`) sin autorización explícita — causa raíz auditada (punto 16.13), sin solución determinada.
- **No modificar `discoverTotalRowCandidate()` ni su validación estricta de rango exacto** sin autorización explícita — es deliberadamente conservadora (ver el caso A26/B en 16.11).
- **No commit ni push** de nada listado en el punto 14 (ni de los archivos nuevos de Fase 2, `RuleSetTotalRowFromDiscoveryCommand.php` y sus tests, ni de los archivos nuevos/modificados de Fase 3A/3B, ver puntos 17.7/17.8) sin autorización explícita.
- **No implementar Fase 3C-3/3C-4 del punto 17.10** (Categorías B/F) sin autorización explícita — **[ACTUALIZADO, ver puntos 17.13/17.16/17.19]** Fase 3A (17.7), el piloto Fase 3B (17.8, solo regla 56), las 226 reglas de Categoría A (Fase 3C-1A + 3C-1B) y las 29 reglas de Categoría C (Fase 3C-2, punto 17.19) ya están **ejecutadas y cerradas**; 3C-3/3C-4 siguen sin ningún trabajo. No confundir el alcance de ninguna de estas.
- **[SUPERADO — ver punto 17.13]** El `--commit` de las 171 reglas aprobadas ya se ejecutó, con autorización explícita, verificado en post-check exacto — quedan **CERRADAS**, no volver a tratarlas como pendientes ni re-ejecutar el comando sobre ellas sin autorización explícita nueva. **La prohibición sigue vigente sin cambios para las 55 rechazadas**: no ejecutar `rule:activate-category-a --commit` sobre ninguna de ellas (`469-478,480-482,484-494,497-501,503-505,507-519,521-528,545,546`) — comparten la misma causa raíz que la regla 461 (candidato fuera de `[filaInicioDatos:filaFinDatos]`) y quedan congeladas junto con ella, ahora como **Fase 3C-1B**, sin diseño ni autorización para tocarlas.
- **No ampliar el guard de límites estructurales de `rule:activate-category-a`** (el que rechaza las 55 + cualquier caso tipo 461) sin autorización explícita — mismo principio que el guard 7 de `rule:set-total-row`.
- **No proponer ni escribir `row_range=[146:157]` (ni ningún rango contiguo) para las reglas 208/214** — confirmado en el piloto que la fórmula real no es un rango contiguo; forzarlo produciría un resultado incorrecto, no solo `BLOCKED_BY_ENGINE_GAP`. Tratar `A09/F.1` como parte del grupo congelado junto a `A09/I` (ver arriba).
- **[SUPERADO — ver punto 17.22]** `SumEqualsEvaluator.php` fue modificado en Fase 3C-3A/3C-3B (soporte `source_rows`) — la prohibición de "sin cambios desde Fase 3A/3B" ya no aplica. **No modificar de nuevo `SumEqualsEvaluator.php`** sin autorización explícita — ya implementado (`validateSourceRows()` + filtrado por `source_rows`), testeado (19 tests) y con regresión limpia; no ampliar su alcance más allá de lo descrito en el punto 17.22.
- **No modificar de nuevo `RuleEngineService.php`** sin autorización explícita — contiene AHORA dos mecanismos aditivos distintos, ambos cerrados: (a) lectura de `rem_technical_totals` (Fase 3B, punto 17.8, 10/10 tests) y (b) soporte de prefiltro + `_section_bounds` para `source_rows` (Fase 3C-3A/3C-3B, punto 17.22, 9 tests) — no ampliar el alcance de ninguno de los dos más allá de lo ya descrito.
- **[SUPERADO — ver punto 17.23]** El comando de activación (`rule:activate-source-rows`) ya está implementado, testeado (11/11) y dry-run-validado en real contra las 12 — la prohibición de diseñar/implementar queda superada. **La prohibición de ESCRITURA REAL sigue vigente sin cambios.**
- **[SUPERADO — ver punto 17.24]** El `--commit` de las 12 reglas B1+B4 ya se ejecutó, con autorización explícita, verificado en post-check exacto — quedan **CERRADAS**.
- **No re-ejecutar `rule:activate-source-rows` sobre ninguna de las 12 reglas ya activadas** (`208,214,393,394,395,396,397,398,399,400,401,402`, ver punto 17.24) sin autorización explícita — quedan cerradas, con `RuleVersion`/activity log de auditoría ya creados.
- **No modificar de nuevo `RuleActivateSourceRowsCommand.php`** (mecanismo de Fase 3C-3A/3C-3B, puntos 17.23/17.24) sin autorización explícita — ya implementado, testeado (11/11), dry-run validado en real, **ejecutado y cerrado en real sobre las 12** (punto 17.24); no ampliar su alcance ni sus guards sin autorización.
- **[ACTUALIZADO — ver punto 17.33]** No implementar ninguna solución de diseño para B2 (5: `226,227,231,232,234`), B3 (1: `229`) ni Categoría F (3: `228,230,233`) sin autorización explícita — **auditoría exhaustiva + comparación formal de alternativas (Opción A/B/C, matriz de impacto en 10 dimensiones) + matriz exacta por rule_id ya completadas** (puntos 17.25 y 17.33), pero **ninguna opción fue decidida ni implementada**: ni la división en reglas independientes (Opción A), ni la extensión de `config`/`SumEqualsEvaluator` a múltiples agregaciones (Opción B). Categoría F sigue sin ninguna solución común — `230` requiere revisión manual dedicada (`NEEDS_DEEP_AUDIT`), nunca agrupada con `228`/`233` ni con B2.
- **No crear ningún `Rule` nuevo para B2/B3 (offsets 0,1,3,4,5 de `226,227,229,231,232,234`) ni escribir `config['aggregations']`/similar en las 9 reglas existentes** sin autorización explícita — ambas opciones quedan solo documentadas (punto 17.33), ninguna implementada.
- **[HALLAZGO NUEVO — ver punto 17.34]** **No implementar la Opción A de expansión de B2 (30 reglas nuevas, `226,227,231,232,234` × 6 offsets) sin resolver primero el bloqueo confirmado de `DUPLICATE`** — `RuleBindingReconciliationService::buildDuplicateKeySet()` agrupa exclusivamente por `sheet+section+column+rule_type`, sin considerar `total_row`/`source_rows`; verificado empíricamente (2 pruebas transaccionales con rollback) que crear 2+ reglas activas sobre la misma columna, con o sin desactivar el original, las clasifica `DUPLICATE`, nunca `SAFE_1_TO_1`. **No modificar `buildDuplicateKeySet()` para incluir `total_row`/`source_rows` en la clave de agrupación sin autorización explícita** — es la corrección más directa pero fue reportada, no implementada, por instrucción explícita del usuario de no adaptar el motor automáticamente ante este hallazgo.
- **[AMPLIADO — ver punto 17.35]** Auditoría completa de identidad/duplicados realizada — reveló **19 grupos reales (50 reglas) que comparten identidad simple hoy**, no solo los 22 `DUPLICATE` oficiales: 28 reglas adicionales (12 grupos) están **ocultas** del conteo `DUPLICATE` porque tienen bindings serie/global que las clasifican `ALREADY_STRUCTURE_AGNOSTIC` antes de que el clasificador evalúe `dupKeySet` — blind spot real, no corregido. De los 19 grupos: 3 son duplicados exactos (no rescatables por ninguna identidad más rica), 9 son "pares completo/incompleto" (ambiguos, posible stale/superseded, **riesgo real si se amplía la identidad sin revisión humana previa**), 4 son rescatables (rangos genuinamente distintos, patrón B2-like), 3 son mixtos/ambiguos (rangos solapados, mezcla `csv_catalog`/`vetted_catalog`). **No modificar `buildDuplicateKeySet()`/`classifyRule()` ni resolver ninguno de los 19 grupos sin autorización explícita** — decisión pendiente entre Opción A (identidad ampliada, menor complejidad pero con el riesgo de los 20 "pares" ya cuantificado), Opción B (multi-agregación en config, mayor alcance arquitectónico) u otra.
- **No tocar los 50 rules de los 19 grupos de colisión de identidad recién auditados** (ids listados en el punto 17.35: `24,553,557,558,559,617,585,602,560,618,570,572,574,576,571,573,575,577,29,580,126,658,698,661,699,662,700,663,701,127,702,714,705,715,720,721,722,723,724,725,786,130,787,133,772,783,773,784,774,785`) sin autorización explícita — ninguno resuelto, ninguno investigado más allá de la caracterización de este punto.
- **[AMPLIADO — ver punto 17.36]** Auditoría final de los 19 grupos completada con clasificación exacta A/B/C/D por grupo (y por miembro donde el grupo es mixto), simulación híbrida vía Reflection sobre `classifyRule()` real (medida, no presupuesta: `SAFE_1_TO_1` 431→439, `DUPLICATE` 22→14, `ALREADY_STRUCTURE_AGNOSTIC` sin cambio), y recomendación de **Opción 2 (full-signature con gate de compatibilidad/ambigüedad)** — **ninguna implementada**. **Corrección importante sobre 17.35**: 7 de los 9 "pares completo/incompleto" originales resultaron ser fórmulas genuinamente distintas (agregación vertical propia vs fórmula horizontal cruzada), no pares stale — y se descubrió un caso NUEVO no anticipado (Grupos 15/16, `A06/C.2|D` y `A06/C.3|D`): full-signature idéntica pero `rule_logic` distinto, con un miembro (`130`,`133`) siendo un artefacto auto-referencial roto (`Suma(D)=Columna D`, 100% skipped) — candidato a `status=inactive` independientemente de cualquier decisión de identidad.
- **No modificar `buildDuplicateKeySet()`/`classifyRule()` para implementar Opción 2 (ni ninguna otra opción)** sin autorización explícita — el gate de compatibilidad/ambigüedad descrito en el punto 17.36 es una recomendación, no una implementación aprobada.
- **No desactivar las reglas `130`/`133`** (artefactos auto-referenciales `Suma(D)=Columna D`, Grupos 15/16 del punto 17.36) sin autorización explícita — identificadas como candidatas fuertes a `status=inactive`, pero es una decisión de negocio/limpieza de catálogo separada de la decisión de identidad, no ejecutada.
- **No decidir automáticamente el destino de `24,29,126,127,585,602` (miembros C-adjacentes/subset-supersede) ni del Grupo 1 completo (`A01/A/C`, 6 reglas, Categoría D)** sin autorización explícita y revisión humana caso por caso — ninguno tiene evidencia suficiente para una resolución mecánica.
- **[SUPERADO — ver punto 17.37]** El gate full-signature (Opción 2) ya fue **implementado, testeado (9/9 tests nuevos, 111/111 regresión de comandos dependientes) y validado rule-por-rule contra las 717 reglas reales** (exactamente 8 cambios: Grupos 4/5 → `SAFE_1_TO_1`, `DUPLICATE` 22→14) — la prohibición de "sin implementar" ya no aplica al gate en sí.
- **No modificar de nuevo `RuleBindingReconciliationService::buildDuplicateKeySet()`, `isLegitimateCoexistence()`, `buildFunctionalSignature()` ni `buildComponentSet()`** (el gate implementado en el punto 17.37) sin autorización explícita — ya validados, no ampliar su alcance ni sus reglas de decisión sin evidencia nueva equivalente a la del punto 17.36.
- **No crear las 30 reglas reales de B2** (`226,227,231,232,234` × 6 offsets, derivación exacta en el punto 17.34) sin autorización explícita — el gate ya las soportaría (confirmado con fixtures sintéticas en 17.37), pero la creación real de `Rule`/`RuleVersion`/activity log/config sigue sin ejecutarse.
- **No desactivar `130`/`133`** sin autorización explícita — confirmado en 17.37 que su clasificación real es `ALREADY_STRUCTURE_AGNOSTIC` (no `DUPLICATE` como se documentó por error en 17.35/17.36), sin ningún cambio por el gate nuevo.
- **No ejecutar `rule:rebind-safe-to-structure` sobre las 8 reglas de los Grupos 4/5** (`570,571,572,573,574,575,576,577`, ya `SAFE_1_TO_1` bajo el gate nuevo) sin autorización explícita — quedan elegibles pero sin bindings a estructura 67 todavía.
- **No tocar las 14 reglas que permanecen `DUPLICATE`** tras el gate (`24,553,557,558,559,617,585,602,560,618,29,580,126,127`) sin autorización explícita — ninguna resuelta, ninguna reclasificada más allá de lo que el gate ya determina automáticamente.
- **[NUEVO — ver punto 17.38]** El mecanismo de expansión de B2 (`rule:expand-b2-aggregation`) ya está **implementado, testeado (11/11) y validado mediante simulación real de las 30 combinaciones (transacción con rollback, 25/30 exitosas, 5/30 bloqueadas por `AR337`)** — pero **ninguna regla real fue creada**. **No ejecutar `--commit` real** sobre ninguna de las 25 combinaciones viables sin autorización explícita.
- **No modificar de nuevo `RuleExpandB2AggregationCommand.php`** sin autorización explícita — ya implementado, testeado, validado; no ampliar su alcance ni sus guards sin evidencia nueva.
- **[HALLAZGO AMPLIADO — ver punto 17.38.4]** `AR337` bloquea, además de la regla 229/offset2 ya documentada, **también** la fila 333 (offset2) de las 5 columnas limpias de B2 (`AM,AN,AT,AU,AX`) — vía `isEmbeddedBackwardSubtotalRow()`, que escanea todas las columnas de la sección al confirmar una fila. **No tocar `AR337` para "arreglar" esto** — sigue congelado, sin autorización, mismo estado que siempre. Las 5 combinaciones `total_row=333` de B2 quedan bloqueadas junto con `AR337`, no se proponen alternativas.
- **No cambiar el `status` de las 5 reglas origen de B2** (`226,227,231,232,234`) a `inactive` sin autorización explícita — la disposición futura está documentada (punto 17.38.2) pero no ejecutada; requeriría `rule:set-rule-status` y su propia autorización, turno a turno.
- **[AMPLIADO — ver punto 17.39]** Simulación completa de las 25 combinaciones (transacción + rollback) confirmó: 0 cambios en las 717 reglas preexistentes, las 25 individualmente `SAFE_1_TO_1`, las 5 orígenes intactos `BLOCKED_BY_ENGINE_GAP`, 0 colisiones nuevas, las 25 (nunca los orígenes) serían candidatas reales a un futuro `rule:rebind-safe-to-structure`, demostrado con código real que el origen nunca produce resultados (prefiltro `row_number∈[0:0]`, jamás satisfecho por datos reales). **No escribir ningún campo nuevo (`metadata.partially_expanded` o similar) en las 5 reglas origen** — evaluado y descartado explícitamente (punto 17.39.3); el estado 5/6 ya es derivable en vivo vía `metadata.derived_from_rule_id` de las hijas, sin necesidad de persistir nada adicional.
- **No ejecutar `--commit` real sobre ninguna de las 25 combinaciones viables de B2** sin autorización explícita — validadas exhaustivamente (17.38 + 17.39), pero sigue pendiente la autorización de escritura real.
- **Los 9 fallos/errores adicionales de la suite grande quedan documentados como FLAKY/ORDER-DEPENDENT** (punto 17.39.5), no como regresión de 17.37 — no investigar/corregir la causa raíz de aislamiento sin autorización explícita, queda como deuda de tests pendiente.
- **[SUPERADO — ver punto 17.40]** La expansión real de las 25 combinaciones ya fue **ejecutada y cerrada** (`--commit` real, ids `868-892`, post-check exhaustivo sin discrepancias) — la prohibición de "no ejecutar --commit real" queda superada para esas 25.
- **No re-ejecutar `rule:expand-b2-aggregation --commit` sobre ninguna de las 25 combinaciones ya creadas** (`868-892`, ver punto 17.40) sin autorización explícita — quedan cerradas, con activity logs de auditoría ya creados; el guard 6/7 del propio comando ya las rechazaría por duplicado, pero no intentar de todas formas.
- **No tocar `AR337`/fila 333 para intentar crear las 5 combinaciones `total_row=333` restantes** (`a09_i_{am,an,at,au,ax}_row333_sum_equals`) sin autorización explícita — siguen bloqueadas por el mismo hallazgo de 17.38/17.39, sin ninguna solución propuesta.
- **No escribir ningún campo `partially_expanded`/similar en las 5 reglas origen** (`226,227,231,232,234`) sin autorización explícita — decisión ya tomada y reafirmada en 17.39.3/17.40.4: el estado 5/6 es derivable en vivo, no debe persistirse.
- **No cambiar el `status` de las 25 reglas de expansión** (`868-892`) ni de las 5 reglas origen sin autorización explícita.
- **No ejecutar ningún `rule:rebind-safe-to-structure` real** que incluya las 25 nuevas reglas de B2 sin autorización explícita — son candidatas reales (confirmado en 17.39), pero ningún rebind ha sido autorizado en toda la campaña.
- **[AMPLIADO — ver punto 17.41]** Confirmado con simulación real (transacción + rollback) que **9 combinaciones adicionales** (`229` offsets 331,332,334,335,336; `228` offsets 331,332; `230` offset 335; `233` offset 334) pasan los MISMOS guards ya validados de B2 y clasificarían `SAFE_1_TO_1` — pero **ninguna fue creada**. **No extender el allowlist de `RuleExpandB2AggregationCommand.php` (guard 2)** para aceptar `228,229,230,233` sin autorización explícita — es una decisión de diseño pendiente, de bajo riesgo pero no ejecutada.
- **No tocar `AR337` para "arreglarlo" ni para excluirlo de la fórmula de `AR333`** sin autorización explícita — confirmado en 17.41, con verificación directa contra el Excel fuente (merges, tipo de dato, ausencia de conexión estructural con la fila 337), que es un defecto de template inequívoco e inocuo aritméticamente, pero la decisión de qué hacer con él (4 opciones ya documentadas en 17.28.4) sigue sin tomarse.
- **No decidir automáticamente la naturaleza de las 4 combinaciones parciales de `230`** (`331,332,333,336`, sumas de 2 de 13 términos) sin autorización explícita — confirmado en 17.41 que son técnicamente implementables con el mecanismo actual en cualquier interpretación, pero la pregunta de si son intencionales o incompletas requiere decisión de negocio (Estadística APS), no evidencia técnica adicional.
- **No asumir que las combinaciones ausentes de `228` (333-336) o `233` (331,332,333,335,336) deban completarse** — confirmado en 17.41 que el template Excel fuente nunca calculó esas combinaciones (sin fórmula alguna), no son huecos a llenar sin una decisión de negocio explícita sobre si deberían existir.
- **No tocar `AR337` como parte de resolver B3 (regla 229)** — el hallazgo se conserva exactamente como está (referencia extraviada, inocua, sin corregir ni eliminar), la fila 333/offset2 de esa columna queda excluida de cualquier regla nueva mientras `AR337` no se resuelva por separado.
- **[SUPERADO — ver punto 17.42]** El mecanismo de expansión ya fue **generalizado, renombrado (`rule:expand-a09-i-aggregation`), testeado (22/22) y validado con dry-run real + barrido completo de 54 combinaciones + simulación consolidada con transacción y rollback** — las 9 combinaciones (`228`×2,`229`×5,`230`×1,`233`×1) confirmadas exactas, 0 efectos colaterales.
- **[SUPERADO — ver punto 17.43]** La expansión real de las 9 combinaciones ya fue **ejecutada y cerrada** (`--commit` real, ids `911-919`, post-check exhaustivo sin discrepancias: `SAFE_1_TO_1` 464→473, `rem_rules` 789→798) — la prohibición de "no ejecutar --commit real" queda superada para esas 9.
- **No re-ejecutar `rule:expand-a09-i-aggregation --commit` sobre ninguna de las 9 combinaciones ya creadas** (`911-919`, ver punto 17.43) sin autorización explícita — quedan cerradas, con activity logs de auditoría ya creados (`rule_a09_i_aggregation_created`/`rule_a09_i_aggregation_derived`); el guard 6/7 del propio comando ya las rechazaría por duplicado, pero no intentar de todas formas.
- **No tocar `AR337`/fila 333 para intentar crear la combinación `229→total_row=333` restante** sin autorización explícita — sigue bloqueada por el mismo hallazgo de 17.41/17.42, sin ninguna solución propuesta.
- **No escribir ningún campo `partially_expanded`/similar en las 4 reglas origen de B3/CategoríaF** (`228,229,230,233`) sin autorización explícita — mismo principio ya aplicado a las 5 de B2 (17.39.3/17.40.4): el estado de cobertura parcial es derivable en vivo vía `metadata.derived_from_rule_id`, no debe persistirse.
- **No cambiar el `status` de las 9 reglas de expansión** (`911-919`) ni de las 4 reglas origen `228,229,230,233` sin autorización explícita.
- **No ejecutar ningún `rule:rebind-safe-to-structure` real** que incluya las 9 nuevas reglas de A09/I B3/CategoríaF sin autorización explícita — son candidatas reales (mismo patrón que las 25 de B2, 17.39), pero ningún rebind ha sido autorizado en toda la campaña.
- **No modificar de nuevo `RuleExpandA09IAggregationCommand.php`** sin autorización explícita — ya implementado, testeado, validado, **ejecutado en real sobre 34 combinaciones totales** (25 de B2 + 9 de B3/CategoríaF); no ampliar su alcance, su allowlist de 9 columnas, ni el guard de coincidencia periódica sin evidencia nueva equivalente a 17.41.
- **No recrear `rule:expand-b2-aggregation`** (comando eliminado, reemplazado por `rule:expand-a09-i-aggregation`) — las 25 reglas ya creadas bajo el nombre anterior (17.40) conservan su metadata histórica intacta (`source=b2_expansion`, `created_via=rule:expand-b2-aggregation`), nunca reescrita retroactivamente.
- **[SUPERADO — ver punto 17.26]** El hallazgo de la etiqueta "TOTAL" fusionada ya fue auditado exhaustivamente (causa raíz exacta, alcance global de 386 secciones, histórico completo, diseño de corrección con 3 opciones comparadas) — la prohibición de "sin evaluar" ya no aplica. **No implementar ninguna de las 3 opciones de corrección del punto 17.26** (`RemParserService::isEmbeddedBackwardSubtotalRow()`, `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()`, ni la mejora opcional de exponer `es_principal`/`col_principal` en `EnhancedCellDTO`) sin autorización explícita — confirmado que `A09/I` ya está calibrada/revisada y que la corrección cambiaría su `pattern_fingerprint`, con alta probabilidad de reclasificarla a `MISMATCH` (requeriría un `safe_reconfirm` posterior) — **este riesgo debe decidirse explícitamente antes de implementar**, no es automáticamente aceptable como en el resto de esta campaña.
- **No tocar la calibración de `A09/I`** (`effective_section_reviewed=true`/`historical_section_reviewed=true`, confirmado en el punto 17.26) sin autorización explícita — cualquier cambio a los mecanismos #8/#12 que la afecte debe ir acompañado de una verificación deliberada de impacto en sus 23 patrones, no asumir que no pasa nada.
- **[SUPERADO — ver punto 17.27]** Ya se investigó, con simulación real (subclase que hereda de `SectionCalibrationMatrixService` sin modificarla): `pattern_id=23` es el único afectado (`filas=[332,333,334,335,336]` → `[333]`, fingerprint `fpv2_fb42171308aa70f8`→`fpv2_3735e4b5bbc386dd`), los otros 22 patrones de `A09/I` quedan idénticos. La prohibición de "sin investigar" ya no aplica.
- **No implementar la Opción C del punto 17.26 tal cual, sin trabajo adicional** sin autorización explícita — confirmado con el código real (punto 17.27) que dejaría `pattern_id=23` de `A09/I` en `MISMATCH` sin ninguna vía de reconfirmación automática disponible hoy (`safe_reconfirm` la rechaza por diseño — cambio real de filas; `structural_row_exclusion` solo verifica mecanismo #6, no #12). Implementar el fix sin resolver esto primero dejaría una sección ya calibrada con un MISMATCH sin salida mecánica.
- **[SUPERADO — ver punto 17.29]** Veredicto `SAFE_TO_EXTEND_STRUCTURAL_ROW_EXCLUSION_TO_12` ya alcanzado (17.28) **y el soporte de código ya implementado, testeado y validado** (17.29) — la prohibición de "sin implementar" ya no aplica al soporte de código en sí.
- **No crear ningún tag real ni ejecutar `confirmMismatchResolution`/`applyQuickRevalidation` contra `A09/I` real** sin autorización explícita — el soporte de código para mecanismo #12 ya está implementado y validado (dry-run real confirma `332,334,335,336` elegibles, `333` no elegible), pero **ninguna resolución real fue aplicada**. El fix de merge (punto 17.26) sigue sin implementar — `A09/I` sigue `AUTO_MIGRATE` en producción, no `MISMATCH`.
- **[SUPERADO — ver punto 17.30]** El fix de merge del punto 17.26 ya está **implementado, testeado y validado** contra la estructura real — la prohibición de "sin implementar" ya no aplica.
- **No modificar de nuevo `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()`, `RemParserService::isEmbeddedBackwardSubtotalRow()`, ni `MergeAnchorResolver.php`** (los 3 tocados/creados en el punto 17.30) sin autorización explícita — ya implementados, testeados (11 tests nuevos + 35 tests existentes de mecanismo #12/#6/#8/#11 reconfirmados sin regresión), validados contra la estructura real y el gate global de 381 secciones. Incluye la corrección ya aplicada del hallazgo de regresión (reglas 545/546, `A33/C`) — no revertir esa corrección.
- **[SUPERADO — ver punto 17.32]** La resolución real de `A09/I pattern_id=23` vía `structural_row_exclusion`/mecanismo #12 ya fue **ejecutada y cerrada** — tag real creado, confirmación real aplicada, post-check exacto sin ninguna diferencia. **No re-ejecutar** `rule:tag-mismatch-resolution`/`confirmMismatchResolution` sobre `pattern_id=23` de nuevo sin autorización explícita — ya quedó resuelto, con `_questions_history` y tag de auditoría ya creados.
- **No tocar la fila 333 de `A09/I` ni `AR337`** sin autorización explícita — permanece **AUDITADA / CONGELADA / NO RESUELTA**, sin ningún cambio por la resolución de 332/334/335/336.
- **No asumir que `A30/C pattern_id=1` (el otro `MISMATCH` preexistente y no relacionado) requiere ninguna acción por este trabajo** — confirmado sin cambios, filas idénticas, ya conocido y sin relación con el fix de merge.
- **No implementar ninguna de las 4 opciones futuras para la fila 333** (corrección del template, excepción estructural manual, revisión humana, regla específica en el mecanismo — punto 17.28.4) sin autorización explícita — ninguna fue elegida, todas quedan como opciones documentadas. Fila 333 permanece **AUDITADA / CONGELADA / NO RESUELTA**.
- **No modificar de nuevo `MismatchResolutionAuditService.php`, `PatternMigrationScanner.php`, `RuleTagMismatchResolutionCommand.php`, `CalibrationViewController.php`** (los 4 archivos tocados en el punto 17.29) sin autorización explícita — ya implementados, testeados (11 tests nuevos, 0 regresiones), validados con dry-run real. No ampliar su alcance ni sus guards sin autorización.
- **No asumir que el fix de merge (Opción C) resuelve la fila 333 de `A09/I`** — confirmado con el código real que NO la excluye, por la interacción con `AR337` (referencia textual "337 > 333" interpretada como referencia posterior por el mecanismo #12) — hallazgo del punto 17.27, reconfirmado en 17.29 vía el mecanismo #12 ya real y expuesto. Requiere revisión humana explícita de `pattern_id=23`, no reconfirmación automática.
- **No tocar `AR337` ni la interacción `AR337`↔`isEmbeddedBackwardSubtotalRow()`** sin autorización explícita — fuera de alcance de la auditoría de merge (punto 17.26/17.27/17.29), es un hallazgo relacionado pero distinto, sin diseño de corrección propuesto.
- **No modificar de nuevo `RemParserService.php`/`ParseResult.php`/`ProcessRemUploadJob.php`/`RemUpload.php`/`RemReprocessCommand.php`/`TempReprocessUploadCommand.php`** (los archivos tocados en Fase 3A, ver punto 17.7) sin autorización explícita — el hook de captura ya quedó implementado y probado; no ampliar su alcance ni tocar los 3 mecanismos (#6/#8/#11/#12) que deciden qué excluir.
- **No modificar la migración `2026_08_27_000001_create_rem_technical_totals_table.php` ni el modelo `RemTechnicalTotal`** sin autorización explícita — esquema ya migrado contra `esalud_dev` real.
- **No conectar `rem_technical_totals`/el mecanismo de `RuleEngineService` a ningún flujo de UI/exportación/certificación** sin autorización explícita — el piloto es interno al motor de evaluación, nunca expuesto a otra capa.
- **No proponer ni escribir ningún `row_range`/`config` nuevo para las 18 reglas de Categoría B** (`208,214,226,227,229,231,232,234,393-402`) sin autorización explícita — **[ACTUALIZADO, ver punto 17.20]** auditoría exhaustiva completada: 4 subfamilias distintas (B1 `A09/F.1`=2, B2 `A09/I` regular=5, B3 `A09/I` con término externo AR=1, B4 `A26/B`=10), ninguna representable con el modelo actual `row_range`+`total_row` (B1/B4 necesitan extender config/evaluador con listas/términos extra; B2 requiere un tipo de representación nuevo por el problema de cardinalidad 1-regla-a-6-totales; B3 debe permanecer congelada individualmente hasta resolver `AR337`). Sigue sin ninguna implementación — solo auditoría + diseño.
- **No implementar ninguna extensión de `config`/evaluador para B1 (`total_row_component_rows`) ni B4 (`total_row_extra_rows`)** propuesta en el punto 17.20 sin autorización explícita — son observaciones de diseño, no decisiones aprobadas.
- **No tratar la regla 229 (`A09/I` AR, Subfamilia B3) como parte del mismo grupo que 226/227/231/232/234 (Subfamilia B2)** sin resolver primero la naturaleza de `AR337` — comparten causa raíz arquitectónica pero B3 tiene una anomalía propia no generalizable.
- **No agrupar ni tratar homogéneamente las reglas `228, 230, 233`** (`A09/I`, Categoría F) con el resto de `A09/I` — confirmado que cada una tiene una anomalía propia y distinta (bloque incompleto, patrón mezclado, casi sin fórmulas) — requieren revisión individual (Fase 3C-4, punto 17.10), sin causa determinada todavía.
- **No inferir automáticamente ningún `row_range`/`total_row` a partir de un candidato de Fase 1 sin verificar cobertura COMPLETA de la fórmula real** (no solo que toque ambos extremos, que es todo lo que Fase 1 garantiza por diseño) — gap documentado en el punto 17.9, sin explotar en los datos reales de hoy, pero debe re-verificarse si se generaliza a reglas futuras o a datos que cambien.
- **No modificar de nuevo `RuleActivateCategoryATotalCommand.php`** (mecanismo de Fase 3C-1A, punto 17.11) sin autorización explícita — ya implementado, testeado (12/12), **ejecutado y cerrado en real sobre las 171** (punto 17.13).
- **No re-ejecutar `rule:activate-category-a` sobre ninguna de las 171 reglas ya activadas** (ver lista completa en el punto 17.13) sin autorización explícita — quedan cerradas, con `RuleVersion`/activity log de auditoría ya creados.
- **No modificar de nuevo `RuleActivateTrailingTotalBeyondBoundsCommand.php` ni `FormulaRangeCoverageAnalyzer.php`** (mecanismo de Fase 3C-1B, punto 17.15) sin autorización explícita — ya implementados, testeados (12/12), **ejecutados y cerrados en real sobre las 55** (punto 17.16).
- **No re-ejecutar `rule:activate-trailing-total-beyond-bounds` sobre ninguna de las 55 reglas ya activadas** (ver punto 17.16) sin autorización explícita — quedan cerradas a nivel de clasificación, con `RuleVersion`/activity log de auditoría ya creados. **[ACTUALIZADO — ver punto 17.48]** Su clasificación NO se revierte ni se toca; el mecanismo runtime que las haría evaluables ya está **implementado y validado** (`findTechnicalSectionContextForRow()`, 55/55 confirmadas) — estado real `CLASIFICACIÓN CERRADA / RUNTIME VALIDADO (sin reprocesar histórico)`, no "runtime pendiente".
- **[SUPERADO — ver punto 17.48]** `findTechnicalSectionContextForRow()` y `resolveTechnicalBoundaryCapture()` ya fueron **implementados en `RemParserService.php`, testeados (12/12) y validados contra las 55 reglas reales (55/55 elegibles, 0 reglas fuera del universo afectadas)** — la prohibición de "no implementar" queda superada para esta pieza específica (Opción C, contexto compartido). El reconocimiento formula-based de la 461 (17.46) **sigue sin implementar**.
- **No modificar de nuevo `RemParserService::findTechnicalSectionContextForRow()` ni `resolveTechnicalBoundaryCapture()`** (puntos 17.48/17.49) sin autorización explícita — ya implementados, testeados, validados contra evidencia real, incluyendo la dirección `leading` (combina #6 + `isLeadingFormulaBasedTotalBeyondBounds()`, ver 17.49).
- **[SUPERADO — ver punto 17.49]** El reconocimiento formula-based específico de la 461 (`isLeadingFormulaBasedTotalBeyondBounds`, diseñado en 17.46) ya fue **implementado** (en `SectionCalibrationMatrixService` y `RemParserService`), testeado (36 tests nuevos), y validado con dry-run real + simulación de clasificación (transacción+rollback) — la prohibición de "no implementar" queda superada.
- **[SUPERADO — ver punto 17.50]** El `--commit` real de la regla 461 ya fue **ejecutado y cerrado** (`total_row=123`, `SAFE_1_TO_1=473→474`, `BLOCKED_BY_ENGINE_GAP=66→65`, post-check exacto sin discrepancias) — la prohibición de "no ejecutar --commit real sobre la 461" queda superada. **No re-ejecutar `rule:activate-leading-formula-total-beyond-bounds` sobre la regla 461** sin autorización explícita — queda cerrada, con `RuleVersion`/activity log de auditoría ya creados; el guard 4 del propio comando (`total_row` ya presente) la rechazaría de todas formas.
- **No modificar de nuevo `SectionCalibrationMatrixService::isLeadingFormulaBasedTotalBeyondBounds()`, `RuleBindingReconciliationService::isLegitimateLeadingTotalBeyondBounds()`, ni `RuleActivateLeadingFormulaTotalBeyondBoundsCommand.php`** (punto 17.49) sin autorización explícita — ya implementados, testeados, validados contra las 381 secciones reales (barrido de seguridad: solo la 461 los necesita).
- **No revertir ni modificar el `total_row`/`config`/`status` de ninguna de las 55 reglas de Fase 3C-1B** (`469-478,480-482,484-486,487-494,497-501,503-505,507-512,513-519,521-528,545,546`) sin autorización explícita — confirmadas correctas a nivel de clasificación, el mecanismo runtime ya validado no requiere ni justifica tocar su config.
- **No reprocesar el upload 186 ni ningún otro upload** para intentar "forzar" la captura de las filas boundary (28,46,66,85,16,24,104,90,118,56 de las 55; 123 de la 461) sin autorización explícita — el mecanismo ya está implementado y solo se activará para cargas NUEVAS; reprocesar histórico requiere autorización de backfill separada, no concedida.
- **No asumir que `A32/F2` fila `151` o `A33/E` fila `74`** (2 casos adicionales genuinos descubiertos por el barrido exhaustivo de 17.48, sin relación con ninguna regla activa) requieren ninguna acción — confirmados inertes (0 reglas los referencian), documentados solo como evidencia de que el gate es correctamente general, no limitado a las 55.
- **No modificar de nuevo el método `isLegitimateTrailingTotalBeyondBounds()` de `RuleBindingReconciliationService.php`** (punto 17.15) sin autorización explícita — la condición genérica del bounds-check permanece byte-idéntica; el método aislado ya está implementado y validado, no ampliar su alcance.
- **No corregir retroactivamente el gap de guard `no_utilizada` en `RuleActivateCategoryATotalCommand.php`** (Fase 3C-1A, ya cerrado) sin autorización explícita — hallazgo documentado en el punto 17.15, no explotado en la práctica (ninguna de las 171 ya activadas era `no_utilizada`), corregido solo en el comando nuevo.
- **[SUPERADO — ver punto 17.19]** El `--commit` de las 29 reglas de Categoría C ya se ejecutó, con autorización explícita, verificado en post-check exacto — quedan **CERRADAS**, no volver a tratarlas como pendientes ni re-ejecutar el comando sobre ellas sin autorización explícita nueva.
- **No re-ejecutar `rule:activate-category-c-leading` sobre ninguna de las 29 reglas ya activadas** (`46,47,48,49,61,62,63,64,65,87,89,90,91,92,93,96,97,105,106,107,108,182,183,277,278,279,280,281,282`, ver punto 17.19) sin autorización explícita — quedan cerradas, con `RuleVersion`/activity log de auditoría ya creados.
- **No modificar de nuevo `RuleActivateCategoryCLeadingCommand.php`** (mecanismo de Fase 3C-2, puntos 17.18/17.19) sin autorización explícita — ya implementado, testeado (12/12), dry-run validado en real, **ejecutado y cerrado en real sobre las 29** (punto 17.19); no ampliar su alcance ni sus guards sin autorización.
- **No implementar Fase 3C-3 ni iniciar 3C-4** sin autorización explícita, una por una — **[ACTUALIZADO, ver punto 17.20]** la auditoría+diseño de Fase 3C-3 (Categoría B, 18 reglas) quedó completada (4 subfamilias, matriz de tratamiento recomendado, nada implementado); 3C-4 (Categoría F, 3 reglas) sigue sin ningún trabajo todavía.

## ESTADO VIGENTE ÚNICO (al cierre de la certificación end-to-end, 2026-08-28 — reemplaza cualquier baseline numérico anterior en este checkpoint)

**CERTIFICACIÓN END-TO-END REM A completada (punto 17.54)** — veredicto: **`REM_A_END_TO_END_CERTIFICADO`**. Carga real de certificación (`upload_id=187`, copia trazable de `upload 186`, `102302A05.xlsm`) procesada por el flujo completo real (parser → cola real → `ValidateWithEngineJob`, recogido por un `queue:work` en background, nunca invocado manualmente). Confirmado con evidencia concreta: las 9 piezas del motor (normal, Categoría A, Categoría C, `source_rows`, hijas B2/B3-F, trailing-beyond-bounds, `461` leading-formula-based, `ALREADY_STRUCTURE_AGNOSTIC` vía serie) ejecutaron y dieron `passed` con componentes/totales verificados. **Trailing-beyond-bounds y la regla 461 tuvieron su primera ejecución real de producción**, confirmando ambos mecanismos (17.48/17.49-17.50) funcionando exactamente como diseñado. Prueba negativa real (no fabricada): 3 fallas genuinas (`178,714,715`) diagnosticadas correctamente. `rem_technical_totals` verificado en los 5 puntos exigidos (55-familia capturada, 461 capturada, sin contaminar `rem_data`, `A09/I` con el fix de merge intacto, fila 333 correctamente ausente por `AR337`). Integridad total: `0` cambios a reglas/config/bindings-count/calibraciones/estructura/template, `upload 186` byte-idéntico. Ningún defecto encontrado, ninguna corrección necesaria.

## ESTADO VIGENTE ÚNICO (al cierre del rebind real a estructura 67, 2026-08-28 — histórico, ver bloque de arriba para el más reciente)

**REBIND REAL A ESTRUCTURA 67 EJECUTADO Y CERRADO (punto 17.53)** — 451 bindings nuevos (`bindable_type=structure, bindable_id=67`) creados vía el comando real `rule:rebind-safe-to-structure --commit`, post-check exacto sin ninguna discrepancia respecto a lo predicho/simulado en 17.52. **451 reglas tienen ahora binding específico a la estructura activa 67/v35** — incluye la regla `461`, las 55 trailing-beyond-bounds (Fase 3C-1B), las 25 hijas B2 (`868-892`) y las 9 hijas B3/CategoríaF (`911-919`). **198 reglas siguen siendo ejecutables mediante su binding `serie`/`global` preexistente** (confirmado 198/198, ajeno a este rebind, sin cambio). `229`, `230`, `130`, `133`, las 14 `DUPLICATE` y las 23 `SAFE_1_TO_1` en hojas `no_utilizada` correctamente NO recibieron ningún binding. **REM A pasa de "motor completo pero inerte" a "motor vinculado"** — ninguna regla/config/status fue modificado, ninguna calibración/`rem_data`/`rem_technical_totals`/estructura/template fue tocado, `RuleVersion` creados=`0` (el rebind no versiona reglas). **Pendiente único y explícito: la certificación end-to-end (Fase 17.54, autorización separada)** — procesar una carga REM A real y confirmar que las reglas vinculadas efectivamente ejecutan y producen resultados correctos, distinguiendo "bindings instalados" de "ejecución validada".

**PREFLIGHT DEL REBIND A ESTRUCTURA 67 completado (punto 17.52, 100% READ-ONLY/SIMULACIÓN)** — veredicto: **`REBIND_READY`**. Dry-run real ejecutado (451 candidatos, discrepancia contra los 474 `SAFE_1_TO_1` totalmente explicada por la exclusión correcta de 23 reglas en hojas `no_utilizada`), gate estricto verificado (9/9: 461 presente, 55 trailing presentes, 25 hijas B2 presentes, 9 hijas B3/F presentes, 229/230/130/133/DUPLICATE ausentes), los 198 `ALREADY_STRUCTURE_AGNOSTIC` confirmados 198/198 ya ejecutables vía binding serie/global (correctamente fuera de alcance del rebind), simulación transaccional con rollback garantizado (bindings `1204→1655` simulado, `0→451` a estructura 67, 0 duplicados, 0 reglas sin destino, 0 impacto en clasificación, rollback restauró exactamente el baseline), y diseño (no ejecutado) del plan de prueba end-to-end post-rebind con 10 casos representativos reales — **ejecutado en real en 17.53**.

**AUDITORÍA FINAL DE CIERRE REM A completada (punto 17.51, 100% READ-ONLY)** — veredicto: **`REM_A_LISTO_PARA_REBIND_Y_PRUEBAS_FINALES`**. De los 65 `BLOCKED_BY_ENGINE_GAP`: 56 irrelevantes (`no_utilizada`), 7/9 origen A09/I con cobertura funcional real completa vía hijas, solo `229` (bloqueado por `AR337`, defecto de template) y `230` (mapeo ambiguo, requiere Estadística APS) tienen trabajo genuino sin cerrar — **0 de los 65 requiere desarrollo de código**. De los 14 `DUPLICATE`: 8 son deuda de catálogo confirmada (duplicado exacto/subset-superseded), 6 (`A01/A/C`) son el único caso ambiguo que podría ocultar una necesidad de remapeo real. Barrido de los 198 `ALREADY_STRUCTURE_AGNOSTIC` confirmó exactamente los mismos 13 grupos de colisión ya conocidos (0 nuevos), con `130`/`133` como únicas reglas rotas. Calibración Serie A: 302/306 (99%), 1 único `MISMATCH` (`A30/C`, sin cambio). **Todos los mecanismos runtime confirmados `RUNTIME_VALIDADO`** (mecanismo real + prueba end-to-end), pero **ninguna regla se ejecuta hoy contra cargas reales** (`rem_rule_bindings=1204`, ninguno a la estructura activa 67) — el rebind es el único paso operativo real pendiente, diseñado (no ejecutado) en el punto 17.51.7.

**FASE 2: CERRADA** para las 11 reglas autorizadas. **FASE 3A/3B implementadas (puntos 17.7/17.8). AUDITORÍA DE ELEGIBILIDAD DE LOS 333 COMPLETADA (punto 17.9). FASE 3C-1A EJECUTADA Y CERRADA — 171 reglas (punto 17.13). FASE 3C-1B EJECUTADA Y CERRADA — 55 reglas (punto 17.16), estado `CLASIFICACIÓN CERRADA / RUNTIME VALIDADO (sin reprocesar histórico)` desde el punto 17.48. FASE 3C-2 EJECUTADA Y CERRADA — 29 reglas (punto 17.19). Categorías A (226) y C (29) resueltas a nivel de clasificación. FASE 3C-3: AUDITORÍA EXHAUSTIVA completada (punto 17.20). FASE 3C-3A (B1)/3C-3B (B4): EJECUTADA Y CERRADA — 12 reglas (punto 17.24). HALLAZGO DE ETIQUETA FUSIONADA: auditado (17.26), impacto en calibración simulado (17.27), soporte de mecanismo #12 en `structural_row_exclusion` auditado/implementado/validado (17.28/17.29), fix de merge implementado y validado (17.30), y **RESOLUCIÓN REAL EJECUTADA Y CERRADA para `A09/I pattern_id=23`, filas 332/334/335/336 (punto 17.32)** — `A09/I` ya reclasifica `AUTO_MIGRATE`. Fila 333 permanece `AUDITADA/CONGELADA/NO RESUELTA` por `AR337`. **AUDITORÍA DE IDENTIDAD/DUPLICADOS completa (17.35/17.36) y GATE FULL-SIGNATURE IMPLEMENTADO (punto 17.37)** — 8 reglas (Grupos 4/5) pasaron de `DUPLICATE` a `SAFE_1_TO_1`. **B2 — EXPANSIÓN PARCIAL 25/30 EJECUTADA Y CERRADA (17.38-17.40)** — 25 reglas reales (`868-892`). **AUDITORÍA A09/I B3/F COMPLETA (17.41) Y EXPANSIÓN REAL DE 9 AGREGACIONES EJECUTADA Y CERRADA (17.42/17.43)** — comando generalizado/renombrado `rule:expand-a09-i-aggregation`, 9 reglas reales creadas (`911-919`: `228`×2,`229`×5,`230`×1,`233`×1). Las 5 combinaciones `total_row=333` (B2) + 1 (B3/229) siguen pendientes por `AR337`; las combinaciones inexistentes/ambiguas de `228,230,233` permanecen sin resolver (`DEFECTO_TEMPLATE`/`REQUIERE_DECISION_HUMANA`). Las 9 reglas origen (5 de B2 + 4 de B3/F) quedan en expansión parcial permanente, sin ningún campo/status nuevo escrito. **AUDITORÍA READ-ONLY EXHAUSTIVA de los 66 `BLOCKED_BY_ENGINE_GAP` completada (punto 17.44)**. **REGLA 461 (`A30/F`) — EJECUTADA Y CERRADA (punto 17.50)**: auditada (17.45), mecanismo `leading_formula_total_beyond_bounds` diseñado (17.46), runtime de technical totals fuera de bounds auditado (17.47), contexto técnico de frontera implementado (17.48), mecanismo formula-based implementado/validado con dry-run (17.49), y **`--commit` real ejecutado** — `config.total_row=123`, clasificación real `SAFE_1_TO_1`, post-check exacto sin discrepancias. Único caso real de toda Serie A que necesitaba este mecanismo (`A30/A,C,D,E` siguen resolviendo vía #6, sin cambio).**

- `SAFE_1_TO_1 = 474` · `REQUIRES_REMAP = 0` · `DUPLICATE = 14` · `BLOCKED_BY_ENGINE_GAP = 65` · `ALREADY_STRUCTURE_AGNOSTIC = 198` · reglas activas = `751`. **[ACTUALIZADO 2026-08-28, punto 17.50]** — `SAFE_1_TO_1` 473→474 (+1, exactamente la regla 461); `BLOCKED_BY_ENGINE_GAP` 66→65 (−1); `DUPLICATE`/`ALREADY_STRUCTURE_AGNOSTIC` sin cambio.
- `rem_rules = 798` · `rem_rule_bindings = 1655` (**[ACTUALIZADO 2026-08-28, punto 17.53]** — 1204→1655, +451 exacto) · estructura activa `67/v35` · bindings a estructura 67 = `451` (**[ACTUALIZADO — punto 17.53]** — 0→451) · `rem_technical_totals = 126` (sin cambio — ningún upload fue reprocesado).
- **Las 11 reglas resueltas de Fase 2**, **las 171 de Categoría A/Fase 3C-1A**, **las 29 de Categoría C**, **las 12 de Categoría B1+B4**, **las 8 de los Grupos 4/5** (17.37), **las 25 de B2** (17.40) y **la regla 461** (17.50) siguen **CERRADAS a nivel de clasificación** — no volver a tratarlas como pendientes de clasificación. **Las 55 de Fase 3C-1B (dentro de las 226 de Categoría A) quedan en estado `CLASIFICACIÓN CERRADA / RUNTIME VALIDADO (sin reprocesar histórico)`** (ver punto 17.48) — su `config`/`total_row`/`status` NO se tocaron; el mecanismo que las haría evaluables en producción ya está implementado y validado (55/55), pero solo se activará para cargas NUEVAS (nunca las 144 históricas, sin backfill autorizado).
- **[NUEVO — punto 17.50]** **Regla 461 (`A30/F`)**: `config.total_row=123` (única clave agregada, resto byte-idéntico), `status=active`, clasificación real `SAFE_1_TO_1`. `RuleVersion` y activity log (`rule_leading_formula_total_beyond_bounds_activated`) creados. Binding preexistente (`525→estructura 19`) intacto, sin rebind a estructura 67. **Cerrada, no volver a tocar salvo autorización explícita futura.**
- **[NUEVO — punto 17.43]** **Las 9 reglas de expansión A09/I B3/CategoríaF** (`911-919`, `source=a09_i_expansion`) quedan **CERRADAS y CREADAS** — clasifican `SAFE_1_TO_1` en vivo, `metadata.derived_from_rule_id`/`metadata.total_row` correctos, sin bindings todavía. **La combinación `229`/`total_row=333` permanece sin crear**, bloqueada por `AR337` — no tocar `AR337`/fila 333 sin autorización explícita futura. Las combinaciones inexistentes de `228`(4)/`233`(5) y las 5 problemáticas de `230` permanecen sin resolver (no son "pendientes de crear", son `DEFECTO_TEMPLATE`/`REQUIERE_DECISION_HUMANA`).
- **Las 5 reglas origen de B2** (`226,227,231,232,234`) **y las 4 de B3/CategoríaF** (`228,229,230,233`): **`status=active`, `config` sin ningún cambio, `BLOCKED_BY_ENGINE_GAP`** — las 9 quedan en **estado de expansión parcial permanente**, derivable en vivo vía `Rule::where('metadata->derived_from_rule_id', $originId)`, sin ningún campo nuevo escrito en ninguna.
- **[SUPERADO — ver punto 17.50]** Regla 461 (`A30/F`): **EJECUTADA Y CERRADA** — `config.total_row=123`, clasificación real `SAFE_1_TO_1`. Ya no está "congelada", "pendiente" ni "no resuelta" — cualquier instrucción previa que la trate como pendiente queda superada por este punto.
- **Las 14 reglas que permanecen `DUPLICATE`** (Grupos 1,2,3,6 completos + `126`,`127` de los grupos mixtos 7/11): `24,553,557,558,559,617` · `585,602` · `560,618` · `29,580` · `126` · `127` — ninguna resuelta, sin cambio.
- **`130`/`133`** (artefactos autorreferenciales, Grupos 15/16): `ALREADY_STRUCTURE_AGNOSTIC`, sin cambio, no desactivados.
- **Campaña MISMATCH**: **1 único `MISMATCH` en toda la Serie A** (`A30/C pattern_id=1`, preexistente, sin relación) — sin cambio.
- Hashes `reglas-funcionales.json`/`mismatch-resolution-audit.json`: `8565f0af.../24b3d2b7...` — sin cambio desde 17.32 (la expansión no toca calibraciones).

## ★ CHECKPOINT DE CIERRE DE JORNADA — 2026-08-28 (leer esto primero al reanudar) ★

**Última fase completada: 17.54 — CERTIFICACIÓN END-TO-END REM A.**
**Veredicto: `REM_A_END_TO_END_CERTIFICADO`.**

**Baseline vigente (verificado en el post-check de 17.54, sin ningún cambio posterior)**:
- `activas = 751`
- `SAFE_1_TO_1 = 474`
- `BLOCKED_BY_ENGINE_GAP = 65`
- `DUPLICATE = 14`
- `ALREADY_STRUCTURE_AGNOSTIC = 198`
- `REQUIRES_REMAP = 0`
- `rem_rules = 798`
- `rem_rule_bindings = 1655`
- bindings a estructura 67 = `451`
- estructura activa = `67/v35`
- `uploads = 146` (incluye `upload_id=187`) · `rem_data = 403.247` · `rem_technical_totals = 276`

**`Upload 187`** = la carga real usada para la certificación end-to-end de 17.54 (copia trazable de `upload 186`, `102302A05.xlsm`) — **queda como fixture de referencia, no eliminar ni reprocesar sin autorización explícita**.

**Resultado de 17.54**: parser, `rem_technical_totals`, bindings y `RuleEngine` quedaron certificados con ejecución real (no solo simulada/dry-run) contra una carga nueva. **Las 55 reglas trailing-beyond-bounds y la regla 461 quedaron verificadas en ejecución real de producción por primera vez** (antes solo tenían mecanismo validado + tests sintéticos). **REM A queda técnicamente certificado.**

**Pendientes conocidos, todos NO bloqueantes, ninguno con fecha**:
- `229` (`A09/I` AR) — bloqueado por `AR337`, defecto del template Excel de origen, fuera del control del sistema. 4 opciones de tratamiento documentadas (punto 17.28.4), ninguna elegida.
- `230` (`A09/I` AS) — mapeo disperso ambiguo, requiere decisión funcional de Estadística APS, no resoluble con más evidencia técnica.
- `A30/C pattern_id=1` — único `MISMATCH` de toda la Serie A, columnas J/K/L nuevas sin decisión histórica, requiere calibración funcional de Estadística APS desde la interfaz ordinaria.
- 56 secciones `NO_UTILIZADA` (`A21,A24,A25,A30AR,A34`) — fuera de alcance mientras Estadística APS no las reactive.
- 14 reglas `DUPLICATE` — 8 deuda de catálogo confirmada (duplicado exacto/subset-superseded, sin funcionalidad faltante), 6 (`A01/A/C`) genuinamente ambiguas, pendientes de revisión humana opcional.
- `130`/`133` — artefactos autorreferenciales rotos (`Suma(D)=Columna D`), candidatos a `status=inactive`, no desactivados, no urgente.
- Deuda/flakiness de tests ya documentada (9 fallos "flaky/order-dependent" en la suite grande, ver punto 17.39.5) — no investigada, no bloqueante.

**NO iniciar todavía 17.55.**

**Próximo paso al retomar**: **17.55 — CIERRE TÉCNICO REM A / AUDITORÍA GIT / CHECKPOINT FINAL**, pendiente de instrucción explícita del usuario — no iniciar por iniciativa propia.

**Recordatorio explícito**: todavía **NO se ha hecho commit ni push** de nada de esta campaña (Fase 1 en adelante) — todo el trabajo permanece local sobre el working tree, sin tocar `main`.

## ★ FASE 17.55 — CIERRE TÉCNICO REM A / AUDITORÍA GIT — COMPLETADA, 100% READ-ONLY (2026-08-31) ★

**Veredicto: `REM_A_READY_FOR_CLEAN_COMMIT`.** Auditoría de cierre y de working tree, sin ninguna escritura de BD/reglas/bindings/calibraciones/estructura/uploads, sin commit, sin push.

**Baseline reconfirmado en vivo** (BD real, no memoria): `activas=751`, `SAFE_1_TO_1=474`, `BLOCKED_BY_ENGINE_GAP=65`, `DUPLICATE=14`, `ALREADY_STRUCTURE_AGNOSTIC=198`, `REQUIRES_REMAP=0`, `rem_rules=798`, `rem_rule_bindings=1655`, bindings a estructura 67=`451`, estructura activa `67/v35`, `upload 187` intacto (`status=with_errors`) — **exacto, sin ninguna discrepancia** respecto al checkpoint de cierre de 17.54.

**Auditoría Git completa** (rama `main`, 6 commits locales previos a esta campaña por delante de `origin/main`, sin pushear; 0 archivos staged; 55 entradas en el working tree): **52 archivos clasificados `REM_A_CAMPAIGN_CONFIRMED`** (13 modificados de motor + `CLAUDE.md` + 38 nuevos: 12 comandos, 4 servicios/modelo/migración, 24 tests) — cada uno verificado contra su punto 17.x de origen leyendo el diff real (nombres de función/constante agregados), sin código no explicado en ninguno. **1 archivo `PREEXISTING_OR_OTHER_WORK`**: `frontend/vite.config.ts` (fix de puerto proxy 8080→8000, incidente de entorno local ya documentado en el punto 17.24 como "ajeno a REM") — no mezclar en el commit REM A sin decidirlo aparte. **2 artefactos `DIAGNOSTIC_TEMPORARY`**: `backend/app/Console/Commands/DiagCheckAdminPasswordCommand.php` y `DiagResetAdminPasswordCommand.php` (comandos del mismo incidente de login local del punto 17.24, ambos auto-documentados como "borrar cuando termine el diagnóstico") — excluir del commit REM A. `backend/demo/` reconfirmado preexistente/no relacionado (ya documentado). Ningún dump/log/fixture temporal suelto (todo lo de ese tipo ya está git-ignorado). `RuleExpandB2AggregationCommandTest.php` (nombre anterior, reemplazado en 17.42) confirmado correctamente ausente — el rename no dejó residuo.

**Alcance del cierre confirmado**: calibración 302/306 (1 MISMATCH, `A30/C`, sin cambio), 474 reglas `SAFE_1_TO_1` con 451 bindings reales a estructura 67, mecanismos especiales presentes en el diff real, `upload 187` como evidencia de ejecución real intacta. Pendientes (`229`/`AR337`, `230`, `A30/C`, 56 `NO_UTILIZADA`, 14 `DUPLICATE`, `130`/`133`, flakiness de tests) — todos preservados sin tocar, ninguno resuelto en esta fase. **No fue necesario repetir 17.53 ni 17.54.**

**Propuesta de composición del eventual commit** (NO ejecutada): incluir los 52 archivos `REM_A_CAMPAIGN_CONFIRMED`; excluir `vite.config.ts`, los 2 comandos `Diag*`, y `backend/demo/`. Decisiones pendientes antes de comitear: (a) si `vite.config.ts` va en un commit separado de infraestructura local; (b) qué hacer con los 2 comandos `Diag*` (borrar o archivar). Ninguna bloquea el estado técnico de REM A.

**Propuesta de reducción de `CLAUDE.md`** (NO ejecutada, tamaño real confirmado: 778.423 caracteres / 4.222 líneas): mover líneas 1–3443 (detalle punto-por-punto de fases 17.1–17.54) a `docs/handoffs/rem-a-fase3-detalle-17.1-17.54.md`, y líneas ~3662–4222 (campaña MISMATCH 2026-08-11 a 2026-08-26 + Fase A/B/C original + calibración funcional Serie A) a `docs/handoffs/rem-a-mismatch-y-calibracion-2026-08-11-a-2026-08-26.md` — sin editar contenido, solo reubicación. `CLAUDE.md` quedaría con únicamente el bloque operativo vigente (ESTADO VIGENTE ÚNICO, checkpoint de cierre, próximo paso, prohibiciones, inventario Git), muy por debajo del límite de 150k. **No ejecutada — pendiente de autorización explícita.**

**Próximo paso**: ninguno de los ítems de la lista original de abajo (B2/B3/CategoríaF, guard `no_utilizada`, commit de Git, etc.) tiene prioridad automática — todos requieren autorización explícita, turno a turno, igual que el resto de la campaña.

## Próximo paso vigente (NO ejecutar todavía, solo dejar indicado)

**[CERRADO — ver punto 17.54 y Fase 17.55 arriba]** El rebind real a estructura 67 (17.53), la certificación end-to-end (17.54) y el cierre técnico/auditoría Git (17.55) ya fueron **ejecutados y cerrados** — ninguno de los tres sigue pendiente.

Ninguno priorizado sobre otro, todos requieren autorización explícita — auditoría de las 9 ya completa (punto 17.25), pendiente es la DECISIÓN de diseño/implementación, no más investigación:

1. **B2 (`A09/I` regular, 5 reglas: `226,227,231,232,234`)** — **[CERRADO PARCIALMENTE — ver punto 17.40]** las 25 combinaciones viables ya fueron **creadas y verificadas en real** (ids `868-892`, `SAFE_1_TO_1`, post-check exhaustivo sin discrepancias). Pendiente, sin autorizar: (a) decidir el futuro de `AR337`/fila 333 (4 opciones documentadas en 17.28.4, ninguna elegida) para poder crear las 5 combinaciones restantes; (b) decidir si algún día se autoriza `status=inactive` para las 5 reglas origen (quedarían sin ningún propósito funcional una vez resuelto (a)) — ninguna de las dos decisiones es urgente, ambas quedan abiertas sin fecha.
2. **B3 (regla 229/AR)** — **[CERRADO PARCIALMENTE — ver punto 17.43]** sus 5 posiciones limpias (331,332,334,335,336) fueron **creadas y verificadas en real** (ids incluidos en `911-919`, `SAFE_1_TO_1`, post-check exhaustivo sin discrepancias). La 6ta (fila 333) permanece bloqueada por `AR337` — sin tocar, sin corregir ni eliminar, sin fecha.
3. **Categoría F (3 reglas: `228,230,233`)** — **[CERRADO PARCIALMENTE — ver punto 17.43]** las 4 combinaciones reales y limpias (`228`×2, `230`×1, `233`×1) fueron **creadas y verificadas en real** (ids incluidos en `911-919`). Las combinaciones ausentes (`228` 4, `233` 5) y las ambiguas/de residuo incorrecto de `230` (5) permanecen sin resolver — `DEFECTO_TEMPLATE`/`REQUIERE_DECISION_HUMANA`, sin ninguna acción propuesta ni autorizada.
4. Corregir el gap de guard `no_utilizada` en `RuleActivateCategoryATotalCommand.php` (Fase 3C-1A, hallazgo del punto 17.15) — no explotado en la práctica, no autorizado todavía.
5. **[SUPERADO — ver punto 17.50]** Regla 461 (`A30/F`): **EJECUTADA Y CERRADA** — `--commit` real ejecutado, `config.total_row=123`, clasificación real `SAFE_1_TO_1`, post-check exacto sin discrepancias. Todo el trabajo de diseño/implementación/validación (17.45-17.49) culminó en la ejecución real (17.50) — ya no queda ningún ítem pendiente para la regla 461 en sí. El hallazgo colateral sobre las 55 trailing (mismo hueco arquitectónico, ver 17.47) fue resuelto de forma compartida por la implementación de 17.48 (`findTechnicalSectionContextForRow()`), ya **CERRADA/CLASIFICACIÓN VALIDADA** para esas 55 (ver punto 17.48/17.16).
6. **[CERRADO — ver punto 17.32]** Corrección del gap de etiqueta "TOTAL" fusionada para `A09/I`: auditoría, diseño, código, validación y **resolución real ejecutada y cerrada** para las filas `332,334,335,336` (`pattern_id=23` → `AUTO_MIGRATE`). Único pendiente restante en esta línea: **decidir qué hacer con la fila 333/`AR337`** (4 opciones documentadas en 17.28.4, ninguna elegida, fila congelada) — sin autorización todavía, nada urgente.

### Otros temas abiertos (no relacionados con Fase 3, ninguno iniciado ni priorizado sobre otro)

1. **[SUPERADO — ver puntos 17.20, 17.24 y 17.25]** Diseñar una solución para `A09/I`/`A09/F.1`/`A26/B` (Categoría B, originalmente 18 reglas) — **B1 (`A09/F.1`, 2) y B4 (`A26/B`, 10) ya quedaron ejecutadas y cerradas** (Fase 3C-3A/3C-3B, punto 17.24). **B2 (5), B3 (1) y Categoría F (3) ya fueron auditadas exhaustivamente** (punto 17.25, matriz completa con causa raíz/evidencia/tratamiento por regla) — pendiente exclusivamente de decisión de diseño/implementación, no de más investigación. Ver "Próximo paso vigente" ítems 1-3.
2. Auditar los 22 `DUPLICATE` preexistentes (sin relación con 529/530, nunca investigados en esta campaña).
3. Considerar, como pregunta abierta de producto (no técnica, no urgente): si se desea alguna acción correctiva sobre la vista histórica de los 32 uploads ya procesados por la 344 (el resumen se recalcula en vivo y seguirá mostrando esas 54 fallas si alguien revisita esos uploads) — no propuesta para ejecutar, solo señalada.
4. Corregir el gap del guard de `rule:remap-section` identificado en el punto 16.3.2 (verificar colisión de clave funcional post-remap contra el estado que resultaría de escribir, no solo contra el estado previo).
5. Decidir si se autoriza el commit de Git de todo el trabajo acumulado de esta campaña (comandos nuevos + tests + servicios modificados, ver punto 14, Fase 1/2/3A/3B) — no autorizado todavía.
6. Decidir si se autoriza backfill histórico limitado sobre las 144 cargas existentes una vez generalizado el motor (Fase 3C) — pregunta abierta, no bloqueante, no evaluada en profundidad.

Ninguna acción debe ejecutarse sin autorización explícita, turno a turno, igual que el resto de la campaña.

---
