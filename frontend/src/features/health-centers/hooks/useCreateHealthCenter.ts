import { useMutation, useQueryClient } from '@tanstack/react-query'
import { healthCenterService } from '../services/healthCenterService'
import type { CreateHealthCenterData, HealthCenter } from '../types'
import type { ApiError } from '@/shared/types/api'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'

export const useCreateHealthCenter = () => {
  const queryClient = useQueryClient()

  return useMutation<HealthCenter, AxiosError<ApiError>, CreateHealthCenterData>({
    mutationFn: (data) => healthCenterService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['health-centers'] })
      toast.success('Centro de salud creado exitosamente')
    },
    onError: (error) => {
      const message = error?.response?.data?.message ?? 'Error al crear centro de salud'
      toast.error(message)
    },
  })
}
