# Arquitectura del REM Parser — Documento Técnico de Cierre

## Fases 1–4 (Julio 2026)

---

## 1. Arquitectura General

```
┌──────────────────────────────────────────────────────────────────────┐
│                        app/Domain/RemParser/                         │
│                                                                       │
│  ┌──────────────┐    ┌────────────────────┐    ┌──────────────────┐  │
│  │    DTOs       │    │     Services        │    │     Models        │  │
│  │  (7 clases)   │◄──►│   (11 clases)       │◄──►│  (1 clase)       │  │
│  └──────────────┘    └────────────────────┘    └──────────────────┘  │
│                              │                                        │
│         ┌────────────────────┴────────────────────┐                  │
│         ▼                                         ▼                  │
│  ┌──────────────┐                        ┌──────────────────┐        │
│  │ Comandos     │                        │   Migraciones    │        │
│  │ Artisan (5)  │                        │   (2 archivos)    │        │
│  └──────────────┘                        └──────────────────┘        │
└──────────────────────────────────────────────────────────────────────┘
         │
         │ depende de
         ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     Capa de Infraestructura                           │
│                                                                       │
│  ┌──────────────────────┐  ┌──────────────────────────────────────┐  │
│  │ phpoffice/            │  │ Laravel Framework 13.x               │  │
│  │ phpspreadsheet ^5.7   │  │ (Eloquent, Migrations, Artisan)      │  │
│  └──────────────────────┘  └──────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

El módulo `RemParser` es **totalmente independiente** del parser antiguo (`app/Domain/REM/`). No lo modifica, no lo hereda, no lo acopla. Se comunica con las tablas existentes solo para lectura/escritura controlada (`RemData`, `RemValidationResult`, `RemUpload`, `RemTemplate`).

---

## 2. Componentes Creados — 19 archivos

### 2.1 DTOs (`app/Domain/RemParser/DTOs/`)

| Archivo | Propósito | Propiedades clave |
|---|---|---|
| `ParsedTemplateDTO` | Template completo parseado | `anio`, `serie`, `hashEstructura`, `forms[]` |
| `ParsedFormDTO` | Una hoja del Excel | `sheetName`, `sections[]` |
| `ParsedSectionDTO` | Una sección dentro de una hoja | `codigo`, `titulo`, `filaHeader`, `filaInicioDatos`, `filaFinDatos`, `fields[]` |
| `ParsedFieldDTO` | Una columna/campo | `letra`, `label`, `esTotal`, `esControlOculto`, `reglaDetectada` |
| `ParsedFormulaRuleDTO` | Fórmula detectada y analizada | `tipo`, `columnasOrigen[]`, `columnaDestino`, `rangoFilas` |
| `PersistResult` | Resultado de persistencia | `model`, `wasCreated` |
| `ValidationRuleDTO` | Regla de validación compilada | `ruleKey`, `ruleType`, `sheet`, `targetColumn`, `sourceColumns[]`, `scope`, `rowFrom`, `rowTo`, `severity` |

### 2.2 Servicios (`app/Domain/RemParser/Services/`)

| Archivo | Rol | Método principal |
|---|---|---|
| `RemParserService` | **Orquestador principal** | `parse(filePath): ParsedTemplateDTO` |
| `MetadataExtractorService` | Extrae año y serie desde filename + hoja NOMBRE | `extract(spreadsheet, filePath): array{anio, serie}` |
| `SheetDetectorService` | Filtra hojas válidas (excluye NOMBRE, CONTROL, MACROS, ocultas) | `detect(spreadsheet): string[]` |
| `SectionDetectorService` | Detecta secciones (SECCIÓN N.X) + filtra agregadores | `detect(worksheet, sheetName): ParsedSectionDTO[]` |
| `ColumnDetectorService` | Detecta columnas, controles ocultos, analiza fórmulas | `detect(...): ParsedFieldDTO[]` |
| `FormulaAnalyzerService` | Analiza strings de fórmula Excel → clasifica + extrae referencias | `analyze(formula): ?ParsedFormulaRuleDTO` |
| `RemTemplateStructurePersistenceService` | Persiste estructura en BD, busca duplicados por hash, asigna versión | `persist(dto, ...): PersistResult` |
| `StructureVersioningService` | Calcula próximo version_number para (anio, serie) | `resolveNextVersion(anio, serie): int` |
| `StructureApprovalService` | Aprueba (draft→approved) y activa (approved→active, supersede anterior) | `approve()`, `activate()` |
| `RemFormulaRuleBuilder` | Convierte estructura JSON → ValidationRuleDTO[] | `build(structure): ValidationRuleDTO[]` |
| `RemFormulaValidationExecutor` | Ejecuta reglas contra rem_data, escribe en rem_validation_results | `execute(uploadId, rules): array` |

### 2.3 Commands Artisan

| Comando | Propósito |
|---|---|
| `rem:parse-persist {path}` | Parsea Excel + guarda/actualiza estructura en BD |
| `rem:approve-structure {id} --user=` | draft → approved |
| `rem:activate-structure {id}` | approved → active (supersede la anterior) |
| `rem:diff-structures {id1} {id2}` | Compara dos estructuras (hojas agregadas/eliminadas) |
| `rem:validate-structure {upload_id} {structure_id}` | Ejecuta validaciones automáticas contra datos reales |

### 2.4 Migraciones

| Archivo | Cambios |
|---|---|
| `000001_create_rem_template_structures_table.php` | Tabla base: id, FKs a rem_uploads/rem_templates, anio, serie, hash_estructura, estructura (JSON), metadata (JSON), source_filename, status, timestamps, softDeletes. UNIQUE(anio, serie, hash_estructura) |
| `000002_add_versioning_to_rem_template_structures_table.php` | Agrega: version_number, approved_at, approved_by (FK→users), superseded_by_id (FK→self), notes. Cambia UNIQUE a (anio, serie, version_number). Agrega INDEX(anio, serie, hash_estructura). Default status pasa a 'draft' |

### 2.5 Modelo

`app/Domain/RemParser/Models/RemTemplateStructure.php`
- Table: `rem_template_structures`
- SoftDeletes
- Casts: `anio: integer`, `version_number: integer`, `estructura: array`, `metadata: array`, `approved_at: datetime`
- Relationships: `remUpload()`, `remTemplate()`, `supersededBy()`, `supersedes()`

---

## 3. Modelo de Datos

### `rem_template_structures` (nueva)

| Columna | Tipo | Restricción | Notas |
|---|---|---|---|
| id | bigint unsigned | PK | |
| rem_upload_id | bigint unsigned | FK→rem_uploads, nullOnDelete | Opcional por ahora |
| rem_template_id | bigint unsigned | FK→rem_templates, nullOnDelete | Opcional por ahora |
| anio | smallint | NOT NULL | Año REM |
| serie | varchar(10) | NOT NULL | A, BM, BS, D, P |
| hash_estructura | varchar(64) | INDEX | MD5 de la estructura serializada |
| version_number | tinyint unsigned | DEFAULT 1 | Auto-asignado por anio+serie |
| estructura | json | NOT NULL | Árbol completo forms→sections→fields |
| metadata | json | NULL | metadatos adicionales |
| source_filename | varchar(255) | NULL | Archivo Excel original |
| status | varchar(20) | DEFAULT 'draft' | draft→approved→active→superseded |
| approved_at | timestamp | NULL | Momento de aprobación |
| approved_by | bigint unsigned | FK→users, nullOnDelete | Quién aprobó |
| superseded_by_id | bigint unsigned | FK→self, nullOnDelete | Versión que la reemplazó |
| notes | text | NULL | Notas del revisor |
| created_at | timestamp | NULL | |
| updated_at | timestamp | NULL | |
| deleted_at | timestamp | NULL | Soft delete |

**Constraints:**
- `UNIQUE(anio, serie, version_number)`
- `INDEX(anio, serie, hash_estructura)`
- `INDEX(status)`

### Tablas existentes utilizadas (no modificadas)

| Tabla | Uso en RemParser |
|---|---|
| `rem_data` | Lectura de datos reales para validación |
| `rem_validation_results` | Escritura de resultados de validación automática |
| `rem_uploads` | FK opcional, lookup de uploads |
| `rem_templates` | FK opcional |
| `users` | FK para approved_by |

---

## 4. Flujo del Parser (Fase 1)

```
Excel (.xlsm)
    │
    ▼
