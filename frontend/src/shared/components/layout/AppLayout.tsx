import { Toaster } from 'sonner'
import type { ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import {
  Activity,
  LayoutDashboard,
  UploadCloud,
  Users,
  Building2,
  FileText,
  LogOut,
} from 'lucide-react'
import { useAuthStore } from '@/app/store/authStore'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useLogout } from '@/features/auth'

interface AppLayoutProps {
  children: ReactNode
}

export default function AppLayout({ children }: AppLayoutProps) {
  const navigate = useNavigate()
  const user = useAuthStore((s) => s.user)
  const logout = useLogout()
  const { isAdmin } = usePermissions()

  const handleLogout = async () => {
    await logout.mutateAsync()
    navigate('/login')
  }

  const primaryRole = user?.roles?.[0] ?? 'Usuario'

  const menuItems = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, show: true },
    { to: '/rem-uploads', label: 'Cargas REM', icon: UploadCloud, show: true },
    { to: '/users', label: 'Usuarios', icon: Users, show: isAdmin },
    { to: '/health-centers', label: 'Centros de Salud', icon: Building2, show: isAdmin },
    { to: '/audit', label: 'Auditoría', icon: FileText, show: isAdmin },
  ]

  return (
    <div className="flex h-screen overflow-hidden bg-slate-50 text-slate-800">
      <Toaster richColors position="top-right" />

      <aside className="w-64 bg-slate-900 text-slate-200 flex flex-col shadow-xl z-20 shrink-0">
        <div className="p-4 flex items-center gap-3 border-b border-slate-800">
          <div className="p-2 bg-blue-600 rounded-lg text-white">
            <Activity className="w-5 h-5" />
          </div>
          <span className="font-bold text-lg tracking-wide whitespace-nowrap">Estadística APS</span>
        </div>

        <div className="p-4 border-b border-slate-800 bg-slate-950/40">
          <p className="text-xs text-slate-500 uppercase font-semibold tracking-wider">
            Usuario Conectado
          </p>
          <p className="text-sm font-medium text-slate-200 truncate mt-1">
            {user?.name || 'Usuario'}
          </p>
          <span className="inline-flex items-center px-2 py-0.5 mt-1 rounded text-xs font-medium bg-blue-900/50 text-blue-400 border border-blue-800">
            {primaryRole}
          </span>
        </div>

        <nav className="p-3 space-y-1 flex-1 overflow-y-auto">
          {menuItems
            .filter((item) => item.show)
            .map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                className={({ isActive }) =>
                  `flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                    isActive
                      ? 'bg-slate-800 text-white'
                      : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                  }`
                }
              >
                {({ isActive }) => (
                  <>
                    <item.icon className={`w-5 h-5 ${isActive ? 'text-blue-500' : ''}`} />
                    <span className="truncate">{item.label}</span>
                  </>
                )}
              </NavLink>
            ))}
        </nav>

        <div className="p-3 border-t border-slate-800">
          <button
            onClick={handleLogout}
            className="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium text-rose-400 hover:bg-rose-950/30 hover:text-rose-300 transition-colors"
          >
            <LogOut className="w-5 h-5" />
            <span>Cerrar Sesión</span>
          </button>
        </div>
      </aside>

      <main className="flex-1 overflow-y-auto p-8">{children}</main>
    </div>
  )
}
