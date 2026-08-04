import { api } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type { SectionResponse, FunctionalRule } from '../types/functional-rule'

export const functionalRuleService = {
  getSection: async (
    sheet: string,
    section: string,
    params?: Record<string, string>
  ): Promise<SectionResponse> => {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    const { data } = await api.get<ApiResponse<SectionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}${query}`
    )
    return data.data
  },

  getFunctionalRule: async (ruleKey: string): Promise<FunctionalRule | null> => {
    const { data } = await api.get<ApiResponse<{ funcional: FunctionalRule | null }>>(
      `/rule-engine/catalog/${encodeURIComponent(ruleKey)}/functional-rules`
    )
    return data.data.funcional
  },

  saveFunctionalRule: async (
    ruleKey: string,
    payload: Partial<FunctionalRule>
  ): Promise<FunctionalRule> => {
    const { data } = await api.post<
      ApiResponse<{ success: boolean; message: string; funcional: FunctionalRule }>
    >(`/rule-engine/catalog/${encodeURIComponent(ruleKey)}/functional-rules`, payload)
    return data.data.funcional
  },

  getSectionExportUrl: (sheet: string, section: string): string => {
    return `${api.defaults.baseURL}/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/export`
  },
}