RemParserService::parse(filePath)
    │
    ├── loadSpreadsheet() ──► PhpSpreadsheet (IOFactory::createReader('Xlsx'))
    │                            setReadDataOnly(false) ← preserva fórmulas
    │
    ├── MetadataExtractorService::extract()
    │       ├── extractFromFilename()  → anio (regex \d{4}), serie (SA→A, SBM→BM...)
    │       └── extractFromSheet()     → hoja NOMBRE!B7 = año, B17 = "SERIE X"
    │
    ├── SheetDetectorService::detect()
    │       └── Excluye: NOMBRE, CONTROL, MACROS, hojas ocultas
    │
    └── Para cada hoja válida:
            │
            ├── SectionDetectorService::detect(worksheet, sheetName)
            │       ├── Busca "SECCIÓN N.X" en columna A
            │       ├── Para cada sección:
            │       │       ├── findHeaderRow() → fila de encabezados
            │       │       ├── findDataEndRow() → última fila de datos
            │       │       └── ColumnDetectorService::detect(...)
            │       │               ├── Itera columnas desde header row
            │       │               ├── cleanLabel() → nombre del campo
            │       │               ├── isTotalColumn() → detecta TOTAL/AMBOS SEXOS
            │       │               ├── isControlOculto() → todas las celdas tienen =fórmula
            │       │               │       └── getFirstFormula() → primera fórmula de la columna
            │       │               │               └── FormulaAnalyzerService::analyze()
            │       │               │                       ├── trySumEquals() → =SUM, =A1+B1, etc.
            │       │               │                       ├── tryRequiredAndLeParent() → =IF(AND(...))
            │       │               │                       └── tryControlOculto() → =IF(...,1,0), &, ref directa
            │       │               └── ParsedFieldDTO(letra, label, esTotal, esControlOculto, reglaDetectada)
            │       └── filterAggregators() → elimina secciones padre (D cuando existen D.1, D.2)
            │
            ├── ParsedFormDTO(sheetName, sections)
            │
            └── structureBuffer += sheetName + codigo + letra:label + ...
                    │
                    ▼
              hashEstructura = md5(structureBuffer)
                    │
                    ▼
              ParsedTemplateDTO(anio, serie, hashEstructura, forms)
