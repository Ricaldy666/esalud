# Módulo Web de Gestión y Certificación de Reglas de Consistencia

## Arquitectura Propuesta

### 1. Capas

```
Frontend (Vue 3 + Inertia)  ←→  Backend (Laravel API)  ←→  DB (MySQL)
                ↓                        ↓
         Componentes SPA          Domain Services
         (Listado, Ficha,         (RuleManagementService,
          Propuesta,               CertificationService,
          Historial)               VersioningService)
```

### 2. Ubicación en el código existente

```
backend/
├── app/
│   ├── Domain/
│   │   ├── RuleEngine/              ← existente
│   │   │   ├── Models/
│   │   │   │   ├── Rule.php         ← extender (sin romper)
│   │   │   │   ├── RuleBinding.php
│   │   │   │   ├── RuleVersion.php  ← ya existe
│   │   │   │   ├── RuleExecutionLog.php
│   │   │   │   └── (nuevos)
│   │   │   │       ├── RuleDraft.php
│   │   │   │       └── RuleCertification.php
│   │   │   ├── Services/
│   │   │   │   ├── CertificationService.php  ← ya creado
│   │   │   │   └── (nuevos)
│   │   │   │       ├── RuleManagementService.php
│   │   │   │       └── RuleVersioningService.php
│   │   │   └── Controllers/         ← nuevos (API)
│   │   │       ├── RuleManagementController.php
│   │   │       ├── RuleDraftController.php
│   │   │       └── RuleCertificationController.php
│   │   └── ...
│   ├── Http/
│   │   └── Requests/                ← Form Requests nuevos
│   │       ├── StoreRuleDraftRequest.php
│   │       └── ApproveRuleVersionRequest.php
│   └── ...
├── routes/
│   └── api.php                      ← nuevas rutas
└── resources/
    └── js/
        └── Pages/
            └── RuleEngine/          ← nuevas páginas Vue
                ├── RuleList.vue
                ├── RuleCard.vue
                ├── RuleDraftForm.vue
                └── RuleHistory.vue
```

---

### 3. Tablas necesarias

#### 3.1 `rem_rule_drafts` — Propuestas / borradores de reglas externas

```sql
CREATE TABLE rem_rule_drafts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Quién propone
    user_id         BIGINT UNSIGNED NOT NULL,
    -- Metadata de la regla propuesta
    serie           VARCHAR(10) NOT NULL COMMENT 'A, BM, BS, D, P',
    hoja            VARCHAR(10) NOT NULL COMMENT 'A01, A02...',
    seccion         VARCHAR(50) NOT NULL,
    rule_type       VARCHAR(50) NOT NULL COMMENT 'sum_equals, required_and_le_parent, cross_sheet...',
    -- Definición funcional
    condition_desc  TEXT NOT NULL COMMENT 'Descripción de la condición en lenguaje natural',
    columns_source  JSON COMMENT 'Columnas origen involucradas',
    column_target   VARCHAR(10) COMMENT 'Columna destino o total',
    row_range       VARCHAR(20) COMMENT 'Rango de filas ej: 11:32',
    severity        VARCHAR(20) NOT NULL DEFAULT 'error' COMMENT 'error, warning',
    message         TEXT COMMENT 'Mensaje personalizado para el usuario',
    justification   TEXT COMMENT 'Justificación funcional/técnica de la regla',
    -- Referencia a regla existente (si es modificación)
    based_on_rule_id BIGINT UNSIGNED NULL,
    -- Estado del draft
    status          VARCHAR(30) NOT NULL DEFAULT 'pending_approval'
                    COMMENT 'pending_approval, approved, rejected, implemented',
    -- Revisión
    reviewed_by     BIGINT UNSIGNED NULL,
    reviewed_at     TIMESTAMP NULL,
    review_comment  TEXT NULL,
    -- Implementación
    implemented_rule_id BIGINT UNSIGNED NULL COMMENT 'ID de rem_rules cuando se activa',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (based_on_rule_id) REFERENCES rem_rules(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (implemented_rule_id) REFERENCES rem_rules(id)
);
```

