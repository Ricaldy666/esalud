import { CheckCircle2, AlertTriangle, AlertCircle, ExternalLink, Upload, Info } from 'lucide-react'
import type { RemUpload } from '../types/rem'
import { Button } from '@/shared/components/ui/button'

interface RemUploadResultCardProps {
  upload: RemUpload
  validationSummary: {
    total_rules: number
    applicable?: number
    passed: number
    failed: number
    compliance: number | null
  } | null
  onViewSummary: (id: number) => void
  onUploadAnother: () => void
}

export function RemUploadResultCard({
  upload,
  validationSummary,
  onViewSummary,
  onUploadAnother,
}: RemUploadResultCardProps) {
  const isSuccess = upload.status === 'success'
  const isRejected = upload.status === 'rejected'
  const hasFailed = upload.status === 'failed'
  const hasObservations = upload.status === 'with_errors'

  const applicable =
    validationSummary?.applicable ??
    (validationSummary ? validationSummary.passed + validationSummary.failed : 0)
  const notApplicable = validationSummary ? validationSummary.total_rules - applicable : 0
  const compliance = validationSummary?.compliance ?? null
  const noRulesImplemented = !validationSummary || validationSummary.total_rules === 0

  const monthLabel = (() => {
    const months = [
      'Enero',
      'Febrero',
      'Marzo',
      'Abril',
      'Mayo',
      'Junio',
      'Julio',
      'Agosto',
      'Septiembre',
      'Octubre',
      'Noviembre',
      'Diciembre',
    ]
    return `${months[upload.month - 1] || upload.month} ${upload.year}`
  })()

  const cardBg = isSuccess
    ? 'bg-emerald-50 border-emerald-200'
    : hasObservations
      ? 'bg-amber-50 border-amber-200'
      : 'bg-rose-50 border-rose-200'

  return (
    <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
      {/* Status banner */}
      <div className={`flex items-start gap-4 mb-6 p-4 rounded-lg border ${cardBg}`}>
        <div className="p-2 rounded-full bg-white">
          {isSuccess ? (
            <CheckCircle2 className="w-6 h-6 text-emerald-600" />
          ) : hasObservations ? (
            <AlertTriangle className="w-6 h-6 text-amber-600" />
          ) : (
            <AlertCircle className="w-6 h-6 text-rose-600" />
          )}
        </div>
        <div>
          <h3 className="text-lg font-bold text-slate-900">
            {isSuccess
              ? 'Archivo procesado correctamente'
              : hasObservations
                ? 'Archivo procesado con observaciones'
                : isRejected
                  ? 'Estructura no disponible'
                  : 'Error en el procesamiento del archivo'}
          </h3>
          <p className="text-sm text-slate-500 mt-1">
            {upload.original_filename} — {upload.health_center?.name ?? '—'} — {monthLabel}
          </p>
        </div>
      </div>

      {noRulesImplemented ? (
        <div className="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
          <div className="flex items-start gap-3">
            <Info className="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-medium text-blue-800">Archivo cargado correctamente</p>
              <p className="text-sm text-blue-700 mt-1">
                La validación automática de esta serie aún no está implementada. Las reglas de
                consistencia se aplicarán cuando estén disponibles.
              </p>
            </div>
          </div>
        </div>
      ) : (
        <>
          {/* Summary metrics */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
            <div className="p-3 bg-slate-50 rounded-lg text-center">
              <p className="text-2xl font-bold text-slate-900">
                {validationSummary?.total_rules ?? '—'}
              </p>
              <p className="text-xs text-slate-500 mt-0.5">Reglas evaluadas</p>
            </div>
            <div className="p-3 bg-emerald-50 rounded-lg text-center">
              <p className="text-2xl font-bold text-emerald-700">
                {validationSummary?.passed ?? '—'}
              </p>
              <p className="text-xs text-emerald-600 mt-0.5">Cumplen</p>
            </div>
            <div
              className={`p-3 rounded-lg text-center ${validationSummary && validationSummary.failed > 0 ? 'bg-amber-50' : 'bg-slate-50'}`}
            >
              <p
                className={`text-2xl font-bold ${validationSummary && validationSummary.failed > 0 ? 'text-amber-700' : 'text-slate-400'}`}
              >
                {validationSummary?.failed ?? '—'}
              </p>
              <p
                className={`text-xs mt-0.5 ${validationSummary && validationSummary.failed > 0 ? 'text-amber-600' : 'text-slate-400'}`}
              >
                Incumplen
              </p>
            </div>
            <div
              className={`p-3 rounded-lg text-center ${compliance !== null && compliance >= 95 ? 'bg-emerald-50' : compliance !== null && compliance >= 80 ? 'bg-amber-50' : compliance !== null ? 'bg-rose-50' : 'bg-slate-50'}`}
            >
              <p
                className={`text-2xl font-bold ${compliance !== null && compliance >= 95 ? 'text-emerald-700' : compliance !== null && compliance >= 80 ? 'text-amber-700' : compliance !== null ? 'text-rose-700' : 'text-slate-400'}`}
              >
                {compliance !== null ? `${compliance.toFixed(1)}%` : '—'}
              </p>
              <p className="text-xs text-slate-500 mt-0.5">Cumplimiento sobre reglas aplicables</p>
            </div>
          </div>

          {/* Detail text */}
          {applicable > 0 && (
            <p className="text-xs text-slate-500 mb-4">
              {applicable} regla(s) aplicable(s): {validationSummary?.passed ?? 0} cumplen y{' '}
              {validationSummary?.failed ?? 0} incumplen.
              {notApplicable > 0 && ` ${notApplicable} regla(s) no aplican a este archivo.`}
            </p>
          )}
        </>
      )}

      <div className="flex flex-wrap gap-2">
        {!hasFailed && !isRejected && !noRulesImplemented && (
          <Button
            variant={hasObservations ? 'default' : 'outline'}
            size="sm"
            onClick={() => onViewSummary(upload.id)}
            className="gap-1.5"
          >
            <ExternalLink className="w-4 h-4" />
            Revisar resultado
          </Button>
        )}
        <Button variant="outline" size="sm" onClick={onUploadAnother} className="gap-1.5">
          <Upload className="w-4 h-4" />
          Cargar otro REM
        </Button>
      </div>
    </div>
  )
}
