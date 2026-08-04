import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Info,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  ChevronDown,
  ChevronRight,
  HelpCircle,
  Layers,
  ShieldAlert,
  Download,
  CheckSquare,
  Save,
} from 'lucide-react'
import type {
  CalibrationMatrixResponse,
  CalibrationRow,
  CoverageType,
  EvidenceType,
  AggregatedRule,
  CalibrationQuestion,
} from '../types/calibration'
import type { CertificationCard } from '../types/certification'
import { calibrationService } from '../services/calibration'
import { CertificationStatusBadge } from './CertificationStatusBadge'
import { Input } from '@/shared/components/ui/input'

// ─── Readable labels ────────────────────────────────────────────────

const COVERAGE_LABELS: Record<
  CoverageType,
  { icon: typeof CheckCircle2; color: string; label: string; description: string }
> = {
  direct_rule: {
    icon: CheckCircle2,
    color: 'text-emerald-600',
    label: 'Cubierta por regla',
    description: 'Tiene una regla de validación técnica directamente asociada.',
  },
  aggregated_rule_candidate: {
    icon: Layers,
    color: 'text-blue-600',
    label: 'Candidata (mismo patrón)',
    description:
      'Comparte la misma estructura que la regla de la sección, pero no está certificada individualmente.',
  },
  partial_exception: {
    icon: AlertTriangle,
    color: 'text-amber-500',
    label: 'Excepción parcial',
    description: 'Tiene un patrón distinto al esperado. Requiere revisión.',
  },
  exception: {
    icon: ShieldAlert,
    color: 'text-red-500',
    label: 'Excepción',
    description: 'Su fórmula es diferente al patrón general de la sección.',
  },
  no_formula: {
    icon: XCircle,
    color: 'text-gray-400',
    label: 'Sin fórmula',
    description: 'No se detectó fórmula en el XLSM ni se pudo inferir un patrón.',
  },
  not_applicable: {
    icon: Info,
    color: 'text-gray-300',
    label: 'No aplica',
    description: 'Es encabezado, separador o fila no calibrable.',
  },
}

const EVIDENCE_LABELS: Record<EvidenceType, { label: string; description: string }> = {
  formula_xlsm: {
    label: 'Fórmula del XLSM',
    description: 'El validador encontró la fórmula directamente en el archivo REM.',
  },
  cell_level_xlsm: {
    label: 'Celda XLSM',
    description: 'La relacion se obtuvo desde formulas reales de la celda en el XLSM.',
  },
  structure_pattern: {
    label: 'Patrón de estructura',
    description:
      'La fórmula se infiere de la estructura del formulario. No está confirmada en el XLSM.',
  },
  inferred_candidate: {
    label: 'Inferida',
    description: 'Calculada a partir de las columnas habilitadas. Requiere verificación.',
  },
  no_evidence: {
    label: 'Sin evidencia',
    description: 'No hay información disponible sobre la fórmula de esta fila.',
  },
}

// ─── Coverage description helper ────────────────────────────────────

function coverageDescription(coverage: string): string {
  const c = COVERAGE_LABELS[coverage as CoverageType]
  return c?.description ?? ''
}

type FunctionalRule = NonNullable<CalibrationRow['functional_rules']>[number]
type FunctionalRuleStatus = 'pending' | 'propuesta' | 'aprobada' | 'rechazada'
type RowFunctionalVersion = {
  change_type?: string
  changed_at?: string
  changed_by?: string
  status_from?: string
  status_to?: string
}

function isMenAgeRule(rule: FunctionalRule) {
  return rule.total_column === 'C' && (rule.origin_columns?.length ?? 0) > 2
}

function isWomenAgeRule(rule: FunctionalRule) {
  return rule.total_column === 'D' && (rule.origin_columns?.length ?? 0) > 2
}

function isSexTotalRule(rule: FunctionalRule) {
  const origins = rule.origin_columns?.join(',')
  return (
    (rule.total_column === 'B' && origins === 'C,D') ||
    (rule.total_column === 'C' && origins === 'D,E')
  )
}

function functionalDestination(rule: FunctionalRule) {
  if (isSexTotalRule(rule)) return 'Ambos Sexos'
  if (isMenAgeRule(rule)) return 'Total Hombres'
  if (isWomenAgeRule(rule)) return 'Total Mujeres'
  return rule.destino_funcional || rule.destination || '—'
}

function functionalOrigin(rule: FunctionalRule) {
  if (isSexTotalRule(rule)) return 'Hombres + Mujeres'
  if (isMenAgeRule(rule)) return 'rangos etarios de Hombres'
  if (isWomenAgeRule(rule)) return 'rangos etarios de Mujeres'
  return rule.descripcion_funcional_origen || rule.origin_coordinates?.join(', ') || '—'
}

function functionalRuleLabel(rule: FunctionalRule, fallback?: string | null) {
  if (isSexTotalRule(rule)) return 'Ambos Sexos = Hombres + Mujeres'
  if (isMenAgeRule(rule)) return 'Total Hombres = suma de rangos etarios de Hombres'
  if (isWomenAgeRule(rule)) return 'Total Mujeres = suma de rangos etarios de Mujeres'
  return rule.label || fallback || '—'
}

function exceptionReason(reason?: string | null) {
  const value = (reason ?? '').toLowerCase()
  if (value.includes('formula') || value.includes('fórmula')) return 'fórmula distinta'
  if (value.includes('origen') || value.includes('columna')) return 'columnas de origen distintas'
  if (value.includes('bloque')) return 'celdas bloqueadas diferentes'
  if (value.includes('estructura')) return 'estructura distinta'
  return reason || 'estructura distinta'
}

function RuleTechnicalDetails({ rule }: { rule: FunctionalRule }) {
  return (
    <details className="mt-1 rounded border border-gray-200 bg-white px-2 py-1">
      <summary className="cursor-pointer text-[10px] font-medium text-indigo-600">
        Ver fórmula y celdas
      </summary>
      <div className="mt-1 space-y-0.5 text-[10px] text-gray-500">
        <div>
          Destino: <span className="font-mono">{rule.destination || rule.total_column || '—'}</span>
        </div>
        <div>
          Origen:{' '}
          <span className="font-mono">
            {rule.origin_coordinates?.join(', ') || rule.origin_columns?.join(', ') || '—'}
          </span>
        </div>
        <div>
          Fórmula:{' '}
          <span className="font-mono">{rule.formula_exacta || rule.formula_template || '—'}</span>
        </div>
      </div>
    </details>
  )
}

// ─── Main component ─────────────────────────────────────────────────

interface SectionCalibrationTableProps {
  data: CalibrationMatrixResponse | undefined
  loading: boolean
  sheet: string | undefined
  section: string | undefined
}

