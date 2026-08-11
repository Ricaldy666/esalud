import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, ArrowLeft, ArrowRight, CheckCircle2, Settings2 } from 'lucide-react'
import { toast } from 'sonner'
import { useAuthStore } from '@/app/store/authStore'
import { calibrationService } from '../../services/calibration'
import type {
  CalibrationApplicability,
  CalibrationQuestion,
  PatternMatrixResponse,
} from '../../types/calibration'

const CRITERIA_LABELS: Record<keyof CalibrationApplicability['criteria'], string> = {
  structure_available_and_consistent: 'Estructura disponible y consistente con el cell-data',
  cell_data_available: 'Cell-data escaneado',
  no_pending_warnings: 'Sin advertencias técnicas pendientes',
  no_editable_cells: 'Sin celdas editables/capturables',
  no_functional_formulas: 'Sin fórmulas que requieran criterio funcional',
  no_calibratable_patterns: 'Sin patrones calibrables detectados',
}

function questionKey(question: Pick<CalibrationQuestion, 'id' | 'question'>) {
  return question.id || question.question
}

function findNoCalibratableClosure(
  questions: CalibrationQuestion[]
): CalibrationQuestion | undefined {
  return questions.find(
    (question) =>
      questionKey(question) === 'section_review' &&
      question.review_status === 'section_reviewed' &&
      question.response === 'no_calibrable'
  )
}

interface Props {
  sheet: string
  section: string
  sectionTitle?: string
  data: PatternMatrixResponse
  structureVersion: string
  readOnly?: boolean
  previousSection?: string
  nextSection?: string
  onOpenAdvanced?: () => void
  onNavigateSection?: (section: string) => void
}

export function NotCalibratableSectionPanel({
  sheet,
  section,
  sectionTitle,
  data,
  structureVersion = '',
  readOnly = false,
  previousSection,
  nextSection,
  onOpenAdvanced,
  onNavigateSection,
}: Props) {
  const user = useAuthStore((state) => state.user)
  const queryClient = useQueryClient()
  const userName = user?.name ?? user?.email ?? 'Usuario funcional'
  const questions = data.questions ?? []
  const applicability = data.calibration_applicability
  const closure = findNoCalibratableClosure(questions)

  const saveMutation = useMutation({
    mutationFn: (payload: CalibrationQuestion[]) =>
      calibrationService.savePatternQuestions(sheet, section, payload),
    onSuccess: () => {
      toast.success('Sección cerrada: no requiere calibración funcional.')
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', sheet, section] })
      if (nextSection && onNavigateSection) onNavigateSection(nextSection)
    },
    onError: () => {
      toast.error('No se pudo guardar el cierre de la sección.')
    },
  })

  const confirmNoCalibratable = () => {
    if (readOnly || closure) return

    const reviewedAt = new Date().toISOString()
    const otherQuestions = questions.filter(
      (question) => questionKey(question) !== 'section_review'
    )
    const closureQuestion: CalibrationQuestion = {
      id: 'section_review',
      row: null,
      type: 'section_review',
      question: `Sección ${section}: sin celdas capturables ni reglas funcionales, no requiere calibración`,
      response: 'no_calibrable',
      observation: applicability?.reason ?? '',
      status: 'answered',
      review_status: 'section_reviewed',
      reviewed_at: reviewedAt,
      reviewed_by: userName,
      closure_reason: 'no_calibratable_data',
      source_type: 'manual',
      technical_signature: `no_calibratable:${structureVersion}`,
      structure_version: structureVersion,
    }

    saveMutation.mutate([...otherQuestions, closureQuestion])
  }

  const criteriaEntries = applicability
    ? (Object.keys(CRITERIA_LABELS) as Array<keyof CalibrationApplicability['criteria']>)
    : []

  return (
    <div className="space-y-5">
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
              Calibración rápida
            </p>
            <h2 className="mt-1 text-xl font-bold text-slate-900">
              Sección {section} — {sectionTitle || data.section.titulo}
            </h2>
          </div>
          <span
            className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium ${
              closure
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-slate-300 bg-slate-50 text-slate-700'
            }`}
          >
            {closure ? (
              <CheckCircle2 className="h-3.5 w-3.5" />
            ) : (
              <AlertCircle className="h-3.5 w-3.5" />
            )}
            {closure ? 'No requiere calibración' : 'Sin contenido calibrable'}
          </span>
        </div>
      </div>

      <div
        className={`rounded-xl border p-5 shadow-sm ${
          closure ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'
        }`}
      >
        <p className={`text-sm font-medium ${closure ? 'text-emerald-900' : 'text-slate-800'}`}>
          Esta sección no contiene celdas capturables ni reglas funcionales que requieran
          calibración.
        </p>
        <p className={`mt-2 text-sm ${closure ? 'text-emerald-800' : 'text-slate-600'}`}>
          {applicability?.reason ??
            'Todas las celdas funcionales del rango de datos están bloqueadas, sin fórmulas ni patrones detectados.'}
        </p>

        {criteriaEntries.length > 0 && (
          <ul className="mt-4 space-y-1.5">
            {criteriaEntries.map((key) => (
              <li key={key} className="flex items-center gap-2 text-sm text-slate-600">
                <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                {CRITERIA_LABELS[key]}
              </li>
            ))}
          </ul>
        )}

        {closure ? (
          <div className="mt-4 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-xs text-emerald-800">
            Confirmado por {closure.reviewed_by || 'usuario'}
            {closure.reviewed_at ? ` el ${new Date(closure.reviewed_at).toLocaleString()}` : ''}.
          </div>
        ) : (
          <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            Este diagnóstico es una recomendación técnica. Debe confirmarlo antes de cerrar la
            sección — no se guarda ningún cambio hasta que lo haga.
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
            {!readOnly && !closure && (
              <button
                type="button"
                onClick={confirmNoCalibratable}
                disabled={saveMutation.isPending}
                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {saveMutation.isPending ? 'Guardando...' : 'Confirmar diagnóstico y cerrar sección'}
                {nextSection && <ArrowRight className="ml-1 inline h-4 w-4" />}
              </button>
            )}
            {!readOnly && closure && nextSection && onNavigateSection && (
              <button
                type="button"
                onClick={() => onNavigateSection(nextSection)}
                className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
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
