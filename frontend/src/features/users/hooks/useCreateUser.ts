import { useMutation, useQueryClient } from '@tanstack/react-query'
import { userService } from '../services/userService'
import type { CreateUserData, User } from '../types'
import type { ApiError } from '@/shared/types/api'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'

export const useCreateUser = () => {
  const queryClient = useQueryClient()

  return useMutation<User, AxiosError<ApiError>, CreateUserData>({
    mutationFn: (data) => userService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] })
      toast.success('Usuario creado exitosamente')
    },
    onError: (error) => {
      const message = error?.response?.data?.message ?? 'Error al crear usuario'
      toast.error(message)
    },
  })
}
