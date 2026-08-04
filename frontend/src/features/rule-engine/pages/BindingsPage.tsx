import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useBindings } from '../hooks/useBindings'
import { DataTable } from '@/shared/components/DataTable'
import { PageHeader } from '@/shared/components/PageHeader'
import { Input } from '@/shared/components/ui/input'
import { Eye } from 'lucide-react'
import { HelpTooltip } from '../components/HelpTooltip'
import { getHelpText, getBindableTypeLabel } from '../utils/labels'
import type { ColumnDef } from '@tanstack/react-table'
import type { Binding, BindingFilters } from '../types/binding'

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'

export default function BindingsPage() {
  const navigate = useNavigate()

  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [filters, setFilters] = useState<BindingFilters>({})
  const [page, setPage] = useState(1)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  const handleFilterChange = useCallback(
    (key: keyof BindingFilters, value: string | number | undefined) => {
      setFilters((prev) => ({ ...prev, [key]: value || undefined }))
      setPage(1)
    },
    []
  )

  const queryFilters: BindingFilters = {
    page,
    per_page: 20,
    search: debouncedSearch || undefined,
    ...filters,
  }

  const { data, isLoading } = useBindings(queryFilters)

  const columns: ColumnDef<Binding>[] = [
    {
      header: 'Código de Regla',
      accessorKey: 'rule.rule_key',
      cell: ({ row }) => (
        <span className="font-mono text-sm text-gray-900">
          {row.original.rule?.rule_key ?? '—'}
        </span>
      ),
    },
    {
      header: 'Nombre',
      accessorKey: 'rule.name',
      cell: ({ row }) => (
        <span className="text-sm text-gray-700 truncate max-w-[200px] block">
          {row.original.rule?.name ?? '—'}
        </span>
      ),
    },
    {
      header: 'Tipo',
      accessorKey: 'bindable_type',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600 capitalize">
          {getBindableTypeLabel(row.original.bindable_type)}
        </span>
      ),
    },
    {
      header: 'Serie',
      accessorKey: 'serie',
      cell: ({ row }) => (
        <span className="text-sm text-gray-900 font-mono">{row.original.serie ?? '—'}</span>
      ),
    },
    {
      header: 'Año',
      accessorKey: 'anio',
      cell: ({ row }) => <span className="text-sm text-gray-600">{row.original.anio ?? '—'}</span>,
    },
    {
      header: 'Estado',
      accessorKey: 'active',
      cell: ({ row }) => (
        <span
          className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${
            row.original.active
              ? 'bg-emerald-100 text-emerald-700 border-emerald-200'
              : 'bg-slate-100 text-slate-500 border-slate-200'
          }`}
        >
          {row.original.active ? 'Activo' : 'Inactivo'}
        </span>
      ),
    },
    {
      header: 'Severidad',
      accessorKey: 'rule.severity',
      cell: ({ row }) => (
        <span
          className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
            row.original.rule?.severity === 'error'
              ? 'bg-rose-50 text-rose-600 border-rose-200'
              : 'bg-amber-50 text-amber-600 border-amber-200'
          }`}
        >
          {row.original.rule?.severity ?? '—'}
        </span>
      ),
    },
    {
      header: 'Fecha',
      accessorKey: 'created_at',
      cell: ({ row }) => (
        <span className="text-sm text-gray-500 whitespace-nowrap">
          {new Date(row.original.created_at).toLocaleDateString('es-CL')}
        </span>
      ),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <button
          onClick={() => navigate(`/rule-engine/bindings/${row.original.id}`)}
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
      <div className="flex items-center gap-2">
        <PageHeader
          title="Relaciones de Reglas"
          description="Indica en qué formularios REM se aplica cada regla de consistencia"
        />
        <HelpTooltip text={getHelpText('bindings') ?? ''} />
      </div>

      <div className="flex flex-wrap gap-3 items-end">
        <div className="w-full max-w-sm">
          <Input
            placeholder="Buscar por código o nombre de regla..."
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
            value={filters.bindable_type ?? ''}
            onChange={(e) => handleFilterChange('bindable_type', e.target.value)}
          >
            <option value="">Todos</option>
            <option value="structure">Estructura</option>
            <option value="serie">Serie</option>
            <option value="global">Global</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Serie</label>
          <select
            className={SELECT_CLASS}
            value={filters.serie ?? ''}
            onChange={(e) => handleFilterChange('serie', e.target.value)}
          >
            <option value="">Todas</option>
            <option value="A">A</option>
            <option value="BM">BM</option>
            <option value="BS">BS</option>
            <option value="D">D</option>
            <option value="P">P</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Año</label>
          <Input
            type="number"
            placeholder="2026"
            className="w-24"
            value={filters.anio ?? ''}
            onChange={(e) =>
              handleFilterChange('anio', e.target.value ? Number(e.target.value) : undefined)
            }
          />
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Estado</label>
          <select
            className={SELECT_CLASS}
            value={filters.active === undefined ? '' : String(filters.active)}
            onChange={(e) => {
              const val = e.target.value
              setFilters((prev) => ({ ...prev, active: val === '' ? undefined : val === 'true' }))
              setPage(1)
            }}
          >
            <option value="">Todos</option>
            <option value="true">Activo</option>
            <option value="false">Inactivo</option>
          </select>
        </div>
      </div>

      <DataTable<Binding>
        key={`${page}-${debouncedSearch}-${JSON.stringify(filters)}`}
        columns={columns}
        data={data?.data ?? []}
        loading={isLoading}
        pagination={data?.meta}
        onPageChange={setPage}
        emptyMessage="No se encontraron relaciones de reglas"
      />
    </div>
  )
}
