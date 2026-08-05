import { Link } from 'react-router-dom'
import {
  ClipboardCheck,
  Gavel,
  LayoutDashboard,
  ListChecks,
  UploadCloud,
  Users,
} from 'lucide-react'
import { useAuthStore } from '@/app/store/authStore'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import { Badge } from '@/shared/components/ui/badge'
import { getRoleDisplayLabel } from '@/shared/utils/roleLabels'

// Duplicado deliberadamente desde UsersTable.tsx -- el modulo Usuarios ya
// quedo cerrado y no se toca; este mapa es puramente presentacional.
const ROLE_BADGE_STYLES: Record<string, string> = {
  Superadmin: 'bg-violet-50 text-violet-700 border-violet-200',
  Administrador: 'bg-blue-50 text-blue-700 border-blue-200',
  Analista: 'bg-indigo-50 text-indigo-700 border-indigo-200',
}
const DEFAULT_ROLE_BADGE_STYLE = 'bg-slate-100 text-slate-600 border-slate-200'

interface Shortcut {
  to: string
  label: string
  description: string
  icon: typeof UploadCloud
  roles: string[]
}

const SHORTCUTS: Shortcut[] = [
  {
    to: '/rem-uploads',
    label: 'Cargas REM',
    description: 'Subir y revisar archivos REM',
    icon: UploadCloud,
    roles: ['all'],
  },
  {
    to: '/criterios-funcionales',
    label: 'Criterios funcionales',
    description: 'Revisar criterios de validación',
    icon: ListChecks,
    roles: ['all'],
  },
  {
    to: '/calibracion',
    label: 'Calibración REM',
    description: 'Mapeo de estructuras REM',
    icon: ClipboardCheck,
    roles: ['Superadmin', 'Analista', 'Revisor', 'Auditor'],
  },
  {
    to: '/rule-engine',
    label: 'Motor de Reglas',
    description: 'Reglas de consistencia y validación',
    icon: Gavel,
    roles: ['Administrador', 'Superadmin', 'Revisor', 'Auditor'],
  },
  {
    to: '/users',
    label: 'Usuarios',
    description: 'Gestión de cuentas y permisos',
    icon: Users,
    roles: ['Administrador', 'Superadmin'],
  },
]

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user)

  const visibleShortcuts = SHORTCUTS.filter(
    (item) => item.roles.includes('all') || item.roles.some((r) => user?.roles?.includes(r))
  )

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <Card className="border border-slate-200 bg-white px-5 py-5 shadow-sm">
        <div className="flex items-center gap-3">
          <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
            <LayoutDashboard className="size-5" />
          </div>
          <div>
            <h1 className="text-xl font-bold text-slate-900">Dashboard</h1>
            <p className="text-sm text-slate-500">Panel principal de ATENEA — Estadística APS</p>
          </div>
        </div>
      </Card>

      <Card className="border border-slate-200 bg-white shadow-sm">
        <CardHeader>
          <CardTitle className="text-slate-900">Bienvenido{user ? `, ${user.name}` : ''}</CardTitle>
        </CardHeader>
        <CardContent>
          {user && (
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm text-slate-500">Rol(es):</span>
              {user.roles.map((role) => (
                <Badge
                  key={role}
                  variant="outline"
                  className={`font-medium ${ROLE_BADGE_STYLES[role] ?? DEFAULT_ROLE_BADGE_STYLE}`}
                >
                  {getRoleDisplayLabel(role)}
                </Badge>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {visibleShortcuts.length > 0 && (
        <div>
          <h2 className="mb-3 text-sm font-semibold text-slate-600">Accesos rápidos</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {visibleShortcuts.map((item) => (
              <Link
                key={item.to}
                to={item.to}
                className="group flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
              >
                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 transition-colors group-hover:bg-blue-600 group-hover:text-white">
                  <item.icon className="size-5" />
                </div>
                <div>
                  <p className="text-sm font-semibold text-slate-900">{item.label}</p>
                  <p className="mt-0.5 text-xs text-slate-500">{item.description}</p>
                </div>
              </Link>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
