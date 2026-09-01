import { Construction } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'

interface ComingSoonPageProps {
  title: string
}

export default function ComingSoonPage({ title }: ComingSoonPageProps) {
  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader title={title} icon={Construction} />

      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-slate-300 bg-white p-12 text-center">
        <Construction className="h-8 w-8 text-slate-300" />
        <p className="text-sm font-medium text-slate-700">Módulo en planificación.</p>
      </div>
    </div>
  )
}