#### 3.2 `rem_rule_certifications` — Estado de certificación por regla

```sql
CREATE TABLE rem_rule_certifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id         BIGINT UNSIGNED NOT NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'pending'
                    COMMENT 'pending, tech_certified, needs_review, stats_validated, rejected, active, inactive',
    certified_by    BIGINT UNSIGNED NULL,
    certified_at    TIMESTAMP NULL,
    observations    TEXT NULL,
    -- Evidencia recolectada
    evidence_xlsm   JSON COMMENT 'Evidencia automática desde estructura XLSM',
    evidence_manual TEXT COMMENT 'Evidencia del Manual REM (ingreso manual)',
    -- Prompt para Estadística
    question_for_stats TEXT NULL COMMENT 'Pregunta específica para el equipo de Estadística',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_rule_cert (rule_id),
    FOREIGN KEY (rule_id) REFERENCES rem_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (certified_by) REFERENCES users(id)
);
```

#### 3.3 `rem_rule_audit_log` — Auditoría completa (reemplaza activitylog para este módulo)

```sql
CREATE TABLE rem_rule_audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id         BIGINT UNSIGNED NULL,
    draft_id        BIGINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    action          VARCHAR(50) NOT NULL COMMENT 'created, updated, certified, draft_submitted, approved, rejected, version_created, status_changed',
    field           VARCHAR(50) NULL COMMENT 'Campo modificado',
    old_value       TEXT NULL,
    new_value       TEXT NULL,
    comment         TEXT NULL,
    approver_id     BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (rule_id) REFERENCES rem_rules(id),
    FOREIGN KEY (draft_id) REFERENCES rem_rule_drafts(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approver_id) REFERENCES users(id)
);
```

#### 3.4 Modificaciones a `rem_rules` (sin migración forzada)

```sql
-- Agregar columnas (opcionales, no rompen compatibilidad)
ALTER TABLE rem_rules
    ADD COLUMN serie VARCHAR(10) NULL AFTER rule_key,
    ADD COLUMN hoja VARCHAR(10) NULL AFTER serie,
    ADD COLUMN seccion VARCHAR(50) NULL AFTER hoja,
    ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN draft_parent_id BIGINT UNSIGNED NULL AFTER is_draft,
    ADD INDEX idx_certification (status, is_draft);
```

> **Decisión:** No ejecutar migración ahora. Las columnas `serie`, `hoja`, `seccion` se obtienen de `config->sheet`, `config->section`. La columna `is_draft` y `draft_parent_id` se agregarían en la Fase 2.

#### 3.5 `rem_rule_versions` — Ya existe

```sql
-- Ya existe: rem_rule_versions (rule_id, version, config, changelog, created_by)
-- Solo extender con:
ALTER TABLE rem_rule_versions
    ADD COLUMN status VARCHAR(20) DEFAULT 'draft' COMMENT 'draft, active, superseded',
    ADD COLUMN approved_by BIGINT UNSIGNED NULL,
    ADD COLUMN approved_at TIMESTAMP NULL,
    ADD FOREIGN KEY (approved_by) REFERENCES users(id);
```

---

### 4. Endpoints

#### 4.1 Reglas

| Método | Ruta | Propósito |
|--------|------|-----------|
| `GET` | `/api/v1/rule-manager/rules` | Listado con filtros |
| `GET` | `/api/v1/rule-manager/rules/{rule}` | Ficha completa |
| `GET` | `/api/v1/rule-manager/rules/{rule}/history` | Historial de cambios |
| `GET` | `/api/v1/rule-manager/rules/{rule}/versions` | Versiones de la regla |
| `POST` | `/api/v1/rule-manager/rules/{rule}/certify` | Cambiar estado de certificación |

#### 4.2 Drafts (propuestas externas)

