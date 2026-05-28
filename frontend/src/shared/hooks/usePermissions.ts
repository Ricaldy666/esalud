import { useAuthStore } from '@/app/store/authStore'

export const usePermissions = () => {
  const user = useAuthStore((s) => s.user)

  const hasRole = (role: string) => user?.roles.includes(role) ?? false
  const isAdmin = hasRole('Administrador')
  const isAnalista = hasRole('Analista')
  const isLector = hasRole('Lector')

  return { hasRole, isAdmin, isAnalista, isLector }
}
