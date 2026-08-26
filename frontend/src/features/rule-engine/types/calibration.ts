export type RowType =
  | 'header'
  | 'data'
  | 'subtotal'
  | 'total'
  | 'special'
  | 'spacer'
  | 'not_applicable'

export type CoverageType =
  | 'direct_rule'
  | 'aggregated_rule_candidate'
  | 'partial_exception'
  | 'exception'
  | 'no_formula'
  | 'not_applicable'

export type EvidenceType =
  | 'formula_xlsm'
  | 'cell_level_xlsm'
  | 'structure_pattern'
  | 'inferred_candidate'
  | 'no_evidence'

export interface CalibrationColumn {
  letra: string
  label: string
  es_total: boolean
  es_bloqueada: boolean
}

export interface CalibrationRowEvidence {
  destino_exacto: string | null
  origen_efectivo: string[]
  origen_columnas: string[]
  formula_efectiva: string | null
  formula_candidata: string | null
  columna_destino: string | null
  tipo_evidencia: EvidenceType
}

export interface CalibrationRow {
  row: number
  row_type: RowType
  concepto: string | null
  profesional: string | null
  rule_key: string | null
  rule_type: string | null
  aggregated_rule_key: string | null
  destino_exacto: string | null
  origen_efectivo: string[]
  origen_columnas: string[]
  formula_efectiva: string | null
  formula_candidata: string | null
  tipo_evidencia: EvidenceType
  cobertura: CoverageType
  estado_tecnico: string
  columnas_habilitadas: CalibrationColumn[]
  columnas_sumadas: string[]
  columnas_fuera_suma: string[]
  columna_total: string
  destino_funcional?: string | null
  descripcion_funcional_origen?: string
  regla_funcional_label?: string
  functional_rules?: Array<{
    total_column: string
    destination: string
    destino_funcional: string
    origin_columns: string[]
    origin_coordinates: string[]
    descripcion_funcional_origen: string
    formula_exacta: string
    formula_template: string
    label: string
  }>
  pattern_label?: string
  es_excepcion: boolean
  excepcion_razon: string | null
  funcional_por_fila: CalibrationFunctionalRule | null
  funcional_por_regla: import('./functional-rule').FunctionalRule | null
}

export interface AggregatedRule {
  rule_key: string
  rule_type: string
  patron_general: string
  columna_destino: string | null
  columnas_origen: string[]
  rango_filas: string
  filas_directas: number[]
  filas_candidatas: number[]
  filas_excluidas: number[]
  total_filas_directas: number
  total_filas_candidatas: number
  total_excluidas: number
  estado_tecnico: string
}

export interface CalibrationSectionSummary {
  codigo: string
  titulo: string
  fila_header: number
  fila_inicio: number
  fila_fin: number
  total_filas_fisicas: number
  total_campos: number
}

export interface CalibrationMatrixSummary {
  total_filas_fisicas: number
  total_filas_datos: number
  total_headers: number
  total_subtotales: number
  total_spacers: number
  total_no_aplica: number
  cubiertas_directas: number
  candidatas_agregadas: number
  excepciones: number
  sin_formula: number
  certificadas_tecnicamente: number
  validadas_funcionalmente: number
  pendientes_definicion_funcional: number
}

export type PatternReconciliationStatus =
  | 'reviewed'
  | 'pending'
  | 'requiere_revalidacion'
  | 'unresolved'

