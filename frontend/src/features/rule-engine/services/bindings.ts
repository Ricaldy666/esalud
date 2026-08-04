import { api } from '@/shared/services/api'
import type { PaginatedResponse } from '@/shared/types/api'
import type { Binding, BindingFilters } from '../types/binding'

export const bindingsService = {
  list: async (filters?: BindingFilters): Promise<PaginatedResponse<Binding>> => {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== '' && value !== null) {
          params.append(key, String(value))
        }
      })
    }
    const { data } = await api.get<PaginatedResponse<Binding>>(
      `/rule-engine/bindings?${params.toString()}`
    )
    return data
  },

  get: async (id: number): Promise<Binding> => {
    const { data } = await api.get<{ data: Binding }>(`/rule-engine/bindings/${id}`)
    return data.data
  },
}
