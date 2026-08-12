import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  ClipboardCheck,
  Settings2,
} from 'lucide-react'
import { toast } from 'sonner'
import { useAuthStore } from '@/app/store/authStore'
import { calibrationService } from '../../services/calibration'
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

const HEALTH_CENTERS = [
  'CESFAM Cirujano Aguirre',
  'CESFAM Cirujano Videla',
  'CESFAM Sur',
  'CESFAM Guzmán',
  'CECOF',
  'SAPU',
  'SAR',
]

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

function sectionHasCompleteCellEvidence(patterns: PatternGroup[], warnings?: string[]) {
  if ((warnings ?? []).length > 0) return false
  return (
    patterns.length > 0 &&
    patterns.every(
      (pattern) =>
        pattern.source === 'cell_data' &&
        pattern.rows.length > 0 &&
        (pattern.mode === 'direct_input' ||
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

function sectionTypeLabel(directInputEvidence: boolean, confirmedEvidence: boolean) {
  if (directInputEvidence) return 'Captura directa'
  if (confirmedEvidence) return 'Cálculo horizontal'
  return 'Pendiente de revisión'
}

function hasVerticalConsolidation(data: PatternMatrixResponse) {
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

function isSexTotal(rule: ReturnType<typeof firstRules>[number]) {
  const origins = rule.origin_columns.join(',')
  return (
    (rule.total_column === 'B' && origins === 'C,D') ||
    (rule.total_column === 'C' && origins === 'D,E')
  )
}

function isMenAge(rule: ReturnType<typeof firstRules>[number]) {
  return rule.total_column === 'C' && rule.origin_columns.length > 2
}

function isWomenAge(rule: ReturnType<typeof firstRules>[number]) {
  return rule.total_column === 'D' && rule.origin_columns.length > 2
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
  if (isSexTotal(rule)) return 'Ambos Sexos = Hombres + Mujeres'
  if (isMenAge(rule)) return 'Total Hombres = suma de rangos etarios de Hombres'
  if (isWomenAge(rule)) return 'Total Mujeres = suma de rangos etarios de Mujeres'
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

function getInitialResponse(questions: CalibrationQuestion[], id: string) {
  return questions.find((question) => questionKey(question) === id)?.response ?? ''
}

function hasSectionReview(questions: CalibrationQuestion[]) {
  return questions.some(
    (question) =>
      questionKey(question) === 'section_review' && question.review_status === 'section_reviewed'
  )
}

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

export default function QuickCalibrationPanel({
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
  const sectionType = sectionTypeLabel(directInputEvidence, confirmedEvidence)
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
  // la seccion (calculado por el backend, nunca aqui). La calibracion
  // rapida aplica UNA sola decision a todos los patrones de la seccion por
  // diseno -- por eso, si existen excepciones detectadas, se advierte para
  // que no se asuma que heredan la misma configuracion sin revisarlas.
  const exceptionPatterns = useMemo(
    () => quickPatterns.filter((pattern) => pattern.possible_business_exception),
    [quickPatterns]
  )
  // Fase 3/4 (2026-08-12): clasificacion de migracion al fingerprint v2,
  // recalculada EN VIVO en cada carga -- decide unicamente si se muestra
  // QuickRevalidationPanel en vez del flujo normal. No participa del
  // calculo de progreso ni de reconcileLive() en produccion.
  const { data: migrationPlan } = useQuery({
    queryKey: ['migration-plan', sheet, section],
    queryFn: () => calibrationService.getMigrationPlan(sheet, section),
    enabled: Boolean(sheet) && Boolean(section),
    staleTime: 0,
  })
  // "Revisar nuevamente esta sección" no persiste nada -- solo oculta el
  // panel de confirmacion rapida y deja pasar al flujo normal de
  // calibracion para que el usuario la rehaga manualmente. Se reinicia al
  // cambiar de seccion (ajuste en render, no en efecto, para evitar el
  // render en cascada de un setState dentro de useEffect).
  const sectionKey = `${sheet}_${section}`
  const [forceFullReview, setForceFullReview] = useState(false)
  const [lastSectionKey, setLastSectionKey] = useState(sectionKey)
  if (lastSectionKey !== sectionKey) {
    setLastSectionKey(sectionKey)
    setForceFullReview(false)
  }

  const [showProblem, setShowProblem] = useState(false)
  const [problemType, setProblemType] = useState<ProblemType>('regla incorrecta')
  const [problemObservation, setProblemObservation] = useState('')
  const [exceptionDetail, setExceptionDetail] = useState('')
  const [centerModes, setCenterModes] = useState<Record<string, CenterMode>>({})
  const [sourceByDecision, setSourceByDecision] = useState<Record<string, SourceType>>({})
  const [inheritedFrom, setInheritedFrom] = useState<EquivalentCandidateInput | null>(null)
  const [hideEquivalent, setHideEquivalent] = useState(false)
  const [showDecisionDetails, setShowDecisionDetails] = useState(false)

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
  const decisions = [
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
    !confirmedEvidence ||
    showProblem ||
    !responses.empty ||
    responses.exceptions === 'si' ||
    confidence !== 'Alta'
  const showDecisionControls = showDecisionDetails || needsManualDecisionView
  const canFastSave =
    answeredDecisions === totalDecisions && !showProblem && blockedPatterns.length === 0

  const saveMutation = useMutation({
    mutationFn: (payload: CalibrationQuestion[]) =>
      calibrationService.savePatternQuestions(sheet, section, payload),
    onSuccess: () => {
      toast.success(
        showProblem
          ? 'Problema reportado correctamente.'
          : nextSection
            ? 'Sección guardada. Abriendo la siguiente sección.'
            : 'Sección guardada correctamente.'
      )
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', sheet, section] })
      if (!showProblem && nextSection) onNavigateSection(nextSection)
    },
    onError: () => toast.error('No se pudo guardar la calibración rápida'),
  })

  const updateResponse = (key: DecisionKey, value: string) => {
    setResponses((prev) => ({ ...prev, [key]: value }))
    setSourceByDecision((prev) => ({ ...prev, [key]: 'manual' }))
  }

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

      const entries: Array<[DecisionKey, string, string]> = [
        ['empty', 'Si no existen datos, debe registrarse 0 o puede quedar vacío', responses.empty],
        ['all_est', 'Aplicabilidad a establecimientos que reportan la hoja', responses.all_est],
        [
          'exceptions',
          'Excepciones por establecimiento o tipo de establecimiento',
          responses.exceptions,
        ],
        ['inconsistency', 'Clasificación funcional de la inconsistencia', responses.inconsistency],
      ]

      if (complementaryGroup)
        entries.push([
          'special',
          'Validación funcional de variables complementarias',
          responses.special,
        ])
      if (!confirmedEvidence)
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
        next.push({
          ...existing,
          ...base,
          id,
          question: `${label} (Patrón ${pattern.id}: ${pattern.filas.join(', ')})`,
          response: finalResponse,
          observation,
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
        'Uno o más patrones de esta sección requieren revalidación y no pueden certificarse desde la calibración rápida. Abra "Ver evidencia técnica" para revisarlos patrón por patrón.'
      )
      return
    }

    saveMutation.mutate(buildPayload(!showProblem))
  }

  if (migrationPlan?.category === 'QUICK_CONFIRMATION' && !forceFullReview) {
    return (
      <QuickRevalidationPanel
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

  if (data.calibration_applicability?.status === 'not_calibratable') {
    return (
      <NotCalibratableSectionPanel
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

  return (
    <div className="space-y-5">
      {blockedPatterns.length > 0 && (
        <div className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            <p className="font-semibold">
              {blockedPatterns.length} patrón{blockedPatterns.length === 1 ? '' : 'es'} de esta
              sección requiere{blockedPatterns.length === 1 ? '' : 'n'} revalidación.
            </p>
            <p className="mt-1">
              El conjunto de filas de este patrón cambió respecto a la última calibración. No se
              aplicó ninguna respuesta anterior automáticamente. Use{' '}
              <span className="font-medium">Ver evidencia técnica</span> para revisar cada patrón y
              su respuesta histórica antes de certificar la sección.
            </p>
          </div>
        </div>
      )}
      {exceptionPatterns.length > 0 && (
        <div className="flex items-start gap-2 rounded-xl border border-purple-300 bg-purple-50 p-4 text-sm text-purple-900">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <div>
            <p className="font-semibold">
              {exceptionPatterns.length} patrón{exceptionPatterns.length === 1 ? '' : 'es'} de esta
              sección {exceptionPatterns.length === 1 ? 'se detectó' : 'se detectaron'} como posible
              {exceptionPatterns.length === 1 ? '' : 's'} excepción de negocio (patrón
              {exceptionPatterns.length === 1 ? '' : 'es'}{' '}
              {exceptionPatterns.map((pattern) => pattern.id).join(', ')}).
            </p>
            <p className="mt-1">
              La calibración rápida aplica la misma decisión a todos los patrones de la sección. No
              asuma que estas filas heredan la configuración general: revíselas de forma individual
              en <span className="font-medium">Ver evidencia técnica</span> antes de certificar.
            </p>
          </div>
        </div>
      )}
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
              Calibración rápida
            </p>
            <h2 className="mt-1 text-xl font-bold text-slate-900">
              Sección {section} — {sectionTitle || data.section.titulo}
            </h2>
          </div>
          <span
            className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium ${
              confirmedEvidence
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-amber-200 bg-amber-50 text-amber-700'
            }`}
          >
            {confirmedEvidence ? (
              <CheckCircle2 className="h-3.5 w-3.5" />
            ) : (
              <AlertTriangle className="h-3.5 w-3.5" />
            )}
            Evidencia técnica: {confirmedEvidence ? 'OK' : 'Preliminar'}
          </span>
        </div>

        <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatusMetric label="Filas analizadas" value={patternRowsText(quickPatterns)} />
          <StatusMetric
            label="Decisiones funcionales"
            value={`${answeredDecisions}/${totalDecisions}`}
          />
          <StatusMetric label="Problemas reportados" value={String(reportedProblems)} />
          <StatusMetric
            label="Estado"
            value={
              blockedPatterns.length > 0
                ? 'Requiere revalidación'
                : sectionReviewed
                  ? 'Lista para certificar'
                  : 'Pendiente'
            }
          />
        </div>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
        </div>
      </div>

      <div className="grid gap-3 lg:grid-cols-4">
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

      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <ClipboardCheck className="h-4 w-4 text-emerald-600" />
          Resumen automático
        </h3>
        {directInputEvidence ? (
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
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-semibold text-slate-900">Decisiones funcionales</h3>
            <p className="mt-1 text-xs text-slate-500">
              {showDecisionControls
                ? 'Complete solo lo que requiere criterio funcional.'
                : 'No hay fórmulas técnicas que confirmar. Puede aceptar la recomendación o ajustar manualmente.'}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">
              {answeredDecisions}/{totalDecisions}
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

        {primaryPattern && primaryPattern.conceptos.length > 0 && (
          <div className="mt-3 rounded-lg border border-slate-100 bg-slate-50 px-4 py-3">
            <p className="text-sm text-slate-700">
              Esta decisión se aplicará a {primaryPattern.conceptos.length} fila
              {primaryPattern.conceptos.length === 1 ? '' : 's'}:{' '}
              {joinWithY(primaryPattern.conceptos)}.
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Puedes cambiar una fila de forma individual en la tabla inferior.
            </p>
          </div>
        )}

        {!showDecisionControls && (
          <div className="mt-4 rounded-lg border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-950">
            <p className="font-semibold">Configuración recomendada</p>
            <div className="mt-2 grid gap-2 md:grid-cols-2">
              <p>Sin datos: pendiente de definir por Estadística.</p>
              <p>Severidad: Error.</p>
              <p>Aplicación: todos los establecimientos que reportan la hoja.</p>
              {complementaryGroup && (
                <p>Variables complementarias: validar de forma independiente.</p>
              )}
            </div>
            {inheritedFrom && (
              <p className="mt-2 text-xs text-indigo-700">
                Origen: configuración heredada desde {inheritedFrom.sheet}/{inheritedFrom.section}.
              </p>
            )}
          </div>
        )}

        {showDecisionControls && (
          <div className="mt-4 grid gap-4 lg:grid-cols-2">
            {!confirmedEvidence && (
              <ChoiceGroup
                label="Lógica detectada"
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
            <ChoiceGroup
              label="Sin datos"
              value={responses.empty}
              readOnly={readOnly}
              onChange={(value) => updateResponse('empty', value)}
              options={[
                ['debe_registrar_cero', 'Registrar 0'],
                ['puede_quedar_vacio', 'Permitir vacío'],
              ]}
            />
            <ChoiceGroup
              label="Severidad"
              value={responses.inconsistency}
              readOnly={readOnly}
              onChange={(value) => updateResponse('inconsistency', value)}
              options={[
                ['error', 'Error'],
                ['advertencia', 'Advertencia'],
              ]}
            />
            <ChoiceGroup
              label="Aplicación"
              value={responses.all_est}
              readOnly={readOnly}
              onChange={(value) => updateResponse('all_est', value)}
              options={[
                ['si', 'Todos los establecimientos'],
                ['depende', 'Elegir excepciones'],
              ]}
            />
            <ChoiceGroup
              label="Excepciones"
              value={responses.exceptions}
              readOnly={readOnly}
              onChange={(value) => updateResponse('exceptions', value)}
              options={[
                ['no', 'No existen'],
                ['si', 'Existen excepciones'],
              ]}
            />
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
        )}

        {responses.exceptions === 'si' && (
          <div className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <h4 className="text-sm font-semibold text-amber-900">Alcance por establecimiento</h4>
            <div className="mt-3 grid gap-2 md:grid-cols-2">
              {HEALTH_CENTERS.map((center) => (
                <label
                  key={center}
                  className="flex items-center justify-between gap-3 rounded-md bg-white px-3 py-2 text-xs"
                >
                  <span className="font-medium text-slate-700">{center}</span>
                  <select
                    value={centerModes[center] ?? ''}
                    disabled={readOnly}
                    onChange={(event) =>
                      setCenterModes((prev) => ({
                        ...prev,
                        [center]: event.target.value as CenterMode,
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

        <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
          <div className="flex flex-wrap gap-2">
            {previousSection && (
              <button
                type="button"
                onClick={() => onNavigateSection(previousSection)}
                className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
              >
                <ArrowLeft className="h-4 w-4" />
                Sección anterior
              </button>
            )}
          </div>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={onOpenAdvanced}
              className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              <Settings2 className="h-4 w-4" />
              Ver evidencia técnica
            </button>
            {!readOnly && (
              <>
                <button
                  type="button"
                  onClick={applySuggestions}
                  disabled={!confirmedEvidence || saveMutation.isPending}
                  className="rounded-lg border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Confirmar sugerencias
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
                      : 'Confirmar y guardar sección'}
                  {!showProblem && nextSection && <ArrowRight className="ml-1 inline h-4 w-4" />}
                </button>
              </>
            )}
          </div>
        </div>
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
