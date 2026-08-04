import { useNavigate } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
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

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <StatCard label="Total reglas" value={stats.total} color="text-gray-900" />
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

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Estado
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Fila
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Regla
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Variable
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Fórmula técnica
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Columnas origen
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Destino
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Severidad
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Aplica a
                </th>
                <th className="px-3 py-3 text-left font-semibold text-gray-600 text-xs uppercase">
                  Condición funcional
                </th>
                <th className="px-3 py-3"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i}>
                    {Array.from({ length: 11 }).map((_, j) => (
                      <td key={j} className="px-3 py-2.5">
                        <div className="h-4 bg-gray-100 rounded animate-pulse" />
                      </td>
                    ))}
                  </tr>
                ))
              ) : cards.length === 0 ? (
                <tr>
                  <td colSpan={11} className="px-3 py-8 text-center text-sm text-gray-400">
                    No se encontraron reglas en esta sección.
                  </td>
                </tr>
              ) : (
                cards.map((card) => {
                  const fr = funcionalRules[card.rule_key]
                  return (
                    <tr
                      key={card.rule_key}
                      className="hover:bg-gray-50 transition-colors cursor-pointer"
                      onClick={() =>
                        navigate(`/rule-engine/catalog/${encodeURIComponent(card.rule_key)}`)
                      }
                    >
                      <td className="px-3 py-2.5">
                        <CertificationStatusBadge estado={card.estado} />
                      </td>
                      <td className="px-3 py-2.5 font-mono text-xs text-gray-500">
                        {card.rango_filas ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 font-mono text-xs font-medium text-gray-700">
                        {card.rule_key}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-gray-600 max-w-[160px] truncate">
                        {card.description ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 font-mono text-xs text-gray-700 max-w-[200px]">
                        {card.formula_interpretada}
                      </td>
                      <td className="px-3 py-2.5 font-mono text-xs text-gray-500">
                        {card.columnas_origen.join(', ') || '—'}
                      </td>
                      <td className="px-3 py-2.5 font-mono text-xs font-medium text-gray-700">
                        {card.columna_destino ?? '—'}
                      </td>
                      <td className="px-3 py-2.5">
                        {card.severity && (
                          <span
                            className={`text-xs uppercase tracking-wider ${card.severity === 'error' ? 'text-red-500' : 'text-gray-400'}`}
                          >
                            {card.severity}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-gray-500 max-w-[120px] truncate">
                        {fr?.applies_to_types?.join(', ') || '—'}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-gray-500 max-w-[160px] truncate">
                        {fr?.functional_condition || '—'}
                      </td>
                      <td className="px-3 py-2.5 text-right">
                        <ChevronRight className="w-4 h-4 text-gray-300" />
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function StatCard({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-3 text-center shadow-sm">
      <div className={`text-xl font-bold ${color}`}>{value}</div>
      <div className="text-xs text-gray-500 mt-0.5">{label}</div>
    </div>
  )
}
