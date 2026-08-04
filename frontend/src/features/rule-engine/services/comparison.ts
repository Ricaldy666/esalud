import { api } from '@/shared/services/api'
import type { ComparisonReport } from '../types/comparison'

export const comparisonService = {
  run: async (structureId: number, uploadId: number): Promise<ComparisonReport> => {
    const { data } = await api.get<{ data: ComparisonReport }>('/rule-engine/compare', {
      params: { structure_id: structureId, upload_id: uploadId },
    })
    return data.data
  },
}
