import { useEffect, useState } from 'react'
import { validationService } from '../services/validation'
import type { ValidationError, ValidationErrorFilters } from '../types/validation'

export function useValidationErrors(uploadId: number, filters?: ValidationErrorFilters) {
  const [data, setData] = useState<ValidationError[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    // Una peticion abortada (StrictMode remonta el efecto dos veces en dev,
    // o el usuario cambia de filtro mientras la anterior sigue en vuelo) no
    // debe poder pisar el resultado de la peticion vigente -- de lo
    // contrario, cuando la peticion vieja resuelve DESPUES de que la nueva
    // ya arranco, su .finally() marca loading=false con data todavia vacia,
    // y la pantalla muestra "sin resultados" por un instante antes de que
    // llegue la respuesta real.
    let cancelled = false
    // eslint-disable-next-line react-hooks/set-state-in-effect -- reset de loading/error al iniciar cada fetch (uploadId/filters/reloadToken cambian)
    setLoading(true)
    setError(null)

    validationService
      .getErrors(uploadId, filters, controller.signal)
      .then((res) => {
        if (!cancelled) setData(res)
      })
      .catch((e) => {
        if (cancelled || e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED') return
        setError(e?.response?.data?.message || e.message)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [uploadId, filters, reloadToken])

  const refetch = () => setReloadToken((token) => token + 1)

  return { data, loading, error, refetch }
}
