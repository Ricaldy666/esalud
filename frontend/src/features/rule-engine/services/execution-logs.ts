import { api } from '@/shared/services/api'
import type { PaginatedResponse } from '@/shared/types/api'
import type { ExecutionLog, ExecutionLogFilters } from '../types/execution-log'

export const executionLogsService = {
  list: async (filters?: ExecutionLogFilters): Promise<PaginatedResponse<ExecutionLog>> => {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== '' && value !== null) {
          params.append(key, String(value))
        }
      })
    }
    const { data } = await api.get<PaginatedResponse<ExecutionLog>>(
      `/rule-engine/logs?${params.toString()}`
    )
    return data
  },

  get: async (id: number): Promise<ExecutionLog> => {
    const { data } = await api.get<{ data: ExecutionLog }>(`/rule-engine/logs/${id}`)
    return data.data
  },
}
