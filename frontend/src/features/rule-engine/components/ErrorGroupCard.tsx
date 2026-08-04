import React, { useState } from 'react'
import type { ErrorGroup } from '../types/validation'
import { SeverityBadge } from './SeverityBadge'
import { ValidationErrorsTable } from './ValidationErrorsTable'

interface Props {
  group: ErrorGroup
}

const severityBorder: Record<string, string> = {
  error: 'border-l-red-500',
  warning: 'border-l-amber-400',
  info: 'border-l-blue-400',
}

const severityBg: Record<string, string> = {
  error: 'bg-red-50/40',
  warning: 'bg-amber-50/40',
  info: 'bg-blue-50/40',
}

export const ErrorGroupCard: React.FC<Props> = ({ group }) => {
  const [expanded, setExpanded] = useState(false)

  const borderClass = severityBorder[group.severidad] ?? 'border-l-gray-300'
  const bgClass = severityBg[group.severidad] ?? ''

  return (
    <div
      className={`bg-white rounded-lg border border-gray-200 border-l-[5px] ${borderClass} ${bgClass} overflow-hidden`}
    >
      <button
        onClick={() => setExpanded(!expanded)}
        className="w-full text-left p-4 hover:bg-gray-50/60 transition-colors"
      >
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 mb-1">
              <span className="text-sm font-semibold text-gray-900">{group.form}</span>
              <span className="text-xs text-gray-400">/</span>
              <span className="text-sm text-gray-600">Sección {group.section}</span>
              <SeverityBadge severity={group.severidad} />
            </div>

            <p className="text-sm text-gray-700 mb-2">
              {group.count} inconsistencia(s) · {group.variables.length} variable(s) afectadas
            </p>

            <div className="flex flex-wrap gap-x-4 gap-y-0.5 text-xs">
              {group.variables.map((v) => (
                <span key={v} className="font-medium text-gray-800">
                  • {v}
                </span>
              ))}
            </div>
          </div>

          <div className="shrink-0">
            <span className="text-xs font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">
              {expanded ? '▲ Ocultar detalle' : '▼ Ver detalle'}
            </span>
          </div>
        </div>
      </button>

      {expanded && (
        <div className="border-t border-gray-100">
          <div className="p-4">
            <p className="text-xs font-semibold text-gray-600 mb-1">Acción recomendada</p>
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm text-blue-800 mb-4">
              {group.recomendacion}
            </div>
            <ValidationErrorsTable errors={group.errors} />
          </div>
        </div>
      )}
    </div>
  )
}
