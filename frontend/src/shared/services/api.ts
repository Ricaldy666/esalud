import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
  },
})

export async function fetchCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', {
    baseURL: import.meta.env.VITE_API_URL.replace('/api/v1', ''),
    withCredentials: true,
  })
}

export default api
