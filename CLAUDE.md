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

## ESTADO VIGENTE ÚNICO — REM A end-to-end certificado (2026-08-31)

**Veredicto: `REM_A_END_TO_END_CERTIFICADO`.** La campaña Fase 3 del motor de reglas REM Serie A quedó cerrada técnicamente: 474 reglas `SAFE_1_TO_1`, 451 de ellas con binding real a la estructura activa (67/v35), y una carga de certificación real (`upload_id=187`) confirmó ejecución real de producción — no solo simulación — para los 9 mecanismos del motor, incluyendo los dos que nunca antes se habían ejercido con datos reales (las 55 reglas "trailing-beyond-bounds" y la regla 461 "leading-formula-based").

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

## Pendientes conocidos — no bloqueantes, ninguno con fecha, ninguno resuelto en esta campaña

- **Regla `229`** (`A09/I`, columna AR, offset/`total_row=333`) — bloqueada por `AR337`, una referencia espuria e inocua (matemáticamente = 0) del template Excel de origen a una celda vacía fuera de toda sección. 4 opciones de tratamiento documentadas (archivo histórico Fase 3, punto 17.28.4), ninguna elegida. **No tocar `AR337` ni la fila 333 de `A09/I`.**
- **Regla `230`** (`A09/I`, columna AS) — mapeo disperso ambiguo entre sus 6 posibles combinaciones periódicas (1 completa/limpia ya resuelta, 4 parciales, 1 con término mal referenciado). Requiere decisión funcional de Estadística APS, no resoluble con más evidencia técnica. **No decidir automáticamente su destino.**
- **`A30/C pattern_id=1`** — único `MISMATCH` de calibración en toda la Serie A. Columnas J/K/L nuevas (bloque "Modalidad", Nivel Primario) sin decisión histórica — requiere calibración funcional de Estadística APS desde la interfaz ordinaria de ATHENEA, no una decisión de este asistente.
- **56 secciones `NO_UTILIZADA`** (hojas A21, A24, A25, A30AR, A34) — fuera de alcance de cualquier campaña mientras Estadística APS no las reactive vía `rem:set-sheet-usage-status`.
- **14 reglas `DUPLICATE`** (`24,553,557,558,559,617,585,602,560,618,29,580,126,127`) — 8 son deuda de catálogo confirmada (duplicado exacto o subset/superseded, sin funcionalidad real faltante); 6 (`A01/A/C`: `24,553,557,558,559,617`) son genuinamente ambiguas (rangos de fila solapados, mezcla de proveniencia `csv_catalog`/`vetted_catalog`) y requieren revisión humana opcional, no automatizable.
- **Reglas `130`/`133`** — artefactos autorreferenciales rotos (`Suma(D)=Columna D`, 100% `skipped` en su historial), candidatas a `status=inactive`, no desactivadas, no urgente.
- **Flakiness de tests ya documentada** (punto 17.39.5 del archivo histórico) — 9 fallos "flaky/order-dependent" al correr la suite completa en un solo proceso (no aparecen si el archivo se corre aislado), no atribuibles a ningún cambio de código, no investigados, no bloqueantes.
- **Diseño residual de B2/B3/CategoríaF** (`A09/I`, reglas origen `226,227,228,229,230,231,232,233,234`) — `status=active`, `config` intacta, quedan en "expansión parcial permanente" (sus posiciones limpias ya viven como reglas hijas independientes: 25 de B2 + 9 de B3/CategoríaF). Ninguna decisión de diseño (Opción A/B, `config['aggregations']`, etc.) fue tomada para el residuo — no implementar sin autorización.

## Estado Git auditado (Fase 17.55, reconfirmar antes de cualquier commit)

Rama `main`, 6 commits locales por delante de `origin/main` (previos a esta campaña, ya committeados, nunca pusheados). Working tree con 55 entradas verificadas en 17.55 (14 modificadas + `CLAUDE.md` + 40 nuevas), 0 staged — más `docs/handoffs/` (nuevo, creado en 17.56, documentación pura, sin código).

