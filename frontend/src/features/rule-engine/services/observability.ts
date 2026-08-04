import { api } from '@/shared/services/api'
import type { HealthData, StatsData } from '../types/observability'

export const observabilityService = {
  getHealth: async (): Promise<HealthData> => {
    const { data } = await api.get<{ data: HealthData }>('/rule-engine/health')
    return data.data
  },

  getStats: async (): Promise<StatsData> => {
    const { data } = await api.get<{ data: StatsData }>('/rule-engine/stats')
    return data.data
  },
}