| Método | Ruta | Propósito |
|--------|------|-----------|
| `GET` | `/api/v1/rule-manager/drafts` | Listar propuestas |
| `POST` | `/api/v1/rule-manager/drafts` | Crear propuesta |
| `GET` | `/api/v1/rule-manager/drafts/{draft}` | Ver propuesta |
| `PUT` | `/api/v1/rule-manager/drafts/{draft}` | Editar propuesta |
| `POST` | `/api/v1/rule-manager/drafts/{draft}/approve` | Aprobar propuesta |
| `POST` | `/api/v1/rule-manager/drafts/{draft}/reject` | Rechazar propuesta |
| `POST` | `/api/v1/rule-manager/drafts/{draft}/implement` | Implementar como regla activa |

#### 4.3 Versiones

| Método | Ruta | Propósito |
|--------|------|-----------|
| `POST` | `/api/v1/rule-manager/rules/{rule}/versions` | Crear nueva versión borrador |
| `POST` | `/api/v1/rule-manager/rules/{rule}/versions/{version}/approve` | Aprobar versión |
| `POST` | `/api/v1/rule-manager/rules/{rule}/versions/{version}/activate` | Activar versión |

#### 4.4 Certificación

| Método | Ruta | Propósito |
|--------|------|-----------|
| `GET` | `/api/v1/rule-manager/certifications` | Dashboard de certificación |
| `GET` | `/api/v1/rule-manager/certifications/stats` | Estadísticas |
| `POST` | `/api/v1/rule-manager/certifications/bulk` | Cambio masivo de estado |

#### 4.5 Exportación

| Método | Ruta | Propósito |
|--------|------|-----------|
| `GET` | `/api/v1/rule-manager/export` | Exportar Excel completo |
| `GET` | `/api/v1/rule-manager/export?sheet=A01` | Exportar por hoja |
| `GET` | `/api/v1/rule-manager/export?status=pending` | Exportar pendientes |
| `GET` | `/api/v1/rule-manager/export?status=stats_validated` | Exportar validadas |
| `GET` | `/api/v1/rule-manager/export/questions` | Preguntas para Estadística |

---

### 5. Pantallas (Vue 3 + Inertia)

#### 5.1 Listado de Reglas (`/rules`)

```
┌─────────────────────────────────────────────────────────────┐
│  Gestión y Certificación de Reglas de Consistencia          │
├─────────┬──────────┬────────┬────────┬────────┬─────────────┤
│ Filtros │          │        │        │        │             │
│ Serie:  │ Hoja:    │ Tipo:  │ Estado:│ Buscar │ [Exportar]  │
│ [A ▼]   │ [A01 ▼]  │ [Todos]│ [Todos]│ [....] │             │
├─────────┴──────────┴────────┴────────┴────────┴─────────────┤
│ # │ Rule Key      │ Hoja │ Tipo    │ Severidad │ Estado     │
│ 1 │ a01_a_c_sum.. │ A01  │ sum_eq..│ error     │ ⏳ Pend.   │
│ 2 │ a01_d_i_sum.. │ A01  │ sum_eq..│ error     │ ✅ Certif. │
│ 3 │ a02_a_ag_su.. │ A02  │ sum_eq..│ error     │ ⏳ Pend.   │
│...│               │      │         │           │            │
├─────────────────────────────────────────────────────────────┤
│  [✅ Técnico] [🔍 Revisión] [📊 Estadística] [❌ Rechazar]  │
│  Acciones masivas                                           │
└─────────────────────────────────────────────────────────────┘
```

#### 5.2 Ficha de Regla (`/rules/{rule}`)