```

### Clasificación de Fórmulas

| Tipo detectado | Patrones | Count (SA) |
|---|---|---|
| `sum_equals` | `=SUM(rango)`, `=A1+B1+...`, `=SUM(A1,B2,...)`, `=+A1+B1` | 497 |
| `required_and_le_parent` | `=IF(AND(...), ...)` | 32 |
| `control_oculto` | `=IF(...,1,0)`, concat `&`, cell ref directa | 13 |
| `null` | Columna de datos normal (sin fórmula) | — |
| **Total** | | **542** (100% clasificados) |

---

## 5. Flujo de Persistencia (Fase 2)

```
RemTemplateStructurePersistenceService::persist(dto, remUploadId?, sourceFilename?)
    │
    ├── Buscar: WHERE anio=? AND serie=? AND hash_estructura=?
    │       │
    │       ├── Encontrado ──► PersistResult(model, wasCreated=false)  ◄── Reutilización
    │       │
    │       └── No encontrado:
    │               │
    │               ├── StructureVersioningService::resolveNextVersion()
    │               │       └── MAX(version_number) + 1 para (anio, serie)
    │               │
    │               ├── Crear RemTemplateStructure
    │               │       ├── version_number = next
    │               │       ├── status = 'draft'
    │               │       └── estructura = dto->toArray()
    │               │
    │               └── PersistResult(model, wasCreated=true)  ◄── Nueva versión
```

---

## 6. Flujo de Versionado (Fase 3)

### Estados

```
                ┌──────────┐
                │  draft   │ ◄── Creación automática
                └────┬─────┘
                     │ rem:approve-structure
                     ▼
                ┌──────────┐
                │ approved │ ◄── Revisado por admin
                └────┬─────┘
                     │ rem:activate-structure
                     ▼
                ┌──────────┐
                │  active  │ ◄── Vigente, única por (anio, serie)
                └────┬─────┘
                     │ (al activar una nueva)
                     ▼
                ┌────────────┐
                │ superseded │ ◄── Reemplazada
                └────────────┘
```

### Reglas

- Solo una `active` por `(anio, serie)`.
- `UNIQUE(anio, serie, version_number)` — no hay versiones duplicadas.
- `draft` puede editarse/eliminarse; `approved` y `active` son inmutables.
- Al activar, la versión `active` anterior pasa a `superseded` con `superseded_by_id`.

---

## 7. Flujo de Validación (Fase 4)

```
RemValidateStructureCommand(upload_id, structure_id)
    │
    ├── RemFormulaRuleBuilder::build(structure)
    │       │
    │       └── Recorre estructura JSON:
    │               forms[] → sections[] → fields[]
    │                   │
    │                   ├── reglaDetectada = null → skip
    │                   ├── tipo = 'control_oculto' → skip
    │                   ├── tipo = 'sum_equals' → ValidationRuleDTO
    │                   │       ├── scope: 'per_row' | 'row_range'
    │                   │       ├── sourceColumns: letras de columnas origen
    │                   │       └── severity: 'error'
    │                   └── tipo = 'required_and_le_parent' → ValidationRuleDTO
    │                           ├── parent = columnasOrigen[0]
    │                           ├── child = targetColumn
    │                           └── severity: 'warning'
    │
    └── RemFormulaValidationExecutor::execute(uploadId, rules)
            │
            ├── Cargar RemData WHERE rem_upload_id = uploadId
            ├── Agrupar por section
            │
            └── Para cada regla:
                    │
                    ├── Filtrar filas por section + row_range
                    │
                    ├── sum_equals:
                    │       └── Por cada fila: Σ values[source_cols] == values[target]
                    │
                    ├── required_and_le_parent:
                    │       └── Por cada fila: if parent>0 → child not null && child ≤ parent
                    │
                    └── Escribir RemValidationResult(ruleKey, ruleType, severity, passed, message, context)
