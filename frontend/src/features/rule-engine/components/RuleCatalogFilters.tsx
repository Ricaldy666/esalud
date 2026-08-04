import type { CatalogFilters } from '../types/certification'

interface RuleCatalogFiltersProps {
  filters: CatalogFilters
  sheets: string[]
  ruleTypes: string[]
  statuses: { key: string; label: string }[]
  onChange: (filters: Partial<CatalogFilters>) => void
  onReset: () => void
}

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none w-full'

export function RuleCatalogFilters({
  filters,
  sheets,
  ruleTypes,
  statuses,
  onChange,
  onReset,
}: RuleCatalogFiltersProps) {
  return (
    <div className="flex flex-wrap gap-3 items-end">
      <div>
        <label className="text-xs font-medium text-gray-500 mb-1 block">Hoja</label>
        <select
          className={SELECT_CLASS}
          value={filters.sheet}
          onChange={(e) => onChange({ sheet: e.target.value })}
        >
          <option value="">Todas las hojas</option>
          {sheets.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
      </div>
      <div>
        <label className="text-xs font-medium text-gray-500 mb-1 block">Tipo</label>
        <select
          className={SELECT_CLASS}
          value={filters.rule_type}
          onChange={(e) => onChange({ rule_type: e.target.value })}
        >
          <option value="">Todos los tipos</option>
          {ruleTypes.map((t) => (
            <option key={t} value={t}>
              {t === 'sum_equals' ? 'Sum_Equals' : 'Required ≤ Parent'}
            </option>
          ))}
        </select>
      </div>
      <div>
        <label className="text-xs font-medium text-gray-500 mb-1 block">Estado</label>
        <select
          className={SELECT_CLASS}
          value={filters.status}
          onChange={(e) => onChange({ status: e.target.value })}
        >
          <option value="">Todos los estados</option>
          {statuses.map((s) => (
            <option key={s.key} value={s.key}>
              {s.label}
            </option>
          ))}
        </select>
      </div>
      {Object.values(filters).some((v) => v !== '') && (
        <div className="self-end">
          <button
            onClick={onReset}
            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 transition-colors"
          >
            Limpiar
          </button>
        </div>
      )}
    </div>
  )
}
