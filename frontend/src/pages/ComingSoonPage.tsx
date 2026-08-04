import { Construction } from 'lucide-react'

interface ComingSoonPageProps {
  title: string
}

export default function ComingSoonPage({ title }: ComingSoonPageProps) {
  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">{title}</h1>

      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
        <Construction className="h-8 w-8 text-gray-300" />
        <p className="text-sm font-medium text-gray-700">Módulo en planificación.</p>
      </div>
    </div>
  )
}
