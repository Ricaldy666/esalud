import { api, fetchCsrfCookie } from '@/shared/services/api'
import type { PaginatedResponse, ApiResponse } from '@/shared/types/api'
import type { User, UserFilters, CreateUserData, UpdateUserData } from '../types'

export const userService = {
  list: async (filters?: UserFilters): Promise<PaginatedResponse<User>> => {
    const params = new URLSearchParams()
    if (filters?.search) params.set('search', filters.search)
    if (filters?.role) params.set('role', filters.role)
    if (filters?.is_active !== undefined) params.set('is_active', String(filters.is_active))
    if (filters?.health_center_id) params.set('health_center_id', String(filters.health_center_id))
    if (filters?.page) params.set('page', String(filters.page))
    if (filters?.per_page) params.set('per_page', String(filters.per_page))

    const response = await api.get<PaginatedResponse<User>>(`/users?${params.toString()}`)
    return response.data
  },

  get: async (id: number): Promise<User> => {
    const response = await api.get<ApiResponse<User>>(`/users/${id}`)
    return response.data.data
  },

  create: async (data: CreateUserData): Promise<User> => {
    await fetchCsrfCookie()
    const response = await api.post<ApiResponse<User>>('/users', data)
    return response.data.data
  },

  update: async (id: number, data: UpdateUserData): Promise<User> => {
    await fetchCsrfCookie()
    const response = await api.put<ApiResponse<User>>(`/users/${id}`, data)
    return response.data.data
  },

  remove: async (id: number): Promise<void> => {
    await fetchCsrfCookie()
    await api.delete(`/users/${id}`)
  },
}
