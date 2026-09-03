import { useEffect, useRef, useState } from 'react'
import { authService } from '../services/authService'
import { useAuthStore } from '@/app/store/authStore'

export const useAuthInit = () => {
  const setUser = useAuthStore((s) => s.setUser)
  const setTwoFactorPending = useAuthStore((s) => s.setTwoFactorPending)
  const [isInitializing, setIsInitializing] = useState(true)
  // Guard contra el doble-montaje intencional de StrictMode en desarrollo
  // -- este efecto no tiene nada que limpiar (no es una suscripcion), es
  // una comprobacion de sesion que debe dispararse una sola vez por carga
  // real de la app. El ref sobrevive el remontaje sintetico de StrictMode
  // (misma instancia de fiber), asi que en dev evita la segunda peticion
  // duplicada a /auth/session sin desactivar StrictMode. En produccion no
  // hay doble-invoke, asi que esto no cambia el comportamiento.
  const hasInitialized = useRef(false)

  useEffect(() => {
    if (hasInitialized.current) return
    hasInitialized.current = true

    // /auth/session (a diferencia de /auth/me) responde siempre 200 -- no
    // hay sesion es un resultado normal (ej. abrir /login por primera vez),
    // no un error, asi que no genera una peticion fallida en el Network tab.
    authService
      .session()
      .then((result) => {
        if (result.status === 'requires_2fa') {
          // La pagina se recargo (o se abrio en otra pestaña) a mitad de
          // un desafio 2FA pendiente -- se retoma la pantalla de challenge
          // en vez de tratar la sesion como no autenticada.
          setTwoFactorPending(true)
          return
        }
        setUser(result.status === 'authenticated' ? result.user : null)
      })
      // Solo deberia llegar aqui un error real (red, 500) -- authenticated:false
      // ya no pasa por aqui, viene resuelto arriba como parte de la respuesta.
      .catch(() => setUser(null))
      .finally(() => setIsInitializing(false))
  }, [setUser, setTwoFactorPending])

  return { isInitializing }
}
