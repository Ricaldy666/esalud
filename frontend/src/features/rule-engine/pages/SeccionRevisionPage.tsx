import { useState, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, Edit3, AlertTriangle, Clock } from 'lucide-react'
import { useFunctionalCriteria } from '../hooks/useFunctionalCriteria'
import type { FuncionalCriterion } from '../services/criteriaService'
import { AsistenteRevision } from '../components/AsistenteRevision'

type FilterKey = 'todas' | 'pendientes' | 'revisadas' | 'aprobadas' | 'heredadas' | 'excepciones'

const FILTERS: { key: FilterKey; label: string }[] = [
  { key: 'todas', label: 'Todas' },
  { key: 'pendientes', label: 'Pendientes' },
  { key: 'revisadas', label: 'Revisadas' },
  { key: 'aprobadas', label: 'Aprobadas' },
  { key: 'heredadas', label: 'Heredadas' },
  { key: 'excepciones', label: 'Excepciones' },
]

function getPatternLabel(criterion: FuncionalCriterion): string {
  return (
    (criterion as FuncionalCriterion & { pattern_label?: string }).pattern_label ??
    criterion.pattern_id ??
    '—'
  )
}

const WORKFLOW_MAP: Record<string, { label: string; color: string }> = {
  creado: { label: 'Pendiente', color: 'bg-slate-100 text-slate-600' },
  en_revision: { label: 'En revisión', color: 'bg-amber-100 text-amber-700' },
  revisado: { label: 'Revisado', color: 'bg-blue-100 text-blue-700' },
  validado: { label: 'Validado', color: 'bg-indigo-100 text-indigo-700' },
  aprobado: { label: 'Aprobado', color: 'bg-green-100 text-green-700' },
  publicado: { label: 'Publicado', color: 'bg-emerald-100 text-emerald-700' },
}

const SYNC_STATUS_MAP: Record<string, { label: string; color: string }> = {
  pendiente: { label: 'Pendiente', color: 'bg-slate-100 text-slate-500' },
  sincronizado: { label: 'Sincronizado', color: 'bg-emerald-100 text-emerald-700' },
  error: { label: 'Error', color: 'bg-red-100 text-red-700' },
}

const SCOPE_LABELS: Record<string, string> = {
  global: 'Global',
  by_type: 'Por tipo',
  by_center: 'Por establecimiento',
  by_node: 'Por nodo',
}

const SCOPE_COLORS: Record<string, string> = {
  global: 'bg-purple-100 text-purple-700',
  by_type: 'bg-blue-100 text-blue-700',
  by_center: 'bg-amber-100 text-amber-700',
  by_node: 'bg-indigo-100 text-indigo-700',
}

