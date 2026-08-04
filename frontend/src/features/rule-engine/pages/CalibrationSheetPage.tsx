import { useMemo } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQueries, useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, CheckCircle2, ChevronRight, Clock } from 'lucide-react'
import { structuresService } from '../services/structures'
import { calibrationService } from '../services/calibration'
import type { CalibrationQuestion, PatternMatrixResponse } from '../types/calibration'
import type { StructureSection } from '../types/structure'

export default function CalibrationSheetPage() {
  const { templateId, series, sheet } = useParams<{
    templateId: string
    series: string
    sheet: string
  }>()
  const navigate = useNavigate()
  const structureId = Number(templateId)

  const { data: structure, isLoading } = useQuery({
    queryKey: ['calibration-template', structureId],
    queryFn: () => structuresService.get(structureId),
    enabled: Number.isFinite(structureId),
  })

  const sections = useMemo(() => {
    const form = structure?.forms_detail?.find((item) => item.sheetName === sheet)
    if (!form) return []

    return form.sections
  }, [sheet, structure])

  const sectionSummaries = useQueries({
    queries: sections.map((section) => ({
      queryKey: ['pattern-matrix', sheet, section.codigo],
      queryFn: () => calibrationService.getPatterns(sheet!, section.codigo),
      enabled: Boolean(sheet) && hasTechnicalData(section),
      staleTime: 30_000,
    })),
  })

  const summariesBySection = useMemo(() => {
    const map = new Map<string, FunctionalSectionSummary>()
    sections.forEach((section, index) => {
      const data = sectionSummaries[index]?.data
      if (data) map.set(section.codigo, buildFunctionalSummary(data))
    })
    return map
  }, [sectionSummaries, sections])

  const missingEvidenceSections = useMemo(() => {
    return sections
      .filter(hasTechnicalData)
      .filter((_section, index) => {
        const data = sectionSummaries[index]?.data
        return data ? lacksCellEvidence(data) : false
      })
      .map((section) => section.codigo)
  }, [sectionSummaries, sections])

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => navigate(`/calibracion/templates/${templateId}/series/${series}`)}
          className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
        >
          <ArrowLeft className="h-4 w-4" />
          Serie {series}
        </button>
        <span className="text-sm text-gray-300">/</span>
        <h1 className="text-xl font-bold text-gray-900">Hoja {sheet}</h1>
      </div>

      {isLoading ? (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {[1, 2, 3].map((item) => (
            <div
              key={item}
              className="h-32 rounded-lg border border-gray-200 bg-white p-5 animate-pulse"
            />
          ))}
        </div>
      ) : sections.length === 0 ? (
        <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">
          No hay secciones disponibles para esta hoja.
        </div>
      ) : (
        <>
          {missingEvidenceSections.length > 0 && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
              <div className="flex gap-3">
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                <div>
                  <p className="font-semibold">
                    La hoja {sheet} no esta completamente preparada para calibracion.
                  </p>
                  <p className="mt-1">
                    Faltan evidencias de celdas escaneadas para:{' '}
                    {missingEvidenceSections.join(', ')}.
                  </p>
                  <p className="mt-2 font-mono text-xs">php artisan rem:scan-cells {sheet} --all</p>
                </div>
              </div>
            </div>
          )}
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {sections.map((section, index) => {
              const summaryData = sectionSummaries[index]?.data
              const evidenceMissing = summaryData ? lacksCellEvidence(summaryData) : false
              return (
                <SectionCard
                  key={section.codigo}
                  section={section}
                  functionalSummary={summariesBySection.get(section.codigo)}
                  loadingSummary={sectionSummaries[index]?.isLoading ?? false}
                  evidenceMissing={evidenceMissing}
                  onOpen={() =>
                    navigate(
                      `/calibracion/templates/${templateId}/series/${series}/sheets/${sheet}/sections/${section.codigo}`
                    )
                  }
                  onOpenPreliminary={() =>
                    navigate(
                      `/calibracion/templates/${templateId}/series/${series}/sheets/${sheet}/sections/${section.codigo}?preliminar=1`
                    )
                  }
                />
              )
            })}
          </div>
        </>
      )}
    </div>
  )
}

interface FunctionalSectionSummary {
  status: 'Sin iniciar' | 'En revision' | 'Revisada' | 'Con observaciones'
  progressPct: number
  patternsTotal: number
  patternsReviewed: number
  questionsTotal: number
  questionsAnswered: number
  reviewedAt?: string
  reviewedBy?: string
}

function hasTechnicalData(section: StructureSection) {
  return section.campos > 0 || section.reglas > 0 || section.fields.length > 0
}

function questionKey(question: Pick<CalibrationQuestion, 'id' | 'question'>) {
  return question.id || question.question
}

