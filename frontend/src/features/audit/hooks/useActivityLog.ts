import { useQuery } from '@tanstack/react-query'
import { auditService } from '../services/auditService'
import type { AuditFilters } from '../types'

export const useActivityLog = (filters?: AuditFilters) => {
  return useQuery({
    queryKey: ['activity-log', filters],
    queryFn: () => auditService.list(filters),
  })
}
