import { useQuery } from '@tanstack/react-query'
import { observabilityService } from '../services/observability'

export const useRuleEngineHealth = () =>
  useQuery({
    queryKey: ['rule-engine', 'health'],
    queryFn: observabilityService.getHealth,
    staleTime: 30_000,
    refetchInterval: 60_000,
  })
