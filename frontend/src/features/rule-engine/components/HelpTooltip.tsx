import React, { useState } from 'react'
import { HelpCircle } from 'lucide-react'

interface Props {
  text: string
  className?: string
}

export const HelpTooltip: React.FC<Props> = ({ text, className }) => {
  const [visible, setVisible] = useState(false)

  return (
    <span
      className={`relative inline-flex items-center ${className ?? ''}`}
      onMouseEnter={() => setVisible(true)}
      onMouseLeave={() => setVisible(false)}
    >
      <HelpCircle className="h-3.5 w-3.5 text-gray-400 cursor-help" />
      {visible && (
        <span className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-1.5 bg-gray-800 text-white text-xs rounded-lg shadow-lg whitespace-nowrap z-50 pointer-events-none">
          {text}
          <span className="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800" />
        </span>
      )}
    </span>
  )
}
