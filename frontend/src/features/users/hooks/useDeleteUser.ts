import { useMutation, useQueryClient } from '@tanstack/react-query'
import { userService } from '../services/userService'
import type { ApiError } from '@/shared/types/api'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'

export const useDeleteUser = () => {
  const queryClient = useQueryClient()

  return useMutation<void, AxiosError<ApiError>, number>({
    mutationFn: (id) => userService.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      toast.success('Usuario eliminado exitosamente')
    },
    onError: (error) => {
      const message = error?.response?.data?.message ?? 'Error al eliminar usuario'
      toast.error(message)
    },
  })
}
