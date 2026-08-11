import { useQuery } from '@tanstack/react-query'
import { calibrationService } from '../services/calibration'

/**
 * Progreso agregado de calibración de toda la estructura activa (ver
 * SectionCalibrationMatrixService::buildStructureCalibrationSummary()).
 * Un solo request compartido (misma queryKey) entre Dashboard, Plantilla y
 * Serie -- navegar entre esas 3 pantallas reutiliza la misma respuesta en
 * caché de React Query en vez de volver a pedirla.
 */
export function useCalibrationSummary() {
  return useQuery({
    queryKey: ['calibration-summary'],
    queryFn: () => calibrationService.getCalibrationSummary(),
    staleTime: 60_000,
  })
}
