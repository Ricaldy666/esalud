export interface Rule {
  id: number
  rule_key: string
  rule_type: 'sum_equals' | 'required_and_le_parent'
  name: string
  source: string
  category: string | null
  severity: 'error' | 'warning'
  scope?: string
  status: 'active' | 'inactive' | 'deprecated'
  version: string
  description?: string | null
  config?: Record<string, unknown>
  metadata?: Record<string, unknown> | null
  bindings?: RuleBinding[]
  versions?: RuleVersion[]
  execution_logs?: RuleExecutionLog[]
  created_at?: string
  updated_at: string
}

export interface RuleBinding {
  id: number
  bindable_type: 'structure' | 'serie' | 'global'
  bindable_id: string | null
  serie: string | null
  anio: number | null
  conditions: Record<string, unknown> | null
  active: boolean
  created_at: string
}

export interface RuleVersion {
  id: number
  version: string
  config: Record<string, unknown> | null
  changelog: string | null
  created_at: string
}

export interface RuleExecutionLog {
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
}

export interface RuleFilters {
  page?: number
  per_page?: number
  rule_type?: string
  status?: string
  severity?: string
  source?: string
  category?: string
  version?: string
  search?: string
  sort?: string
  order?: 'asc' | 'desc'
}
