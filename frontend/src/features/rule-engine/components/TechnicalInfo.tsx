import React, { useState } from 'react'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { HelpTooltip } from './HelpTooltip'
import { getHelpText } from '../utils/labels'

interface Props {
  children: React.ReactNode
  title?: string
  defaultOpen?: boolean
}

export const TechnicalInfo: React.FC<Props> = ({ children, title, defaultOpen = false }) => {
  const [open, setOpen] = useState(defaultOpen)

  return (
    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        className="w-full flex items-center gap-2 px-4 py-3 text-left hover:bg-gray-50 transition-colors"
      >
        {open ? (
          <ChevronDown className="h-4 w-4 text-gray-400" />
        ) : (
          <ChevronRight className="h-4 w-4 text-gray-400" />
        )}
        <span className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
          {title ?? 'Información técnica'}
        </span>
        <HelpTooltip text={getHelpText('technical-info') ?? 'Información interna del sistema'} />
      </button>
      {open && <div className="border-t border-gray-100">{children}</div>}
    </div>
  )
}
