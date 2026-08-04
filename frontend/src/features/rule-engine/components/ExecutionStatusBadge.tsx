import type { ExecutionLog } from '../types/execution-log'

const STATUS_STYLES: Record<ExecutionLog['status'], string> = {
  passed: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  failed: 'bg-rose-100 text-rose-700 border-rose-200',
  skipped: 'bg-slate-100 text-slate-500 border-slate-200',
}

const STATUS_LABELS: Record<ExecutionLog['status'], string> = {
  passed: 'Correctas',
  failed: 'Con observaciones',
  skipped: 'No aplica',
}

interface ExecutionStatusBadgeProps {
  status: ExecutionLog['status']
}

export function ExecutionStatusBadge({ status }: ExecutionStatusBadgeProps) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[status]}`}
    >
      {STATUS_LABELS[status]}
    </span>
  )
}
