import { useQuery } from '@tanstack/react-query'
import { featureFlagService } from '../services/feature-flags'

export const useFeatureFlagConfig = () =>
  useQuery({
    queryKey: ['rule-engine', 'config'],
    queryFn: featureFlagService.getConfig,
    staleTime: 30_000,
  })
