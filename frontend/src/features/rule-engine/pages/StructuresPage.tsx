import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Database, Eye } from 'lucide-react'
import { useStructures } from '../hooks/useStructures'
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
import type { Structure, StructureFilters } from '../types/structure'
import { HelpTooltip } from '../components/HelpTooltip'
import { getHelpText, getStructureStatusLabel } from '../utils/labels'

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const INPUT_CLASS =
  'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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
        <span className="text-sm font-medium text-slate-900">{row.original.anio}</span>
      ),
    },
    {
      header: 'Serie',
      accessorKey: 'serie',
      cell: ({ row }) => (
        <span className="text-sm text-slate-900 font-mono">{row.original.serie}</span>
      ),
    },
    {
      header: 'Versión',
      accessorKey: 'version_number',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600">v{row.original.version_number}</span>
      ),
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => (
        <span
          className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[row.original.status] ?? 'bg-slate-100 text-slate-500 border-slate-200'}`}
        >
          {getStructureStatusLabel(row.original.status)}
        </span>
      ),
    },
    {
      header: () => <span className="text-xs text-slate-400 font-normal">Hash</span>,
      accessorKey: 'hash_short',
      cell: ({ row }) => (
        <span className="font-mono text-xs text-slate-400">{row.original.hash_short}…</span>
      ),
    },
    {
      header: 'Reglas',
      accessorKey: 'stats.total_rules',
      cell: ({ row }) => (
        <span className="text-sm font-medium text-slate-900 tabular-nums">
          {row.original.stats?.total_rules ?? 0}
        </span>
      ),
    },
    {
      header: 'Formularios',
      accessorKey: 'stats.total_forms',
      cell: ({ row }) => (
        <span className="text-sm font-medium text-slate-900 tabular-nums">
          {row.original.stats?.total_forms ?? 0}
        </span>
      ),
    },
    {
      header: 'Secciones',
      accessorKey: 'stats.total_sections',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600 tabular-nums">
          {row.original.stats?.total_sections ?? 0}
        </span>
      ),
    },
    {
      header: 'Campos',
      accessorKey: 'stats.total_fields',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600 tabular-nums">
          {row.original.stats?.total_fields ?? 0}
        </span>
      ),
    },
    {
      header: 'Fecha de actualización',
      accessorKey: 'created_at',
      cell: ({ row }) => (
        <span className="text-sm text-slate-500 whitespace-nowrap">
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
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Estructuras REM"
        description="Biblioteca de estructuras REM utilizadas para construir las reglas de consistencia"
        icon={Database}
        actions={<HelpTooltip text={getHelpText('structures') ?? ''} />}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="w-full max-w-sm">
            <Input
              placeholder="Buscar por serie, año o archivo..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              className={INPUT_CLASS}
            />
          </div>
          <div>
            <Label className={LABEL_CLASS}>Año</Label>
            <Input
              type="number"
              placeholder="2026"
              className={`w-24 ${INPUT_CLASS}`}
              value={filters.anio ?? ''}
              onChange={(e) =>
                handleFilterChange('anio', e.target.value ? Number(e.target.value) : undefined)
              }
            />
          </div>
          <div className="w-28">
            <Label className={LABEL_CLASS}>Serie</Label>
            <Select
              value={filters.serie ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('serie', v && v !== 'all' ? v : undefined)
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todas
                </SelectItem>
                <SelectItem value="A" className={SELECT_ITEM_CLASS}>
                  A
                </SelectItem>
                <SelectItem value="BM" className={SELECT_ITEM_CLASS}>
                  BM
                </SelectItem>
                <SelectItem value="BS" className={SELECT_ITEM_CLASS}>
                  BS
                </SelectItem>
                <SelectItem value="D" className={SELECT_ITEM_CLASS}>
                  D
                </SelectItem>
                <SelectItem value="P" className={SELECT_ITEM_CLASS}>
                  P
                </SelectItem>
              </SelectContent>
            </Select>
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
                <SelectItem value="approved" className={SELECT_ITEM_CLASS}>
                  {getStructureStatusLabel('approved')}
                </SelectItem>
                <SelectItem value="draft" className={SELECT_ITEM_CLASS}>
                  {getStructureStatusLabel('draft')}
                </SelectItem>
                <SelectItem value="superseded" className={SELECT_ITEM_CLASS}>
                  {getStructureStatusLabel('superseded')}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label className={LABEL_CLASS}>Versión</Label>
            <Input
              type="number"
              placeholder="1"
              className={`w-20 ${INPUT_CLASS}`}
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
    </div>
  )
}
