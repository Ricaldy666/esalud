import { useQuery } from '@tanstack/react-query'
import { calibrationService } from '../services/calibration'

export function useCalibrationMatrix(sheet: string | undefined, section: string | undefined) {
  return useQuery({
    queryKey: ['calibration-matrix', sheet, section],
    queryFn: () => calibrationService.getMatrix(sheet!, section!),
    enabled: !!sheet && !!section,
  })
}
