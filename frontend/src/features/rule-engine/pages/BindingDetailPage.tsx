import { useParams, useNavigate } from 'react-router-dom'
import { useBinding } from '../hooks/useBinding'
import { SeverityBadge } from '../components/SeverityBadge'
import { RuleStatusBadge } from '../components/RuleStatusBadge'
import { HelpTooltip } from '../components/HelpTooltip'
import { TechnicalInfo } from '../components/TechnicalInfo'
import { PageHeader } from '@/shared/components/PageHeader'
import { Loader2, ArrowLeft, AlertTriangle, Layers } from 'lucide-react'
import {
  getRuleTypeLabel,
  getBindableTypeLabel,
  getHelpText,
  getStructureStatusLabel,
} from '../utils/labels'

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

export default function BindingDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const bindingId = id ? Number(id) : undefined
  const { data: binding, isLoading, isError } = useBinding(bindingId)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
      </div>
    )
  }

  if (isError || !binding) {
    return (
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader title="Relación de Regla" icon={Layers} />
        <div className="flex items-center gap-3 mt-8 p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <AlertTriangle className="w-5 h-5 shrink-0" />
          <span className="text-sm">
            No se pudo cargar la relación. Verifica que el ID sea correcto.
          </span>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex items-center gap-4">
        <button
          onClick={() => navigate('/rule-engine/bindings')}
          className="text-slate-500 hover:text-slate-700 transition-colors"
          title="Volver a relaciones de reglas"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <PageHeader
          title="Relación de la Regla"
          description={binding.rule?.name ?? binding.rule?.rule_key ?? 'Sin regla asociada'}
          icon={Layers}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-4 shadow-sm">
          <div className="flex items-center gap-1.5">
            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
              Información General
            </h3>
            <HelpTooltip text={getHelpText('binding-detail') ?? ''} />
          </div>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <dt className="text-slate-500">Tipo</dt>
            <dd className="text-slate-900 font-medium">
              {getBindableTypeLabel(binding.bindable_type)}
            </dd>
            <dt className="text-slate-500">Serie</dt>
            <dd className="text-slate-900">{binding.serie ?? '—'}</dd>
            <dt className="text-slate-500">Año</dt>
            <dd className="text-slate-900">{binding.anio ?? '—'}</dd>
            <dt className="text-slate-500">Estado</dt>
            <dd>
              <span
                className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${
                  binding.active
                    ? 'bg-emerald-100 text-emerald-700 border-emerald-200'
                    : 'bg-slate-100 text-slate-500 border-slate-200'
                }`}
              >
                {binding.active ? 'Activo' : 'Inactivo'}
              </span>
            </dd>
          </dl>
        </div>

        <div className="space-y-4">
          {binding.rule && (
            <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
              <div className="flex items-center gap-1.5">
                <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                  Regla de Consistencia
                </h3>
                <HelpTooltip text={getHelpText('rule-detail') ?? ''} />
              </div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <dt className="text-slate-500">Nombre</dt>
                <dd>
                  <button
                    onClick={() => navigate(`/rule-engine/rules/${binding.rule!.id}`)}
                    className="text-blue-600 hover:text-blue-800 font-medium transition-colors"
                  >
                    {binding.rule!.name}
                  </button>
                </dd>
                <dt className="text-slate-500">Variable</dt>
                <dd className="text-slate-900 font-medium">{getCampoLabel(binding.rule!)}</dd>
                <dt className="text-slate-500">Validación que aplica</dt>
                <dd className="text-slate-900">{getRuleTypeLabel(binding.rule!.rule_type)}</dd>
                <dt className="text-slate-500">Nivel de importancia</dt>
                <dd>
                  <SeverityBadge severity={binding.rule!.severity} />
                </dd>
                <dt className="text-slate-500">Estado de la regla</dt>
                <dd>
                  <RuleStatusBadge
                    status={binding.rule!.status as 'active' | 'inactive' | 'deprecated'}
                  />
                </dd>
              </dl>
            </div>
          )}

          {binding.structure && (
            <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
              <div className="flex items-center gap-1.5">
                <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                  Estructura REM
                </h3>
                <HelpTooltip text={getHelpText('structure-detail') ?? ''} />
              </div>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <dt className="text-slate-500">Serie</dt>
                <dd>
                  <button
                    onClick={() => navigate(`/rule-engine/structures/${binding.structure!.id}`)}
                    className="text-blue-600 hover:text-blue-800 font-mono transition-colors"
                  >
                    {binding.structure!.serie}
                  </button>
                </dd>
                <dt className="text-slate-500">Año</dt>
                <dd className="text-slate-900">{binding.structure!.anio}</dd>
                <dt className="text-slate-500">Versión</dt>
                <dd className="text-slate-900">v{binding.structure!.version_number}</dd>
                <dt className="text-slate-500">Estado</dt>
                <dd className="text-slate-900">
                  {getStructureStatusLabel(binding.structure!.status)}
                </dd>
                {binding.structure!.source_filename && (
                  <>
                    <dt className="text-slate-500">Archivo</dt>
                    <dd className="text-slate-900">{binding.structure!.source_filename}</dd>
                  </>
                )}
              </dl>
            </div>
          )}
        </div>
      </div>

      <TechnicalInfo title="Información técnica">
        <div className="p-4 space-y-4">
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <dt className="text-slate-500">ID de la Relación</dt>
            <dd className="text-slate-900 font-mono">#{binding.id}</dd>
            <dt className="text-slate-500">Tipo interno</dt>
            <dd className="text-slate-900 capitalize">{binding.bindable_type}</dd>
            <dt className="text-slate-500">ID del elemento asociado</dt>
            <dd className="text-slate-900 font-mono">{binding.bindable_id ?? '—'}</dd>
            <dt className="text-slate-500">Creado</dt>
            <dd className="text-slate-900">
              {new Date(binding.created_at).toLocaleString('es-CL')}
            </dd>
            {binding.rule && (
              <>
                <dt className="text-slate-500">Código de Regla</dt>
                <dd className="text-slate-900 font-mono">{binding.rule.rule_key}</dd>
              </>
            )}
          </dl>

          <div>
            <p className="text-xs font-semibold text-slate-500 mb-2">Condiciones</p>
            <pre className="bg-slate-50 rounded-lg p-3 text-xs font-mono text-slate-700 overflow-auto max-h-48 whitespace-pre-wrap">
              {formatJson(binding.conditions)}
            </pre>
          </div>
        </div>
      </TechnicalInfo>
    </div>
  )
}
