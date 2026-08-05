import { Label } from '@/shared/components/ui/label'
import { Button } from '@/shared/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import type { CatalogFilters } from '../types/certification'

interface RuleCatalogFiltersProps {
  filters: CatalogFilters
  sheets: string[]
  ruleTypes: string[]
  statuses: { key: string; label: string }[]
  onChange: (filters: Partial<CatalogFilters>) => void
  onReset: () => void
}

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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
      <div className="w-40">
        <Label className={LABEL_CLASS}>Hoja</Label>
        <Select
          value={filters.sheet || 'all'}
          onValueChange={(v: string | null) => onChange({ sheet: v && v !== 'all' ? v : '' })}
        >
          <SelectTrigger className={SELECT_TRIGGER_CLASS}>
            <SelectValue placeholder="Todas las hojas" />
          </SelectTrigger>
          <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
            <SelectItem value="all" className={SELECT_ITEM_CLASS}>
              Todas las hojas
            </SelectItem>
            {sheets.map((s) => (
              <SelectItem key={s} value={s} className={SELECT_ITEM_CLASS}>
                {s}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="w-44">
        <Label className={LABEL_CLASS}>Tipo</Label>
        <Select
          value={filters.rule_type || 'all'}
          onValueChange={(v: string | null) => onChange({ rule_type: v && v !== 'all' ? v : '' })}
        >
          <SelectTrigger className={SELECT_TRIGGER_CLASS}>
            <SelectValue placeholder="Todos los tipos" />
          </SelectTrigger>
          <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
            <SelectItem value="all" className={SELECT_ITEM_CLASS}>
              Todos los tipos
            </SelectItem>
            {ruleTypes.map((t) => (
              <SelectItem key={t} value={t} className={SELECT_ITEM_CLASS}>
                {t === 'sum_equals' ? 'Sum_Equals' : 'Required ≤ Parent'}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="w-44">
        <Label className={LABEL_CLASS}>Estado</Label>
        <Select
          value={filters.status || 'all'}
          onValueChange={(v: string | null) => onChange({ status: v && v !== 'all' ? v : '' })}
        >
          <SelectTrigger className={SELECT_TRIGGER_CLASS}>
            <SelectValue placeholder="Todos los estados" />
          </SelectTrigger>
          <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
            <SelectItem value="all" className={SELECT_ITEM_CLASS}>
              Todos los estados
            </SelectItem>
            {statuses.map((s) => (
              <SelectItem key={s.key} value={s.key} className={SELECT_ITEM_CLASS}>
                {s.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      {Object.values(filters).some((v) => v !== '') && (
        <div className="self-end">
          <Button
            variant="outline"
            onClick={onReset}
            className="border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900"
          >
            Limpiar
          </Button>
        </div>
      )}
    </div>
  )
}
