import { api } from '@/shared/services/api'
import type { FeatureFlagConfig } from '../types/feature-flag'

export const featureFlagService = {
  getConfig: async (): Promise<FeatureFlagConfig> => {
    const { data } = await api.get<{ data: FeatureFlagConfig }>('/rule-engine/config')
    return data.data
  },

  updateConfig: async (payload: Partial<FeatureFlagConfig>): Promise<FeatureFlagConfig> => {
    const { data } = await api.put<{ data: FeatureFlagConfig }>('/rule-engine/config', payload)
    return data.data
  },
}
