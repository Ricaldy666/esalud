import { useMutation } from '@tanstack/react-query'
import { twoFactorService } from '../services/twoFactorService'
import { useAuthStore } from '@/app/store/authStore'

export const useTwoFactorChallenge = () => {
  const setUser = useAuthStore((s) => s.setUser)

  return useMutation({
    mutationFn: twoFactorService.verify,
    onSuccess: (result) => {
      if (result.status === 'authenticated') {
        setUser(result.user)
      }
    },
  })
}