export default function SeccionRevisionPage() {
  const { sheet, section } = useParams<{ sheet: string; section: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filter, setFilter] = useState<FilterKey>('todas')
  const [revisionRow, setRevisionRow] = useState<FuncionalCriterion | null>(null)

  const { data: criteriaData, isLoading } = useFunctionalCriteria(sheet, section)
  const allRows = useMemo(() => criteriaData?.data ?? [], [criteriaData])

  const statusCounts = useMemo(() => {
    const counts: Record<string, number> = {
      todas: 0,
      pendientes: 0,
      revisadas: 0,
      aprobadas: 0,
      heredadas: 0,
      excepciones: 0,
    }
    for (const c of allRows) {
      counts.todas++
      const code = c.workflow_instance?.current_state?.code
      if (code === 'aprobado' || code === 'publicado') counts.aprobadas++
      else if (code === 'revisado') counts.revisadas++
      else counts.pendientes++
      if (c.parent_criterion_id) counts.heredadas++
      if (c.scope !== 'global') counts.excepciones++
    }
    return counts
  }, [allRows])

  const filteredRows = useMemo(() => {
    switch (filter) {
      case 'pendientes':
        return allRows.filter((c) => {
          const code = c.workflow_instance?.current_state?.code
          return !code || code === 'creado' || code === 'en_revision'
        })
      case 'revisadas':
        return allRows.filter((c) => c.workflow_instance?.current_state?.code === 'revisado')
      case 'aprobadas':
        return allRows.filter((c) => {
          const code = c.workflow_instance?.current_state?.code
          return code === 'aprobado' || code === 'publicado'
        })
      case 'heredadas':
        return allRows.filter((c) => c.parent_criterion_id != null)
      case 'excepciones':
        return allRows.filter((c) => c.scope !== 'global' && c.parent_criterion_id != null)
      default:
        return allRows
    }
  }, [allRows, filter])

  const getWorkflowInfo = (c: FuncionalCriterion) => {
    const code = c.workflow_instance?.current_state?.code
    return code
      ? (WORKFLOW_MAP[code] ?? { label: code, color: 'bg-slate-100 text-slate-500' })
      : { label: 'Pendiente', color: 'bg-slate-100 text-slate-400' }
  }

  const findParentRow = (c: FuncionalCriterion) => {
    if (!c.parent_criterion_id) return null
    return allRows.find((p) => p.id === c.parent_criterion_id)
  }

  const handleStartRevision = (criterion: FuncionalCriterion) => {
    setRevisionRow(criterion)
  }

  const handleCloseRevision = () => {
    queryClient.invalidateQueries({ queryKey: ['functional-criteria'] })
    setRevisionRow(null)
  }

  if (revisionRow && sheet && section) {
    const pendingRows = allRows.filter((c) => {
      const code = c.workflow_instance?.current_state?.code
      return !code || code === 'creado' || code === 'en_revision'
    })
    return (
      <AsistenteRevision
        row={revisionRow}
        sheet={sheet}
        section={section}
        allPendingRows={pendingRows}
        onClose={handleCloseRevision}
      />
    )
  }

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate('/criterios-funcionales')}
            className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
          >
            <ArrowLeft className="w-4 h-4" />
            Criterios funcionales
          </button>
          <span className="text-slate-300 text-sm">/</span>
          <div>
            <h1 className="text-xl font-bold text-slate-900">
              <span className="font-mono text-indigo-600">{sheet}</span>
              <span className="text-slate-400 mx-1">/</span>
              <span className="font-mono">Sección {section}</span>
            </h1>
          </div>
        </div>
      </div>

      {/* Filter tabs */}
      <div className="flex flex-wrap items-center gap-1.5 border-b border-slate-200 pb-px">
        {FILTERS.map((f) => (
          <button
            key={f.key}
            onClick={() => setFilter(f.key)}
            className={`px-3 py-2 text-xs font-medium border-b-2 transition-colors ${
              filter === f.key
                ? 'border-indigo-600 text-indigo-700'
                : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
            }`}
          >
            {f.label}
            <span className="ml-1.5 text-xs opacity-70">({statusCounts[f.key]})</span>
          </button>
        ))}
      </div>

      {/* Loading */}
      {isLoading && (
        <div className="flex items-center justify-center py-12 text-sm text-slate-400">
          Cargando criterios funcionales...
        </div>
      )}

      {/* Table */}
      {!isLoading && (
        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
          <table className="w-full">
            <thead>
              <tr className="bg-slate-50 text-[11px] text-slate-500 uppercase tracking-wider">
                <th className="px-3 py-3 text-left font-medium">#</th>
                <th className="px-3 py-3 text-left font-medium">Concepto</th>
                <th className="px-3 py-3 text-left font-medium">Profesional</th>
                <th className="px-3 py-3 text-left font-medium">Patrón técnico</th>
                <th className="px-3 py-3 text-left font-medium">Criterio funcional</th>
                <th className="px-3 py-3 text-left font-medium">Workflow</th>
                <th className="px-3 py-3 text-left font-medium">Alcance</th>
                <th className="px-3 py-3 text-left font-medium">Motor</th>
                <th className="px-3 py-3 text-left font-medium">Origen / Herencia</th>
                <th className="px-3 py-3 text-right font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-sm">
              {filteredRows.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center text-slate-400 text-xs">
                    No hay filas para este filtro.
                  </td>
                </tr>
              ) : (
                filteredRows.map((c) => {
                  const wf = getWorkflowInfo(c)
                  const parent = findParentRow(c)
                  return (
                    <tr key={c.id} className="hover:bg-slate-50 transition-colors">
                      <td className="px-3 py-3 text-slate-500 font-mono text-xs">{c.row_number}</td>
                      <td className="px-3 py-3 font-medium text-slate-900 max-w-xs truncate">
                        {c.concepto}
                      </td>
                      <td className="px-3 py-3 text-slate-600">{c.profesional}</td>
                      <td className="px-3 py-3">
                        <span className="text-xs text-slate-700 whitespace-nowrap">
                          {getPatternLabel(c)}
                        </span>
                      </td>
                      <td className="px-3 py-3">
                        <span className="text-xs text-slate-700">{c.empty_behavior ?? '—'}</span>
                      </td>
                      <td className="px-3 py-3">
                        <span
                          className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${wf.color}`}
                        >
                          {wf.label}
                        </span>
                      </td>
                      <td className="px-3 py-3">
                        <span
                          className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${SCOPE_COLORS[c.scope] ?? 'bg-slate-100 text-slate-500'}`}
                        >
                          {SCOPE_LABELS[c.scope] ?? c.scope}
                        </span>
                      </td>
                      <td className="px-3 py-3">
                        <span
                          className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${(SYNC_STATUS_MAP[c.sync_status ?? ''] ?? SYNC_STATUS_MAP.pendiente).color}`}
                        >
                          {c.sync_status === 'sincronizado' ? (
                            <CheckCircle2 size={11} />
                          ) : c.sync_status === 'error' ? (
                            <AlertTriangle size={11} />
                          ) : (
                            <Clock size={11} />
                          )}
                          {
                            (SYNC_STATUS_MAP[c.sync_status ?? ''] ?? SYNC_STATUS_MAP.pendiente)
                              .label
                          }
                        </span>
                      </td>
                      <td className="px-3 py-3 text-xs text-slate-500">
                        {parent ? (
                          <span className="inline-flex items-center gap-1 text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded font-medium">
                            Hereda fila {parent.row_number}
                          </span>
                        ) : (
                          <span className="text-slate-300">—</span>
                        )}
                      </td>
                      <td className="px-3 py-3 text-right">
                        <button
                          onClick={() => handleStartRevision(c)}
                          className="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-xs font-medium hover:bg-indigo-700 transition-colors"
                        >
                          <Edit3 size={13} />
                          {c.workflow_instance?.current_state?.code === 'aprobado' ||
                          c.workflow_instance?.current_state?.code === 'publicado'
                            ? 'Editar'
                            : 'Revisar'}
                        </button>
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* Summary */}
      {!isLoading && allRows.length > 0 && (
        <div className="flex items-center gap-4 text-xs text-slate-400">
          <span>{allRows.length} filas</span>
          <span>{statusCounts.aprobadas} aprobadas</span>
          <span>{statusCounts.revisadas} revisadas</span>
          <span>{statusCounts.pendientes} pendientes</span>
          <span>{statusCounts.heredadas} heredadas</span>
        </div>
      )}
    </div>
  )
}
