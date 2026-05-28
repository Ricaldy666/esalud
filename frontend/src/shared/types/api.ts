export interface ApiResponse<T> {
  data: T
  message: string
  errors: Record<string, string[]> | null
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PaginationMeta
  message: string
  errors: Record<string, string[]> | null
}

export interface ApiError {
  message: string
  errors: Record<string, string[]> | null
  status: number
}
