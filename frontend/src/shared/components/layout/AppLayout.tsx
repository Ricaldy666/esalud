import { useState } from 'react'
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
  ChevronLeft,
  ChevronRight,
  Menu,
  X,
} from 'lucide-react'
import { useAuthStore } from '@/app/store/authStore'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useLogout } from '@/features/auth'

interface AppLayoutProps {
  children: ReactNode
}

const MENU_ITEMS = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, adminOnly: false },
  { to: '/rem-uploads', label: 'Cargas REM', icon: UploadCloud, adminOnly: false },
  { to: '/users', label: 'Usuarios', icon: Users, adminOnly: true },
  { to: '/health-centers', label: 'Centros de Salud', icon: Building2, adminOnly: true },
  { to: '/audit', label: 'Auditoría', icon: FileText, adminOnly: true },
]

function SidebarContent({
  collapsed,
  isAdmin,
  user,
  primaryRole,
  handleLogout,
  onToggleCollapse,
  onNavigate,
}: {
  collapsed: boolean
  isAdmin: boolean
  user: { name?: string; roles?: string[] } | null
  primaryRole: string
  handleLogout: () => Promise<void>
  onToggleCollapse: () => void
  onNavigate?: () => void
}) {
  return (
    <>
      {/* Logo + toggle desktop */}
      <div className="p-4 flex items-center justify-between border-b border-slate-800 shrink-0">
        <div className="flex items-center gap-3 overflow-hidden">
          <div className="p-2 bg-blue-600 rounded-lg text-white shrink-0">
            <Activity className="w-5 h-5" />
          </div>
          {!collapsed && (
            <span className="font-bold text-lg tracking-wide whitespace-nowrap">
              Estadística APS
            </span>
          )}
        </div>
        <button
          onClick={onToggleCollapse}
          className="hidden lg:flex p-1.5 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors shrink-0"
        >
          {collapsed ? <ChevronRight className="w-4 h-4" /> : <ChevronLeft className="w-4 h-4" />}
        </button>
      </div>

      {/* Usuario conectado */}
      {!collapsed && (
        <div className="p-4 border-b border-slate-800 bg-slate-950/40 shrink-0">
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
      )}
      {collapsed && (
        <div className="p-3 border-b border-slate-800 bg-slate-950/40 shrink-0 flex justify-center">
          <div className="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold">
            {user?.name?.[0]?.toUpperCase() ?? 'U'}
          </div>
        </div>
      )}

      {/* Nav */}
      <nav className="p-3 space-y-1 flex-1 overflow-y-auto">
        {MENU_ITEMS.filter((item) => !item.adminOnly || isAdmin).map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            onClick={onNavigate}
            className={({ isActive }) =>
              `flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                collapsed ? 'justify-center' : ''
              } ${
                isActive
                  ? 'bg-slate-800 text-white'
                  : 'text-slate-400 hover:bg-slate-800 hover:text-white'
              }`
            }
            title={collapsed ? item.label : undefined}
          >
            {({ isActive }) => (
              <>
                <item.icon className={`w-5 h-5 shrink-0 ${isActive ? 'text-blue-500' : ''}`} />
                {!collapsed && <span className="truncate">{item.label}</span>}
              </>
            )}
          </NavLink>
        ))}
      </nav>

      {/* Logout */}
      <div className="p-3 border-t border-slate-800 shrink-0">
        <button
          onClick={handleLogout}
          className={`flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium text-rose-400 hover:bg-rose-950/30 hover:text-rose-300 transition-colors ${
            collapsed ? 'justify-center' : ''
          }`}
          title={collapsed ? 'Cerrar Sesión' : undefined}
        >
          <LogOut className="w-5 h-5 shrink-0" />
          {!collapsed && <span>Cerrar Sesión</span>}
        </button>
      </div>
    </>
  )
}

export default function AppLayout({ children }: AppLayoutProps) {
  const navigate = useNavigate()
  const user = useAuthStore((s) => s.user)
  const logout = useLogout()
  const { isAdmin } = usePermissions()
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)

  const handleLogout = async () => {
    await logout.mutateAsync()
    navigate('/login')
  }

  const primaryRole = user?.roles?.[0] ?? 'Usuario'

  return (
    <div className="flex h-screen overflow-hidden bg-slate-50 text-slate-800">
      <Toaster richColors position="top-right" />

      {/* Sidebar desktop */}
      <aside
        className={`hidden lg:flex flex-col bg-slate-900 text-slate-200 shadow-xl z-20 shrink-0 transition-all duration-300 ${
          collapsed ? 'w-16' : 'w-64'
        }`}
      >
        <SidebarContent
          collapsed={collapsed}
          isAdmin={isAdmin}
          user={user}
          primaryRole={primaryRole}
          handleLogout={handleLogout}
          onToggleCollapse={() => setCollapsed(!collapsed)}
        />
      </aside>

      {/* Overlay mobile */}
      {mobileOpen && (
        <div
          className="lg:hidden fixed inset-0 bg-black/50 z-30"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Sidebar mobile (drawer) */}
      <aside
        className={`lg:hidden fixed top-0 left-0 h-full w-64 bg-slate-900 text-slate-200 shadow-xl z-40 flex flex-col transition-transform duration-300 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <SidebarContent
          collapsed={false}
          isAdmin={isAdmin}
          user={user}
          primaryRole={primaryRole}
          handleLogout={handleLogout}
          onToggleCollapse={() => {}}
          onNavigate={() => setMobileOpen(false)}
        />
      </aside>

      {/* Main */}
      <main className="flex-1 overflow-y-auto flex flex-col">
        {/* Header mobile con hamburger */}
        <div className="lg:hidden flex items-center gap-3 px-4 py-3 bg-white border-b border-slate-200 shrink-0">
          <button
            onClick={() => setMobileOpen(!mobileOpen)}
            className="p-2 rounded-lg hover:bg-slate-100 text-slate-600 transition-colors"
          >
            {mobileOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
          <div className="flex items-center gap-2">
            <div className="p-1.5 bg-blue-600 rounded-lg text-white">
              <Activity className="w-4 h-4" />
            </div>
            <span className="font-bold text-slate-900">Estadística APS</span>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-4 lg:p-8">{children}</div>
      </main>
    </div>
  )
}
