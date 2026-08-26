# Checkpoint ATHENEA — cierre de jornada 2026-08-24

Continuación directa de la Fase 3 (motor de reglas REM, campaña de resolución
MISMATCH) documentada en `CLAUDE.md` (sección "Estado al cierre de la sesión
— 2026-08-21"). **Leer este archivo primero al retomar mañana** — es
autosuficiente, no depende del historial del chat.

---

## 1. Estado final verificado al cierre de hoy

| Métrica | Valor |
|---|---|
| Secciones `MISMATCH` | **16** |
| Secciones `FULL_REVALIDATION` | **0** |
| Tags en `mismatch-resolution-audit.json` | **87** |
| `rem_rules` | **764** |
| `rem_rule_bindings` | **1204** |
| Estructura activa | **ID 67 / versión 35** |
| Bindings a estructura 67 | **0** |
| SHA-256 `reglas-funcionales.json` (final, post-reparación) | `66d446569f7d4b693f53f6c7a0e240d3ff04427cd02e084bc3ae4bbf2546f0c1` |

Todo verificado en vivo contra la BD real y el archivo real al cierre de hoy
(no es un valor recordado de una sesión anterior).

---

## 2. A32/D1 — trabajo de hoy (cerrado)

Uno de los 3 `REQUIRES_INVESTIGATION` heredados del checkpoint anterior
(2026-08-21: `A09/G P2`, `A09/G P4`, `A32/D1 P3`).

- **Auditoría forense en vivo** contra `cell_data` real confirmó: fila 58
  ("Geriatría"), fórmula TOTAL `B58=SUM(C58:S58)` referencia las 19 columnas
  completas de rango etario, pero solo `O:S` (60-64 a 80+) + `T:U` (sexo) son
  genuinamente editables — las columnas `C:N` (edades 0-4 a 55-59) están
  bloqueadas por no ser clínicamente aplicables a Geriatría. A diferencia de
  su hermano P2/Neonatología, cuya fórmula sí fue acotada en el Excel
  original (`SUM(C:D)`), en Geriatría el Excel de origen nunca acotó la
  fórmula — es una inconsistencia del template Excel, no del motor.
- **Auditoría histórica exhaustiva**: 137 cargas reales revisadas
  (`upload_id` 19-182) — columnas `C58:N58 = null` en las 137, sin una sola
  excepción, total siempre 0. Confirma que la decisión histórica
  `debe_registrar_cero` es 100% compatible con el histórico real.
- Tagueado como `safe_reconfirm` (con `reason` extenso documentando la
  excepción — no se creó una categoría nueva separada, se usó
  `safe_reconfirm` con justificación explícita) junto con P1 y P2 (subconjunto
  seguro completo de la sección, Tanda 2B-6).
- **Los 3 patrones (P1, P2, P3) confirmados manualmente por el usuario en
  ATHENEA. Post-check en vivo hoy confirma: `A32/D1` → categoría de sección
  `AUTO_MIGRATE`, los 3 patrones `AUTO_MIGRATE`.** A diferencia de A09/G, en
  A32/D1 el `pattern_id` posicional en vivo coincidía exactamente con el
  histórico para los 3 patrones — la escritura nunca sufrió el bug de
  identidad (ver sección 3). **A32/D1 queda cerrado, sin pendientes.**

---

## 3. A09/G — investigación, fixes y reparación completa (cerrado)

### 3.1 Contexto e incidente

Al aplicar el mecanismo #6 (TOTAL líder, ver sección 4) a A09/G, la exclusión
de las filas TOTAL líder (183, 196) desplazó la numeración posicional de los
patrones vivos. El sistema de emparejamiento por identidad de contenido
(`matchLivePatternsToHistorical()`, sección 5) resolvía correctamente la
identidad para el **gate** de validación, pero `FunctionalRuleService::applyQuickRevalidation()`
seguía escribiendo filtrando por el `pattern_id` **posicional en vivo**, no
por el histórico ya resuelto.

**Consecuencia real**: al confirmar manualmente en ATHENEA `A09/G P3` (vivo,
row-set `[190,191]`, cuya identidad histórica correcta es `pattern_id=2`,
`[183,190,191]`), la escritura fue al registro crudo `pattern_id=3` — que en
realidad representaba un patrón distinto, ya resuelto en la Tanda 2B-6
(`[184-189]`). Resultado: el registro histórico 2 nunca se actualizó
(quedó con fingerprint/rows obsoletos, `revalidated_by=NULL`) y el registro
histórico 3 quedó sobrescrito con datos que no le correspondían. La sección
cayó a `FULL_REVALIDATION` (detectado por post-check, no oculto).
`A09/G P4` no sufrió el bug (su `pattern_id` vivo ya coincidía con el
histórico, sin desplazamiento).

### 3.2 Fix de causa raíz (genérico, no un parche puntual para A09/G)

`FunctionalRuleService::applyQuickRevalidation()`: parámetro renombrado
`$patternId` → `$historicalPatternId`; el filtro de escritura ahora usa
`($q['pattern_id'] ?? null) !== $historicalPatternId`. `PatternMigrationScanner::scanSection()`
expone `historical_pattern_id` (identidad ya resuelta) en las 3 ramas
(vacío/v2/legacy). `CalibrationViewController::confirmQuickRevalidation()` y
`confirmMismatchResolution()` extraen `historical_pattern_id` del plan de
sección y lo propagan a la escritura, con guard 409
`historical_identity_unresolved` si no está resuelto. **Misma identidad
usada para validar el gate y para escribir, en todo el camino.**

### 3.3 Reparación de los datos corruptos

Ejecutada hoy, con autorización explícita, tras backup y verificación
exhaustiva de evidencia (ver secciones 8 y 9).

- **Registro histórico `pattern_id=2`** (nunca había recibido su escritura
  correcta): actualizado a `pattern_fingerprint=fpv2_3f84a7496c12bad0`,
  `pattern_rows=[190,191]`, `revalidated_by=Administrador Esalud`,
  `revalidated_at=2026-08-24T16:51:06+00:00`,
  `revalidation_source_type=structural_row_exclusion`. Fuente: tag
  `A09_G_rowset_ae96ae3e5e40f19c` + entrada de `_questions_history` a esa
  misma hora.
- **Registro histórico `pattern_id=3`** (restaurado a su estado correcto de
  Tanda 2B-6, previo al clic erróneo): `pattern_fingerprint=fpv2_79c2e8d8b8d51f0c`,
  `pattern_rows=[184,185,186,187,188,189]`, `revalidated_by=Administrador Esalud`,
  `revalidated_at=2026-08-24T14:41:42+00:00`,
  `revalidation_source_type=manual_revalidation`. Fuente: tag legacy
  `A09_G_3` (intacto durante todo el incidente) + entrada de
  `_questions_history` a esa misma hora + valores idénticos confirmados en
  los registros hermanos de la misma tanda (`pattern_id=1,5,6`, mismos
  timestamps ~14:41).
- Campos protegidos (`response`/`reviewed_by`/`reviewed_at`/`review_status`)
  **nunca tocados** — verificado que ya tenían el valor correcto (nunca
  fueron escritos por la corrupción, esta clase de campo está fuera del
  contrato de `applyQuickRevalidation()`).
- `pattern_id=4`: verificado correcto, nunca corrupto, no requirió
  reparación.

### 3.4 Post-check final tras la reparación

- A09/G → **`AUTO_MIGRATE` en las 14 posiciones vivas de la sección**;
  identidad resuelta en ambos sentidos (live P2→histórico 3, live P3→histórico 2).
- `FULL_REVALIDATION`: 1 → **0**.
- `MISMATCH` global: **16**, sin ninguna sección inesperada.
- Diff completo del archivo reparado contra el backup `PRE_REPAIR`
  (excluyendo los 12 campos deliberadamente cambiados): **0 diferencias**.
- Tags (87), `rem_rules` (764), `rem_rule_bindings` (1204), estructura
  (67/v35), bindings a 67 (0): todos verificados intactos.

**A09/G queda cerrado, sin pendientes.**

---

## 4. Mecanismo #6 — TOTAL líder, wireado a la matriz de calibración

`SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()` (ya existente
como mecanismo del parser, documentado en `CLAUDE.md` sección 2026-08-11)
fue hecho **público** y **wireado en `classifyRow()`** — antes solo protegía
la persistencia real (`RemParserService`), nunca afectaba la matriz de
patrones de calibración. Una fila con columna de concepto poblada
("Altas integrales", etc.) + marcador "TOTAL"/"AMBOS SEXOS" en alguna
columna de texto + fórmulas que agregan EXCLUSIVAMENTE filas posteriores
(referencias a la propia fila son neutrales, cualquier referencia hacia
atrás descalifica) se clasifica como `'total'`, no `'data'` — excluida de
patrones/calibración, igual que el mecanismo #12 (subtotal embebido hacia
atrás).

