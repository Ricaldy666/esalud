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
}

export interface LoginCredentials {
  email: string
  password: string
}