**52 archivos `REM_A_CAMPAIGN_CONFIRMED`** (verificados diff por diff contra su fase de origen — nombres de función/constante agregados coinciden exactamente con lo documentado):
- 13 archivos de motor modificados: `RemReprocessCommand.php`, `RuleTagMismatchResolutionCommand.php`, `ProcessRemUploadJob.php`, `RemUpload.php`, `ParseResult.php`, `RemParserService.php`, `CalibrationViewController.php`, `SumEqualsEvaluator.php`, `MismatchResolutionAuditService.php`, `PatternMigrationScanner.php`, `RuleBindingReconciliationService.php`, `RuleEngineService.php`, `SectionCalibrationMatrixService.php`.
- `CLAUDE.md` (este archivo, más los dos históricos nuevos).
- 12 comandos nuevos: `RuleActivateCategoryATotalCommand.php`, `RuleActivateCategoryCLeadingCommand.php`, `RuleActivateLeadingFormulaTotalBeyondBoundsCommand.php`, `RuleActivateSourceRowsCommand.php`, `RuleActivateTrailingTotalBeyondBoundsCommand.php`, `RuleExpandA09IAggregationCommand.php`, `RuleRemapSectionCommand.php`, `RuleRestoreConfigVersionCommand.php`, `RuleSetRuleStatusCommand.php`, `RuleSetTotalRowFromDiscoveryCommand.php`.
- 4 archivos nuevos de servicios/modelo/migración: `RemTechnicalTotal.php`, `FormulaRangeCoverageAnalyzer.php`, `MergeAnchorResolver.php`, `2026_08_27_000001_create_rem_technical_totals_table.php` (ya migrada contra `esalud_dev`).
- 24 tests nuevos (Feature/REM, Feature/RuleEngine, Unit/RuleEngine) — cada uno verificado presente y correspondiente a su punto de origen.

**Archivos que deben EXCLUIRSE del commit REM A** (identificados, no tocados, no borrados):
- `frontend/vite.config.ts` — fix de puerto proxy local (8080→8000), incidente de entorno ajeno a REM (login/desarrollo local). Decidir aparte si va en un commit separado de infraestructura.
- `backend/app/Console/Commands/DiagCheckAdminPasswordCommand.php` y `DiagResetAdminPasswordCommand.php` — comandos de diagnóstico temporal del mismo incidente de login local, auto-documentados como "borrar cuando termine el diagnóstico". No relacionados con REM A.
- `backend/demo/` (1 archivo, `calibracion-fila-12.php`) — preexistente, no relacionado con esta campaña, no tocar.

**Veredicto de 17.55: `REM_A_READY_FOR_CLEAN_COMMIT`** — sujeto a decidir, antes de comitear, el destino de `vite.config.ts` y de los 2 comandos `Diag*`. Ninguna decisión bloquea el estado técnico de REM A.

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

## Próximo paso vigente

Ninguno de los siguientes tiene prioridad automática — todos requieren autorización explícita, turno a turno, igual que el resto de la campaña:

1. Decidir si se autoriza el commit de los 52 archivos `REM_A_CAMPAIGN_CONFIRMED` (y qué hacer con `vite.config.ts`/los 2 `Diag*`).
2. Decidir el futuro de `AR337`/fila 333 de `A09/I` (4 opciones documentadas, ninguna elegida) — habilitaría crear las combinaciones `total_row=333` restantes de B2/B3.
3. Decidir si se autoriza revisión humana de los 6 casos ambiguos `A01/A/C` y/o desactivación de `130`/`133`.
4. Decidir qué hacer con la regla `230` (requiere insumo de Estadística APS, no solo técnico).
5. Calibración funcional de `A30/C` P1 — corresponde a Estadística APS desde la interfaz ordinaria, no a este asistente.
6. Decidir si se autoriza backfill histórico sobre las cargas existentes (ninguna autorización concedida hasta ahora).

No iniciar ninguno de estos por iniciativa propia. Si al reanudar el estado real (BD/Git) difiere de lo documentado aquí: **STOP y reportar la discrepancia antes de escribir nada.**