export interface CalibrationQuestion {
  id?: string
  row: number | null
  type: string
  question: string
  suggests_block?: boolean
  pattern_id?: number
  pattern_key?: string
  response?: string
  observation?: string
  responsible?: string
  date?: string
  status?: 'pending' | 'answered' | 'clarification'
  review_status?: 'pending' | 'reviewed' | 'section_reviewed'
  reviewed_at?: string
  reviewed_by?: string
  updated_at?: string
  source_type?: 'manual' | 'sugerida' | 'heredada' | 'reported'
  source_sheet?: string
  source_section?: string
  technical_signature?: string
  structure_version?: string
  // Distingue un cierre 'section_review' por falta de contenido calibrable
  // (response='no_calibrable') de un cierre de calibracion normal
  // (response='revisada') -- ver CalibrationApplicability.
  closure_reason?: string
  // Identidad de patron basada en huella de filas (no en pattern_id, que es
  // secuencial y se reasigna al cambiar la estructura). Siempre se envian
  // como eco de lo que el backend ya devolvio en PatternGroup -- el frontend
  // nunca calcula pattern_fingerprint.
  pattern_rows?: number[]
  pattern_fingerprint?: string
  backfill_status?: string | null
  reconciliation_status?: PatternReconciliationStatus
  derived_from_fingerprint?: string[] | null
  // Metadata canonica v2 (Fase 1/3, 2026-08-12) -- server-owned, el
  // frontend solo la lee para decidir que NO reenviar en el flujo normal
  // de guardado (ver QuickCalibrationPanel::buildPayload()), nunca la
  // calcula ni la construye.
  fingerprint_version?: number
  revalidated_by?: string
  revalidated_at?: string
  revalidation_source_type?: string
}

export interface CalibrationQuestionResponse {
  questions: CalibrationQuestion[]
  history: Array<{
    type: string
    previous: string
    new: string
    by: string
    at: string
  }>
}

export interface BulkFunctionalPayload {
  rowNumbers: number[]
  empty_behavior?: string | null
  applies_to_types?: string[]
  included_health_centers?: string[]
  excluded_health_centers?: string[]
  functional_condition?: string
  justification?: string
  informed_by?: string
  status?: string
}

export interface CalibrationMatrixResponse {
  section: CalibrationSectionSummary
  rows: CalibrationRow[]
  summary: CalibrationMatrixSummary
  columnas: CalibrationColumn[]
  aggregated_rules: AggregatedRule[]
  questions: CalibrationQuestion[]
  header_labels: Record<string, string>
}

// ─── Pattern Matrix Types ──────────────────────────────────────────

export interface PatternCellInfo {
  letra: string
  color: string
  rgb: string
  bloqueada: boolean
  editable: boolean
  contenido: string
  es_formula: boolean
  formula: string
  tipo_celda: string
}

export interface PatternRow {
  fila: number
  concepto: string
  profesional: string
  formula_exacta: string
  tipo_evidencia: string
  cobertura: string
  estado_tecnico: string
  destino_funcional?: string
  descripcion_funcional_origen?: string
  regla_funcional_label?: string
  functional_rules?: Array<{
    total_column: string
    destination: string
    destino_funcional: string
    origin_columns: string[]
    origin_coordinates: string[]
    descripcion_funcional_origen: string
    formula_exacta: string
    formula_template: string
    label: string
  }>
  funcional_por_fila: CalibrationFunctionalRule | null
  funcional_por_regla: import('./functional-rule').FunctionalRule | null
  editables: PatternCellInfo[]
  bloqueadas: PatternCellInfo[]
  especiales: PatternCellInfo[]
  total_info: Record<string, unknown>
  objetivo: string
}

export interface PatternHistoricalReference {
  response: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  pattern_fingerprint: string | null
  pattern_rows: number[] | null
}

