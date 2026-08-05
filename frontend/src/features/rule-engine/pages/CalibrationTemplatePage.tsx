import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ChevronRight, Layers3 } from 'lucide-react'
import { structuresService } from '../services/structures'

export default function CalibrationTemplatePage() {
  const { templateId } = useParams<{ templateId: string }>()
  const navigate = useNavigate()
  const structureId = Number(templateId)

  const { data: structure, isLoading } = useQuery({
    queryKey: ['calibration-template', structureId],
    queryFn: () => structuresService.get(structureId),
    enabled: Number.isFinite(structureId),
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => navigate('/calibracion')}
          className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
        >
          <ArrowLeft className="h-4 w-4" />
          Calibración REM
        </button>
        <span className="text-sm text-slate-300">/</span>
        <h1 className="text-xl font-bold text-slate-900">
          {structure ? `Plantilla REM ${structure.anio}` : 'Plantilla REM'}
        </h1>
      </div>

      {isLoading ? (
        <div className="h-32 rounded-lg border border-slate-200 bg-white p-5 animate-pulse" />
      ) : !structure ? (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
          No se encontró la plantilla solicitada.
        </div>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-4 md:grid-cols-3">
            <Summary label="Estado" value={structure.status} />
            <Summary
              label="Hojas"
              value={String(structure.forms_detail?.length ?? structure.stats?.total_forms ?? 0)}
            />
            <Summary label="Secciones" value={String(structure.stats?.total_sections ?? 0)} />
          </div>

          <button
            type="button"
            onClick={() =>
              navigate(
                `/calibracion/templates/${structure.id}/series/${encodeURIComponent(structure.serie)}`
              )
            }
            className="flex w-full items-center justify-between rounded-lg border border-slate-200 bg-white p-5 text-left shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/30"
          >
            <div className="flex items-center gap-4">
              <div className="rounded-lg bg-indigo-50 p-3 text-indigo-600">
                <Layers3 className="h-5 w-5" />
              </div>
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
                  Serie disponible
                </p>
                <h2 className="mt-1 text-lg font-semibold text-slate-900">
                  Serie {structure.serie}
                </h2>
                <p className="mt-1 text-xs text-slate-500">Progreso: sin datos suficientes</p>
              </div>
            </div>
            <ChevronRight className="h-5 w-5 text-slate-400" />
          </button>
        </div>
      )}
    </div>
  )
}

function Summary({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-1 text-lg font-semibold text-slate-900">{value}</p>
    </div>
  )
}
