import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { List } from 'lucide-react'
import { useRules } from '../hooks/useRules'
import { RuleStatusBadge } from '../components/RuleStatusBadge'
import { SeverityBadge } from '../components/SeverityBadge'
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
import { Eye } from 'lucide-react'
import type { ColumnDef } from '@tanstack/react-table'
import type { Rule, RuleFilters } from '../types/rule'
import { getHelpText, getRuleTypeLabel, getSourceLabel, getSeverityLabel } from '../utils/labels'

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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
        <span className="font-mono text-sm text-slate-900">{row.original.rule_key}</span>
      ),
    },
    {
      header: 'Nombre',
      accessorKey: 'name',
      cell: ({ row }) => <span className="text-sm text-slate-700">{row.original.name}</span>,
    },
    {
      header: 'Tipo',
      accessorKey: 'rule_type',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600">{getRuleTypeLabel(row.original.rule_type)}</span>
      ),
    },
    {
      header: 'Fuente',
      accessorKey: 'source',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600">{getSourceLabel(row.original.source)}</span>
      ),
    },
    {
      header: 'Categoría',
      accessorKey: 'category',
      cell: ({ row }) => (
        <span className="text-sm text-slate-600">{row.original.category ?? '—'}</span>
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
      cell: ({ row }) => <span className="text-sm text-slate-600">{row.original.version}</span>,
    },
    {
      header: 'Actualización',
      accessorKey: 'updated_at',
      cell: ({ row }) => (
        <span className="text-sm text-slate-500">
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
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Reglas de Consistencia"
        description="Catálogo de reglas que valida el motor de reglas sobre los archivos REM"
        icon={List}
        actions={<HelpTooltip text={getHelpText('rules') ?? ''} />}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="w-full max-w-sm">
            <Input
              placeholder="Buscar por nombre o código..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value)
                setPage(1)
              }}
              className="border-slate-300 bg-white text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30"
            />
          </div>
          <div className="w-40">
            <Label className={LABEL_CLASS}>Tipo</Label>
            <Select
              value={filters.rule_type ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('rule_type', v && v !== 'all' ? v : '')
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                <SelectItem value="sum_equals" className={SELECT_ITEM_CLASS}>
                  {getRuleTypeLabel('sum_equals')}
                </SelectItem>
                <SelectItem value="required_and_le_parent" className={SELECT_ITEM_CLASS}>
                  {getRuleTypeLabel('required_and_le_parent')}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Estado</Label>
            <Select
              value={filters.status ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('status', v && v !== 'all' ? v : '')
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                <SelectItem value="active" className={SELECT_ITEM_CLASS}>
                  Activo
                </SelectItem>
                <SelectItem value="inactive" className={SELECT_ITEM_CLASS}>
                  Inactivo
                </SelectItem>
                <SelectItem value="deprecated" className={SELECT_ITEM_CLASS}>
                  Deprecado
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Severidad</Label>
            <Select
              value={filters.severity ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('severity', v && v !== 'all' ? v : '')
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todas
                </SelectItem>
                <SelectItem value="error" className={SELECT_ITEM_CLASS}>
                  {getSeverityLabel('error')}
                </SelectItem>
                <SelectItem value="warning" className={SELECT_ITEM_CLASS}>
                  {getSeverityLabel('warning')}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Fuente</Label>
            <Select
              value={filters.source ?? 'all'}
              onValueChange={(v: string | null) =>
                handleFilterChange('source', v && v !== 'all' ? v : '')
              }
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todas
                </SelectItem>
                <SelectItem value="excel_formula" className={SELECT_ITEM_CLASS}>
                  {getSourceLabel('excel_formula')}
                </SelectItem>
                <SelectItem value="manual" className={SELECT_ITEM_CLASS}>
                  Manual
                </SelectItem>
              </SelectContent>
            </Select>
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
    </div>
  )
}
