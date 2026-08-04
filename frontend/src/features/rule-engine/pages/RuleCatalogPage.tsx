import { useState, useEffect, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { certificationService } from '../services/certification'
import { RuleCatalogFilters } from '../components/RuleCatalogFilters'
import { RuleCatalogTable } from '../components/RuleCatalogTable'
import { Input } from '@/shared/components/ui/input'
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
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-900">Catálogo de Reglas de Consistencia</h1>

      <div className="flex flex-wrap gap-3 items-end">
        <div className="w-full max-w-sm">
          <Input
            placeholder="Buscar por regla o descripción..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
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
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-500">
            Página {data.meta.current_page} de {data.meta.last_page} ({data.meta.total} reglas)
          </p>
          <div className="flex gap-2">
            <button
              disabled={data.meta.current_page <= 1}
              onClick={() => setPage((p) => p - 1)}
              className="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-40 transition-colors"
            >
              Anterior
            </button>
            <button
              disabled={data.meta.current_page >= data.meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-40 transition-colors"
            >
              Siguiente
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
