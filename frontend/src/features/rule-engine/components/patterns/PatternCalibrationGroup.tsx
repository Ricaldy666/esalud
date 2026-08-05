import { useState } from 'react'
import { ChevronDown, ChevronRight } from 'lucide-react'
import RowCellMatrix from './RowCellMatrix'
import type { ColumnGroup, PatternGroup } from '../../types/calibration'

const COBERTURA_LABELS: Record<string, { text: string; color: string }> = {
  'evidencia directa': { text: 'Evidencia directa', color: 'bg-green-100 text-green-700' },
  'cubierta por patrón real': { text: 'Cubierta por patrón', color: 'bg-blue-100 text-blue-700' },
  'pendiente de validación funcional': {
    text: 'Pendiente funcional',
    color: 'bg-yellow-100 text-yellow-700',
  },
  excepción: { text: 'Excepción', color: 'bg-orange-100 text-orange-700' },
  'no aplica': { text: 'No aplica', color: 'bg-slate-100 text-slate-500' },
}

interface Props {
  pattern: PatternGroup
  columnGroups?: ColumnGroup[]
  warnings?: string[]
}

function labelForColumn(column: string, columnGroups?: ColumnGroup[]) {
  for (const group of columnGroups ?? []) {
    const direct = group.columns.find((item) => item.letter === column)
    if (direct?.label) return direct.label
    for (const subgroup of group.subgroups ?? []) {
      const nested = subgroup.columns.find((item) => item.letter === column)
      if (nested?.label) return nested.label
    }
  }
  return column
}

function columnRange(group: ColumnGroup) {
  return `${group.start_column}:${group.end_column}`
}

function isMenAgeRule(rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number]) {
  return rule.total_column === 'C' && (rule.origin_columns?.length ?? 0) > 2
}

function isWomenAgeRule(
  rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number]
) {
  return rule.total_column === 'D' && (rule.origin_columns?.length ?? 0) > 2
}

function conciseRuleLabel(
  rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number],
  columnGroups?: ColumnGroup[]
) {
  if (rule.total_column === 'B' && rule.origin_columns?.join(',') === 'C,D')
    return 'Ambos Sexos = Hombres + Mujeres'
  if (rule.total_column === 'C' && rule.origin_columns?.join(',') === 'D,E')
    return 'Ambos Sexos = Hombres + Mujeres'
  if (isMenAgeRule(rule)) return 'Total Hombres = suma de rangos etarios de Hombres'
  if (isWomenAgeRule(rule)) return 'Total Mujeres = suma de rangos etarios de Mujeres'

  const totalLabel = labelForColumn(rule.total_column, columnGroups)
  const origins = rule.origin_columns
    ?.map((column) => labelForColumn(column, columnGroups))
    .join(' + ')
  return origins
    ? `${totalLabel} = ${origins}`
    : rule.label || rule.destino_funcional || rule.total_column
}

function hasConfirmedEvidence(pattern: PatternGroup, warnings?: string[]) {
  if (pattern.source !== 'cell_data' || (warnings ?? []).length > 0) return false
  return (
    pattern.rows.length > 0 &&
    pattern.rows.every(
      (row) =>
        row.functional_rules?.length &&
        row.functional_rules.every(
          (rule) =>
            rule.total_column &&
            rule.origin_columns?.length &&
            (rule.formula_exacta || rule.formula_template)
        )
    )
  )
}

function TechnicalDetails({ pattern }: { pattern: PatternGroup }) {
  const rules = pattern.rows[0]?.functional_rules ?? []
  return (
    <details className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs">
      <summary className="cursor-pointer font-medium text-indigo-700">Ver detalle técnico</summary>
      <div className="mt-2 space-y-2">
        {rules.map((rule) => (
          <div key={`${pattern.id}-${rule.total_column}`} className="rounded-md bg-slate-50 p-2">
            <div>
              <span className="font-medium text-slate-600">Destino:</span>{' '}
              <span className="font-mono">{rule.destination || rule.total_column}</span>
            </div>
            <div>
              <span className="font-medium text-slate-600">Origen:</span>{' '}
              <span className="font-mono">
                {rule.origin_coordinates?.join(', ') ||
                  rule.origin_columns?.join(', ') ||
                  'sin origen'}
              </span>
            </div>
            <div>
              <span className="font-medium text-slate-600">Fórmula:</span>{' '}
              <span className="font-mono">
                {rule.formula_exacta || rule.formula_template || 'sin fórmula'}
              </span>
            </div>
          </div>
        ))}
      </div>
    </details>
  )
}

