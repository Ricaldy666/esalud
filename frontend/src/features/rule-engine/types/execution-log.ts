export interface ExecutionLog {
  id: number
  rule_key: string
  rem_upload_id: number
  status: 'passed' | 'failed' | 'skipped'
  total_rows: number
  passed_rows: number
  failed_rows: number
  execution_ms: number
  error_message: string | null
  triggered_by: 'cli' | 'job'
  created_at: string
  context?: Record<string, unknown> | null
  rule?: {
    id: number
    rule_key: string
    rule_type: string
    name: string
    severity: string
    status: string
  }
  upload?: {
    id: number
    filename: string
    period: string
    rem_type: string
  }
}

export interface ExecutionLogFilters {
  page?: number
  per_page?: number
  upload_id?: number
  rule_id?: number
  rule_key?: string
  status?: string
  triggered_by?: string
  from?: string
  to?: string
  structure_id?: number
  sort?: string
  order?: 'asc' | 'desc'
}
