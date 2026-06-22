import { api } from '@/shared/services/api'
import type { PaginatedResponse, RemUpload, RemUploadFilters } from '../types/rem'

export const remUploadsService = {
  list: async (filters?: RemUploadFilters): Promise<PaginatedResponse<RemUpload>> => {
    const params = new URLSearchParams()
    if (filters?.page) params.set('page', String(filters.page))
    if (filters?.per_page) params.set('per_page', String(filters.per_page))
    if (filters?.year) params.set('year', String(filters.year))
    if (filters?.month) params.set('month', String(filters.month))
    if (filters?.rem_type) params.set('rem_type', filters.rem_type)
    if (filters?.status) params.set('status', filters.status)
    if (filters?.health_center_id) params.set('health_center_id', String(filters.health_center_id))

    const response = await api.get<PaginatedResponse<RemUpload>>(
      `/rem-uploads?${params.toString()}`
    )
    return response.data
  },

  get: async (id: number): Promise<RemUpload> => {
    const { data } = await api.get<{ data: RemUpload }>(`/rem-uploads/${id}`)
    return data.data
  },

  getStatus: async (id: number) => {
    const { data } = await api.get(`/rem-uploads/${id}/status`)
    return data
  },
}
