import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  Settings2,
} from 'lucide-react'
import { toast } from 'sonner'
import { useAuthStore } from '@/app/store/authStore'
import { useHealthCenters } from '@/features/health-centers/hooks/useHealthCenters'
import { invalidateSectionCalibration } from '../../hooks/invalidateSectionCalibration'
import { calibrationService } from '../../services/calibration'
import { MismatchResolutionPanel } from './MismatchResolutionPanel'
import { NotCalibratableSectionPanel } from './NotCalibratableSectionPanel'
import { QuickRevalidationPanel } from './QuickRevalidationPanel'
import type {
  CalibrationQuestion,
  ColumnGroup,
  PatternGroup,
  PatternMatrixResponse,
  PatternReconciliationStatus,
} from '../../types/calibration'

function needsRevalidation(status: PatternReconciliationStatus | undefined) {
  return status === 'requiere_revalidacion' || status === 'unresolved'
}

// BM-11.8 (ENGINE_UI_REDUNDANCY): la lista de establecimientos ya NO se
// hardcodea -- se obtiene de useHealthCenters() (fuente canonica real,
// health_centers.name), porque el backend compara nombres exactos
// (FunctionalRuleService::resolveScope()/ValidateRemUploadJob::establishmentInScope()).
// Ver bloque de estado del componente mas abajo.

const CENTER_MODES = [
  ['aplica', 'Aplica'],
  ['no_aplica', 'No aplica'],
  ['advertencia', 'Advertencia solamente'],
  ['obligatorio', 'Obligatorio'],
  ['opcional', 'Opcional'],
] as const

type CenterMode = (typeof CENTER_MODES)[number][0]
type DecisionKey =
  | 'empty'
  | 'all_est'
  | 'exceptions'
  | 'inconsistency'
  | 'special'
  | 'logic_correct'
type ProblemType =
  | 'regla incorrecta'
  | 'columna equivocada'
  | 'rango incorrecto'
  | 'fila que no aplica'
  | 'otro problema'

interface Props {
  serie: string
  sheet: string
  section: string
  sectionTitle?: string
  data: PatternMatrixResponse
  readOnly?: boolean
  previousSection?: string
  nextSection?: string
  structureVersion: string
  candidateSections?: EquivalentCandidateInput[]
  onOpenAdvanced: () => void
  // Abre la misma vista avanzada, pero directamente en la calibracion por
  // grupo (usada para las filas especiales). Opcional: si no se entrega se
  // usa onOpenAdvanced.
  onOpenGroupCalibration?: () => void
  onNavigateSection: (section: string) => void
}

interface EquivalentCandidateInput {
  sheet: string
  section: string
  title: string
  data: PatternMatrixResponse
  structureVersion: string
}

type SourceType = NonNullable<CalibrationQuestion['source_type']>

function questionKey(question: Pick<CalibrationQuestion, 'id' | 'question'>) {
  return question.id || question.question
}

function patternQuestionId(patternId: number, suffix: DecisionKey | 'formula_confirmation') {
  return `patron_${patternId}_${suffix}`
}

// BM-11.15: una seccion 100% derivada (ej. BM18/B) nunca puebla
// row.functional_rules por fila (ese campo depende de
// buildFunctionalRulesForMatrixRow(), mecanismo distinto, intencionalmente
// sin tocar aqui) -- se trata como evidencia completa igual que
// 'direct_input', porque su respaldo real es la presencia de reglas
// tecnicas activas ya verificada por el backend (ver
// buildDerivedAutoFillPattern()), no por fila individual.
function sectionHasCompleteCellEvidence(patterns: PatternGroup[], warnings?: string[]) {
  if ((warnings ?? []).length > 0) return false
  return (
    patterns.length > 0 &&
    patterns.every(
      (pattern) =>
        pattern.source === 'cell_data' &&
        pattern.rows.length > 0 &&
        (pattern.mode === 'direct_input' ||
          pattern.mode === 'derived_auto_fill' ||
          pattern.rows.every(
            (row) =>
              row.functional_rules?.length &&
              row.functional_rules.every(
                (rule) =>
                  rule.total_column &&
                  rule.origin_columns?.length &&
                  (rule.formula_exacta || rule.formula_template)
              )
          ))
    )
  )
}

function sectionHasDirectInputEvidence(patterns: PatternGroup[], warnings?: string[]) {
  if ((warnings ?? []).length > 0) return false
  return (
    patterns.length > 0 &&
    patterns.every(
      (pattern) =>
        pattern.source === 'cell_data' && pattern.mode === 'direct_input' && pattern.rows.length > 0
    )
  )
}

function isDerivedAutoFillSection(patterns: PatternGroup[]) {
  return patterns.length > 0 && patterns.every((pattern) => pattern.mode === 'derived_auto_fill')
}

function sectionTypeLabel(
  directInputEvidence: boolean,
  confirmedEvidence: boolean,
  derived: boolean
) {
  if (derived) return 'Llenado automático (derivado de otra hoja)'
  if (directInputEvidence) return 'Captura directa'
  if (confirmedEvidence) return 'Cálculo horizontal'
  return 'Pendiente de revisión'
}

function hasVerticalConsolidation(data: PatternMatrixResponse) {
  // BM-11.15: fuente explicita provista por el backend (SectionCalibrationMatrixService,
  // §9) -- fila TOTAL real reportada aunque se excluya deliberadamente de
  // all_rows/patterns (ej. BM18/B fila 52). Se preserva la heuristica
  // anterior como respaldo para secciones donde este campo no venga
  // presente (compatibilidad).
  if (data.has_vertical_consolidation) return true
  if ((data.summary?.total_subtotales ?? 0) > 0) return true
  return (data.all_rows ?? []).some((row) => {
    const label = `${row.concepto ?? ''} ${row.profesional ?? ''}`.toLowerCase()
    return (
      row.row_type === 'subtotal' ||
      row.aggregated_rule_key ||
      (label.includes('total') && Boolean(row.formula_efectiva || row.formula_candidata))
    )
  })
}

function isSpecialPattern(pattern: PatternGroup) {
  return pattern.rows.some((row) => row.objetivo === 'special' || row.cobertura === 'special')
}

function hasReportedProblem(questions: CalibrationQuestion[]) {
  return questions.some(
    (question) => question.response === 'reported' || question.status === 'clarification'
  )
}

function hasEstablishmentExceptions(questions: CalibrationQuestion[]) {
  return questions.some(
    (question) => question.id?.includes('_exceptions') && question.response === 'si'
  )
}

function range(group?: ColumnGroup) {
  return group ? `${group.start_column}:${group.end_column}` : ''
}

function firstRules(patterns: PatternGroup[]) {
  return patterns.flatMap((pattern) => pattern.rows[0]?.functional_rules ?? [])
}

function normalizeFormula(value: string) {
  return value.toUpperCase().replace(/\s+/g, '').replace(/\d+/g, '{row}')
}

function sorted(values: string[]) {
  return [...new Set(values.filter(Boolean))].sort()
}

function technicalSignature(data: PatternMatrixResponse, structureVersion: string) {
  const patterns = data.patterns.filter((pattern) => !isSpecialPattern(pattern))
  const groups = (data.column_groups ?? []).map((group) => ({
    type: group.type,
    start: group.start_column,
    end: group.end_column,
    columns: group.columns.map(
      (column) => `${column.letter}:${column.label}:${column.editable_rows}:${column.blocked_rows}`
    ),
    subgroups: (group.subgroups ?? []).map(
      (subgroup) => `${subgroup.type}:${subgroup.columns.map((column) => column.letter).join(',')}`
    ),
  }))
  const patternSignature = patterns.map((pattern) => ({
    formulas: sorted(
      pattern.rows
        .flatMap((row) => row.functional_rules ?? [])
        .map(
          (rule) =>
            `${rule.total_column}:${normalizeFormula(rule.formula_exacta || rule.formula_template || '')}`
        )
    ),
    total_columns: sorted(
      pattern.total_columns?.length ? pattern.total_columns : [pattern.columna_total]
    ),
    origin_columns: sorted(
      pattern.origin_columns?.length ? pattern.origin_columns : pattern.columnas_origen
    ),
    headers: sorted([...pattern.conceptos, ...pattern.profesionales]),
    editability: pattern.rows.map((row) =>
      [
        sorted(row.editables.map((cell) => cell.letra)).join(','),
        sorted(row.bloqueadas.map((cell) => cell.letra)).join(','),
      ].join('|')
    ),
    row_count: pattern.filas.length,
    row_span: patternRowsText([pattern]),
    source: pattern.source,
    mode: pattern.mode,
  }))

  return JSON.stringify({
    structure_version: structureVersion,
    source: 'cell_data',
    warnings: data.warnings ?? [],
    groups,
    patterns: patternSignature,
  })
}

function compactSignature(signature: string) {
  let hash = 0
  for (let i = 0; i < signature.length; i++) {
    hash = ((hash << 5) - hash + signature.charCodeAt(i)) | 0
  }
  return `sig_${Math.abs(hash)}`
}

function confidenceLevel(
  data: PatternMatrixResponse,
  patterns: PatternGroup[],
  confirmedEvidence: boolean
) {
  if (confirmedEvidence) return 'Alta'
  if (
    patterns.some((pattern) => pattern.source === 'cell_data') &&
    (data.warnings ?? []).length <= 1
  )
    return 'Media'
  return 'Baja'
}

function canReuse(
  data: PatternMatrixResponse,
  patterns: PatternGroup[],
  confirmedEvidence: boolean
) {
  return (
    confirmedEvidence &&
    patterns.length > 0 &&
    !patterns.some(isSpecialPattern) &&
    !hasReportedProblem(data.questions ?? []) &&
    !hasEstablishmentExceptions(data.questions ?? [])
  )
}

function responseBySuffix(questions: CalibrationQuestion[], suffix: DecisionKey) {
  return (
    questions.find(
      (question) => question.id?.match(new RegExp(`^patron_\\d+_${suffix}$`)) && question.response
    )?.response ?? ''
  )
}

// BM-11.31 (ENGINE_UI_GAP, BM-11.30): la semantica de sexo/edad NUNCA se
// infiere solo por la POSICION de columna (total_column/origin_columns) --
// eso coincidia por pura coincidencia posicional para secciones reales sin
// ninguna relacion de sexo (ej. BM18A/A: total_column='C', origin=['D','E'],
// pero D/E son "SAPU/SAR/SUR"/"Resto Establecimientos APS", nunca Hombres/
// Mujeres). Exige evidencia real del encabezado (mismo contrato que el
// backend, SectionCalibrationMatrixService::isSexMainRuleFormula()): el
// total debe decir "ambos sexos" (o, como respaldo, el generico "total"), Y
// los dos origenes deben decir explicitamente "hombre(s)"/"mujer(es)" --
// sin esa evidencia, jamas se asume sexo, sin importar que las columnas
// coincidan posicionalmente con el patron historico de Serie A.
function normalizeLabelText(value: string): string {
  return value.trim().toLowerCase()
}

function hasSexEvidence(totalLabel: string, firstLabel: string, secondLabel: string): boolean {
  const total = normalizeLabelText(totalLabel)
  const first = normalizeLabelText(firstLabel)
  const second = normalizeLabelText(secondLabel)
  const totalLooksLikeSexTotal = total.includes('ambos sexos') || total === 'total'
  return totalLooksLikeSexTotal && first.includes('hombre') && second.includes('mujer')
}

