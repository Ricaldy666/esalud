import { useNavigate } from 'react-router-dom'
import { ChevronRight, Download } from 'lucide-react'
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

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl border border-gray-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-gray-900">{stats.total}</div>
          <div className="text-xs text-gray-500 mt-1">Total reglas</div>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-yellow-600">{stats.pendientes}</div>
          <div className="text-xs text-gray-500 mt-1">Pendientes</div>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-emerald-600">{stats.certificadas}</div>
          <div className="text-xs text-gray-500 mt-1">Certificadas técnicamente</div>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4 text-center shadow-sm">
          <div className="text-2xl font-bold text-red-600">{stats.requiere_revision}</div>
          <div className="text-xs text-gray-500 mt-1">Requieren revisión</div>
        </div>
      </div>

      <div className="flex justify-end">
        <a
          href={certificationService.getExportUrl()}
          className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
        >
          <Download className="w-4 h-4" />
          Exportar Excel
        </a>
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Estado
                </th>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Regla
                </th>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Hoja
                </th>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Sección
                </th>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Tipo
                </th>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Severidad
                </th>
                <th className="px-4 py-3 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider">
                  Descripción
                </th>
                <th className="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {loading ? (
                Array.from({ length: 8 }).map((_, i) => (
                  <tr key={i}>
                    {Array.from({ length: 8 }).map((_, j) => (
                      <td key={j} className="px-4 py-3">
                        <div className="h-4 bg-gray-100 rounded animate-pulse" />
                      </td>
                    ))}
                  </tr>
                ))
              ) : cards.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-4 py-8 text-center text-sm text-gray-400">
                    No se encontraron reglas con los filtros seleccionados.
                  </td>
                </tr>
              ) : (
                cards.map((card) => (
                  <tr
                    key={card.rule_key}
                    className="hover:bg-gray-50 transition-colors cursor-pointer"
                    onClick={() =>
                      navigate(`/rule-engine/catalog/${encodeURIComponent(card.rule_key)}`)
                    }
                  >
                    <td className="px-4 py-3">
                      <CertificationStatusBadge estado={card.estado} />
                    </td>
                    <td className="px-4 py-3 font-mono text-xs font-medium text-gray-700">
                      {card.rule_key}
                    </td>
                    <td className="px-4 py-3 font-mono font-semibold text-indigo-600">
                      {card.hoja}
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-500">{card.seccion}</td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-block px-1.5 py-0.5 rounded text-xs font-medium ${
                          card.rule_type === 'sum_equals'
                            ? 'text-amber-600 bg-amber-50'
                            : 'text-rose-600 bg-rose-50'
                        }`}
                      >
                        {card.rule_type === 'sum_equals' ? 'Sum_Equals' : 'Req ≤ Parent'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {card.severity && (
                        <span
                          className={`text-xs uppercase tracking-wider ${
                            card.severity === 'error' ? 'text-red-500' : 'text-gray-400'
                          }`}
                        >
                          {card.severity}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-500 max-w-[240px] truncate">
                      {card.description ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <ChevronRight className="w-4 h-4 text-gray-300" />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