function buildFunctionalSummary(data: PatternMatrixResponse): FunctionalSectionSummary {
  const questions = data.questions ?? []
  const patternQuestions = questions.filter(
    (question) => question.pattern_id || question.type === 'pattern_question'
  )
  const questionsTotal = patternQuestions.length
  const questionsAnswered = patternQuestions.filter(
    (question) => String(question.response ?? '').trim() !== ''
  ).length
  const reviewedPatternIds = new Set(
    patternQuestions
      .filter((question) => question.pattern_id && question.review_status === 'reviewed')
      .map((question) => question.pattern_id)
  )
  const sectionReview = questions.find((question) => questionKey(question) === 'section_review')
  const hasClarifications = questions.some((question) => question.status === 'clarification')
  const patternsTotal = data.patterns.length
  const patternsReviewed = reviewedPatternIds.size
  const progressPct =
    questionsTotal > 0 ? Math.round((questionsAnswered / questionsTotal) * 100) : 0
  const status =
    sectionReview?.review_status === 'section_reviewed'
      ? 'Revisada'
      : hasClarifications
        ? 'Con observaciones'
        : questionsAnswered > 0
          ? 'En revision'
          : 'Sin iniciar'

  return {
    status,
    progressPct,
    patternsTotal,
    patternsReviewed,
    questionsTotal,
    questionsAnswered,
    reviewedAt: sectionReview?.reviewed_at,
    reviewedBy: sectionReview?.reviewed_by,
  }
}

function lacksCellEvidence(data: PatternMatrixResponse) {
  const hasMissingEvidenceWarning = (data.warnings ?? []).some((warning) =>
    warning.toLowerCase().includes('no existe evidencia de celdas escaneadas')
  )
  const sources = [
    ...data.patterns.map((pattern) => pattern.source),
    ...(data.column_groups ?? []).map((group) => group.source),
  ].filter(Boolean)

  return hasMissingEvidenceWarning || sources.includes('structure_inferred')
}

function formatReviewDate(value?: string) {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('es-CL', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date)
}

function statusClass(status: FunctionalSectionSummary['status'] | 'Pendiente') {
  if (status === 'Revisada') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (status === 'Con observaciones') return 'border-amber-200 bg-amber-50 text-amber-700'
  if (status === 'En revision') return 'border-indigo-200 bg-indigo-50 text-indigo-700'
  return 'border-slate-200 bg-slate-100 text-slate-600'
}

function SectionCard({
  section,
  functionalSummary,
  loadingSummary,
  evidenceMissing,
  onOpen,
  onOpenPreliminary,
}: {
  section: StructureSection
  functionalSummary?: FunctionalSectionSummary
  loadingSummary: boolean
  evidenceMissing: boolean
  onOpen: () => void
  onOpenPreliminary: () => void
}) {
  const hasData = hasTechnicalData(section)
  const status = functionalSummary?.status ?? (hasData ? 'Sin iniciar' : 'Pendiente')
  const reviewedDate = formatReviewDate(functionalSummary?.reviewedAt)
  const reviewText = evidenceMissing
    ? 'Falta evidencia de celdas escaneadas'
    : functionalSummary?.reviewedBy
      ? `Reviso: ${functionalSummary.reviewedBy}${reviewedDate ? ` - ${reviewedDate}` : ''}`
      : 'Calibracion funcional pendiente'

  return (
    <div
      className={`rounded-lg border bg-white p-5 text-left shadow-sm transition-colors ${
        evidenceMissing
          ? 'border-amber-200'
          : 'border-gray-200 hover:border-indigo-200 hover:bg-indigo-50/30'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Seccion</p>
          <h2 className="mt-1 text-lg font-semibold text-gray-900">{section.codigo}</h2>
        </div>
        <span
          className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${statusClass(status)}`}
        >
          {status === 'Revisada' ? (
            <CheckCircle2 className="h-3 w-3" />
          ) : (
            <Clock className="h-3 w-3" />
          )}
          {loadingSummary ? 'Cargando' : status}
        </span>
      </div>
      <p className="mt-3 min-h-10 text-sm text-gray-600">
        {section.titulo || 'Sin datos suficientes'}
      </p>
      <div className="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 text-sm">
        <CardMetric label="Campos" value={String(section.campos ?? section.fields.length)} />
        <CardMetric label="Reglas tecnicas" value={String(section.reglas ?? 0)} />
        <CardMetric
          label="Avance funcional"
          value={functionalSummary ? `${functionalSummary.progressPct}%` : 'Sin iniciar'}
        />
        <CardMetric
          label="Patrones"
          value={
            functionalSummary
              ? `${functionalSummary.patternsReviewed}/${functionalSummary.patternsTotal}`
              : '0/0'
          }
        />
        <CardMetric
          label="Preguntas"
          value={
            functionalSummary
              ? `${functionalSummary.questionsAnswered}/${functionalSummary.questionsTotal}`
              : '0/0'
          }
        />
      </div>
      <div className="mt-4 flex items-center justify-between text-xs text-gray-500">
        <span>{reviewText}</span>
        {!evidenceMissing && <ChevronRight className="h-4 w-4 text-gray-400" />}
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          onClick={onOpen}
          disabled={evidenceMissing || !hasData || loadingSummary}
          className="inline-flex items-center gap-1 rounded-md border border-indigo-200 px-3 py-1.5 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-50 disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-400"
        >
          Abrir calibracion
        </button>
        {evidenceMissing && hasData && (
          <button
            type="button"
            onClick={onOpenPreliminary}
            className="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-800 transition-colors hover:bg-amber-200"
          >
            Abrir modo preliminar
          </button>
        )}
      </div>
    </div>
  )
}

function CardMetric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-gray-400">{label}</p>
      <p className="mt-1 font-medium text-gray-900">{value}</p>
    </div>
  )
}
