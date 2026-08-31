# ARCHIVO HISTÓRICO — Campaña MISMATCH, calibración funcional Serie A y Fase A/B/C original (2026-08-11 a 2026-08-26)

> **Este archivo es una reorganización, no una reescritura.** Todo el contenido que sigue después de esta nota es una copia **verbatim** (carácter por carácter) de las secciones más antiguas que hasta el 2026-08-31 vivían al final de `CLAUDE.md` — el cierre de la campaña MISMATCH (2026-08-26 y 2026-08-21), el trabajo de Fase A/B/C original del motor de reglas (2026-08-12), y el cierre de la calibración funcional completa de la Serie A (2026-08-11). Se movió aquí exclusivamente para que `CLAUDE.md` vuelva a caber bajo el límite automático de contexto — **nada fue resumido, editado ni eliminado**.
>
> **Para el estado operativo vigente, lee `CLAUDE.md` primero.** Para el detalle de la campaña Fase 3 más reciente (17.1–17.55), ver `docs/handoffs/rem-a-fase3-detalle-17.1-17.54.md`.
>
> Consulta este archivo cuando necesites: el detalle exacto de cómo se cerró cada patrón MISMATCH (tags `safe_reconfirm`/`structural_row_exclusion`/`human_review`), los mecanismos estructurales #1–#16 descubiertos durante la auditoría/corrección de las hojas A19a–A34 de Serie A, el estado de Fase A (bindings a estructura 63) y Fase B (auditoría de reclasificación original), o cualquier razón histórica detrás de una restricción sobre una sección/hoja específica de Serie A.
>
> Verificación de integridad de este traslado: `sha256sum` del cuerpo verbatim de este archivo = `18c98ae7f5271b86d694d9ad04100d62de81f384156c5589ff6773f3a52162ec` (calculado sobre las 561 líneas originales antes de anteponer esta nota) — reproducible ejecutando `sed -n '3678,4238p'` sobre la versión de `CLAUDE.md` previa a la Fase 17.56.

---

# Estado al cierre de la sesión — 2026-08-26 (FASE 3 MOTOR DE REGLAS — CIERRE DE LA CAMPAÑA MISMATCH, ESTADO REAL SERIE A CONFIRMADO)

Handoff de cierre de la campaña de reconciliación MISMATCH del motor de reglas REM (Fase 3), continuación directa de la sección "2026-08-21" de más abajo (léela para el detalle completo de mecanismos/fixes de esa jornada — no se repite aquí). Esta sección documenta el **estado final confirmado hoy**, tras completar las tandas 2B-2 a 2B-6 más el trabajo adicional de identidad estable, reparación de A09/G, y el barrido final de los 14 `HUMAN_REVIEW` originales. Leer esta sección primero antes de retomar cualquier trabajo sobre calibración, reconciliación o el flujo MISMATCH.

## Campaña MISMATCH — técnicamente reconciliada (cerrada)

**Todos los MISMATCH detectados quedaron explicados o auditados con evidencia — ninguno permanece como "diferencia sin explicar".** Resultado final:

