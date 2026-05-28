import { useMutation } from '@tanstack/react-query'
import { authService } from '../services/authService'
import { useAuthStore } from '@/app/store/authStore'

export const useLogout = () => {
  const clearAuth = useAuthStore((s) => s.clearAuth)

  return useMutation({
    mutationFn: authService.logout,
    onSuccess: () => clearAuth(),
  })
}
