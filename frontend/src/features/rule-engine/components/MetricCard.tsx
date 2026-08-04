import { type VariantProps, cva } from 'class-variance-authority'
import { cn } from '@/shared/lib/utils'
import type { LucideIcon } from 'lucide-react'

const metricCardVariants = cva(
  'rounded-xl border p-5 flex items-start gap-4 transition-shadow hover:shadow-md',
  {
    variants: {
      variant: {
        default: 'bg-white border-slate-200',
        success: 'bg-emerald-50 border-emerald-200',
        warning: 'bg-amber-50 border-amber-200',
        danger: 'bg-rose-50 border-rose-200',
        info: 'bg-blue-50 border-blue-200',
      },
    },
    defaultVariants: { variant: 'default' },
  }
)

const iconVariants = cva('p-2.5 rounded-lg shrink-0', {
  variants: {
    variant: {
      default: 'bg-slate-100 text-slate-600',
      success: 'bg-emerald-100 text-emerald-700',
      warning: 'bg-amber-100 text-amber-700',
      danger: 'bg-rose-100 text-rose-700',
      info: 'bg-blue-100 text-blue-700',
    },
  },
  defaultVariants: { variant: 'default' },
})

interface MetricCardProps extends VariantProps<typeof metricCardVariants> {
  icon: LucideIcon
  label: string
  value: string | number
  subtitle?: string
}

export function MetricCard({ icon: Icon, label, value, subtitle, variant }: MetricCardProps) {
  return (
    <div className={cn(metricCardVariants({ variant }))}>
      <div className={cn(iconVariants({ variant }))}>
        <Icon className="w-5 h-5" />
      </div>
      <div className="min-w-0">
        <p className="text-xs font-medium text-slate-500 uppercase tracking-wider">{label}</p>
        <p className="text-2xl font-bold text-slate-900 mt-0.5 tabular-nums">{value}</p>
        {subtitle && <p className="text-xs text-slate-400 mt-0.5">{subtitle}</p>}
      </div>
    </div>
  )
}
