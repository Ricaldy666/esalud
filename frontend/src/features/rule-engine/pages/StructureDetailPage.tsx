import { useParams, useNavigate } from 'react-router-dom'
import { useStructure } from '../hooks/useStructure'
import { StructureTreeNode } from '../components/StructureTreeNode'
import { SeverityBadge } from '../components/SeverityBadge'
import { RuleStatusBadge } from '../components/RuleStatusBadge'
import { HelpTooltip } from '../components/HelpTooltip'
import { TechnicalInfo } from '../components/TechnicalInfo'
import { PageHeader } from '@/shared/components/PageHeader'
import { Loader2, ArrowLeft, AlertTriangle, FileSpreadsheet, List, Database } from 'lucide-react'
import { getHelpText, getStructureStatusLabel, getRuleTypeLabel } from '../utils/labels'

function formatJson(data: unknown): string {
  if (!data) return '—'
  try {
    return JSON.stringify(data, null, 2)
  } catch {
    return String(data)
  }
}

export default function StructureDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const structureId = id ? Number(id) : undefined
  const { data: structure, isLoading, isError } = useStructure(structureId)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
      </div>
    )
  }

  if (isError || !structure) {
    return (
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader title="Estructura REM" icon={Database} />
        <div className="flex items-center gap-3 mt-8 p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <AlertTriangle className="w-5 h-5 shrink-0" />
          <span className="text-sm">
            No se pudo cargar la estructura. Verifica que el ID sea correcto.
          </span>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex items-center gap-4">
        <button
          onClick={() => navigate('/rule-engine/structures')}
          className="text-slate-500 hover:text-slate-700 transition-colors"
          title="Volver a estructuras REM"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <PageHeader
          title={`Serie ${structure.serie} ${structure.anio}`}
          description={`Versión ${structure.version_number} · ${structure.source_filename ?? 'Estructura REM'}`}
          icon={Database}
          actions={<HelpTooltip text={getHelpText('structure-detail') ?? ''} />}
        />
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <FileSpreadsheet className="h-5 w-5 text-slate-400 mx-auto mb-1.5" />
          <p className="text-2xl font-bold text-slate-900 tabular-nums">
            {structure.stats?.total_forms ?? 0}
          </p>
          <p className="text-xs text-slate-500 mt-0.5">Formularios</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <List className="h-5 w-5 text-slate-400 mx-auto mb-1.5" />
          <p className="text-2xl font-bold text-slate-900 tabular-nums">
            {structure.stats?.total_sections ?? 0}
          </p>
          <p className="text-xs text-slate-500 mt-0.5">Secciones</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <FileSpreadsheet className="h-5 w-5 text-slate-400 mx-auto mb-1.5" />
          <p className="text-2xl font-bold text-slate-900 tabular-nums">
            {structure.stats?.total_fields ?? 0}
          </p>
          <p className="text-xs text-slate-500 mt-0.5">Campos</p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <AlertTriangle className="h-5 w-5 text-blue-400 mx-auto mb-1.5" />
          <p className="text-2xl font-bold text-blue-600 tabular-nums">
            {structure.stats?.total_rules ?? 0}
          </p>
          <p className="text-xs text-slate-500 mt-0.5">Reglas de consistencia</p>
        </div>
      </div>

      {/* Info + Secondary panels */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-4 shadow-sm">
          <div className="flex items-center gap-1.5">
            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
              Información General
            </h3>
            <HelpTooltip text={getHelpText('structures') ?? ''} />
          </div>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <dt className="text-slate-500">Serie</dt>
            <dd className="text-slate-900 font-bold">{structure.serie}</dd>
            <dt className="text-slate-500">Año</dt>
            <dd className="text-slate-900">{structure.anio}</dd>
            <dt className="text-slate-500">Versión</dt>
            <dd className="text-slate-900 font-mono">v{structure.version_number}</dd>
            <dt className="text-slate-500">Estado</dt>
            <dd>
              <span
                className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${
                  structure.status === 'approved'
                    ? 'bg-emerald-100 text-emerald-700 border-emerald-200'
                    : structure.status === 'superseded'
                      ? 'bg-amber-100 text-amber-700 border-amber-200'
                      : 'bg-slate-100 text-slate-500 border-slate-200'
                }`}
              >
                {getStructureStatusLabel(structure.status)}
              </span>
            </dd>
            <dt className="text-slate-500">Archivo</dt>
            <dd className="text-slate-900 text-xs break-all">{structure.source_filename ?? '—'}</dd>
            <dt className="text-slate-500">Reglas</dt>
            <dd className="text-slate-900">{structure.rules_count ?? 0}</dd>
            <dt className="text-slate-500">Fecha de actualización</dt>
            <dd className="text-slate-900">
              {structure.updated_at ? new Date(structure.updated_at).toLocaleString('es-CL') : '—'}
            </dd>
            {structure.notes && (
              <>
                <dt className="text-slate-500">Notas</dt>
                <dd className="text-slate-900">{structure.notes}</dd>
              </>
            )}
          </dl>

          <TechnicalInfo title="Información técnica">
            <div className="p-4">
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <dt className="text-slate-500">ID interno</dt>
                <dd className="text-slate-900 font-mono">#{structure.id}</dd>
                <dt className="text-slate-500">Hash</dt>
                <dd className="text-slate-900 font-mono text-xs break-all">
                  {structure.hash_estructura}
                </dd>
                <dt className="text-slate-500">Creado</dt>
                <dd className="text-slate-900">
                  {new Date(structure.created_at).toLocaleString('es-CL')}
                </dd>
              </dl>
            </div>
          </TechnicalInfo>
        </div>

        <div className="space-y-4">
          {structure.template && (
            <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
              <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                Plantilla
              </h3>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt className="text-slate-500">Tipo</dt>
                <dd className="text-slate-900">{structure.template.rem_type}</dd>
                <dt className="text-slate-500">Año</dt>
                <dd className="text-slate-900">{structure.template.year}</dd>
                <dt className="text-slate-500">Versión</dt>
                <dd className="text-slate-900">{structure.template.version}</dd>
                <dt className="text-slate-500">Activo</dt>
                <dd className="text-slate-900">{structure.template.is_active ? 'Sí' : 'No'}</dd>
              </dl>
            </div>
          )}

          {structure.upload && (
            <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
              <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                Archivo de origen
              </h3>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt className="text-slate-500">ID</dt>
                <dd className="text-slate-900">#{structure.upload.id}</dd>
                <dt className="text-slate-500">Archivo</dt>
                <dd className="text-slate-900">{structure.upload.filename}</dd>
                <dt className="text-slate-500">Período</dt>
                <dd className="text-slate-900">{structure.upload.period}</dd>
              </dl>
            </div>
          )}

          {structure.version_history && structure.version_history.length > 0 && (
            <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
              <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
                Historial de Versiones ({structure.version_history.length})
              </h3>
              <div className="space-y-2">
                {structure.version_history.map((v) => (
                  <div key={v.id} className="flex items-center justify-between text-sm">
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-slate-900">v{v.version_number}</span>
                      <span
                        className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
                          v.status === 'approved'
                            ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                            : v.status === 'superseded'
                              ? 'bg-amber-50 text-amber-600 border-amber-200'
                              : 'bg-slate-50 text-slate-500 border-slate-200'
                        }`}
                      >
                        {getStructureStatusLabel(v.status)}
                      </span>
                      {v.id === structure.id && (
                        <span className="text-[10px] text-blue-600 font-medium">actual</span>
                      )}
                    </div>
                    <span className="text-slate-500 text-xs">
                      {new Date(v.created_at).toLocaleDateString('es-CL')}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Reglas asociadas */}
      {structure.rules && structure.rules.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
            Reglas Asociadas ({structure.rules.length})
          </h3>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs text-slate-500 uppercase">
                  <th className="pb-2 pr-4 font-medium">Código de Regla</th>
                  <th className="pb-2 pr-4 font-medium">Nombre</th>
                  <th className="pb-2 pr-4 font-medium">Tipo</th>
                  <th className="pb-2 pr-4 font-medium">Nivel de importancia</th>
                  <th className="pb-2 font-medium">Estado</th>
                </tr>
              </thead>
              <tbody>
                {structure.rules.map((r) => (
                  <tr key={r.id} className="border-b border-slate-100 hover:bg-slate-50">
                    <td className="py-2 pr-4">
                      <button
                        onClick={() => navigate(`/rule-engine/rules/${r.id}`)}
                        className="font-mono text-blue-600 hover:text-blue-800 transition-colors"
                      >
                        {r.rule_key}
                      </button>
                    </td>
                    <td className="py-2 pr-4 text-slate-700">{r.name}</td>
                    <td className="py-2 pr-4 text-slate-600">{getRuleTypeLabel(r.rule_type)}</td>
                    <td className="py-2 pr-4">
                      <SeverityBadge severity={r.severity} />
                    </td>
                    <td className="py-2">
                      <RuleStatusBadge status={r.status as 'active' | 'inactive' | 'deprecated'} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Árbol expandible */}
      {structure.forms_detail && structure.forms_detail.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-6 space-y-3 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
            Árbol de Estructura ({structure.forms_detail.length} formularios)
          </h3>
          <div className="space-y-2">
            {structure.forms_detail.map((form) => (
              <StructureTreeNode key={form.sheetName} form={form} />
            ))}
          </div>
        </div>
      )}

      {/* JSON Data */}
      <TechnicalInfo title="Datos técnicos">
        <div className="p-4">
          <pre className="bg-slate-50 rounded-lg p-4 text-xs font-mono text-slate-700 overflow-auto max-h-96 whitespace-pre-wrap">
            {formatJson({ estructura: structure.forms_detail, metadata: structure.metadata })}
          </pre>
        </div>
      </TechnicalInfo>
    </div>
  )
}
