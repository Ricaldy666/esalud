export interface User {
  id: number
  rut: string
  name: string
  email: string
  is_active: boolean
  health_center_id: number | null
  health_center?: { id: number; name: string; type: string } | null
  roles: string[]
  last_login_at: string | null
  created_at: string
  updated_at: string
}

export interface UserFilters {
  search?: string
  role?: string
  is_active?: boolean
  health_center_id?: number
  page?: number
  per_page?: number
}

export interface CreateUserData {
  name: string
  rut: string
  email: string
  password: string
  password_confirmation: string
  health_center_id: number | null
  role: string
  is_active: boolean
}

export interface UpdateUserData {
  name?: string
  rut?: string
  email?: string
  password?: string
  password_confirmation?: string
  health_center_id?: number | null
  role?: string
  is_active?: boolean
}
