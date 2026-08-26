import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle,
  HelpCircle,
  Scissors,
  Settings2,
  ShieldCheck,
  ShieldQuestion,
} from 'lucide-react'
import type { AxiosError } from 'axios'
import { toast } from 'sonner'
import { calibrationService } from '../../services/calibration'
import type {
  MigrationPlanPattern,
  MigrationPlanResponse,
  MismatchResolutionCategory,
  MismatchResolutionDetails,
} from '../../types/calibration'

interface MismatchConfirmErrorResponse {
  message: string
  errors: string[] | null
  data?: { category?: string; resolution_category?: string }
}

interface Props {
  sheet: string
  section: string
  sectionTitle?: string
  plan: MigrationPlanResponse
  readOnly?: boolean
  onOpenAdvanced?: () => void
}

function formatDate(value: string | null | undefined): string {
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

const CATEGORY_LABEL: Record<MismatchResolutionCategory, string> = {
  safe_reconfirm: 'Auditado como seguro para reconfirmar',
  human_review: 'Requiere revisión funcional completa',
  structural_review: 'Cambio estructural — requiere flujo de calibración completa',
  structural_row_exclusion: 'Exclusión estructural de TOTAL líder — validada mecánicamente',
}

/**
 * Panel de resolucion para patrones en categoria MISMATCH (fingerprint v2
 * que dejo de coincidir con el recalculo en vivo). A diferencia de
 * QuickRevalidationPanel (gatillado solo por la categoria de migracion), un
 * MISMATCH NUNCA se resuelve solo por estar en esta lista: cada patron debe
 * haber sido auditado y etiquetado previamente (safe_reconfirm / human_review
 * / structural_review) -- sin esa etiqueta, el backend rechaza cualquier
 * confirmacion. El boton de confirmacion rapida solo aparece para
 * safe_reconfirm; human_review solo ofrece "revision completa"; structural
 * nunca ofrece un boton rapido.
 */
export function MismatchResolutionPanel({
  sheet,
  section,
  sectionTitle,
  plan,
  readOnly = false,
  onOpenAdvanced,
}: Props) {
  const mismatchPatterns = plan.patterns.filter((pattern) => pattern.category === 'MISMATCH')

  if (mismatchPatterns.length === 0) {
    return null
  }

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-amber-600">
              Discrepancia detectada (MISMATCH)
            </p>
            <h2 className="mt-1 text-xl font-bold text-slate-900">
              Sección {section} — {sectionTitle || plan.code}
            </h2>
          </div>
          <span className="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">
            <AlertTriangle className="h-3.5 w-3.5" />
            {mismatchPatterns.length} patrón{mismatchPatterns.length === 1 ? '' : 'es'} en MISMATCH
          </span>
        </div>
        <p className="mt-3 text-sm text-amber-900">
          La huella (fingerprint) que quedó registrada la última vez que se revisó esta sección ya
          no coincide con lo que el motor calcula hoy. Esto <strong>no se confirma a ciegas</strong>
          : cada patrón requiere una auditoría previa antes de poder resolverse desde aquí.
        </p>
      </div>

      <div className="space-y-3">
        {mismatchPatterns.map((pattern) => (
          <MismatchPatternCard
            key={pattern.pattern_id}
            sheet={sheet}
            section={section}
            pattern={pattern}
            readOnly={readOnly}
            onOpenAdvanced={onOpenAdvanced}
          />
        ))}
      </div>
    </div>
  )
}

