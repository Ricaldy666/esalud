import { useQuery } from '@tanstack/react-query'
import { calibrationService } from '../services/calibration'

/**
 * Progreso agregado de calibración de toda la estructura activa (ver
 * SectionCalibrationMatrixService::buildStructureCalibrationSummary()).
 * Un solo request compartido (misma queryKey) entre Dashboard, Plantilla y
 * Serie -- navegar entre esas 3 pantallas reutiliza la misma respuesta en
 * caché de React Query en vez de volver a pedirla.
 *
 * BM-2 (2026-09-11): `serie` es explícito y obligatorio -- ninguna llamada
 * puede asumir 'A' implícitamente. `enabled` evita disparar la consulta
 * mientras el caller todavía no conoce la serie (p. ej. CalibrationTemplatePage
 * antes de que cargue la estructura).
 */
export function useCalibrationSummary(serie: string | undefined) {
  return useQuery({
    queryKey: ['calibration-summary', serie],
    queryFn: () => calibrationService.getCalibrationSummary(serie!),
    enabled: !!serie,
    staleTime: 60_000,
  })
}
