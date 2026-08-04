import { useMutation } from '@tanstack/react-query'
import { comparisonService } from '../services/comparison'

export const useComparison = () =>
  useMutation({
    mutationFn: ({ structureId, uploadId }: { structureId: number; uploadId: number }) =>
      comparisonService.run(structureId, uploadId),
  })
