import { useNavigate } from 'react-router-dom'
import { CalendarDays, ChevronRight, ClipboardCheck, FileSpreadsheet, Layers3 } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { useStructures } from '../hooks/useStructures'
import type { Structure } from '../types/structure'

const STATUS_LABELS: Record<string, string> = {
  draft: 'Borrador',
  approved: 'Aprobada',
  active: 'Activa',
  superseded: 'Reemplazada',
}

const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-600 border-slate-200',
  approved: 'bg-blue-100 text-blue-700 border-blue-200',
  active: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  superseded: 'bg-amber-100 text-amber-700 border-amber-200',
}

export default function CalibrationDashboardPage() {
  const navigate = useNavigate()
  const { data, isLoading } = useStructures({ per_page: 100 })
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
              onOpen={() => navigate(`/calibracion/templates/${structure.id}`)}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function TemplateCard({ structure, onOpen }: { structure: Structure; onOpen: () => void }) {
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
          className={`rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[structure.status] ?? 'bg-slate-100 text-slate-600 border-slate-200'}`}
        >
          {STATUS_LABELS[structure.status] ?? structure.status}
        </span>
      </div>

      <div className="mt-5 grid grid-cols-3 gap-3 text-sm">
        <Metric icon={Layers3} label="Serie" value={structure.serie} />
        <Metric icon={FileSpreadsheet} label="Hojas" value={String(forms)} />
        <Metric icon={CalendarDays} label="Secciones" value={String(sections)} />
      </div>

      <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
        <span className="text-xs text-slate-500">Progreso: sin datos suficientes</span>
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
