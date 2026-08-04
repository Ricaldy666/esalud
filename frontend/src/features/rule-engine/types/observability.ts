export interface HealthData {
  config_enabled: boolean
  config_mode: string
  total_rules_active: number
  total_bindings_active: number
  total_structures: number
  structures_with_rules: number
  structures_without_bindings: number
  total_uploads: number
  uploads_with_engine: number
  uploads_without_engine: number
  total_execution_logs: number
  error_logs: number
  last_error: { id: number; upload_id: number; message: string; created_at: string } | null
  last_execution: { id: number; upload_id: number; status: string; created_at: string } | null
}

export interface StatsData {
  rules_by_type: Record<string, number>
  executions_by_status: Record<string, number>
  executions_by_trigger: Record<string, number>
  avg_execution_time_ms: number
  total_rows_processed: number
  total_rows_failed: number
  by_structure: Record<
    string,
    { total_logs: number; avg_ms: number; total_rows: number; total_failed: number }
  >
  last_20_uploads: {
    rem_upload_id: number
    total_rules: number
    passed: number
    failed: number
    skipped: number
    avg_ms: number
    total_rows: number
  }[]
  top_10_slowest_rules: {
    id: number
    rule_key: string
    execution_ms: number
    total_rows: number
    rem_upload_id: number
  }[]
}