function isSexTotal(rule: ReturnType<typeof firstRules>[number], labels: Map<string, string>) {
  if (rule.origin_columns.length !== 2) return false
  const [firstColumn, secondColumn] = rule.origin_columns
  return hasSexEvidence(
    labels.get(rule.total_column) ?? '',
    labels.get(firstColumn) ?? '',
    labels.get(secondColumn) ?? ''
  )
}

// isMenAge/isWomenAge: mismo riesgo -- "mas de 2 columnas de origen" NO
// implica rango etario de un sexo especifico. Exige que la etiqueta real
// del total mencione explicitamente "hombre(s)"/"mujer(es)".
function isMenAge(rule: ReturnType<typeof firstRules>[number], labels: Map<string, string>) {
  if (rule.origin_columns.length <= 2) return false
  return normalizeLabelText(labels.get(rule.total_column) ?? '').includes('hombre')
}

function isWomenAge(rule: ReturnType<typeof firstRules>[number], labels: Map<string, string>) {
  if (rule.origin_columns.length <= 2) return false
  return normalizeLabelText(labels.get(rule.total_column) ?? '').includes('mujer')
}

function columnLabelMap(columnGroups?: ColumnGroup[]) {
  const labels = new Map<string, string>()
  for (const group of columnGroups ?? []) {
    for (const [column, label] of Object.entries(group.labels ?? {})) {
      if (label) labels.set(column, label)
    }
    for (const column of group.columns ?? []) {
      if (column.label) labels.set(column.letter, column.label)
    }
    for (const subgroup of group.subgroups ?? []) {
      for (const column of subgroup.columns ?? []) {
        if (column.label) labels.set(column.letter, column.label)
      }
    }
  }
  return labels
}

function readableColumn(column: string, labels: Map<string, string>) {
  return labels.get(column)?.trim() || column
}

function ruleLabel(
  rule: ReturnType<typeof firstRules>[number],
  labels: Map<string, string>,
  useFunctionalLabels: boolean
) {
  if (isSexTotal(rule, labels)) return 'Ambos Sexos = Hombres + Mujeres'
  if (isMenAge(rule, labels)) return 'Total Hombres = suma de rangos etarios de Hombres'
  if (isWomenAge(rule, labels)) return 'Total Mujeres = suma de rangos etarios de Mujeres'
  if (useFunctionalLabels) {
    const destination = readableColumn(rule.total_column, labels)
    const origins = rule.origin_columns.map((column) => readableColumn(column, labels))
    return `${destination} = ${origins.join(' + ')}`
  }
  return rule.label || `${rule.total_column} = ${rule.origin_columns.join(' + ')}`
}

// El backend agrupa hoy las columnas E:AH (rangos etarios + complementarias)
// bajo un unico grupo "complementary" sin separar el rango etario. Filtramos
// aqui las columnas "Hombres"/"Mujeres" (que en realidad son pares de rango
// etario, no variables complementarias) para no mostrarlas duplicadas.
// TODO backend: SectionCalibrationMatrixService deberia clasificar un grupo
// "age_range" separado en vez de mezclarlo con "complementary".
function trueComplementaryColumns(group?: ColumnGroup) {
  return (group?.columns ?? []).filter((column) => {
    const label = (column.label || '').trim().toLowerCase()
    return label !== 'hombres' && label !== 'mujeres'
  })
}

