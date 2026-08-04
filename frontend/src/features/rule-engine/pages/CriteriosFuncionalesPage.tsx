import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import { certificationService } from '../services/certification'

const SERIES_ORDER = [
  'A',
  'B',
  'C',
  'D',
  'E',
  'F',
  'G',
  'H',
  'I',
  'J',
  'K',
  'L',
  'M',
  'N',
  'O',
  'P',
  'Q',
  'R',
  'S',
  'T',
  'U',
  'V',
  'W',
  'X',
  'Y',
  'Z',
]

export default function CriteriosFuncionalesPage() {
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({
    queryKey: ['catalog-summary'],
    queryFn: () => certificationService.list({ per_page: '500' }),
  })

  const seriesMap = new Map<string, { codigo: string; count: number }[]>()
  if (data?.reglas) {
    for (const r of data.reglas) {
      const serie = r.hoja?.match(/^([A-Z])/)?.[1] ?? '?'
      if (!seriesMap.has(serie)) seriesMap.set(serie, [])
      const existing = seriesMap.get(serie)!
      const found = existing.find((s) => s.codigo === r.hoja)
      if (found) {
        found.count++
      } else {
        existing.push({ codigo: r.hoja, count: 1 })
      }
    }
  }

  const sortedSeries = [...seriesMap.entries()].sort(
    (a, b) => SERIES_ORDER.indexOf(a[0]) - SERIES_ORDER.indexOf(b[0])
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Criterios funcionales</h1>
        <p className="text-sm text-gray-500 mt-1">
          Revisa y define el comportamiento funcional de cada fila en los formularios REM. Las filas
          pendientes usan la regla técnica por defecto hasta que tomes una decisión.
        </p>
      </div>

      {isLoading ? (
        <div className="grid gap-4">
          {[1, 2, 3].map((i) => (
            <div key={i} className="bg-white rounded-xl border border-gray-200 p-5 animate-pulse">
              <div className="h-5 w-32 bg-gray-100 rounded mb-3" />
              <div className="flex gap-2">
                {[1, 2, 3].map((j) => (
                  <div key={j} className="h-8 w-24 bg-gray-50 rounded" />
                ))}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="space-y-4">
          {sortedSeries.map(([serie, sections]) => (
            <div
              key={serie}
              className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden"
            >
              <div className="px-5 py-3 bg-gradient-to-r from-indigo-50 to-blue-50 border-b border-gray-100">
                <h2 className="text-base font-semibold text-indigo-900">Serie {serie}</h2>
              </div>
              <div className="divide-y divide-gray-100">
                {sections
                  .sort((a, b) => a.codigo.localeCompare(b.codigo))
                  .map((s) => (
                    <button
                      key={s.codigo}
                      onClick={() => navigate(`/criterios-funcionales/${s.codigo}/sections/A`)}
                      className="w-full flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors text-left"
                    >
                      <div className="flex items-center gap-3">
                        <span className="font-mono text-sm font-medium text-indigo-600">
                          {s.codigo}
                        </span>
                        <span className="text-sm text-gray-600">{s.count} reglas</span>
                      </div>
                      <ChevronRight className="w-4 h-4 text-gray-300" />
                    </button>
                  ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