- **117 patrones tagueados y reconfirmados como `safe_reconfirm`** (drift puro del algoritmo canónico de fingerprint v2 — filas, columnas, fórmulas y editabilidad idénticas entre la estructura histórica y la viva; demostrado con evidencia real de `cell_data` y de `rem_data` en cada caso, nunca por analogía). Secciones cerradas por esta vía en la jornada de hoy: `A05/U`, `A04/F`, `A04/I.1`, `A04/I.2`, `A07/A` (3 patrones), `A08/M`, `A11/E` (3 patrones), `A27/D`, `A27/E`, `A27/F`, `A27/K` (8 patrones), `A30/A`, `A32/L` (4 patrones), `A32/M`, `A32/N`.
- **2 patrones tagueados y reconfirmados como `structural_row_exclusion`** (`A09/G` P3/P4 — exclusión mecánica de fila TOTAL líder, mecanismo #6, verificada patrón por patrón contra el gate ya documentado en la sección 2026-08-21).
- **1 patrón tagueado como `human_review`**: `A30/C` P1 — ver más abajo, es la única excepción real.
- Total de tags en `mismatch-resolution-audit.json`: **120** (117 `safe_reconfirm` + 2 `structural_row_exclusion` + 1 `human_review`).
- **Incidente cerrado**: la corrupción real de datos de `A09/G` (causa raíz: `applyQuickRevalidation()` escribía por `pattern_id` posicional en vez de por identidad histórica resuelta) fue diagnosticada, reparada con evidencia verificable (backup + hash previo, campos restaurados solo desde fuentes verificables: `_questions_history` y tags de auditoría legacy) y el fix de causa raíz quedó aplicado de forma genérica (no un parche puntual) — ver sección 2026-08-21 para el detalle completo.

### Corrección importante sobre la clasificación original de los 14 `HUMAN_REVIEW`

La clasificación original (documentada en la sección 2026-08-21, heredada de una auditoría más temprana de la campaña) marcaba 14 secciones como `HUMAN_REVIEW`: `A04/I.1, A04/I.2, A04/F, A07/A, A08/M, A11/E, A27/D, A27/E, A27/F, A27/K, A30/A, A32/L, A32/M, A32/N`. **Auditoría profunda de cada una, hoy, con evidencia real (`cell_data`, `rem_data`, comparación de estructura histórica vs. viva) demostró que 13 de esas 14 eran en realidad `SAFE_RECONFIRM` puro** — la etiqueta `HUMAN_REVIEW` original no reflejaba ningún cambio funcional real, solo drift del algoritmo. Las 13 quedaron reconfirmadas (ver lista arriba). **`A30/C` es la única excepción genuina** de las 14 originales — ver abajo por qué.

## A30/C — único ítem restante, y por qué es distinto

| Patrón | Filas | Estado |
|---|---|---|
| P1 | 81,82,83,84,85,86,87,88,89,92,93 | **`human_review`** — calibración funcional nueva pendiente, no drift técnico |
| P2 | 90,91 | Resuelto — `AUTO_MIGRATE` |

**A30/C P1 es la única sección donde la auditoría encontró una superficie de captura real y genuinamente nueva** (a diferencia de las otras 13, donde la superficie de columnas era idéntica): columnas `J` (Modalidad → Institucional), `K` (Modalidad → Compra de Servicio → Sistema), `L` (Modalidad → Compra de Servicio → Extrasistema), correspondientes al bloque "Nivel Primario" — verificadas en `cell_data` real como celdas de escritura directa genuinas (no fórmulas, no totales, no columnas auxiliares). La estructura histórica (id=52, revisada el 2026-08-10 por Francisco Arcos) **no incluía estas 3 columnas en absoluto** — la decisión histórica `debe_registrar_cero` nunca pudo haberlas considerado. Las 138 cargas reales históricas no aportan evidencia: la clave `J`/`K`/`L` nunca existió en `values`, porque el parser nunca leyó esas columnas al no estar declaradas entonces.

**Decisión de diseño explícita (2026-08-26, adoptada tras discusión)**: este caso **no se trata como trabajo de reconciliación pendiente**, y **Claude/el asistente no decide ni sugiere** si J/K/L deben registrar 0, pueden quedar vacías, o no aplican — esa es una decisión funcional que corresponde exclusivamente a Estadística APS, a tomar desde la interfaz ordinaria de calibración de ATHENEA (la misma vía con la que se calibró cada patrón nuevo de la Serie A desde el inicio del proyecto). El patrón queda etiquetado `human_review` (tag ya persistido) y a la espera, sin fecha límite, igual que `A05/V`/`A30/D`.

**Nota arquitectónica (deuda/mejora futura, NO implementada)**: se detectó que la capa de reconciliación (`PatternMigrationScanner`/`PatternReconciliationService`, categorías `AUTO_MIGRATE`/`MISMATCH`/`FULL_REVALIDATION`/`QUICK_CONFIRMATION`) y la capa de auditoría (`MismatchResolutionAuditService`, categorías `safe_reconfirm`/`human_review`/`structural_review`/`structural_row_exclusion`) están completamente desacopladas — el scanner nunca lee los tags. Por eso una sección con un patrón ya auditado y tagueado `human_review` (como `A30/C`) sigue contándose globalmente como `MISMATCH` sin distinguirse de un MISMATCH nunca auditado. Propuesta para el futuro: un estado `MIGRATION_NEEDS_CALIBRATION` (o similar) en la capa de reconciliación que el scanner asigne cuando exista un tag `human_review` vigente, separándolo del conteo de "trabajo de reconciliación técnica pendiente". **No implementar sin autorización explícita** — es una decisión de diseño real (uniría dos subsistemas hoy independientes), no una corrección menor.

## Estado real de Serie A confirmado hoy (verificado en vivo, no de memoria)

El conteo "303/303 (100%)" de la sección 2026-08-11 (más abajo) fue correcto en su momento, pero quedó superado por el crecimiento posterior de la estructura activa. **Estado real verificado hoy contra `SectionCalibrationMatrixService::buildStructureCalibrationSummary()` y el scanner en vivo**:

- `sections_aplicables` = **306**
- `sections_completed` = **304**
- `sections_calibrated` = **302**
- `sections_not_calibratable` = **2** (`A04/N`, `A32/E1`, sin cambios)
- `sections_pending` = **2** — exclusivamente `A05/V` y `A30/D`

**`A05/V` y `A30/D` son `NEW_SECTION`** (detectadas por evolución de la estructura, nunca calibradas) **y permanecen deliberadamente fuera de alcance** — no deben interpretarse como calibración olvidada ni como regresión. Ya estaban documentadas así en `CLAUDE.md` ("Nunca calibrar dentro de esta campaña") desde antes de hoy.

**`A33` — corrección de una nota desactualizada**: verificado en vivo hoy (BD real + scanner) que A33 está **100% calibrada**: 5/5 secciones (`A`, `B`, `C`, `D`, `E`), 9/9 patrones revisados (A=1, B=3, C=1, D=2, E=2), todas `review_status=section_reviewed`, `reviewed_by=Francisco Arcos`, respuestas funcionales reales persistidas para los 9 patrones, timestamps 2026-08-11T15:17:57–15:18:19. Las 5 secciones clasifican `AUTO_MIGRATE` en el scanner vivo (fingerprint guardado = fingerprint recalculado). La nota original en la sección 2026-08-11 más abajo ("0 patrones revisados... pendiente de calibración funcional manual") quedó desactualizada porque la calibración ocurrió más tarde ese mismo día y el checkpoint nunca se editó — anotado in situ en esa sección, **sin borrar el texto original**.

## Hallazgos técnicos para backlog (documentados, no bloquean nada, no corregidos)

Filas fantasma/TOTAL histórico descubiertas durante la auditoría de esta campaña — mismo patrón ya conocido (mecanismo #6/#8), estructura activa ya corregida hacia adelante, histórico intencionalmente no limpiado (decisión ya establecida en toda la campaña):

- **`A30/A` fila 12**: 131 registros históricos reales en `rem_data`, `concept="TOTAL"`, con valores numéricos no-cero. Fuera del patrón calibrado (`P1` = filas 13-67), sin efecto sobre su clasificación.
- **`A32/N` fila 204**: 55 registros históricos, valores siempre `null`. Fuera del patrón calibrado (`P1` = filas 206-207).
- Se suman a la lista ya documentada en la sección 2026-08-11 (mecanismo #8): A01, A23, A26, A28, A29 (25 filas TOTAL final fantasma).

## Baseline técnico final de la campaña MISMATCH

`MISMATCH` (scanner) = 1 (`A30/C`, solo por P1) · Tags = 120 · `rem_rules` = 764 · `rem_rule_bindings` = 1204 · Estructura activa = 67/v35 · Bindings a estructura 67 = 0 · `reglas-funcionales.json` sin cambios fuera de las reconfirmaciones documentadas · Nada commiteado ni pusheado.

## Próximo trabajo (autorizado a nivel de intención, no iniciado)

- Revisar los **66 `REQUIRES_REMAP`** documentados en la sección 2026-08-12 (Fase B) — no iniciado.
- Decidir el diseño pendiente del gap arquitectónico `sum_equals`/`total_row` (deuda técnica #5, sección 2026-08-12).
- Decidir si se autoriza el commit del trabajo acumulado de Fase 3 (ver auditoría de working tree de esta misma fecha, entregada en el chat, no repetida aquí).

## Restricciones vigentes

- **No calibrar `A30/C` P1, `A05/V` ni `A30/D`** — la decisión funcional corresponde a Estadística APS desde la interfaz ordinaria, no a este asistente.
- **No implementar el estado `MIGRATION_NEEDS_CALIBRATION`** (propuesta arquitectónica) sin autorización explícita.
- Todas las restricciones generales de Fase 3 documentadas en las secciones anteriores **siguen vigentes**.

---

# Estado al cierre de la sesión — 2026-08-21 (FASE 3 MOTOR DE REGLAS — FLUJO SAFE_RECONFIRM EN PRODUCCIÓN LOCAL, TANDA 2B-2 TAGUEADA/PENDIENTE DE CLIC)

Handoff de la campaña de reconciliación del motor de reglas REM (Fase 3), continuación directa de la sección "2026-08-12" de más abajo (léela también si necesitas contexto de Fase A/B/C, pero **no la repitas**: nada de esa sección cambió hoy). Leer esta sección primero antes de tocar cualquier tag/reconfirmación MISMATCH, `SectionCalibrationMatrixService`, `SectionDetectorService`, o el flujo de resolución de patrones.

## Qué problema se está resolviendo

Desde ~2026-08-13 hasta hoy (gap no documentado en este archivo, trabajo de sesiones intermedias) la estructura activa avanzó de **63 → 66 → 67**. Al retomar hoy, `PatternMigrationScanner` (que compara el `pattern_fingerprint` v2 guardado en `reglas-funcionales.json` contra el fingerprint canónico recalculado en vivo) reportaba **43 secciones en categoría `MISMATCH`** — patrones cuya fórmula/estructura no cambió realmente, pero cuyo fingerprint sí cambió por mejoras del motor de detección (ver fixes abajo). El objetivo de hoy fue: (1) corregir los bugs de motor que causaban fingerprints "falso positivo", (2) diseñar e implementar un flujo seguro y auditable para resolver esos MISMATCH desde la interfaz (nunca a ciegas), y (3) empezar a resolverlos en tandas pequeñas, verificadas paso a paso contra Excel/cell-data real.

## Fixes de motor aplicados hoy (código, sin commit)

1. **`SectionDetectorService` — fila TOTAL líder contaminando labels** (bug pre-existente encontrado al auditar A30/D). Cuando una fila TOTAL/subtotal líder compartía la firma de columnas con filas de captura reales, `findTrailingHeaderRows()` la incluía en el array usado para construir las etiquetas de columna (`ColumnDetectorService`), contaminando el label de columnas reales. Fix: nuevo parámetro de salida `$filasTotalLider` en `findTrailingHeaderRows()`, restado (`array_diff`) del array pasado a `columnDetector->detect()` en los dos call-sites de `detect()` y dentro de `findSecondaryHeaderBlocks()`. Cubierto por `SectionDetectorServiceLeadingTotalRowLabelTest.php` (6 tests) y `SectionDetectorServiceLeadingTotalRowLabelRealFileTest.php` (2 tests, archivo real).
2. **Corrección estructural real de A30 (patch aplicado, estructura 66→67 activada)** — motivada por el fix anterior. El patch real (con backup previo, dry-run repetido, commit y approve/activate) afectó las secciones **A30/A, A30/C, A30/D y A30/E** de la hoja A30 real. Esta es la razón por la que la estructura activa pasó de 66 a **67 (versión 35)**. Detalle fila-por-fila de ese patch específico: ver el informe "RESULTADO PATCH REAL A30 TOTAL LÍDER" entregado en el chat de esa fase (no repetido aquí completo por longitud — si hace falta, se puede reconstruir con `php artisan tinker` contra `RemTemplateStructure::find(67)` vs `find(66)` en modo solo lectura).
3. **Gate individual de fórmulas en `SectionCalibrationMatrixService`** — tres fixes acumulativos aplicados hoy sobre `isFunctionalHorizontalFormula()` / `buildDynamicPatternDefinitions()` / `hasEditableInputComponentsForFormula()`:
   - **Fase 2**: `isFunctionalHorizontalFormula()` simplificado a un criterio puramente evidencial (deja de rechazar por conteo/label de componentes; solo exige que exista evidencia real — `isSexMainRuleFormula()` o `hasEditableInputComponentsForFormula()`).
   - **Fase 3 ("arrastre")**: el loop de activación por fila de `buildDynamicPatternDefinitions()` no revalidaba la firma de dependencias de cada fila individualmente, dejando que filas inválidas "viajaran" sobre la candidatura de toda la columna. Fix: revalidación por fila (`isSameRowFormula`, columnas de dependencia no vacías, columna total no incluida en el origen).
   - **Fix de "búsqueda existencial de evidencia"** (mismo día, después del anterior): `hasEditableInputComponentsForFormula()` hacía cortocircuito en la PRIMERA fila con la misma firma que fallaba, sin seguir buscando en filas posteriores con la misma firma que sí tuvieran evidencia válida. Fix: búsqueda existencial completa sobre todas las filas antes de descartar.
   - **Veredicto final tras barrido completo A/B/C (aislando cada fix con `git stash`)**: **`GATE_INDIVIDUAL_ESTABLE`** — el gate queda estable, generaliza correctamente (confirmado también en A03/A.4 y A11/E, no solo en los casos originalmente auditados), sin pérdidas ni ganancias espurias nuevas.
   - Tests: `SectionCalibrationMatrixServiceHorizontalTwoComponentTest.php` (9), `SectionCalibrationMatrixServiceRowLevelValidationGateTest.php` (12), `SectionCalibrationMatrixServiceExistentialEvidenceSearchTest.php` (10).
4. **`PatternMigrationScanner::scanSection()` — `historical_answer` ausente en el path canónico v2** (hallazgo durante la verificación UI del flujo MISMATCH). El path legacy (v1) ya exponía `historical_answer` (para mostrar "decisión anterior" en el panel); el path v2 (canónico, el que produce prácticamente todos los MISMATCH reales de la campaña) nunca lo seteaba. Fix de una línea: reutiliza `summarizeHistoricalAnswer()` también en el path v2. Solo lectura, no participa de la clasificación. Test: `test_historical_answer_is_exposed_for_canonical_v2_mismatch` en `MismatchResolutionApiTest.php`.

**Efecto combinado de estos fixes sobre el catálogo**: el fix de Fase 2 introdujo 9 secciones nuevas en MISMATCH (auditadas y clasificadas 4 SAFE / 5 no-triviales); el fix de "arrastre" afectó 36 secciones (23 ganancias, 13 pérdidas); el fix existencial corrigió esas 13 pérdidas sin introducir regresiones nuevas (confirmado con barrido A/B/C). El universo final de 43 MISMATCH (auditado exhaustivamente, ver clasificación abajo) es el resultado neto de toda esta cadena de fixes.

## Estructura activa

**ID 67, versión 35.** Confirmada sin cambios durante TODO el trabajo de hoy de ahí en adelante (piloto + Tanda 1 + Tanda 2 completa) — verificado en cada post-check. **No se ha hecho ningún rebind a 67** (bindings de reglas a `bindable_type=structure, bindable_id=67` = **0**, confirmado repetidamente). Los bindings de Fase A siguen apuntando a las estructuras antiguas: 115 a estructura 63, 115 a estructura 66 (rebind ya ejecutado en una fase anterior de hoy, antes del trabajo documentado en detalle en esta sección).

## Flujo SAFE_RECONFIRM — diseño y cómo funciona

Diseñado y construido hoy porque se descubrió que el endpoint/flujo existente (`confirmQuickRevalidation` / `QuickRevalidationPanel.tsx`) está **estrictamente limitado a categoría `QUICK_CONFIRMATION`** y nunca puede resolver `MISMATCH` — ninguno de los 43 MISMATCH podía tocarse desde la interfaz antes de este trabajo.

**Piezas nuevas:**
- **`MismatchResolutionAuditService`** (`backend/app/Domain/RuleEngine/Services/MismatchResolutionAuditService.php`, nuevo) — almacén JSON separado (`storage/app/private/certificacion/mismatch-resolution-audit.json`), **deliberadamente distinto de `reglas-funcionales.json`**. Guarda solo una **etiqueta de clasificación** por `(sheet, section, pattern_id)`: `category` (`safe_reconfirm`/`human_review`/`structural_review`), `audited_fingerprint`, `audited_rows`, `reason`, `audited_by`, `audited_at`. Nunca toca `response`/`reviewed_by`/`reviewed_at`/`review_status` ni ningún fingerprint real.
- **`rule:tag-mismatch-resolution {sheet} {section} {pattern_id} --category= --reason= --by= [--commit]`** (comando nuevo, `RuleTagMismatchResolutionCommand.php`) — dry-run por defecto. Antes de permitir el tag: exige que la categoría viva sea `MISMATCH` (nunca taguea algo que ya cambió), y para `safe_reconfirm` específicamente exige que las filas vivas coincidan **exactamente** con las filas históricas guardadas (si no, aborta — un cambio de filas es por definición un cambio estructural, nunca `safe_reconfirm`).
- **`CalibrationViewController::mismatchResolutionDetails()`** (GET, solo lectura) y **`confirmMismatchResolution()`** (POST) — endpoints nuevos, separados de `confirmQuickRevalidation()` (que no se tocó). El POST revalida en vivo: categoría aún `MISMATCH` → si no, 409; existe un tag → si no, 409 `not_audited`; tag es `safe_reconfirm` → si es `human_review`/`structural_review`, 409 `requires_full_review`; `audited_fingerprint`/`audited_rows` del tag siguen coincidiendo con lo vivo → si no, 409 `audit_stale`. Solo si todo pasa, llama a `FunctionalRuleService::applyQuickRevalidation()` (sin modificar ese método) — escribe únicamente los 6 campos ya protegidos: `fingerprint_version`, `pattern_fingerprint`, `pattern_rows`, `revalidated_by`, `revalidated_at`, `revalidation_source_type`.
- **Rutas nuevas** en `routes/api.php`: `GET .../patterns/{patternId}/mismatch-resolution` y `POST .../patterns/{patternId}/mismatch-resolution/confirm`.
- **`MismatchResolutionPanel.tsx`** (frontend, nuevo) — integrado en `QuickCalibrationPanel.tsx` con un branch nuevo (`migrationPlan?.category === 'MISMATCH' && !forceFullReview`), al lado del branch ya existente de `QuickRevalidationPanel`. Una tarjeta por patrón MISMATCH: sin tag → sin botón; `safe_reconfirm` → botón "Confirmar reconfirmación segura"; `human_review` → botón "Abrir revisión funcional completa" (navega a vista avanzada, nunca confirma); `structural_review` → aviso, sin botón. **Un clic = exactamente un `pattern_id` resuelto** (confirmado leyendo el código, no asumido) — una sección con N patrones MISMATCH muestra N tarjetas en la misma pantalla.
- Tests: `MismatchResolutionApiTest.php` (12 tests, fixtures 100% sintéticas, nunca contra las 43 secciones reales) — cubre: QUICK_CONFIRMATION intacto, safe_reconfirm confirma, human_review/structural_review rechazados, sin-tag rechazado, `audit_stale` por cambio de fingerprint/filas, campos protegidos intactos, metadata de revalidación correcta, historial registrado, aislamiento entre patrones.
- **Regresión completa `tests/Feature/RuleEngine`** corrida dos veces hoy (antes y después del fix de `PatternMigrationScanner`): 254 tests, 219 passed, **35 failed — exactamente los mismos preexistentes ya documentados en sesiones anteriores**, cero regresiones nuevas atribuibles a este trabajo.

## Uso real — piloto y tandas (todo verificado punta a punta, con clics reales en ATHENEA)

Metodología repetida en cada patrón/tanda, sin excepción: (1) auditoría fresca contra Excel/cell-data real antes de proponer; (2) revalidación inmediata contra el estado vivo justo antes de escribir; (3) dry-run del comando de tag; (4) solo si coincide exactamente, `--commit`; (5) post-check inmediato del tag; (6) usuario hace los clics reales en ATHENEA; (7) post-check read-only campo por campo (fingerprint, rows, decisión histórica, `reviewed_by/at/status`, metadata `revalidated_*`, tags intactos, barrido global de MISMATCH). Nunca hubo una sola discrepancia en todo el proceso.

- **Piloto — A05/N P3** (fila 208): primer caso real, end-to-end. `AUTO_MIGRATE` confirmado, MISMATCH 43→42.
- **Tanda 1 (5/5 confirmados)**: A05/O P3, A05/R P1, A23/B P4, A27/A P9 (+ el piloto A05/N P3) — MISMATCH 42→38.
- **Tanda 2 Parte 1 (5/5 tagueados y confirmados)**: A03/A.6 P1, A03/C P1, A03/D.6 P1, A07/G P1, A05/Q P2 — MISMATCH 38→33.
- **Tanda 2 Parte 2A (4 secciones / 8 patrones, tagueados y confirmados)**: A03/A.4 (P1,P2), A07/D (P1,P2), A08/B (P1,P2), A11/D (P1,P3) — MISMATCH 33→29.
- **Auditoría Parte 2B (solo lectura, 13 secciones / 69 patrones restantes clasificados)**: 66 `SAFE_RECONFIRM`, **3 `REQUIRES_INVESTIGATION`** (`A09/G P2`, `A09/G P4`, `A32/D1 P3` — mismo conjunto de columnas de origen se comporta de forma inconsistente entre filas del mismo patrón: a veces genuinamente editable, a veces fórmula o totalmente bloqueado sin evidencia directa — no se fuerza a SAFE_RECONFIRM, quedan en categoría propia hasta decisión futura). 0 reclasificados a `HUMAN_REVIEW` (ninguno mostró relación nueva).
- **Tanda 2B-1 (6/6 tagueados y confirmados)**: A07/F (P1,P2,P3), A09/I (P7,P8,P9) — MISMATCH 29→27.
- **Tanda 2B-2 — TAGUEADA, PENDIENTE DE CLIC MANUAL (ver checkpoint exacto abajo).**

## ★ CHECKPOINT EXACTO AL CIERRE DE HOY ★

**Tanda 2B-2 quedó tagueada (14/14 `--commit` exitosos, verificados uno por uno) pero NO reconfirmada en ATHENEA todavía.**

Patrones tagueados como `safe_reconfirm`, pendientes de clic manual:
- **A11/A.1**: P1 (filas 13,14,15,16,17,19,20), P2 (18,24), P3 (21), P4 (22), P5 (23,26), P6 (25,28,29,30), P7 (27).
- **A11/A.2**: P1 (35,36,37,38,39,41,42), P2 (40,46), P3 (43), P4 (44), P5 (45,48), P6 (47,50,51,52), P7 (49).

= **14 patrones / 14 clics manuales pendientes** ("Confirmar reconfirmación segura" en cada tarjeta).

Rutas ATHENEA:
- A11/A.1 → `/calibracion/templates/67/series/A/sheets/A11/sections/A.1`
- A11/A.2 → `/calibracion/templates/67/series/A/sheets/A11/sections/A.2`

**Baseline numérico exacto al cerrar hoy** (verificado en el último post-check, antes de los 14 clics pendientes):
- Secciones `MISMATCH` = **27**
- Tags de auditoría en `mismatch-resolution-audit.json` = **38**
- `rem_rules` = **764**
- `rem_rule_bindings` = **1204**
- Estructura activa = **67 / versión 35**
- Bindings a estructura 67 = **0**
- `reglas-funcionales.json` — última escritura real fue la reconfirmación de A09/I P9 (Tanda 2B-1), nada escrito después (los 14 tags de 2B-2 solo tocaron `mismatch-resolution-audit.json`).

### Primer paso de mañana

1. El usuario abre ATHENEA y hace los **14 clics** de Tanda 2B-2 (rutas arriba).
2. Claude ejecuta **solo un post-check read-only** verificando: A11/A.1 P1-P7 y A11/A.2 P1-P7 → `AUTO_MIGRATE`; ambas secciones completas sin MISMATCH; fingerprints = canónico vivo ya auditado; `pattern_rows` sin cambio; decisiones históricas sin cambio; `reviewed_by`/`reviewed_at`/`review_status` históricos intactos; metadata `revalidated_*` agregada; los 38 tags intactos; ningún patrón ajeno tocado; MISMATCH baja **27 → 25** exacto; `rem_rules=764`, `rem_rule_bindings=1204`, estructura `67/v35`, bindings a 67=`0` siguen iguales.
3. Si todo coincide: reportar **`TANDA_2B_2_OK`** y STOP antes de preparar la siguiente tanda.
4. **Si el estado vivo difiere de este checkpoint en cualquier punto: STOP inmediato y reportar la discrepancia antes de escribir nada** — no asumir, no forzar, no intentar "corregir" el checkpoint.

## Plan posterior ya acordado (no ejecutar sin autorización explícita turno a turno)

- **Tanda 2B-3**: A11/A.3 (P1-P7) + A11/A.4 (P1-P7) — 14 patrones. Esperado MISMATCH 25→23.
- **Tanda 2B-4**: A11/A.5 (P1-P7) + A11/A.6 (P1-P7) — 14 patrones. Esperado MISMATCH 23→21.
- **Tanda 2B-5**: A11/C.1 (4) + A11/C.2 (4) + A11/F (4) — 12 patrones. Esperado MISMATCH 21→18.
- **Tanda 2B-6 (solo subconjunto seguro)**: A09/G (P1,P3,P5,P6) + A32/D1 (P1,P2) — 6 patrones. Estas 2 secciones **NO** saldrán de MISMATCH tras esta tanda (cada una retiene 1 patrón `REQUIRES_INVESTIGATION` sin resolver: A09/G P2/P4, A32/D1 P3) — el contador de *secciones* seguiría en 18, pero el conteo de *patrones* individuales bajaría a 3 pendientes de esa categoría.
- **Piso proyectado si se completan las 4 tandas restantes con SAFE_RECONFIRM solamente**: **18 secciones MISMATCH** = 14 `HUMAN_REVIEW` + `A05/U` + `A30/C` + `A09/G` (parcial) + `A32/D1` (parcial).

## Clasificación completa vigente de los 43 MISMATCH originales

**Ya resueltos (19 secciones / patrones, ver arriba)**: A05/N, A05/O, A05/R, A23/B, A27/A (Tanda 1) + A03/A.6, A03/C, A03/D.6, A07/G, A05/Q (Tanda 2 Pte.1) + A03/A.4, A07/D, A08/B, A11/D (Tanda 2 Pte.2A) + A07/F, A09/I (Tanda 2B-1).

**Tagueados, pendientes de clic (Tanda 2B-2)**: A11/A.1, A11/A.2.

**`SAFE_RECONFIRM` restante, auditado, sin taguear (44 patrones)**: A11/A.3 (7), A11/A.4 (7), A11/A.5 (7), A11/A.6 (7), A11/C.1 (4), A11/C.2 (4), A11/F (4), A09/G subconjunto seguro (P1,P3,P5,P6 = 4 de 6 patrones), A32/D1 subconjunto seguro (P1,P2 = 2 de 3 patrones).

**`REQUIRES_INVESTIGATION` (3 patrones, NO tocar sin decisión explícita)**: `A09/G P2`, `A09/G P4`, `A32/D1 P3` — el mismo conjunto de columnas de origen que en otras filas del mismo patrón es genuinamente editable, aquí aparece como fórmula o completamente bloqueado sin evidencia directa. No es una relación nueva (no es `HUMAN_REVIEW`), es una inconsistencia evidencial que requiere revisión manual antes de clasificar.

**`HUMAN_REVIEW` (14 secciones / 30 patrones, sin tocar, requiere revisión funcional humana, no automatizable con este flujo)**: A04/I.1, A04/I.2, A04/F, A07/A, A08/M, A11/E, A27/D, A27/E, A27/F, A27/K, A30/A, A32/L, A32/M, A32/N.

**`A05/U` y `A30/C`** — MISMATCH estructural histórico conocido, preexistente a esta campaña, fuera de alcance de este flujo. No tocar.

**`NEW_SECTION`** — A05/V, A30/D. Nunca calibrar dentro de esta campaña de resolución de MISMATCH.

**`QUICK_CONFIRMATION`** — A11a (10 secciones: A, C, E, F, G, H, I, J, K, N). Flujo completamente distinto (`confirmQuickRevalidation`/`QuickRevalidationPanel.tsx`, no tocado hoy), funcionando normalmente, nunca mezclar con el flujo MISMATCH.

**`NO_UTILIZADA`** — 75 secciones (A21, A24, A25, A30AR, A34), sin cambios, ver sección 2026-08-11 más abajo.

**`NOT_CALIBRATABLE`** — A04/N, A32/E1, sin cambios.

## Estado del working tree (sin commit, todo local)

Confirmado con `git status` al cierre de hoy — nada commiteado, nada pusheado, rama sincronizada con `origin/main`:

**Modificados:**
- `backend/app/Domain/RemParser/Services/SectionDetectorService.php` (fix TOTAL líder labels, hoy + fix RichText de sesión anterior)
- `backend/app/Domain/RuleEngine/Controllers/CalibrationViewController.php` (2 endpoints nuevos: `mismatchResolutionDetails`, `confirmMismatchResolution`)
- `backend/app/Domain/RuleEngine/Services/PatternMigrationScanner.php` (fix `historical_answer` en path v2)
- `backend/app/Domain/RuleEngine/Services/SectionCalibrationMatrixService.php` (3 fixes del gate individual)
- `backend/routes/api.php` (2 rutas nuevas)
- `frontend/src/features/rule-engine/components/patterns/QuickCalibrationPanel.tsx` (branch nuevo para `MismatchResolutionPanel`)
- `frontend/src/features/rule-engine/services/calibration.ts` (2 métodos nuevos)
- `frontend/src/features/rule-engine/types/calibration.ts` (tipos nuevos del flujo MISMATCH)

**Nuevos, sin trackear:**
- `backend/app/Console/Commands/RuleTagMismatchResolutionCommand.php`
- `backend/app/Domain/RuleEngine/Services/MismatchResolutionAuditService.php`
- `backend/demo/` (contenido no relacionado con esta campaña, verificar antes de tocar)
- `backend/tests/Feature/REM/SectionDetectorServiceLeadingTotalRowLabelRealFileTest.php`
- `backend/tests/Feature/RuleEngine/MismatchResolutionApiTest.php`
- `backend/tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceExistentialEvidenceSearchTest.php`
- `backend/tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceHorizontalTwoComponentTest.php`
- `backend/tests/Feature/RuleEngine/Services/SectionCalibrationMatrixServiceRowLevelValidationGateTest.php`
- `backend/tests/Unit/RemParser/Services/SectionDetectorServiceLeadingTotalRowLabelTest.php`
- `backend/tests/Unit/RemParser/Services/SectionDetectorServiceRichTextMarkerTest.php` (de sesión anterior, aún sin commit)
- `frontend/src/features/rule-engine/components/patterns/MismatchResolutionPanel.tsx`

**Persistido en BD real (no en git, ver checkpoint numérico arriba)**: 38 tags en `mismatch-resolution-audit.json`; 19 patrones reales reconfirmados en `reglas-funcionales.json` (fingerprint_version=2, `revalidated_by`/`revalidated_at`/`revalidation_source_type` agregados — nunca se tocó `response`/`reviewed_by`/`reviewed_at`/`review_status`); estructura 67/v35 activa (creada/activada hoy a partir del patch de A30).

## Reglas de seguridad vigentes — Fase 3 / flujo MISMATCH

- **No rebind a estructura 67.**
- **No ejecutar `reconcileLiveCanonical()`** (existe en `PatternReconciliationService` pero nunca se invoca desde código de producción — solo en tests aislados).
- No producción, no servidor expuesto, no deploy.
- **No reconfirmación masiva/automática** — siempre patrón por patrón, con verificación antes y después.
- **No modificar decisiones históricas** (`response`) para hacer "desaparecer" un MISMATCH.
- **No convertir `HUMAN_REVIEW` en `SAFE_RECONFIRM`** sin evidencia real nueva revisada por un humano.
- **No reconfirmar los 3 `REQUIRES_INVESTIGATION`** (`A09/G P2`, `A09/G P4`, `A32/D1 P3`) sin decisión explícita futura sobre qué hacer con ellos.
- **No tocar `A05/U` ni `A30/C`** por este flujo (estructural histórico conocido, fuera de alcance).
- **No calibrar `A05/V` ni `A30/D`** dentro de esta campaña (son `NEW_SECTION`).
- **No mezclar `A11a`** (10 secciones `QUICK_CONFIRMATION`) con el flujo MISMATCH — son flujos y endpoints distintos.
- **No commit ni push** de nada de lo listado arriba sin autorización explícita.
- Todas las restricciones generales de Fase 3/Fase 2 más abajo (no rebind a 63, no repetir Fase A/B, etc.) **siguen vigentes** — nada de eso cambió hoy.
- **Si al reanudar mañana el estado vivo (MISMATCH, tags, `rem_rules`, `rem_rule_bindings`, estructura activa) difiere de este checkpoint: STOP y reportar la discrepancia antes de escribir absolutamente nada.**

---

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

> ⚠ **NOTA DE ACTUALIZACIÓN (2026-08-26)**: los conteos "303/303" y "0 pendientes" de esta sección eran correctos **el 2026-08-11**, pero quedaron superados por la evolución posterior de la estructura activa (crecimiento a 67/v35, secciones nuevas detectadas). Esta sección se conserva íntegra como registro histórico — **no se reescribe**. El estado real vigente hoy está documentado en la sección "2026-08-26 (CIERRE CAMPAÑA MISMATCH...)" al inicio de este archivo: 306 secciones aplicables, 304 completadas, 2 pendientes (`A05/V`, `A30/D`, deliberadamente fuera de alcance, no un retroceso).

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
- **A33 (cerrada estructuralmente)** — usuario aprobó el patch completo (estructura activa ID 63). Pendiente de calibración funcional manual — nunca calibrada, 0 patrones revisados en las 5 secciones (A,B,C,D,E). **[SUPERADO — ver nota 2026-08-26 al inicio del archivo: A33 se calibró funcionalmente más tarde ese mismo día (2026-08-11, ~15:17-15:18), 9/9 patrones revisados por Francisco Arcos. Esta nota quedó desactualizada por no haberse editado tras esa calibración — verificado en vivo el 2026-08-26 contra BD y scanner real: A33 = 5/5 secciones AUTO_MIGRATE, 100% completada.]**

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

   **Actualización 2026-08-26 (auditoría READ-ONLY de los 13 `BLOCKED_BY_ENGINE_GAP` expuestos por el fix de `row_range={0,0}` en `RuleBindingReconciliationService`, commit `ac14ee0`, ids 56, 208, 214, 226-234, 354)** — confirma que deuda técnica #5 tiene **dos subtipos distintos**, no uno solo:
   - **Subtipo A — TOTAL único excluido del modelo de datos** (reglas **56** `A03/D.7` columna AH, fila candidata 208; **208** y **214** `A09/F.1` columnas F/L, fila candidata 158 — misma fila para ambas). Auditoría inicial del mismo día había concluido erróneamente que estas 3 eran casos "config-only" (bastaba completar `total_row`) por no revisar `valor_bruto` de la columna de concepto de esas filas. **Conclusión corregida, reemplaza la anterior**: se invocó directamente `SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()` (mecanismo #12) contra la estructura viva 67/v35 — retorna `true` para ambas filas (`A208`/`A158` contienen literalmente el texto `"TOTAL"`, y el resto de columnas de cada fila son fórmulas que agregan exclusivamente filas anteriores de la sección). Confirmado además que ninguna de las dos filas aparece en los `pattern_rows` vivos que devuelve `PatternMigrationScanner::scanAllSections()`, y que **no existe ningún registro histórico en `rem_data`** para `A03/D.7` fila 208 ni para `A09/F.1` fila 158 (0 confirmado por consulta directa). Es decir: son filas TOTAL reales, correctamente excluidas por el mecanismo #12 — **no debe agregarse `total_row` a estas 3 reglas como solución**, porque apuntaría a una fila que nunca tiene (ni puede tener, mientras el mecanismo #12 siga vigente) un valor persistido contra el cual evaluar la suma. Las reglas 56, 208 y 214 **permanecen correctamente clasificadas como `BLOCKED_BY_ENGINE_GAP`** — es el comportamiento esperado del motor dado el gap arquitectónico, no un defecto del clasificador.

   **[CORRECCIÓN — ver punto 17.20, 2026-08-27]**: la frase "no existe ningún registro histórico en `rem_data`" para `A09/F.1` fila 158 es **imprecisa** — la auditoría exhaustiva de Fase 3C-3 encontró **119 registros históricos reales** para esa fila (`upload_id` 36-175, `concept="TOTAL"`, `values.F=0` explícito, no null), todos de **cargas anteriores al 2026-08-10 ~19:00** (activación del mecanismo #12 para esta sección). Las 6 cargas posteriores (`upload_id` 176-184) que sí tienen datos de `A09/F.1` muestran **0 registros para la fila 158**, confirmando que el mecanismo protege correctamente las cargas nuevas — el patrón es idéntico al ya documentado para A01/A23/A26/A28/A29 (deuda técnica #4: "protección de pipeline ya activa, histórico no limpiado"), simplemente no se había verificado `rem_data` con esa granularidad para F.1/158 en el momento de esta entrada. **No cambia ninguna conclusión de diseño** — la fila sigue excluida del modelo de datos para toda carga futura, `total_row` sigue sin poder agregarse de forma útil — solo corrige el dato puntual "0 histórico" a "0 desde el fix, 119 antes". No verificado si aplica la misma corrección a `A03/D.7` fila 208 (regla 56, ya resuelta y cerrada — fuera de alcance de esta verificación).
   - **Subtipo B — agregación periódica / múltiples filas TOTAL** (`A09/I`, reglas **226-234**, 9 reglas, columnas AM,AN,AQ,AR,AS,AT,AU,AV,AX). Forma distinta y más compleja que el subtipo A: no existe una única fila TOTAL por columna, sino **6 filas** (331-336), todas dentro del rango vivo de la sección, cada una sumando con paso fijo de 6 a través de ~13 bloques repetidos (ej. `AM331 = AM253+AM259+...+AM325`), con al menos una irregularidad detectada (`AR333` incluye un término `AR337` fuera del patrón de las demás filas). No encaja en la convención de una sola fila `total_row`. **Congelado explícitamente como deuda de diseño separada** — no forzar un `total_row` de una sola fila, no tocar sin decisión de diseño nueva (posible concepto `block_total_rows` o similar).
   - **`A25/B` (regla 354) permanece fuera de alcance** — hoja `A25` es `no_utilizada`, irrelevante para el diseño de esta deuda técnica.
   - **Nuevo criterio obligatorio antes de proponer `total_row` para cualquier regla `sum_equals` vertical**: no basta verificar que la fila candidata coincide con `filaFinDatos` de la sección viva y que su fórmula referencia solo filas anteriores válidas. Debe verificarse ADEMÁS, explícitamente, que esa fila **no** sea excluida por los mecanismos estructurales #6/#8/#12 (comprobación directa: invocar el método de clasificación correspondiente, y/o confirmar que la fila aparece en los `pattern_rows` vivos del scanner) y que **existan registros reales en `rem_data`** para esa fila (o que puedan existirlos bajo el modelo de persistencia vigente) — de lo contrario la regla nunca podrá evaluarse en producción aunque la config quede "completa" con un `total_row` asignado.
   - Auditoría 100% READ-ONLY: no se modificaron reglas, bindings, estructura, `rem_data`, calibraciones ni código funcional. Sin commit/push.

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
