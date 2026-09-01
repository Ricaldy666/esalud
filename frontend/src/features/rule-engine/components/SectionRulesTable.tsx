import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronRight } from 'lucide-react'
import { DataTable } from '@/shared/components/DataTable'
import type { CertificationCard } from '../types/certification'
import type { SectionStats, FunctionalRule } from '../types/functional-rule'
import { CertificationStatusBadge } from './CertificationStatusBadge'

interface SectionRulesTableProps {
  cards: CertificationCard[]
  stats: SectionStats
  funcionalRules: Record<string, FunctionalRule>
  loading: boolean
}

export function SectionRulesTable({
  cards,
  stats,
  funcionalRules,
  loading,
}: SectionRulesTableProps) {
  const navigate = useNavigate()

  const columns = useMemo<ColumnDef<CertificationCard>[]>(
    () => [
      {
        header: 'Estado',
        id: 'estado',
        cell: ({ row }) => <CertificationStatusBadge estado={row.original.estado} />,
      },
      {
        header: 'Fila',
        accessorKey: 'rango_filas',
        cell: ({ row }) => (
          <span className="font-mono text-xs text-slate-500">
            {row.original.rango_filas ?? '—'}
          </span>
        ),
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
        header: 'Variable',
        accessorKey: 'description',
        cell: ({ row }) => (
          <span className="block max-w-[160px] truncate text-xs text-slate-600">
            {row.original.description ?? '—'}
          </span>
        ),
      },
      {
        header: 'Fórmula técnica',
        accessorKey: 'formula_interpretada',
        cell: ({ row }) => (
          <span className="block max-w-[200px] font-mono text-xs text-slate-700">
            {row.original.formula_interpretada}
          </span>
        ),
      },
      {
        header: 'Columnas origen',
        accessorKey: 'columnas_origen',
        cell: ({ row }) => (
          <span className="font-mono text-xs text-slate-500">
            {row.original.columnas_origen.join(', ') || '—'}
          </span>
        ),
      },
      {
        header: 'Destino',
        accessorKey: 'columna_destino',
        cell: ({ row }) => (
          <span className="font-mono text-xs font-medium text-slate-700">
            {row.original.columna_destino ?? '—'}
          </span>
        ),
      },
      {
        header: 'Severidad',
        accessorKey: 'severity',
        cell: ({ row }) =>
          row.original.severity && (
            <span
              className={`text-xs uppercase tracking-wider ${row.original.severity === 'error' ? 'text-red-500' : 'text-slate-400'}`}
            >
              {row.original.severity}
            </span>
          ),
      },
      {
        header: 'Aplica a',
        id: 'applies_to',
        cell: ({ row }) => {
          const fr = funcionalRules[row.original.rule_key]
          return (
            <span className="block max-w-[120px] truncate text-xs text-slate-500">
              {fr?.applies_to_types?.join(', ') || '—'}
            </span>
          )
        },
      },
      {
        header: 'Condición funcional',
        id: 'functional_condition',
        cell: ({ row }) => {
          const fr = funcionalRules[row.original.rule_key]
          return (
            <span className="block max-w-[160px] truncate text-xs text-slate-500">
              {fr?.functional_condition || '—'}
            </span>
          )
        },
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
    [funcionalRules]
  )

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <StatCard label="Total reglas" value={stats.total} color="text-slate-900" />
        <StatCard label="Pendientes" value={stats.pendientes} color="text-yellow-600" />
        <StatCard label="Certificadas" value={stats.certificadas} color="text-emerald-600" />
        <StatCard label="Requieren revisión" value={stats.requiere_revision} color="text-red-600" />
        <StatCard label="Horizontales" value={stats.horizontales} color="text-amber-600" />
        <StatCard label="Obligatoriedad" value={stats.obligatoriedad} color="text-rose-600" />
        <StatCard
          label="Filas detectadas"
          value={new Set(cards.map((c) => c.rango_filas)).size}
          color="text-blue-600"
        />
      </div>

      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <DataTable
          columns={columns}
          data={cards}
          loading={loading}
          emptyMessage="No se encontraron reglas en esta sección."
          onRowClick={(card) =>
            navigate(`/rule-engine/catalog/${encodeURIComponent(card.rule_key)}`)
          }
        />
      </div>
    </div>
  )
}

function StatCard({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div className="bg-white rounded-xl border border-slate-200 p-3 text-center shadow-sm">
      <div className={`text-xl font-bold ${color}`}>{value}</div>
      <div className="text-xs text-slate-500 mt-0.5">{label}</div>
    </div>
  )
}
