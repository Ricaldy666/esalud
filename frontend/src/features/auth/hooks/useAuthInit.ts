import { useEffect, useState } from 'react'
import { authService } from '../services/authService'
import { useAuthStore } from '@/app/store/authStore'

export const useAuthInit = () => {
  const setUser = useAuthStore((s) => s.setUser)
  const [isInitializing, setIsInitializing] = useState(true)

  useEffect(() => {
    authService
      .me()
      .then((user) => setUser(user))
      .catch(() => setUser(null))
      .finally(() => setIsInitializing(false))
  }, [setUser])

  return { isInitializing }
}