function joinWithY(items: string[]): string {
  if (items.length === 0) return ''
  if (items.length === 1) return items[0]
  return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`
}

function patternRowsText(patterns: PatternGroup[]) {
  const rows = patterns.flatMap((pattern) => pattern.filas).sort((a, b) => a - b)
  if (rows.length === 0) return 'Sin filas'
  return rows[0] === rows[rows.length - 1] ? String(rows[0]) : `${rows[0]}-${rows[rows.length - 1]}`
}

// Texto compacto y exacto de un conjunto de filas: tramos consecutivos como
// "11–22", separados por coma ("124–127, 145, 161–163").
function rowRangesText(rows: number[], maxSegments = 6) {
  const unique = [...new Set(rows)].sort((a, b) => a - b)
  if (unique.length === 0) return 'sin filas'
  const segments: string[] = []
  let start = unique[0]
  let previous = unique[0]
  for (const row of unique.slice(1)) {
    if (row === previous + 1) {
      previous = row
      continue
    }
    segments.push(start === previous ? String(start) : `${start}–${previous}`)
    start = row
    previous = row
  }
  segments.push(start === previous ? String(start) : `${start}–${previous}`)
  if (segments.length <= maxSegments) return segments.join(', ')
  const hidden = segments.length - maxSegments
  return `${segments.slice(0, maxSegments).join(', ')} y ${hidden} tramo${hidden === 1 ? '' : 's'} más`
}

// Etiquetas de presentacion para los valores internos existentes. Los
// valores guardados no cambian.
const EMPTY_LABELS: Record<string, string> = {
  debe_registrar_cero: 'Debe registrar 0',
  puede_quedar_vacio: 'Puede quedar vacío',
  no_aplica: 'No aplica',
  no_se_puede_ingresar_informacion: 'No se puede ingresar información',
}
const SEVERITY_LABELS: Record<string, string> = {
  error: 'Error',
  advertencia: 'Advertencia',
}
const SPECIAL_LABELS: Record<string, string> = {
  si: 'Validar',
  no: 'Solo informativas',
}
const LOGIC_LABELS: Record<string, string> = {
  si: 'Correcta',
  parcialmente: 'Parcialmente',
  por_definir: 'Por definir',
}

function answerLabel(labels: Record<string, string>, value: string | null | undefined) {
  if (!value) return 'Sin definir'
  return labels[value] ?? value
}

function getInitialResponse(questions: CalibrationQuestion[], id: string) {
  return questions.find((question) => questionKey(question) === id)?.response ?? ''
}

function hasSectionReview(questions: CalibrationQuestion[]) {
  return questions.some(
    (question) =>
      questionKey(question) === 'section_review' && question.review_status === 'section_reviewed'
  )
}

// BM-11.8: SOLO texto humano -- nunca se parsea de vuelta para reconstruir
// nombres de establecimiento. La unica fuente funcional de scope es
// buildStructuredScope() (mas abajo).
function buildScopeObservation(selectedCenters: Record<string, CenterMode>, extra: string) {
  const entries = Object.entries(selectedCenters)
    .filter(([, mode]) => mode)
    .map(
      ([center, mode]) =>
        `${center}: ${CENTER_MODES.find(([value]) => value === mode)?.[1] ?? mode}`
    )
  return [entries.length ? `Alcance por establecimiento: ${entries.join('; ')}` : '', extra.trim()]
    .filter(Boolean)
    .join('\n')
}

// BM-11.8: modo de alcance efectivo segun el contrato de FunctionalRuleService
// ::resolveScope() (BM-11.6) -- 'all' (Caso A, sin scope necesario),
// 'excluded' (Caso B: all_est=si + exceptions=si) o 'included' (Caso C:
// all_est=depende + exceptions=si). all_est=depende SIEMPRE fuerza
// exceptions=si (ver handleAllEstChange), por lo que la combinacion invalida
// depende+no nunca deberia poder construirse desde la UI.
function scopeModeFor(allEst: string, exceptions: string): 'all' | 'included' | 'excluded' {
  if (allEst === 'depende') return 'included'
  if (exceptions === 'si') return 'excluded'
  return 'all'
}

// Unica fuente FUNCIONAL de included_health_centers/excluded_health_centers.
// Solo aplica/obligatorio participan en 'included'; solo no_aplica participa
// en 'excluded' -- advertencia/opcional quedan fuera del scope funcional por
// diseno (BM-11.4 §1: alcance de este fix), se preservan unicamente en
// observation para lectura humana.
function buildStructuredScope(
  allEst: string,
  exceptions: string,
  selectedCenters: Record<string, CenterMode>
): CalibrationQuestion['scope'] | undefined {
  const mode = scopeModeFor(allEst, exceptions)
  if (mode === 'all') return undefined

  if (mode === 'included') {
    const included = Object.entries(selectedCenters)
      .filter(([, centerMode]) => centerMode === 'aplica' || centerMode === 'obligatorio')
      .map(([center]) => center)
    return { mode: 'included', included_health_centers: included, excluded_health_centers: [] }
  }

  const excluded = Object.entries(selectedCenters)
    .filter(([, centerMode]) => centerMode === 'no_aplica')
    .map(([center]) => center)
  return { mode: 'excluded', included_health_centers: [], excluded_health_centers: excluded }
}

export default function QuickCalibrationPanel({
  serie,
  sheet,
  section,
  sectionTitle,
  data,
  readOnly = false,
  previousSection,
  nextSection,
  structureVersion,
  candidateSections = [],
  onOpenAdvanced,
  onOpenGroupCalibration,
  onNavigateSection,
}: Props) {
  const user = useAuthStore((state) => state.user)
  const queryClient = useQueryClient()
  const userName = user?.name ?? user?.email ?? 'Usuario funcional'
  const questions = data.questions ?? []
  const quickPatterns = useMemo(
    () => data.patterns.filter((pattern) => !isSpecialPattern(pattern)),
    [data.patterns]
  )
  const confirmedEvidence = sectionHasCompleteCellEvidence(quickPatterns, data.warnings)
  const directInputEvidence = sectionHasDirectInputEvidence(quickPatterns, data.warnings)
  // BM-11.15: metadata explicita del backend (capture_mode), nunca inferida
  // de titulos/textos -- respaldada como segunda confirmacion por la forma
  // real de los patrones (isDerivedAutoFillSection), que sigue siendo
  // correcta aunque capture_mode no venga presente (compatibilidad).
  const isDerivedAutoFill =
    data.capture_mode === 'derived_auto_fill' || isDerivedAutoFillSection(quickPatterns)
  // BM-11.25 (secciones hibridas, BM1124_BM18A_ENGINE_GAP_DETECTED): un
  // booleano de seccion NUNCA debe decidir las preguntas de un patron
  // individual -- hasDerivedPattern/hasNormalPattern reflejan la EVIDENCIA
  // real por patron (pattern.mode), y ambos pueden ser true a la vez
  // cuando coexisten un bloque derivado y un bloque de captura funcional en
  // la misma seccion (ej. BM18/A). isDerivedAutoFill (arriba, BM-11.15/17)
  // conserva su significado exacto de "seccion 100% derivada" -- sigue
  // siendo false para una seccion hibrida, a proposito.
  const hasDerivedPattern = quickPatterns.some((pattern) => pattern.mode === 'derived_auto_fill')
  const hasNormalPattern = quickPatterns.some((pattern) => pattern.mode !== 'derived_auto_fill')
  const isHybridSection = hasDerivedPattern && hasNormalPattern
  const derivedPatterns = useMemo(
    () => quickPatterns.filter((pattern) => pattern.mode === 'derived_auto_fill'),
    [quickPatterns]
  )
  const derivedRowCount = useMemo(
    () => derivedPatterns.reduce((total, pattern) => total + pattern.filas.length, 0),
    [derivedPatterns]
  )
  // BM-11.27 (BM1126_CLASSIFICATION_GAP_CONFIRMED): todos los patrones NO
  // derivados de la seccion -- usado para hasNormalPattern/isHybridSection
  // (clasificacion derivado-vs-normal) y como base de
  // quickSaveNormalPatterns (mas abajo), que ademas excluye las posibles
  // excepciones de negocio (BM-11.31).
  const normalPatterns = useMemo(
    () => quickPatterns.filter((pattern) => pattern.mode !== 'derived_auto_fill'),
    [quickPatterns]
  )
  const sectionType = isHybridSection
    ? 'Mixta (llenado automático + captura funcional)'
    : sectionTypeLabel(directInputEvidence, confirmedEvidence, isDerivedAutoFill)
  const verticalConsolidation = hasVerticalConsolidation(data)
  const currentSignature = useMemo(
    () => technicalSignature(data, structureVersion),
    [data, structureVersion]
  )
  const currentSignatureId = useMemo(() => compactSignature(currentSignature), [currentSignature])
  const confidence = confidenceLevel(data, quickPatterns, confirmedEvidence)
  const equivalentCandidates = useMemo(() => {
    if (!canReuse(data, quickPatterns, confirmedEvidence)) return []
    return candidateSections.filter((candidate) => {
      const candidatePatterns = candidate.data.patterns.filter(
        (pattern) => !isSpecialPattern(pattern)
      )
      const candidateConfirmed = sectionHasCompleteCellEvidence(
        candidatePatterns,
        candidate.data.warnings
      )
      return (
        canReuse(candidate.data, candidatePatterns, candidateConfirmed) &&
        technicalSignature(candidate.data, candidate.structureVersion) === currentSignature
      )
    })
  }, [candidateSections, confirmedEvidence, currentSignature, data, quickPatterns])
  const equivalentCandidate = equivalentCandidates[0]
  const ageGroup = data.column_groups?.find((group) => group.type === 'age_range')
  const complementaryGroup = data.column_groups?.find((group) => group.type === 'complementary')
  const complementaryColumns = useMemo(
    () => trueComplementaryColumns(complementaryGroup),
    [complementaryGroup]
  )
  const labelsByColumn = useMemo(() => columnLabelMap(data.column_groups), [data.column_groups])
  // Estado efectivo: la marca historica de section_review se conserva para
  // mostrar "Lista para certificar", pero si el backend indica que algun
  // patron quedo requiere_revalidacion/unresolved, deja de contar como
  // vigente -- nunca se recalcula aqui, solo se consume lo que ya calculo
  // PatternReconciliationService::computeEffectiveSectionReviewed().
  const historicalSectionReviewed = hasSectionReview(questions)
  const sectionReviewed =
    historicalSectionReviewed && (data.reconciliation?.effective_section_reviewed ?? true)
  const blockedPatterns = useMemo(
    () => quickPatterns.filter((pattern) => needsRevalidation(pattern.reconciliation_status)),
    [quickPatterns]
  )
  // Patrones de fila unica o grupo minoritario frente al patron dominante de
  // la seccion (calculado por el backend, nunca aqui).
  const exceptionPatterns = useMemo(
    () => quickPatterns.filter((pattern) => pattern.possible_business_exception),
    [quickPatterns]
  )
  const exceptionRowCount = useMemo(
    () => exceptionPatterns.reduce((total, pattern) => total + pattern.filas.length, 0),
    [exceptionPatterns]
  )
  // BM-11.31 (BM1130_BM18AA_PATTERN_EXCEPTION_REQUIRES_DECISION): la
  // calibracion rapida ya NO aplica su decision compartida a un patron
  // marcado como posible excepcion de negocio (ej. BM18A/A patron 2, fila
  // 119: sin concepto, sin regla tecnica, significado desconocido) -- ese
  // patron queda fuera del conteo "aplicara a N filas" Y del payload real
  // que "Confirmar y guardar seccion" envia (ver buildPayload() abajo).
  // Sigue siendo NORMAL (no derivado) y sigue totalmente visible/calibrable
  // de forma individual en "Ver evidencia tecnica" (FunctionalQuestionsPanel,
  // que ya persiste decisiones independientes por pattern_id -- mismo
  // contrato/payload, sin cambio de modelo). No se oculta, no se le asigna
  // significado, no se altera el payload en silencio -- el aviso de abajo
  // deja explicito que se excluyo.
  const quickSaveNormalPatterns = useMemo(
    () => normalPatterns.filter((pattern) => !pattern.possible_business_exception),
    [normalPatterns]
  )
  const quickSaveFunctionalRows = useMemo(
    () =>
      Array.from(new Set(quickSaveNormalPatterns.flatMap((pattern) => pattern.filas))).sort(
        (a, b) => a - b
      ),
    [quickSaveNormalPatterns]
  )
  // Fase 3/4 (2026-08-12): clasificacion de migracion al fingerprint v2,
  // recalculada EN VIVO en cada carga -- decide unicamente si se muestra
  // QuickRevalidationPanel en vez del flujo normal. No participa del
  // calculo de progreso ni de reconcileLive() en produccion.
  const { data: migrationPlan } = useQuery({
    queryKey: ['migration-plan', serie, sheet, section],
    queryFn: () => calibrationService.getMigrationPlan(serie, sheet, section),
    enabled: Boolean(serie) && Boolean(sheet) && Boolean(section),
    staleTime: 0,
  })
  // "Revisar nuevamente esta sección" no persiste nada -- solo oculta el
  // panel de confirmacion rapida y deja pasar al flujo normal de
  // calibracion para que el usuario la rehaga manualmente. Se reinicia al
  // cambiar de seccion (ajuste en render, no en efecto, para evitar el
  // render en cascada de un setState dentro de useEffect).
  const sectionKey = `${sheet}_${section}`
  const [forceFullReview, setForceFullReview] = useState(false)
  // Confirmacion visible del ultimo guardado exitoso en ESTA seccion (solo
  // presentacion; se limpia al cambiar de seccion junto con forceFullReview).
  const [lastSavedAt, setLastSavedAt] = useState<Date | null>(null)
  const [lastSectionKey, setLastSectionKey] = useState(sectionKey)
  if (lastSectionKey !== sectionKey) {
    setLastSectionKey(sectionKey)
    setForceFullReview(false)
    setLastSavedAt(null)
  }

  const [showProblem, setShowProblem] = useState(false)
  const [problemType, setProblemType] = useState<ProblemType>('regla incorrecta')
  const [problemObservation, setProblemObservation] = useState('')
  const [exceptionDetail, setExceptionDetail] = useState('')
  const [centerModes, setCenterModes] = useState<Record<string, CenterMode>>({})
  // BM-11.8: fuente real de establecimientos (health_centers.name exacto,
  // nunca hardcodeado). Mismo patron ya usado en UserForm.tsx/RemUploadsPage.tsx
  // (useHealthCenters() sin paginacion explicita -- el universo real es
  // pequeño). Solo se muestran activos.
  const healthCentersQuery = useHealthCenters({ is_active: true })
  const healthCenters = useMemo(
    () => healthCentersQuery.data?.data ?? [],
    [healthCentersQuery.data]
  )
  const [sourceByDecision, setSourceByDecision] = useState<Record<string, SourceType>>({})
  const [inheritedFrom, setInheritedFrom] = useState<EquivalentCandidateInput | null>(null)
  const [hideEquivalent, setHideEquivalent] = useState(false)
  const [showDecisionDetails, setShowDecisionDetails] = useState(false)
  // Solo presentacion: abrir/cerrar bloques secundarios. No participan del
  // payload ni del calculo de decisiones.
  const [showSectionTechnical, setShowSectionTechnical] = useState(false)
  const [showAdvancedOptions, setShowAdvancedOptions] = useState(false)

  const primaryPattern = quickPatterns[0]
  const primaryId = primaryPattern?.id ?? 1
  const [responses, setResponses] = useState<Record<string, string>>(() => ({
    empty: getInitialResponse(questions, patternQuestionId(primaryId, 'empty')),
    all_est: getInitialResponse(questions, patternQuestionId(primaryId, 'all_est')),
    exceptions: getInitialResponse(questions, patternQuestionId(primaryId, 'exceptions')),
    inconsistency: getInitialResponse(questions, patternQuestionId(primaryId, 'inconsistency')),
    special: getInitialResponse(questions, patternQuestionId(primaryId, 'special')),
    logic_correct: getInitialResponse(questions, patternQuestionId(primaryId, 'logic_correct')),
  }))

  const rules = useMemo(
    () =>
      Array.from(
        new Set(
          firstRules(quickPatterns).map((rule) =>
            ruleLabel(rule, labelsByColumn, confirmedEvidence)
          )
        )
      ),
    [confirmedEvidence, labelsByColumn, quickPatterns]
  )
  const reportedProblems =
    questions.filter((question) => question.response === 'reported').length + (showProblem ? 1 : 0)
  // BM-11.15 (§7): una seccion derivada NO tiene decision de "Sin datos"
  // (nunca esta vacia por eleccion humana), ni Severidad/Aplicacion/
  // Excepciones asociadas a esa decision inexistente -- la unica
  // confirmacion humana que corresponde es sobre la logica automatica
  // detectada (reutiliza la misma clave/opciones ya existentes de
  // "Lógica detectada", sin inventar un nuevo modelo de datos).
  // BM-11.25: en una seccion hibrida se agrega UNA decision extra al
  // principio -- la confirmacion propia del bloque derivado (misma clave
  // `responses.logic_correct` que ya usa la rama puramente derivada) --
  // ademas de las 6 decisiones normales del bloque de captura funcional.
  // Limitacion conocida y documentada: si el bloque normal tambien
  // careciera de evidencia completa (`!confirmedEvidence`), su propio slot
  // de "confirmacion funcional de la logica detectada" reutilizaria la
  // MISMA clave -- caso extremo no verificado en ningun caso real de esta
  // campaña (BM18/A tiene evidencia completa para su bloque normal).
  const decisions = isDerivedAutoFill
    ? [responses.logic_correct]
    : [
        ...(isHybridSection ? [responses.logic_correct] : []),
        responses.empty,
        responses.inconsistency,
        responses.all_est,
        responses.exceptions,
        complementaryGroup ? responses.special : 'no_aplica',
        confirmedEvidence ? 'confirmed' : responses.logic_correct,
      ]
  const answeredDecisions = decisions.filter((value) => String(value ?? '').trim()).length
  const totalDecisions = decisions.length
  const problemObservationMissing = showProblem && !problemObservation.trim()
  const needsManualDecisionView =
    isDerivedAutoFill ||
    isHybridSection ||
    !confirmedEvidence ||
    showProblem ||
    !responses.empty ||
    responses.exceptions === 'si' ||
    confidence !== 'Alta'
  const showDecisionControls = showDecisionDetails || needsManualDecisionView
  const canFastSave =
    answeredDecisions === totalDecisions && !showProblem && blockedPatterns.length === 0
  // Solo para avisar antes de salir de la seccion: compara las respuestas en
  // pantalla con las guardadas (mismas claves que el estado inicial). No
  // interviene en el guardado.
  const hasUnsavedChanges = (Object.keys(responses) as DecisionKey[]).some(
    (key) =>
      (responses[key] ?? '') !== getInitialResponse(questions, patternQuestionId(primaryId, key))
  )
  const goToSection = (target: string) => {
    if (
      hasUnsavedChanges &&
      !window.confirm(
        'Hay respuestas sin guardar en esta sección. ¿Desea salir de todos modos? Los cambios no guardados se perderán.'
      )
    ) {
      return
    }
    onNavigateSection(target)
  }

  const saveMutation = useMutation({
    mutationFn: (payload: CalibrationQuestion[]) =>
      calibrationService.savePatternQuestions(serie, sheet, section, payload),
    onSuccess: () => {
      toast.success(
        showProblem ? 'Problema reportado correctamente.' : 'Calibración guardada correctamente.'
      )
      // Guardar ya no cambia de seccion: el funcionario queda en la misma
      // para comprobar lo guardado y avanza con "Siguiente sección" cuando
      // quiera. Se retorna la invalidacion para que la mutation siga
      // "pendiente" hasta que toda la pantalla de la seccion ya se volvio a
      // leer del backend (estado revisado, grupos, tabla por fila).
      if (!showProblem) setLastSavedAt(new Date())
      return invalidateSectionCalibration(queryClient, serie, sheet, section)
    },
    onError: () => toast.error('No se pudo guardar la calibración rápida'),
  })

  const updateResponse = (key: DecisionKey, value: string) => {
    setResponses((prev) => ({ ...prev, [key]: value }))
    setSourceByDecision((prev) => ({ ...prev, [key]: 'manual' }))
  }

  // BM-11.8 (§3/§7): "Aplicación" y "Excepciones" ya no son dos controles
  // independientes -- cambiar cualquiera de los dos puede alterar el MODO de
  // scope efectivo (all/included/excluded). Cuando el modo cambia, se limpia
  // centerModes/exceptionDetail para no arrastrar una seleccion residual de
  // un modo anterior (ej: included bajo 'depende' -> nunca se reinterpreta
  // como excluded al volver a 'si').
  const handleAllEstChange = (value: string) => {
    const nextExceptions = value === 'depende' ? 'si' : responses.exceptions
    const previousMode = scopeModeFor(responses.all_est, responses.exceptions)
    const nextMode = scopeModeFor(value, nextExceptions)

    setResponses((prev) => ({ ...prev, all_est: value, exceptions: nextExceptions }))
    setSourceByDecision((prev) => ({ ...prev, all_est: 'manual', exceptions: 'manual' }))

    if (nextMode !== previousMode) {
      setCenterModes({})
      setExceptionDetail('')
    }
  }

  const handleExceptionsChange = (value: string) => {
    // 'depende' fuerza exceptions='si' (ver handleAllEstChange) -- este
    // control queda deshabilitado en la UI mientras tanto, pero se protege
    // igual aqui por si acaso.
    if (responses.all_est === 'depende') return

    const previousMode = scopeModeFor(responses.all_est, responses.exceptions)
    const nextMode = scopeModeFor(responses.all_est, value)

    setResponses((prev) => ({ ...prev, exceptions: value }))
    setSourceByDecision((prev) => ({ ...prev, exceptions: 'manual' }))

    if (nextMode !== previousMode) {
      setCenterModes({})
      setExceptionDetail('')
    }
  }

  const scopeMode = scopeModeFor(responses.all_est, responses.exceptions)
  const selectedCentersForScope = Object.entries(centerModes).filter(([, mode]) =>
    scopeMode === 'included' ? mode === 'aplica' || mode === 'obligatorio' : mode === 'no_aplica'
  )
  const needsEstablishmentScope = scopeMode !== 'all'

  // Presentacion de la vista simple (no cambia ninguna regla de guardado):
  // las opciones avanzadas quedan cerradas por defecto, salvo que falte una
  // respuesta que canFastSave exige o que el alcance por establecimiento
  // este activo -- nunca se esconde algo que bloquearia el guardado.
  const advancedOptionsNeedAnswer =
    !responses.all_est ||
    !responses.exceptions ||
    (Boolean(complementaryGroup) && !responses.special) ||
    needsEstablishmentScope
  const advancedOptionsOpen = showAdvancedOptions || advancedOptionsNeedAnswer
  const establishmentsSummary = !responses.all_est
    ? 'Sin definir'
    : responses.all_est === 'depende'
      ? 'Solo algunos establecimientos'
      : responses.exceptions === 'si'
        ? 'Todos, con excepciones'
        : responses.exceptions === 'no'
          ? 'Todos, sin excepciones'
          : 'Todos los establecimientos'
  const calibrationStatusLabel =
    blockedPatterns.length > 0
      ? 'Requiere revisión'
      : sectionReviewed
        ? 'Calibración confirmada'
        : answeredDecisions > 0
          ? 'En progreso'
          : 'Sin iniciar'

  const applySuggestions = () => {
    if (!confirmedEvidence) {
      toast.warning('La configuración estándar REM solo aplica con evidencia XLSM confirmada.')
      return
    }

    setResponses((prev) => ({
      ...prev,
      all_est: prev.all_est || 'si',
      exceptions: prev.exceptions || 'no',
      inconsistency: prev.inconsistency || 'error',
      special: complementaryGroup ? prev.special || 'si' : prev.special,
    }))
    setSourceByDecision((prev) => ({
      ...prev,
      ...Object.fromEntries(
        (
          [
            'all_est',
            'exceptions',
            'inconsistency',
            ...(complementaryGroup ? ['special'] : []),
          ] as DecisionKey[]
        )
          .filter((key) => !responses[key])
          .map((key) => [key, 'sugerida' as SourceType])
      ),
    }))
    toast.info('Sugerencias aplicadas en pantalla. Revise antes de guardar.')
  }

  const applyEquivalent = (candidate: EquivalentCandidateInput) => {
    const candidateQuestions = candidate.data.questions ?? []
    const copied: Partial<Record<DecisionKey, string>> = {}
    for (const key of [
      'empty',
      'all_est',
      'exceptions',
      'inconsistency',
      'special',
    ] as DecisionKey[]) {
      if (responses[key]) continue
      const value = responseBySuffix(candidateQuestions, key)
      if (value) copied[key] = value
    }
    setResponses((prev) => ({ ...prev, ...copied }))
    setSourceByDecision((prev) => ({
      ...prev,
      ...Object.fromEntries(Object.keys(copied).map((key) => [key, 'heredada' as SourceType])),
    }))
    setInheritedFrom(candidate)
    setShowDecisionDetails(false)
    toast.info('Configuración equivalente aplicada en pantalla. Revise antes de guardar.')
  }

  const buildPayload = (markReviewed: boolean) => {
    const existingByKey = new Map<string, CalibrationQuestion>()
    for (const question of questions) existingByKey.set(questionKey(question), question)
    const reviewedAt = new Date().toISOString()
    const next: CalibrationQuestion[] = []

    for (const pattern of quickPatterns) {
      // BM-11.31 (BM1130_BM18AA_PATTERN_EXCEPTION_REQUIRES_DECISION): un
      // patron NORMAL marcado como posible excepcion de negocio nunca
      // recibe la decision compartida de la calibracion rapida -- su
      // significado no esta respaldado (sin concepto/regla tecnica en
      // casos reales como BM18A/A patron 2), y aplicarle automaticamente
      // la misma respuesta que al patron dominante equivaldria a
      // inventarle un criterio sin evidencia. Se calibra por separado en
      // "Ver evidencia técnica" (FunctionalQuestionsPanel), que ya
      // persiste respuestas independientes por pattern_id con el MISMO
      // contrato de payload -- ningun cambio de modelo/backend. Los
      // patrones derivados NUNCA se saltan aqui, tengan o no esa marca.
      if (pattern.mode !== 'derived_auto_fill' && pattern.possible_business_exception) {
        continue
      }

      // Si alguna pregunta de este patron ya quedo en fingerprint canonico
      // v2 (por una confirmacion rapida previa), este flujo normal nunca
      // debe reenviar pattern_fingerprint/pattern_rows en formato v1 -- el
      // backend los ignora de todas formas (metadata server-owned, ver
      // FunctionalRuleService::saveQuestions()), pero el payload no debe
      // sugerir un valor que no va a aplicarse. Hallazgo A01/G.-G.2,
      // 2026-08-12.
      const patternIsV2 = questions.some(
        (question) => question.pattern_id === pattern.id && question.fingerprint_version === 2
      )
      // BM-11.25: evaluado POR PATRON (nunca con el booleano de seccion
      // isDerivedAutoFill) -- en una seccion hibrida, un patron
      // derived_auto_fill nunca debe recibir las entradas normales
      // (empty/all_est/exceptions/inconsistency/special/scope) aunque
      // OTRO patron de la misma seccion si las reciba, y viceversa. Para
      // una seccion 100% de un solo modo, esto equivale exactamente al
      // comportamiento anterior (isDerivedAutoFill compartido por todos
      // los patrones).
      const patternIsDerived = pattern.mode === 'derived_auto_fill'
      const base = {
        row: null,
        pattern_id: pattern.id,
        pattern_key: `pattern_${pattern.id}`,
        type: 'pattern_question',
        suggests_block: false,
        // Eco de la identidad que el backend ya calculo y devolvio en este
        // mismo patron -- nunca se calcula aqui. Omitido por completo
        // cuando el patron ya es v2 (ver comentario arriba).
        ...(patternIsV2
          ? {}
          : { pattern_fingerprint: pattern.row_fingerprint, pattern_rows: pattern.pattern_rows }),
      }

      // BM-11.15 (§7/§8): una seccion derivada NUNCA envia empty/all_est/
      // exceptions/inconsistency/special -- no existe la nocion de "fila
      // vacia por decision humana" para una fila 100% calculada, y
      // FunctionalRuleService::patternQuestionsToFunctionalRule() exige una
      // pregunta 'empty' respondida para generar cualquier regla funcional
      // (BM-11.6) -- al nunca enviarla, el backend jamas construye una
      // regla de vacio artificial para este patron, sin necesitar ningun
      // cambio en el motor. La unica confirmacion que se envia es sobre la
      // logica automatica detectada (misma clave/opciones que "Lógica
      // detectada" ya usa para !confirmedEvidence).
      const entries: Array<[DecisionKey, string, string]> = patternIsDerived
        ? [
            [
              'logic_correct',
              'Confirmación de la lógica automática detectada (sección derivada de otra hoja)',
              responses.logic_correct,
            ],
          ]
        : [
            [
              'empty',
              'Si no existen datos, debe registrarse 0 o puede quedar vacío',
              responses.empty,
            ],
            ['all_est', 'Aplicabilidad a establecimientos que reportan la hoja', responses.all_est],
            [
              'exceptions',
              'Excepciones por establecimiento o tipo de establecimiento',
              responses.exceptions,
            ],
            [
              'inconsistency',
              'Clasificación funcional de la inconsistencia',
              responses.inconsistency,
            ],
          ]

      if (!patternIsDerived && complementaryGroup)
        entries.push([
          'special',
          'Validación funcional de variables complementarias',
          responses.special,
        ])
      if (!patternIsDerived && !confirmedEvidence)
        entries.unshift([
          'logic_correct',
          'Confirmación funcional de la lógica detectada',
          responses.logic_correct,
        ])

      for (const [suffix, label, response] of entries) {
        const id = patternQuestionId(pattern.id, suffix)
        const existing = existingByKey.get(id)
        const finalResponse = response || existing?.response
        const sourceType = sourceByDecision[suffix] ?? existing?.source_type ?? 'manual'
        const observation =
          suffix === 'exceptions' && finalResponse === 'si'
            ? existing?.observation || buildScopeObservation(centerModes, exceptionDetail)
            : (existing?.observation ?? '')
        // BM-11.8: scope estructurado SOLO se adjunta/recalcula en la
        // pregunta 'exceptions' -- unica fuente funcional que lee el
        // backend. Se recalcula siempre a partir del estado actual (nunca se
        // hereda de `existing`) para que un scope residual de una
        // configuracion anterior nunca sobreviva a un cambio de modo (BM-11.8
        // §7) -- cuando el modo es 'all', buildStructuredScope() devuelve
        // undefined y JSON.stringify() omite la clave del payload enviado.
        const scope =
          suffix === 'exceptions'
            ? buildStructuredScope(responses.all_est, finalResponse ?? '', centerModes)
            : existing?.scope
        next.push({
          ...existing,
          ...base,
          id,
          question: `${label} (Patrón ${pattern.id}: ${pattern.filas.join(', ')})`,
          response: finalResponse,
          observation,
          scope,
          status: finalResponse ? 'answered' : 'pending',
          review_status: markReviewed ? 'reviewed' : (existing?.review_status ?? 'pending'),
          reviewed_at: markReviewed ? (existing?.reviewed_at ?? reviewedAt) : existing?.reviewed_at,
          reviewed_by: markReviewed ? (existing?.reviewed_by ?? userName) : existing?.reviewed_by,
          source_type: sourceType,
          source_sheet: sourceType === 'heredada' ? inheritedFrom?.sheet : existing?.source_sheet,
          source_section:
            sourceType === 'heredada' ? inheritedFrom?.section : existing?.source_section,
          technical_signature: currentSignatureId,
          structure_version: structureVersion,
        })
        existingByKey.delete(id)
      }

      if (confirmedEvidence) {
        const id = patternQuestionId(pattern.id, 'formula_confirmation')
        const existing = existingByKey.get(id)
        next.push({
          ...existing,
          row: null,
          pattern_id: pattern.id,
          pattern_key: `pattern_${pattern.id}`,
          ...(patternIsV2
            ? {}
            : { pattern_fingerprint: pattern.row_fingerprint, pattern_rows: pattern.pattern_rows }),
          type: 'pattern_confirmation',
          id,
          question: 'Confirmación de lectura técnica desde el XLSM',
          response: showProblem ? 'reported' : existing?.response || 'confirmed',
          observation: showProblem
            ? `[${problemType}] ${problemObservation}`
            : (existing?.observation ?? ''),
          suggests_block: false,
          status: showProblem ? 'clarification' : 'answered',
          review_status: markReviewed ? 'reviewed' : (existing?.review_status ?? 'pending'),
          reviewed_at: markReviewed ? reviewedAt : existing?.reviewed_at,
          reviewed_by: markReviewed ? userName : existing?.reviewed_by,
          source_type: showProblem ? 'reported' : (existing?.source_type ?? 'manual'),
          source_sheet: existing?.source_sheet,
          source_section: existing?.source_section,
          technical_signature: currentSignatureId,
          structure_version: structureVersion,
        })
        existingByKey.delete(id)
      }
    }

    if (markReviewed) {
      next.push({
        ...(existingByKey.get('section_review') ?? {}),
        id: 'section_review',
        row: null,
        type: 'section_review',
        question: `Sección ${section} revisada funcionalmente`,
        response: 'revisada',
        observation: '',
        suggests_block: false,
        status: 'answered',
        review_status: 'section_reviewed',
        reviewed_at: reviewedAt,
        reviewed_by: userName,
        source_type: 'manual',
        technical_signature: currentSignatureId,
        structure_version: structureVersion,
      })
      existingByKey.delete('section_review')
    }

    return [...next, ...existingByKey.values()]
  }

  const saveSection = () => {
    if (readOnly) return
    if (showProblem && problemObservationMissing) {
      toast.warning('Debe describir el problema reportado.')
      return
    }
    if (!showProblem && answeredDecisions < totalDecisions) {
      toast.warning('Complete las decisiones funcionales pendientes antes de guardar.')
      return
    }
    if (!showProblem && blockedPatterns.length > 0) {
      toast.warning(
        'Uno o más grupos de filas de esta sección deben revisarse nuevamente y no pueden confirmarse desde esta vista. Abra "Calibración avanzada" para revisarlos grupo por grupo.'
      )
      return
    }
    // BM-11.8 (§6): defensa adicional -- estructuralmente ya no debería
    // poder construirse desde la UI (all_est='depende' fuerza exceptions='si'
    // en handleAllEstChange), pero se bloquea igual si de algún modo llegara.
    if (!showProblem && responses.all_est === 'depende' && responses.exceptions !== 'si') {
      toast.error(
        '"Elegir excepciones" requiere que Excepciones quede en "Existen excepciones". Ajuste la selección antes de guardar.'
      )
      return
    }
    if (!showProblem && needsEstablishmentScope) {
      if (healthCentersQuery.isLoading) {
        toast.warning(
          'Espere a que termine de cargar la lista de establecimientos antes de guardar.'
        )
        return
      }
      if (healthCentersQuery.isError) {
        toast.error(
          'No se pudo cargar la lista de establecimientos. Reintente antes de guardar una fila con excepciones por establecimiento.'
        )
        return
      }
      if (healthCenters.length === 0) {
        toast.error(
          'No hay establecimientos activos disponibles para definir el alcance por establecimiento.'
        )
        return
      }
      if (selectedCentersForScope.length === 0) {
        toast.warning(
          scopeMode === 'included'
            ? 'Seleccione al menos un establecimiento al que SÍ aplica esta fila (Aplica/Obligatorio).'
            : 'Seleccione al menos un establecimiento al que NO aplica esta fila (No aplica).'
        )
        return
      }
    }

    saveMutation.mutate(buildPayload(!showProblem))
  }

  if (migrationPlan?.category === 'QUICK_CONFIRMATION' && !forceFullReview) {
    return (
      <QuickRevalidationPanel
        serie={serie}
        sheet={sheet}
        section={section}
        sectionTitle={sectionTitle}
        plan={migrationPlan}
        readOnly={readOnly}
        previousSection={previousSection}
        nextSection={nextSection}
        onOpenAdvanced={onOpenAdvanced}
        onNavigateSection={onNavigateSection}
        onReviewAgain={() => setForceFullReview(true)}
      />
    )
  }

  // Flujo de resolucion MISMATCH (2026-08-21): nunca se confirma a ciegas
  // por caer en esta categoria -- MismatchResolutionPanel exige ademas una
  // etiqueta de auditoria previa (safe_reconfirm/human_review/
  // structural_review) por patron antes de admitir cualquier accion rapida.
  if (migrationPlan?.category === 'MISMATCH' && !forceFullReview) {
    return (
      <MismatchResolutionPanel
        serie={serie}
        sheet={sheet}
        section={section}
        sectionTitle={sectionTitle}
        plan={migrationPlan}
        readOnly={readOnly}
        onOpenAdvanced={onOpenAdvanced}
      />
    )
  }

  if (data.calibration_applicability?.status === 'not_calibratable') {
    return (
      <NotCalibratableSectionPanel
        serie={serie}
        sheet={sheet}
        section={section}
        sectionTitle={sectionTitle}
        data={data}
        structureVersion={structureVersion}
        readOnly={readOnly}
        previousSection={previousSection}
        nextSection={nextSection}
        onOpenAdvanced={onOpenAdvanced}
        onNavigateSection={onNavigateSection}
      />
    )
  }

  // "Resumen automatico" original, sin cambios de contenido: ahora vive
  // dentro de "Ver informacion tecnica de la seccion" (vista secundaria).
  const technicalSummary = (
    <>
      {isHybridSection ? (
        <div className="mt-3 space-y-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">
          <p className="font-medium">
            Sección mixta: parte del llenado es automático (derivado de otra hoja/columna) y parte
            requiere captura funcional.
          </p>
          <p>
            {derivedRowCount} fila{derivedRowCount === 1 ? '' : 's'} se calcula
            {derivedRowCount === 1 ? '' : 'n'} automáticamente — no requiere
            {derivedRowCount === 1 ? '' : 'n'} decisión de "Sin datos", severidad, aplicación ni
            excepciones.
          </p>
          <p>
            El resto de las filas de esta sección sí requiere su calibración funcional habitual (ver
            más abajo).
          </p>
          {verticalConsolidation && (
            <p>Además, existe una fila TOTAL real que se trata como consolidación vertical.</p>
          )}
        </div>
      ) : isDerivedAutoFill ? (
        <div className="mt-3 space-y-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">
          <p className="font-medium">Sección de llenado automático (derivada de otra hoja).</p>
          <p>
            Todas las celdas de esta sección son fórmulas (locales y/o referencias a otra hoja); no
            existe ninguna celda de captura editable. El llenado se completa automáticamente.
          </p>
          <p>
            El sistema ya certificó y ejecuta reglas técnicas reales para esta sección — no se
            genera ninguna decisión de "Sin datos"/Severidad/Aplicación/Excepciones, porque no
            aplican a una fila que nunca queda vacía por elección humana.
          </p>
          {verticalConsolidation && (
            <p>Además, existe una fila TOTAL real que se trata como consolidación vertical.</p>
          )}
        </div>
      ) : directInputEvidence ? (
        <div className="mt-3 space-y-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
          <p className="font-medium">Captura directa desde el REM.</p>
          <p>
            Esta sección no posee reglas matemáticas horizontales; los datos son ingresados por el
            funcionario.
          </p>
          <p>
            El sistema validará criterios funcionales sobre esos datos: si se debe registrar 0 o
            permitir vacío, severidad de inconsistencias, aplicabilidad y excepciones.
          </p>
          {verticalConsolidation && (
            <p>Además, existe una fila TOTAL que se trata como consolidación vertical.</p>
          )}
        </div>
      ) : confirmedEvidence ? (
        <div className="mt-3 space-y-2 text-sm text-slate-700">
          <p>
            Esta sección posee cálculos horizontales entre columnas. El sistema validará que los
            totales y componentes registrados mantengan esas relaciones.
          </p>
          {rules.map((rule) => (
            <p key={rule}>✓ {rule}</p>
          ))}
          {ageGroup && <p>✓ Rango etario detectado: {range(ageGroup)}</p>}
          {complementaryColumns.length > 0 && (
            <p>
              ✓ Variables complementarias:{' '}
              {complementaryColumns.map((column) => column.label || column.letter).join(', ')}
            </p>
          )}
          {verticalConsolidation && <p>✓ Consolidación vertical detectada en fila TOTAL.</p>}
        </div>
      ) : (
        <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          Esta sección usa estructura preliminar. Debe confirmar manualmente la lógica detectada
          antes de guardar.
        </div>
      )}
    </>
  )

  return (
    <div className="space-y-5">
      {blockedPatterns.length > 0 && (
        <div className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            <p className="font-semibold">
              {blockedPatterns.length} grupo{blockedPatterns.length === 1 ? '' : 's'} de filas de
              esta sección debe{blockedPatterns.length === 1 ? '' : 'n'} revisarse nuevamente.
            </p>
            <p className="mt-1">
              Las filas de {blockedPatterns.length === 1 ? 'este grupo' : 'estos grupos'} cambiaron
              respecto a la última calibración. No se aplicó ninguna respuesta anterior
              automáticamente. Revíse{blockedPatterns.length === 1 ? 'lo' : 'los'} en{' '}
              <span className="font-medium">Calibración avanzada</span> antes de confirmar la
              sección.
            </p>
          </div>
        </div>
      )}
      {exceptionPatterns.length > 0 && (
        // BM-11.31 (BM1130_BM18AA_PATTERN_EXCEPTION_REQUIRES_DECISION): a
        // diferencia del aviso anterior (que solo advertia), la
        // calibracion rapida ahora EXCLUYE por completo a estos patrones
        // de "Confirmar y guardar sección" (ver quickSaveNormalPatterns) --
        // este aviso deja explicito que fueron excluidos, no solo que
        // "podrian no heredar correctamente".
        <div className="flex items-start gap-2 rounded-xl border border-purple-300 bg-purple-50 p-4 text-sm text-purple-900">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            <p className="font-semibold">
              Esta sección tiene {exceptionRowCount} fila{exceptionRowCount === 1 ? '' : 's'}{' '}
              especial{exceptionRowCount === 1 ? '' : 'es'} (fila
              {exceptionRowCount === 1 ? '' : 's'}{' '}
              {rowRangesText(exceptionPatterns.flatMap((pattern) => pattern.filas))}).
            </p>
            <p className="mt-1">
              Se comporta{exceptionRowCount === 1 ? '' : 'n'} distinto al resto y{' '}
              <span className="font-semibold">
                no recibe{exceptionRowCount === 1 ? '' : 'n'} la decisión general de la sección
              </span>
              . Decída{exceptionRowCount === 1 ? 'la' : 'las'} por separado desde la lista de grupos
              de filas (por ejemplo, «No aplica» cuando no corresponden a un concepto del REM).
            </p>
          </div>
        </div>
      )}
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
              Calibración de la sección
            </p>
            <h2 className="mt-1 text-xl font-bold text-slate-900">
              Sección {section} — {sectionTitle || data.section.titulo}
            </h2>
          </div>
          <span
            className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium ${
              blockedPatterns.length > 0
                ? 'border-amber-200 bg-amber-50 text-amber-700'
                : sectionReviewed
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                  : 'border-slate-200 bg-slate-50 text-slate-600'
            }`}
          >
            {sectionReviewed && blockedPatterns.length === 0 ? (
              <CheckCircle2 className="h-3.5 w-3.5" />
            ) : blockedPatterns.length > 0 ? (
              <AlertTriangle className="h-3.5 w-3.5" />
            ) : null}
            {calibrationStatusLabel}
          </span>
        </div>

        <div className="mt-5">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <p className="text-sm font-medium text-slate-800">
              {answeredDecisions} de {totalDecisions} decisiones completadas
            </p>
            <p className="text-xs text-slate-500">
              {quickPatterns.reduce((total, pattern) => total + pattern.filas.length, 0)} filas
              analizadas · filas {patternRowsText(quickPatterns)}
            </p>
          </div>
          <div
            className="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100"
            role="progressbar"
            aria-label="Progreso de la calibración"
            aria-valuemin={0}
            aria-valuemax={totalDecisions}
            aria-valuenow={answeredDecisions}
          >
            <div
              className="h-full rounded-full bg-emerald-500 transition-all"
              style={{
                width: `${totalDecisions > 0 ? Math.round((answeredDecisions / totalDecisions) * 100) : 0}%`,
              }}
            />
          </div>
        </div>

        {(reportedProblems > 0 || !confirmedEvidence) && (
          <div className="mt-4 space-y-2">
            {reportedProblems > 0 && (
              <p className="flex items-center gap-1.5 text-sm text-red-700">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                {reportedProblems} problema{reportedProblems === 1 ? '' : 's'} reportado
                {reportedProblems === 1 ? '' : 's'} en esta sección.
              </p>
            )}
            {!confirmedEvidence && (
              <p className="flex items-center gap-1.5 text-sm text-amber-700">
                <AlertTriangle className="h-4 w-4 shrink-0" />
                La lectura automática de esta sección es preliminar: confirme más abajo si es
                correcta.
              </p>
            )}
          </div>
        )}

        {/* Mismo estado de siempre, solo fuera de la vista principal: nada de
            esto se recalcula aqui ni cambia su significado. */}
        <Disclosure
          className="mt-4"
          title="Ver información técnica de la sección"
          open={showSectionTechnical}
          onToggle={() => setShowSectionTechnical((value) => !value)}
        >
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatusMetric label="Tipo de sección" value={sectionType} />
            <StatusMetric
              label="Consolidación vertical"
              value={verticalConsolidation ? 'Sí' : 'No detectada'}
            />
            <StatusMetric
              label="Diferencias detectadas"
              value={String((data.warnings ?? []).length)}
            />
            <StatusMetric label="Confianza técnica" value={confidence} />
            <StatusMetric
              label="Evidencia técnica"
              value={
                confirmedEvidence
                  ? 'Verificada'
                  : data.patterns.some((pattern) => pattern.source === 'cell_data')
                    ? 'Disponible'
                    : 'Pendiente'
              }
            />
            <StatusMetric
              label="Calibración funcional"
              value={
                sectionReviewed ? 'Revisada' : answeredDecisions > 0 ? 'En revisión' : 'Sin iniciar'
              }
            />
            <StatusMetric label="Preparación del motor" value="No generada" />
            <StatusMetric label="Activación productiva" value="Inactiva" />
          </div>
          <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p className="text-xs font-semibold text-slate-600">Resumen automático</p>
            {technicalSummary}
          </div>
          <p className="mt-3 text-[11px] text-slate-400">Firma técnica: {currentSignatureId}</p>
        </Disclosure>
      </div>

      {equivalentCandidate && !hideEquivalent && !inheritedFrom && (
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h3 className="text-sm font-semibold text-emerald-900">
                Configuración equivalente encontrada
              </h3>
              <p className="mt-1 text-sm text-emerald-800">
                Puede reutilizar las mismas decisiones de {equivalentCandidate.sheet}/
                {equivalentCandidate.section}.
              </p>
              <p className="mt-1 text-xs text-emerald-700">
                Coincidencia técnica completa. Revise el resumen y guarde si corresponde.
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                disabled={readOnly}
                onClick={() => applyEquivalent(equivalentCandidate)}
                className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Aplicar configuración equivalente
              </button>
              <button
                type="button"
                onClick={onOpenAdvanced}
                className="rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100"
              >
                Revisar diferencias
              </button>
              <button
                type="button"
                onClick={() => setHideEquivalent(true)}
                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                Calibrar manualmente
              </button>
            </div>
          </div>
        </div>
      )}

      {inheritedFrom && (
        <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
          Respuestas heredadas desde {inheritedFrom.sheet}/{inheritedFrom.section}. Revise cada
          decisión antes de guardar.
        </div>
      )}

      {(isHybridSection || isDerivedAutoFill) && (
        <div className="rounded-xl border border-indigo-200 bg-indigo-50 px-5 py-4 text-sm text-indigo-900">
          {isDerivedAutoFill
            ? 'Todas las filas de esta sección se calculan automáticamente: no requieren decidir qué registrar cuando no hay atenciones. Solo confirme que el cálculo es correcto.'
            : `${derivedRowCount} fila${derivedRowCount === 1 ? '' : 's'} de esta sección se calcula${derivedRowCount === 1 ? '' : 'n'} automáticamente y no requiere${derivedRowCount === 1 ? '' : 'n'} decidir qué registrar cuando no hay atenciones. El resto de las filas sí requiere su decisión habitual.`}
        </div>
      )}

      <RowGroupsOverview
        patterns={quickPatterns}
        questions={questions}
        readOnly={readOnly}
        onOpenGroupCalibration={onOpenGroupCalibration ?? onOpenAdvanced}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-semibold text-slate-900">
              Decisión para los grupos de filas similares
            </h3>
            <p className="mt-1 text-xs text-slate-500">
              {showDecisionControls
                ? 'Responda en el lenguaje del REM. La decisión se aplica a todas las filas de los grupos similares.'
                : 'Estas son las decisiones actuales de la sección. Puede ajustarlas si es necesario.'}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
              {answeredDecisions} de {totalDecisions}
            </span>
            {!showDecisionControls && (
              <button
                type="button"
                onClick={() => setShowDecisionDetails(true)}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                Ajustar decisiones
              </button>
            )}
          </div>
        </div>

        {quickSaveFunctionalRows.length > 0 && (
          // BM-11.21 + BM-11.27 + BM-11.31: la cantidad de filas SIEMPRE se
          // toma de la evidencia estructural real (`pattern.filas`, nunca
          // `conceptos.length` -- ver BM-11.20/21), y de la UNION de los
          // patrones normales QUE RECIBEN esta decision compartida
          // (`quickSaveNormalPatterns` -- excluye explicitamente cualquier
          // patron marcado `possible_business_exception`, ver
          // BM1130_BM18AA_PATTERN_EXCEPTION_REQUIRES_DECISION). Con un
          // unico patron en ese conjunto se conserva el texto detallado con
          // conceptos/profesionales; con 2+ se usa un mensaje agregado.
          // Secciones 100% derivadas o donde el unico patron normal es una
          // excepcion ya no muestran este bloque.
          <div className="mt-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
            <p className="text-sm text-slate-700">
              {quickSaveNormalPatterns.length <= 1 && quickSaveNormalPatterns[0] ? (
                <>
                  Esta decisión se aplicará a {quickSaveFunctionalRows.length} fila
                  {quickSaveFunctionalRows.length === 1 ? '' : 's'}
                  {quickSaveNormalPatterns[0].conceptos.length > 0
                    ? `: ${joinWithY(quickSaveNormalPatterns[0].conceptos)}`
                    : ''}
                  {quickSaveNormalPatterns[0].conceptos.length < quickSaveFunctionalRows.length &&
                  quickSaveNormalPatterns[0].profesionales.length >
                    quickSaveNormalPatterns[0].conceptos.length
                    ? ` (${joinWithY(quickSaveNormalPatterns[0].profesionales)})`
                    : ''}
                  .
                </>
              ) : (
                `Esta decisión se aplicará a ${quickSaveFunctionalRows.length} filas de esta sección.`
              )}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Si una fila necesita un criterio distinto, ajústela en «Decisiones funcionales por
              fila», más abajo.
            </p>
          </div>
        )}

        {!showDecisionControls && (
          // Resumen de las respuestas REALES actuales (mismo estado
          // `responses` que se guarda), en lugar del texto fijo anterior.
          <div className="mt-4 grid gap-2 rounded-lg border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-950 md:grid-cols-2">
            <p>
              Si no hay atenciones: <strong>{answerLabel(EMPTY_LABELS, responses.empty)}</strong>
            </p>
            <p>
              Si un dato no cumple:{' '}
              <strong>{answerLabel(SEVERITY_LABELS, responses.inconsistency)}</strong>
            </p>
            <p>
              Establecimientos: <strong>{establishmentsSummary}</strong>
            </p>
            {complementaryGroup && (
              <p>
                Variables complementarias:{' '}
                <strong>{answerLabel(SPECIAL_LABELS, responses.special)}</strong>
              </p>
            )}
            {inheritedFrom && (
              <p className="text-xs text-indigo-700 md:col-span-2">
                Origen: configuración heredada desde {inheritedFrom.sheet}/{inheritedFrom.section}.
              </p>
            )}
          </div>
        )}

        {showDecisionControls && hasDerivedPattern && (
          // BM-11.15 (§7) / BM-11.25 (secciones hibridas): unica
          // confirmacion humana que corresponde al/los patron(es)
          // derivado(s) de esta seccion -- misma clave logic_correct y
          // mismas opciones. Deliberadamente SIN las preguntas de registro/
          // severidad/aplicacion/excepciones (ver buildPayload()).
          <div className="mt-5">
            <DecisionQuestion
              question="¿El cálculo automático de estas filas es correcto?"
              help="Estas filas se completan solas a partir de otras celdas u hojas. No se le pedirá decidir qué registrar cuando no hay atenciones."
              value={responses.logic_correct}
              readOnly={readOnly}
              onChange={(value) => updateResponse('logic_correct', value)}
              options={[
                ['si', 'Correcto'],
                ['parcialmente', 'Parcialmente'],
                ['por_definir', 'Requiere revisión'],
              ]}
            />
          </div>
        )}

        {showDecisionControls && hasNormalPattern && (
          <div className="mt-5 space-y-6">
            {!confirmedEvidence && (
              <DecisionQuestion
                question="¿La lectura automática de esta sección es correcta?"
                help="ATHENEA no pudo confirmar por completo cómo se calculan estas filas. Indique si lo que detectó coincide con el REM."
                value={responses.logic_correct}
                readOnly={readOnly}
                onChange={(value) => updateResponse('logic_correct', value)}
                options={[
                  ['si', 'Correcta'],
                  ['parcialmente', 'Parcialmente'],
                  ['por_definir', 'Por definir'],
                ]}
              />
            )}
            <DecisionQuestion
              question="Si no existen atenciones durante el período, ¿qué corresponde registrar?"
              help={
                exceptionPatterns.length > 0
                  ? '«No aplica» se decide aparte, en las filas especiales de la lista de grupos.'
                  : undefined
              }
              value={responses.empty}
              readOnly={readOnly}
              onChange={(value) => updateResponse('empty', value)}
              options={[
                ['debe_registrar_cero', 'Debe registrar 0', 'La celda no puede quedar vacía.'],
                [
                  'puede_quedar_vacio',
                  'Puede quedar vacío',
                  'No es obligatorio registrar un valor.',
                ],
              ]}
            />
            <DecisionQuestion
              question="Si ATHENEA encuentra un dato que no cumple esta regla, ¿cómo debe informarlo?"
              help={
                responses.empty === 'puede_quedar_vacio'
                  ? 'Con «Puede quedar vacío» las celdas vacías no se informan como incumplimiento; esta respuesta se guarda igualmente.'
                  : undefined
              }
              value={responses.inconsistency}
              readOnly={readOnly}
              onChange={(value) => updateResponse('inconsistency', value)}
              options={[
                ['advertencia', 'Advertencia', 'Se informa, pero la carga queda aceptada.'],
                ['error', 'Error', 'Se informa y la carga queda marcada con errores.'],
              ]}
            />

            <Disclosure
              title="Opciones avanzadas: establecimientos y variables complementarias"
              summary={
                advancedOptionsOpen
                  ? undefined
                  : `${establishmentsSummary}${complementaryGroup ? ` · Variables complementarias: ${answerLabel(SPECIAL_LABELS, responses.special)}` : ''}`
              }
              open={advancedOptionsOpen}
              locked={advancedOptionsNeedAnswer}
              lockedHint="Requiere respuesta"
              onToggle={() => setShowAdvancedOptions((value) => !value)}
            >
              <div className="grid gap-4 lg:grid-cols-2">
                <ChoiceGroup
                  label="¿A qué establecimientos aplica?"
                  value={responses.all_est}
                  readOnly={readOnly}
                  onChange={handleAllEstChange}
                  options={[
                    ['si', 'Todos los establecimientos'],
                    ['depende', 'Elegir excepciones'],
                  ]}
                />
                <ChoiceGroup
                  label="¿Existen excepciones por establecimiento?"
                  value={responses.exceptions}
                  // BM-11.8 (§3, Caso C): 'depende' fuerza exceptions='si' -- el
                  // control queda bloqueado mientras tanto para que la
                  // combinación inválida depende+no no pueda construirse desde
                  // la UI (handleAllEstChange ya lo fuerza al cambiar).
                  readOnly={readOnly || responses.all_est === 'depende'}
                  onChange={handleExceptionsChange}
                  options={[
                    ['no', 'No existen'],
                    ['si', 'Existen excepciones'],
                  ]}
                />
                {responses.all_est === 'depende' && (
                  <p className="text-xs text-slate-500 lg:col-span-2">
                    "Excepciones" queda fijo en "Existen excepciones" mientras se elijan excepciones
                    por establecimiento.
                  </p>
                )}
                {complementaryGroup && (
                  <ChoiceGroup
                    label="Variables complementarias"
                    value={responses.special}
                    readOnly={readOnly}
                    onChange={(value) => updateResponse('special', value)}
                    options={[
                      ['si', 'Validar'],
                      ['no', 'Solo informativas'],
                    ]}
                  />
                )}
              </div>
            </Disclosure>
          </div>
        )}

        {needsEstablishmentScope && (
          <div className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <h4 className="text-sm font-semibold text-amber-900">Alcance por establecimiento</h4>
            <p className="mt-1 text-xs text-amber-800">
              {scopeMode === 'included'
                ? 'Marque los establecimientos a los que SÍ aplica esta fila (Aplica/Obligatorio). Los no marcados quedan fuera.'
                : 'Marque los establecimientos a los que NO aplica esta fila (No aplica). El resto sigue aplicando normalmente.'}
            </p>

            {healthCentersQuery.isLoading && (
              <p className="mt-3 text-xs text-amber-700">Cargando establecimientos…</p>
            )}
            {healthCentersQuery.isError && (
              <p className="mt-3 text-xs font-medium text-red-700">
                No se pudo cargar la lista de establecimientos. Reintente antes de guardar.
              </p>
            )}
            {!healthCentersQuery.isLoading &&
              !healthCentersQuery.isError &&
              healthCenters.length === 0 && (
                <p className="mt-3 text-xs font-medium text-red-700">
                  No hay establecimientos activos disponibles.
                </p>
              )}

            {!healthCentersQuery.isLoading &&
              !healthCentersQuery.isError &&
              healthCenters.length > 0 && (
                <div className="mt-3 grid gap-2 md:grid-cols-2">
                  {healthCenters.map((center) => (
                    <label
                      key={center.id}
                      className="flex items-center justify-between gap-3 rounded-md bg-white px-3 py-2 text-xs"
                    >
                      <span className="font-medium text-slate-700">{center.name}</span>
                      <select
                        value={centerModes[center.name] ?? ''}
                        disabled={readOnly}
                        onChange={(event) =>
                          setCenterModes((prev) => ({
                            ...prev,
                            [center.name]: event.target.value as CenterMode,
                          }))
                        }
                        className="rounded-md border border-slate-300 px-2 py-1 text-xs"
                      >
                        <option value="">Sin definir</option>
                        {CENTER_MODES.map(([value, label]) => (
                          <option key={value} value={value}>
                            {label}
                          </option>
                        ))}
                      </select>
                    </label>
                  ))}
                </div>
              )}
            <p className="mt-3 text-xs text-slate-500">
              "Advertencia solamente" y "Opcional" quedan registrados solo como observación de
              lectura humana — no modifican el alcance funcional de la regla.
            </p>
            <textarea
              value={exceptionDetail}
              disabled={readOnly}
              onChange={(event) => setExceptionDetail(event.target.value)}
              placeholder="Observación adicional sobre excepciones"
              className="mt-3 h-20 w-full rounded-lg border border-amber-300 px-3 py-2 text-sm"
            />
          </div>
        )}

        {showProblem && (
          <div className="mt-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <h4 className="text-sm font-semibold text-red-900">Reportar problema</h4>
            <div className="mt-3 grid gap-3 md:grid-cols-2">
              <select
                value={problemType}
                disabled={readOnly}
                onChange={(event) => setProblemType(event.target.value as ProblemType)}
                className="rounded-lg border border-red-300 px-3 py-2 text-sm"
              >
                <option value="regla incorrecta">Regla incorrecta</option>
                <option value="columna equivocada">Columna equivocada</option>
                <option value="rango incorrecto">Rango incorrecto</option>
                <option value="fila que no aplica">Fila que no aplica</option>
                <option value="otro problema">Otro problema</option>
              </select>
              <textarea
                value={problemObservation}
                disabled={readOnly}
                onChange={(event) => setProblemObservation(event.target.value)}
                placeholder="Describa el problema detectado"
                className="h-24 rounded-lg border border-red-300 px-3 py-2 text-sm md:col-span-2"
              />
            </div>
            {problemObservationMissing && (
              <p className="mt-2 text-xs font-medium text-red-700">
                La observación es obligatoria.
              </p>
            )}
          </div>
        )}

        {lastSavedAt && !hasUnsavedChanges && (
          <div
            role="status"
            className="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
          >
            <span className="inline-flex items-center gap-1.5 font-medium">
              <CheckCircle2 className="h-4 w-4" />
              Calibración guardada correctamente.
            </span>
            <span className="text-emerald-800">
              {answeredDecisions} de {totalDecisions} decisiones completadas · Estado:{' '}
              {calibrationStatusLabel}
            </span>
            <span className="text-xs text-emerald-700">
              {lastSavedAt.toLocaleTimeString('es-CL')}
            </span>
          </div>
        )}

        <div className="mt-5 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-4">
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={onOpenAdvanced}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              <Settings2 className="h-4 w-4" />
              Calibración avanzada
            </button>
            {!readOnly && (
              <>
                {/* applySuggestions solo completa en pantalla las respuestas
                    vacias con valores sugeridos; no guarda nada. */}
                <button
                  type="button"
                  onClick={applySuggestions}
                  disabled={!confirmedEvidence || saveMutation.isPending}
                  title="Completa en pantalla las respuestas vacías con los valores habituales. No guarda."
                  className="rounded-lg border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Usar valores sugeridos
                </button>
                <button
                  type="button"
                  onClick={() => setShowProblem((value) => !value)}
                  className="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                >
                  Reportar problema
                </button>
                <button
                  type="button"
                  onClick={saveSection}
                  disabled={saveMutation.isPending || (!showProblem && !canFastSave)}
                  className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {saveMutation.isPending
                    ? 'Guardando...'
                    : showProblem
                      ? 'Guardar reporte de problema'
                      : 'Confirmar calibración'}
                </button>
              </>
            )}
          </div>
        </div>

        {/* Navegacion independiente del guardado: nunca guarda nada. */}
        {(previousSection || nextSection) && (
          <nav
            aria-label="Navegación entre secciones"
            className="mt-4 flex flex-wrap items-center justify-between gap-3"
          >
            <div>
              {previousSection && (
                <button
                  type="button"
                  onClick={() => goToSection(previousSection)}
                  className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                >
                  <ArrowLeft className="h-4 w-4" />
                  Sección anterior
                </button>
              )}
            </div>
            <div>
              {nextSection && (
                <button
                  type="button"
                  onClick={() => goToSection(nextSection)}
                  className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                >
                  Siguiente sección
                  <ArrowRight className="h-4 w-4" />
                </button>
              )}
            </div>
          </nav>
        )}
      </div>
    </div>
  )
}

