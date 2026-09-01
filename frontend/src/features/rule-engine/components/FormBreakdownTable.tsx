import React, { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronDown, ChevronRight, CheckCircle2 } from 'lucide-react'
import { DataTable } from '@/shared/components/DataTable'
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
  // `sorted` ya agrupa errores primero (ver order en sortForms) -- concatenar
  // ambos grupos reproduce exactamente el mismo orden que `sorted`.
  const visibleForms = showCorrectForms ? sorted : formsWithErrors

  const columns = useMemo<ColumnDef<FormSummary>[]>(
    () => [
      {
        header: 'Formulario',
        accessorKey: 'form',
        cell: ({ row }) => (
          <span className="text-sm font-medium text-slate-900">{row.original.form}</span>
        ),
      },
      {
        header: () => <div className="text-center">Total</div>,
        accessorKey: 'total',
        cell: ({ row }) => (
          <div className="text-center text-sm text-slate-600">{row.original.total}</div>
        ),
      },
      {
        header: () => <div className="text-center text-green-600">Cumplen</div>,
        accessorKey: 'passed',
        cell: ({ row }) => (
          <div className="text-center text-sm text-green-600">{row.original.passed}</div>
        ),
      },
      {
        header: () => <div className="text-center text-red-600">Incumplen</div>,
        id: 'incumplen',
        cell: ({ row }) => {
          const f = row.original
          const isErrorBucket = sortBucket(f) === 'errores'
          return (
            <div
              className={`text-center text-sm ${isErrorBucket ? 'text-red-600 font-medium' : 'text-slate-400'}`}
            >
              {isErrorBucket ? f.failed : 0}
            </div>
          )
        },
      },
      {
        header: () => <div className="text-center">No aplica</div>,
        accessorKey: 'skipped',
        cell: ({ row }) => (
          <div className="text-center text-sm text-slate-400">{row.original.skipped}</div>
        ),
      },
      {
        header: () => <div className="text-center">Cumplimiento</div>,
        accessorKey: 'cumplimiento',
        cell: ({ row }) => (
          <div className="text-center text-sm font-medium">
            <span className={cumplimientoColor(row.original.cumplimiento)}>
              {row.original.cumplimiento !== null ? `${row.original.cumplimiento}%` : '—'}
            </span>
          </div>
        ),
      },
      {
        header: () => <div className="text-center">Acción</div>,
        id: 'accion',
        cell: ({ row }) => {
          const f = row.original
          const isErrorBucket = sortBucket(f) === 'errores'
          return (
            <div className="text-center">
              {isErrorBucket ? (
                f.failed > 0 ? (
                  <button
                    onClick={(e) => {
                      e.stopPropagation()
                      navigate(`/rule-engine/uploads/${uploadId}/validation-errors?form=${f.form}`)
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
                )
              ) : f.passed > 0 ? (
                <span className="inline-flex items-center gap-1 text-xs text-green-700">
                  <CheckCircle2 className="w-3 h-3" /> Correcto
                </span>
              ) : (
                <span className="text-xs text-slate-400">Sin reglas aplicables</span>
              )}
            </div>
          )
        },
      },
    ],
    [navigate, uploadId]
  )

  const getRowClassName = (f: FormSummary) => {
    const isActive = activeForm === f.form
    const isErrorBucket = sortBucket(f) === 'errores'
    if (isActive) return 'bg-blue-50 hover:bg-blue-50'
    // Estas filas nunca tuvieron hover propio -- se fija el hover al mismo
    // color de fondo para que TableRow no le agregue uno nuevo.
    return isErrorBucket ? 'bg-amber-50/40 hover:bg-amber-50/40' : 'bg-white hover:bg-white'
  }

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

      <DataTable columns={columns} data={visibleForms} getRowClassName={getRowClassName} />

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
