import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  ClipboardCheck,
  Database,
  Gavel,
  LayoutDashboard,
  UploadCloud,
  Users,
} from 'lucide-react'
import { useAuthStore } from '@/app/store/authStore'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/components/ui/card'
import { Badge } from '@/shared/components/ui/badge'
import { PageHeader } from '@/shared/components/PageHeader'
import { getRoleDisplayLabel } from '@/shared/utils/roleLabels'
import { MetricCard, useRuleEngineHealth } from '@/features/rule-engine'
import { useRemUploads } from '@/features/rem'

// Duplicado deliberadamente desde UsersTable.tsx -- el modulo Usuarios ya
// quedo cerrado y no se toca; este mapa es puramente presentacional.
const ROLE_BADGE_STYLES: Record<string, string> = {
  Superadmin: 'bg-violet-50 text-violet-700 border-violet-200',
  Administrador: 'bg-blue-50 text-blue-700 border-blue-200',
  Analista: 'bg-indigo-50 text-indigo-700 border-indigo-200',
}
const DEFAULT_ROLE_BADGE_STYLE = 'bg-slate-100 text-slate-600 border-slate-200'

// Mismos roles que ya usa el shortcut "Motor de Reglas" mas abajo y el menu
// lateral (AppLayout.tsx) para /rule-engine -- el resumen del motor solo se
// pide (y se muestra) a roles que ya tienen acceso a esa seccion.
const RULE_ENGINE_SUMMARY_ROLES = ['Administrador', 'Superadmin', 'Revisor', 'Auditor']

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

// Se monta solo para roles con acceso a /rule-engine (ver
// RULE_ENGINE_SUMMARY_ROLES) -- asi el hook de datos reales del motor nunca
// se ejecuta para un rol sin ese permiso. Reutiliza useRuleEngineHealth,
// el mismo hook que ya alimenta RuleEngineDashboardPage -- sin endpoint
// nuevo, sin logica nueva de backend.
function RuleEngineSummary() {
  const { data: health, isLoading, isError } = useRuleEngineHealth()

  if (isLoading || isError || !health) return null

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <MetricCard
        icon={Gavel}
        label="Reglas activas"
        value={health.total_rules_active}
        variant="info"
      />
      <MetricCard
        icon={Database}
        label="Bindings activos"
        value={health.total_bindings_active}
        variant="default"
      />
      <MetricCard
        icon={UploadCloud}
        label="Cargas con motor ejecutado"
        value={health.uploads_with_engine}
        subtitle={`de ${health.total_uploads} totales`}
        variant="default"
      />
      <MetricCard
        icon={AlertTriangle}
        label="Logs con error"
        value={health.error_logs}
        variant={health.error_logs > 0 ? 'danger' : 'success'}
      />
    </div>
  )
}

// Reutiliza useRemUploads (mismo hook de la pantalla Cargas REM) pidiendo
// solo 1 registro -- el total real ya viene en la paginacion del backend
// (meta.total), sin traer ni procesar la lista completa.
function RemUploadsSummary() {
  const { data, isLoading, isError } = useRemUploads({ per_page: 1 })

  if (isLoading || isError || !data) return null

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <MetricCard
        icon={UploadCloud}
        label="Cargas REM totales"
        value={data.meta.total}
        variant="default"
      />
    </div>
  )
}

export default function DashboardPage() {
  const user = useAuthStore((s) => s.user)

  const visibleShortcuts = SHORTCUTS.filter(
    (item) => item.roles.includes('all') || item.roles.some((r) => user?.roles?.includes(r))
  )
  const showRuleEngineSummary = user?.roles?.some((r) => RULE_ENGINE_SUMMARY_ROLES.includes(r))

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Dashboard"
        description="Panel principal de ATENEA — Estadística APS"
        icon={LayoutDashboard}
      />

      <Card>
        <CardHeader>
          <CardTitle>Bienvenido{user ? `, ${user.name}` : ''}</CardTitle>
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

      <RemUploadsSummary />
      {showRuleEngineSummary && <RuleEngineSummary />}

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