function StatusMetric({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="mt-1 text-sm font-semibold text-slate-900">{value}</p>
    </div>
  )
}

function ChoiceGroup({
  label,
  value,
  options,
  readOnly,
  onChange,
}: {
  label: string
  value: string
  options: Array<[string, string]>
  readOnly: boolean
  onChange: (value: string) => void
}) {
  return (
    <div>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
      <div className="flex flex-wrap gap-2">
        {options.map(([optionValue, optionLabel]) => (
          <button
            key={optionValue}
            type="button"
            disabled={readOnly}
            onClick={() => onChange(optionValue)}
            className={`rounded-lg border px-3 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
              value === optionValue
                ? 'border-indigo-600 bg-indigo-600 text-white'
                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
            }`}
          >
            {optionLabel}
          </button>
        ))}
      </div>
    </div>
  )
}

// ─── Vista simple para Estadistica (solo presentacion) ──────────────────
// Los componentes de abajo NO guardan nada ni recalculan decisiones: solo
// muestran el mismo estado (patrones, preguntas y respuestas) con lenguaje
// operativo. Internamente cada "grupo de filas" sigue siendo un patron.

function Disclosure({
  title,
  summary,
  open,
  onToggle,
  locked = false,
  lockedHint,
  className,
  children,
}: {
  title: string
  summary?: string
  open: boolean
  onToggle: () => void
  locked?: boolean
  lockedHint?: string
  className?: string
  children: React.ReactNode
}) {
  return (
    <div className={`rounded-lg border border-slate-200 ${className ?? ''}`}>
      <button
        type="button"
        onClick={locked ? undefined : onToggle}
        aria-expanded={open}
        aria-disabled={locked || undefined}
        className={`flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left ${
          locked ? 'cursor-default' : 'hover:bg-slate-50'
        }`}
      >
        <span className="flex items-center gap-2 text-sm font-medium text-slate-700">
          {open ? (
            <ChevronDown className="h-4 w-4 text-slate-400" />
          ) : (
            <ChevronRight className="h-4 w-4 text-slate-400" />
          )}
          {title}
        </span>
        {locked && lockedHint ? (
          <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-amber-200">
            {lockedHint}
          </span>
        ) : summary ? (
          <span className="truncate text-xs text-slate-500">{summary}</span>
        ) : null}
      </button>
      {open && <div className="border-t border-slate-100 px-4 py-4">{children}</div>}
    </div>
  )
}

