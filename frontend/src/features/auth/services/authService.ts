import { api, fetchCsrfCookie } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type { LoginCredentials, User } from '../types'

export const authService = {
  login: async (credentials: LoginCredentials): Promise<User> => {
    await fetchCsrfCookie()
    const response = await api.post<ApiResponse<User>>('/auth/login', credentials)
    return response.data.data
  },

  logout: async (): Promise<void> => {
    await api.post('/auth/logout')
  },

  me: async (): Promise<User> => {
    const response = await api.get<ApiResponse<User>>('/auth/me')
    return response.data.data
  },
}
