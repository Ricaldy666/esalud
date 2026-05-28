export interface ApiResponse<T> {
  data: T
  message: string
  errors: Record<string, string[]> | null
}
