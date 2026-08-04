import { useQuery } from '@tanstack/react-query'
import { criteriaService } from '../services/criteriaService'
import { loginPrime } from '@/shared/services/apiPrime'

export function useFunctionalCriteria(sheet: string | undefined, section: string | undefined) {
  return useQuery({
    queryKey: ['functional-criteria', sheet, section],
    queryFn: async () => {
      const params: Record<string, string | number> = {
        year: 2026,
        serie: 'REM',
        per_page: 100,
      }
      // URL params: sheet="A01" (full section code), section="A" (subsection letter)
      // primecormudesi DB stores: section="A01", sheet="A"
      // Use the URL's sheet param as DB section (it IS the full code)
      if (sheet) params.section = sheet
      try {
        return await criteriaService.list(params)
      } catch (err) {
        const status = (err as { response?: { status?: number } })?.response?.status
        if (status === 401) {
          await loginPrime()
          return await criteriaService.list(params)
        }
        throw err
      }
    },
    enabled: !!sheet && !!section,
  })
}
