import { useQuery } from '@tanstack/react-query'
import { getHealthStatus } from '../services/healthService'

export function useHealthCheck() {
  return useQuery({
    queryKey: ['health'],
    queryFn: getHealthStatus,
    retry: 1,
  })
}
