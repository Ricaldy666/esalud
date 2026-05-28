import { useMutation } from '@tanstack/react-query'
import { authService } from '../services/authService'
import { useAuthStore } from '@/app/store/authStore'

export const useLogin = () => {
  const setUser = useAuthStore((s) => s.setUser)

  return useMutation({
    mutationFn: authService.login,
    onSuccess: (user) => setUser(user),
  })
}
