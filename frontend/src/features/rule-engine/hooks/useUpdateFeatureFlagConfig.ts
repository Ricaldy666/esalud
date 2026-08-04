import { useMutation, useQueryClient } from '@tanstack/react-query'
import { featureFlagService } from '../services/feature-flags'
import type { FeatureFlagConfig } from '../types/feature-flag'

export const useUpdateFeatureFlagConfig = () => {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: Partial<FeatureFlagConfig>) => featureFlagService.updateConfig(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['rule-engine', 'config'] })
      queryClient.invalidateQueries({ queryKey: ['rule-engine', 'health'] })
    },
  })
}
