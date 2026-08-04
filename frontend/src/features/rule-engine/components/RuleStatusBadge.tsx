import type { Rule } from '../types/rule'

const STATUS_STYLES: Record<Rule['status'], string> = {
  active: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  inactive: 'bg-slate-100 text-slate-500 border-slate-200',
  deprecated: 'bg-amber-100 text-amber-700 border-amber-200',
}

const STATUS_LABELS: Record<Rule['status'], string> = {
  active: 'Activo',
  inactive: 'Inactivo',
  deprecated: 'Deprecado',
}

interface RuleStatusBadgeProps {
  status: Rule['status']
}

export function RuleStatusBadge({ status }: RuleStatusBadgeProps) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[status]}`}
    >
      {STATUS_LABELS[status]}
    </span>
  )
}
