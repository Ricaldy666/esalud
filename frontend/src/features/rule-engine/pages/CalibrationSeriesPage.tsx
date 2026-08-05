import { useMemo } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ChevronRight, FileSpreadsheet } from 'lucide-react'
import { structuresService } from '../services/structures'

const A01_SECTION_CODES = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L']

export default function CalibrationSeriesPage() {
  const { templateId, series } = useParams<{ templateId: string; series: string }>()
  const navigate = useNavigate()
  const structureId = Number(templateId)

  const { data: structure, isLoading } = useQuery({
    queryKey: ['calibration-template', structureId],
    queryFn: () => structuresService.get(structureId),
    enabled: Number.isFinite(structureId),
  })

  const forms = useMemo(() => structure?.forms_detail ?? [], [structure])

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => navigate(`/calibracion/templates/${templateId}`)}
          className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
        >
          <ArrowLeft className="h-4 w-4" />
          Plantilla
        </button>
        <span className="text-sm text-slate-300">/</span>
        <h1 className="text-xl font-bold text-slate-900">Serie {series}</h1>
      </div>

      {isLoading ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[1, 2, 3].map((item) => (
            <div
              key={item}
              className="h-40 rounded-lg border border-slate-200 bg-white p-5 animate-pulse"
            />
          ))}
        </div>
      ) : !structure ? (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
          No se encontró la serie solicitada.
        </div>
      ) : forms.length === 0 ? (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
          La estructura no contiene hojas detalladas para mostrar.
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {forms.map((form) => {
            const sectionsCount =
              form.sheetName === 'A01'
                ? Math.max(form.sections.length, A01_SECTION_CODES.length)
                : form.sections.length
            return (
              <button
                key={form.sheetName}
                type="button"
                onClick={() =>
                  navigate(
                    `/calibracion/templates/${templateId}/series/${encodeURIComponent(series ?? structure.serie)}/sheets/${encodeURIComponent(form.sheetName)}`
                  )
                }
                className="rounded-lg border border-slate-200 bg-white p-5 text-left shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/30"
              >
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
                      Hoja REM
                    </p>
                    <h2 className="mt-1 text-lg font-semibold text-slate-900">{form.sheetName}</h2>
                  </div>
                  <FileSpreadsheet className="h-5 w-5 text-indigo-500" />
                </div>
                <div className="mt-5 grid grid-cols-3 gap-3 text-sm">
                  <CardMetric label="Avance" value="Sin datos" />
                  <CardMetric label="Secciones" value={String(sectionsCount)} />
                  <CardMetric label="Pendientes" value="Sin datos" />
                </div>
                <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                  <span className="text-xs text-slate-500">
                    Estado general: sin datos suficientes
                  </span>
                  <ChevronRight className="h-4 w-4 text-slate-400" />
                </div>
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

function CardMetric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-slate-400">{label}</p>
      <p className="mt-1 font-medium text-slate-900">{value}</p>
    </div>
  )
}