function ConfirmedRulesSummary({
  pattern,
  columnGroups,
}: {
  pattern: PatternGroup
  columnGroups?: ColumnGroup[]
}) {
  const rules = pattern.rows[0]?.functional_rules ?? []
  const ageRange = columnGroups?.find((group) => group.type === 'age_range')
  const complementary = columnGroups?.find((group) => group.type === 'complementary')

  return (
    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm">
      <p className="font-semibold text-emerald-800">Reglas confirmadas desde el XLSM</p>
      <div className="mt-2 space-y-1 text-emerald-950">
        {rules.map((rule) => (
          <p key={`${pattern.id}-${rule.total_column}`}>✓ {conciseRuleLabel(rule, columnGroups)}</p>
        ))}
        {ageRange && <p>✓ Rango etario detectado: {columnRange(ageRange)}</p>}
        {complementary && <p>✓ Variables complementarias: {columnRange(complementary)}</p>}
      </div>
      <div className="mt-3">
        <TechnicalDetails pattern={pattern} />
      </div>
    </div>
  )
}

export default function PatternCalibrationGroup({ pattern, columnGroups, warnings }: Props) {
  const [open, setOpen] = useState(false)
  const totalColumns = pattern.total_columns?.length
    ? pattern.total_columns
    : [pattern.columna_total].filter(Boolean)
  const originColumns = pattern.origin_columns?.length
    ? pattern.origin_columns
    : pattern.columnas_origen
  const formulaText = pattern.formula_templates
    ? Object.entries(pattern.formula_templates)
        .map(([total, formula]) => `${total}: ${formula}`)
        .join(' | ')
    : pattern.formula_template

  const confirmedEvidence = hasConfirmedEvidence(pattern, warnings)

  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
      <button
        onClick={() => setOpen(!open)}
        className="w-full flex items-center justify-between px-6 py-4 hover:bg-slate-50 transition-colors text-left"
      >
        <div className="flex items-center gap-4">
          <span className="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 text-sm font-bold">
            {pattern.id}
          </span>
          <div>
            <h3 className="text-base font-semibold text-slate-900">{pattern.nombre}</h3>
            <p className="text-sm text-slate-500">{pattern.descripcion}</p>
          </div>
        </div>
        <div className="flex items-center gap-4 text-sm text-slate-500">
          <span>{pattern.cantidad_filas} filas</span>
          {!confirmedEvidence && <span className="font-mono text-indigo-600">={formulaText}</span>}
          {confirmedEvidence && <span className="text-emerald-700">Lectura XLSM confirmada</span>}
          {open ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
        </div>
      </button>

      {open && (
        <>
          <div className="px-6 py-3 bg-slate-50 border-t border-b border-slate-200 grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
            <div>
              <span className="text-slate-500">Filas:</span>
              <span className="font-mono ml-1">{pattern.filas.join(', ')}</span>
            </div>
            <div>
              <span className="text-slate-500">Columna total:</span>
              <span className="font-mono ml-1">{totalColumns.join(', ')}</span>
            </div>
            <div>
              <span className="text-slate-500">Columnas origen:</span>
              <span className="font-mono text-xs ml-1">{originColumns.join(', ')}</span>
            </div>
            <div>
              <span className="text-slate-500">Columnas en fórmula:</span>
              <span className="font-mono text-xs ml-1">{originColumns.length}</span>
            </div>
            <div>
              <span className="text-slate-500">Fuente:</span>
              <span className="ml-1">
                {pattern.source === 'structure_inferred' ? 'Estructura preliminar' : 'Celdas XLSM'}
              </span>
            </div>
            {pattern.conceptos.length > 0 && (
              <div>
                <span className="text-slate-500">Conceptos:</span>
                <span className="ml-1">{pattern.conceptos.join(', ')}</span>
              </div>
            )}
            {pattern.profesionales.length > 0 && (
              <div>
                <span className="text-slate-500">Profesionales:</span>
                <span className="ml-1">{pattern.profesionales.join(', ')}</span>
              </div>
            )}
            {pattern.regla_funcional_label && (
              <div className="col-span-2 lg:col-span-4">
                <span className="text-slate-500">Regla funcional:</span>
                <span className="ml-1 font-medium text-indigo-700">
                  {pattern.regla_funcional_label}
                </span>
              </div>
            )}
          </div>

          {confirmedEvidence && (
            <div className="border-b border-slate-200 px-6 py-4">
              <ConfirmedRulesSummary pattern={pattern} columnGroups={columnGroups} />
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-slate-50 text-slate-500 uppercase tracking-wider">
                  <th className="px-3 py-2 text-left font-mono">Fila</th>
                  <th className="px-3 py-2 text-left">Concepto</th>
                  <th className="px-3 py-2 text-left">Profesional</th>
                  <th className="px-3 py-2 text-left">Destino</th>
                  <th className="px-3 py-2 text-left">Origen</th>
                  <th className="px-3 py-2 text-left">Regla</th>
                  <th className="px-3 py-2 text-left">Editables</th>
                  <th className="px-3 py-2 text-left">Bloqueadas</th>
                  <th className="px-3 py-2 text-center">Mini matriz</th>
                  <th className="px-3 py-2 text-left">Especiales</th>
                  <th className="px-3 py-2 text-left">Cobertura</th>
                  <th className="px-3 py-2 text-left">Estado técnico</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {pattern.rows.map((r) => {
                  const cLabel = COBERTURA_LABELS[r.cobertura] ?? {
                    text: r.cobertura,
                    color: 'bg-slate-100 text-slate-600',
                  }
                  const functionalRules = r.functional_rules?.length
                    ? r.functional_rules
                    : [
                        {
                          total_column: pattern.columna_total,
                          destino_funcional:
                            r.destino_funcional ?? 'TOTAL (' + pattern.columna_total + r.fila + ')',
                          origin_columns: originColumns,
                          descripcion_funcional_origen: r.descripcion_funcional_origen ?? '',
                          label: r.regla_funcional_label ?? '',
                        },
                      ]
                  const rowTotalColumns = functionalRules
                    .map((rule) => rule.total_column)
                    .filter(Boolean)
                  const rowOriginColumns = Array.from(
                    new Set(functionalRules.flatMap((rule) => rule.origin_columns ?? []))
                  )
                  return (
                    <tr key={r.fila} className="hover:bg-slate-50">
                      <td className="px-3 py-2 font-mono font-bold text-slate-700">{r.fila}</td>
                      <td className="px-3 py-2 text-slate-600 max-w-[140px] truncate">
                        {r.concepto || '\u2014'}
                      </td>
                      <td className="px-3 py-2 text-slate-600">{r.profesional || '\u2014'}</td>
                      <td className="px-3 py-2 text-xs">
                        <div className="space-y-1">
                          {functionalRules.map((rule) => (
                            <div
                              key={`${r.fila}-${rule.total_column}`}
                              className="font-medium text-slate-800"
                            >
                              {rule.destino_funcional}
                            </div>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2 text-xs max-w-[160px]">
                        <div className="space-y-1">
                          {functionalRules.map((rule) => (
                            <div key={`${r.fila}-${rule.total_column}-origin`}>
                              <span className="text-slate-700">
                                {rule.descripcion_funcional_origen || '\u2014'}
                              </span>
                              {rule.origin_columns?.length ? (
                                <div className="text-[10px] text-slate-400 font-mono mt-0.5">
                                  {rule.origin_columns.join(', ')}
                                </div>
                              ) : null}
                            </div>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2 text-xs max-w-[160px]">
                        <div className="space-y-1">
                          {functionalRules.map((rule) => (
                            <div
                              key={`${r.fila}-${rule.total_column}-label`}
                              className="font-medium text-slate-700"
                            >
                              {rule.label || r.regla_funcional_label || '\u2014'}
                            </div>
                          ))}
                        </div>
                        <div className="text-[10px] text-slate-400 font-mono mt-0.5">
                          {pattern.nombre}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex flex-wrap gap-0.5 max-w-[100px]">
                          {r.editables.slice(0, 8).map((ec) => (
                            <span
                              key={ec.letra}
                              className="inline-block w-3 h-3 rounded-sm"
                              style={{ background: '#FFFFCC' }}
                              title={ec.letra}
                            />
                          ))}
                          {r.editables.length > 8 && (
                            <span className="text-[10px] text-slate-400">
                              +{r.editables.length - 8}
                            </span>
                          )}
                        </div>
                        <div className="text-[10px] text-slate-400 mt-0.5">
                          {r.editables.length} cols
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex flex-wrap gap-0.5 max-w-[100px]">
                          {r.bloqueadas.slice(0, 8).map((bc) => (
                            <span
                              key={bc.letra}
                              className="inline-block w-3 h-3 rounded-sm"
                              style={{ background: '#C0C0C0', border: '1px solid #ccc' }}
                              title={bc.letra}
                            />
                          ))}
                          {r.bloqueadas.length > 8 && (
                            <span className="text-[10px] text-slate-400">
                              +{r.bloqueadas.length - 8}
                            </span>
                          )}
                        </div>
                        <div className="text-[10px] text-slate-400 mt-0.5">
                          {r.bloqueadas.length} cols
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <RowCellMatrix
                          row={r}
                          colTotal={pattern.columna_total}
                          totalColumns={rowTotalColumns}
                          colsOrigen={rowOriginColumns}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex flex-wrap gap-0.5 max-w-[80px]">
                          {r.especiales.map((ec) => (
                            <span
                              key={ec.letra}
                              className="inline-block w-3 h-3 rounded-sm"
                              style={{
                                background: ec.editable ? '#FFFFCC' : '#C0C0C0',
                                border: '1px solid #ccc',
                              }}
                              title={`${ec.letra}: ${ec.tipo_celda}`}
                            />
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <span
                          className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-medium ${cLabel.color}`}
                        >
                          {cLabel.text}
                        </span>
                      </td>
                      <td className="px-3 py-2 text-[10px] text-slate-600">
                        {r.estado_tecnico || 'Pendiente'}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
