export interface ComparisonSummary {
  total_rules_in_map: number
  match_count: number
  difference_count: number
  match_percentage: number
  legacy: { passed: number; failed: number; skipped: number }
  engine: { passed: number; failed: number; skipped: number }
}

export interface ComparisonDiff {
  comp_key: string
  new_key: string
  sheet: string
  section: string
  letra: string
  tipo: string
  row_from: number | null
  row_to: number | null
  status_match: boolean
  rows_match: boolean
  failed_match: boolean
  severity: string | null
  legacy: { status: string; total_rows: number; failed_rows: number }
  engine: { status: string; total_rows: number; failed_rows: number }
}

export interface ComparisonReport {
  structure_id: number
  upload_id: number
  structure: {
    id: number
    serie: string
    anio: number
    version_number: number
    status: string
  } | null
  upload: {
    id: number
    filename: string
    period: string
  } | null
  summary: ComparisonSummary | null
  differences: ComparisonDiff[]
  execution_time_ms: number
}
