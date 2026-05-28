import { useQuery } from '@tanstack/react-query'
import { roleService } from '../services/roleService'

export const useRoles = () => {
  return useQuery({
    queryKey: ['roles'],
    queryFn: () => roleService.list(),
  })
}
