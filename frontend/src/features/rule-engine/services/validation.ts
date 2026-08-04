import { api } from '@/shared/services/api'
import type {
  ValidationSummary,
  ValidationError,
  ValidationErrorFilters,
} from '../types/validation'

export const validationService = {
  getSummary: async (uploadId: number): Promise<ValidationSummary> => {
    const { data } = await api.get<{ data: ValidationSummary }>(
      `/rule-engine/uploads/${uploadId}/validation-summary`
    )
    return data.data
  },

  getErrors: async (
    uploadId: number,
    filters?: ValidationErrorFilters,
    signal?: AbortSignal
  ): Promise<ValidationError[]> => {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== '' && value !== null) {
          params.append(key, String(value))
        }
      })
    }
    const qs = params.toString()
    const { data } = await api.get<{ data: ValidationError[] }>(
      `/rule-engine/uploads/${uploadId}/validation-errors${qs ? `?${qs}` : ''}`,
      { signal }
    )
    return data.data
  },
}
