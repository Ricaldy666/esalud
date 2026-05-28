export interface ActivityLogEntry {
  id: number
  description: string
  event: string | null
  subject_type: string
  subject_id: string | number
  causer: { id: number; name: string; email: string } | null
  properties: Record<string, unknown> | null
  created_at: string
}

export interface AuditFilters {
  subject_type?: string
  subject_id?: number
  causer_id?: number
  event?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}
