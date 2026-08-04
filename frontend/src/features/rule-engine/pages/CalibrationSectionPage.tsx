import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useQueries, useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, Grid3X3, LayoutGrid, ListChecks } from 'lucide-react'
import { useAuthStore } from '@/app/store/authStore'
import { useCalibrationMatrix } from '../hooks/useCalibrationMatrix'
import { calibrationService } from '../services/calibration'
import { structuresService } from '../services/structures'
import SeccionRevisionPage from './SeccionRevisionPage'
import { SectionCalibrationTable } from '../components/SectionCalibrationTable'
import PatternCalibrationSummary from '../components/patterns/PatternCalibrationSummary'
import QuickCalibrationPanel from '../components/patterns/QuickCalibrationPanel'
import RowFunctionalDecisionTable from '../components/patterns/RowFunctionalDecisionTable'
import type { CalibrationQuestion, PatternMatrixResponse } from '../types/calibration'

type TabView = 'functional' | 'matrix' | 'patterns'

const READ_ONLY_ROLES = ['Revisor', 'Auditor']

export default function CalibrationSectionPage() {
  const { templateId, series, sheet, section } = useParams<{
    templateId: string
    series: string
    sheet: string
    section: string
  }>()
  const [searchParams] = useSearchParams()
  const preliminaryMode = searchParams.get('preliminar') === '1'
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const isReadOnly = user?.roles.some((role) => READ_ONLY_ROLES.includes(role)) ?? false
  const [tab, setTab] = useState<TabView>(isReadOnly ? 'matrix' : 'functional')
  const [showAdvanced, setShowAdvanced] = useState(false)
  const { data: matrixData, isLoading: matrixLoading } = useCalibrationMatrix(sheet, section)
  const structureId = Number(templateId)
  const { data: structure } = useQuery({
    queryKey: ['calibration-template', structureId],
    queryFn: () => structuresService.get(structureId),
    enabled: Number.isFinite(structureId),
  })
  const { data: patternData, isLoading: patternLoading } = useQuery({
    queryKey: ['pattern-matrix', sheet, section],
    queryFn: () => calibrationService.getPatterns(sheet!, section!),
    enabled: Boolean(sheet) && Boolean(section),
    staleTime: 30_000,
  })
  const sections = useMemo(() => {
    const form = structure?.forms_detail?.find((item) => item.sheetName === sheet)
    return form?.sections ?? []
  }, [sheet, structure])
  const currentIndex = sections.findIndex((item) => item.codigo === section)
  // Se difieren las peticiones de las demas secciones (usadas solo para la
  // navegacion "siguiente seccion pendiente") hasta que la seccion actual ya
  // tenga su propio patternData. Sin esto, una hoja con muchas secciones (ej.
  // A03 con 23) dispara una peticion /patterns por cada una en simultaneo con
  // matrix/patterns/row-functional-decisions de la seccion actual, saturando
  // la cola del servidor y retrasando la carga de la tabla por fila.
  const [readySection, setReadySection] = useState<string | undefined>(undefined)
  useEffect(() => {
    if (patternLoading || !patternData) return
    const id = setTimeout(() => setReadySection(section), 0)
    return () => clearTimeout(id)
  }, [patternLoading, patternData, section])
  const deferSummaries = readySection === section
  const sectionSummaries = useQueries({
    queries: sections.map((item) => ({
      queryKey: ['pattern-matrix', sheet, item.codigo],
      queryFn: () => calibrationService.getPatterns(sheet!, item.codigo),
      enabled: Boolean(sheet) && item.codigo !== section && deferSummaries,
      staleTime: 30_000,
    })),
  })
  const previousSection = currentIndex > 0 ? sections[currentIndex - 1]?.codigo : undefined
  const nextSection = useMemo(() => {
    if (currentIndex < 0) return undefined
    const pendingAfterCurrent = sections.slice(currentIndex + 1).find((_item, offset) => {
      const queryIndex = currentIndex + 1 + offset
      const summary = sectionSummaries[queryIndex]?.data
      return summary ? !isSectionReviewed(summary.questions ?? []) : true
    })
    return pendingAfterCurrent?.codigo ?? sections[currentIndex + 1]?.codigo
  }, [currentIndex, sectionSummaries, sections])
  const currentSectionTitle = sections.find((item) => item.codigo === section)?.titulo
  const candidateSections = useMemo(() => {
    return sections
      .map((item, index) => ({
        sheet: sheet ?? 'A01',
        section: item.codigo,
        title: item.titulo,
        data: sectionSummaries[index]?.data,
        structureVersion: String(templateId ?? ''),
      }))
      .filter(
        (
          item
        ): item is {
          sheet: string
          section: string
          title: string
          data: PatternMatrixResponse
          structureVersion: string
        } => item.section !== section && Boolean(item.data)
      )
  }, [section, sectionSummaries, sections, sheet, templateId])
  const evidenceMissing = patternData ? lacksCellEvidence(patternData) : false
  const navigateToSection = (targetSection: string) => {
    navigate(
      `/calibracion/templates/${templateId}/series/${series}/sheets/${sheet}/sections/${targetSection}`
    )
  }

  if (evidenceMissing && !preliminaryMode) {
    return (
      <div className="space-y-6">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() =>
              navigate(`/calibracion/templates/${templateId}/series/${series}/sheets/${sheet}`)
            }
            className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
          >
            <ArrowLeft className="h-4 w-4" />
            Hoja {sheet}
          </button>
          <span className="text-sm text-gray-300">/</span>
          <h1 className="text-xl font-bold text-gray-900">Seccion {section}</h1>
        </div>

        <div className="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
          <div className="flex gap-3">
            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
            <div>
              <p className="font-semibold">
                La hoja {sheet} no esta completamente preparada para calibracion.
              </p>
              <p className="mt-1">Falta evidencia persistida para la seccion {section}.</p>
              <p className="mt-2">Debe ejecutarse:</p>
              <p className="mt-1 font-mono text-xs">php artisan rem:scan-cells {sheet} --all</p>
              <button
                type="button"
                onClick={() =>
                  navigate(
                    `/calibracion/templates/${templateId}/series/${series}/sheets/${sheet}/sections/${section}?preliminar=1`
                  )
                }
                className="mt-4 rounded-md border border-amber-300 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-200"
              >
                Abrir modo preliminar
              </button>
            </div>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() =>
              navigate(`/calibracion/templates/${templateId}/series/${series}/sheets/${sheet}`)
            }
            className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
          >
            <ArrowLeft className="h-4 w-4" />
            Hoja {sheet}
          </button>
          <span className="text-sm text-gray-300">/</span>
          <h1 className="text-xl font-bold text-gray-900">Seccion {section}</h1>
        </div>
        <div className="flex items-center gap-2">
          {preliminaryMode && evidenceMissing && (
            <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">
              Modo preliminar
            </span>
          )}
          {isReadOnly && (
            <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">
              Solo lectura
            </span>
          )}
        </div>
      </div>

      {preliminaryMode && evidenceMissing && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          Esta seccion se esta revisando en modo preliminar porque falta evidencia de celdas
          escaneadas.
        </div>
      )}

      {patternLoading && !patternData ? (
        <div className="h-28 rounded-lg border border-gray-200 bg-white p-5 animate-pulse" />
      ) : null}

      {!patternLoading && patternData && !showAdvanced && (
        <>
          <QuickCalibrationPanel
            sheet={sheet ?? 'A01'}
            section={section ?? 'A'}
            sectionTitle={currentSectionTitle}
            data={patternData}
            readOnly={isReadOnly}
            previousSection={previousSection}
            nextSection={nextSection}
            structureVersion={String(templateId ?? '')}
            candidateSections={candidateSections}
            onOpenAdvanced={() => setShowAdvanced(true)}
            onNavigateSection={navigateToSection}
          />
          <RowFunctionalDecisionTable
            sheet={sheet ?? 'A01'}
            section={section ?? 'A'}
            readOnly={isReadOnly}
          />
        </>
      )}

      {showAdvanced && (
        <>
          <AdvancedTabs tab={tab} setTab={setTab} isReadOnly={isReadOnly} />
          {tab === 'functional' && !isReadOnly ? (
            <SeccionRevisionPage />
          ) : tab === 'matrix' ? (
            <div
              className={isReadOnly ? 'pointer-events-none' : undefined}
              aria-disabled={isReadOnly}
            >
              <SectionCalibrationTable
                data={matrixData}
                loading={matrixLoading}
                sheet={sheet}
                section={section}
              />
            </div>
          ) : (
            <PatternCalibrationSummary
              sheet={sheet ?? 'A01'}
              section={section ?? 'A'}
              readOnly={isReadOnly}
            />
          )}
        </>
      )}
    </div>
  )
}

function isSectionReviewed(questions: CalibrationQuestion[]) {
  return questions.some(
    (question) =>
      (question.id || question.question) === 'section_review' &&
      question.review_status === 'section_reviewed'
  )
}

function AdvancedTabs({
  tab,
  setTab,
  isReadOnly,
}: {
  tab: TabView
  setTab: (tab: TabView) => void
  isReadOnly: boolean
}) {
  return (
    <div className="flex gap-1 border-b border-gray-200">
      {!isReadOnly && (
        <TabButton active={tab === 'functional'} onClick={() => setTab('functional')}>
          <ListChecks className="h-4 w-4" />
          Revision funcional
        </TabButton>
      )}
      <TabButton active={tab === 'matrix'} onClick={() => setTab('matrix')}>
        <Grid3X3 className="h-4 w-4" />
        Matriz
      </TabButton>
      <TabButton active={tab === 'patterns'} onClick={() => setTab('patterns')}>
        <LayoutGrid className="h-4 w-4" />
        Patrones y formulas
      </TabButton>
    </div>
  )
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

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors ${
        active
          ? 'border-indigo-500 text-indigo-700'
          : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
      }`}
    >
      {children}
    </button>
  )
}
