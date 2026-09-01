import { useParams, useNavigate } from 'react-router-dom'
import type { ColumnDef } from '@tanstack/react-table'
import { useRule } from '../hooks/useRule'
import { RuleStatusBadge } from '../components/RuleStatusBadge'
import { SeverityBadge } from '../components/SeverityBadge'
import { HelpTooltip } from '../components/HelpTooltip'
import { TechnicalInfo } from '../components/TechnicalInfo'
import { ExecutionStatusBadge } from '../components/ExecutionStatusBadge'
import { PageHeader } from '@/shared/components/PageHeader'
import { DataTable } from '@/shared/components/DataTable'
import { Loader2, ArrowLeft, List, Info, AlertTriangle, Gavel } from 'lucide-react'
import {
  getRuleTypeLabel,
  getSourceLabel,
  getBindableTypeLabel,
  getHelpText,
  getTriggerLabel,
} from '../utils/labels'
import type { RuleVersion, RuleBinding, RuleExecutionLog } from '../types/rule'

// Ninguna de las 3 tablas de esta pagina tenia hover propio (solo un
// border-b como divisor) -- se fija el hover al mismo fondo para que
// TableRow no le agregue uno nuevo que antes no existia.
const noHoverRowClassName = () => 'hover:bg-white'

function formatJson(data: unknown): string {
  if (!data) return '—'
  try {
    return JSON.stringify(data, null, 2)
  } catch {
    return String(data)
  }
}

function getCampoLabel(rule: { metadata?: Record<string, unknown> | null; name?: string }): string {
  const name = rule.metadata?.label || rule.name
  if (!name || typeof name !== 'string') return '—'
  const match = name.match(/variable\s+(.+?)\./u)
  if (match) return match[1].trim()
  const parts = name.split(':')
  return parts.length > 1 ? parts[1].trim() : name
}

function getFormLabel(meta: Record<string, unknown> | null | undefined): string {
  if (!meta) return '—'
  const sheet = meta.sheet
  const section = meta.section
  const letra = meta.letra
  const parts: string[] = []
  if (sheet) parts.push(`Formulario ${sheet}`)
  if (section) parts.push(`sección ${section}`)
  if (letra) parts.push(`columna ${letra}`)
  return parts.length > 0 ? parts.join(', ') : '—'
}

const ruleTypeDescriptions: Record<string, { what: string; action: string }> = {
  sum_equals: {
    what: 'Verifica que la suma de las variables detalle sea igual al total declarado.',
    action: 'Revise los valores digitados. Si alguna variable no corresponde, registre 0.',
  },
  required_and_le_parent: {
    what: 'Verifica que se haya ingresado un valor y que no supere el total del formulario.',
    action: 'Revise las variables afectadas. Si la variable no corresponde, registre 0.',
  },
}

