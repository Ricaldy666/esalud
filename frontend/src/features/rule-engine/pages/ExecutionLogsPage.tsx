import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useExecutionLogs } from '../hooks/useExecutionLogs'
import { ExecutionStatusBadge } from '../components/ExecutionStatusBadge'
import { HelpTooltip } from '../components/HelpTooltip'
import { DataTable } from '@/shared/components/DataTable'
import { Input } from '@/shared/components/ui/input'
import { Eye } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import type { ExecutionLog, ExecutionLogFilters } from '../types/execution-log'
import { getHelpText, getStatusLabel, getTriggerLabel } from '../utils/labels'

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'
const DATE_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'

export default function ExecutionLogsPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const initialRuleId = searchParams.get('rule_id')

  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [filters, setFilters] = useState<ExecutionLogFilters>({
    rule_id: initialRuleId ? Number(initialRuleId) : undefined,
  })
  const [page, setPage] = useState(1)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  const handleFilterChange = useCallback(
    (key: keyof ExecutionLogFilters, value: string | number | undefined) => {
      setFilters((prev) => {
        const next = { ...prev, [key]: value || undefined }
        return next
      })
      setPage(1)
    },
    []
  )

  const queryFilters: ExecutionLogFilters = {
    page,
    per_page: 20,
    rule_key: debouncedSearch || undefined,
    ...filters,
  }

  const { data, isLoading } = useExecutionLogs(queryFilters)

  const columns: ColumnDef<ExecutionLog>[] = [
    {
      header: 'Código de Regla',
      accessorKey: 'rule_key',
      cell: ({ row }) => (
        <span className="font-mono text-sm text-gray-900">{row.original.rule_key}</span>
      ),
    },
    {
      header: 'Archivo',
      accessorKey: 'rem_upload_id',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600">#{row.original.rem_upload_id}</span>
      ),
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => <ExecutionStatusBadge status={row.original.status} />,
    },
    {
      header: 'Filas',
      accessorKey: 'total_rows',
      cell: ({ row }) => (
        <span className="text-sm text-gray-700 tabular-nums">{row.original.total_rows}</span>
      ),
    },
    {
      header: 'Correctas',
      accessorKey: 'passed_rows',
      cell: ({ row }) => (
        <span className="text-sm text-emerald-600 font-medium tabular-nums">
          {row.original.passed_rows}
        </span>
      ),
    },
    {
      header: 'Con observaciones',
      accessorKey: 'failed_rows',
      cell: ({ row }) => (
        <span
          className={`text-sm font-medium tabular-nums ${row.original.failed_rows > 0 ? 'text-rose-600' : 'text-gray-500'}`}
        >
          {row.original.failed_rows}
        </span>
      ),
    },
    {
      header: 'Duración',
      accessorKey: 'execution_ms',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600 tabular-nums">{row.original.execution_ms} ms</span>
      ),
    },
    {
      header: 'Origen',
      accessorKey: 'triggered_by',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600 capitalize">
          {getTriggerLabel(row.original.triggered_by)}
        </span>
      ),
    },
    {
      header: 'Fecha',
      accessorKey: 'created_at',
      cell: ({ row }) => (
        <span className="text-sm text-gray-500 whitespace-nowrap">
          {new Date(row.original.created_at).toLocaleString('es-CL')}
        </span>
      ),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <button
          onClick={() => navigate(`/rule-engine/logs/${row.original.id}`)}
          className="text-blue-600 hover:text-blue-800 transition-colors"
          title="Ver detalle"
        >
          <Eye className="h-4 w-4" />
        </button>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <div className="mb-6">
        <div className="flex items-center gap-2">
          <h1 className="text-2xl font-bold text-gray-900">Historial de Validaciones</h1>
          <HelpTooltip text={getHelpText('logs') ?? ''} />
        </div>
        <p className="mt-1 text-sm text-gray-500">
          Muestra todas las veces que una regla ha sido ejecutada sobre los archivos REM
        </p>
      </div>

      <div className="flex flex-wrap gap-3 items-end">
        <div className="w-full max-w-sm">
          <Input
            placeholder="Buscar por código de regla..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Estado</label>
          <select
            className={SELECT_CLASS}
            value={filters.status ?? ''}
            onChange={(e) => handleFilterChange('status', e.target.value)}
          >
            <option value="">Todos</option>
            <option value="passed">{getStatusLabel('passed')}</option>
            <option value="failed">{getStatusLabel('failed')}</option>
            <option value="skipped">{getStatusLabel('skipped')}</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Trigger</label>
          <select
            className={SELECT_CLASS}
            value={filters.triggered_by ?? ''}
            onChange={(e) => handleFilterChange('triggered_by', e.target.value)}
          >
            <option value="">Todos</option>
            <option value="cli">{getTriggerLabel('cli')}</option>
            <option value="job">{getTriggerLabel('job')}</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Upload ID</label>
          <Input
            type="number"
            placeholder="ID"
            className="w-24"
            value={filters.upload_id ?? ''}
            onChange={(e) =>
              handleFilterChange('upload_id', e.target.value ? Number(e.target.value) : undefined)
            }
          />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Desde</label>
          <input
            type="date"
            className={DATE_CLASS}
            value={filters.from ?? ''}
            onChange={(e) => handleFilterChange('from', e.target.value)}
          />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Hasta</label>
          <input
            type="date"
            className={DATE_CLASS}
            value={filters.to ?? ''}
            onChange={(e) => handleFilterChange('to', e.target.value)}
          />
        </div>
      </div>

      <DataTable<ExecutionLog>
        key={`${page}-${debouncedSearch}-${JSON.stringify(filters)}`}
        columns={columns}
        data={data?.data ?? []}
        loading={isLoading}
        pagination={data?.meta}
        onPageChange={setPage}
        emptyMessage="No se encontraron registros de validación"
      />
    </div>
  )
}
