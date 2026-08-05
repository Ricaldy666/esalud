import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Layers, Eye } from 'lucide-react'
import { useBindings } from '../hooks/useBindings'
import { DataTable } from '@/shared/components/DataTable'
import { PageHeader } from '@/shared/components/PageHeader'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import { HelpTooltip } from '../components/HelpTooltip'
import { getHelpText, getBindableTypeLabel } from '../utils/labels'
import type { ColumnDef } from '@tanstack/react-table'
import type { Binding, BindingFilters } from '../types/binding'

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const INPUT_CLASS =
  'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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
        <span className="font-mono text-sm text-slate-900">
          {row.original.rule?.rule_key ?? '—'}
        </span>
      ),
    },
    {
      header: 'Nombre',
      accessorKey: 'rule.name',
      cell: ({ row }) => (
        <span className="text-sm text-slate-700 truncate max-w-[200px] block">
          {row.original.rule?.name ?? '—'}
        </span>
      ),
    },
    {
      header: 'Tipo',
      accessorKey: 'bindable_type',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600 capitalize">
          {getBindableTypeLabel(row.original.bindable_type)}
        </span>
      ),
    },
    {
      header: 'Serie',
      accessorKey: 'serie',
      cell: ({ row }) => (
        <span className="text-sm text-slate-900 font-mono">{row.original.serie ?? '—'}</span>
      ),
    },
    {
      header: 'Año',
      accessorKey: 'anio',
      cell: ({ row }) => <span className="text-sm text-slate-600">{row.original.anio ?? '—'}</span>,
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
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Relaciones de Reglas"
        description="Indica en qué formularios REM se aplica cada regla de consistencia"
        icon={Layers}
        actions={<HelpTooltip text={getHelpText('bindings') ?? ''} />}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="w-full max-w-sm">
            <Input
              placeholder="Buscar por código o nombre de regla..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              className={INPUT_CLASS}
            />
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Tipo</Label>
            <Select
              value={filters.bindable_type ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('bindable_type', v && v !== 'all' ? v : undefined)
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                <SelectItem value="structure" className={SELECT_ITEM_CLASS}>
                  Estructura
                </SelectItem>
                <SelectItem value="serie" className={SELECT_ITEM_CLASS}>
                  Serie
                </SelectItem>
                <SelectItem value="global" className={SELECT_ITEM_CLASS}>
                  Global
                </SelectItem>
              </SelectContent>
            </Select>
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
          <div className="w-32">
            <Label className={LABEL_CLASS}>Estado</Label>
            <Select
              value={filters.active === undefined ? 'all' : String(filters.active)}
              onValueChange={(v: string | null) => {
                setFilters((prev) => ({
                  ...prev,
                  active: !v || v === 'all' ? undefined : v === 'true',
                }))
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                <SelectItem value="true" className={SELECT_ITEM_CLASS}>
                  Activo
                </SelectItem>
                <SelectItem value="false" className={SELECT_ITEM_CLASS}>
                  Inactivo
                </SelectItem>
              </SelectContent>
            </Select>
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
    </div>
  )
}
