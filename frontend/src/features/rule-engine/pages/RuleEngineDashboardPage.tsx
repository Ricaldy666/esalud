import {
  Activity,
  AlertTriangle,
  CheckCircle2,
  Database,
  FileWarning,
  FileText,
  Loader2,
  Server,
  Settings,
  UploadCloud,
  XCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { PageHeader } from '@/shared/components/PageHeader'
import { MetricCard } from '../components/MetricCard'
import { HelpTooltip } from '../components/HelpTooltip'
import { useRuleEngineHealth } from '../hooks/useRuleEngineHealth'
import { useRuleEngineStats } from '../hooks/useRuleEngineStats'
import { MODE_LABELS } from '../utils/labels'
import { getHelpText, getTriggerLabel, getStatusLabel } from '../utils/labels'

export default function RuleEngineDashboardPage() {
  const navigate = useNavigate()
  const { data: health, isLoading: healthLoading, isError: healthError } = useRuleEngineHealth()
  const { data: stats, isLoading: statsLoading, isError: statsError } = useRuleEngineStats()

  if (healthLoading || statsLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
      </div>
    )
  }

  if (healthError || statsError || !health || !stats) {
    return (
      <div>
        <div className="flex items-center gap-2">
          <PageHeader
            title="Motor de Reglas"
            description="Componente encargado de validar automáticamente la información del REM"
          />
        </div>
        <div className="flex items-center gap-3 mt-8 p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <AlertTriangle className="w-6 h-6 shrink-0" />
          <div>
            <p className="font-semibold">Error al cargar datos del motor de reglas</p>
            <p className="text-sm mt-1">Verifica que el backend esté disponible.</p>
          </div>
        </div>
      </div>
    )
  }

  const statusIcon = health.config_enabled ? CheckCircle2 : XCircle
  const statusVariant = health.config_enabled ? 'success' : 'danger'

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <PageHeader
          title="Motor de Reglas"
          description="Componente encargado de validar automáticamente la información del REM"
        />
        <HelpTooltip text={getHelpText('rule-engine') ?? ''} />
      </div>

      {/* Config summary */}
      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-1.5">
            <h2 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
              Configuración del Motor
            </h2>
            <HelpTooltip text={getHelpText('config') ?? ''} />
          </div>
          <button
            onClick={() => navigate('/rule-engine/config')}
            className="inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:text-blue-800 transition-colors"
          >
            <Settings className="h-3.5 w-3.5" />
            Configurar
          </button>
        </div>
        <div className="flex flex-wrap gap-4">
          <div className="flex items-center gap-2">
            <span className="text-xs text-slate-400">Motor:</span>
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${
                health.config_enabled
                  ? 'bg-emerald-100 text-emerald-700'
                  : 'bg-slate-100 text-slate-500'
              }`}
            >
              {health.config_enabled ? 'Habilitado' : 'Deshabilitado'}
            </span>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-xs text-slate-400">Modo:</span>
            <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
              {MODE_LABELS[health.config_mode]?.label ?? health.config_mode}
            </span>
          </div>
        </div>
      </div>

      {/* Metric cards row 1 */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <MetricCard
          icon={statusIcon}
          label="Estado"
          value={health.config_enabled ? 'Activo' : 'Inactivo'}
          subtitle={`Modo: ${MODE_LABELS[health.config_mode]?.label ?? health.config_mode}`}
          variant={statusVariant}
        />
        <MetricCard
          icon={Database}
          label="Reglas Activas"
          value={health.total_rules_active}
          subtitle={`${stats.rules_by_type['sum_equals'] ?? 0} · ${stats.rules_by_type['required_and_le_parent'] ?? 0} reglas`}
          variant="info"
        />
        <MetricCard
          icon={Server}
          label="Estructuras REM"
          value={health.total_structures}
          subtitle={`${health.structures_with_rules} con reglas · ${health.structures_without_bindings} sin asociaciones`}
          variant="default"
        />
        <MetricCard
          icon={UploadCloud}
          label="Archivos Validados"
          value={health.uploads_with_engine}
          subtitle={`de ${health.total_uploads} totales`}
          variant="default"
        />
      </div>

      {/* Metric cards row 2 */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <MetricCard
          icon={Activity}
          label="Ejecuciones"
          value={health.total_execution_logs}
          subtitle={`Promedio: ${stats.avg_execution_time_ms} ms`}
          variant="default"
        />
        <MetricCard
          icon={(stats.executions_by_status?.passed ?? 0 > 0) ? CheckCircle2 : Activity}
          label="Correctas"
          value={stats.executions_by_status?.passed ?? 0}
          variant="success"
        />
        <MetricCard
          icon={(stats.executions_by_status?.failed ?? 0 > 0) ? XCircle : Activity}
          label="Con observaciones"
          value={stats.executions_by_status?.failed ?? 0}
          variant={(stats.executions_by_status?.failed ?? 0 > 0) ? 'danger' : 'default'}
        />
        <MetricCard
          icon={FileWarning}
          label="Errores del Motor"
          value={health.error_logs}
          subtitle={
            health.last_error ? `Último: archivo #${health.last_error.upload_id}` : 'Sin errores'
          }
          variant={health.error_logs > 0 ? 'warning' : 'success'}
        />
      </div>

      {/* Stats by trigger & status */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">
            Ejecuciones por Origen
          </h2>
          <div className="space-y-2">
            {Object.entries(stats.executions_by_trigger ?? {}).map(([trigger, count]) => (
              <div key={trigger} className="flex items-center justify-between">
                <span className="text-sm text-slate-700 font-medium">
                  {getTriggerLabel(trigger)}
                </span>
                <span className="text-sm tabular-nums font-semibold text-slate-900">{count}</span>
              </div>
            ))}
            {Object.keys(stats.executions_by_trigger ?? {}).length === 0 && (
              <p className="text-sm text-slate-400">Sin ejecuciones registradas</p>
            )}
          </div>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">
            Ejecuciones por Resultado
          </h2>
          <div className="space-y-2">
            {Object.entries(stats.executions_by_status ?? {}).map(([status, count]) => (
              <div key={status} className="flex items-center justify-between">
                <span className="text-sm text-slate-700 font-medium">{getStatusLabel(status)}</span>
                <span className="text-sm tabular-nums font-semibold text-slate-900">{count}</span>
              </div>
            ))}
            {Object.keys(stats.executions_by_status ?? {}).length === 0 && (
              <p className="text-sm text-slate-400">Sin ejecuciones registradas</p>
            )}
          </div>
        </div>
      </div>

      {/* Latest uploads table */}
      <div className="rounded-xl border border-slate-200 bg-white overflow-hidden">
        <div className="p-5 border-b border-slate-100 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
            Últimos Archivos Procesados
          </h2>
          <button
            onClick={() => navigate('/rule-engine/logs')}
            className="inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:text-blue-800 transition-colors"
          >
            <FileText className="h-3.5 w-3.5" />
            Ver historial completo
          </button>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/50">
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Archivo
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Reglas
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Correctas
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Con observaciones
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  No aplica
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Tiempo
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Filas
                </th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                  Acción
                </th>
              </tr>
            </thead>
            <tbody>
              {stats.last_20_uploads?.length > 0 ? (
                stats.last_20_uploads.map((u) => (
                  <tr
                    key={u.rem_upload_id}
                    className="border-b border-slate-50 hover:bg-slate-50/50"
                  >
                    <td className="px-5 py-3 font-medium text-slate-900">#{u.rem_upload_id}</td>
                    <td className="px-5 py-3 text-slate-700">{u.total_rules}</td>
                    <td className="px-5 py-3">
                      <span className="text-emerald-600 font-medium">{u.passed}</span>
                    </td>
                    <td className="px-5 py-3">
                      <span
                        className={`font-medium ${u.failed > 0 ? 'text-rose-600' : 'text-slate-700'}`}
                      >
                        {u.failed}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-slate-500">{u.skipped}</td>
                    <td className="px-5 py-3 text-slate-700">{u.avg_ms.toFixed(1)} ms</td>
                    <td className="px-5 py-3 text-slate-700">{u.total_rows}</td>
                    <td className="px-5 py-3">
                      <button
                        onClick={() =>
                          navigate(`/rule-engine/uploads/${u.rem_upload_id}/validation-summary`)
                        }
                        className="text-xs font-medium text-blue-600 hover:text-blue-800 transition-colors"
                      >
                        Validación
                      </button>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={8} className="px-5 py-8 text-center text-slate-400">
                    No hay archivos procesados por el motor
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
