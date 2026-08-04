import { useQuery } from '@tanstack/react-query'
import { executionLogsService } from '../services/execution-logs'
import type { ExecutionLogFilters } from '../types/execution-log'

export const useExecutionLogs = (filters?: ExecutionLogFilters) =>
  useQuery({
    queryKey: ['execution-logs', filters],
    queryFn: () => executionLogsService.list(filters),
    staleTime: 30_000,
  })
