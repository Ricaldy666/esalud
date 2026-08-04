import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useStructures } from '../hooks/useStructures'
import { DataTable } from '@/shared/components/DataTable'
import { Input } from '@/shared/components/ui/input'
import { Eye } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import type { Structure, StructureFilters } from '../types/structure'
import { HelpTooltip } from '../components/HelpTooltip'
import { getHelpText, getStructureStatusLabel } from '../utils/labels'

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'

const STATUS_STYLES: Record<string, string> = {
  approved: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  draft: 'bg-slate-100 text-slate-500 border-slate-200',
  superseded: 'bg-amber-100 text-amber-700 border-amber-200',
}

export default function StructuresPage() {
  const navigate = useNavigate()

  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [filters, setFilters] = useState<StructureFilters>({})
  const [page, setPage] = useState(1)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  const handleFilterChange = useCallback(
    (key: keyof StructureFilters, value: string | number | undefined) => {
      setFilters((prev) => ({ ...prev, [key]: value || undefined }))
      setPage(1)
    },
    []
  )

  const queryFilters: StructureFilters = {
    page,
    per_page: 20,
    search: debouncedSearch || undefined,
    ...filters,
  }

  const { data, isLoading } = useStructures(queryFilters)

  const columns: ColumnDef<Structure>[] = [
    {
      header: 'Año',
      accessorKey: 'anio',
      cell: ({ row }) => (
        <span className="text-sm font-medium text-gray-900">{row.original.anio}</span>
      ),
    },
    {
      header: 'Serie',
      accessorKey: 'serie',
      cell: ({ row }) => (
        <span className="text-sm text-gray-900 font-mono">{row.original.serie}</span>
      ),
    },
    {
      header: 'Versión',
      accessorKey: 'version_number',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600">v{row.original.version_number}</span>
      ),
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => (
        <span
          className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[row.original.status] ?? 'bg-gray-100 text-gray-500 border-gray-200'}`}
        >
          {getStructureStatusLabel(row.original.status)}
        </span>
      ),
    },
    {
      header: () => <span className="text-xs text-gray-400 font-normal">Hash</span>,
      accessorKey: 'hash_short',
      cell: ({ row }) => (
        <span className="font-mono text-xs text-gray-400">{row.original.hash_short}…</span>
      ),
    },
    {
      header: 'Reglas',
      accessorKey: 'stats.total_rules',
      cell: ({ row }) => (
        <span className="text-sm font-medium text-gray-900 tabular-nums">
          {row.original.stats?.total_rules ?? 0}
        </span>
      ),
    },
    {
      header: 'Formularios',
      accessorKey: 'stats.total_forms',
      cell: ({ row }) => (
        <span className="text-sm font-medium text-gray-900 tabular-nums">
          {row.original.stats?.total_forms ?? 0}
        </span>
      ),
    },
    {
      header: 'Secciones',
      accessorKey: 'stats.total_sections',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600 tabular-nums">
          {row.original.stats?.total_sections ?? 0}
        </span>
      ),
    },
    {
      header: 'Campos',
      accessorKey: 'stats.total_fields',
      cell: ({ row }) => (
        <span className="text-sm text-gray-600 tabular-nums">
          {row.original.stats?.total_fields ?? 0}
        </span>
      ),
    },
    {
      header: 'Fecha de actualización',
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
          onClick={() => navigate(`/rule-engine/structures/${row.original.id}`)}
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
          <h1 className="text-2xl font-bold text-gray-900">Estructuras REM</h1>
          <HelpTooltip text={getHelpText('structures') ?? ''} />
        </div>
        <p className="mt-1 text-sm text-gray-500">
          Biblioteca de estructuras REM utilizadas para construir las reglas de consistencia
        </p>
      </div>

      <div className="flex flex-wrap gap-3 items-end">
        <div className="w-full max-w-sm">
          <Input
            placeholder="Buscar por serie, año o archivo..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
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
          <label className="text-xs font-medium text-gray-500 mb-1 block">Estado</label>
          <select
            className={SELECT_CLASS}
            value={filters.status ?? ''}
            onChange={(e) => handleFilterChange('status', e.target.value)}
          >
            <option value="">Todos</option>
            <option value="approved">{getStructureStatusLabel('approved')}</option>
            <option value="draft">{getStructureStatusLabel('draft')}</option>
            <option value="superseded">{getStructureStatusLabel('superseded')}</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-gray-500 mb-1 block">Versión</label>
          <Input
            type="number"
            placeholder="1"
            className="w-20"
            value={filters.version ?? ''}
            onChange={(e) =>
              handleFilterChange('version', e.target.value ? Number(e.target.value) : undefined)
            }
          />
        </div>
      </div>

      <DataTable<Structure>
        key={`${page}-${debouncedSearch}-${JSON.stringify(filters)}`}
        columns={columns}
        data={data?.data ?? []}
        loading={isLoading}
        pagination={data?.meta}
        onPageChange={setPage}
        emptyMessage="No se encontraron estructuras"
      />
    </div>
  )
}
