import { useQuery } from '@tanstack/react-query'
import { executionLogsService } from '../services/execution-logs'

export const useExecutionLog = (id: number | undefined) =>
  useQuery({
    queryKey: ['execution-log', id],
    queryFn: () => executionLogsService.get(id!),
    enabled: !!id,
    staleTime: 30_000,
  })
