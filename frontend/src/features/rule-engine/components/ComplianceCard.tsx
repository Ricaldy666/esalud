import React from 'react'

interface Props {
  title: string
  cumplimiento: number | null
  total: number
  passed: number
  failed: number
  skipped: number
}

export const ComplianceCard: React.FC<Props> = ({
  title,
  cumplimiento,
  total,
  passed,
  failed,
  skipped,
}) => {
  const noAplica = cumplimiento === null
  const color = noAplica
    ? 'text-slate-400'
    : cumplimiento >= 95
      ? 'text-green-600'
      : cumplimiento >= 80
        ? 'text-yellow-600'
        : 'text-red-600'
  const bgColor = noAplica
    ? 'bg-slate-50 border-slate-200'
    : cumplimiento >= 95
      ? 'bg-green-50 border-green-200'
      : cumplimiento >= 80
        ? 'bg-yellow-50 border-yellow-200'
        : 'bg-red-50 border-red-200'

  return (
    <div className={`rounded-lg border p-4 ${bgColor}`}>
      <h3 className="text-sm font-medium text-slate-600 mb-1">{title}</h3>
      <div className={`text-3xl font-bold ${color}`}>{noAplica ? '—' : `${cumplimiento}%`}</div>
      <div className="mt-2 text-xs text-slate-500 space-y-0.5">
        <div>Total: {total}</div>
        <div className="text-green-600">Cumplen: {passed}</div>
        <div className="text-red-600">Incumplen: {failed}</div>
        {skipped > 0 && <div className="text-slate-400">No aplica / Sin datos: {skipped}</div>}
      </div>
    </div>
  )
}
