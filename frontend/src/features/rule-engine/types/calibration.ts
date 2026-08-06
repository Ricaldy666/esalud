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
  // Identidad de patron basada en huella de filas (no en pattern_id, que es
  // secuencial y se reasigna al cambiar la estructura). Siempre se envian
  // como eco de lo que el backend ya devolvio en PatternGroup -- el frontend
  // nunca calcula pattern_fingerprint.
  pattern_rows?: number[]
  pattern_fingerprint?: string
  backfill_status?: string | null
  reconciliation_status?: PatternReconciliationStatus
  derived_from_fingerprint?: string[] | null
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
