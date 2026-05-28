import { api } from '@/shared/services/api'
import type { HealthResponse } from '../types'

export async function getHealthStatus(): Promise<HealthResponse> {
  const response = await api.get<HealthResponse>('/health')
  return response.data
}
