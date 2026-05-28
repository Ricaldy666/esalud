import { useQuery } from '@tanstack/react-query'
import { userService } from '../services/userService'
import type { UserFilters } from '../types'

export const useUsers = (filters?: UserFilters) => {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => userService.list(filters),
  })
}

export const useUser = (id: number | null) => {
  return useQuery({
    queryKey: ['users', id],
    queryFn: () => userService.get(id!),
    enabled: !!id,
  })
}
