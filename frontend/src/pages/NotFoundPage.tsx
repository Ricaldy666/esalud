import { useNavigate } from 'react-router-dom'
import { SearchX } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { EmptyState } from '@/shared/components/EmptyState'
import { Button } from '@/shared/components/ui/button'

export default function NotFoundPage() {
  const navigate = useNavigate()

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader title="Página no encontrada" icon={SearchX} />

      <div className="rounded-lg border border-dashed border-slate-300 bg-white p-12">
        <EmptyState
          icon={<SearchX className="h-12 w-12" />}
          title="Página no encontrada"
          description="La página que intentas visitar no existe o ya no está disponible."
          action={<Button onClick={() => navigate('/')}>Volver al Dashboard</Button>}
        />
      </div>
    </div>
  )
}
