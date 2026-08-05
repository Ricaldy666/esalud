import React from 'react'
import type { ExecutiveSummary } from '../types/validation'
import { useNavigate } from 'react-router-dom'

interface Props {
  summary: ExecutiveSummary
  passed: number
  total: number
  uploadId: number
  formFilter?: string
  formStatus?: 'error' | 'warning' | 'success' | null
}

const statusConfig: Record<string, { label: string; bg: string; text: string; dot: string }> = {
  error: { label: 'Requiere corrección', bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-500' },
  warning: {
    label: 'Con advertencias',
    bg: 'bg-amber-50',
    text: 'text-amber-700',
    dot: 'bg-amber-400',
  },
  success: { label: 'Correcto', bg: 'bg-green-50', text: 'text-green-700', dot: 'bg-green-500' },
}

export const ExecutiveSummaryCard: React.FC<Props> = ({
  summary,
  passed,
  total,
  uploadId,
  formFilter,
  formStatus,
}) => {
  const navigate = useNavigate()
  const isValid = total > 0
  const cumplimiento = isValid ? Math.round((passed / total) * 100) : null
  const statusStyle = formStatus ? statusConfig[formStatus] : null

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
      <div className="flex items-center justify-between gap-4">
        <h2 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
          {formFilter ? `Resumen de ${formFilter}` : 'Resumen de validación'}
        </h2>
        {statusStyle && (
          <span
            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ${statusStyle.bg} ${statusStyle.text}`}
          >
            <span className={`w-2 h-2 rounded-full ${statusStyle.dot}`} />
            {statusStyle.label}
          </span>
        )}
      </div>

      <div className="flex flex-wrap gap-6">
        <div className="flex items-center gap-2">
          <span className="text-lg">✔</span>
          <span className="text-sm text-slate-700">{passed} reglas cumplen</span>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-lg">✖</span>
          <span className="text-sm text-red-600 font-medium">
            {summary.total_errors} reglas presentan observaciones
          </span>
        </div>
        {cumplimiento !== null && isValid && (
          <div className="flex items-center gap-2">
            <span className="text-sm text-slate-400">Cumplimiento:</span>
            <span
              className={`text-sm font-bold ${cumplimiento >= 95 ? 'text-green-600' : cumplimiento >= 80 ? 'text-yellow-600' : 'text-red-600'}`}
            >
              {cumplimiento}%
            </span>
          </div>
        )}
      </div>

      {!formFilter && summary.top_forms.length > 0 && (
        <div>
          <h3 className="text-xs font-semibold text-slate-500 uppercase mb-2">
            Formularios con más problemas
          </h3>
          <div className="space-y-1">
            {summary.top_forms.map((f, i) => (
              <div key={f.form} className="flex items-center gap-2 text-sm">
                <span className="text-slate-400 w-4 text-right">{i + 1}.</span>
                <button
                  onClick={() =>
                    navigate(`/rule-engine/uploads/${uploadId}/validation-errors?form=${f.form}`)
                  }
                  className="text-indigo-600 hover:text-indigo-800 underline font-medium"
                >
                  {f.form}
                </button>
                <span className="text-slate-500">({f.count})</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {summary.top_recommendation && (
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800">
          {summary.top_recommendation}
        </div>
      )}
    </div>
  )
}
