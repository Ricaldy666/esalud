import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios'
import { useAuthStore } from '@/app/store/authStore'

interface RetryConfig extends InternalAxiosRequestConfig {
  _retry?: boolean
}

export const api: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

const apiBaseUrl = import.meta.env.VITE_API_URL.replace('/api/v1', '')

export async function fetchCsrfCookie(): Promise<void> {
  await axios.get(`${apiBaseUrl}/sanctum/csrf-cookie`, { withCredentials: true })
}

api.interceptors.request.use(
  (config) => {
    if (!(config.data instanceof FormData)) {
      config.headers.set('Content-Type', 'application/json')
    } else {
      config.headers.delete('Content-Type')
    }
    return config
  },
  (error) => Promise.reject(error)
)

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const config = error.config as RetryConfig | undefined

    if (error.response?.status === 419 && config && !config._retry) {
      config._retry = true

      await fetchCsrfCookie()

      if (config.data instanceof FormData) {
        config.headers.delete('Content-Type')
      }

      return await api(config)
    }

    if (error.response?.status === 401) {
      // Solo es una expiracion real si el store ya marcaba al usuario como
      // autenticado antes de este 401 -- la comprobacion inicial de sesion
      // (ej. useAuthInit en /login sin cookie previa) tambien responde 401
      // y es el flujo esperado, no una perdida de sesion.
      if (useAuthStore.getState().isAuthenticated) {
        console.warn('Sesión expirada o no autenticada')
      }
    }

    return Promise.reject(error)
  }
)
