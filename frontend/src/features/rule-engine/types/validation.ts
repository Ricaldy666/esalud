export interface UploadInfo {
  id: number
  filename: string
  serie: string
  periodo: string
  centro: string
  estado_legacy: string
}

export interface FormSummary {
  form: string
  total: number
  passed: number
  failed: number
  skipped: number
  evaluated: number
  cumplimiento: number | null
}

export interface SeveritySummary {
  severidad: string
  total: number
  failed: number
}

export interface ValidationSummary {
  upload: UploadInfo
  total_rules: number
  passed: number
  failed: number
  skipped: number
  cumplimiento_porcentaje: number | null
  ultima_ejecucion: string | null
  por_formulario: FormSummary[]
  por_severidad: SeveritySummary[]
}

export interface CriterioFuncional {
  empty_behavior: string
  functional_condition: string
  justification: string
  informed_by: string
  informed_at: string | null
  status: string
  updated_by: string
  updated_at: string | null
  scope: string
  confidence_level: string
  acquisition_method: string
}

export interface PendingFunctionalCell {
  coordinate: string
  column: string
  label: string
  value: string | number | null
  expected_value: number
  editable: boolean
  blocked: boolean
  color?: string | null
}

export interface ValidationError {
  id: number
  form: string
  section: string
  section_code?: string | null
  section_name?: string | null
  section_order?: number | null
  row_number?: number | null
  campo: string
  columna: string
  tipo: string
  tipo_error: 'tecnico' | 'funcional'
  mensaje: string
  recomendacion: string
  filas_afectadas: number
  filas_totales: number
  severidad: string
  criterio?: CriterioFuncional
  row_concept?: string
  row_profesional?: string
  row_total?: string | number | null
  pending_cells?: PendingFunctionalCell[]
  pending_cells_count?: number
}

export interface ValidationErrorFilters {
  form?: string
  severidad?: string
  tipo_regla?: string
  tipo_error?: 'tecnico' | 'funcional'
}

export interface ErrorGroup {
  key: string
  form: string
  section: string
  tipo_error: 'tecnico' | 'funcional'
  tipo: string
  recomendacion: string
  errors: ValidationError[]
  count: number
  variables: string[]
  severidad: string
}

export interface ExecutiveSummary {
  total_errors: number
  top_forms: { form: string; count: number }[]
  top_recommendation: string
}