function DecisionQuestion({
  question,
  help,
  value,
  options,
  readOnly,
  onChange,
}: {
  question: string
  help?: string
  value: string
  options: Array<[string, string, string?]>
  readOnly: boolean
  onChange: (value: string) => void
}) {
  return (
    <fieldset>
      <legend className="text-sm font-semibold text-slate-900">{question}</legend>
      {help && <p className="mt-1 text-xs text-slate-500">{help}</p>}
      <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {options.map(([optionValue, optionLabel, description]) => {
          const selected = value === optionValue
          return (
            <button
              key={optionValue}
              type="button"
              disabled={readOnly}
              aria-pressed={selected}
              onClick={() => onChange(optionValue)}
              className={`rounded-lg border px-4 py-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
                selected
                  ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600'
                  : 'border-slate-200 bg-white hover:bg-slate-50'
              }`}
            >
              <span
                className={`block text-sm font-semibold ${selected ? 'text-indigo-800' : 'text-slate-800'}`}
              >
                {optionLabel}
              </span>
              {description && (
                <span className="mt-0.5 block text-xs text-slate-500">{description}</span>
              )}
            </button>
          )
        })}
      </div>
    </fieldset>
  )
}

type RowGroupKind = 'similar' | 'special' | 'automatic'

function rowGroupKind(pattern: PatternGroup): RowGroupKind {
  if (pattern.mode === 'derived_auto_fill') return 'automatic'
  if (pattern.possible_business_exception) return 'special'
  return 'similar'
}

