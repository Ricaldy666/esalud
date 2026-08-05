import React, { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronDown, ChevronRight, CheckCircle2 } from 'lucide-react'
import type { FormSummary } from '../types/validation'

interface Props {
  forms: FormSummary[]
  uploadId: number
  onFormClick?: (form: string) => void
  activeForm?: string | null
}

const cumplimientoColor = (v: number | null) => {
  if (v === null) return 'text-slate-400'
  if (v >= 95) return 'text-green-600'
  if (v >= 80) return 'text-yellow-600'
  return 'text-red-600'
}

type SortBucket = 'errores' | 'parcial' | 'completo' | 'noaplica'

const sortBucket = (f: FormSummary): SortBucket => {
  if (f.cumplimiento === null) return 'noaplica'
  if (f.failed > 0) return 'errores'
  if (f.cumplimiento < 100) return 'parcial'
  return 'completo'
}

const sortForms = (a: FormSummary, b: FormSummary): number => {
  const order: Record<SortBucket, number> = { errores: 0, parcial: 1, completo: 2, noaplica: 3 }
  const ba = sortBucket(a)
  const bb = sortBucket(b)
  if (order[ba] !== order[bb]) return order[ba] - order[bb]
  if (ba === 'errores' || ba === 'parcial') return b.failed - a.failed
  return a.form.localeCompare(b.form)
}

export const FormBreakdownTable: React.FC<Props> = ({ forms, uploadId, activeForm }) => {
  const navigate = useNavigate()
  const [showCorrectForms, setShowCorrectForms] = useState(false)

  const sorted = [...forms].sort(sortForms)
  const formsWithErrors = sorted.filter((f) => sortBucket(f) === 'errores')
  const formsWithoutErrors = sorted.filter((f) => sortBucket(f) !== 'errores')

  return (
    <div className="space-y-3">
      {/* Summary of forms needing review */}
      {formsWithErrors.length > 0 && (
        <p className="text-xs text-amber-700 font-medium">
          {formsWithErrors.length} formulario(s) requieren revisión
        </p>
      )}
      {formsWithoutErrors.length > 0 && (
        <p className="text-xs text-slate-500">
          {formsWithoutErrors.length} formulario(s) sin errores
        </p>
      )}

      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">
                Formulario
              </th>
              <th className="px-4 py-2 text-center text-xs font-medium text-slate-500 uppercase">
                Total
              </th>
              <th className="px-4 py-2 text-center text-xs font-medium text-green-600 uppercase">
                Cumplen
              </th>
              <th className="px-4 py-2 text-center text-xs font-medium text-red-600 uppercase">
                Incumplen
              </th>
              <th className="px-4 py-2 text-center text-xs font-medium text-slate-500 uppercase">
                No aplica
              </th>
              <th className="px-4 py-2 text-center text-xs font-medium text-slate-500 uppercase">
                Cumplimiento
              </th>
              <th className="px-4 py-2 text-center text-xs font-medium text-slate-500 uppercase">
                Acción
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {/* Forms with errors first */}
            {formsWithErrors.map((f) => (
              <tr
                key={f.form}
                className={`bg-amber-50/40 ${activeForm === f.form ? 'bg-blue-50' : ''}`}
              >
                <td className="px-4 py-2 text-sm font-medium text-slate-900">{f.form}</td>
                <td className="px-4 py-2 text-sm text-center text-slate-600">{f.total}</td>
                <td className="px-4 py-2 text-sm text-center text-green-600">{f.passed}</td>
                <td className="px-4 py-2 text-sm text-center text-red-600 font-medium">
                  {f.failed}
                </td>
                <td className="px-4 py-2 text-sm text-center text-slate-400">{f.skipped}</td>
                <td className="px-4 py-2 text-sm text-center font-medium">
                  <span className={cumplimientoColor(f.cumplimiento)}>
                    {f.cumplimiento !== null ? `${f.cumplimiento}%` : '—'}
                  </span>
                </td>
                <td className="px-4 py-2 text-center">
                  {f.failed > 0 ? (
                    <button
                      onClick={(e) => {
                        e.stopPropagation()
                        navigate(
                          `/rule-engine/uploads/${uploadId}/validation-errors?form=${f.form}`
                        )
                      }}
                      className="text-xs font-medium text-amber-700 hover:text-amber-900 underline transition-colors"
                    >
                      Revisar {f.failed} error(es)
                    </button>
                  ) : f.passed > 0 ? (
                    <span className="inline-flex items-center gap-1 text-xs text-green-700">
                      <CheckCircle2 className="w-3 h-3" /> Correcto
                    </span>
                  ) : (
                    <span className="text-xs text-slate-400">Sin reglas aplicables</span>
                  )}
                </td>
              </tr>
            ))}

            {/* Collapsible section for forms without errors */}
            {showCorrectForms &&
              formsWithoutErrors.map((f) => (
                <tr key={f.form} className={activeForm === f.form ? 'bg-blue-50' : 'bg-white'}>
                  <td className="px-4 py-2 text-sm font-medium text-slate-900">{f.form}</td>
                  <td className="px-4 py-2 text-sm text-center text-slate-600">{f.total}</td>
                  <td className="px-4 py-2 text-sm text-center text-green-600">{f.passed}</td>
                  <td className="px-4 py-2 text-sm text-center text-slate-400">0</td>
                  <td className="px-4 py-2 text-sm text-center text-slate-400">{f.skipped}</td>
                  <td className="px-4 py-2 text-sm text-center font-medium">
                    <span className={cumplimientoColor(f.cumplimiento)}>
                      {f.cumplimiento !== null ? `${f.cumplimiento}%` : '—'}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-center">
                    {f.passed > 0 ? (
                      <span className="inline-flex items-center gap-1 text-xs text-green-700">
                        <CheckCircle2 className="w-3 h-3" /> Correcto
                      </span>
                    ) : (
                      <span className="text-xs text-slate-400">Sin reglas aplicables</span>
                    )}
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {/* Toggle for correct forms */}
      {formsWithoutErrors.length > 0 && (
        <button
          onClick={() => setShowCorrectForms(!showCorrectForms)}
          className="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 transition-colors"
        >
          {showCorrectForms ? (
            <ChevronDown className="w-3 h-3" />
          ) : (
            <ChevronRight className="w-3 h-3" />
          )}
          {showCorrectForms
            ? 'Ocultar formularios correctos'
            : `Mostrar ${formsWithoutErrors.length} formularios sin errores`}
        </button>
      )}
    </div>
  )
}