export function SectionCalibrationTable({
  data,
  loading,
  sheet,
  section,
}: SectionCalibrationTableProps) {
  const queryClient = useQueryClient()
  const [expandedRow, setExpandedRow] = useState<number | null>(null)
  const [filterCobertura, setFilterCobertura] = useState<string>('')
  const [showQuestions, setShowQuestions] = useState(false)
  const [showBulk, setShowBulk] = useState(false)

  const rows = useMemo(() => {
    if (!data?.rows) return []
    if (!filterCobertura) return data.rows
    return data.rows.filter((r) => r.cobertura === filterCobertura)
  }, [data, filterCobertura])

  const candidateRows = useMemo(() => {
    if (!data?.rows) return []
    return data.rows.filter((r) => r.cobertura === 'aggregated_rule_candidate').map((r) => r.row)
  }, [data])

  // Questions
  const { data: questionsData } = useQuery({
    queryKey: ['calibration-questions', sheet, section],
    queryFn: () => calibrationService.getQuestions(sheet!, section!),
    enabled: !!sheet && !!section && showQuestions,
  })

  const actualQuestions: CalibrationQuestion[] = questionsData?.questions ?? data?.questions ?? []

  return (
    <div className="space-y-4">
      {/* ── Summary cards ── */}
      <SummaryCards summary={data?.summary} />

      {/* ── Aggregated rule card ── */}
      {data?.aggregated_rules && data.aggregated_rules.length > 0 && (
        <AggregatedRuleCard rules={data.aggregated_rules} />
      )}

      {/* ── Action bar ── */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap gap-1.5 items-center">
          <span className="text-xs font-medium text-gray-500 mr-1">Filtrar:</span>
          {(
            [
              '',
              'direct_rule',
              'aggregated_rule_candidate',
              'exception',
              'no_formula',
              'not_applicable',
            ] as const
          ).map((v) => {
            const cfg = v ? COVERAGE_LABELS[v] : null
            return (
              <button
                key={v}
                onClick={() => setFilterCobertura(v)}
                className={`px-2 py-1 rounded-md text-xs font-medium transition-colors ${
                  filterCobertura === v
                    ? 'bg-indigo-100 text-indigo-700 ring-1 ring-indigo-300'
                    : 'bg-gray-50 text-gray-500 hover:bg-gray-100'
                }`}
              >
                {v ? (cfg?.label ?? v) : 'Todas las filas'}
                {v && data && (
                  <span className="ml-1 opacity-60">
                    ({data.rows.filter((r) => !v || r.cobertura === (v as CoverageType)).length})
                  </span>
                )}
              </button>
            )
          })}
        </div>
        <div className="flex gap-2">
          {candidateRows.length > 0 && (
            <button
              onClick={() => setShowBulk(true)}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors"
            >
              <CheckSquare className="w-3.5 h-3.5" />
              Decisión masiva ({candidateRows.length} filas)
            </button>
          )}
          <button
            onClick={() => setShowQuestions(!showQuestions)}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              showQuestions
                ? 'bg-amber-100 text-amber-800 ring-1 ring-amber-300'
                : 'bg-amber-50 text-amber-700 hover:bg-amber-100'
            }`}
          >
            <HelpCircle className="w-3.5 h-3.5" />
            Preguntas para Estadística ({actualQuestions.length})
          </button>
          {sheet && section && (
            <a
              href={calibrationService.getCalibrationExportUrl(sheet, section)}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-green-50 text-green-700 hover:bg-green-100 transition-colors"
            >
              <Download className="w-3.5 h-3.5" />
              Exportar Excel
            </a>
          )}
        </div>
      </div>

      {/* ── Questions panel ── */}
      {showQuestions && (
        <QuestionsPanel
          questions={actualQuestions as EditableQuestion[]}
          sheet={sheet}
          section={section}
          onClose={() => setShowQuestions(false)}
        />
      )}

      {/* ── Bulk decision dialog ── */}
      {showBulk && sheet && section && (
        <BulkDecisionDialog
          sheet={sheet}
          section={section}
          candidateRows={candidateRows}
          onClose={() => setShowBulk(false)}
          onSaved={() => {
            setShowBulk(false)
            queryClient.invalidateQueries({ queryKey: ['calibration-matrix', sheet, section] })
          }}
        />
      )}

      {/* ── Main table ── */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-2 py-2.5 w-8"></th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Fila
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Tipo
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Cobertura técnica
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Estado
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Destino
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Origen
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Evidencia
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Regla
                </th>
                <th className="px-3 py-2.5 text-left font-semibold text-gray-600 text-xs uppercase">
                  Decisión funcional
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <tr key={i}>
                    {Array.from({ length: 10 }).map((_, j) => (
                      <td key={j} className="px-3 py-2.5">
                        <div className="h-4 bg-gray-100 rounded animate-pulse" />
                      </td>
                    ))}
                  </tr>
                ))
              ) : rows.length === 0 ? (
                <tr>
                  <td colSpan={10} className="px-3 py-8 text-center text-sm text-gray-400">
                    No hay filas que coincidan con el filtro.
                  </td>
                </tr>
              ) : (
                rows.map((r) => (
                  <CalibrationRowComponent
                    key={r.row}
                    row={r}
                    sheet={sheet ?? 'A01'}
                    section={section ?? 'A'}
                    isExpanded={expandedRow === r.row}
                    onToggle={() => setExpandedRow(expandedRow === r.row ? null : r.row)}
                  />
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* ── Footer info ── */}
      <div className="flex justify-between items-center text-xs text-gray-400">
        <span>
          {data?.summary ? (
            <>
              <strong className="text-gray-600">{data.summary.total_filas_datos}</strong> filas
              calibrables
              {' · '}
              <strong className="text-gray-600">{data.summary.total_headers}</strong> encabezados
              (no calibrables)
              {' · '}
              <strong className="text-gray-600">{data.summary.total_filas_fisicas}</strong> filas
              físicas
            </>
          ) : (
            '—'
          )}
        </span>
        <span>{rows.length} filas mostradas</span>
      </div>
    </div>
  )
}

// ─── Row component ──────────────────────────────────────────────────

function CalibrationRowComponent({
  row,
  sheet,
  section,
  isExpanded,
  onToggle,
}: {
  row: CalibrationRow
  sheet: string
  section: string
  isExpanded: boolean
  onToggle: () => void
}) {
  const cfg = COVERAGE_LABELS[row.cobertura] || COVERAGE_LABELS.no_formula
  const Icon = cfg.icon
  const isHeader = row.row_type === 'header'
  const functionalRules = row.functional_rules?.length
    ? row.functional_rules
    : [
        {
          total_column: row.columna_total,
          destination: row.destino_exacto ?? '',
          destino_funcional: row.destino_funcional ?? row.destino_exacto ?? '—',
          origin_columns: row.origen_columnas,
          origin_coordinates: row.origen_efectivo,
          descripcion_funcional_origen: row.descripcion_funcional_origen ?? '',
          formula_exacta: row.formula_efectiva ?? '',
          formula_template: '',
          label: row.regla_funcional_label ?? '',
        },
      ]
  const [editing, setEditing] = useState(false)
  const funcRow = row.funcional_por_fila
  const funcStatus = funcRow?.status ?? 'pending'

  const funcStatusLabel = (s: string) => {
    if (s === 'validada') return 'Validada'
    if (s === 'rechazada') return 'Rechazada'
    if (s === 'propuesta') return 'Propuesta'
    return 'Pendiente'
  }

  const funcStatusColor = (s: string) => {
    if (s === 'validada') return 'bg-emerald-50 text-emerald-700'
    if (s === 'rechazada') return 'bg-red-50 text-red-700'
    if (s === 'propuesta') return 'bg-blue-50 text-blue-700'
    return 'bg-gray-50 text-gray-500'
  }

  return (
    <>
      <tr
        className={`hover:bg-gray-50 transition-colors cursor-pointer ${isHeader ? 'bg-gray-50/50' : ''}`}
        onClick={onToggle}
      >
        <td className="px-2 py-2 text-center">
          {isExpanded ? (
            <ChevronDown className="w-3.5 h-3.5 text-gray-300 inline" />
          ) : (
            <ChevronRight className="w-3.5 h-3.5 text-gray-300 inline" />
          )}
        </td>
        <td className="px-3 py-2">
          <div className="font-mono text-xs font-medium text-gray-700">{row.row}</div>
          {(row.concepto || row.profesional) && (
            <div className="text-[10px] text-gray-500 leading-tight mt-0.5">
              {[row.concepto, row.profesional].filter(Boolean).join(' · ')}
            </div>
          )}
        </td>
        <td className="px-3 py-2 text-xs">
          <RowTypeBadge type={row.row_type} />
        </td>
        <td className="px-3 py-2">
          <div className="flex items-center gap-1.5">
            <Icon className={`w-3.5 h-3.5 ${cfg.color}`} />
            <span className={`text-xs font-medium ${cfg.color}`}>{cfg.label}</span>
            {row.cobertura === 'aggregated_rule_candidate' && (
              <span className="text-[10px] bg-blue-50 text-blue-500 px-1 rounded font-medium">
                No certificada
              </span>
            )}
            {row.cobertura === 'direct_rule' && (
              <span className="text-[10px] bg-emerald-50 text-emerald-500 px-1 rounded font-medium">
                Certificada
              </span>
            )}
            {row.es_excepcion && <ShieldAlert className="w-3 h-3 text-red-500" />}
          </div>
          <div className="text-[10px] text-gray-400 mt-0.5 leading-tight max-w-[200px]">
            {coverageDescription(row.cobertura)}
          </div>
        </td>
        <td className="px-3 py-2">
          <TechnicalStatusBadge status={row.estado_tecnico} />
        </td>
        <td className="px-3 py-2 text-xs">
          <div className="space-y-1">
            {functionalRules.map((rule) => (
              <div
                key={`${row.row}-${rule.total_column}-destination`}
                className="font-medium text-gray-800"
              >
                {functionalDestination(rule)}
              </div>
            ))}
          </div>
        </td>
        <td className="px-3 py-2 text-xs max-w-[200px]">
          <div className="space-y-1">
            {functionalRules.map((rule) => (
              <div key={`${row.row}-${rule.total_column}-origin`}>
                <span className="text-gray-700">{functionalOrigin(rule)}</span>
                <RuleTechnicalDetails rule={rule} />
              </div>
            ))}
          </div>
        </td>
        <td className="px-3 py-2 text-xs">
          <EvidenceBadge type={row.tipo_evidencia} />
        </td>
        <td className="px-3 py-2 text-xs max-w-[160px]">
          {row.regla_funcional_label ? (
            <>
              <div className="space-y-1">
                {functionalRules.map((rule) => (
                  <div
                    key={`${row.row}-${rule.total_column}-rule`}
                    className="font-medium text-gray-700"
                  >
                    {functionalRuleLabel(rule, row.regla_funcional_label)}
                  </div>
                ))}
              </div>
              {row.aggregated_rule_key && (
                <div className="text-[10px] text-gray-400 font-mono mt-0.5">
                  {row.aggregated_rule_key}
                </div>
              )}
            </>
          ) : row.aggregated_rule_key ? (
            <span className="font-mono text-xs text-gray-500">{row.aggregated_rule_key}</span>
          ) : (
            <span className="text-gray-300">—</span>
          )}
        </td>
        <td className="px-3 py-2 text-xs">
          <div className="flex items-center gap-1">
            <span
              className={`px-1.5 py-0.5 rounded text-xs font-medium whitespace-nowrap ${funcStatusColor(funcStatus)}`}
            >
              {funcStatusLabel(funcStatus)}
            </span>
            {row.row_type === 'data' && (
              <button
                onClick={(e) => {
                  e.stopPropagation()
                  setEditing(true)
                }}
                className="text-[10px] text-indigo-500 hover:text-indigo-700 ml-1 shrink-0"
                title="Editar decisión funcional"
              >
                Editar
              </button>
            )}
          </div>
        </td>
      </tr>
      {isExpanded && (
        <tr className="bg-gray-50/50">
          <td colSpan={10} className="px-6 py-4">
            <RowDetailPanel
              row={row}
              sheet={sheet}
              section={section}
              editing={editing}
              setEditing={setEditing}
            />
          </td>
        </tr>
      )}
    </>
  )
}

// ─── Sub-components ─────────────────────────────────────────────────

function RowTypeBadge({ type }: { type: string }) {
  const config: Record<string, { bg: string; text: string; label: string }> = {
    header: { bg: 'bg-purple-50', text: 'text-purple-600', label: 'Encabezado' },
    data: { bg: 'bg-blue-50', text: 'text-blue-600', label: 'Dato' },
    subtotal: { bg: 'bg-amber-50', text: 'text-amber-600', label: 'Subtotal' },
    total: { bg: 'bg-emerald-50', text: 'text-emerald-600', label: 'Total' },
    special: { bg: 'bg-amber-50', text: 'text-amber-700', label: 'Fila especial' },
    spacer: { bg: 'bg-gray-50', text: 'text-gray-400', label: 'Separador' },
    not_applicable: { bg: 'bg-gray-50', text: 'text-gray-400', label: 'N/A' },
  }
  const c = config[type] ?? { bg: 'bg-gray-50', text: 'text-gray-500', label: type }
  return (
    <span className={`inline-block px-1.5 py-0.5 rounded text-xs font-medium ${c.bg} ${c.text}`}>
      {c.label}
    </span>
  )
}

function TechnicalStatusBadge({ status }: { status: string }) {
  if (status === 'No aplica') {
    return <span className="text-xs text-gray-300 italic">—</span>
  }
  if (status === 'Sin regla directa') {
    return <span className="text-xs text-blue-500 italic">Sin regla propia</span>
  }
  return <CertificationStatusBadge estado={status as CertificationCard['estado']} />
}

function EvidenceBadge({ type }: { type: EvidenceType }) {
  const c = EVIDENCE_LABELS[type] ?? { label: type, description: '' }
  return (
    <span className="group relative inline-block" title={c.description}>
      <span
        className={`inline-block px-1.5 py-0.5 rounded text-xs font-medium cursor-help ${
          type === 'formula_xlsm'
            ? 'bg-emerald-50 text-emerald-700'
            : type === 'structure_pattern'
              ? 'bg-blue-50 text-blue-700'
              : type === 'inferred_candidate'
                ? 'bg-amber-50 text-amber-700'
                : 'bg-gray-50 text-gray-400'
        }`}
      >
        {type === 'formula_xlsm'
          ? 'Directa'
          : type === 'structure_pattern'
            ? 'Estructura'
            : type === 'inferred_candidate'
              ? 'Inferida'
              : 'Sin dato'}
      </span>
    </span>
  )
}

function RowDetailPanel({
  row,
  sheet,
  section,
  editing,
  setEditing,
}: {
  row: CalibrationRow
  sheet: string
  section: string
  editing: boolean
  setEditing: (v: boolean) => void
}) {
  const [showTecnico, setShowTecnico] = useState(false)
  const [showVersions, setShowVersions] = useState(false)
  const [versions, setVersions] = useState<RowFunctionalVersion[]>([])
  const [loadingVersions, setLoadingVersions] = useState(false)
  const functionalRules = row.functional_rules?.length
    ? row.functional_rules
    : [
        {
          total_column: row.columna_total,
          destination: row.destino_exacto ?? '',
          destino_funcional: row.destino_funcional ?? row.destino_exacto ?? '—',
          origin_columns: row.origen_columnas,
          origin_coordinates: row.origen_efectivo,
          descripcion_funcional_origen: row.descripcion_funcional_origen ?? '',
          formula_exacta: row.formula_efectiva ?? '',
          formula_template: '',
          label: row.regla_funcional_label ?? '',
        },
      ]
  const summedColumns = Array.from(
    new Set(functionalRules.flatMap((rule) => rule.origin_columns ?? []))
  )
  const totalColumns = Array.from(
    new Set(functionalRules.map((rule) => rule.total_column).filter(Boolean))
  )

  const loadVersions = async () => {
    setLoadingVersions(true)
    try {
      const res = await calibrationService.getRowFunctionalVersions(sheet, section, row.row)
      setVersions(res.versions)
    } catch {
      setVersions([])
    } finally {
      setLoadingVersions(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="space-y-2">
          <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
            Identificación
          </h4>
          <div className="bg-white rounded-lg border border-gray-200 p-3 space-y-1.5">
            <DetailItem label="Fila" value={String(row.row)} />
            <DetailItem label="Concepto" value={row.concepto ?? '—'} />
            <DetailItem label="Profesional" value={row.profesional ?? '—'} />
            <DetailItem
              label="Tipo"
              value={
                row.row_type === 'header'
                  ? 'Encabezado'
                  : row.row_type === 'data'
                    ? 'Dato'
                    : row.row_type
              }
            />
            <DetailItem
              label="Regla"
              value={
                functionalRules
                  .map((rule) => rule.label)
                  .filter(Boolean)
                  .join(' | ') ||
                row.rule_key ||
                'Ninguna'
              }
            />
            <DetailItem
              label="Cobertura"
              value={COVERAGE_LABELS[row.cobertura]?.label ?? row.cobertura}
            />
            {row.es_excepcion && (
              <div className="mt-2 p-2 bg-red-50 rounded text-xs text-red-700">
                <strong>Motivo de la excepción:</strong> {exceptionReason(row.excepcion_razon)}
              </div>
            )}
          </div>
        </div>
        <div className="space-y-2">
          <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
            Descripción funcional
          </h4>
          <div className="bg-white rounded-lg border border-gray-200 p-3 space-y-1.5">
            <DetailItem
              label="Destino"
              value={
                functionalRules
                  .map((rule) => functionalDestination(rule))
                  .filter(Boolean)
                  .join(' | ') || '—'
              }
            />
            <DetailItem
              label="Origen"
              value={
                functionalRules
                  .map((rule) => functionalOrigin(rule))
                  .filter(Boolean)
                  .join(' | ') || '—'
              }
            />
            <DetailItem
              label="Regla"
              value={
                functionalRules
                  .map((rule) => functionalRuleLabel(rule, row.regla_funcional_label))
                  .filter(Boolean)
                  .join(' | ') || '—'
              }
            />
            {summedColumns.length > 0 && (
              <div className="mt-1 text-[10px] text-gray-400">
                Columnas tecnicas: {summedColumns.join(', ')}
              </div>
            )}
          </div>
        </div>
        <div className="space-y-2">
          <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
            Columnas del formulario
          </h4>
          <div className="bg-white rounded-lg border border-gray-200 p-3">
            <div className="grid grid-cols-4 gap-1">
              {row.columnas_habilitadas.map((c) => (
                <div
                  key={c.letra}
                  className={`text-center p-1 rounded text-[10px] font-medium leading-tight ${
                    c.es_bloqueada
                      ? 'bg-gray-100 text-gray-300'
                      : summedColumns.includes(c.letra)
                        ? 'bg-emerald-50 text-emerald-700'
                        : totalColumns.includes(c.letra) || c.es_total
                          ? 'bg-indigo-50 text-indigo-700'
                          : 'bg-blue-50 text-blue-600'
                  }`}
                  title={c.label}
                >
                  {c.letra}
                </div>
              ))}
            </div>
            <div className="mt-2 flex flex-wrap gap-2 text-[10px] text-gray-400">
              <span>
                <span className="inline-block w-2 h-2 rounded bg-emerald-50 border border-emerald-200 mr-1" />{' '}
                Sumada
              </span>
              <span>
                <span className="inline-block w-2 h-2 rounded bg-indigo-50 border border-indigo-200 mr-1" />{' '}
                Total
              </span>
              <span>
                <span className="inline-block w-2 h-2 rounded bg-blue-50 border border-blue-200 mr-1" />{' '}
                Dato
              </span>
              <span>
                <span className="inline-block w-2 h-2 rounded bg-gray-100 border border-gray-200 mr-1" />{' '}
                Bloqueada
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* ── Inline functional editor ── */}
      {row.row_type === 'data' && (
        <FunctionalRuleEditor
          row={row}
          sheet={sheet}
          section={section}
          editing={editing}
          setEditing={setEditing}
        />
      )}

      <div className="border-t border-gray-200 pt-3 space-y-3">
        <button
          onClick={() => setShowTecnico(!showTecnico)}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors"
        >
          {showTecnico ? <ChevronDown className="w-3 h-3" /> : <ChevronRight className="w-3 h-3" />}
          Ver detalle técnico
        </button>
        {showTecnico && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-white rounded-lg border border-gray-200 p-3 space-y-1.5">
              <h5 className="text-[10px] font-semibold text-gray-500 uppercase tracking-wider mb-2">
                Fórmula de validación
              </h5>
              <DetailItem label="Regla técnica" value={row.rule_key ?? '—'} />
              <DetailItem
                label="Destino exacto"
                value={
                  functionalRules
                    .map((rule) => rule.destination)
                    .filter(Boolean)
                    .join(' | ') ||
                  row.destino_exacto ||
                  '—'
                }
              />
              <DetailItem
                label="Origen efectivo"
                value={
                  functionalRules.flatMap((rule) => rule.origin_coordinates).join(', ') ||
                  row.origen_efectivo.join(', ') ||
                  '—'
                }
              />
              <DetailItem
                label="Evidencia"
                value={EVIDENCE_LABELS[row.tipo_evidencia]?.label ?? row.tipo_evidencia}
              />
              {row.formula_efectiva && (
                <div className="mt-2">
                  <span className="text-xs text-gray-500">Fórmula detectada:</span>
                  <pre className="mt-0.5 text-xs font-mono bg-emerald-50 p-1.5 rounded border border-emerald-100 text-emerald-700 whitespace-pre-wrap">
                    {functionalRules
                      .map((rule) => rule.formula_exacta)
                      .filter(Boolean)
                      .join(' | ') || row.formula_efectiva}
                  </pre>
                </div>
              )}
              {row.formula_candidata && row.formula_candidata !== row.formula_efectiva && (
                <div className="mt-1">
                  <span className="text-xs text-gray-500">Fórmula inferida (no confirmada):</span>
                  <pre className="mt-0.5 text-xs font-mono bg-amber-50 p-1.5 rounded border border-amber-100 text-amber-700 whitespace-pre-wrap">
                    {row.formula_candidata}
                  </pre>
                </div>
              )}
            </div>
            <div className="bg-white rounded-lg border border-gray-200 p-3 space-y-1.5">
              <h5 className="text-[10px] font-semibold text-gray-500 uppercase tracking-wider mb-2">
                Patrón detectado
              </h5>
              <DetailItem
                label="Patrón"
                value={
                  summedColumns.length <= 1
                    ? 'Conteo directo'
                    : `Suma de ${summedColumns.length} columnas`
                }
              />
              <DetailItem label="Columnas sumadas" value={summedColumns.join(', ') || '—'} />
              <DetailItem
                label="Columna total"
                value={totalColumns.join(', ') || row.columna_total}
              />
              <DetailItem label="Regla agregada" value={row.aggregated_rule_key ?? '—'} />
              <DetailItem label="Tipo de regla" value={row.rule_type ?? '—'} />
            </div>
          </div>
        )}

        {/* ── Version history ── */}
        <button
          onClick={() => {
            setShowVersions(!showVersions)
            if (!showVersions && versions.length === 0) loadVersions()
          }}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors"
        >
          {showVersions ? (
            <ChevronDown className="w-3 h-3" />
          ) : (
            <ChevronRight className="w-3 h-3" />
          )}
          Historial de versiones
        </button>
        {showVersions && (
          <div className="bg-white rounded-lg border border-gray-200 p-3">
            {loadingVersions ? (
              <div className="text-xs text-gray-400">Cargando...</div>
            ) : versions.length === 0 ? (
              <div className="text-xs text-gray-400">Sin versiones registradas.</div>
            ) : (
              <div className="space-y-2 max-h-48 overflow-y-auto">
                {[...versions].reverse().map((v, i) => (
                  <div
                    key={i}
                    className="text-[11px] border-b border-gray-100 pb-1.5 last:border-0"
                  >
                    <div className="flex items-center gap-2">
                      <span
                        className={`px-1 rounded text-[9px] font-medium ${
                          v.change_type === 'create'
                            ? 'bg-green-100 text-green-700'
                            : v.change_type === 'status_change'
                              ? 'bg-amber-100 text-amber-700'
                              : 'bg-blue-100 text-blue-700'
                        }`}
                      >
                        {v.change_type === 'create'
                          ? 'Creación'
                          : v.change_type === 'status_change'
                            ? 'Cambio estado'
                            : 'Actualización'}
                      </span>
                      <span className="text-gray-400">
                        {v.changed_at ? new Date(v.changed_at).toLocaleString('es-CL') : ''}
                      </span>
                    </div>
                    {v.changed_by && (
                      <div className="text-gray-500 mt-0.5">Por: {v.changed_by}</div>
                    )}
                    {v.change_type === 'status_change' && (
                      <div className="text-gray-500 mt-0.5">
                        Estado: {v.status_from} → {v.status_to}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function FunctionalRuleEditor({
  row,
  sheet,
  section,
  editing,
  setEditing,
}: {
  row: CalibrationRow
  sheet: string
  section: string
  editing: boolean
  setEditing: (v: boolean) => void
}) {
  const queryClient = useQueryClient()
  const [saving, setSaving] = useState(false)
  const [emptyBehavior, setEmptyBehavior] = useState(row.funcional_por_fila?.empty_behavior ?? '')
  const [includedCenters, setIncludedCenters] = useState(
    row.funcional_por_fila?.included_health_centers?.join(', ') ?? ''
  )
  const [excludedCenters, setExcludedCenters] = useState(
    row.funcional_por_fila?.excluded_health_centers?.join(', ') ?? ''
  )
  const [functionalCondition, setFunctionalCondition] = useState(
    row.funcional_por_fila?.functional_condition ?? ''
  )
  const [justification, setJustification] = useState(row.funcional_por_fila?.justification ?? '')
  const [informedBy, setInformedBy] = useState(row.funcional_por_fila?.informed_by ?? '')
  const [funcStatus, setFuncStatus] = useState(row.funcional_por_fila?.status ?? 'pending')
  const [saveError, setSaveError] = useState('')
  const [saved, setSaved] = useState(false)

  if (!editing) return null

  const handleSave = async () => {
    setSaving(true)
    setSaveError('')
    setSaved(false)
    try {
      await calibrationService.saveRowFunctionalRule(sheet, section, row.row, {
        empty_behavior: emptyBehavior || null,
        applies_to_types: [],
        included_health_centers: includedCenters
          ? includedCenters
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        excluded_health_centers: excludedCenters
          ? excludedCenters
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        functional_condition: functionalCondition,
        justification,
        informed_by: informedBy,
        updated_by: informedBy,
        status: funcStatus,
      })
      setSaved(true)
      queryClient.invalidateQueries({ queryKey: ['calibration-matrix'] })
      setTimeout(() => {
        setEditing(false)
        setSaved(false)
      }, 1000)
    } catch (err: unknown) {
      setSaveError(err instanceof Error ? err.message : 'Error al guardar')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="bg-white rounded-lg border border-indigo-200 p-4 space-y-3">
      <h4 className="text-xs font-semibold text-indigo-700 uppercase tracking-wider flex items-center gap-2">
        <Save className="w-3.5 h-3.5" />
        Editor de decisión funcional — Fila {row.row}
      </h4>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
            Comportamiento si está vacío
          </label>
          <select
            value={emptyBehavior}
            onChange={(e) => setEmptyBehavior(e.target.value)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
          >
            <option value="">Seleccione...</option>
            <option value="puede_quedar_vacio">Puede quedar vacío</option>
            <option value="debe_registrar_cero">Debe registrar cero</option>
            <option value="debe_tener_al_menos_un_valor">Debe tener al menos un valor</option>
            <option value="no_aplica">No aplica</option>
            <option value="pendiente_definicion">Pendiente de definición</option>
          </select>
        </div>
        <div>
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">Estado</label>
          <select
            value={funcStatus}
            onChange={(e) => setFuncStatus(e.target.value as FunctionalRuleStatus)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
          >
            <option value="pending">Sin revisar</option>
            <option value="propuesta">Propuesta</option>
            <option value="aprobada">Aprobada</option>
            <option value="rechazada">Rechazada</option>
          </select>
        </div>
        <div>
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
            Informado por
          </label>
          <input
            value={informedBy}
            onChange={(e) => setInformedBy(e.target.value)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
            placeholder="Nombre y cargo"
          />
        </div>
        <div>
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
            Establecimientos incluidos
          </label>
          <input
            value={includedCenters}
            onChange={(e) => setIncludedCenters(e.target.value)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
            placeholder="Separados por coma"
          />
        </div>
        <div>
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
            Establecimientos excluidos
          </label>
          <input
            value={excludedCenters}
            onChange={(e) => setExcludedCenters(e.target.value)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
            placeholder="Separados por coma"
          />
        </div>
        <div>
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
            Condición funcional
          </label>
          <input
            value={functionalCondition}
            onChange={(e) => setFunctionalCondition(e.target.value)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
            placeholder="Condición"
          />
        </div>
        <div className="md:col-span-2 lg:col-span-3">
          <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
            Justificación
          </label>
          <textarea
            value={justification}
            onChange={(e) => setJustification(e.target.value)}
            className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none h-14 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
            placeholder="Justificación de la decisión..."
          />
        </div>
      </div>

      <div className="flex items-center gap-2 pt-1">
        <button
          onClick={handleSave}
          disabled={saving}
          className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
        >
          {saving ? 'Guardando...' : 'Guardar decisión'}
        </button>
        <button
          onClick={() => setEditing(false)}
          className="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-100 transition-colors"
        >
          Cancelar
        </button>
        {saveError && <span className="text-[10px] text-red-500">{saveError}</span>}
        {saved && <span className="text-[10px] text-emerald-600">Guardado correctamente</span>}
      </div>
    </div>
  )
}

function DetailItem({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between items-start gap-2">
      <span className="text-xs text-gray-500 shrink-0">{label}:</span>
      <span className="text-xs font-medium text-gray-800 text-right break-all">{value}</span>
    </div>
  )
}

// ─── Summary cards ──────────────────────────────────────────────────

function SummaryCards({ summary }: { summary: CalibrationMatrixResponse['summary'] | undefined }) {
  if (!summary) return null
  const cards = [
    {
      label: 'Filas físicas',
      value: summary.total_filas_fisicas,
      color: 'text-gray-900',
      desc: 'Total en el rango',
    },
    {
      label: 'Datos calibrables',
      value: summary.total_filas_datos,
      color: 'text-blue-600',
      desc: 'Filas con información',
    },
    {
      label: 'Encabezados',
      value: summary.total_headers,
      color: 'text-purple-500',
      desc: 'No se calibran',
    },
    {
      label: 'Cubiertas por regla',
      value: summary.cubiertas_directas,
      color: 'text-emerald-600',
      desc: 'Tienen regla asociada',
    },
    {
      label: 'Candidatas',
      value: summary.candidatas_agregadas,
      color: 'text-blue-500',
      desc: 'Mismo patrón, sin certificar',
    },
    {
      label: 'Excepciones',
      value: summary.excepciones,
      color: 'text-red-500',
      desc: 'Patrón distinto',
    },
    {
      label: 'Técnicamente certificadas',
      value: summary.certificadas_tecnicamente,
      color: 'text-indigo-600',
      desc: 'Por el equipo técnico',
    },
    {
      label: 'Pendientes funcionales',
      value: summary.pendientes_definicion_funcional,
      color: 'text-orange-500',
      desc: 'Esperan decisión de Estadística',
    },
  ]

  return (
    <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-1.5">
      {cards.map((c) => (
        <div
          key={c.label}
          className="bg-white rounded-lg border border-gray-200 p-2 text-center shadow-sm"
        >
          <div className={`text-base font-bold ${c.color}`}>{c.value}</div>
          <div className="text-[10px] text-gray-500 mt-0.5 leading-tight">{c.label}</div>
          <div className="text-[9px] text-gray-400 mt-0.5 leading-tight">{c.desc}</div>
        </div>
      ))}
    </div>
  )
}

// ─── Aggregated rule card ───────────────────────────────────────────

function AggregatedRuleCard({ rules }: { rules: AggregatedRule[] }) {
  const [expanded, setExpanded] = useState(false)
  if (rules.length === 0) return null

  return (
    <div className="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200 p-4 shadow-sm">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Layers className="w-5 h-5 text-blue-600" />
          <h3 className="text-sm font-semibold text-blue-900">Regla general de la sección</h3>
        </div>
        <button
          onClick={() => setExpanded(!expanded)}
          className="text-xs text-blue-600 hover:text-blue-800 font-medium"
        >
          {expanded ? 'Ocultar' : 'Ver detalle'}
        </button>
      </div>

      {rules.map((ag) => (
        <div key={ag.rule_key} className="mt-2">
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-blue-700">
            <span className="font-mono font-semibold">{ag.rule_key}</span>
            <span>
              Patrón: <span className="font-mono">{ag.patron_general}</span>
            </span>
            <span>
              Filas directas: <span className="font-semibold">{ag.total_filas_directas}</span>
            </span>
            <span>
              Filas candidatas: <span className="font-semibold">{ag.total_filas_candidatas}</span>
            </span>
          </div>
          {/* Readable explanation */}
          <p className="mt-1.5 text-xs text-blue-600">
            Esta regla verifica que el <strong>TOTAL</strong> (columna C) sea igual a la suma de las
            columnas de rango etario <strong>F–N</strong> para cada fila. La fila{' '}
            <strong>11</strong> tiene la fórmula confirmada en el XLSM original. Las filas{' '}
            <strong>12 a 32</strong> comparten la misma estructura pero no tienen certificación
            individual.
          </p>
          {expanded && (
            <div className="mt-2 flex flex-wrap gap-6 text-xs text-blue-600">
              <div>
                <span className="text-blue-500">Directas:</span>{' '}
                <span className="ml-1 font-mono">{ag.filas_directas.join(', ') || '—'}</span>
              </div>
              <div>
                <span className="text-blue-500">Candidatas:</span>{' '}
                <span className="ml-1 font-mono">
                  {ag.filas_candidatas.length > 5
                    ? `Filas ${ag.filas_candidatas[0]} a ${ag.filas_candidatas[ag.filas_candidatas.length - 1]} (${ag.total_filas_candidatas} filas)`
                    : ag.filas_candidatas.join(', ')}
                </span>
              </div>
              <div>
                <span className="text-blue-500">Columnas origen:</span>{' '}
                <span className="ml-1 font-mono">{ag.columnas_origen.join(', ')}</span>
              </div>
              <div>
                <span className="text-blue-500">Destino:</span>{' '}
                <span className="ml-1 font-mono">{ag.columna_destino ?? 'C'}</span>
              </div>
            </div>
          )}
        </div>
      ))}
    </div>
  )
}

// ─── Editable Question type ─────────────────────────────────────────

type EditableQuestion = CalibrationQuestion & {
  response?: string
  observation?: string
  responsible?: string
  date?: string
  status?: 'pending' | 'answered' | 'clarification'
}

// ─── Questions Panel ────────────────────────────────────────────────

function QuestionsPanel({
  questions,
  sheet,
  section,
  onClose,
}: {
  questions: EditableQuestion[]
  sheet: string | undefined
  section: string | undefined
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const [localQuestions, setLocalQuestions] = useState<EditableQuestion[]>(questions)

  const saveMutation = useMutation({
    mutationFn: () => calibrationService.saveQuestions(sheet!, section!, localQuestions),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['calibration-questions', sheet, section] })
    },
  })

  const updateQuestion = (index: number, field: string, value: string) => {
    setLocalQuestions((prev) => {
      const next = [...prev]
      next[index] = { ...next[index], [field]: value }
      return next
    })
  }

  if (localQuestions.length === 0) {
    return (
      <div className="bg-amber-50 rounded-xl border border-amber-200 p-4 text-sm text-amber-700">
        No hay preguntas generadas para esta sección.
      </div>
    )
  }

  return (
    <div className="bg-amber-50 rounded-xl border border-amber-200 p-4 shadow-sm">
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <HelpCircle className="w-4 h-4 text-amber-600" />
          <h3 className="text-sm font-semibold text-amber-900">
            Preguntas para Estadística ({localQuestions.length})
          </h3>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => saveMutation.mutate()}
            disabled={saveMutation.isPending}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50 transition-colors"
          >
            <Save className="w-3.5 h-3.5" />
            {saveMutation.isPending ? 'Guardando...' : 'Guardar respuestas'}
          </button>
          <button
            onClick={onClose}
            className="text-xs text-amber-600 hover:text-amber-800 font-medium"
          >
            Cerrar
          </button>
        </div>
      </div>

      {saveMutation.isSuccess && (
        <div className="mb-3 p-2 bg-emerald-50 border border-emerald-200 rounded text-xs text-emerald-700">
          Respuestas guardadas correctamente.
        </div>
      )}

      <div className="space-y-3 max-h-[500px] overflow-y-auto">
        {localQuestions.map((q, i) => (
          <div key={i} className="bg-white rounded-lg border border-amber-100 p-3 space-y-2">
            <div className="flex items-start gap-2">
              <span className="text-amber-400 font-mono text-xs shrink-0 mt-0.5">
                {q.row ? `Fila ${q.row}` : 'General'}
              </span>
              <p className="text-xs text-amber-800">{q.question}</p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
              <div>
                <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
                  Respuesta
                </label>
                <textarea
                  className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none focus:border-amber-300 focus:ring-1 focus:ring-amber-300 outline-none"
                  rows={2}
                  value={q.response ?? ''}
                  onChange={(e) => updateQuestion(i, 'response', e.target.value)}
                  placeholder="Escriba la respuesta..."
                />
              </div>
              <div>
                <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
                  Observación
                </label>
                <textarea
                  className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none focus:border-amber-300 focus:ring-1 focus:ring-amber-300 outline-none"
                  rows={2}
                  value={q.observation ?? ''}
                  onChange={(e) => updateQuestion(i, 'observation', e.target.value)}
                  placeholder="Observaciones adicionales..."
                />
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
              <div>
                <label className="text-[10px] font-medium text-gray-500 block mb-0.5">
                  Responsable
                </label>
                <Input
                  className="text-xs h-7"
                  value={q.responsible ?? ''}
                  onChange={(e) => updateQuestion(i, 'responsible', e.target.value)}
                  placeholder="Nombre o cargo"
                />
              </div>
              <div>
                <label className="text-[10px] font-medium text-gray-500 block mb-0.5">Fecha</label>
                <Input
                  className="text-xs h-7"
                  type="date"
                  value={q.date ?? ''}
                  onChange={(e) => updateQuestion(i, 'date', e.target.value)}
                />
              </div>
              <div>
                <label className="text-[10px] font-medium text-gray-500 block mb-0.5">Estado</label>
                <select
                  className="w-full rounded border border-gray-200 px-2 py-1 text-xs h-7 outline-none focus:border-amber-300 focus:ring-1 focus:ring-amber-300"
                  value={q.status ?? 'pending'}
                  onChange={(e) => updateQuestion(i, 'status', e.target.value)}
                >
                  <option value="pending">Pendiente</option>
                  <option value="answered">Respondida</option>
                  <option value="clarification">Requiere aclaración</option>
                </select>
              </div>
            </div>
          </div>
        ))}
      </div>

      <p className="mt-2 text-[10px] text-gray-400">
        Las respuestas se guardan localmente y no modifican el Rule Engine ni las reglas activas.
      </p>
    </div>
  )
}

// ─── Bulk Decision Dialog ───────────────────────────────────────────

interface BulkDecisionDialogProps {
  sheet: string
  section: string
  candidateRows: number[]
  onClose: () => void
  onSaved: () => void
}

function BulkDecisionDialog({
  sheet,
  section,
  candidateRows,
  onClose,
  onSaved,
}: BulkDecisionDialogProps) {
  const queryClient = useQueryClient()
  const [scope, setScope] = useState<'all' | 'selected'>('all')
  const [selectedRows, setSelectedRows] = useState<number[]>([...candidateRows])
  const [emptyBehavior, setEmptyBehavior] = useState('')
  const [appliesToTypes, setAppliesToTypes] = useState('')
  const [includedCenters, setIncludedCenters] = useState('')
  const [excludedCenters, setExcludedCenters] = useState('')
  const [functionalCondition, setFunctionalCondition] = useState('')
  const [justification, setJustification] = useState('')
  const [informedBy, setInformedBy] = useState('')
  const [confirmed, setConfirmed] = useState(false)

  const affectedRows = scope === 'all' ? candidateRows : selectedRows
  const scopeLabel =
    scope === 'all'
      ? `todas las ${candidateRows.length} filas candidatas`
      : `${selectedRows.length} filas seleccionadas`

  const mutation = useMutation({
    mutationFn: () =>
      calibrationService.bulkFunctional(sheet, section, {
        rowNumbers: affectedRows,
        empty_behavior: emptyBehavior || null,
        applies_to_types: appliesToTypes
          ? appliesToTypes
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        included_health_centers: includedCenters
          ? includedCenters
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        excluded_health_centers: excludedCenters
          ? excludedCenters
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        functional_condition: functionalCondition || '',
        justification: justification || '',
        informed_by: informedBy || '',
        status: 'propuesta',
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['calibration-matrix', sheet, section] })
      onSaved()
    },
  })

  return (
    <div
      className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
      onClick={onClose}
    >
      <div
        className="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="p-5 space-y-4">
          <div className="flex items-center justify-between">
            <h3 className="text-base font-bold text-gray-900 flex items-center gap-2">
              <CheckSquare className="w-5 h-5 text-blue-600" />
              Decisión funcional masiva
            </h3>
            <button
              onClick={onClose}
              className="text-gray-400 hover:text-gray-600 text-xl leading-none"
            >
              &times;
            </button>
          </div>

          {/* Scope */}
          <div>
            <label className="text-xs font-medium text-gray-600 block mb-1">Aplicar a</label>
            <div className="flex gap-3">
              <label className="flex items-center gap-1.5 text-xs">
                <input
                  type="radio"
                  name="scope"
                  checked={scope === 'all'}
                  onChange={() => setScope('all')}
                />
                Todas las filas candidatas ({candidateRows.length})
              </label>
              <label className="flex items-center gap-1.5 text-xs">
                <input
                  type="radio"
                  name="scope"
                  checked={scope === 'selected'}
                  onChange={() => setScope('selected')}
                />
                Filas seleccionadas
              </label>
            </div>
            {scope === 'selected' && (
              <div className="mt-1">
                <Input
                  className="text-xs h-7"
                  value={selectedRows.join(', ')}
                  onChange={(e) =>
                    setSelectedRows(
                      e.target.value
                        .split(',')
                        .map((s) => parseInt(s.trim()))
                        .filter((n) => !isNaN(n))
                    )
                  }
                  placeholder="Ej: 12, 13, 14, 15"
                />
              </div>
            )}
          </div>

          {/* Empty behavior */}
          <div>
            <label className="text-xs font-medium text-gray-600 block mb-1">Si no hay datos</label>
            <select
              className="w-full rounded border border-gray-200 px-2 py-1.5 text-xs h-8 outline-none focus:border-blue-300 focus:ring-1 focus:ring-blue-300"
              value={emptyBehavior}
              onChange={(e) => setEmptyBehavior(e.target.value)}
            >
              <option value="">Seleccione una opción...</option>
              <option value="puede_quedar_vacio">Puede quedar vacío (solo informativo)</option>
              <option value="debe_registrar_cero">Debe registrar 0</option>
              <option value="debe_tener_al_menos_un_valor">Debe tener al menos un valor</option>
              <option value="no_aplica">No aplica para esta fila</option>
            </select>
          </div>

          {/* Applies to */}
          <div>
            <label className="text-xs font-medium text-gray-600 block mb-1">
              Aplica a tipo de establecimiento
            </label>
            <Input
              className="text-xs h-7"
              value={appliesToTypes}
              onChange={(e) => setAppliesToTypes(e.target.value)}
              placeholder="Ej: CESFAM, SAPU, SAR (separados por coma)"
            />
          </div>

          {/* Health centers */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div>
              <label className="text-xs font-medium text-gray-600 block mb-1">
                Establecimientos incluidos
              </label>
              <textarea
                className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none h-14"
                value={includedCenters}
                onChange={(e) => setIncludedCenters(e.target.value)}
                placeholder="Nombres separados por coma. Vacío = todos."
              />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 block mb-1">
                Establecimientos excluidos
              </label>
              <textarea
                className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none h-14"
                value={excludedCenters}
                onChange={(e) => setExcludedCenters(e.target.value)}
                placeholder="Nombres separados por coma."
              />
            </div>
          </div>

          {/* Condition & justification */}
          <div>
            <label className="text-xs font-medium text-gray-600 block mb-1">
              Condición funcional
            </label>
            <textarea
              className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none h-14"
              value={functionalCondition}
              onChange={(e) => setFunctionalCondition(e.target.value)}
              placeholder="Describa la condición que debe cumplirse..."
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 block mb-1">Justificación</label>
            <textarea
              className="w-full rounded border border-gray-200 px-2 py-1 text-xs resize-none h-14"
              value={justification}
              onChange={(e) => setJustification(e.target.value)}
              placeholder="Motivo de esta decisión..."
            />
          </div>

          {/* Responsible */}
          <div>
            <label className="text-xs font-medium text-gray-600 block mb-1">Informado por</label>
            <Input
              className="text-xs h-7"
              value={informedBy}
              onChange={(e) => setInformedBy(e.target.value)}
              placeholder="Nombre y cargo del responsable"
            />
          </div>

          {/* Preview */}
          {affectedRows.length > 0 && (
            <div className="p-3 bg-blue-50 rounded-lg border border-blue-200">
              <p className="text-xs font-medium text-blue-800">
                Esta decisión afectará a <strong>{affectedRows.length} filas</strong>:
              </p>
              <p className="text-xs text-blue-600 mt-0.5">
                {affectedRows.length > 20
                  ? `Filas ${affectedRows[0]} a ${affectedRows[affectedRows.length - 1]} (${affectedRows.length} en total)`
                  : `Filas: ${affectedRows.join(', ')}`}
              </p>
              <p className="text-xs text-blue-500 mt-1">Alcance: {scopeLabel}</p>
            </div>
          )}

          {/* Confirmation */}
          <label className="flex items-start gap-2 text-xs text-gray-600">
            <input
              type="checkbox"
              checked={confirmed}
              onChange={(e) => setConfirmed(e.target.checked)}
              className="mt-0.5"
            />
            <span>
              Confirmo que deseo aplicar esta decisión a{' '}
              <strong>{affectedRows.length} filas</strong>. Esta acción no modifica las reglas
              técnicas del Rule Engine.
            </span>
          </label>

          {/* Buttons */}
          <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
            <button
              onClick={onClose}
              className="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-100 transition-colors"
            >
              Cancelar
            </button>
            <button
              onClick={() => mutation.mutate()}
              disabled={!confirmed || mutation.isPending || affectedRows.length === 0}
              className="px-4 py-1.5 rounded-lg text-xs font-medium bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              {mutation.isPending ? 'Aplicando...' : `Aplicar a ${affectedRows.length} filas`}
            </button>
          </div>

          {mutation.isError && (
            <div className="p-2 bg-red-50 border border-red-200 rounded text-xs text-red-700">
              Error al guardar: {(mutation.error as Error)?.message ?? 'Error desconocido'}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
