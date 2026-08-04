import { api } from '@/shared/services/api'
import type { PaginatedResponse } from '@/shared/types/api'
import type { Structure, StructureFilters } from '../types/structure'

export const structuresService = {
  list: async (filters?: StructureFilters): Promise<PaginatedResponse<Structure>> => {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== '' && value !== null) {
          params.append(key, String(value))
        }
      })
    }
    const { data } = await api.get<PaginatedResponse<Structure>>(
      `/rule-engine/structures?${params.toString()}`
    )
    return data
  },

  get: async (id: number): Promise<Structure> => {
    const { data } = await api.get<{ data: Structure }>(`/rule-engine/structures/${id}`)
    return data.data
  },
}
