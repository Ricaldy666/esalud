import { useQuery } from '@tanstack/react-query'
import { Grid3X3, FileSpreadsheet, CheckCircle2, Clock } from 'lucide-react'
import { calibrationService } from '../../services/calibration'
import PatternCalibrationGroup from './PatternCalibrationGroup'
import SpecialColumnsPanel from './SpecialColumnsPanel'
import FunctionalQuestionsPanel from './FunctionalQuestionsPanel'
import type { PatternMatrixResponse } from '../../types/calibration'

const COLOR_LEGEND = [
  {
    rgb: 'FFFFFFFF',
    label: 'Blanco',
    desc: 'Fórmula / etiqueta',
    style: { background: '#FFFFFF', border: '1px solid #ccc' },
  },
  {
    rgb: 'FFFFFFCC',
    label: 'Crema',
    desc: 'Editable',
    style: { background: '#FFFFCC', border: '1px solid #ccc' },
  },
  {
    rgb: 'FFC0C0C0',
    label: 'Plateado',
    desc: 'Bloqueado / no aplica',
    style: { background: '#C0C0C0', border: '1px solid #999' },
  },
  {
    rgb: 'FFBFBFBF',
    label: 'Gris claro',
    desc: 'Bloqueado / no aplica',
    style: { background: '#BFBFBF', border: '1px solid #999' },
  },
]

interface Props {
  sheet: string
  section: string
  readOnly?: boolean
}

export default function PatternCalibrationSummary({ sheet, section, readOnly = false }: Props) {
  const { data, isLoading, error } = useQuery<PatternMatrixResponse>({
    queryKey: ['pattern-matrix', sheet, section],
    queryFn: () => calibrationService.getPatterns(sheet, section),
    enabled: !!sheet && !!section,
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-500" />
      </div>
    )
  }

  if (error || !data) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm">
        Error al cargar la matriz de patrones. Verifique que el escaneo de celdas esté completo.
      </div>
    )
  }

  const { patterns, summary, columnas_especiales } = data
  const totalPatternRows = patterns.reduce((acc, p) => acc + p.cantidad_filas, 0)

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Grid3X3 className="w-5 h-5 text-indigo-600" />
          <h2 className="text-lg font-semibold text-slate-900">Calibración por patrones</h2>
          <span className="text-sm text-slate-400">
            ({totalPatternRows} filas calibrables · {patterns.length} patrones)
          </span>
        </div>
        <a
          href={calibrationService.getCalibrationExportUrl(sheet, section)}
          className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors"
        >
          <FileSpreadsheet className="w-4 h-4" />
          Exportar Excel
        </a>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
          <div className="flex items-center gap-2">
            <Grid3X3 className="w-4 h-4 text-indigo-500" />
            <span className="text-xs text-slate-500">Patrones</span>
          </div>
          <div className="text-2xl font-bold text-indigo-600 mt-1">{patterns.length}</div>
        </div>
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
          <div className="flex items-center gap-2">
            <FileSpreadsheet className="w-4 h-4 text-blue-500" />
            <span className="text-xs text-slate-500">Filas calibrables</span>
          </div>
          <div className="text-2xl font-bold text-blue-600 mt-1">{totalPatternRows}</div>
        </div>
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
          <div className="flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4 text-green-500" />
            <span className="text-xs text-slate-500">Evidencia directa</span>
          </div>
          <div className="text-2xl font-bold text-green-600 mt-1">{summary.cubiertas_directas}</div>
        </div>
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
          <div className="flex items-center gap-2">
            <Clock className="w-4 h-4 text-yellow-500" />
            <span className="text-xs text-slate-500">Pendientes funcionales</span>
          </div>
          <div className="text-2xl font-bold text-yellow-600 mt-1">
            {summary.pendientes_definicion_funcional}
          </div>
        </div>
      </div>

      <div className="flex flex-wrap gap-4 text-xs p-3 bg-slate-50 rounded-lg">
        <span className="font-medium text-slate-700">
          Leyenda de colores (RGB reales del XLSM):
        </span>
        {COLOR_LEGEND.map((cl) => (
          <div key={cl.rgb} className="flex items-center gap-1.5">
            <span className="inline-block w-4 h-4 rounded" style={cl.style} />
            <span className="text-slate-600">
              {cl.rgb} = {cl.label} ({cl.desc})
            </span>
          </div>
        ))}
      </div>

      {data.warnings && data.warnings.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          {data.warnings.map((warning) => (
            <p key={warning}>{warning}</p>
          ))}
        </div>
      )}

      {patterns.map((pattern) => (
        <PatternCalibrationGroup
          key={pattern.id}
          pattern={pattern}
          columnGroups={data.column_groups}
          warnings={data.warnings}
        />
      ))}

      <SpecialColumnsPanel
        columns={columnas_especiales}
        columnGroups={data.column_groups}
        allRows={data.all_rows}
        patterns={patterns}
      />

      <FunctionalQuestionsPanel
        sheet={sheet}
        section={section}
        patterns={patterns}
        columnGroups={data.column_groups}
        initialQuestions={data.questions}
        warnings={data.warnings}
        readOnly={readOnly}
        reconciliation={data.reconciliation}
      />
    </div>
  )
}
