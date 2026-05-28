import type { ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/shared/components/ui/button'
import { useAuthStore } from '@/app/store/authStore'
import { useLogout } from '@/features/auth'

interface AppLayoutProps {
  children: ReactNode
}

export default function AppLayout({ children }: AppLayoutProps) {
  const navigate = useNavigate()
  const user = useAuthStore((s) => s.user)
  const logout = useLogout()

  const handleLogout = async () => {
    await logout.mutateAsync()
    navigate('/login')
  }

  return (
    <div className="flex min-h-screen bg-gray-50">
      <aside className="w-64 border-r bg-white p-4">
        <div className="mb-8">
          <h2 className="text-lg font-bold text-gray-900">Esalud</h2>
        </div>
        <nav className="space-y-2">
          <a
            href="/"
            className="block rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-900"
          >
            Dashboard
          </a>
          <a
            href="#"
            className="block rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100"
          >
            Carga REM
          </a>
          <a
            href="#"
            className="block rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100"
          >
            Metas Sanitarias
          </a>
          <a
            href="#"
            className="block rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100"
          >
            Biblioteca
          </a>
        </nav>
      </aside>

      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-between border-b bg-white px-6 py-3">
          <span className="text-sm text-gray-500">{user && `${user.name}`}</span>
          <Button variant="outline" size="sm" onClick={handleLogout}>
            Cerrar sesión
          </Button>
        </header>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  )
}
