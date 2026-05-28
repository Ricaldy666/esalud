import { Navigate, Outlet } from 'react-router-dom'
import AppLayout from '@/shared/components/layout/AppLayout'
import { useAuthStore } from '@/app/store/authStore'

export function ProtectedRoute() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  return (
    <AppLayout>
      <Outlet />
    </AppLayout>
  )
}
