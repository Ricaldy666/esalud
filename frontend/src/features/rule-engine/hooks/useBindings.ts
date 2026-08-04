import { useQuery } from '@tanstack/react-query'
import { bindingsService } from '../services/bindings'
import type { BindingFilters } from '../types/binding'

export const useBindings = (filters?: BindingFilters) =>
  useQuery({
    queryKey: ['bindings', filters],
    queryFn: () => bindingsService.list(filters),
    staleTime: 30_000,
  })
