import { useAuthStore } from '@/app/store/authStore'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user)

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>

      <Card>
        <CardHeader>
          <CardTitle>Bienvenido{user ? `, ${user.name}` : ''}</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-gray-500">
            {user && (
              <>
                Rol(es): <span className="font-medium text-gray-900">{user.roles.join(', ')}</span>
              </>
            )}
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