function RowGroupsOverview({
  patterns,
  questions,
  readOnly,
  onOpenGroupCalibration,
}: {
  patterns: PatternGroup[]
  questions: CalibrationQuestion[]
  readOnly: boolean
  onOpenGroupCalibration: () => void
}) {
  if (patterns.length === 0) return null

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h3 className="text-sm font-semibold text-slate-900">Grupos de filas de esta sección</h3>
      <p className="mt-1 text-xs text-slate-500">
        ATHENEA agrupa las filas que se comportan igual en el REM. La decisión general se aplica a
        los grupos de filas similares; las filas especiales se deciden por separado; y cualquier
        fila puede ajustarse individualmente en «Decisiones funcionales por fila».
      </p>
      <ul className="mt-4 space-y-2">
        {patterns.map((pattern) => (
          <RowGroupCard
            key={pattern.id}
            pattern={pattern}
            questions={questions.filter((question) => question.pattern_id === pattern.id)}
            readOnly={readOnly}
            onOpenGroupCalibration={onOpenGroupCalibration}
          />
        ))}
      </ul>
    </div>
  )
}

function RowGroupCard({
  pattern,
  questions,
  readOnly,
  onOpenGroupCalibration,
}: {
  pattern: PatternGroup
  questions: CalibrationQuestion[]
  readOnly: boolean
  onOpenGroupCalibration: () => void
}) {
  const [showTechnical, setShowTechnical] = useState(false)
  const kind = rowGroupKind(pattern)
  const rowCount = pattern.filas.length
  const ranges = rowRangesText(pattern.filas)
  // Solo se muestra un concepto cuando el grupo tiene UNO solo: con varios,
  // cualquier nombre seria una inferencia.
  const uniqueConcepts = [
    ...new Set(pattern.conceptos.map((concept) => concept.trim()).filter(Boolean)),
  ]
  const concept = uniqueConcepts.length === 1 ? uniqueConcepts[0] : null
  const emptyQuestion = questions.find(
    (question) => question.id === patternQuestionId(pattern.id, 'empty')
  )
  const logicQuestion = questions.find(
    (question) => question.id === patternQuestionId(pattern.id, 'logic_correct')
  )
  const needsReview = needsRevalidation(pattern.reconciliation_status)

  const title =
    kind === 'automatic'
      ? 'Filas de cálculo automático'
      : kind === 'special'
        ? 'Filas especiales'
        : concept
          ? `Grupo de filas similares — ${concept}`
          : `Grupo de ${rowCount} fila${rowCount === 1 ? '' : 's'} similar${rowCount === 1 ? '' : 'es'}`
  const subtitle =
    kind === 'automatic'
      ? `Filas ${ranges} · se completan automáticamente`
      : kind === 'special'
        ? `Fila${rowCount === 1 ? '' : 's'} ${ranges} · comportamiento diferente al resto de la sección`
        : `Filas ${ranges} · ${rowCount} fila${rowCount === 1 ? '' : 's'} con el mismo comportamiento`

  const decided =
    kind === 'automatic' ? Boolean(logicQuestion?.response) : Boolean(emptyQuestion?.response)
  const decisionText =
    kind === 'automatic'
      ? `Cálculo automático: ${answerLabel(LOGIC_LABELS, logicQuestion?.response)}`
      : answerLabel(EMPTY_LABELS, emptyQuestion?.response)

  return (
    <li className="rounded-lg border border-slate-200">
      <div className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-slate-900">{title}</p>
          <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {needsReview && (
            <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
              Revisar nuevamente
            </span>
          )}
          <span
            className={`rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ${
              decided
                ? 'bg-emerald-50 text-emerald-800 ring-emerald-200'
                : 'bg-slate-50 text-slate-500 ring-slate-200'
            }`}
          >
            {decided ? decisionText : 'Sin decidir'}
          </span>
        </div>
      </div>

      {kind === 'special' && (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-purple-50/50 px-4 py-2.5">
          <p className="text-xs text-purple-900">
            No recibe la decisión general. Decida estas filas por separado, por ejemplo «No aplica»
            si no corresponden a un concepto del REM.
          </p>
          <button
            type="button"
            onClick={onOpenGroupCalibration}
            className="rounded-md border border-purple-200 bg-white px-2.5 py-1 text-xs font-medium text-purple-800 hover:bg-purple-50"
          >
            {readOnly ? 'Ver decisión de estas filas' : 'Decidir estas filas'}
          </button>
        </div>
      )}

      <div className="border-t border-slate-100 px-4 py-2">
        <button
          type="button"
          onClick={() => setShowTechnical((value) => !value)}
          aria-expanded={showTechnical}
          className="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
        >
          {showTechnical ? (
            <ChevronDown className="h-3.5 w-3.5" />
          ) : (
            <ChevronRight className="h-3.5 w-3.5" />
          )}
          Ver información técnica
        </button>
        {showTechnical && <RowGroupTechnicalInfo pattern={pattern} emptyQuestion={emptyQuestion} />}
      </div>
    </li>
  )
}

