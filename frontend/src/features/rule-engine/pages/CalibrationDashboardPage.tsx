import { useNavigate } from 'react-router-dom'
import {
  CalendarDays,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  FileSpreadsheet,
  Layers3,
} from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { useStructures } from '../hooks/useStructures'
import { useCalibrationSummary } from '../hooks/useCalibrationSummary'
import { getStructureStatusLabel, getStructureStatusStyle } from '../utils/labels'
import type { Structure } from '../types/structure'
import type { CalibrationStructureTotals } from '../types/calibration'

export default function CalibrationDashboardPage() {
  const navigate = useNavigate()
  const { data, isLoading } = useStructures({ per_page: 100 })
  const { data: summary, isLoading: summaryLoading } = useCalibrationSummary()
  const visibleStructures =
    data?.data.filter((structure) => structure.status !== 'superseded') ?? []

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Calibración REM"
        description="Selecciona una plantilla REM para revisar sus series, hojas y secciones calibrables"
        icon={ClipboardCheck}
      />

      {isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[1, 2, 3].map((item) => (
            <div
              key={item}
              className="h-44 rounded-lg border border-slate-200 bg-white p-5 animate-pulse"
            >
              <div className="h-5 w-36 rounded bg-slate-100" />
              <div className="mt-5 h-3 w-full rounded bg-slate-100" />
              <div className="mt-3 h-3 w-2/3 rounded bg-slate-100" />
            </div>
          ))}
        </div>
      ) : visibleStructures.length === 0 ? (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center">
          <FileSpreadsheet className="mx-auto h-8 w-8 text-slate-300" />
          <p className="mt-3 text-sm font-medium text-slate-700">
            No hay plantillas REM disponibles
          </p>
          <p className="mt-1 text-xs text-slate-500">
            Cuando existan estructuras REM activas o en borrador aparecerán aquí.
          </p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {visibleStructures.map((structure) => (
            <TemplateCard
              key={structure.id}
              structure={structure}
              totals={structure.id === summary?.structure_id ? summary.totals : undefined}
              loadingProgress={summaryLoading}
              onOpen={() => navigate(`/calibracion/templates/${structure.id}`)}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function TemplateCard({
  structure,
  totals,
  loadingProgress,
  onOpen,
}: {
  structure: Structure
  totals?: CalibrationStructureTotals
  loadingProgress: boolean
  onOpen: () => void
}) {
  const forms = structure.forms_detail?.length ?? structure.stats?.total_forms ?? 0
  const sections =
    structure.stats?.total_sections ??
    structure.forms_detail?.reduce((total, form) => total + form.sections.length, 0) ??
    0

  return (
    <button
      type="button"
      onClick={onOpen}
      className="rounded-lg border border-slate-200 bg-white p-5 text-left shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/30"
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
            Plantilla REM
          </p>
          <h2 className="mt-1 text-lg font-semibold text-slate-900">{structure.anio}</h2>
        </div>
        <span
          className={`rounded-full border px-2 py-0.5 text-xs font-medium ${getStructureStatusStyle(structure.status)}`}
        >
          {getStructureStatusLabel(structure.status)}
        </span>
      </div>

      <div className="mt-5 grid grid-cols-3 gap-3 text-sm">
        <Metric icon={Layers3} label="Serie" value={structure.serie} />
        <Metric icon={FileSpreadsheet} label="Hojas" value={String(forms)} />
        <Metric icon={CalendarDays} label="Secciones" value={String(sections)} />
      </div>

      <div className="mt-5 border-t border-slate-100 pt-4">
        {loadingProgress ? (
          <p className="text-xs text-slate-400">Calculando progreso...</p>
        ) : !totals ? (
          <p className="text-xs text-slate-400">No aplica — no es la estructura activa</p>
        ) : (
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-600">Progreso de calibración</span>
              <span className="text-sm font-semibold text-slate-900">{totals.progress_pct}%</span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
              <div
                className="h-full rounded-full bg-emerald-500"
                style={{ width: `${totals.progress_pct}%` }}
              />
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
              <span>
                {totals.sheets_completed}/{totals.sheets_total} hojas completadas
              </span>
              <span>
                {totals.sections_completed}/{totals.sections_aplicables} secciones
              </span>
              {totals.sections_not_calibratable > 0 && (
                <span className="inline-flex items-center gap-1 text-slate-400">
                  <CheckCircle2 className="h-3 w-3" />
                  {totals.sections_not_calibratable} no requiere calibración
                </span>
              )}
              {totals.sections_pending > 0 && <span>{totals.sections_pending} pendientes</span>}
            </div>
            {totals.sheets_no_utilizadas > 0 && (
              <p className="text-xs text-slate-400">
                {totals.sheets_no_utilizadas} hojas no utilizadas por Estadística APS ·{' '}
                {totals.sections_no_utilizadas} secciones
              </p>
            )}
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center justify-end">
        <ChevronRight className="h-4 w-4 text-slate-400" />
      </div>
    </button>
  )
}

function Metric({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Layers3
  label: string
  value: string
}) {
  return (
    <div>
      <div className="flex items-center gap-1.5 text-slate-400">
        <Icon className="h-3.5 w-3.5" />
        <span className="text-xs">{label}</span>
      </div>
      <p className="mt-1 font-medium text-slate-900">{value}</p>
    </div>
  )
}
