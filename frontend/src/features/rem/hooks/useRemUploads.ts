import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { remUploadsService } from '../services/rem-uploads'
import type { RemUploadFilters, RemUploadStatusResponse } from '../types/rem'
import { TERMINAL_REM_UPLOAD_STATUSES } from '../types/rem'
import { toast } from 'sonner'
import type { AxiosError } from 'axios'

// Tiempo sin alcanzar un estado terminal antes de avisar que puede haber un
// problema (ej. worker no esta corriendo). En una carga real con worker activo
// la cadena completa (parseo + validacion + motor de reglas) tomo ~25-60s en
// las pruebas -- 90s da margen sin ser alarmista antes de tiempo.
const STALL_THRESHOLD_MS = 90_000

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
        description: 'REM procesado y validado correctamente.',
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

export const useRemUploadPreview = () => {
  return useMutation({
    mutationFn: (file: File) => remUploadsService.preview(file),
  })
}

export const useRemUploadStatusPolling = (id: number | null, enabled: boolean) => {
  const query = useQuery({
    queryKey: ['rem-uploads', id, 'status-polling'],
    queryFn: () => remUploadsService.getStatus(id!),
    enabled: enabled && !!id,
    refetchInterval: (query) => {
      const data = query.state.data as RemUploadStatusResponse | undefined
      // pending, processing y validating siguen sondeando -- solo se detiene
      // en un estado realmente terminal, nunca antes de que la cadena completa
      // (parseo -> validacion -> motor de reglas) haya terminado de verdad.
      if (data && TERMINAL_REM_UPLOAD_STATUSES.includes(data.status)) {
        return false
      }
      return 2000
    },
    staleTime: 1000,
  })

  // Detecta estancamiento (ej. worker no esta corriendo) sin marcar la carga
  // como fallida -- el job sigue intacto en la cola, solo avisamos al usuario.
  const startedAtRef = useRef<number | null>(null)
  const [isStalled, setIsStalled] = useState(false)

  useEffect(() => {
    if (!enabled || !id) {
      startedAtRef.current = null
      // eslint-disable-next-line react-hooks/set-state-in-effect -- reset del flag de estancamiento cuando el polling se desactiva o cambia de carga
      setIsStalled(false)
      return
    }
    if (startedAtRef.current === null) {
      startedAtRef.current = Date.now()
      setIsStalled(false)
    }

    const interval = setInterval(() => {
      const startedAt = startedAtRef.current
      if (startedAt === null) return
      setIsStalled(Date.now() - startedAt >= STALL_THRESHOLD_MS)
    }, 3000)

    return () => clearInterval(interval)
  }, [enabled, id])

  return { ...query, isStalled }
}