Aplicado y verificado en A09/G (filas 183, 196) y confirmado que generaliza
sin regresión sobre las secciones ya calibradas.

---

## 5. Fix de identidad estable de patrones — `matchLivePatternsToHistorical()`

**Causa raíz descubierta**: `pattern_id` es puramente posicional (orden de
primera aparición durante `buildDynamicPatternDefinitions()`). Excluir una
fila (ej. TOTAL líder) desplaza TODOS los `pattern_id` posteriores, rompiendo
cualquier comparación 1:1 contra el histórico guardado por `pattern_id`.

**Diseño implementado** en `PatternMigrationScanner::matchLivePatternsToHistorical()`
— identidad basada en contenido (conjunto de filas), 3 fases, nunca adivina
bajo ambigüedad:

1. **Match exacto** (mismo conjunto de filas).
2. **Subconjunto/superconjunto** (único en ambos sentidos).
3. **Solapamiento Jaccard ≥ 0.5** (único en ambos sentidos).

Candidatos ambiguos (split/merge) quedan explícitamente **excluidos**, no
"resueltos" por una fase más laxa — se rastrean en conjuntos de exclusión
separados (`$excludedLive`/`$excludedHist`) del conjunto de matches
confirmados, para que la fase 3 nunca reabra algo que la fase 2 ya marcó
ambiguo.