```
┌──────────────────────────────────────────────────────────────┐
│ ← Volver                     Regla: a01_a_c_sum_equals       │
├──────────────────────────────────────────────────────────────┤
│  ⏳ Pendiente                      [Certificar] [Revisar]   │
├──────────┬───────────────────────────────────────────────────┤
│ Datos    │ rule_key:  a01_a_c_sum_equals                     │
│          │ tipo:      sum_equals                             │
│          │ severidad: error                                  │
│          │ hoja:      A01                                    │
│          │ sección:   A                                      │
│          │ columnas:  F, G, H, I, J, K, L, M, N             │
│          │ destino:   C (TOTAL)                              │
│          │ filas:     11                                     │
│          │ fórmula:   Suma(F+G+H+I+J+K+L+M+N) = Columna C    │
│          │ desc:      Valida suma = total fila 11           │
├──────────┴───────────────────────────────────────────────────┤
│  Evidencia XLSM                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ Sección: CONTROLES DE SALUD SEXUAL Y REPRODUCTIVA      │  │
│  │ Columna: TOTAL (C) - Es Total: Sí - Es Oculto: Sí     │  │
│  │ Origen fórmula: F11, G11, H11, I11, J11...            │  │
│  └────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────┤
│  Evidencia Manual REM                                        │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ [Editar] Ingrese referencia del Manual REM...          │  │
│  └────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────┤
│  Observaciones                                               │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ [Editar] ...                                           │  │
│  └────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────┤
│  Historial de Cambios                                        │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ 2026-07-15 admin@esalud  → Certificada técnicamente   │  │
│  │ 2026-07-14 sistema       → Creada desde estructura    │  │
│  └────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────┤
│  Versiones                                                    │
│  ┌──────────┬────────┬──────────┬────────────────────────┐   │
│  │ Versión  │ Estado │ Aprobada │ Fecha                  │   │
│  │ 1.0.0    │ active │ ✓ Sí     │ 2026-07-14             │   │
│  │ 1.1.0    │ draft  │ - No     │ 2026-07-15 (borrador)  │   │
│  └──────────┴────────┴──────────┴────────────────────────┘   │
└──────────────────────────────────────────────────────────────┘
```

#### 5.3 Crear Propuesta (`/drafts/create`)

```
┌──────────────────────────────────────────────────────────────┐
│  Nueva Propuesta de Regla                                    │
├──────────────────────────────────────────────────────────────┤
│ Serie:     [A ▼]      Hoja:  [A01 ▼]   Sección: [_____]     │
│ Tipo:      [sum_equals ▼]                                    │
│ Condición: [La suma de X debe ser igual al total de Y...]    │
│ Columnas Origen: [F, G, H]                                   │
│ Columna Destino: [C]                                         │
│ Rango Filas:     [11:32]                                     │
│ Severidad: [error ▼]                                         │
│ Mensaje:   [La suma de F+G+H no coincide con el total C]     │
│ Justificación: [Según Circular N° XX del MINSAL...]          │
│                                                              │
│ [Guardar Borrador]  [Enviar a Aprobación]                    │
└──────────────────────────────────────────────────────────────┘
```

#### 5.4 Dashboard de Certificación (`/certification`)