```

---

## 8. Riesgos Encontrados

| Riesgo | Estado | Mitigación |
|---|---|---|
| **Cobertura de fórmulas incompleta** | Resuelto | 542/542 columnas con fórmula clasificadas (100%) |
| **Rangos de columnas no expandidos** | **Corregido** | Bug en `extractColumnRefs` sin expansión de C12:U12 → ahora expande |
| **Datos no coinciden con template** | Observado | Upload #1 de prueba no coincide exactamente con SA → 45 fallos esperados |
| **required_and_le_parent sin severidad definida** | Pendiente | Hoy hardcodeado como 'warning'; debe ser configurable |
| **Duplicación con validaciones manuales** | Pendiente | `rule_key` prefijado con `{sheet}_v{version}_` evita colisiones |
| **Upload ID no vinculado automáticamente** | Pendiente | Persistencia actual no se llama desde el flujo de subida |
| **Rendimiento con archivos grandes** | No evaluado | Validación procesa todos los `RemData` en memoria; monitorear |

---

## 9. Bugs Corregidos

| Bug | Fase | Síntoma | Fix |
|---|---|---|---|
| **Rangos no expandidos** | Fase 4 | `a04_v1_D_sum_equals` sumaba solo 2 columnas en vez de 17, dando 204 vs 1557 | `extractColumnRefs()` ahora usa `preg_replace_callback` para detectar rangos como `C12:U12` y expandir a todas las columnas/rows intermedias mediante `colLetterToIndex()` / `indexToColLetter()` |
| **reglaDetectada como string** | Fase 4 | Se perdía `columnasOrigen`, `rangoFilas`, `columnaDestino` | `ParsedFieldDTO::$reglaDetectada` pasó de `?string` a `null\|array`; `ColumnDetectorService` ahora pasa `$rule?->toArray()` en vez de `$rule?->tipo` |

**Impacto del fix de rangos**: `passed` 250 → 261, `failed` 56 → 45 (−11 reglas que ahora pasan).

---

## 10. Pendientes para Integración al Flujo Normal (Fase 5 en adelante)

### Corto plazo

| Pendiente | Prioridad | Detalle |
|---|---|---|
| Vincular parseo automático a la subida | Alta | Cuando se crea `RemUpload`, invocar `RemParserService` + `RemTemplateStructurePersistenceService` |
| Job de validación automática | Alta | Nuevo job post-parsing (no modificar `ProcessRemUploadJob` ni `ValidateRemUploadJob`) |
| Severidad configurable | Media | required_and_le_parent como 'warning' por defecto, parametrizable |
| Mostrar resultados en UI | Media | Distinguir reglas automáticas (prefijo `{sheet}_v{version}_`) vs manuales en el panel admin |

### Mediano plazo

| Pendiente | Prioridad | Detalle |
|---|---|---|
| Aprobación de estructura desde UI | Media | Hoy solo por comando Artisan |
| Notificación de nuevas versiones detectadas | Baja | Cuando el hash cambia para un (anio, serie) conocido |
| Pruebas de rendimiento | Baja | Validación carga todos los `RemData` en memoria; evaluar con uploads grandes |
| Pipeline completo CI | Baja | Tests unitarios para cada servicio |

### No blocker

Los 45 fallos restantes en la validación contra upload #1 son datos reales no coincidentes, no bugs. Las 32 reglas `required_and_le_parent` que fallan deben mostrarse como advertencias (warning), no errores, en la versión final.

---

## 11. Métricas Finales

| Métrica | Valor |
|---|---|
| Archivos creados | 19 (7 DTOs + 11 Services + 1 Model) |
| Migraciones | 2 |
| Comandos Artisan | 5 |
| Tablas nuevas | 1 (`rem_template_structures`) |
| Registros en BD | SA v1, SP v1 |
| Fórmulas clasificadas SA | 542 (100%) |
| sum_equals | 497 |
| required_and_le_parent | 32 |
| control_oculto | 13 (ignoradas) |
| Reglas de validación generadas | 529 (497+32, excluyendo control_oculto) |
| Reglas ejecutadas contra upload #1 | 306 (223 sin datos en ese upload) |
| Passed | 261 |
| Failed | 45 (datos reales no coincidentes, no bugs) |
| Bugs corregidos | 2 (rangos no expandidos, reglaDetectada string) |
| Tablas existentes modificadas | 0 |
| Parser antiguo modificado | 0 |
