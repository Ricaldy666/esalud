import { api } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type { SectionResponse, FunctionalRule } from '../types/functional-rule'

// BM-2 (2026-09-11): igual que certification.ts -- estas rutas cuelgan del
// grupo generalizado `catalog` (prefijo {serie}). RuleSectionPage/
// FunctionalRuleForm no tienen hoy un selector de serie en su ruta, asi
// que el `serie` se resuelve como 'A' explicito en el punto de llamada de
// cada pantalla, no aqui.
export const functionalRuleService = {
  getSection: async (
    serie: string,
    sheet: string,
    section: string,
    params?: Record<string, string>
  ): Promise<SectionResponse> => {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    const { data } = await api.get<ApiResponse<SectionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}${query}`
    )
    return data.data
  },

  getFunctionalRule: async (serie: string, ruleKey: string): Promise<FunctionalRule | null> => {
    const { data } = await api.get<ApiResponse<{ funcional: FunctionalRule | null }>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(ruleKey)}/functional-rules`
    )
    return data.data.funcional
  },

  saveFunctionalRule: async (
    serie: string,
    ruleKey: string,
    payload: Partial<FunctionalRule>
  ): Promise<FunctionalRule> => {
    const { data } = await api.post<
      ApiResponse<{ success: boolean; message: string; funcional: FunctionalRule }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(ruleKey)}/functional-rules`,
      payload
    )
    return data.data.funcional
  },

  getSectionExportUrl: (serie: string, sheet: string, section: string): string => {
    return `${api.defaults.baseURL}/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/export`
  },
}
