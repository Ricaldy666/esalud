import { api, fetchCsrfCookie } from '@/shared/services/api'
import type { PaginatedResponse, ApiResponse } from '@/shared/types/api'
import type {
  HealthCenter,
  HealthCenterFilters,
  CreateHealthCenterData,
  UpdateHealthCenterData,
} from '../types'

export const healthCenterService = {
  list: async (filters?: HealthCenterFilters): Promise<PaginatedResponse<HealthCenter>> => {
    const params = new URLSearchParams()
    if (filters?.search) params.set('search', filters.search)
    if (filters?.type) params.set('type', filters.type)
    if (filters?.is_active !== undefined) params.set('is_active', String(filters.is_active))
    if (filters?.page) params.set('page', String(filters.page))
    if (filters?.per_page) params.set('per_page', String(filters.per_page))

    const response = await api.get<PaginatedResponse<HealthCenter>>(
      `/health-centers?${params.toString()}`
    )
    return response.data
  },

  get: async (id: number): Promise<HealthCenter> => {
    const response = await api.get<ApiResponse<HealthCenter>>(`/health-centers/${id}`)
    return response.data.data
  },

  create: async (data: CreateHealthCenterData): Promise<HealthCenter> => {
    await fetchCsrfCookie()
    const response = await api.post<ApiResponse<HealthCenter>>('/health-centers', data)
    return response.data.data
  },

  update: async (id: number, data: UpdateHealthCenterData): Promise<HealthCenter> => {
    await fetchCsrfCookie()
    const response = await api.put<ApiResponse<HealthCenter>>(`/health-centers/${id}`, data)
    return response.data.data
  },

  remove: async (id: number): Promise<void> => {
    await fetchCsrfCookie()
    await api.delete(`/health-centers/${id}`)
  },
}
