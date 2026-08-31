import { useEffect, useState } from 'react'
import { authService } from '../services/authService'
import { useAuthStore } from '@/app/store/authStore'

export const useAuthInit = () => {
  const setUser = useAuthStore((s) => s.setUser)
  const setTwoFactorPending = useAuthStore((s) => s.setTwoFactorPending)
  const [isInitializing, setIsInitializing] = useState(true)

  useEffect(() => {
    authService
      .me()
      .then((result) => {
        if (result.status === 'requires_2fa') {
          // La pagina se recargo (o se abrio en otra pestaña) a mitad de
          // un desafio 2FA pendiente -- se retoma la pantalla de challenge
          // en vez de tratar la sesion como no autenticada.
          setTwoFactorPending(true)
          return
        }
        setUser(result.user)
      })
      .catch(() => setUser(null))
      .finally(() => setIsInitializing(false))
  }, [setUser, setTwoFactorPending])

  return { isInitializing }
}
