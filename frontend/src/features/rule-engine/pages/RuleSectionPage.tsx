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
import { Label } from '@/shared/components/ui/label'
import { Button } from '@/shared/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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

  const filaOptions = data?.reglas
    ? [...new Set(data.reglas.map((r) => r.rango_filas).filter(Boolean))].sort()
    : []

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate('/rule-engine/catalog')}
            className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
          >
            <ArrowLeft className="w-4 h-4" />
            Catálogo
          </button>
          <span className="text-slate-300 text-sm">/</span>
          <div>
            <h1 className="text-xl font-bold text-slate-900">
              <span className="font-mono text-indigo-600">{sheet}</span>
              <span className="text-slate-400 mx-1">/</span>
              <span className="font-mono">Sección {section}</span>
            </h1>
            {data?.section?.titulo && (
              <p className="text-sm text-slate-500">{data.section.titulo}</p>
            )}
          </div>
        </div>
        <a
          href={functionalRuleService.getSectionExportUrl(sheet ?? 'A01', section ?? 'A')}
          className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors"
        >
          <Download className="w-4 h-4" />
          Exportar sección
        </a>
      </div>

      <div className="flex gap-1 border-b border-slate-200">
        <TabButton active={tab === 'rules'} onClick={() => setTab('rules')}>
          <TableIcon className="w-4 h-4" />
          Reglas detectadas
          {data?.reglas && (
            <span className="ml-1.5 text-xs text-slate-400">({data.reglas.length})</span>
          )}
        </TabButton>
        <TabButton active={tab === 'calibration'} onClick={() => setTab('calibration')}>
          <Grid3X3 className="w-4 h-4" />
          Matriz de calibración
          {matrixData?.rows && (
            <span className="ml-1.5 text-xs text-slate-400">({matrixData.rows.length} filas)</span>
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
                className="border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30"
              />
            </div>
            <div className="w-40">
              <Label className={LABEL_CLASS}>Fila</Label>
              <Select
                value={fila || 'all'}
                onValueChange={(v: string | null) => setFila(v && v !== 'all' ? v : '')}
              >
                <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                  <SelectValue placeholder="Todas las filas" />
                </SelectTrigger>
                <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                  <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                    Todas las filas
                  </SelectItem>
                  {filaOptions.map((f) => (
                    <SelectItem key={f} value={f!} className={SELECT_ITEM_CLASS}>
                      {f}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="w-44">
              <Label className={LABEL_CLASS}>Estado técnico</Label>
              <Select
                value={estado || 'all'}
                onValueChange={(v: string | null) => setEstado(v && v !== 'all' ? v : '')}
              >
                <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                  <SelectValue placeholder="Todos" />
                </SelectTrigger>
                <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                  <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                    Todos
                  </SelectItem>
                  <SelectItem value="Pendiente" className={SELECT_ITEM_CLASS}>
                    Pendiente
                  </SelectItem>
                  <SelectItem value="Certificada técnicamente" className={SELECT_ITEM_CLASS}>
                    Certificada
                  </SelectItem>
                  <SelectItem value="Requiere revisión" className={SELECT_ITEM_CLASS}>
                    Requiere revisión
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
            {(fila || estado || search) && (
              <Button
                variant="outline"
                onClick={() => {
                  setFila('')
                  setEstado('')
                  setSearch('')
                }}
                className="border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900"
              >
                Limpiar
              </Button>
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
          : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
      }`}
    >
      {children}
    </button>
  )
}
