import { api } from '@/shared/services/api'
import type { PaginatedResponse } from '@/shared/types/api'
import type { Rule, RuleFilters } from '../types/rule'

export const rulesService = {
  list: async (filters?: RuleFilters): Promise<PaginatedResponse<Rule>> => {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== '' && value !== null) {
          params.append(key, String(value))
        }
      })
    }
    const { data } = await api.get<PaginatedResponse<Rule>>(
      `/rule-engine/rules?${params.toString()}`
    )
    return data
  },

  get: async (id: number): Promise<Rule> => {
    const { data } = await api.get<{ data: Rule }>(`/rule-engine/rules/${id}`)
    return data.data
  },
}
