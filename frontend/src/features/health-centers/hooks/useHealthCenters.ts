import { useQuery } from '@tanstack/react-query'
import { healthCenterService } from '../services/healthCenterService'
import type { HealthCenterFilters } from '../types'

export const useHealthCenters = (filters?: HealthCenterFilters) => {
  return useQuery({
    queryKey: ['health-centers', filters],
    queryFn: () => healthCenterService.list(filters),
  })
}

export const useHealthCenter = (id: number | null) => {
  return useQuery({
    queryKey: ['health-centers', id],
    queryFn: () => healthCenterService.get(id!),
    enabled: !!id,
  })
}
