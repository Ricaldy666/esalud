import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { FileText, Eye } from 'lucide-react'
import { useExecutionLogs } from '../hooks/useExecutionLogs'
import { ExecutionStatusBadge } from '../components/ExecutionStatusBadge'
import { HelpTooltip } from '../components/HelpTooltip'
import { PageHeader } from '@/shared/components/PageHeader'
import { DataTable } from '@/shared/components/DataTable'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import type { ColumnDef } from '@tanstack/react-table'
import type { ExecutionLog, ExecutionLogFilters } from '../types/execution-log'
import { getHelpText, getStatusLabel, getTriggerLabel } from '../utils/labels'

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const INPUT_CLASS =
  'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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
        <span className="font-mono text-sm text-slate-900">{row.original.rule_key}</span>
      ),
    },
    {
      header: 'Archivo',
      accessorKey: 'rem_upload_id',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600">#{row.original.rem_upload_id}</span>
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
        <span className="text-sm text-slate-700 tabular-nums">{row.original.total_rows}</span>
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
          className={`text-sm font-medium tabular-nums ${row.original.failed_rows > 0 ? 'text-rose-600' : 'text-slate-500'}`}
        >
          {row.original.failed_rows}
        </span>
      ),
    },
    {
      header: 'Duración',
      accessorKey: 'execution_ms',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600 tabular-nums">{row.original.execution_ms} ms</span>
      ),
    },
    {
      header: 'Origen',
      accessorKey: 'triggered_by',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600 capitalize">
          {getTriggerLabel(row.original.triggered_by)}
        </span>
      ),
    },
    {
      header: 'Fecha',
      accessorKey: 'created_at',
      cell: ({ row }) => (
        <span className="text-sm text-slate-500 whitespace-nowrap">
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
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Historial de Validaciones"
        description="Muestra todas las veces que una regla ha sido ejecutada sobre los archivos REM"
        icon={FileText}
        actions={<HelpTooltip text={getHelpText('logs') ?? ''} />}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="w-full max-w-sm">
            <Input
              placeholder="Buscar por código de regla..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              className={INPUT_CLASS}
            />
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Estado</Label>
            <Select
              value={filters.status ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('status', v && v !== 'all' ? v : undefined)
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                <SelectItem value="passed" className={SELECT_ITEM_CLASS}>
                  {getStatusLabel('passed')}
                </SelectItem>
                <SelectItem value="failed" className={SELECT_ITEM_CLASS}>
                  {getStatusLabel('failed')}
                </SelectItem>
                <SelectItem value="skipped" className={SELECT_ITEM_CLASS}>
                  {getStatusLabel('skipped')}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Trigger</Label>
            <Select
              value={filters.triggered_by ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('triggered_by', v && v !== 'all' ? v : undefined)
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                <SelectItem value="cli" className={SELECT_ITEM_CLASS}>
                  {getTriggerLabel('cli')}
                </SelectItem>
                <SelectItem value="job" className={SELECT_ITEM_CLASS}>
                  {getTriggerLabel('job')}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label className={LABEL_CLASS}>Upload ID</Label>
            <Input
              type="number"
              placeholder="ID"
              className={`w-24 ${INPUT_CLASS}`}
              value={filters.upload_id ?? ''}
              onChange={(e) =>
                handleFilterChange('upload_id', e.target.value ? Number(e.target.value) : undefined)
              }
            />
          </div>
          <div>
            <Label className={LABEL_CLASS}>Desde</Label>
            <Input
              type="date"
              className={INPUT_CLASS}
              value={filters.from ?? ''}
              onChange={(e) => handleFilterChange('from', e.target.value)}
            />
          </div>
          <div>
            <Label className={LABEL_CLASS}>Hasta</Label>
            <Input
              type="date"
              className={INPUT_CLASS}
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
    </div>
  )
}
