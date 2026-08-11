import { useMemo } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, ChevronRight, Clock, FileSpreadsheet } from 'lucide-react'
import { structuresService } from '../services/structures'
import { useCalibrationSummary } from '../hooks/useCalibrationSummary'
import {
  getCalibrationProgressLabel,
  getCalibrationProgressStyle,
  NO_UTILIZADA_LABEL,
  NO_UTILIZADA_STYLE,
} from '../utils/labels'
import type { CalibrationNoUtilizadaSheet, CalibrationSheetSummary } from '../types/calibration'

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
  const { data: summary, isLoading: summaryLoading } = useCalibrationSummary()
  const sheetSummaries = useMemo(() => {
    if (structureId !== summary?.structure_id) return new Map<string, CalibrationSheetSummary>()
    return new Map(summary.sheets.map((s) => [s.sheet_name, s]))
  }, [structureId, summary])
  // Hojas 'no_utilizada' (ver RemSheetUsageStatusService) -- NUNCA aparecen
  // en sheetSummaries (quedan fuera del cálculo de progreso), se buscan
  // aparte para poder distinguirlas visualmente de "no aplica" (que
  // significa "no es la estructura activa", un caso distinto).
  const noUtilizadaSheets = useMemo(() => {
    if (structureId !== summary?.structure_id) return new Map<string, CalibrationNoUtilizadaSheet>()
    return new Map(summary.no_utilizadas.map((s) => [s.sheet_name, s]))
  }, [structureId, summary])

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
            const sheetSummary = sheetSummaries.get(form.sheetName)
            const noUtilizada = noUtilizadaSheets.get(form.sheetName)
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
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
                      Hoja REM
                    </p>
                    <h2 className="mt-1 text-lg font-semibold text-slate-900">{form.sheetName}</h2>
                  </div>
                  {summaryLoading ? (
                    <FileSpreadsheet className="h-5 w-5 text-indigo-500" />
                  ) : noUtilizada ? (
                    <span
                      className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${NO_UTILIZADA_STYLE}`}
                    >
                      {NO_UTILIZADA_LABEL}
                    </span>
                  ) : sheetSummary ? (
                    <span
                      className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${getCalibrationProgressStyle(sheetSummary.status)}`}
                    >
                      {sheetSummary.status === 'completada' ? (
                        <CheckCircle2 className="h-3 w-3" />
                      ) : (
                        <Clock className="h-3 w-3" />
                      )}
                      {getCalibrationProgressLabel(sheetSummary.status)}
                    </span>
                  ) : (
                    <FileSpreadsheet className="h-5 w-5 text-indigo-500" />
                  )}
                </div>
                {noUtilizada ? (
                  <div className="mt-5 text-sm">
                    <CardMetric label="Secciones" value={String(sectionsCount)} />
                    <p className="mt-3 text-xs text-slate-400">
                      No utilizada por Estadística APS
                      {noUtilizada.reason ? ` · ${noUtilizada.reason}` : ''}
                    </p>
                  </div>
                ) : (
                  <div className="mt-5 grid grid-cols-3 gap-3 text-sm">
                    <CardMetric
                      label="Avance"
                      value={sheetSummary ? `${sheetSummary.progress_pct}%` : 'Sin datos'}
                    />
                    <CardMetric label="Secciones" value={String(sectionsCount)} />
                    <CardMetric
                      label="Pendientes"
                      value={sheetSummary ? String(sheetSummary.sections_pending) : 'Sin datos'}
                    />
                  </div>
                )}
                <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                  <span className="text-xs text-slate-500">
                    {summaryLoading
                      ? 'Calculando progreso...'
                      : noUtilizada
                        ? 'No forma parte del progreso de calibración'
                        : !sheetSummary
                          ? 'No aplica — no es la estructura activa'
                          : `${sheetSummary.sections_completed}/${sheetSummary.sections_total} secciones completadas${
                              sheetSummary.sections_not_calibratable > 0
                                ? ` · ${sheetSummary.sections_not_calibratable} no requiere calibración`
                                : ''
                            }`}
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
