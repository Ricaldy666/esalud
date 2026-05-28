export interface HealthData {
  status: string
  service: string
  version: string
  timestamp: string
}

export interface HealthResponse {
  data: HealthData
  message: string
  errors: null | Record<string, string[]>
}
