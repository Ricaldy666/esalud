import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from '@/shared/components/DataTable'
import type { ComparisonDiff } from '../types/comparison'
import { getStatusLabel, getSeverityLabel, getComparisonStatusLabel } from '../utils/labels'

const STATUS_MATCH_STYLES: Record<string, string> = {
  true: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  false: 'bg-rose-100 text-rose-700 border-rose-200',
}

export function ComparisonDiffTable({ differences }: { differences: ComparisonDiff[] }) {
  const columns = useMemo<ColumnDef<ComparisonDiff>[]>(
    () => [
      {
        header: 'Formulario',
        accessorKey: 'sheet',
        cell: ({ row }) => (
          <span className="font-mono text-xs text-slate-700">{row.original.sheet}</span>
        ),
      },
      {
        header: 'Sección',
        accessorKey: 'section',
        cell: ({ row }) => <span className="text-xs text-slate-600">{row.original.section}</span>,
      },
      {
        header: 'Código de Regla',
        accessorKey: 'new_key',
        cell: ({ row }) => (
          <span className="font-mono text-xs text-slate-900">{row.original.new_key}</span>
        ),
      },
      {
        header: 'Tipo',
        accessorKey: 'tipo',
        cell: ({ row }) => <span className="text-xs text-slate-600">{row.original.tipo}</span>,
      },
      {
        header: 'Nivel de importancia',
        id: 'severity',
        cell: ({ row }) => (
          <span
            className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
              row.original.severity === 'error'
                ? 'bg-rose-50 text-rose-600 border-rose-200'
                : row.original.severity === 'warning'
                  ? 'bg-amber-50 text-amber-600 border-amber-200'
                  : 'bg-slate-50 text-slate-500 border-slate-200'
            }`}
          >
            {getSeverityLabel(row.original.severity)}
          </span>
        ),
      },
      {
        header: 'Anterior',
        id: 'legacy_status',
        cell: ({ row }) => (
          <span
            className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
              row.original.legacy.status === 'passed'
                ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                : row.original.legacy.status === 'failed'
                  ? 'bg-rose-50 text-rose-600 border-rose-200'
                  : 'bg-slate-50 text-slate-500 border-slate-200'
            }`}
          >
            {getStatusLabel(row.original.legacy.status)}
          </span>
        ),
      },
      {
        header: 'Actual',
        id: 'engine_status',
        cell: ({ row }) => (
          <span
            className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
              row.original.engine.status === 'passed'
                ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                : row.original.engine.status === 'failed'
                  ? 'bg-rose-50 text-rose-600 border-rose-200'
                  : 'bg-slate-50 text-slate-500 border-slate-200'
            }`}
          >
            {getStatusLabel(row.original.engine.status)}
          </span>
        ),
      },
      {
        header: 'Resultado',
        id: 'status_match',
        cell: ({ row }) => (
          <span
            className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
              STATUS_MATCH_STYLES[String(row.original.status_match)]
            }`}
          >
            {getComparisonStatusLabel(String(row.original.status_match))}
          </span>
        ),
      },
      {
        header: 'Filas',
        id: 'rows',
        cell: ({ row }) => (
          <span className="text-xs text-slate-700 tabular-nums">
            {row.original.legacy.total_rows} vs {row.original.engine.total_rows}
          </span>
        ),
      },
      {
        header: 'Observaciones',
        id: 'failed_rows',
        cell: ({ row }) => (
          <span className="text-xs text-slate-700 tabular-nums">
            {row.original.legacy.failed_rows} vs {row.original.engine.failed_rows}
          </span>
        ),
      },
    ],
    []
  )

  if (differences.length === 0) return null

  return (
    <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <div className="px-6 py-4 border-b border-slate-100">
        <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wider">
          Diferencias ({differences.length})
        </h3>
      </div>
      <DataTable columns={columns} data={differences} />
    </div>
  )
}
