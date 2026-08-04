import { useState, useEffect } from 'react'
import { validationService } from '../services/validation'
import type { ValidationError, ValidationErrorFilters } from '../types/validation'

export function useValidationErrors(uploadId: number, filters?: ValidationErrorFilters) {
  const [data, setData] = useState<ValidationError[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const controller = new AbortController()
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reset de loading/error al iniciar cada fetch (uploadId/filters cambian)
    setLoading(true)
    setError(null)

    validationService
      .getErrors(uploadId, filters, controller.signal)
      .then((res) => setData(res))
      .catch((e) => {
        if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED') return
        setError(e?.response?.data?.message || e.message)
      })
      .finally(() => setLoading(false))

    return () => controller.abort()
  }, [uploadId, filters])

  return { data, loading, error }
}
