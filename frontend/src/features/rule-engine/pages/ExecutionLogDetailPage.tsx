import { useParams, useNavigate } from 'react-router-dom'
import { useExecutionLog } from '../hooks/useExecutionLog'
import { ExecutionStatusBadge } from '../components/ExecutionStatusBadge'
import { SeverityBadge } from '../components/SeverityBadge'
import { TechnicalInfo } from '../components/TechnicalInfo'
import { PageHeader } from '@/shared/components/PageHeader'
import { Loader2, ArrowLeft, CheckCircle2, XCircle, Clock, FileText } from 'lucide-react'
import { getRuleTypeLabel, getTriggerLabel } from '../utils/labels'

function formatJson(data: unknown): string {
  if (!data) return '—'
  try {
    return JSON.stringify(data, null, 2)
  } catch {
    return String(data)
  }
}

export default function ExecutionLogDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const logId = id ? Number(id) : undefined
  const { data: log, isLoading, isError } = useExecutionLog(logId)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-blue-600" />
      </div>
    )
  }

  if (isError || !log) {
    return (
      <div className="space-y-6">
        <PageHeader title="Detalle de Ejecución" />
        <div className="flex items-center gap-3 mt-8 p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <XCircle className="w-5 h-5 shrink-0" />
          <span className="text-sm">
            No se pudo cargar el registro de ejecución. Verifica que el ID sea correcto.
          </span>
        </div>
      </div>
    )
  }

  const passRate = log.total_rows > 0 ? ((log.passed_rows / log.total_rows) * 100).toFixed(1) : '0'

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <button
          onClick={() => navigate('/rule-engine/logs')}
          className="text-gray-500 hover:text-gray-700 transition-colors"
          title="Volver al historial"
        >
          <ArrowLeft className="h-5 w-5" />
        </button>
        <div>
          <PageHeader title={`Ejecución #${log.id}`} description={log.rule_key} />
        </div>
      </div>

      <div className="bg-white rounded-xl border border-gray-200 p-6">
        <div className="flex flex-wrap items-start gap-6">
          <div className="flex items-center gap-3">
            <div
              className={`p-2.5 rounded-full ${
                log.status === 'passed'
                  ? 'bg-emerald-100'
                  : log.status === 'failed'
                    ? 'bg-rose-100'
                    : 'bg-slate-100'
              }`}
            >
              {log.status === 'passed' ? (
                <CheckCircle2 className="h-6 w-6 text-emerald-600" />
              ) : log.status === 'failed' ? (
                <XCircle className="h-6 w-6 text-rose-600" />
              ) : (
                <Clock className="h-6 w-6 text-slate-400" />
              )}
            </div>
            <div>
              <p className="text-lg font-semibold text-gray-900">
                {log.status === 'passed'
                  ? 'Validación ejecutada correctamente'
                  : log.status === 'failed'
                    ? 'Se encontraron observaciones'
                    : 'Validación no aplica'}
              </p>
              <ExecutionStatusBadge status={log.status} />
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6">
          <div className="bg-gray-50 rounded-lg p-3">
            <p className="text-xs text-gray-500 mb-0.5">Archivo</p>
            <p
              className="text-sm font-medium text-gray-900 truncate"
              title={log.upload?.filename ?? `#${log.rem_upload_id}`}
            >
              {log.upload?.filename ?? `ID: ${log.rem_upload_id}`}
            </p>
          </div>
          <div className="bg-gray-50 rounded-lg p-3">
            <p className="text-xs text-gray-500 mb-0.5">Filas evaluadas</p>
            <p className="text-sm font-medium text-gray-900">{log.total_rows}</p>
          </div>
          <div className="bg-gray-50 rounded-lg p-3">
            <p className="text-xs text-gray-500 mb-0.5">Tiempo</p>
            <p className="text-sm font-medium text-gray-900">{log.execution_ms} ms</p>
          </div>
          <div className={`rounded-lg p-3 ${log.failed_rows > 0 ? 'bg-rose-50' : 'bg-emerald-50'}`}>
            <p className="text-xs text-gray-500 mb-0.5">Resultado</p>
            <p
              className={`text-sm font-semibold ${
                log.failed_rows > 0 ? 'text-rose-600' : 'text-emerald-600'
              }`}
            >
              {log.failed_rows > 0 ? `${log.failed_rows} observación(es)` : 'Sin observaciones'}
            </p>
          </div>
        </div>

        <div className="mt-6 pt-4 border-t border-gray-100">
          <div className="flex items-center justify-between text-sm mb-2">
            <span className="text-gray-500">Cumplimiento</span>
            <span
              className={`font-semibold ${Number(passRate) === 100 ? 'text-emerald-600' : Number(passRate) >= 80 ? 'text-amber-600' : 'text-rose-600'}`}
            >
              {Number(passRate).toFixed(0)}%
            </span>
          </div>
          <div className="flex gap-1 text-xs text-gray-500 mb-1.5">
            <span className="text-emerald-600 font-medium">{log.passed_rows} correctas</span>
            <span>/</span>
            <span className={log.failed_rows > 0 ? 'text-rose-600 font-medium' : ''}>
              {log.failed_rows} con observaciones
            </span>
          </div>
          <div className="w-full bg-gray-200 rounded-full h-2.5">
            <div
              className={`h-2.5 rounded-full transition-all ${Number(passRate) === 100 ? 'bg-emerald-500' : Number(passRate) >= 80 ? 'bg-amber-500' : 'bg-rose-500'}`}
              style={{ width: `${passRate}%` }}
            />
          </div>
        </div>

        {log.error_message && (
          <div className="mt-4 pt-4 border-t border-gray-100">
            <p className="text-xs font-medium text-gray-500 mb-1">Error</p>
            <p className="text-sm text-rose-600 bg-rose-50 rounded-lg p-3">{log.error_message}</p>
          </div>
        )}
      </div>

      <TechnicalInfo title="Información técnica">
        <div className="p-4 space-y-4">
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            <dt className="text-gray-500">Código de Regla</dt>
            <dd>
              {log.rule ? (
                <button
                  onClick={() => navigate(`/rule-engine/rules/${log.rule!.id}`)}
                  className="font-mono text-blue-600 hover:text-blue-800 transition-colors"
                >
                  {log.rule!.rule_key}
                </button>
              ) : (
                <span className="font-mono text-gray-900">{log.rule_key}</span>
              )}
            </dd>
            <dt className="text-gray-500">ID de Ejecución</dt>
            <dd className="text-gray-900 font-mono">#{log.id}</dd>
            <dt className="text-gray-500">Archivo</dt>
            <dd>
              {log.upload ? (
                <span className="text-gray-900">
                  {log.upload.filename} ({log.upload.period})
                </span>
              ) : (
                <span className="text-gray-600">#{log.rem_upload_id}</span>
              )}
            </dd>
            <dt className="text-gray-500">Origen</dt>
            <dd className="text-gray-900 capitalize">{getTriggerLabel(log.triggered_by)}</dd>
            <dt className="text-gray-500">Fecha</dt>
            <dd className="text-gray-900">{new Date(log.created_at).toLocaleString('es-CL')}</dd>
            {log.rule && (
              <>
                <dt className="text-gray-500">Tipo de validación</dt>
                <dd className="text-gray-900">{getRuleTypeLabel(log.rule.rule_type)}</dd>
                <dt className="text-gray-500">Nivel de importancia</dt>
                <dd>
                  <SeverityBadge severity={log.rule.severity} />
                </dd>
              </>
            )}
          </dl>
        </div>
      </TechnicalInfo>

      {log.context && (
        <TechnicalInfo title="Contexto de ejecución">
          <pre className="bg-gray-50 rounded-lg p-4 text-xs font-mono text-gray-700 overflow-auto max-h-80 whitespace-pre-wrap">
            {formatJson(log.context)}
          </pre>
        </TechnicalInfo>
      )}

      <div className="flex gap-3">
        {log.rule && (
          <button
            onClick={() => navigate(`/rule-engine/rules/${log.rule!.id}`)}
            className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors"
          >
            <FileText className="h-4 w-4" />
            Ver Regla de Consistencia
          </button>
        )}
        <button
          onClick={() => navigate(`/rule-engine/logs?rule_key=${log.rule_key}`)}
          className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 bg-gray-50 border border-gray-200 rounded-lg hover:bg-gray-100 transition-colors"
        >
          Ver historial de {log.rule_key}
        </button>
      </div>
    </div>
  )
}