Usado por: `scanSection()` (matching de preguntas históricas — expone
`historical_rows` y `historical_pattern_id`), `RuleTagMismatchResolutionCommand`
(búsqueda de filas históricas), y ahora `applyQuickRevalidation()` (la
escritura misma, ver sección 3.2).

---

## 6. Fix de identidad estable del almacén de tags — `MismatchResolutionAuditService`

**Segundo bug de identidad, independiente del anterior**: la clave de
almacenamiento de tags era `"{sheet}_{section}_{patternId}"` — también
posicional. Tras el desplazamiento de A09/G, taguear el "pattern_id=3" vivo
de hoy habría sobrescrito el tag legacy `A09_G_3` (Tanda 2B-6, un patrón
distinto ya resuelto).

**Rediseño**: clave estable basada en contenido —
`"{sheet}_{section}_rowset_{16hex}"` (`rowSetFingerprint()`, SHA-256[0:16]
de las filas ordenadas/deduplicadas). `getTag()` intenta primero la clave
estable; si no existe, cae a la clave legacy **solo si su contenido
(`audited_rows`) coincide con las filas vivas actuales** — nunca un fallback
ciego. Nuevo método de solo lectura `auditKeys()` para detección de
colisiones. Verificado contra los 87 tags reales: **0 colisiones**.

---

## 7. Flujo `structural_row_exclusion` (nueva categoría de resolución)

Categoría independiente, nunca relaja el gate de `safe_reconfirm`
(igualdad estricta de filas). Gate mecánico en
`RuleTagMismatchResolutionCommand`: `union(filas_vivas, filas_total_excluidas) === filas_históricas`,
ninguna fila agregada, cada fila excluida re-verificada en vivo vía
`isEmbeddedLeadingTotalRow()`. Auditoría completa almacenada en el tag:
`historical_rows`, `excluded_total_rows`, `exclusion_mechanism`.

