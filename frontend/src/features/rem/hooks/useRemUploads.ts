import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { remUploadsService } from '../services/rem-uploads'
import type { RemUploadFilters } from '../types/rem'
import { toast } from 'sonner'
import type { AxiosError } from 'axios'

export const useRemUploads = (filters?: RemUploadFilters) => {
  return useQuery({
    queryKey: ['rem-uploads', filters],
    queryFn: () => remUploadsService.list(filters),
    staleTime: 30_000,
  })
}

export const useRemUpload = (id: number) => {
  return useQuery({
    queryKey: ['rem-uploads', id],
    queryFn: () => remUploadsService.get(id),
    enabled: !!id,
  })
}

export const useRemUploadValidation = (id: number, enabled: boolean) => {
  return useQuery({
    queryKey: ['rem-uploads', id, 'validation-results'],
    queryFn: () => remUploadsService.getValidationResults(id),
    enabled,
  })
}

export const useCreateRemUpload = () => {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: remUploadsService.create,
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['rem-uploads'] })
      toast.success(`REM cargado: ${data.original_filename}`, {
        description: 'El archivo se está procesando en segundo plano.',
      })
    },
    onError: (error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      const message = error?.response?.data?.message || 'Error al subir el archivo'
      const errors = error?.response?.data?.errors

      if (errors) {
        const firstError = Object.values(errors)[0] as string[]
        toast.error(message, {
          description: firstError?.[0] || 'Verificá los datos ingresados',
        })
      } else {
        toast.error(message)
      }
    },
  })
}
