import { api } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type {
  CatalogResponse,
  CertificationDetailResponse,
  CertificationUpdateResponse,
  CertificationPayload,
} from '../types/certification'

export const certificationService = {
  list: async (params?: Record<string, string>): Promise<CatalogResponse> => {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    const { data } = await api.get<ApiResponse<CatalogResponse>>(`/rule-engine/catalog${query}`)
    return data.data
  },

  get: async (ruleKey: string): Promise<CertificationDetailResponse> => {
    const { data } = await api.get<ApiResponse<CertificationDetailResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(ruleKey)}`
    )
    return data.data
  },

  updateStatus: async (
    ruleKey: string,
    payload: CertificationPayload
  ): Promise<CertificationUpdateResponse> => {
    const { data } = await api.post<ApiResponse<CertificationUpdateResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(ruleKey)}/status`,
      payload
    )
    return data.data
  },

  getExportUrl: (): string => {
    return `${api.defaults.baseURL}/rule-engine/catalog/export`
  },
}
