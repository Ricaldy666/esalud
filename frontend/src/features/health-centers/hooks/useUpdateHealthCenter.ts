import { useMutation, useQueryClient } from '@tanstack/react-query'
import { healthCenterService } from '../services/healthCenterService'
import type { UpdateHealthCenterData, HealthCenter } from '../types'
import type { ApiError } from '@/shared/types/api'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'

export const useUpdateHealthCenter = () => {
  const queryClient = useQueryClient()

  return useMutation<
    HealthCenter,
    AxiosError<ApiError>,
    { id: number; data: UpdateHealthCenterData }
  >({
    mutationFn: ({ id, data }) => healthCenterService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['health-centers'] })
      toast.success('Centro de salud actualizado exitosamente')
    },
    onError: (error) => {
      const message = error?.response?.data?.message ?? 'Error al actualizar centro de salud'
      toast.error(message)
    },
  })
}
