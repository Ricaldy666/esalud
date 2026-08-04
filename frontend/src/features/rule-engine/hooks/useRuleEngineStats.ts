import { useQuery } from '@tanstack/react-query'
import { observabilityService } from '../services/observability'

export const useRuleEngineStats = () =>
  useQuery({
    queryKey: ['rule-engine', 'stats'],
    queryFn: observabilityService.getStats,
    staleTime: 30_000,
    refetchInterval: 60_000,
  })