export interface PatternGroup {
  id: number
  nombre: string
  descripcion: string
  regla_funcional_label?: string
  filas: number[]
  formula_template: string
  columnas_origen: string[]
  columna_total: string
  origin_columns?: string[]
  total_columns?: string[]
  formula_templates?: Record<string, string>
  source?: 'legacy_a01_a' | 'cell_data' | 'structure_inferred'
  mode?: 'formula' | 'direct_input'
  cantidad_filas: number
  conceptos: string[]
  profesionales: string[]
  rows: PatternRow[]
  // Identidad y reconciliacion -- siempre provistos por el backend
  // (SectionCalibrationMatrixService::buildPatternMatrix()). pattern_rows es
  // un alias explicito de filas, en el vocabulario de reconciliacion.
  row_fingerprint: string
  pattern_rows: number[]
  reconciliation_status: PatternReconciliationStatus
  backfill_status: string | null
  derived_from_fingerprint: string[] | null
  historical_reference: PatternHistoricalReference | null
  // Senal estadistica (no funcional): patron de fila unica o grupo
  // claramente minoritario frente al patron dominante de la MISMA seccion.
  // Nunca implica que la respuesta general no aplique -- solo marca que debe
  // confirmarse explicitamente, no heredarse por defecto.
  pattern_size_class: 'majority' | 'minority'
  possible_business_exception: boolean
  exception_reason: string | null
}

export interface ColumnGroupColumn {
  letter: string
  label: string
  editable_rows: number
  blocked_rows: number
}

export interface ColumnGroup {
  key: string
  label: string
  type: 'legacy_special' | 'main_rule' | 'age_range' | 'complementary' | string
  start_column: string
  end_column: string
  columns: ColumnGroupColumn[]
  editable_rows: number
  blocked_rows: number
  source: 'legacy_a01_a' | 'cell_data' | 'structure_inferred'
  description: string
  total?: string
  components?: string[]
  formula?: string
  labels?: Record<string, string>
  subgroups?: Array<{
    key: string
    label: string
    type: string
    columns: ColumnGroupColumn[]
    editable_rows: number
    blocked_rows: number
    source: 'legacy_a01_a' | 'cell_data' | 'structure_inferred'
  }>
}

export interface PatternMatrixSection {
  codigo: string
  titulo: string
  fila_header: number
  fila_inicio: number
  fila_fin: number
  total_filas_fisicas: number
  total_campos: number
}

export interface PatternMatrixReconciliation {
  effective_section_reviewed: boolean
  historical_section_reviewed: boolean
}

// Diagnostico generico (recalculado en vivo en cada carga, nunca hardcodeado
// a una hoja/seccion) de si una seccion estructuralmente valida NO tiene
// ningun contenido calibrable -- hallazgo A32/E1 (2026-08-11): 0 celdas
// editables, 0 formulas, 0 patrones, con el cell-data ya escaneado y sin
// advertencias pendientes. Cualquier duda tecnica (sin escanear, escaneo
// incompleto, advertencias, patrones existentes) fuerza 'requires_calibration'.
export type CalibrationApplicabilityStatus = 'requires_calibration' | 'not_calibratable'

export interface CalibrationApplicabilityCriteria {
  structure_available_and_consistent: boolean
  cell_data_available: boolean
  no_pending_warnings: boolean
  no_editable_cells: boolean
  no_functional_formulas: boolean
  no_calibratable_patterns: boolean
}

export interface CalibrationApplicability {
  status: CalibrationApplicabilityStatus
  reason: string | null
  criteria: CalibrationApplicabilityCriteria
}

export interface PatternMatrixResponse {
  section: PatternMatrixSection
  patterns: PatternGroup[]
  columnas_especiales: string[]
  column_groups?: ColumnGroup[]
  summary: CalibrationMatrixSummary
  questions: CalibrationQuestion[]
  all_rows: CalibrationRow[]
  header_labels: Record<string, string>
  warnings?: string[]
  reconciliation: PatternMatrixReconciliation
  calibration_applicability?: CalibrationApplicability
}

export type RowFunctionalDecisionValue =
  | 'debe_registrar_cero'
  | 'puede_quedar_vacio'
  | 'pendiente_definicion'
  | null

export type RowFunctionalDecisionOrigin = 'row' | 'pattern' | 'section' | 'none'