Endpoint real: `CalibrationViewController::confirmMismatchResolution()`
acepta `safe_reconfirm` **o** `structural_row_exclusion`, reutiliza
`applyQuickRevalidation()` (extendida con parámetros opcionales, escribe
metadata de exclusión únicamente en `_questions_history`, nunca viola el
contrato de los 6 campos protegidos). Frontend:
`MismatchResolutionPanel.tsx` — tarjeta distinta (icono Scissors, paleta
cian, tabla de filas histórica/viva/excluida, botón "Confirmar exclusión
estructural validada").

Usado para: A09/G P3/P4 (hoy, con el incidente y reparación de la sección 3).

---

## 8. Tests y resultado de regresión

Archivos nuevos (todos passing):
- `SectionCalibrationMatrixServiceEmbeddedLeadingTotalRowTest.php`
- `PatternMigrationScannerIdentityMatchingTest.php`
- `RuleTagMismatchResolutionCommandIdentityTest.php`
- `RuleTagMismatchResolutionCommandStructuralExclusionTest.php`
- `StructuralRowExclusionConfirmTest.php`
- `MismatchResolutionAuditServiceIdentityTest.php`
- `ApplyQuickRevalidationWriteIdentityTest.php` (6 tests — reproduce
  exactamente la topología de A09/G: P1=[10,11]/P2=[12,19,20] con fila 12
  TOTAL líder/P3=[13,14] no relacionado; confirma que la escritura siempre
  apunta al `pattern_id` histórico correcto y que el patrón no relacionado
  permanece intacto byte a byte)

**Regresión completa `tests/Feature/RuleEngine`**: **391 passed / 35 failed
(exactamente los mismos preexistentes ya documentados en sesiones
anteriores) / 0 regresiones nuevas.**

---

## 9. Backup y hash

- Backup pre-reparación:
  `C:\Users\INFORMATICA\Desktop\Esalud\backups\reglas-funcionales_PRE_REPAIR_20260824.json`
  (SHA-256 `b83c10069e32d55fc9d57764fb900b78e1f6f1f409621d07d1d566865c1f7f13`
  — idéntico al archivo real justo antes de escribir la reparación).
- Archivo real reparado, SHA-256 final:
  `66d446569f7d4b693f53f6c7a0e240d3ff04427cd02e084bc3ae4bbf2546f0c1`.
- El backup **se conserva**, no se ha borrado.

---

## 10. Los 16 MISMATCH restantes

```
A04/F, A04/I.1, A04/I.2, A05/U, A07/A, A08/M, A11/E,
A27/D, A27/E, A27/F, A27/K, A30/A, A30/C, A32/L, A32/M, A32/N
```

**14 secciones `HUMAN_REVIEW`** (requieren revisión funcional humana, no
automatizable con este flujo — sin tocar):
`A04/I.1, A04/I.2, A04/F, A07/A, A08/M, A11/E, A27/D, A27/E, A27/F, A27/K, A30/A, A32/L, A32/M, A32/N`

**2 secciones aparte, MISMATCH estructural histórico conocido, preexistente,
fuera de alcance de este flujo — punto de partida de mañana (ver sección 13)**:
`A05/U`, `A30/C`

---

## 11. Explícitamente fuera del próximo bloque de trabajo

- **`A05/V`, `A30/D`** — categoría `NEW_SECTION`. Nunca calibrar dentro de
  esta campaña de resolución de MISMATCH.
- **`A11a`** (10 secciones: A, C, E, F, G, H, I, J, K, N) — categoría
  `QUICK_CONFIRMATION`, flujo completamente distinto
  (`confirmQuickRevalidation`/`QuickRevalidationPanel.tsx`), funcionando
  normalmente, nunca mezclar con el flujo MISMATCH.

---

## 12. Incidente aparte — carga REM del servidor (no relacionado con la
campaña de reglas)

Durante la jornada se reportó una carga REM aparentemente estancada al 50%.
**Diagnóstico: el worker de colas funcionaba correctamente** — el estancamiento
aparente se debió a un **401 por sesión expirada** en el cliente (frontend),
no a un problema real de procesamiento backend/worker. No requirió ninguna
acción sobre el motor de reglas, la BD de reglas, ni el pipeline REM. Sin
relación con la campaña MISMATCH documentada arriba.

---

## 13. Reglas de seguridad vigentes — qué NO hacer al retomar

- **No rebind** a ninguna estructura.
- **No ejecutar `reconcileLiveCanonical()`** (existe en
  `PatternReconciliationService`, nunca se invoca desde código de
  producción — solo en tests aislados).