```
┌──────────────────────────────────────────────────────────────┐
│  Dashboard de Certificación — Serie A 2026                   │
├──────────────────────────────────────────────────────────────┤
│  Totales            │  Avance por Hoja                       │
│  ┌────────────────┐ │  ┌──────────────────────────────────┐  │
│  │ 529  Total      │ │  │ A01 ████████████░░░  18/22    │  │
│  │ 120  ✅ Técnico │ │  │ A02 ██████░░░░░░░░   4/4 ✅   │  │
│  │  45  🔍 Revisión│ │  │ A03 ██░░░░░░░░░░░░   2/11    │  │
│  │  30  📊 Estadís.│ │  │ ...                          │  │
│  │ 334  ⏳ Pendiente│ │  └──────────────────────────────────┘  │
│  └────────────────┘ │                                         │
├──────────────────────────────────────────────────────────────┤
│  Preguntas Pendientes para Estadística (5)                    │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ a05_c_c_sum_equals: Verificar rango etario 15-19 años │  │
│  │ a23_a_ao_sum_equals: Confirmar columna origen AO      │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

---

### 6. Flujo de Aprobación

```
                    ┌──────────────┐
                    │  Propuesta   │  (Usuario externo)
                    │  (Draft)     │
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐
                    │  Pendiente   │
                    │  Aprobación  │
                    └──────┬───────┘
                           │
                    ┌──────┴──────┐
                    │             │
                    ▼             ▼
            ┌──────────┐  ┌──────────┐
            │ Aprobada  │  │Rechazada │
            └─────┬─────┘  └──────────┘
                  │
                  ▼
          ┌────────────────┐
          │ Implementar    │  → Crear regla en rem_rules
          │ como versión   │  → status = 'inactive'
          │ borrador       │  → version = n+1
          └───────┬────────┘
                  │
                  ▼
          ┌────────────────┐
          │ Certificación  │  → Informática certifica
          │ Técnica        │  → Estado: tech_certified
          └───────┬────────┘
                  │
                  ▼
          ┌────────────────┐
          │ Validación     │  → Estadística valida
          │ Estadística    │  → Estado: stats_validated
          └───────┬────────┘
                  │
                  ▼
          ┌────────────────┐
          │ Activar        │  → status = 'active'
          │ Versión        │  → versión anterior → 'superseded'
          └────────────────┘

Regla existente → modificación:

                    ┌──────────────┐
                    │  Regla       │
                    │  Activa      │  (v1.0.0)
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐
                    │ Crear        │  → Copia de config
                    │ Borrador     │  → version = 1.1.0
                    │ (draft)      │  → status = 'draft'
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐
                    │ Modificar    │  → Editar config
                    │ Borrador     │
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐
                    │ Aprobar      │  → reviewed_by, approved_at
                    │ Borrador     │
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐
                    │ Activar      │  → old.active = 0
                    │ Nueva Versión│  → new.active = 1
                    └──────────────┘