export interface RowFunctionalDecision {
  row: number
  concept: string
  professional: string
  explicit_decision: RowFunctionalDecisionValue
  inherited_decision: RowFunctionalDecisionValue
  effective_decision: RowFunctionalDecisionValue
  origin: RowFunctionalDecisionOrigin
  source_row: number | null
  status: 'pending' | 'propuesta' | 'aprobada' | 'rechazada' | 'validada' | null
  reviewed_by: string | null
  reviewed_at: string | null
  condition: string | null
  observation: string | null
  has_explicit_decision: boolean
  is_inherited: boolean
  possible_inconsistency: boolean
}

export interface RowFunctionalDecisionResponse {
  sheet: string
  section: string
  rows: RowFunctionalDecision[]
}

export interface RowFunctionalRulePayload {
  empty_behavior?: string | null
  applies_to_types?: string[]
  included_health_centers?: string[]
  excluded_health_centers?: string[]
  functional_condition?: string
  justification?: string
  informed_by?: string
  updated_by?: string
  status?: string
}

export interface RowFunctionalVersion {
  change_type?: string
  changed_at?: string
  changed_by?: string
  status_from?: string
  status_to?: string
}

// ─── Existing types follow ─────────────────────────────────────────

export interface CalibrationFunctionalRule {
  rule_key: string | null
  sheet: string
  section: string
  row: number
  empty_behavior: string | null
  applies_to_types: string[]
  included_health_centers: string[]
  excluded_health_centers: string[]
  functional_condition: string
  justification: string
  informed_by: string
  informed_at: string | null
  status: 'pending' | 'propuesta' | 'aprobada' | 'rechazada'
  updated_by: string
  updated_at: string | null
}

// ─── Progreso agregado de calibración ───────────────────────────────
//
// Ver backend SectionCalibrationMatrixService::buildStructureCalibrationSummary().
// Una sección cuenta como "completed" cuando effective_section_reviewed
// es true, sin importar si fue una calibración normal (response='revisada',
// contada en 'calibrated') o un cierre "no requiere calibración"
// (response='no_calibrable', contada en 'not_calibratable') -- ambas suman
// al mismo total de completadas, nunca se restan entre sí.

export interface CalibrationSectionCounters {
  sections_total: number
  sections_completed: number
  sections_calibrated: number
  sections_not_calibratable: number
  sections_pending: number
}

export interface CalibrationSheetSummary extends CalibrationSectionCounters {
  sheet_name: string
  progress_pct: number
  status: 'completada' | 'en_revision' | 'pendiente'
}

// Hoja marcada 'no_utilizada' por Estadística APS (ver
// RemSheetUsageStatusService) -- estado de negocio a nivel de hoja
// (año+serie+sheet_name), no una calificación de progreso de calibración.
// Nunca se mezcla con pendiente/completada/no_calibrable: queda excluida
// por completo del cálculo de progreso (ver progress_pct abajo).
export interface CalibrationNoUtilizadaSheet {
  sheet_name: string
  sections_total: number
  reason: string | null
  decided_by: string | null
  decided_at: string | null
  structure_id: number | null
}

// Totales a nivel de estructura completa. sections_aplicables es el
// denominador exclusivo de progress_pct -- las secciones de hojas
// 'no_utilizadas' (sections_no_utilizadas) nunca entran en
// sections_completed/sections_pending ni en el progreso.
export interface CalibrationStructureTotals {
  sections_total_estructura: number
  sections_no_utilizadas: number
  sections_aplicables: number
  sections_completed: number
  sections_calibrated: number
  sections_not_calibratable: number
  sections_pending: number
  progress_pct: number
  sheets_total: number
  sheets_completed: number
  sheets_no_utilizadas: number
}

export interface CalibrationSummaryResponse {
  structure_id: number | null
  sheets: CalibrationSheetSummary[]
  no_utilizadas: CalibrationNoUtilizadaSheet[]
  totals: CalibrationStructureTotals
}

