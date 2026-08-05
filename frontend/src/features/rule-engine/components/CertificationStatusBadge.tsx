import type { CertificationCard } from '../types/certification'

const STATUS_STYLES: Record<CertificationCard['estado'], string> = {
  Pendiente: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  'Certificada técnicamente': 'bg-emerald-100 text-emerald-700 border-emerald-200',
  'Requiere revisión': 'bg-red-100 text-red-700 border-red-200',
}

interface CertificationStatusBadgeProps {
  estado: CertificationCard['estado']
}

export function CertificationStatusBadge({ estado }: CertificationStatusBadgeProps) {
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[estado] ?? 'bg-slate-100 text-slate-500 border-slate-200'}`}
    >
      {estado}
    </span>
  )
}
