import { useMutation, useQueryClient } from '@tanstack/react-query'
import { userService } from '../services/userService'
import type { UpdateUserData, User } from '../types'
import type { ApiError } from '@/shared/types/api'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'

export const useUpdateUser = () => {
  const queryClient = useQueryClient()

  return useMutation<User, AxiosError<ApiError>, { id: number; data: UpdateUserData }>({
    mutationFn: ({ id, data }) => userService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      toast.success('Usuario actualizado exitosamente')
    },
    onError: (error) => {
      const message = error?.response?.data?.message ?? 'Error al actualizar usuario'
      toast.error(message)
    },
  })
}
