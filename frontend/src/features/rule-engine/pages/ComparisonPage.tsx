import { useState } from 'react'
import { useComparison } from '../hooks/useComparison'
import { ComparisonDiffTable } from '../components/ComparisonDiffTable'
import { HelpTooltip } from '../components/HelpTooltip'
import { TechnicalInfo } from '../components/TechnicalInfo'
import { PageHeader } from '@/shared/components/PageHeader'
import { Loader2, AlertTriangle, CheckCircle2, GitCompareArrows } from 'lucide-react'
import { getHelpText, getStatusLabel, getStructureStatusLabel } from '../utils/labels'

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'

export default function ComparisonPage() {
  const [structureId, setStructureId] = useState('')
  const [uploadId, setUploadId] = useState('')
  const { mutate, data: result, isPending, isError, error } = useComparison()

  const handleCompare = () => {
    if (!structureId || !uploadId) return
    mutate({ structureId: Number(structureId), uploadId: Number(uploadId) })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <PageHeader
          title="Comparación de Resultados"
          description="Compara los resultados del motor actual con el sistema anterior para verificar su precisión"
        />
        <HelpTooltip text={getHelpText('comparison') ?? ''} />
      </div>

      {/* Form */}
      <div className="bg-white rounded-xl border border-gray-200 p-6">
        <div className="flex flex-wrap gap-4 items-end">
          <div>
            <label className="text-xs font-medium text-gray-500 mb-1 block">ID de Estructura</label>
            <input
              type="number"
              className={SELECT_CLASS + ' w-32'}
              placeholder="7"
              value={structureId}
              onChange={(e) => setStructureId(e.target.value)}
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-500 mb-1 block">ID de Archivo</label>
            <input
              type="number"
              className={SELECT_CLASS + ' w-32'}
              placeholder="1"
              value={uploadId}
              onChange={(e) => setUploadId(e.target.value)}
            />
          </div>
          <button
            onClick={handleCompare}
            disabled={isPending || !structureId || !uploadId}
            className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <GitCompareArrows className="h-4 w-4" />
            )}
            {isPending ? 'Comparando...' : 'Comparar'}
          </button>
        </div>
      </div>

      {/* Error */}
      {isError && (
        <div className="flex items-center gap-3 p-4 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <AlertTriangle className="h-5 w-5 shrink-0" />
          <span className="text-sm">{error?.message ?? 'Error al ejecutar la comparación'}</span>
        </div>
      )}

      {/* Results */}
      {result && result.summary && (
        <>
          {/* Context */}
          <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-3">
            <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">
              Contexto
            </h3>
            <dl className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
              <div>
                <dt className="text-gray-500">Estructura</dt>
                <dd className="text-gray-900 font-medium">
                  {result.structure
                    ? `${result.structure.serie} ${result.structure.anio} v${result.structure.version_number} (${getStructureStatusLabel(result.structure.status)})`
                    : `#${result.structure_id}`}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500">Archivo</dt>
                <dd className="text-gray-900 font-medium">
                  {result.upload
                    ? `${result.upload.filename} (${result.upload.period})`
                    : `#${result.upload_id}`}
                </dd>
              </div>
              <div>
                <dt className="text-gray-500">ID de Archivo</dt>
                <dd className="text-gray-900 font-mono">#{result.upload_id}</dd>
              </div>
              <div>
                <dt className="text-gray-500">Tiempo</dt>
                <dd className="text-gray-900 font-medium">{result.execution_time_ms} ms</dd>
              </div>
            </dl>
          </div>

          {/* Summary cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white rounded-xl border border-gray-200 p-4 text-center">
              <div className="flex justify-center mb-2">
                <span
                  className={`inline-flex items-center justify-center w-10 h-10 rounded-full ${
                    result.summary.match_percentage === 100 ? 'bg-emerald-100' : 'bg-amber-100'
                  }`}
                >
                  {result.summary.match_percentage === 100 ? (
                    <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                  ) : (
                    <AlertTriangle className="h-5 w-5 text-amber-600" />
                  )}
                </span>
              </div>
              <p className="text-2xl font-bold tabular-nums text-gray-900">
                {result.summary.match_percentage}%
              </p>
              <p className="text-xs text-gray-500 mt-1">Precisión</p>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-4 text-center">
              <div className="flex justify-center mb-2">
                <span className="inline-flex items-center justify-center w-10 h-10 rounded-full bg-blue-100">
                  <GitCompareArrows className="h-5 w-5 text-blue-600" />
                </span>
              </div>
              <p className="text-2xl font-bold tabular-nums text-gray-900">
                {result.summary.total_rules_in_map}
              </p>
              <p className="text-xs text-gray-500 mt-1">Reglas</p>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-4 text-center">
              <div className="flex justify-center mb-2">
                <span
                  className={`inline-flex items-center justify-center w-10 h-10 rounded-full ${
                    result.summary.match_count > 0 ? 'bg-emerald-100' : 'bg-gray-100'
                  }`}
                >
                  <CheckCircle2
                    className={`h-5 w-5 ${result.summary.match_count > 0 ? 'text-emerald-600' : 'text-gray-400'}`}
                  />
                </span>
              </div>
              <p className="text-2xl font-bold tabular-nums text-emerald-600">
                {result.summary.match_count}
              </p>
              <p className="text-xs text-gray-500 mt-1">Coincidencias</p>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 p-4 text-center">
              <div className="flex justify-center mb-2">
                <span
                  className={`inline-flex items-center justify-center w-10 h-10 rounded-full ${
                    result.summary.difference_count > 0 ? 'bg-rose-100' : 'bg-gray-100'
                  }`}
                >
                  <AlertTriangle
                    className={`h-5 w-5 ${result.summary.difference_count > 0 ? 'text-rose-600' : 'text-gray-400'}`}
                  />
                </span>
              </div>
              <p
                className={`text-2xl font-bold tabular-nums ${result.summary.difference_count > 0 ? 'text-rose-600' : 'text-gray-900'}`}
              >
                {result.summary.difference_count}
              </p>
              <p className="text-xs text-gray-500 mt-1">Diferencias</p>
            </div>
          </div>

          {/* Legacy vs Engine stats */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div className="bg-white rounded-xl border border-gray-200 p-5">
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
                Sistema Anterior
              </h3>
              <div className="space-y-2">
                {Object.entries(result.summary.legacy).map(([status, count]) => (
                  <div key={status} className="flex items-center justify-between">
                    <span className="text-sm text-gray-700 font-medium">
                      {getStatusLabel(status)}
                    </span>
                    <span
                      className={`text-sm tabular-nums font-semibold ${
                        status === 'passed'
                          ? 'text-emerald-600'
                          : status === 'failed'
                            ? 'text-rose-600'
                            : 'text-gray-500'
                      }`}
                    >
                      {count}
                    </span>
                  </div>
                ))}
              </div>
            </div>
            <div className="bg-white rounded-xl border border-gray-200 p-5">
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
                Motor Actual
              </h3>
              <div className="space-y-2">
                {Object.entries(result.summary.engine).map(([status, count]) => (
                  <div key={status} className="flex items-center justify-between">
                    <span className="text-sm text-gray-700 font-medium">
                      {getStatusLabel(status)}
                    </span>
                    <span
                      className={`text-sm tabular-nums font-semibold ${
                        status === 'passed'
                          ? 'text-emerald-600'
                          : status === 'failed'
                            ? 'text-rose-600'
                            : 'text-gray-500'
                      }`}
                    >
                      {count}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Diff table */}
          <ComparisonDiffTable differences={result.differences} />

          {/* JSON viewer inside TechnicalInfo */}
          <TechnicalInfo title="Reporte completo">
            <div className="p-4">
              <pre className="bg-gray-50 rounded-lg p-4 text-xs font-mono text-gray-700 overflow-auto max-h-96 whitespace-pre-wrap">
                {JSON.stringify(result, null, 2)}
              </pre>
            </div>
          </TechnicalInfo>
        </>
      )}
    </div>
  )
}
