export type RemUploadStatus = 'pending' | 'processing' | 'success' | 'with_errors' | 'failed'

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
