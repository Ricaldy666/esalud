import { useQuery } from '@tanstack/react-query'
import { rulesService } from '../services/rules'
import type { RuleFilters } from '../types/rule'

export const useRules = (filters?: RuleFilters) =>
  useQuery({
    queryKey: ['rules', filters],
    queryFn: () => rulesService.list(filters),
    staleTime: 30_000,
  })
