import { useState, useEffect, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileSpreadsheet } from 'lucide-react'
import { certificationService } from '../services/certification'
import { RuleCatalogFilters } from '../components/RuleCatalogFilters'
import { RuleCatalogTable } from '../components/RuleCatalogTable'
import { PageHeader } from '@/shared/components/PageHeader'
import { Input } from '@/shared/components/ui/input'
import { Button } from '@/shared/components/ui/button'
import type { CatalogFilters } from '../types/certification'

const DEFAULT_FILTERS: CatalogFilters = {
  sheet: '',
  rule_type: '',
  status: '',
  search: '',
}

export default function RuleCatalogPage() {
  const [filters, setFilters] = useState<CatalogFilters>(DEFAULT_FILTERS)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search)
      setPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const queryParams: Record<string, string> = {}
  if (filters.sheet) queryParams.sheet = filters.sheet
  if (filters.rule_type) queryParams.rule_type = filters.rule_type
  if (filters.status) queryParams.status = filters.status
  if (debouncedSearch) queryParams.search = debouncedSearch
  if (page > 1) queryParams.page = String(page)

  const { data, isLoading } = useQuery({
    queryKey: ['rule-catalog', queryParams],
    queryFn: () => certificationService.list(queryParams),
  })

  const handleFilterChange = useCallback((partial: Partial<CatalogFilters>) => {
    setFilters((prev) => ({ ...prev, ...partial }))
    setPage(1)
  }, [])

  const handleReset = useCallback(() => {
    setFilters(DEFAULT_FILTERS)
    setSearch('')
    setPage(1)
  }, [])

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Catálogo de Reglas de Consistencia"
        description="Certificación técnica y funcional de las reglas del motor de reglas"
        icon={FileSpreadsheet}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 flex flex-wrap gap-3 items-end">
          <div className="w-full max-w-sm">
            <Input
              placeholder="Buscar por regla o descripción..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30"
            />
          </div>
          <RuleCatalogFilters
            filters={filters}
            sheets={data?.sheets ?? []}
            ruleTypes={data?.rule_types ?? []}
            statuses={data?.statuses ?? []}
            onChange={handleFilterChange}
            onReset={handleReset}
          />
        </div>

        <RuleCatalogTable
          cards={data?.reglas ?? []}
          loading={isLoading}
          stats={data?.stats ?? { total: 0, pendientes: 0, certificadas: 0, requiere_revision: 0 }}
        />

        {data && data.meta.last_page > 1 && (
          <div className="mt-4 flex items-center justify-between">
            <p className="text-sm text-slate-500">
              Página {data.meta.current_page} de {data.meta.last_page} ({data.meta.total} reglas)
            </p>
            <div className="flex gap-2">
              <Button
                variant="outline"
                disabled={data.meta.current_page <= 1}
                onClick={() => setPage((p) => p - 1)}
                className="border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
              >
                Anterior
              </Button>
              <Button
                variant="outline"
                disabled={data.meta.current_page >= data.meta.last_page}
                onClick={() => setPage((p) => p + 1)}
                className="border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
              >
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
