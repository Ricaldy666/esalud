import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  RotateCcw,
  Settings2,
} from 'lucide-react'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'
import { calibrationService } from '../../services/calibration'
import type { MigrationPlanPattern, MigrationPlanResponse } from '../../types/calibration'

interface QuickRevalidationErrorResponse {
  message: string
  errors: string[] | null
  data?: { category?: string }
}

interface Props {
  sheet: string
  section: string
  sectionTitle?: string
  plan: MigrationPlanResponse
  readOnly?: boolean
  previousSection?: string
  nextSection?: string
  onOpenAdvanced?: () => void
  onNavigateSection?: (section: string) => void
  onReviewAgain: () => void
}

function formatDate(value: string | null): string {
  if (!value) return 'fecha no registrada'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return value
  }
}

function rowsText(rows: number[]): string {
  if (rows.length === 0) return 'sin filas'
  const sorted = [...rows].sort((a, b) => a - b)
  return sorted[0] === sorted[sorted.length - 1]
    ? `fila ${sorted[0]}`
    : `filas ${sorted[0]}-${sorted[sorted.length - 1]}`
}

export function QuickRevalidationPanel({
  sheet,
  section,
  sectionTitle,
  plan,
  readOnly = false,
  previousSection,
  nextSection,
  onOpenAdvanced,
  onNavigateSection,
  onReviewAgain,
}: Props) {
  const queryClient = useQueryClient()
  const eligiblePatterns = plan.patterns.filter(
    (pattern) => pattern.category === 'QUICK_CONFIRMATION'
  )
  const columnDiff = plan.column_diff

  const confirmMutation = useMutation({
    mutationFn: (patternId: number) =>
      calibrationService.confirmQuickRevalidation(sheet, section, patternId),
    onSuccess: (_data, patternId) => {
      toast.success(`Patrón ${patternId} confirmado.`)
      queryClient.invalidateQueries({ queryKey: ['migration-plan', sheet, section] })
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', sheet, section] })
    },
    onError: (error: AxiosError<QuickRevalidationErrorResponse>) => {
      const category = error.response?.data?.data?.category
      if (error.response?.status === 409) {
        toast.warning(
          category
            ? `Esta sección cambió (ahora es ${category}) y ya no admite confirmación rápida. Debe revisarse completa.`
            : 'Esta sección cambió desde que se cargó y ya no admite confirmación rápida.'
        )
        queryClient.invalidateQueries({ queryKey: ['migration-plan', sheet, section] })
        return
      }
      toast.error(error.response?.data?.message ?? 'No se pudo confirmar la revalidación rápida.')
    },
  })

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
              Confirmación rápida
            </p>
            <h2 className="mt-1 text-xl font-bold text-slate-900">
              Sección {section} — {sectionTitle || plan.code}
            </h2>
          </div>
          <span className="inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700">
            <AlertCircle className="h-3.5 w-3.5" />
            {eligiblePatterns.length} patrón{eligiblePatterns.length === 1 ? '' : 'es'} por
            confirmar
          </span>
        </div>
        <p className="mt-3 text-sm text-slate-600">
          Esta sección ya fue revisada funcionalmente antes. La estructura cambió desde entonces,
          pero las filas de cada patrón siguen siendo exactamente las mismas — solo debe confirmar
          que la revisión anterior sigue siendo válida. No se modifica la decisión original ni quién
          la registró.
        </p>
        {columnDiff && (columnDiff.added.length > 0 || columnDiff.removed.length > 0) && (
          <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
            <p className="font-semibold">Qué cambió en la estructura:</p>
            {columnDiff.added.length > 0 && (
              <p>Columnas agregadas: {columnDiff.added.join(', ')}</p>
            )}
            {columnDiff.removed.length > 0 && (
              <p>Columnas eliminadas: {columnDiff.removed.join(', ')}</p>
            )}
          </div>
        )}
      </div>

      <div className="space-y-3">
        {eligiblePatterns.map((pattern) => (
          <PatternCard
            key={pattern.pattern_id}
            pattern={pattern}
            readOnly={readOnly}
            pending={confirmMutation.isPending && confirmMutation.variables === pattern.pattern_id}
            onConfirm={() => confirmMutation.mutate(pattern.pattern_id)}
          />
        ))}
        {eligiblePatterns.length === 0 && (
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <CheckCircle2 className="mr-1.5 inline h-4 w-4" />
            Todos los patrones de esta sección ya fueron confirmados.
          </div>
        )}
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap gap-2">
            {previousSection && onNavigateSection && (
              <button
                type="button"
                onClick={() => onNavigateSection(previousSection)}
                className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
              >
                <ArrowLeft className="h-4 w-4" />
                Sección anterior
              </button>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            {onOpenAdvanced && (
              <button
                type="button"
                onClick={onOpenAdvanced}
                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                <Settings2 className="h-4 w-4" />
                Ver evidencia técnica
              </button>
            )}
            {!readOnly && (
              <button
                type="button"
                onClick={onReviewAgain}
                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                <RotateCcw className="h-4 w-4" />
                Revisar nuevamente esta sección
              </button>
            )}
            {eligiblePatterns.length === 0 && nextSection && onNavigateSection && (
              <button
                type="button"
                onClick={() => onNavigateSection(nextSection)}
                className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
              >
                Sección siguiente
                <ArrowRight className="h-4 w-4" />
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

function PatternCard({
  pattern,
  readOnly,
  pending,
  onConfirm,
}: {
  pattern: MigrationPlanPattern
  readOnly: boolean
  pending: boolean
  onConfirm: () => void
}) {
  const historical = pattern.historical_answer

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
            Patrón {pattern.pattern_id} · {rowsText(pattern.live_rows)}
          </p>
          <p className="mt-1 text-sm text-slate-700">
            {pattern.live_rows.length} fila{pattern.live_rows.length === 1 ? '' : 's'} involucrada
            {pattern.live_rows.length === 1 ? '' : 's'}.
          </p>
        </div>
      </div>

      <div className="mt-3 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-700">
        <p className="font-semibold text-slate-800">Decisión anterior</p>
        <p className="mt-1">{historical?.response || 'Sin decisión registrada'}</p>
        <p className="mt-1 text-xs text-slate-500">
          Revisado originalmente por {historical?.reviewed_by || 'usuario no registrado'} el{' '}
          {formatDate(historical?.reviewed_at ?? null)}.
        </p>
      </div>

      {!readOnly && (
        <div className="mt-4 flex justify-end">
          <button
            type="button"
            onClick={onConfirm}
            disabled={pending}
            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {pending ? 'Confirmando...' : 'Confirmar que la revisión anterior sigue vigente'}
          </button>
        </div>
      )}
    </div>
  )
}
