export interface HealthCenter {
  id: number
  name: string
  code_deis: string
  type: string
  address: string | null
  commune: string | null
  is_active: boolean
  users_count?: number
  created_at: string
  updated_at: string
}

export interface HealthCenterFilters {
  search?: string
  type?: string
  is_active?: boolean
  page?: number
  per_page?: number
}

export interface CreateHealthCenterData {
  name: string
  code_deis: string
  type: string
  address?: string | null
  commune?: string | null
  is_active: boolean
}

export interface UpdateHealthCenterData {
  name?: string
  code_deis?: string
  type?: string
  address?: string | null
  commune?: string | null
  is_active?: boolean
}
