import axios from 'axios'
import type { AxiosInstance } from 'axios'

let primeToken: string | null = localStorage.getItem('prime_token')

export const apiPrime: AxiosInstance = axios.create({
  baseURL: '',
  headers: { Accept: 'application/json' },
})

apiPrime.interceptors.request.use((config) => {
  if (primeToken) {
    config.headers.set('Authorization', `Bearer ${primeToken}`)
  }
  if (!(config.data instanceof FormData)) {
    config.headers.set('Content-Type', 'application/json')
  } else {
    config.headers.delete('Content-Type')
  }
  return config
})

apiPrime.interceptors.response.use(
  (r) => r,
  async (err) => {
    if (err.response?.status === 401 && primeToken) {
      primeToken = null
      localStorage.removeItem('prime_token')
      try {
        await loginPrime()
        if (primeToken && err.config) {
          err.config.headers.set('Authorization', `Bearer ${primeToken}`)
          return apiPrime(err.config)
        }
      } catch {
        // Re-login fallo: se propaga el error original de abajo, no el del reintento.
      }
    }
    return Promise.reject(err)
  }
)

export async function loginPrime(): Promise<void> {
  const { data } = await axios.post(
    '/api/login',
    {
      email: 'admin@esalud.cl',
      password: 'Admin1234!',
    },
    { headers: { Accept: 'application/json' } }
  )
  primeToken = data.token
  localStorage.setItem('prime_token', data.token)
}
