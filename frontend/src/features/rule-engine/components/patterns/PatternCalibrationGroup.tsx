import { useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { DataTable } from '@/shared/components/DataTable'
import RowCellMatrix from './RowCellMatrix'
import type { ColumnGroup, PatternGroup, PatternRow } from '../../types/calibration'

// Solo estos 5 campos se leen realmente en el render (ver el .map() de mas
// abajo) -- igual que en el original, donde el fallback sintetico cuando no
// hay functional_rules solo definia estos mismos 5 campos.
type FunctionalRuleForDisplay = Pick<
  NonNullable<PatternRow['functional_rules']>[number],
  'total_column' | 'destino_funcional' | 'origin_columns' | 'descripcion_funcional_origen' | 'label'
>

type PatternRowWithDerived = PatternRow & {
  functionalRules: FunctionalRuleForDisplay[]
  rowTotalColumns: string[]
  rowOriginColumns: string[]
  cLabel: { text: string; color: string }
}

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

  // Derivacion identica a la que antes se calculaba en linea dentro del
  // .map() de filas -- se precalcula una sola vez por fila para que las
  // columnas de DataTable no dupliquen esta logica en cada cell renderer.
  const rowsData: PatternRowWithDerived[] = pattern.rows.map((r) => {
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
    const rowTotalColumns = functionalRules.map((rule) => rule.total_column).filter(Boolean)
    const rowOriginColumns = Array.from(
      new Set(functionalRules.flatMap((rule) => rule.origin_columns ?? []))
    )
    return { ...r, functionalRules, rowTotalColumns, rowOriginColumns, cLabel }
  })

  const columns: ColumnDef<PatternRowWithDerived>[] = [
    {
      header: 'Fila',
      accessorKey: 'fila',
      cell: ({ row }) => (
        <span className="font-mono font-bold text-slate-700">{row.original.fila}</span>
      ),
    },
    {
      header: 'Concepto',
      accessorKey: 'concepto',
      cell: ({ row }) => (
        <span className="max-w-[140px] truncate text-slate-600">
          {row.original.concepto || '—'}
        </span>
      ),
    },
    {
      header: 'Profesional',
      accessorKey: 'profesional',
      cell: ({ row }) => <span className="text-slate-600">{row.original.profesional || '—'}</span>,
    },
    {
      header: 'Destino',
      id: 'destino',
      cell: ({ row }) => (
        <div className="space-y-1 text-xs">
          {row.original.functionalRules.map((rule) => (
            <div
              key={`${row.original.fila}-${rule.total_column}`}
              className="font-medium text-slate-800"
            >
              {rule.destino_funcional}
            </div>
          ))}
        </div>
      ),
    },
    {
      header: 'Origen',
      id: 'origen',
      cell: ({ row }) => (
        <div className="max-w-[160px] space-y-1 text-xs">
          {row.original.functionalRules.map((rule) => (
            <div key={`${row.original.fila}-${rule.total_column}-origin`}>
              <span className="text-slate-700">{rule.descripcion_funcional_origen || '—'}</span>
              {rule.origin_columns?.length ? (
                <div className="mt-0.5 font-mono text-[10px] text-slate-400">
                  {rule.origin_columns.join(', ')}
                </div>
              ) : null}
            </div>
          ))}
        </div>
      ),
    },
    {
      header: 'Regla',
      id: 'regla',
      cell: ({ row }) => (
        <div className="max-w-[160px] text-xs">
          <div className="space-y-1">
            {row.original.functionalRules.map((rule) => (
              <div
                key={`${row.original.fila}-${rule.total_column}-label`}
                className="font-medium text-slate-700"
              >
                {rule.label || row.original.regla_funcional_label || '—'}
              </div>
            ))}
          </div>
          <div className="mt-0.5 font-mono text-[10px] text-slate-400">{pattern.nombre}</div>
        </div>
      ),
    },
    {
      header: 'Editables',
      id: 'editables',
      cell: ({ row }) => (
        <div>
          <div className="flex max-w-[100px] flex-wrap gap-0.5">
            {row.original.editables.slice(0, 8).map((ec) => (
              <span
                key={ec.letra}
                className="inline-block h-3 w-3 rounded-sm"
                style={{ background: '#FFFFCC' }}
                title={ec.letra}
              />
            ))}
            {row.original.editables.length > 8 && (
              <span className="text-[10px] text-slate-400">
                +{row.original.editables.length - 8}
              </span>
            )}
          </div>
          <div className="mt-0.5 text-[10px] text-slate-400">
            {row.original.editables.length} cols
          </div>
        </div>
      ),
    },
    {
      header: 'Bloqueadas',
      id: 'bloqueadas',
      cell: ({ row }) => (
        <div>
          <div className="flex max-w-[100px] flex-wrap gap-0.5">
            {row.original.bloqueadas.slice(0, 8).map((bc) => (
              <span
                key={bc.letra}
                className="inline-block h-3 w-3 rounded-sm"
                style={{ background: '#C0C0C0', border: '1px solid #ccc' }}
                title={bc.letra}
              />
            ))}
            {row.original.bloqueadas.length > 8 && (
              <span className="text-[10px] text-slate-400">
                +{row.original.bloqueadas.length - 8}
              </span>
            )}
          </div>
          <div className="mt-0.5 text-[10px] text-slate-400">
            {row.original.bloqueadas.length} cols
          </div>
        </div>
      ),
    },
    {
      header: () => <div className="text-center">Mini matriz</div>,
      id: 'mini_matriz',
      cell: ({ row }) => (
        <RowCellMatrix
          row={row.original}
          colTotal={pattern.columna_total}
          totalColumns={row.original.rowTotalColumns}
          colsOrigen={row.original.rowOriginColumns}
        />
      ),
    },
    {
      header: 'Especiales',
      id: 'especiales',
      cell: ({ row }) => (
        <div className="flex max-w-[80px] flex-wrap gap-0.5">
          {row.original.especiales.map((ec) => (
            <span
              key={ec.letra}
              className="inline-block h-3 w-3 rounded-sm"
              style={{
                background: ec.editable ? '#FFFFCC' : '#C0C0C0',
                border: '1px solid #ccc',
              }}
              title={`${ec.letra}: ${ec.tipo_celda}`}
            />
          ))}
        </div>
      ),
    },
    {
      header: 'Cobertura',
      id: 'cobertura',
      cell: ({ row }) => (
        <span
          className={`inline-block rounded px-1.5 py-0.5 text-[10px] font-medium ${row.original.cLabel.color}`}
        >
          {row.original.cLabel.text}
        </span>
      ),
    },
    {
      header: 'Estado técnico',
      id: 'estado_tecnico',
      cell: ({ row }) => (
        <span className="text-[10px] text-slate-600">
          {row.original.estado_tecnico || 'Pendiente'}
        </span>
      ),
    },
  ]

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

          <DataTable columns={columns} data={rowsData} />
        </>
      )}
    </div>
  )
}
