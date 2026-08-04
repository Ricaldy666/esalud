import React from 'react'
import { getSeverityLabel } from '../utils/labels'

const severityStyles: Record<string, string> = {
  error: 'bg-red-100 text-red-800',
  warning: 'bg-yellow-100 text-yellow-800',
  info: 'bg-blue-100 text-blue-800',
}

interface Props {
  severity: string
}

export const SeverityBadge: React.FC<Props> = ({ severity }) => {
  const cls = severityStyles[severity] ?? 'bg-gray-100 text-gray-800'
  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${cls}`}
    >
      {getSeverityLabel(severity)}
    </span>
  )
}
