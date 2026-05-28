import { api } from '@/shared/services/api'
import type { PaginatedResponse } from '@/shared/types/api'
import type { ActivityLogEntry, AuditFilters } from '../types'

export const auditService = {
  list: async (filters?: AuditFilters): Promise<PaginatedResponse<ActivityLogEntry>> => {
    const params = new URLSearchParams()
    if (filters?.subject_type) params.set('subject_type', filters.subject_type)
    if (filters?.subject_id) params.set('subject_id', String(filters.subject_id))
    if (filters?.causer_id) params.set('causer_id', String(filters.causer_id))
    if (filters?.event) params.set('event', filters.event)
    if (filters?.from) params.set('from', filters.from)
    if (filters?.to) params.set('to', filters.to)
    if (filters?.page) params.set('page', String(filters.page))
    if (filters?.per_page) params.set('per_page', String(filters.per_page))

    const response = await api.get<PaginatedResponse<ActivityLogEntry>>(
      `/activity-log?${params.toString()}`
    )
    return response.data
  },
}
