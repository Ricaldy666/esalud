import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { certificationService } from '../services/certification'
import { RuleCertificationCard } from '../components/RuleCertificationCard'
import { Skeleton } from '@/shared/components/ui/skeleton'

export default function RuleCertificationDetailPage() {
  const { ruleKey } = useParams<{ ruleKey: string }>()

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['rule-certification', ruleKey],
    queryFn: () => certificationService.get(ruleKey!),
    enabled: !!ruleKey,
  })

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-6 w-48" />
        <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm space-y-4">
          <Skeleton className="h-8 w-64" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-3/4" />
          <div className="grid grid-cols-2 gap-6">
            <Skeleton className="h-24" />
            <Skeleton className="h-24" />
          </div>
        </div>
      </div>
    )
  }

  if (!data?.regla) {
    return <div className="text-center py-12 text-sm text-gray-400">Regla no encontrada.</div>
  }

  return (
    <RuleCertificationCard
      card={data.regla}
      funcional={data.funcional}
      estructura={data.estructura}
      onStatusUpdated={() => refetch()}
      onFuncionalSaved={() => refetch()}
    />
  )
}
