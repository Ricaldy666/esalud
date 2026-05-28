import { api } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'

export const roleService = {
  list: async (): Promise<string[]> => {
    const response = await api.get<ApiResponse<string[]>>('/roles')
    return response.data.data
  },
}
