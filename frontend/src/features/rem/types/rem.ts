export type RemUploadStatus =
  | 'pending'
  | 'processing'
  | 'validating'
  | 'success'
  | 'with_errors'
  | 'rejected'
  | 'failed'

// Estados que representan un resultado definitivo: el polling se detiene aqui,
// nunca antes. 'validating' (datos persistidos, validaciones en ejecucion) no
// es terminal -- antes se confundia con 'with_errors' si el parseo encontraba
// observaciones estructurales antes de que corriera ninguna regla.
export const TERMINAL_REM_UPLOAD_STATUSES: RemUploadStatus[] = [
  'success',
  'with_errors',
  'rejected',
  'failed',
]

export interface RemUpload {
  id: number
  uuid: string
  year: number
  month: number
  rem_type: string
  original_filename: string
  file_size: number
  mime_type: string
  status: RemUploadStatus
  error_report: {
    summary: {
      total_rows_processed: number
      total_cells_parsed: number
      total_error_cells: number
    }
    errors: Array<Record<string, unknown>>
  } | null
  health_center?: {
    id: number
    name: string
    type: string
  }
  user?: {
    id: number
    name: string
    email: string
  }
  rem_template?: {
    id: number
    version: string
  }
  processed_at: string | null
  created_at: string
  updated_at: string
}

export interface RemUploadFilters {
  page?: number
  per_page?: number
  year?: number
  month?: number
  rem_type?: string
  status?: RemUploadStatus
  health_center_id?: number
}

export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PageMeta
  message: string
  errors: Record<string, string[]> | null
}

// === Tipos para creación de upload ===

export type RemType = 'A' | 'BM' | 'D' | 'P'

export const REM_TYPE_LABELS: Record<RemType, string> = {
  A: 'Serie A - Consultas Médicas',
  BM: 'Serie BM - Salud Mental',
  D: 'Serie D - Discapacidad',
  P: 'Serie P - Programas',
}

export interface RemValidationResult {
  id: number
  rule_key: string
  rule_type: string
  severity: 'error' | 'warning'
  passed: boolean
  message: string | null
  context: Record<string, unknown> | null
}

export interface RemValidationResultsResponse {
  rem_upload_id: number
  status: RemUploadStatus
  total_rules: number
  total_errors: number
  total_warnings: number
  results: RemValidationResult[]
}

export interface CreateRemUploadPayload {
  file: File
  year: number
  month: number
  rem_type: RemType
  health_center_id: number
}

// === Tipos para previsualización y estado mejorado ===

export interface RemUploadPreview {
  filename: string
  extension: string
  size_mb: number
  is_valid_excel: boolean
  has_macros: boolean
  serie_detected: string | null
  rem_type_detected: string | null
  month_detected: number | null
  month_name_detected: string | null
  year_detected: number
  period_detected: string
  period_label: string
  upload_date: string
  health_center_detected: {
    id: number
    code: string
    name: string
  } | null
  sheets_detected: number
  filename_detection: {
    deis_code: string
    serie: string
    month: number | null
  } | null
  content_detection: {
    serie: string | null
    month: number | null
    year: number | null
  }
  version_detected: string | null
  version_active: string | null
  version_status: 'current' | 'outdated' | 'no_template' | 'unknown' | null
  warnings: string[]
  errors: string[]
}

export type ProcessingStep = 'upload' | 'parsing' | 'data_import' | 'rule_engine' | 'report'

export interface RemUploadStatusResponse {
  id: number
  uuid: string
  original_filename: string
  status: RemUploadStatus
  current_step: ProcessingStep
  progress: number
  message: string
  rem_type: string
  period: string
  health_center: { id: number; name: string } | null
  processed_at: string | null
  has_errors: boolean
  error_summary: {
    total_rows_processed: number
    total_cells_parsed: number
    total_error_cells: number
  } | null
  validation_summary: {
    total_rules: number
    applicable: number
    passed: number
    failed: number
    compliance: number | null
  } | null
}

export interface RemValidationSummary {
  rem_upload_id: number
  status: RemUploadStatus
  total_rules: number
  applicable: number
  not_applicable: number
  passed: number
  failed: number
  compliance: number | null
}

export const PROCESSING_STEPS: { key: ProcessingStep; label: string; description: string }[] = [
  { key: 'upload', label: 'Archivo recibido', description: 'El archivo fue subido correctamente' },
  {
    key: 'parsing',
    label: 'Leyendo estructura',
    description: 'Extrayendo datos del archivo Excel',
  },
  {
    key: 'data_import',
    label: 'Importando datos',
    description: 'Guardando registros en el sistema',
  },
  {
    key: 'rule_engine',
    label: 'Validando consistencias',
    description: 'Evaluando reglas de consistencia',
  },
  { key: 'report', label: 'Generando informe', description: 'Preparando resultados de validación' },
]
