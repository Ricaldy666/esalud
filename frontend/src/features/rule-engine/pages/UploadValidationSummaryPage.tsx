import React from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useValidationSummary } from '../hooks/useValidationSummary'
import { ComplianceCard } from '../components/ComplianceCard'
import { FormBreakdownTable } from '../components/FormBreakdownTable'
import { SeverityBadge } from '../components/SeverityBadge'
import { ArrowLeft, CheckCircle2, AlertTriangle, XCircle, RefreshCw } from 'lucide-react'

const statusLabel = (s: string): string => {
  const map: Record<string, string> = {
    success: 'Correcto',
    with_errors: 'Con observaciones',
    rejected: 'Rechazado',
    pending: 'Procesando',
    failed: 'Error de procesamiento',
  }
  return map[s] ?? s
}

const UploadValidationSummaryPage: React.FC = () => {
  const navigate = useNavigate()
  const { uploadId } = useParams<{ uploadId: string }>()
  const id = Number(uploadId)
  const { data, loading, error } = useValidationSummary(id)

  if (loading) return <div className="p-6 text-slate-500">Cargando resumen de validación...</div>
  if (error) return <div className="p-6 text-red-600">Error: {error}</div>
  if (!data) return <div className="p-6 text-slate-500">Sin datos</div>

  const status = data.upload.estado_legacy
  const extra = data as typeof data & { applicable?: number; not_applicable?: number }
  const applicable = extra.applicable ?? data.passed + data.failed
  const notApplicable = extra.not_applicable ?? data.skipped

  const statusBannerStyle =
    status === 'success'
      ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
      : status === 'with_errors'
        ? 'bg-amber-50 border-amber-200 text-amber-800'
        : status === 'rejected' || status === 'failed'
          ? 'bg-rose-50 border-rose-200 text-rose-800'
          : 'bg-blue-50 border-blue-200 text-blue-800'

  const StatusIcon =
    status === 'success'
      ? CheckCircle2
      : status === 'with_errors'
        ? AlertTriangle
        : status === 'rejected' || status === 'failed'
          ? XCircle
          : RefreshCw

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      {/* Breadcrumb */}
      <nav className="flex items-center gap-2 text-xs text-slate-500">
        <button
          onClick={() => navigate('/rem-uploads')}
          className="hover:text-slate-700 transition-colors"
        >
          Cargas REM
        </button>
        <span>/</span>
        <span className="text-slate-700 font-medium">Archivo #{uploadId}</span>
        <span>/</span>
        <span className="text-slate-700">Validación</span>
      </nav>

      {/* Header with back button */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate('/rem-uploads')}
            className="p-1.5 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
            title="Volver a Cargas REM"
          >
            <ArrowLeft className="w-4 h-4" />
          </button>
          <div>
            <h1 className="text-xl font-bold text-slate-900">Validación REM</h1>
            <p className="text-sm text-slate-500">
              {data.upload.serie} · {data.upload.periodo} · {data.upload.centro}
            </p>
          </div>
        </div>
      </div>

      {/* Status banner */}
      <div className={`rounded-lg border p-4 ${statusBannerStyle}`}>
        <div className="flex items-center gap-3">
          <StatusIcon className="w-6 h-6 shrink-0" />
          <div>
            <p className="text-base font-bold">{statusLabel(status)}</p>
            <p className="text-sm opacity-80 mt-0.5">{data.upload.filename}</p>
          </div>
        </div>
      </div>

      {/* Summary metrics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <ComplianceCard
          title="Cumplimiento sobre reglas aplicables"
          cumplimiento={data.cumplimiento_porcentaje}
          total={data.total_rules}
          passed={data.passed}
          failed={data.failed}
          skipped={notApplicable}
        />
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <h3 className="text-sm font-medium text-slate-600 mb-1">Archivo</h3>
          <p className="text-sm text-slate-900 break-all">{data.upload.filename}</p>
          <div className="mt-2 text-xs text-slate-500 space-y-1">
            <div>
              Serie: <span className="font-medium text-slate-700">{data.upload.serie}</span>
            </div>
            <div>
              Período: <span className="font-medium text-slate-700">{data.upload.periodo}</span>
            </div>
            <div>
              Establecimiento:{' '}
              <span className="font-medium text-slate-700">{data.upload.centro}</span>
            </div>
            <div>
              Estado:{' '}
              <span
                className={`font-medium ${
                  status === 'success'
                    ? 'text-emerald-600'
                    : status === 'with_errors'
                      ? 'text-amber-600'
                      : 'text-red-600'
                }`}
              >
                {statusLabel(status)}
              </span>
            </div>
          </div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <h3 className="text-sm font-medium text-slate-600 mb-1">Última ejecución</h3>
          <p className="text-sm text-slate-900">
            {data.ultima_ejecucion
              ? new Date(data.ultima_ejecucion).toLocaleString('es-CL')
              : 'Sin ejecuciones'}
          </p>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <h3 className="text-sm font-medium text-slate-600 mb-1">Reglas evaluadas</h3>
          <p className="text-2xl font-bold text-slate-900">{data.total_rules}</p>
          <p className="text-xs text-slate-500 mt-1">
            {applicable} aplicables · {notApplicable} no aplican
          </p>
        </div>
      </div>

      {data.por_severidad.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-700 mb-3">Errores por severidad</h2>
          <div className="flex gap-4">
            {data.por_severidad.map((s) => (
              <div key={s.severidad} className="flex items-center gap-2">
                <SeverityBadge severity={s.severidad} />
                <span className="text-sm text-slate-600">{s.failed} regla(s)</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {data.por_formulario.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <div className="flex items-center justify-between mb-3">
            <h2 className="text-sm font-semibold text-slate-700">Desglose por formulario</h2>
            {data.failed > 0 && (
              <Link
                to={`/rule-engine/uploads/${id}/validation-errors`}
                className="text-xs font-medium text-indigo-600 hover:text-indigo-800 underline"
              >
                Ver todos los errores
              </Link>
            )}
          </div>
          <FormBreakdownTable forms={data.por_formulario} uploadId={id} />
        </div>
      )}
    </div>
  )
}

export default UploadValidationSummaryPage
