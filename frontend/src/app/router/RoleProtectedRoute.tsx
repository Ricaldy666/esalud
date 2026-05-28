import { Navigate, Outlet } from 'react-router-dom'
import { useAuthStore } from '@/app/store/authStore'

interface RoleProtectedRouteProps {
  allowedRoles: string[]
}

export function RoleProtectedRoute({ allowedRoles }: RoleProtectedRouteProps) {
  const user = useAuthStore((s) => s.user)

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const hasAccess = allowedRoles.some((role) => user.roles.includes(role))

  if (!hasAccess) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
