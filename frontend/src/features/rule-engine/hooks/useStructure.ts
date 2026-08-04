import { useQuery } from '@tanstack/react-query'
import { structuresService } from '../services/structures'

export const useStructure = (id: number | undefined) =>
  useQuery({
    queryKey: ['structure', id],
    queryFn: () => structuresService.get(id!),
    enabled: !!id,
    staleTime: 30_000,
  })
