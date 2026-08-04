import { useQuery } from '@tanstack/react-query'
import { bindingsService } from '../services/bindings'

export const useBinding = (id: number | undefined) =>
  useQuery({
    queryKey: ['binding', id],
    queryFn: () => bindingsService.get(id!),
    enabled: !!id,
    staleTime: 30_000,
  })