function MismatchPatternCard({
  sheet,
  section,
  pattern,
  readOnly,
  onOpenAdvanced,
}: {
  sheet: string
  section: string
  pattern: MigrationPlanPattern
  readOnly: boolean
  onOpenAdvanced?: () => void
}) {
  const queryClient = useQueryClient()

  const detailsQuery = useQuery({
    queryKey: ['mismatch-resolution', sheet, section, pattern.pattern_id],
    queryFn: () =>
      calibrationService.getMismatchResolutionDetails(sheet, section, pattern.pattern_id),
  })

  const details: MismatchResolutionDetails | undefined = detailsQuery.data
  const tag = details?.resolution_tag ?? null
  const historical = pattern.historical_answer ?? details?.historical_answer

  const confirmMutation = useMutation({
    mutationFn: () =>
      calibrationService.confirmMismatchResolution(sheet, section, pattern.pattern_id),
    onSuccess: () => {
      toast.success(
        tag?.category === 'structural_row_exclusion'
          ? `Patrón ${pattern.pattern_id} resuelto (exclusión estructural de TOTAL líder).`
          : `Patrón ${pattern.pattern_id} resuelto (safe_reconfirm).`
      )
      queryClient.invalidateQueries({ queryKey: ['migration-plan', sheet, section] })
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', sheet, section] })
      queryClient.invalidateQueries({
        queryKey: ['mismatch-resolution', sheet, section, pattern.pattern_id],
      })
    },
    onError: (error: AxiosError<MismatchConfirmErrorResponse>) => {
      const err = error.response?.data?.errors?.[0]
      if (error.response?.status === 409) {
        if (err === 'audit_stale') {
          toast.warning(
            'Este patrón volvió a cambiar desde que se auditó — debe auditarse de nuevo antes de confirmar.'
          )
        } else if (err === 'requires_full_review') {
          toast.warning(
            'Este patrón requiere revisión funcional completa, no admite confirmación rápida.'
          )
        } else if (err === 'not_audited') {
          toast.warning(
            'Este patrón todavía no fue auditado — no puede confirmarse sin una clasificación explícita.'
          )
        } else if (
          err === 'incomplete_structural_exclusion_tag' ||
          err === 'structural_exclusion_mismatch'
        ) {
          toast.warning(
            error.response?.data?.message ??
              'La exclusión estructural ya no puede verificarse mecánicamente — requiere volver a auditarse.'
          )
        } else {
          toast.warning(
            'Este patrón cambió desde que se cargó y ya no puede confirmarse por esta vía.'
          )
        }
        queryClient.invalidateQueries({
          queryKey: ['mismatch-resolution', sheet, section, pattern.pattern_id],
        })
        return
      }
      toast.error(error.response?.data?.message ?? 'No se pudo resolver el MISMATCH.')
    },
  })

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
            Patrón {pattern.pattern_id} · {rowsText(pattern.live_rows)}
          </p>
          <p className="mt-1 text-sm text-slate-700">
            {pattern.live_rows.length} fila{pattern.live_rows.length === 1 ? '' : 's'} involucrada
            {pattern.live_rows.length === 1 ? '' : 's'} en el patrón vigente.
          </p>
        </div>
        {tag && (
          <span
            className={
              'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium ' +
              (tag.category === 'safe_reconfirm'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : tag.category === 'structural_row_exclusion'
                  ? 'border-cyan-200 bg-cyan-50 text-cyan-800'
                  : tag.category === 'human_review'
                    ? 'border-indigo-200 bg-indigo-50 text-indigo-800'
                    : 'border-rose-200 bg-rose-50 text-rose-800')
            }
          >
            {tag.category === 'safe_reconfirm' && <ShieldCheck className="h-3.5 w-3.5" />}
            {tag.category === 'structural_row_exclusion' && <Scissors className="h-3.5 w-3.5" />}
            {tag.category === 'human_review' && <ShieldQuestion className="h-3.5 w-3.5" />}
            {tag.category === 'structural_review' && <AlertTriangle className="h-3.5 w-3.5" />}
            {CATEGORY_LABEL[tag.category]}
          </span>
        )}
      </div>

      {details?.column_diff &&
        (details.column_diff.added.length > 0 || details.column_diff.removed.length > 0) && (
          <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
            <p className="font-semibold">Qué cambió en la estructura:</p>
            {details.column_diff.added.length > 0 && (
              <p>Columnas agregadas: {details.column_diff.added.join(', ')}</p>
            )}
            {details.column_diff.removed.length > 0 && (
              <p>Columnas eliminadas: {details.column_diff.removed.join(', ')}</p>
            )}
          </div>
        )}

      <div className="mt-3 rounded-lg border border-slate-100 bg-slate-50 p-3 text-sm text-slate-700">
        <p className="font-semibold text-slate-800">Decisión histórica</p>
        <p className="mt-1">{historical?.response || 'Sin decisión registrada'}</p>
        <p className="mt-1 text-xs text-slate-500">
          Revisado originalmente por {historical?.reviewed_by || 'usuario no registrado'} el{' '}
          {formatDate(historical?.reviewed_at)}.
        </p>
      </div>

      {detailsQuery.isLoading && <p className="mt-3 text-xs text-slate-400">Cargando auditoría…</p>}

      {!detailsQuery.isLoading && tag === null && (
        <div className="mt-4 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
          <HelpCircle className="h-4 w-4 shrink-0" />
          Este patrón aún no fue auditado. No puede confirmarse ni revisarse rápido desde aquí hasta
          que se clasifique explícitamente.
        </div>
      )}

      {tag?.category === 'safe_reconfirm' && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <p className="text-xs text-slate-500">Motivo de la auditoría: {tag.reason}</p>
          {!readOnly && (
            <button
              type="button"
              onClick={() => confirmMutation.mutate()}
              disabled={confirmMutation.isPending}
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {confirmMutation.isPending ? 'Confirmando...' : 'Confirmar reconfirmación segura'}
            </button>
          )}
        </div>
      )}

      {tag?.category === 'structural_row_exclusion' && (
        <div className="mt-4 space-y-3">
          <div className="rounded-lg border border-cyan-200 bg-cyan-50 p-3 text-sm text-cyan-900">
            <p className="flex items-center gap-1.5 font-semibold">
              <Scissors className="h-3.5 w-3.5" />
              No es una reconfirmación común — es una exclusión estructural validada
            </p>
            <p className="mt-1 text-xs text-cyan-800">
              Este patrón cambió de tamaño porque una fila TOTAL (que solo agrega otras filas, sin
              dato propio) salió del cálculo. El motor verificó mecánicamente que esa fila cumple el
              mecanismo #6 — no es una decisión de negocio.
            </p>
            <dl className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-cyan-600">
                  Filas históricas
                </dt>
                <dd className="font-mono text-xs text-cyan-900">
                  [{(tag.historical_rows ?? []).join(', ') || '—'}]
                </dd>
              </div>
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-cyan-600">
                  Filas vigentes
                </dt>
                <dd className="font-mono text-xs text-cyan-900">
                  [{pattern.live_rows.join(', ')}]
                </dd>
              </div>
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-cyan-600">
                  Filas TOTAL líder excluidas
                </dt>
                <dd className="font-mono text-xs font-semibold text-cyan-900">
                  [{(tag.excluded_total_rows ?? []).join(', ') || '—'}]
                </dd>
              </div>
            </dl>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-slate-500">Motivo/mecanismo: {tag.reason}</p>
            {!readOnly && (
              <button
                type="button"
                onClick={() => confirmMutation.mutate()}
                disabled={confirmMutation.isPending}
                className="inline-flex items-center gap-1.5 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-medium text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Scissors className="h-4 w-4" />
                {confirmMutation.isPending
                  ? 'Confirmando...'
                  : 'Confirmar exclusión estructural validada'}
              </button>
            )}
          </div>
        </div>
      )}

      {tag?.category === 'human_review' && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <p className="text-xs text-slate-500">Motivo de la auditoría: {tag.reason}</p>
          {onOpenAdvanced && (
            <button
              type="button"
              onClick={onOpenAdvanced}
              className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
            >
              <Settings2 className="h-4 w-4" />
              Abrir revisión funcional completa
            </button>
          )}
        </div>
      )}

      {tag?.category === 'structural_review' && (
        <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">
          Este es un cambio estructural real — debe resolverse mediante el flujo de calibración
          completa, no admite ninguna vía rápida.
          <p className="mt-1 text-xs text-rose-700">Motivo de la auditoría: {tag.reason}</p>
        </div>
      )}
    </div>
  )
}
