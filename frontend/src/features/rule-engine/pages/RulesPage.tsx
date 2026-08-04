import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useRules } from '../hooks/useRules'
import { RuleStatusBadge } from '../components/RuleStatusBadge'
import { SeverityBadge } from '../components/SeverityBadge'
import { HelpTooltip } from '../components/HelpTooltip'
import { DataTable } from '@/shared/components/DataTable'
import { Input } from '@/shared/components/ui/input'
import { Eye } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import type { Rule, RuleFilters } from '../types/rule'
import { getHelpText, getRuleTypeLabel, getSourceLabel, getSeverityLabel } from '../utils/labels'

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'

export default function RulesPage() {
  const navigate = useNavigate()

  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [filters, setFilters] = useState<RuleFilters>({})
  const [page, setPage] = useState(1)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  const handleFilterChange = useCallback((key: keyof RuleFilters, value: string) => {
    setFilters((prev) => {
      const next = { ...prev, [key]: value || undefined }
      return next
    })
    setPage(1)
  }, [])

  const queryFilters: RuleFilters = {
    page,
    per_page: 15,
    search: debouncedSearch || undefined,
    ...filters,
  }

  const { data, isLoading } = useRules(queryFilters)

  const columns: ColumnDef<Rule>[] = [
    {
      header: () => (
        <span className="inline-flex items-center gap-1">
          Código de Regla
          <HelpTooltip text={getHelpText('rule-key') ?? ''} />
        </span>
      ),
      accessorKey: 'rule_key',
      cell: ({ row }) => (
        <span className="font-mono text-sm text-gray-900">{row.original.rule_key}</span>
      ),
    },
    {
      header: 'Nombre',
      accessorKey: 'name',
      cell: ({ row }) => <span className="text-sm text-gray-700">{row.original.name}</span>,
    },
    {
      header: 'Tipo',
      accessorKey: 'rule_type',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600">{getRuleTypeLabel(row.original.rule_type)}</span>
      ),
    },
    {
      header: 'Fuente',
      accessorKey: 'source',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600">{getSourceLabel(row.original.source)}</span>
      ),
    },
    {
      header: 'Categoría',
      accessorKey: 'category',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600">{row.original.category ?? '—'}</span>
      ),
    },
    {
      header: 'Severidad',
      accessorKey: 'severity',
      cell: ({ row }) => <SeverityBadge severity={row.original.severity} />,
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => <RuleStatusBadge status={row.original.status} />,
    },
    {
      header: 'Versión',
      accessorKey: 'version',
      cell: ({ row }) => <span className="text-sm text-gray-600">{row.original.version}</span>,
    },
    {
      header: 'Actualización',
      accessorKey: 'updated_at',
      cell: ({ row }) => (
        <span className="text-sm text-gray-500">
          {new Date(row.original.updated_at).toLocaleDateString('es-CL')}
        </span>
      ),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <button
          onClick={() => navigate(`/rule-engine/rules/${row.original.id}`)}
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
      <div className="mb-6 flex items-center gap-1.5">
        <h1 className="text-2xl font-bold text-gray-900">Reglas de Consistencia</h1>
        <HelpTooltip text={getHelpText('rules') ?? ''} />
      </div>

      <div className="flex flex-wrap gap-3 items-end">
        <div className="w-full max-w-sm">
          <Input
            placeholder="Buscar por nombre o código..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Tipo</label>
          <select
            className={SELECT_CLASS}
            value={filters.rule_type ?? ''}
            onChange={(e) => handleFilterChange('rule_type', e.target.value)}
          >
            <option value="">Todos</option>
            <option value="sum_equals">{getRuleTypeLabel('sum_equals')}</option>
            <option value="required_and_le_parent">
              {getRuleTypeLabel('required_and_le_parent')}
            </option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Estado</label>
          <select
            className={SELECT_CLASS}
            value={filters.status ?? ''}
            onChange={(e) => handleFilterChange('status', e.target.value)}
          >
            <option value="">Todos</option>
            <option value="active">Activo</option>
            <option value="inactive">Inactivo</option>
            <option value="deprecated">Deprecado</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Severidad</label>
          <select
            className={SELECT_CLASS}
            value={filters.severity ?? ''}
            onChange={(e) => handleFilterChange('severity', e.target.value)}
          >
            <option value="">Todas</option>
            <option value="error">{getSeverityLabel('error')}</option>
            <option value="warning">{getSeverityLabel('warning')}</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Fuente</label>
          <select
            className={SELECT_CLASS}
            value={filters.source ?? ''}
            onChange={(e) => handleFilterChange('source', e.target.value)}
          >
            <option value="">Todas</option>
            <option value="excel_formula">{getSourceLabel('excel_formula')}</option>
            <option value="manual">Manual</option>
          </select>
        </div>
      </div>

      <DataTable<Rule>
        key={`${page}-${debouncedSearch}-${JSON.stringify(filters)}`}
        columns={columns}
        data={data?.data ?? []}
        loading={isLoading}
        pagination={data?.meta}
        onPageChange={setPage}
        emptyMessage="No se encontraron reglas"
      />
    </div>
  )
}
