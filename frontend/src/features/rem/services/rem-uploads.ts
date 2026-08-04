import { api, fetchCsrfCookie } from '@/shared/services/api'
import type {
  PaginatedResponse,
  RemUpload,
  RemUploadFilters,
  CreateRemUploadPayload,
  RemValidationResultsResponse,
  RemUploadPreview,
  RemUploadStatusResponse,
  RemValidationSummary,
} from '../types/rem'

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

  getStatus: async (id: number): Promise<RemUploadStatusResponse> => {
    const { data } = await api.get<{ data: RemUploadStatusResponse }>(`/rem-uploads/${id}/status`)
    return data.data
  },

  getValidationResults: async (id: number): Promise<RemValidationResultsResponse> => {
    const { data } = await api.get<{ data: RemValidationResultsResponse }>(
      `/rem-uploads/${id}/validation-results`
    )
    return data.data
  },

  getValidationSummary: async (id: number): Promise<RemValidationSummary> => {
    const { data } = await api.get<{ data: RemValidationSummary }>(
      `/rule-engine/uploads/${id}/validation-summary`
    )
    return data.data
  },

  preview: async (file: File): Promise<RemUploadPreview> => {
    console.log('Selected file:', file)
    console.log('Is File:', file instanceof File)

    const formData = new FormData()
    formData.append('file', file, file.name)

    console.log('FormData file:', formData.get('file'))

    await fetchCsrfCookie()

    const { data } = await api.post<{ data: RemUploadPreview }>('/rem-uploads/preview', formData)
    return data.data
  },

  create: async (payload: CreateRemUploadPayload): Promise<RemUpload> => {
    const formData = new FormData()
    formData.append('file', payload.file, payload.file.name)
    formData.append('year', payload.year.toString())
    formData.append('month', payload.month.toString())
    formData.append('rem_type', payload.rem_type)
    formData.append('health_center_id', payload.health_center_id.toString())

    await fetchCsrfCookie()

    const { data } = await api.post<{ data: RemUpload }>('/rem-uploads', formData)
    return data.data
  },
}
