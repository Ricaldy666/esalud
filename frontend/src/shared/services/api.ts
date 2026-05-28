import axios, { AxiosError, type AxiosInstance } from 'axios'

export const api: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      console.warn('Sesión expirada o no autenticada')
    }
    if (error.response?.status === 419) {
      console.warn('CSRF token expirado')
    }
    return Promise.reject(error)
  }
)

export async function fetchCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}
