import { useQuery } from '@tanstack/react-query'
import { rulesService } from '../services/rules'

export const useRule = (id: number | undefined) =>
  useQuery({
    queryKey: ['rule', id],
    queryFn: () => rulesService.get(id!),
    enabled: !!id,
    staleTime: 30_000,
  })
