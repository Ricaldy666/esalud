import { useQuery } from '@tanstack/react-query'
import { structuresService } from '../services/structures'
import type { StructureFilters } from '../types/structure'

export const useStructures = (filters?: StructureFilters) =>
  useQuery({
    queryKey: ['structures', filters],
    queryFn: () => structuresService.list(filters),
    staleTime: 30_000,
  })
