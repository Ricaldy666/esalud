import { useState, useMemo, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  ArrowRight,
  Save,
  CheckCircle2,
  HelpCircle,
  ChevronDown,
  ChevronRight,
  ThumbsUp,
  Globe,
  Clock,
  AlertTriangle,
  GitCommit,
  User,
  MessageSquare,
  RefreshCw,
} from 'lucide-react'
import { criteriaService } from '../services/criteriaService'
import type { FuncionalCriterion } from '../services/criteriaService'
import { EMPTY_BEHAVIOR_LABELS, EMPTY_BEHAVIOR_DESCRIPTIONS } from '../types/functional-rule'

const BEHAVIOR_MAP: Record<string, string> = {
  debe_registrar_cero: 'incluir',
  puede_quedar_vacio: 'excluir',
  no_aplica: 'no_aplica',
  pendiente_definicion: 'sin_accion',
}

const BEHAVIOR_REVERSE: Record<string, string> = {
  incluir: 'debe_registrar_cero',
  excluir: 'puede_quedar_vacio',
  no_aplica: 'no_aplica',
  informativo: 'debe_tener_al_menos_un_valor',
  sin_accion: 'pendiente_definicion',
}

const STEPS = [
  { key: 'creado', label: 'Pendiente', position: 0 },
  { key: 'en_revision', label: 'En revisión', position: 1 },
  { key: 'aprobado', label: 'Aprobado', position: 2 },
  { key: 'publicado', label: 'Publicado', position: 3 },
]

const STEP_FROM_CODE: Record<string, number> = {
  creado: 0,
  en_revision: 1,
  aprobado: 2,
  publicado: 3,
}

const WORKFLOW_STATE_LABELS: Record<string, string> = {
  creado: 'Pendiente',
  en_revision: 'En revisión',
  aprobado: 'Aprobado',
  publicado: 'Publicado',
}

const SYNC_STATUS_MAP: Record<string, { label: string; color: string }> = {
  pendiente: { label: 'Pendiente', color: 'text-gray-500' },
  sincronizado: { label: 'Sincronizado', color: 'text-emerald-600' },
  error: { label: 'Error', color: 'text-red-600' },
}

interface Transition {
  id: number
  label: string
  to_state_code: string
}

interface WorkflowData {
  instance: {
    current_state?: { id: number; code: string; name: string } | null
  } & Record<string, unknown>
  available_transitions: Transition[]
  history: Array<{
    id: number
    from_state: { code: string; name: string }
    to_state: { code: string; name: string }
    transitioned_by: { name: string } | null
    comments: string | null
    created_at: string
  }>
}

interface AsistenteRevisionProps {
  row: FuncionalCriterion
  sheet: string
  section: string
  allPendingRows: FuncionalCriterion[]
  onClose: () => void
}

function getPatternLabel(row: FuncionalCriterion): string {
  return (
    (row as FuncionalCriterion & { pattern_label?: string }).pattern_label ?? row.pattern_id ?? '—'
  )
}