```

---

### 7. Roles y Permisos

| Rol | Acciones |
|-----|----------|
| **Administrador** | Todo |
| **Informática** | Ver listado, certificar técnicamente, marcar revisión, crear versiones, activar/inactivar |
| **Estadística** | Ver listado, validar estadísticamente, rechazar, agregar observaciones |
| **Usuario externo** | Crear propuestas, ver estado de sus propuestas |

---

### 8. Riesgos

| # | Riesgo | Impacto | Mitigación |
|---|--------|---------|------------|
| 1 | Modificar regla activa directamente y romper validaciones en producción | Alto | El flujo obliga a crear borrador → aprobar → activar. La regla activa nunca se toca directamente. |
| 2 | Dos personas editando el mismo borrador simultáneamente | Medio | Lock optimista con `updated_at`. El que guarda segundo recibe conflicto. |
| 3 | Reglas huérfanas sin binding al activar nueva versión | Alto | El proceso de activación debe migrar bindings automáticamente a la nueva versión. |
| 4 | Crecimiento de `rem_rule_audit_log` sin control | Bajo | Archivar registros > 6 meses. Paginación forzada en consultas. |
| 5 | Confusión entre `rem_rule_certifications.status` y `rem_rules.status` | Medio | Documentar claramente: `rem_rules.status` = estado operativo (active/inactive). `rem_rule_certifications.status` = estado de certificación. |
| 6 | Dependencia con frontend Vue para vistas importantes | Medio | Entregar primero los endpoints API + comando CLI de respaldo. El frontend Vue se construye en paralelo. |

---

### 9. Plan por Fases

#### Fase 1 — Backend API + CLI (Actual)

**Ya entregado:**
- `CertificationService` — Fichas, evidencia XLSM, persistencia JSON
- `rule:certify` — CLI interactivo para revisar y certificar
- `docs/certificacion-catalogo-serie-a.md` — Documentación

#### Fase 2 — Base de datos + API REST

**Duración estimada:** 3-5 días

- Migraciones para `rem_rule_drafts`, `rem_rule_certifications`, `rem_rule_audit_log`
- Migración para extender `rem_rules` (serie, hoja, seccion, is_draft, draft_parent_id)
- Migrar datos de certificación desde JSON → `rem_rule_certifications`
- `RuleManagementService` — CRUD de reglas con filtros
- `RuleVersioningService` — Crear versiones, aprobar, activar
- Controladores REST con Form Requests y autorización
- Endpoints de exportación Excel (usando PhpSpreadsheet)

#### Fase 3 — Frontend Vue 3 + Inertia

**Duración estimada:** 5-7 días

- Página de listado con filtros (serie, hoja, tipo, severidad, estado)
- Página de ficha de regla con pestañas (datos, evidencia, historial, versiones)
- Formulario de propuesta externa
- Dashboard de certificación con gráficos de avance
- Exportación Excel desde el frontend

#### Fase 4 — Flujo de aprobación completo

**Duración estimada:** 3-4 días

- Integración del flujo: draft → revisión → aprobación → implementación → certificación → activación
- Notificaciones por correo interno
- Historial de auditoría visible desde la ficha
- Lock optimista en borradores

#### Fase 5 — Reportería y cierre

**Duración estimada:** 2-3 días

- Exportaciones avanzadas (preguntas para Estadística, reglas validadas por hoja)
- Tablero resumen para la dirección
- Pruebas de integración
- Documentación de usuario final
- Capacitación a Informática y Estadística

**Total estimado: 13-19 días hábiles**

---

### 10. Resumen de archivos a crear/modificar

```
Fase 2:
  CREATE backend/database/migrations/xxxx_create_rem_rule_drafts_table.php
  CREATE backend/database/migrations/xxxx_create_rem_rule_certifications_table.php
  CREATE backend/database/migrations/xxxx_create_rem_rule_audit_log_table.php
  CREATE backend/database/migrations/xxxx_extend_rem_rules_for_certification.php
  CREATE backend/app/Domain/RuleEngine/Models/RuleDraft.php
  CREATE backend/app/Domain/RuleEngine/Models/RuleCertification.php
  CREATE backend/app/Domain/RuleEngine/Models/RuleAuditLog.php
  CREATE backend/app/Domain/RuleEngine/Services/RuleManagementService.php
  CREATE backend/app/Domain/RuleEngine/Services/RuleVersioningService.php
  CREATE backend/app/Domain/RuleEngine/Controllers/RuleManagementController.php
  CREATE backend/app/Domain/RuleEngine/Controllers/RuleDraftController.php
  CREATE backend/app/Domain/RuleEngine/Controllers/RuleCertificationController.php
  CREATE backend/app/Http/Requests/StoreRuleDraftRequest.php
  CREATE backend/app/Http/Requests/ApproveRuleVersionRequest.php
  MODIFY backend/routes/api.php (agregar rutas)

Fase 3:
  CREATE resources/js/Pages/RuleEngine/RuleList.vue
  CREATE resources/js/Pages/RuleEngine/RuleCard.vue
  CREATE resources/js/Pages/RuleEngine/RuleDraftForm.vue
  CREATE resources/js/Pages/RuleEngine/RuleHistory.vue
  CREATE resources/js/Pages/RuleEngine/CertificationDashboard.vue
  MODIFY resources/js/app.js (agregar rutas)
```

---

### 11. Consideraciones técnicas

- **No romper compatibilidad:** Las nuevas columnas en `rem_rules` son NULLables. Todo el código existente sigue funcionando.
- **Sin cambios al Rule Engine productivo:** El motor de validación lee de `rem_rules` y `rem_rule_bindings`. Mientras el status de la regla sea 'active', funciona igual.
- **Migración gradual:** La certificación arranca con el JSON file (ya funcional) y migra a BD en Fase 2.
- **Pruebas:** Cada fase incluye tests unitarios para servicios y tests de integración para endpoints.
