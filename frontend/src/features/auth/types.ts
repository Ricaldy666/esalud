export interface User {
  id: number
  rut: string
  name: string
  email: string
  is_active: boolean
  health_center_id: number | null
  health_centers: number[]
  roles: string[]
  last_login_at: string | null
  two_factor_enabled: boolean
  must_enroll_two_factor: boolean
}

export interface LoginCredentials {
  email: string
  password: string
}

/**
 * Resultado discriminado de un intento de login -- 'requires_2fa' significa
 * que la password fue correcta pero la sesion NO esta completamente
 * autenticada todavia (ver authStore.twoFactorPending).
 */
export type LoginResult = { status: 'authenticated'; user: User } | { status: 'requires_2fa' }