export function AsistenteRevision({
  row,
  sheet,
  section,
  allPendingRows,
  onClose,
}: AsistenteRevisionProps) {
  const queryClient = useQueryClient()
  const [step, setStep] = useState<1 | 2>(1)
  const [emptyBehavior, setEmptyBehavior] = useState(
    BEHAVIOR_REVERSE[row.empty_behavior ?? ''] ?? ''
  )
  const [justification, setJustification] = useState(row.justification ?? '')
  const [showTecnico, setShowTecnico] = useState(false)
  const [saveError, setSaveError] = useState('')
  const [saved, setSaved] = useState(false)
  const [workflowData, setWorkflowData] = useState<WorkflowData | null>(null)
  const [transitionComments, setTransitionComments] = useState('')
  const [transitionError, setTransitionError] = useState('')
  const [resetting, setResetting] = useState(false)

  const initialCode = row.workflow_instance?.current_state?.code ?? 'creado'
  const [stateCode, setStateCode] = useState(initialCode)

  const current = allPendingRows.findIndex((r) => r.id === row.id)
  const nextRow = allPendingRows[current + 1] ?? null

  const currentStep = STEP_FROM_CODE[stateCode] ?? 0
  const isTerminal = stateCode === 'publicado'
  const wasApproved = initialCode === 'aprobado' || initialCode === 'publicado'

  useEffect(() => {
    criteriaService
      .getWorkflow(row.id)
      .then((data) => {
        setWorkflowData(data as unknown as WorkflowData)
      })
      .catch(() => {})
  }, [row.id])

  const findTransition = (toStateCode: string): Transition | null => {
    if (!workflowData) return null
    return workflowData.available_transitions.find((t) => t.to_state_code === toStateCode) ?? null
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      await criteriaService.update(row.id, {
        empty_behavior: BEHAVIOR_MAP[emptyBehavior] ?? null,
        justification,
        change_reason: wasApproved
          ? 'Re-calibración: cambios realizados desde edición'
          : 'Actualización desde Asistente de Revisión',
      })
    },
    onSuccess: async () => {
      setSaved(true)
      if (wasApproved) {
        setResetting(true)
        try {
          const wf = (await criteriaService.getWorkflow(row.id)) as unknown as WorkflowData
          const returnT = wf.available_transitions.find((t) => t.to_state_code === 'en_revision')
          if (returnT) {
            await criteriaService.transition(row.id, returnT.id, 'Volver a calibrar tras edición')
          }
        } catch {
          // No bloquea el guardado principal si la transición de reset falla.
        }
        setResetting(false)
      }
      const wf = (await criteriaService.getWorkflow(row.id)) as unknown as WorkflowData
      setWorkflowData(wf)
      const newCode = (wf?.instance?.current_state?.code as string) ?? 'en_revision'
      setStateCode(newCode)
      queryClient.invalidateQueries({ queryKey: ['functional-criteria', sheet, section] })
    },
    onError: (err) => {
      const responseMessage = (err as { response?: { data?: { message?: string } } })?.response
        ?.data?.message
      setSaveError(responseMessage ?? err.message ?? 'Error al guardar')
    },
  })

  const transitionMutation = useMutation({
    mutationFn: async (transitionId: number) => {
      await criteriaService.transition(row.id, transitionId, transitionComments)
    },
    onSuccess: async () => {
      setTransitionError('')
      setTransitionComments('')
      setSaved(true)
      const wf = (await criteriaService.getWorkflow(row.id)) as unknown as WorkflowData
      setWorkflowData(wf)
      const newCode = (wf?.instance?.current_state?.code as string) ?? 'en_revision'
      setStateCode(newCode)
      queryClient.invalidateQueries({ queryKey: ['functional-criteria', sheet, section] })
    },
    onError: (err) => {
      const responseMessage = (err as { response?: { data?: { message?: string } } })?.response
        ?.data?.message
      setTransitionError(responseMessage ?? err.message ?? 'Error al cambiar estado')
    },
  })

  const actionTransition = useMemo(() => {
    if (!workflowData) return null
    if (stateCode === 'creado' || stateCode === 'en_revision') {
      const t = findTransition('aprobado')
      if (t) return t
    }
    if (stateCode === 'aprobado') {
      const t = findTransition('publicado')
      if (t) return t
    }
    return null
  }, [workflowData])

  const isApprovable = stateCode === 'en_revision'
  const isPublishable = stateCode === 'aprobado'

  const handleSave = () => {
    setSaveError('')
    setSaved(false)
    saveMutation.mutate()
  }

  const handleTransition = () => {
    if (!actionTransition) return
    setTransitionError('')
    transitionMutation.mutate(actionTransition.id)
  }

  const handleNext = () => {
    if (nextRow) {
      setEmptyBehavior(BEHAVIOR_REVERSE[nextRow.empty_behavior ?? ''] ?? '')
      setJustification(nextRow.justification ?? '')
      setStep(1)
      setSaved(false)
      setSaveError('')
      setWorkflowData(null)
      setResetting(false)
    } else {
      onClose()
    }
  }

  const showEditing = !saved

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <button
          onClick={onClose}
          className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
        >
          <ArrowLeft className="w-4 h-4" />
          Volver a Revisión de criterios
        </button>
        <span className="text-xs text-gray-400">
          Fila {current + 1} de {allPendingRows.length}
        </span>
      </div>

      {/* Row identification card */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div className="flex items-start justify-between">
          <div>
            <h2 className="text-lg font-bold text-gray-900">
              {row.concepto ?? `Fila ${row.row_number}`}
            </h2>
            {row.profesional && <p className="text-sm text-gray-500">{row.profesional}</p>}
            <span className="text-xs text-gray-400 font-mono">Fila n° {row.row_number}</span>
          </div>
          {!wasApproved && !isTerminal && (
            <span className="px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700">
              {WORKFLOW_STATE_LABELS[stateCode] ?? stateCode}
            </span>
          )}
        </div>

        {wasApproved && (
          <div className="mt-3 bg-amber-50 rounded-lg border border-amber-200 p-3">
            <div className="flex items-start gap-2">
              <RefreshCw className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
              <div>
                <p className="text-xs font-medium text-amber-800">
                  Editando criterio {stateCode === 'publicado' ? 'publicado' : 'aprobado'}
                </p>
                <p className="text-xs text-amber-600 mt-0.5">
                  Los cambios requerirán una nueva aprobación.
                </p>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Progress bar */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div className="flex items-center justify-between">
          {STEPS.map((s, idx) => {
            const isActive = currentStep >= s.position
            const isCurrent = currentStep === s.position
            return (
              <div key={s.key} className="flex items-center flex-1">
                <div className="flex flex-col items-center">
                  <div
                    className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-colors ${
                      isCurrent
                        ? 'bg-indigo-600 text-white ring-4 ring-indigo-100'
                        : isActive
                          ? 'bg-emerald-500 text-white'
                          : 'bg-gray-100 text-gray-400'
                    }`}
                  >
                    {isActive && !isCurrent ? <CheckCircle2 className="w-4 h-4" /> : s.position + 1}
                  </div>
                  <span
                    className={`mt-1.5 text-[11px] font-medium whitespace-nowrap ${
                      isCurrent
                        ? 'text-indigo-700'
                        : isActive
                          ? 'text-emerald-600'
                          : 'text-gray-400'
                    }`}
                  >
                    {s.label}
                  </span>
                </div>
                {idx < STEPS.length - 1 && (
                  <div
                    className={`flex-1 h-0.5 mx-2 rounded ${
                      currentStep > s.position ? 'bg-emerald-400' : 'bg-gray-200'
                    }`}
                  />
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* Editing section — always visible when workflow allows editing */}
      {showEditing && !saved && (
        <>
          <div className="flex gap-2">
            {[1, 2].map((s) => (
              <div
                key={s}
                className={`flex-1 h-1.5 rounded-full transition-colors ${s <= step ? 'bg-indigo-500' : 'bg-gray-200'}`}
              />
            ))}
          </div>

          {step === 1 && (
            <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 space-y-4">
              <div className="flex items-center gap-2">
                <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">
                  1
                </span>
                <h3 className="text-sm font-semibold text-gray-900">Comportamiento funcional</h3>
              </div>

              <div className="space-y-2">
                {(
                  [
                    'debe_registrar_cero',
                    'puede_quedar_vacio',
                    'no_aplica',
                    'pendiente_definicion',
                  ] as const
                ).map((opt) => {
                  const info = EMPTY_BEHAVIOR_DESCRIPTIONS[opt]
                  return (
                    <label
                      key={opt}
                      className={`block p-3 rounded-lg border cursor-pointer transition-colors ${
                        emptyBehavior === opt
                          ? 'border-indigo-300 bg-indigo-50 ring-1 ring-indigo-200'
                          : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                      }`}
                    >
                      <div className="flex items-start gap-3">
                        <input
                          type="radio"
                          name="emptyBehavior"
                          value={opt}
                          checked={emptyBehavior === opt}
                          onChange={(e) => setEmptyBehavior(e.target.value)}
                          className="mt-0.5"
                        />
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-1.5">
                            <span className="text-sm font-medium text-gray-900">
                              {EMPTY_BEHAVIOR_LABELS[opt]}
                            </span>
                            <span className="group relative inline-block" title={info?.tooltip}>
                              <HelpCircle className="w-3.5 h-3.5 text-gray-300 hover:text-gray-500 cursor-help" />
                            </span>
                          </div>
                          {info && <p className="text-xs text-gray-500 mt-0.5">{info.tooltip}</p>}
                        </div>
                      </div>
                    </label>
                  )
                })}
              </div>

              <div className="flex justify-end">
                <button
                  onClick={() => setStep(2)}
                  disabled={!emptyBehavior}
                  className="inline-flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  Siguiente
                  <ArrowRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          )}

          {step === 2 && (
            <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 space-y-4">
              <div className="flex items-center gap-2">
                <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">
                  2
                </span>
                <h3 className="text-sm font-semibold text-gray-900">Fundamentación (opcional)</h3>
              </div>

              <div>
                <label className="text-xs font-medium text-gray-600 block mb-1">
                  ¿Por qué tomaste esta decisión?
                </label>
                <textarea
                  value={justification}
                  onChange={(e) => setJustification(e.target.value)}
                  className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm resize-none h-20 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
                  placeholder="Ej: Según Manual REM 2026, esta fila debe registrar 0 cuando no haya atenciones."
                />
              </div>

              <div className="bg-gray-50 rounded-lg border border-gray-200 p-4 space-y-2">
                <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                  Resumen de la decisión
                </h4>
                <div className="text-sm space-y-1">
                  <p>
                    <span className="font-medium text-gray-700">Fila {row.row_number}</span> —{' '}
                    {row.concepto ?? '—'}
                  </p>
                  <p className="text-gray-600">
                    • <span className="font-medium">Comportamiento:</span>{' '}
                    {EMPTY_BEHAVIOR_LABELS[emptyBehavior] ?? '—'}
                  </p>
                  <p className="text-gray-600">
                    • <span className="font-medium">Alcance:</span> {row.scope ?? 'global'}
                  </p>
                  {justification && (
                    <p className="text-gray-600">
                      • <span className="font-medium">Fundamentación:</span> {justification}
                    </p>
                  )}
                </div>
              </div>

              <div className="flex justify-between">
                <button
                  onClick={() => setStep(1)}
                  className="text-sm text-gray-500 hover:text-gray-700 font-medium"
                >
                  ← Anterior
                </button>
                <div className="flex gap-2">
                  {saveError && (
                    <span className="text-xs text-red-500 self-center">{saveError}</span>
                  )}
                  <button
                    onClick={handleSave}
                    disabled={saveMutation.isPending}
                    className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                  >
                    <Save className="w-4 h-4" />
                    {saveMutation.isPending ? 'Guardando...' : 'Guardar criterio'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </>
      )}

      {/* Post-save / Workflow actions */}
      {saved && (
        <div className="bg-emerald-50 rounded-xl border border-emerald-200 p-4">
          <div className="flex items-center gap-2 mb-3">
            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
            <span className="text-sm font-medium text-emerald-800">
              {isTerminal
                ? 'Criterio publicado y sincronizado con el motor'
                : 'Criterio guardado correctamente'}
            </span>
          </div>

          {/* Show resetting indicator */}
          {resetting && (
            <div className="flex items-center gap-2 text-xs text-amber-600 mb-3">
              <RefreshCw className="w-3 h-3 animate-spin" />
              Reingresando al flujo de aprobación...
            </div>
          )}

          {/* Workflow action button — only show one */}
          {!resetting && actionTransition && (
            <div className="space-y-2">
              <div className="flex items-center gap-3">
                <button
                  onClick={handleTransition}
                  disabled={transitionMutation.isPending}
                  className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold text-white transition-colors shadow-sm
                    bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
                >
                  {isApprovable && <ThumbsUp className="w-4 h-4" />}
                  {isPublishable && <Globe className="w-4 h-4" />}
                  {transitionMutation.isPending ? 'Procesando...' : actionTransition.label}
                </button>
                <input
                  type="text"
                  value={transitionComments}
                  onChange={(e) => setTransitionComments(e.target.value)}
                  placeholder="Comentario (opcional)"
                  className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-xs outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
                />
              </div>
              {transitionError && <p className="text-xs text-red-500">{transitionError}</p>}
            </div>
          )}

          {/* Sync info after publication */}
          {isTerminal && (
            <div
              className={`rounded-lg border p-3 text-xs mt-3 ${
                row.sync_status === 'sincronizado'
                  ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
                  : row.sync_status === 'error'
                    ? 'bg-red-50 border-red-200 text-red-700'
                    : 'bg-amber-50 border-amber-200 text-amber-700'
              }`}
            >
              <div className="flex items-center gap-2">
                {row.sync_status === 'sincronizado' ? (
                  <>
                    <CheckCircle2 className="w-4 h-4 shrink-0" />
                    <span>Criterio sincronizado con el motor de validación Esalud.</span>
                  </>
                ) : row.sync_status === 'error' ? (
                  <>
                    <AlertTriangle className="w-4 h-4 shrink-0" />
                    <span>Error de sincronización. Contacta al administrador.</span>
                  </>
                ) : (
                  <>
                    <Clock className="w-4 h-4 shrink-0" />
                    <span>Sincronizando con el motor de validación...</span>
                  </>
                )}
              </div>
            </div>
          )}

          {/* Next row / Close */}
          <div className="flex items-center justify-between mt-4 pt-3 border-t border-emerald-200">
            <div>
              {nextRow ? (
                <p className="text-xs text-emerald-600">
                  Siguiente: {nextRow.concepto ?? `Fila ${nextRow.row_number}`}
                  {nextRow.profesional && <> — {nextRow.profesional}</>}
                </p>
              ) : (
                <p className="text-xs text-emerald-600">
                  No hay más filas pendientes en esta sección.
                </p>
              )}
            </div>
            <div className="flex gap-2">
              <button
                onClick={onClose}
                className="text-xs text-gray-500 hover:text-gray-700 font-medium"
              >
                Volver al panel
              </button>
              {nextRow && (
                <button
                  onClick={handleNext}
                  className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700 transition-colors"
                >
                  Revisar siguiente
                  <ArrowRight className="w-3 h-3" />
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Technical detail collapsible */}
      <div className="border-t border-gray-200 pt-3">
        <button
          onClick={() => setShowTecnico(!showTecnico)}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 transition-colors"
        >
          {showTecnico ? <ChevronDown className="w-3 h-3" /> : <ChevronRight className="w-3 h-3" />}
          Ver información técnica
        </button>
        {showTecnico && (
          <div className="mt-2 space-y-3">
            <div className="bg-gray-50 rounded-lg border border-gray-200 p-3 space-y-1.5 text-xs text-gray-600">
              <p>
                <span className="font-medium text-gray-700">Patrón:</span> {getPatternLabel(row)}
              </p>
              <p>
                <span className="font-medium text-gray-700">Alcance:</span> {row.scope ?? 'global'}
              </p>
              <p>
                <span className="font-medium text-gray-700">Estado:</span>{' '}
                {WORKFLOW_STATE_LABELS[stateCode] ?? stateCode}
              </p>
              <p>
                <span className="font-medium text-gray-700">Sincronización:</span>{' '}
                {SYNC_STATUS_MAP[row.sync_status ?? '']?.label ?? 'Pendiente'}
              </p>
              {row.parent_criterion_id && (
                <p>
                  <span className="font-medium text-gray-700">Hereda de:</span> criterio #
                  {row.parent_criterion_id}
                </p>
              )}
            </div>

            {workflowData && workflowData.history.length > 0 && (
              <div className="bg-gray-50 rounded-lg border border-gray-200 p-3 space-y-2">
                <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-1.5">
                  <GitCommit className="w-3 h-3" />
                  Historial de cambios de estado
                </h4>
                <div className="space-y-1.5">
                  {workflowData.history.map((h) => (
                    <div key={h.id} className="text-[11px] text-gray-500 flex items-start gap-2">
                      <div className="shrink-0 mt-0.5">
                        <div className="w-1.5 h-1.5 rounded-full bg-indigo-300" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <span className="font-medium text-gray-700">{h.from_state.name}</span>
                        <span className="text-gray-400 mx-1">→</span>
                        <span className="font-medium text-gray-700">{h.to_state.name}</span>
                        {h.transitioned_by && (
                          <span className="text-gray-400 ml-1">
                            <User className="inline w-2.5 h-2.5 mr-0.5" />
                            {h.transitioned_by.name}
                          </span>
                        )}
                        {h.comments && (
                          <span className="block text-gray-400 truncate">
                            <MessageSquare className="inline w-2.5 h-2.5 mr-0.5" />
                            {h.comments}
                          </span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
