export type RemUploadStatus = 'pending' | 'processing' | 'success' | 'failed'

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
