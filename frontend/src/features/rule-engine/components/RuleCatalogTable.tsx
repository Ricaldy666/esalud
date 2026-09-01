import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronRight, Download } from 'lucide-react'
import { DataTable } from '@/shared/components/DataTable'
import type { CertificationCard, CatalogStats } from '../types/certification'
import { CertificationStatusBadge } from './CertificationStatusBadge'
import { certificationService } from '../services/certification'

interface RuleCatalogTableProps {
  cards: CertificationCard[]
  loading: boolean
  stats: CatalogStats
}

export function RuleCatalogTable({ cards, loading, stats }: RuleCatalogTableProps) {
  const navigate = useNavigate()

  const columns = useMemo<ColumnDef<CertificationCard>[]>(
    () => [
      {
        header: 'Estado',
        id: 'estado',
        cell: ({ row }) => <CertificationStatusBadge estado={row.original.estado} />,
      },
      {
        header: 'Regla',
        accessorKey: 'rule_key',
        cell: ({ row }) => (
          <span className="font-mono text-xs font-medium text-slate-700">
            {row.original.rule_key}
          </span>
        ),
      },
      {
        header: 'Hoja',
        accessorKey: 'hoja',
        cell: ({ row }) => (
          <span className="font-mono font-semibold text-indigo-600">{row.original.hoja}</span>
        ),
      },
      {
        header: 'Sección',
        accessorKey: 'seccion',
        cell: ({ row }) => <span className="text-xs text-slate-500">{row.original.seccion}</span>,
      },
      {
        header: 'Tipo',
        accessorKey: 'rule_type',
        cell: ({ row }) => (
          <span
            className={`inline-block rounded px-1.5 py-0.5 text-xs font-medium ${
              row.original.rule_type === 'sum_equals'
                ? 'text-amber-600 bg-amber-50'
                : 'text-rose-600 bg-rose-50'
            }`}
          >
            {row.original.rule_type === 'sum_equals' ? 'Sum_Equals' : 'Req ≤ Parent'}
          </span>
        ),
      },
      {
        header: 'Severidad',
        accessorKey: 'severity',
        cell: ({ row }) =>
          row.original.severity && (
            <span
              className={`text-xs uppercase tracking-wider ${
                row.original.severity === 'error' ? 'text-red-500' : 'text-slate-400'
              }`}
            >
              {row.original.severity}
            </span>
          ),
      },
      {
        header: 'Descripción',
        accessorKey: 'description',
        cell: ({ row }) => (
          <span className="block max-w-[240px] truncate text-xs text-slate-500">
            {row.original.description ?? '—'}
          </span>
        ),
      },
      {
        id: 'chevron',
        header: '',
        cell: () => (
          <div className="text-right">
            <ChevronRight className="w-4 h-4 text-slate-300" />
          </div>
        ),
      },
    ],
    []
  )

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-slate-900">{stats.total}</div>
          <div className="text-xs text-slate-500 mt-1">Total reglas</div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-yellow-600">{stats.pendientes}</div>
          <div className="text-xs text-slate-500 mt-1">Pendientes</div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-emerald-600">{stats.certificadas}</div>
          <div className="text-xs text-slate-500 mt-1">Certificadas técnicamente</div>
        </div>
        <div className="bg-white rounded-xl border border-slate-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-red-600">{stats.requiere_revision}</div>
          <div className="text-xs text-slate-500 mt-1">Requieren revisión</div>
        </div>
      </div>

      <div className="flex justify-end">
        <a
          href={certificationService.getExportUrl()}
          className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors"
        >
          <Download className="w-4 h-4" />
          Exportar Excel
        </a>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <DataTable
          columns={columns}
          data={cards}
          loading={loading}
          emptyMessage="No se encontraron reglas con los filtros seleccionados."
          onRowClick={(card) =>
            navigate(`/rule-engine/catalog/${encodeURIComponent(card.rule_key)}`)
          }
        />
      </div>
    </div>
  )
}