export default function RuleDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const ruleId = id ? Number(id) : undefined
  const { data: rule, isLoading, isError } = useRule(ruleId)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
      </div>
    )
  }

  if (isError || !rule) {
    return (
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader title="Regla de Consistencia" icon={Gavel} />
        <div className="flex items-center gap-3 mt-8 p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <AlertTriangle className="w-5 h-5 shrink-0" />
          <span className="text-sm">
            No se pudo cargar la regla. Verifica que el ID sea correcto.
          </span>
        </div>
      </div>
    )
  }

  const typeDesc = ruleTypeDescriptions[rule.rule_type]
  const campo = getCampoLabel(rule)
  const ubicacion = getFormLabel(rule.metadata)

  const versionColumns: ColumnDef<RuleVersion>[] = [
    {
      header: 'Versión',
      accessorKey: 'version',
      cell: ({ row }) => <span className="font-mono text-slate-900">{row.original.version}</span>,
    },
    {
      header: 'Cambios',
      accessorKey: 'changelog',
      cell: ({ row }) => <span className="text-slate-600">{row.original.changelog ?? '—'}</span>,
    },
    {
      header: 'Fecha',
      accessorKey: 'created_at',
      cell: ({ row }) => (
        <span className="text-slate-500 whitespace-nowrap">
          {new Date(row.original.created_at).toLocaleDateString('es-CL')}
        </span>
      ),
    },
  ]

  const bindingColumns: ColumnDef<RuleBinding>[] = [
    {
      header: 'Tipo',
      accessorKey: 'bindable_type',
      cell: ({ row }) => (
        <span className="text-slate-900">{getBindableTypeLabel(row.original.bindable_type)}</span>
      ),
    },
    {
      header: 'Serie',
      accessorKey: 'serie',
      cell: ({ row }) => <span className="text-slate-600">{row.original.serie ?? '—'}</span>,
    },
    {
      header: 'Año',
      accessorKey: 'anio',
      cell: ({ row }) => <span className="text-slate-600">{row.original.anio ?? '—'}</span>,
    },
    {
      header: 'Activo',
      accessorKey: 'active',
      cell: ({ row }) =>
        row.original.active ? (
          <span className="inline-flex items-center rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200 px-2 py-0.5 text-xs font-medium">
            Sí
          </span>
        ) : (
          <span className="inline-flex items-center rounded-full bg-slate-100 text-slate-500 border border-slate-200 px-2 py-0.5 text-xs font-medium">
            No
          </span>
        ),
    },
  ]

  const executionLogColumns: ColumnDef<RuleExecutionLog>[] = [
    {
      header: 'Archivo',
      accessorKey: 'rem_upload_id',
      cell: ({ row }) => (
        <span className="text-slate-900 font-mono">#{row.original.rem_upload_id}</span>
      ),
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => <ExecutionStatusBadge status={row.original.status} />,
    },
    {
      header: 'Filas',
      accessorKey: 'total_rows',
      cell: ({ row }) => <span className="text-slate-600">{row.original.total_rows}</span>,
    },
    {
      header: 'Correctas',
      accessorKey: 'passed_rows',
      cell: ({ row }) => <span className="text-emerald-600">{row.original.passed_rows}</span>,
    },
    {
      header: 'Con observaciones',
      accessorKey: 'failed_rows',
      cell: ({ row }) => <span className="text-rose-600">{row.original.failed_rows}</span>,
    },
    {
      header: 'Duración',
      accessorKey: 'execution_ms',
      cell: ({ row }) => <span className="text-slate-600">{row.original.execution_ms} ms</span>,
    },
    {
      header: 'Origen',
      accessorKey: 'triggered_by',
      cell: ({ row }) => (
        <span className="text-slate-600">{getTriggerLabel(row.original.triggered_by)}</span>
      ),
    },
    {
      header: 'Fecha',
      accessorKey: 'created_at',
      cell: ({ row }) => (
        <span className="text-slate-500 whitespace-nowrap">
          {new Date(row.original.created_at).toLocaleString('es-CL')}
        </span>
      ),
    },
  ]

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex items-center gap-4">
        <button
          onClick={() => navigate('/rule-engine/rules')}
          className="text-slate-500 hover:text-slate-700 transition-colors"
          title="Volver al catálogo"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <div>
          <PageHeader title={rule.name || rule.rule_key} icon={Gavel} />
          <p className="text-xs text-slate-400 font-mono mt-0.5">{rule.rule_key}</p>
        </div>
        <button
          onClick={() => navigate(`/rule-engine/logs?rule_id=${rule.id}`)}
          className="ml-auto inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-slate-600 bg-slate-50 border border-slate-200 rounded-lg hover:bg-slate-100 transition-colors"
        >
          <List className="h-4 w-4" />
          Historial
        </button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-4 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
            <Info className="h-3.5 w-3.5" />
            ¿Qué valida?
          </h3>

          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <div className="col-span-2">
              <dt className="text-slate-500 flex items-center gap-1 mb-1">
                Tipo de validación
                <HelpTooltip text={getHelpText('rule-type') ?? ''} />
              </dt>
              <dd className="inline-flex items-center gap-2 rounded-lg bg-indigo-50 border border-indigo-200 px-3 py-1.5 text-sm font-medium text-indigo-700">
                {getRuleTypeLabel(rule.rule_type)}
              </dd>
            </div>
            <div className="col-span-2">
              <dt className="text-slate-500 mb-1">Descripción</dt>
              <dd className="text-slate-900">{typeDesc?.what ?? rule.description ?? '—'}</dd>
            </div>
            <div className="col-span-2">
              <dt className="text-slate-500 mb-1">Ubicación</dt>
              <dd className="text-slate-900 font-medium">{ubicacion}</dd>
            </div>
            <div className="col-span-2">
              <dt className="text-slate-500 flex items-center gap-1 mb-1">
                Variable
                <HelpTooltip text={getHelpText('campo') ?? ''} />
              </dt>
              <dd className="text-slate-900 font-medium">{campo}</dd>
            </div>
          </dl>

          <hr className="border-slate-100" />

          <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
            ¿Qué ocurre si falla?
          </h3>
          <div className="flex items-start gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <AlertTriangle className="h-4 w-4 text-amber-500 mt-0.5 shrink-0" />
            <div>
              <p className="text-sm font-medium text-amber-800">
                Nivel de importancia: <SeverityBadge severity={rule.severity} />
              </p>
              <p className="text-xs text-amber-700 mt-1">
                {rule.severity === 'error'
                  ? 'Requiere corrección obligatoria antes de finalizar el proceso.'
                  : 'Se recomienda revisar, pero no bloquea el proceso.'}
              </p>
            </div>
          </div>

          <hr className="border-slate-100" />

          <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
            ¿Cómo corregirlo?
          </h3>
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800">
            <p className="font-medium mb-1">Acción recomendada</p>
            <p>
              {typeDesc?.action ?? 'Revise los valores digitados en el formulario correspondiente.'}
            </p>
          </div>
        </div>

        <div className="space-y-6">
          <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-4 shadow-sm">
            <div className="flex items-center gap-1.5">
              <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                Estado
              </h3>
              <HelpTooltip text={getHelpText('status') ?? ''} />
            </div>
            <div className="flex flex-wrap gap-4">
              <div>
                <p className="text-xs text-slate-500 mb-0.5">Estado de la regla</p>
                <RuleStatusBadge status={rule.status} />
              </div>
              <div>
                <p className="text-xs text-slate-500 mb-0.5">Versión</p>
                <span className="text-sm font-mono text-slate-900">{rule.version}</span>
              </div>
              <div>
                <p className="text-xs text-slate-500 mb-0.5">Fuente</p>
                <span className="text-sm text-slate-900">{getSourceLabel(rule.source)}</span>
              </div>
            </div>
          </div>

          <TechnicalInfo title="Información técnica">
            <div className="p-4 space-y-4">
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <dt className="text-slate-500">Código de Regla</dt>
                <dd className="text-slate-900 font-mono">{rule.rule_key}</dd>
                <dt className="text-slate-500">ID interno</dt>
                <dd className="text-slate-900 font-mono">#{rule.id}</dd>
                <dt className="text-slate-500">Categoría</dt>
                <dd className="text-slate-900">{rule.category ?? '—'}</dd>
                <dt className="text-slate-500">Scope</dt>
                <dd className="text-slate-900">{rule.scope ?? '—'}</dd>
                <dt className="text-slate-500">Creado</dt>
                <dd className="text-slate-900">
                  {rule.created_at ? new Date(rule.created_at).toLocaleString('es-CL') : '—'}
                </dd>
                <dt className="text-slate-500">Actualizado</dt>
                <dd className="text-slate-900">
                  {new Date(rule.updated_at).toLocaleString('es-CL')}
                </dd>
              </dl>
              {rule.config && (
                <div>
                  <p className="text-xs font-semibold text-slate-500 mb-2">Configuración Técnica</p>
                  <pre className="bg-slate-50 rounded-lg p-3 text-xs font-mono text-slate-700 overflow-auto max-h-48 whitespace-pre-wrap">
                    {formatJson(rule.config)}
                  </pre>
                </div>
              )}
              {rule.metadata && (
                <div>
                  <p className="text-xs font-semibold text-slate-500 mb-2">Información Técnica</p>
                  <pre className="bg-slate-50 rounded-lg p-3 text-xs font-mono text-slate-700 overflow-auto max-h-48 whitespace-pre-wrap">
                    {formatJson(rule.metadata)}
                  </pre>
                </div>
              )}
            </div>
          </TechnicalInfo>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-4 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
            Versiones ({rule.versions?.length ?? 0})
          </h3>
          {rule.versions && rule.versions.length > 0 ? (
            <DataTable
              columns={versionColumns}
              data={rule.versions}
              getRowClassName={noHoverRowClassName}
            />
          ) : (
            <p className="text-sm text-slate-500">Sin versiones registradas.</p>
          )}
        </div>

        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-4 shadow-sm">
          <div className="flex items-center gap-1.5">
            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
              Relaciones de Reglas ({rule.bindings?.length ?? 0})
            </h3>
            <HelpTooltip text={getHelpText('bindings') ?? ''} />
          </div>
          {rule.bindings && rule.bindings.length > 0 ? (
            <DataTable
              columns={bindingColumns}
              data={rule.bindings}
              getRowClassName={noHoverRowClassName}
            />
          ) : (
            <p className="text-sm text-slate-500">Sin relaciones de reglas registradas.</p>
          )}
        </div>
      </div>

      <TechnicalInfo title="Historial de ejecución">
        {rule.execution_logs && rule.execution_logs.length > 0 ? (
          <div className="p-4">
            <DataTable
              columns={executionLogColumns}
              data={rule.execution_logs}
              getRowClassName={noHoverRowClassName}
            />
          </div>
        ) : (
          <p className="p-4 text-sm text-slate-500">Sin historial de ejecución registrado.</p>
        )}
      </TechnicalInfo>
    </div>
  )
}
