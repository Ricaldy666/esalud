import { useQuery } from '@tanstack/react-query'
import { calibrationService } from '../services/calibration'

export function useCalibrationMatrix(
  serie: string | undefined,
  sheet: string | undefined,
  section: string | undefined
) {
  return useQuery({
    queryKey: ['calibration-matrix', serie, sheet, section],
    queryFn: () => calibrationService.getMatrix(serie!, sheet!, section!),
    enabled: !!serie && !!sheet && !!section,
  })
}