// ─── Plan de migración al fingerprint canónico v2 (Fase 3, 2026-08-12) ──
//
// Ver backend PatternMigrationScanner::scanSection() -- clasificacion
// recalculada EN VIVO en cada carga (nunca cacheada en el frontend como
// verdad definitiva). Unico proposito: decidir si QuickRevalidationPanel
// debe mostrarse en vez del flujo normal de calibracion. No participa del
// calculo de progreso general ni de reconcileLive() en produccion.

export type MigrationPlanCategory =
  | 'AUTO_MIGRATE'
  | 'QUICK_CONFIRMATION'
  | 'FULL_REVALIDATION'
  | 'MISMATCH'
  | 'NEW_SECTION'
  | 'NOT_CALIBRATABLE'
  | 'NO_UTILIZADA'

export interface MigrationPlanHistoricalAnswer {
  response: string | null
  reviewed_by: string | null
  reviewed_at: string | null
}

export interface MigrationPlanPattern {
  pattern_id: number
  category: MigrationPlanCategory
  live_canonical_fingerprint: string | null
  live_rows: number[]
  already_v2_matching: boolean
  question_count: number
  // Solo presentes para patrones legacy con evidencia de fila extraible del
  // texto de la pregunta historica -- ausentes en AUTO_MIGRATE/otros casos.
  historical_answer?: MigrationPlanHistoricalAnswer | null
  historical_rows?: number[] | null
}

export interface MigrationPlanColumnDiff {
  added: string[]
  removed: string[]
  unknown: boolean
}

export interface MigrationPlanResponse {
  sheet: string
  code: string
  category: MigrationPlanCategory
  patterns: MigrationPlanPattern[]
  // Ausente en categorias resueltas antes de llegar a comparar columnas
  // (NEW_SECTION, NOT_CALIBRATABLE, NO_UTILIZADA, FULL_REVALIDATION sin
  // preguntas de patron).
  column_diff?: MigrationPlanColumnDiff
}

export interface QuickRevalidationConfirmResponse {
  questions: CalibrationQuestion[]
}

// Flujo de resolucion MISMATCH (2026-08-21). Un patron MISMATCH solo puede
// resolverse por la via rapida si ya fue auditado y etiquetado como
// safe_reconfirm o structural_row_exclusion -- human_review y
// structural_review nunca admiten confirmacion rapida, ver
// MismatchResolutionAuditService (backend).
//
// structural_row_exclusion (2026-08-24, hallazgo A09/G P2/P4): categoria
// INDEPENDIENTE de safe_reconfirm para patrones cuya unica diferencia de
// filas es la exclusion de una o mas filas TOTAL lider (mecanismo #6,
// SectionCalibrationMatrixService::isEmbeddedLeadingTotalRow()) ya
// verificadas mecanicamente por el motor -- nunca una decision de negocio,
// nunca "confiar a ciegas". safe_reconfirm sigue exigiendo igualdad EXACTA
// de filas y nunca se usa para este caso.
export type MismatchResolutionCategory =
  | 'safe_reconfirm'
  | 'human_review'
  | 'structural_review'
  | 'structural_row_exclusion'

export interface MismatchResolutionTag {
  sheet: string
  section: string
  pattern_id: number
  category: MismatchResolutionCategory
  audited_fingerprint: string
  audited_rows: number[]
  reason: string
  audited_by: string
  audited_at: string
  // Presentes SOLO para tags category=structural_row_exclusion -- ver
  // RuleTagMismatchResolutionCommand (backend).
  historical_rows?: number[]
  excluded_total_rows?: number[]
  exclusion_mechanism?: string
}

export interface MismatchResolutionDetails {
  live_category: MigrationPlanCategory
  live_rows: number[]
  live_canonical_fingerprint: string | null
  historical_answer?: MigrationPlanHistoricalAnswer | null
  historical_rows?: number[] | null
  column_diff: MigrationPlanColumnDiff | null
  resolution_tag: MismatchResolutionTag | null
}

export interface MismatchResolutionConfirmResponse {
  questions: CalibrationQuestion[]
}
