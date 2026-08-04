import { useState, useEffect } from 'react'
import { validationService } from '../services/validation'
import type { ValidationSummary } from '../types/validation'

export function useValidationSummary(uploadId: number) {
  const [data, setData] = useState<ValidationSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reset de loading/error al iniciar cada fetch (uploadId cambia)
    setLoading(true)
    setError(null)
    validationService
      .getSummary(uploadId)
      .then((res) => {
        if (!cancelled) setData(res)
      })
      .catch((e) => {
        if (!cancelled) setError(e?.response?.data?.message || e.message)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [uploadId])

  return { data, loading, error }
}