- **No producción, no servidor expuesto, no deploy.**
- **No tocar `HUMAN_REVIEW` todavía** — las 14 secciones listadas en la
  sección 10 requieren revisión funcional humana explícita antes de
  cualquier acción; no convertir a `SAFE_RECONFIRM` sin evidencia real nueva
  revisada por un humano.
- **No reconfirmación masiva/automática** — siempre patrón por patrón, con
  verificación antes y después.
- **No modificar decisiones históricas** (`response`) para hacer
  "desaparecer" un MISMATCH.
- **No mezclar `A11a`** con el flujo MISMATCH — flujos y endpoints distintos.
- **No commit ni push** de nada sin autorización explícita.
- **No tocar `A05/V` ni `A30/D`** (categoría `NEW_SECTION`).
- Todas las restricciones generales de Fase 3/Fase 2 documentadas en
  `CLAUDE.md` **siguen vigentes**.

---

## 14. PUNTO DE REANUDACIÓN DE MAÑANA

**Comenzar exclusivamente con una auditoría profunda READ-ONLY de `A05/U` y
`A30/C`** (los 2 MISMATCH estructurales históricos conocidos, fuera de las 14
`HUMAN_REVIEW`). **No modificar ni taguear nada** hasta:
1. Entender la causa exacta de cada uno de los 2 casos (evidencia real
   contra `cell_data`/Excel, no solo la clasificación previa).
2. Medir el impacto global de cualquier corrección propuesta.
3. **STOP y presentar hallazgos para autorización explícita antes de
   escribir, tag­uear o confirmar nada.**

---

## 15. Prompt de reanudación para Claude (copiar/pegar mañana)

```
Retomamos la campaña de reconciliación del motor de reglas REM (Fase 3).
Lee primero, completo, C:\Users\INFORMATICA\Desktop\Esalud\docs\checkpoints\ATHENEA_CHECKPOINT_2026-08-24.md
— es autosuficiente, contiene el checkpoint exacto de cierre de la jornada
anterior. También puedes revisar CLAUDE.md (raíz del proyecto) para el
contexto histórico completo de las fases previas (A/B/C, 2026-08-11 a
2026-08-21), pero NO repitas ningún trabajo ya cerrado ahí.

Antes de tocar cualquier cosa, verifica en vivo que el estado actual
coincide EXACTAMENTE con el checkpoint documentado en la sección 1 de ese
archivo: MISMATCH=16, FULL_REVALIDATION=0, tags=87, rem_rules=764,
rem_rule_bindings=1204, estructura activa=67/v35, bindings a 67=0, y que el
SHA-256 de reglas-funcionales.json coincide con el reportado como "final"
en la sección 9. Si CUALQUIER valor difiere: STOP inmediato y reporta la
discrepancia antes de escribir nada — no asumas, no fuerces, no intentes
"corregir" el checkpoint.

Si el estado coincide, el primer y único paso autorizado por ahora es:

AUDITORÍA PROFUNDA READ-ONLY de A05/U y A30/C (los 2 MISMATCH estructurales
históricos conocidos, ver sección 10 del checkpoint — separados
deliberadamente de los 14 HUMAN_REVIEW). Para cada uno:
- Reconstruye la causa exacta contra evidencia real (cell_data/Excel real,
  no solo la clasificación heredada).
- Determina si es genuinamente resoluble con alguno de los mecanismos ya
  existentes (mecanismo #6 TOTAL líder, mecanismo #12 subtotal embebido,
  structural_row_exclusion) o si requiere un tratamiento nuevo.
- Mide el impacto global de cualquier corrección que propongas antes de
  proponerla (cuántas secciones/patrones tocaría, si generaliza o es
  puntual).

NO modifiques código, NO taguees, NO confirmes nada. Al terminar la
auditoría, presenta los hallazgos completos y haz STOP explícito, esperando
autorización antes de cualquier acción de escritura.

Restricciones vigentes (ver sección 13 del checkpoint, no repetir aquí):
no rebind, no reconcileLiveCanonical(), no producción, no tocar HUMAN_REVIEW
todavía, no tocar A05/V/A30/D/A11a, no commit/push sin autorización.
```
