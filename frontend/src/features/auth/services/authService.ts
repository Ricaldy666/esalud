import { api, fetchCsrfCookie } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type { LoginCredentials, LoginResult, SessionStatus, User } from '../types'

/** Forma cruda que puede devolver el backend en data para login/me. */
type RawAuthPayload = User | { requires_2fa: true }

/** Forma cruda de GET /auth/session -- siempre trae las 3 claves. */
interface RawSessionPayload {
  authenticated: boolean
  requires_2fa: boolean
  user: User | null
}

function toLoginResult(data: RawAuthPayload): LoginResult {
  if ('requires_2fa' in data) {
    return { status: 'requires_2fa' }
  }
  return { status: 'authenticated', user: data }
}

function toSessionStatus(data: RawSessionPayload): SessionStatus {
  if (data.authenticated && data.user) {
    return { status: 'authenticated', user: data.user }
  }
  if (data.requires_2fa) {
    return { status: 'requires_2fa' }
  }
  return { status: 'unauthenticated' }
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
   * backend responde 200 con requires_2fa en vez de 401. Sin sesion,
   * responde 401 (rechaza la promesa) -- solo debe usarse donde eso sea
   * una senal real (ver TwoFactorSettingsPanel). Para el chequeo de arranque
   * de la SPA (que sin sesion es el caso normal, no un error) usar session().
   */
  me: async (): Promise<LoginResult> => {
    const response = await api.get<ApiResponse<RawAuthPayload>>('/auth/me')
    return toLoginResult(response.data.data)
  },

  /**
   * Estado de sesion, siempre 200 -- pensado para useAuthInit (se consulta
   * en cada carga de la SPA, incluida /login sin sesion, que es el estado
   * normal, no un error). A diferencia de me(), nunca rechaza la promesa
   * por falta de sesion.
   */
  session: async (): Promise<SessionStatus> => {
    const response = await api.get<ApiResponse<RawSessionPayload>>('/auth/session')
    return toSessionStatus(response.data.data)
  },
}
