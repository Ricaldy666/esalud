import { api, fetchCsrfCookie } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type { LoginCredentials, LoginResult, User } from '../types'

/** Forma cruda que puede devolver el backend en data para login/me. */
type RawAuthPayload = User | { requires_2fa: true }

function toLoginResult(data: RawAuthPayload): LoginResult {
  if ('requires_2fa' in data) {
    return { status: 'requires_2fa' }
  }
  return { status: 'authenticated', user: data }
}

export const authService = {
  login: async (credentials: LoginCredentials): Promise<LoginResult> => {
    await fetchCsrfCookie()
    const response = await api.post<ApiResponse<RawAuthPayload>>('/auth/login', credentials)
    return toLoginResult(response.data.data)
  },

  logout: async (): Promise<void> => {
    await api.post('/auth/logout')
  },

  /**
   * Devuelve el mismo tipo discriminado que login() -- si hay un desafio
   * 2FA pendiente (ej. la pagina se recargo a mitad del challenge), el
   * backend responde 200 con requires_2fa en vez de 401.
   */
  me: async (): Promise<LoginResult> => {
    const response = await api.get<ApiResponse<RawAuthPayload>>('/auth/me')
    return toLoginResult(response.data.data)
  },
}
