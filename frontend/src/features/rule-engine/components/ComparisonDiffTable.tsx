import type { ComparisonDiff } from '../types/comparison'
import { getStatusLabel, getSeverityLabel, getComparisonStatusLabel } from '../utils/labels'

const STATUS_MATCH_STYLES: Record<string, string> = {
  true: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  false: 'bg-rose-100 text-rose-700 border-rose-200',
}

export function ComparisonDiffTable({ differences }: { differences: ComparisonDiff[] }) {
  if (differences.length === 0) return null

  return (
    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div className="px-6 py-4 border-b border-gray-100">
        <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider">
          Diferencias ({differences.length})
        </h3>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-200 bg-gray-50 text-left text-xs text-gray-500 uppercase">
              <th className="px-4 py-3 font-medium">Formulario</th>
              <th className="px-4 py-3 font-medium">Sección</th>
              <th className="px-4 py-3 font-medium">Código de Regla</th>
              <th className="px-4 py-3 font-medium">Tipo</th>
              <th className="px-4 py-3 font-medium">Nivel de importancia</th>
              <th className="px-4 py-3 font-medium">Anterior</th>
              <th className="px-4 py-3 font-medium">Actual</th>
              <th className="px-4 py-3 font-medium">Resultado</th>
              <th className="px-4 py-3 font-medium">Filas</th>
              <th className="px-4 py-3 font-medium">Observaciones</th>
            </tr>
          </thead>
          <tbody>
            {differences.map((diff, idx) => (
              <tr key={diff.comp_key ?? idx} className="border-b border-gray-100 hover:bg-gray-50">
                <td className="px-4 py-2.5 font-mono text-xs text-gray-700">{diff.sheet}</td>
                <td className="px-4 py-2.5 text-xs text-gray-600">{diff.section}</td>
                <td className="px-4 py-2.5 font-mono text-xs text-gray-900">{diff.new_key}</td>
                <td className="px-4 py-2.5 text-xs text-gray-600">{diff.tipo}</td>
                <td className="px-4 py-2.5">
                  <span
                    className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
                      diff.severity === 'error'
                        ? 'bg-rose-50 text-rose-600 border-rose-200'
                        : diff.severity === 'warning'
                          ? 'bg-amber-50 text-amber-600 border-amber-200'
                          : 'bg-gray-50 text-gray-500 border-gray-200'
                    }`}
                  >
                    {getSeverityLabel(diff.severity)}
                  </span>
                </td>
                <td className="px-4 py-2.5 text-xs">
                  <span
                    className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
                      diff.legacy.status === 'passed'
                        ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                        : diff.legacy.status === 'failed'
                          ? 'bg-rose-50 text-rose-600 border-rose-200'
                          : 'bg-gray-50 text-gray-500 border-gray-200'
                    }`}
                  >
                    {getStatusLabel(diff.legacy.status)}
                  </span>
                </td>
                <td className="px-4 py-2.5 text-xs">
                  <span
                    className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
                      diff.engine.status === 'passed'
                        ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                        : diff.engine.status === 'failed'
                          ? 'bg-rose-50 text-rose-600 border-rose-200'
                          : 'bg-gray-50 text-gray-500 border-gray-200'
                    }`}
                  >
                    {getStatusLabel(diff.engine.status)}
                  </span>
                </td>
                <td className="px-4 py-2.5">
                  <span
                    className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
                      STATUS_MATCH_STYLES[String(diff.status_match)]
                    }`}
                  >
                    {getComparisonStatusLabel(String(diff.status_match))}
                  </span>
                </td>
                <td className="px-4 py-2.5 text-xs text-gray-700 tabular-nums">
                  {diff.legacy.total_rows} vs {diff.engine.total_rows}
                </td>
                <td className="px-4 py-2.5 text-xs text-gray-700 tabular-nums">
                  {diff.legacy.failed_rows} vs {diff.engine.failed_rows}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
