import { useMutation, useQueryClient } from '@tanstack/react-query'
import { healthCenterService } from '../services/healthCenterService'
import type { ApiError } from '@/shared/types/api'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'

export const useDeleteHealthCenter = () => {
  const queryClient = useQueryClient()

  return useMutation<void, AxiosError<ApiError>, number>({
    mutationFn: (id) => healthCenterService.remove(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['health-centers'] })
      toast.success('Centro de salud eliminado exitosamente')
    },
    onError: (error) => {
      const message = error?.response?.data?.message ?? 'Error al eliminar centro de salud'
      toast.error(message)
    },
  })
}