// Solo datos que ya trae el patron/pregunta: un campo ausente no se muestra.
function RowGroupTechnicalInfo({
  pattern,
  emptyQuestion,
}: {
  pattern: PatternGroup
  emptyQuestion?: CalibrationQuestion
}) {
  const originColumns = pattern.origin_columns?.length
    ? pattern.origin_columns
    : pattern.columnas_origen
  const fields: Array<[string, string | null | undefined]> = [
    ['Identificador del patrón', `pattern_${pattern.id}`],
    ['Filas detectadas', pattern.filas.join(', ')],
    ['Cantidad de filas', String(pattern.filas.length)],
    ['Modo', pattern.mode],
    ['Origen de la evidencia', pattern.source],
    ['Fórmula', pattern.formula_template || null],
    ['Columna total', pattern.columna_total || null],
    ['Columnas de origen', originColumns?.length ? originColumns.join(', ') : null],
    ['Fingerprint', pattern.row_fingerprint],
    ['Estado de reconciliación', pattern.reconciliation_status],
    ['Tamaño relativo', pattern.pattern_size_class],
    ['Posible excepción de negocio', pattern.possible_business_exception ? 'Sí' : 'No'],
    ['Motivo de la excepción', pattern.exception_reason],
    ['Estado de revisión', emptyQuestion?.review_status],
    ['Revisado por', emptyQuestion?.reviewed_by],
    [
      'Fecha de revisión',
      emptyQuestion?.reviewed_at
        ? new Date(emptyQuestion.reviewed_at).toLocaleString('es-CL')
        : null,
    ],
  ]

  return (
    <dl className="mt-2 grid gap-x-4 gap-y-1.5 rounded-md bg-slate-50 p-3 sm:grid-cols-[12rem_1fr]">
      {fields
        .filter(([, value]) => value !== null && value !== undefined && value !== '')
        .map(([term, value]) => (
          <div key={term} className="contents">
            <dt className="text-xs font-medium text-slate-500">{term}</dt>
            <dd className="break-words font-mono text-xs text-slate-700">{value}</dd>
          </div>
        ))}
    </dl>
  )
}
