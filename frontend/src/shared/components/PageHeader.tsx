import { ArrowLeft } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Card } from '@/shared/components/ui/card'

interface BreadcrumbItem {
  label: string
  onClick: () => void
}

interface PageHeaderProps {
  title: ReactNode
  description?: string
  icon?: LucideIcon
  actions?: ReactNode
  /** Opcional. Reproduce el patrón de retorno ya usado en varias páginas
   * (botón "← Label" + "/" antes del título). Sin esta prop, PageHeader
   * renderiza exactamente igual que antes de esta extensión. */
  breadcrumb?: BreadcrumbItem[]
}

export function PageHeader({
  title,
  description,
  icon: Icon,
  actions,
  breadcrumb,
}: PageHeaderProps) {
  return (
    <Card className="mb-6 border border-slate-200 bg-white px-5 py-5 shadow-sm">
      {breadcrumb && breadcrumb.length > 0 && (
        <div className="mb-3 flex items-center gap-3">
          {breadcrumb.map((item, index) => (
            <span key={`${item.label}-${index}`} className="flex items-center gap-3">
              <button
                type="button"
                onClick={item.onClick}
                className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
              >
                {index === 0 && <ArrowLeft className="h-4 w-4" />}
                {item.label}
              </button>
              <span className="text-sm text-slate-300">/</span>
            </span>
          ))}
        </div>
      )}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          {Icon && (
            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
              <Icon className="size-5" />
            </div>
          )}
          <div>
            <h1 className="text-xl font-bold text-slate-900">{title}</h1>
            {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
          </div>
        </div>
        {actions && <div className="flex items-center gap-3">{actions}</div>}
      </div>
    </Card>
  )
}
