import type { QueryClient } from '@tanstack/react-query'

/**
 * Invalida todas las queries que muestran la calibracion de UNA seccion, para
 * que la pantalla refleje lo recien guardado sin recargar. Antes cada guardado
 * invalidaba solo 'pattern-matrix' y el resto (tabla por fila, plan de
 * migracion, matriz avanzada, progreso de hoja/serie) quedaba obsoleto hasta
 * vencer el staleTime global.
 *
 * Devuelve la promesa de las invalidaciones: si se retorna desde onSuccess,
 * la mutation sigue en estado "pending" hasta que los datos activos ya se
 * volvieron a leer del backend.
 */
export function invalidateSectionCalibration(
  queryClient: QueryClient,
  serie: string,
  sheet: string,
  section: string
) {
  return Promise.all([
    queryClient.invalidateQueries({ queryKey: ['pattern-matrix', serie, sheet, section] }),
    queryClient.invalidateQueries({
      queryKey: ['row-functional-decisions', serie, sheet, section],
    }),
    queryClient.invalidateQueries({ queryKey: ['migration-plan', serie, sheet, section] }),
    queryClient.invalidateQueries({ queryKey: ['calibration-matrix', serie, sheet, section] }),
    queryClient.invalidateQueries({ queryKey: ['calibration-questions', serie, sheet, section] }),
    queryClient.invalidateQueries({ queryKey: ['calibration-summary', serie] }),
  ])
}
