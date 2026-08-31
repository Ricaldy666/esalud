import { useMutation } from '@tanstack/react-query'
import { authService } from '../services/authService'
import { useAuthStore } from '@/app/store/authStore'

export const useLogin = () => {
  const setUser = useAuthStore((s) => s.setUser)
  const setTwoFactorPending = useAuthStore((s) => s.setTwoFactorPending)

  return useMutation({
    mutationFn: authService.login,
    onSuccess: (result) => {
      if (result.status === 'requires_2fa') {
        setTwoFactorPending(true)
        return
      }
      setUser(result.user)
    },
  })
}
