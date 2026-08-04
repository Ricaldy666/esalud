import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Download, Table as TableIcon, Grid3X3, LayoutGrid } from 'lucide-react'
import { functionalRuleService } from '../services/functional-rule'
import { useCalibrationMatrix } from '../hooks/useCalibrationMatrix'
import { SectionRulesTable } from '../components/SectionRulesTable'
import { SectionCalibrationTable } from '../components/SectionCalibrationTable'
import PatternCalibrationSummary from '../components/patterns/PatternCalibrationSummary'
import { Input } from '@/shared/components/ui/input'

const SELECT_CLASS =
  'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none'

type TabView = 'rules' | 'calibration' | 'patterns'

export default function RuleSectionPage() {
  const { sheet, section } = useParams<{ sheet: string; section: string }>()
  const navigate = useNavigate()
  const [tab, setTab] = useState<TabView>('rules')
  const [fila, setFila] = useState('')
  const [estado, setEstado] = useState('')
  const [search, setSearch] = useState('')

  const params: Record<string, string> = {}
  if (fila) params.fila = fila
  if (estado) params.estado = estado
  if (search) params.search = search

  const { data, isLoading } = useQuery({
    queryKey: ['section', sheet, section, params],
    queryFn: () => functionalRuleService.getSection(sheet!, section!, params),
    enabled: !!sheet && !!section,
  })

  const { data: matrixData, isLoading: matrixLoading } = useCalibrationMatrix(sheet, section)

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate('/rule-engine/catalog')}
            className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
          >
            <ArrowLeft className="w-4 h-4" />
            Catálogo
          </button>
          <span className="text-gray-300 text-sm">/</span>
          <div>
            <h1 className="text-xl font-bold text-gray-900">
              <span className="font-mono text-indigo-600">{sheet}</span>
              <span className="text-gray-400 mx-1">/</span>
              <span className="font-mono">Sección {section}</span>
            </h1>
            {data?.section?.titulo && (
              <p className="text-sm text-gray-500">{data.section.titulo}</p>
            )}
          </div>
        </div>
        <a
          href={functionalRuleService.getSectionExportUrl(sheet ?? 'A01', section ?? 'A')}
          className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
        >
          <Download className="w-4 h-4" />
          Exportar sección
        </a>
      </div>

      <div className="flex gap-1 border-b border-gray-200">
        <TabButton active={tab === 'rules'} onClick={() => setTab('rules')}>
          <TableIcon className="w-4 h-4" />
          Reglas detectadas
          {data?.reglas && (
            <span className="ml-1.5 text-xs text-gray-400">({data.reglas.length})</span>
          )}
        </TabButton>
        <TabButton active={tab === 'calibration'} onClick={() => setTab('calibration')}>
          <Grid3X3 className="w-4 h-4" />
          Matriz de calibración
          {matrixData?.rows && (
            <span className="ml-1.5 text-xs text-gray-400">({matrixData.rows.length} filas)</span>
          )}
        </TabButton>
        <TabButton active={tab === 'patterns'} onClick={() => setTab('patterns')}>
          <LayoutGrid className="w-4 h-4" />
          Calibración por patrones
        </TabButton>
      </div>

      {tab === 'rules' ? (
        <>
          <div className="flex flex-wrap gap-3 items-end">
            <div className="w-full max-w-xs">
              <Input
                placeholder="Buscar por regla o descripción..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 mb-1 block">Fila</label>
              <select
                className={SELECT_CLASS}
                value={fila}
                onChange={(e) => setFila(e.target.value)}
              >
                <option value="">Todas las filas</option>
                {data?.reglas &&
                  [...new Set(data.reglas.map((r) => r.rango_filas).filter(Boolean))]
                    .sort()
                    .map((f) => (
                      <option key={f} value={f!}>
                        {f}
                      </option>
                    ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-500 mb-1 block">Estado técnico</label>
              <select
                className={SELECT_CLASS}
                value={estado}
                onChange={(e) => setEstado(e.target.value)}
              >
                <option value="">Todos</option>
                <option value="Pendiente">Pendiente</option>
                <option value="Certificada técnicamente">Certificada</option>
                <option value="Requiere revisión">Requiere revisión</option>
              </select>
            </div>
            {(fila || estado || search) && (
              <button
                onClick={() => {
                  setFila('')
                  setEstado('')
                  setSearch('')
                }}
                className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
              >
                Limpiar
              </button>
            )}
          </div>

          <SectionRulesTable
            cards={data?.reglas ?? []}
            stats={
              data?.stats ?? {
                total: 0,
                pendientes: 0,
                certificadas: 0,
                requiere_revision: 0,
                horizontales: 0,
                verticales: 0,
                obligatoriedad: 0,
              }
            }
            funcionalRules={data?.funcional_rules ?? {}}
            loading={isLoading}
          />
        </>
      ) : tab === 'calibration' ? (
        <SectionCalibrationTable
          data={matrixData}
          loading={matrixLoading}
          sheet={sheet}
          section={section}
        />
      ) : (
        <PatternCalibrationSummary sheet={sheet ?? 'A01'} section={section ?? 'A'} />
      )}
    </div>
  )
}

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
        active
          ? 'border-indigo-500 text-indigo-700'
          : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
      }`}
    >
      {children}
    </button>
  )
}
