import { type ReactNode, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, CheckCircle2, Lock, Save } from 'lucide-react'
import { toast } from 'sonner'
import { useAuthStore } from '@/app/store/authStore'
import { calibrationService } from '../../services/calibration'
import type { CalibrationQuestion, ColumnGroup, PatternGroup } from '../../types/calibration'

interface Props {
  sheet: string
  section: string
  patterns: PatternGroup[]
  columnGroups?: ColumnGroup[]
  initialQuestions: CalibrationQuestion[]
  warnings?: string[]
  readOnly?: boolean
}

type ReviewState = 'Sin iniciar' | 'En revisión' | 'Revisado' | 'Con observaciones'

interface QuestionDefinition {
  idSuffix: string
  text: string
  kind: keyof typeof OPTION_SETS
  required: boolean
}

const OPTION_SETS = {
  empty: [
    ['debe_registrar_cero', 'Debe registrar 0'],
    ['puede_quedar_vacio', 'Puede quedar vacío'],
    ['no_aplica', 'No aplica'],
    ['depende_del_establecimiento', 'Depende del establecimiento'],
  ],
  applicability: [
    ['si', 'Sí'],
    ['no', 'No'],
    ['depende', 'Depende'],
    ['no_aplica', 'No aplica'],
  ],
  exceptions: [
    ['no', 'No'],
    ['si', 'Sí'],
    ['por_definir', 'Por definir'],
  ],
  severity: [
    ['error', 'Error'],
    ['advertencia', 'Advertencia'],
  ],
  special: [
    ['si', 'Sí'],
    ['no', 'No'],
    ['parcialmente', 'Parcialmente'],
    ['por_definir', 'Por definir'],
  ],
} as const

const PATTERN_QUESTIONS: QuestionDefinition[] = [
  {
    idSuffix: 'empty',
    text: 'Si no existen datos, ¿debe registrarse 0 o puede quedar vacío?',
    kind: 'empty',
    required: true,
  },
  {
    idSuffix: 'all_est',
    text: '¿Este patrón debe validarse en todos los establecimientos que reportan esta hoja?',
    kind: 'applicability',
    required: true,
  },
  {
    idSuffix: 'exceptions',
    text: '¿Existen excepciones por tipo de establecimiento?',
    kind: 'exceptions',
    required: true,
  },
  {
    idSuffix: 'inconsistency',
    text: '¿La inconsistencia debe ser error o advertencia?',
    kind: 'severity',
    required: true,
  },
  {
    idSuffix: 'special',
    text: '¿Las columnas especiales U:AH se validan por separado?',
    kind: 'special',
    required: true,
  },
]

const GENERAL_QUESTIONS: Array<{
  id: string
  text: string
  kind: keyof typeof OPTION_SETS
  required: boolean
}> = [
  {
    id: 'general_special_validation',
    text: '¿Las columnas especiales U:AH se validan de manera independiente del total C?',
    kind: 'special',
    required: true,
  },
  {
    id: 'general_aggregated',
    text: '¿Los patrones detectados son correctos funcionalmente?',
    kind: 'applicability',
    required: true,
  },
  {
    id: 'general_sex_columns',
    text: '¿Las columnas especiales U y V, asociadas a sexo, se validan de forma independiente y no participan en el cálculo del total C?',
    kind: 'applicability',
    required: true,
  },
]

const A01_B_PATTERN_QUESTIONS: QuestionDefinition[] = [
  {
    idSuffix: 'logic_correct',
    text: '¿La lógica detectada para este patrón es correcta?',
    kind: 'special',
    required: true,
  },
  {
    idSuffix: 'empty',
    text: 'Si no existen datos, ¿debe registrarse 0 o puede quedar vacío?',
    kind: 'empty',
    required: true,
  },
  {
    idSuffix: 'all_est',
    text: '¿Este patrón debe validarse en todos los establecimientos que reportan esta hoja?',
    kind: 'applicability',
    required: true,
  },
  {
    idSuffix: 'exceptions',
    text: '¿Existen excepciones por tipo de establecimiento?',
    kind: 'exceptions',
    required: true,
  },
  {
    idSuffix: 'inconsistency',
    text: '¿La inconsistencia debe ser error o advertencia?',
    kind: 'severity',
    required: true,
  },
]

const HUMAN_DECISION_SUFFIXES = new Set(['logic_correct'])
const SUGGESTED_SUFFIXES = new Set(['all_est', 'exceptions', 'special', 'inconsistency'])
const FORMULA_CONFIRMATION_ID_SUFFIX = 'formula_confirmation'

const SUGGESTED_RESPONSES: Record<string, string> = {
  all_est: 'si',
  exceptions: 'no',
  special: 'si',
  inconsistency: 'error',
}

const A01_B_GENERAL_QUESTIONS: Array<{
  id: string
  text: string
  kind: keyof typeof OPTION_SETS
  required: boolean
}> = [
  {
    id: 'general_b_main_rule',
    text: '¿El total Ambos sexos (C) debe ser igual a Hombres (D) más Mujeres (E)?',
    kind: 'applicability',
    required: true,
  },
  {
    id: 'general_b_age_empty',
    text: '¿Las columnas del rango etario F:AJ deben completarse obligatoriamente con 0 cuando no existan datos o pueden quedar vacías?',
    kind: 'empty',
    required: true,
  },
  {
    id: 'general_b_age_total',
    text: '¿La suma de los rangos etarios debe cuadrar con algún total de la fila?',
    kind: 'applicability',
    required: true,
  },
  {
    id: 'general_b_complementary',
    text: '¿Las variables complementarias AK:AN se validan de forma independiente?',
    kind: 'special',
    required: true,
  },
  {
    id: 'general_b_exceptions',
    text: '¿Existen excepciones por establecimiento o tipo de establecimiento?',
    kind: 'exceptions',
    required: true,
  },
]

function patternQuestionId(patternId: number, suffix: string) {
  return `patron_${patternId}_${suffix}`
}

function patternQuestionText(pattern: PatternGroup, question: QuestionDefinition) {
  return `${question.text} (Patrón ${pattern.id}: ${pattern.filas.join(', ')})`
}

function questionKey(question: Pick<CalibrationQuestion, 'id' | 'question'>) {
  return question.id || question.question
}

function isKnownOption(kind: keyof typeof OPTION_SETS, value?: string) {
  if (!value) return true
  return OPTION_SETS[kind].some(([option]) => option === value)
}

function optionLabel(kind: keyof typeof OPTION_SETS, value?: string) {
  return OPTION_SETS[kind].find(([option]) => option === value)?.[1] ?? value ?? ''
}

function needsObservation(kind: keyof typeof OPTION_SETS, value?: string) {
  if (!value) return false
  if (value === 'depende' || value === 'depende_del_establecimiento' || value === 'por_definir')
    return true
  if (kind === 'exceptions' && value === 'si') return true
  if (kind === 'special' && value === 'parcialmente') return true
  return false
}

function observationHelp(kind: keyof typeof OPTION_SETS, value?: string) {
  if (kind === 'applicability' && value === 'si') {
    return 'Confirme el alcance o indique si existe alguna condición especial.'
  }
  if (value === 'depende' || value === 'depende_del_establecimiento') {
    return 'Indique de qué establecimiento, programa o condición depende.'
  }
  if (kind === 'exceptions' && value === 'si') {
    return 'Indique los establecimientos o tipos de establecimiento exceptuados.'
  }
  if (value === 'parcialmente') {
    return 'Indique qué columnas o casos se validan por separado.'
  }
  if (value === 'por_definir') {
    return 'Explique qué información falta confirmar.'
  }
  return ''
}

function formatRows(rows: number[]) {
  if (rows.length === 0) return 'sin filas informadas'
  const sorted = [...rows].sort((a, b) => a - b)
  const isContiguous = sorted.every((row, index) => index === 0 || row === sorted[index - 1] + 1)
  if (isContiguous && sorted.length > 1) return `filas ${sorted[0]} a ${sorted[sorted.length - 1]}`
  if (sorted.length === 1) return `fila ${sorted[0]}`
  return `filas ${sorted.join(', ')}`
}

function listOrFallback(values: string[], fallback: string) {
  const unique = Array.from(new Set(values.filter(Boolean)))
  return unique.length > 0 ? unique.join(', ') : fallback
}

function stateLabel(progress: {
  reviewed: boolean
  answered: number
  total: number
  pendingObservation: number
}): ReviewState {
  if (progress.reviewed) return 'Revisado'
  if (progress.pendingObservation > 0) return 'Con observaciones'
  if (progress.answered === 0) return 'Sin iniciar'
  return 'En revisión'
}

function isLegacyA01SectionA(sheet: string, section: string) {
  return sheet.toUpperCase() === 'A01' && section.toUpperCase() === 'A'
}

function isA01SectionB(sheet: string, section: string) {
  return sheet.toUpperCase() === 'A01' && section.toUpperCase() === 'B'
}

function rangeForGroup(columnGroups: ColumnGroup[] | undefined, type: string, fallback: string) {
  const group = columnGroups?.find((item) => item.type === type)
  if (!group) return fallback
  return `${group.start_column}:${group.end_column}`
}

function buildA01BGeneralQuestions(columnGroups: ColumnGroup[] | undefined) {
  const ageRange = rangeForGroup(columnGroups, 'age_range', 'F:AJ')
  const complementaryRange = rangeForGroup(columnGroups, 'complementary', 'AK:AN')

  return A01_B_GENERAL_QUESTIONS.map((question) => ({
    ...question,
    text: question.text.replace('F:AJ', ageRange).replace('AK:AN', complementaryRange),
  }))
}

function buildDynamicGeneralQuestions(
  sheet: string,
  section: string,
  columnGroups: ColumnGroup[] | undefined
) {
  const mainGroup = columnGroups?.find((item) => item.type === 'main_rule')
  const total = mainGroup?.total ?? 'C'
  const components = mainGroup?.components?.length ? mainGroup.components : ['D', 'E']
  const labels = mainGroup?.labels ?? {}
  const totalLabel = labels[total] || 'Ambos sexos'
  const componentText = components
    .map((column) => `${labels[column] || column} (${column})`)
    .join(' más ')
  const ageGroup = columnGroups?.find((item) => item.type === 'age_range')
  const ageRange = rangeForGroup(columnGroups, 'age_range', 'rango etario')
  const complementaryRange = rangeForGroup(
    columnGroups,
    'complementary',
    'variables complementarias'
  )
  const prefix = `${sheet.toLowerCase()}_${section.toLowerCase()}`

  return [
    {
      id: `general_${prefix}_main_rule`,
      text: `¿El ${totalLabel} (${total}) debe ser igual a ${componentText}?`,
      kind: 'applicability' as const,
      required: true,
    },
    ...(ageGroup
      ? [
          {
            id: `general_${prefix}_age_empty`,
            text: `¿Las columnas del rango etario ${ageRange} deben completarse obligatoriamente con 0 cuando no existan datos o pueden quedar vacías?`,
            kind: 'empty' as const,
            required: true,
          },
          {
            id: `general_${prefix}_age_total`,
            text: '¿La suma de los rangos etarios debe cuadrar con algún total de la fila?',
            kind: 'applicability' as const,
            required: true,
          },
        ]
      : []),
    {
      id: `general_${prefix}_complementary`,
      text: `¿Las variables complementarias ${complementaryRange} se validan de forma independiente?`,
      kind: 'special' as const,
      required: true,
    },
    {
      id: `general_${prefix}_exceptions`,
      text: '¿Existen excepciones por establecimiento o tipo de establecimiento?',
      kind: 'exceptions' as const,
      required: true,
    },
  ]
}

function patternDisplayName(pattern: PatternGroup) {
  return pattern.nombre.toLowerCase().startsWith('patrón ')
    ? pattern.nombre
    : `Patrón ${pattern.id}: ${pattern.nombre}`
}

function normalizeFormula(value: string) {
  return value
    .toUpperCase()
    .replace(/\s+/g, '')
    .replace(/=/g, '')
    .replace(/\{FILA\}/g, '{fila}')
}

function sortedCsv(values: string[] | undefined) {
  return [...(values ?? [])].filter(Boolean).sort().join(',')
}

function patternFormulaSignature(pattern: PatternGroup) {
  if (pattern.formula_templates) {
    return Object.entries(pattern.formula_templates)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([total, formula]) => `${total}:${normalizeFormula(formula)}`)
      .join('|')
  }

  return normalizeFormula(pattern.formula_template ?? '')
}

function patternEditabilitySignature(pattern: PatternGroup) {
  return pattern.rows
    .map((row) => {
      const editables = row.editables
        .map((cell) => cell.letra)
        .sort()
        .join(',')
      const bloqueadas = row.bloqueadas
        .map((cell) => cell.letra)
        .sort()
        .join(',')
      const especiales = row.especiales
        .map((cell) => `${cell.letra}:${cell.editable ? 'E' : 'B'}`)
        .sort()
        .join(',')
      return `${editables}|${bloqueadas}|${especiales}`
    })
    .join('~')
}

function patternEquivalenceSignature(pattern: PatternGroup) {
  return [
    patternFormulaSignature(pattern),
    sortedCsv(pattern.total_columns?.length ? pattern.total_columns : [pattern.columna_total]),
    sortedCsv(pattern.origin_columns?.length ? pattern.origin_columns : pattern.columnas_origen),
    pattern.source ?? '',
    String(pattern.cantidad_filas),
    patternEditabilitySignature(pattern),
  ].join('::')
}

function patternHasSpecialRows(pattern: PatternGroup) {
  return pattern.rows.some((row) => row.objetivo === 'special' || row.cobertura === 'special')
}

function isSuggestionEligible(pattern: PatternGroup) {
  return (
    pattern.source !== 'structure_inferred' &&
    pattern.source !== undefined &&
    !patternHasSpecialRows(pattern)
  )
}

function suggestedResponseFor(definition: QuestionDefinition, pattern: PatternGroup) {
  if (!SUGGESTED_SUFFIXES.has(definition.idSuffix)) return ''
  if (definition.idSuffix === 'special') {
    const origins = new Set(
      pattern.origin_columns?.length ? pattern.origin_columns : pattern.columnas_origen
    )
    const hasIndependentSpecial = pattern.rows.some((row) =>
      row.especiales.some((cell) => !origins.has(cell.letra))
    )
    return hasIndependentSpecial ? SUGGESTED_RESPONSES.special : ''
  }

  return SUGGESTED_RESPONSES[definition.idSuffix] ?? ''
}

function suggestedGeneralResponse(id: string) {
  if (id.includes('complementary')) return 'si'
  if (id.includes('exceptions')) return 'no'
  return ''
}

function patternFormulaConfirmationId(patternId: number) {
  return patternQuestionId(patternId, FORMULA_CONFIRMATION_ID_SUFFIX)
}

function hasCompleteCellEvidence(pattern: PatternGroup, warnings: string[] | undefined) {
  if (pattern.source !== 'cell_data') return false
  if ((warnings ?? []).length > 0) return false
  if (!pattern.total_columns?.length && !pattern.columna_total) return false
  if (!pattern.origin_columns?.length && !pattern.columnas_origen?.length) return false
  if (!pattern.rows.length) return false

  return pattern.rows.every(
    (row) =>
      row.functional_rules?.length &&
      row.functional_rules.every(
        (rule) =>
          rule.total_column &&
          rule.origin_columns?.length &&
          (rule.formula_exacta || rule.formula_template)
      )
  )
}

function questionsForPattern(
  pattern: PatternGroup,
  definitions: QuestionDefinition[],
  warnings: string[] | undefined
) {
  if (!hasCompleteCellEvidence(pattern, warnings)) return definitions
  return definitions.filter((definition) => definition.idSuffix !== 'logic_correct')
}

function sectionHasCompleteCellEvidence(patterns: PatternGroup[], warnings: string[] | undefined) {
  return (
    patterns.length > 0 && patterns.every((pattern) => hasCompleteCellEvidence(pattern, warnings))
  )
}

function filterGeneralQuestionsForConfirmedEvidence<T extends { id: string }>(
  questions: T[],
  confirmed: boolean
) {
  if (!confirmed) return questions
  return questions.filter(
    (question) =>
      !question.id.includes('main_rule') &&
      !question.id.includes('age_total') &&
      !question.id.includes('aggregated') &&
      !question.id.includes('sex_columns')
  )
}

function labelForColumn(column: string, columnGroups: ColumnGroup[] | undefined) {
  for (const group of columnGroups ?? []) {
    const found = group.columns.find((item) => item.letter === column)
    if (found?.label) return found.label
    for (const subgroup of group.subgroups ?? []) {
      const foundInSubgroup = subgroup.columns.find((item) => item.letter === column)
      if (foundInSubgroup?.label) return foundInSubgroup.label
    }
  }
  return column
}

function confirmedRuleLabel(
  rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number],
  columnGroups: ColumnGroup[] | undefined
) {
  const totalLabel = labelForColumn(rule.total_column, columnGroups)
  const origins = rule.origin_columns
    .map((column) => labelForColumn(column, columnGroups))
    .join(' + ')
  return `${totalLabel} = ${origins}`
}

function isMenAgeRule(rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number]) {
  return rule.total_column === 'C' && (rule.origin_columns?.length ?? 0) > 2
}

function isWomenAgeRule(
  rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number]
) {
  return rule.total_column === 'D' && (rule.origin_columns?.length ?? 0) > 2
}

function conciseConfirmedRuleLabel(
  rule: NonNullable<PatternGroup['rows'][number]['functional_rules']>[number],
  columnGroups: ColumnGroup[] | undefined
) {
  if (rule.total_column === 'B' && rule.origin_columns.join(',') === 'C,D')
    return 'Ambos Sexos = Hombres + Mujeres'
  if (rule.total_column === 'C' && rule.origin_columns.join(',') === 'D,E')
    return 'Ambos Sexos = Hombres + Mujeres'
  if (isMenAgeRule(rule)) return 'Total Hombres = suma de rangos etarios de Hombres'
  if (isWomenAgeRule(rule)) return 'Total Mujeres = suma de rangos etarios de Mujeres'
  return confirmedRuleLabel(rule, columnGroups)
}

function groupRange(columnGroups: ColumnGroup[] | undefined, type: string) {
  const group = columnGroups?.find((item) => item.type === type)
  return group ? `${group.start_column}:${group.end_column}` : ''
}

export default function FunctionalQuestionsPanel({
  sheet,
  section,
  patterns,
  columnGroups,
  initialQuestions,
  warnings = [],
  readOnly = false,
}: Props) {
  const user = useAuthStore((state) => state.user)
  const queryClient = useQueryClient()
  const userName = user?.name ?? user?.email ?? 'Usuario funcional'
  const patternQuestions = isLegacyA01SectionA(sheet, section)
    ? PATTERN_QUESTIONS
    : A01_B_PATTERN_QUESTIONS
  const baseGeneralQuestions = isLegacyA01SectionA(sheet, section)
    ? GENERAL_QUESTIONS
    : isA01SectionB(sheet, section)
      ? buildA01BGeneralQuestions(columnGroups)
      : buildDynamicGeneralQuestions(sheet, section, columnGroups)
  const generalQuestions = filterGeneralQuestionsForConfirmedEvidence(
    baseGeneralQuestions,
    sectionHasCompleteCellEvidence(patterns, warnings)
  )

  const initialByKey = useMemo(() => {
    const map = new Map<string, CalibrationQuestion>()
    for (const question of initialQuestions) {
      map.set(questionKey(question), question)
    }
    return map
  }, [initialQuestions])

  const initialState = useMemo(() => {
    const responses: Record<string, string> = {}
    const observations: Record<string, string> = {}
    const reviewedPatterns: Record<number, boolean> = {}
    let sectionReviewed = false

    for (const question of initialQuestions) {
      const key = questionKey(question)
      if (question.response) responses[key] = question.response
      if (question.observation) observations[key] = question.observation
      if (question.pattern_id && question.review_status === 'reviewed')
        reviewedPatterns[question.pattern_id] = true
      if (question.id === 'section_review' && question.review_status === 'section_reviewed')
        sectionReviewed = true
    }

    return { responses, observations, reviewedPatterns, sectionReviewed }
  }, [initialQuestions])

  const [responseChanges, setResponseChanges] = useState<Record<string, string>>({})
  const [observationChanges, setObservationChanges] = useState<Record<string, string>>({})
  const [reviewedPatternChanges, setReviewedPatternChanges] = useState<Record<number, boolean>>({})
  const [sectionReviewedChange, setSectionReviewedChange] = useState(false)

  const responses = useMemo(
    () => ({ ...initialState.responses, ...responseChanges }),
    [initialState.responses, responseChanges]
  )
  const observations = useMemo(
    () => ({ ...initialState.observations, ...observationChanges }),
    [initialState.observations, observationChanges]
  )
  const reviewedPatterns = useMemo(
    () => ({ ...initialState.reviewedPatterns, ...reviewedPatternChanges }),
    [initialState.reviewedPatterns, reviewedPatternChanges]
  )
  const sectionReviewed = initialState.sectionReviewed || sectionReviewedChange

  const buildQuestion = (
    key: string,
    base: CalibrationQuestion,
    overrides: Partial<CalibrationQuestion> = {}
  ): CalibrationQuestion => {
    const existing = initialByKey.get(key)
    const response = responses[key] ?? existing?.response ?? ''
    return {
      ...existing,
      ...base,
      ...overrides,
      response,
      observation: observations[key] ?? existing?.observation ?? '',
      status: response.trim() ? (overrides.status ?? existing?.status ?? 'answered') : 'pending',
    }
  }

  const buildAllQuestions = (extra: CalibrationQuestion[] = []) => {
    const existingByKey = new Map<string, CalibrationQuestion>()
    for (const question of initialQuestions) {
      existingByKey.set(questionKey(question), question)
    }

    const next: CalibrationQuestion[] = []

    for (const pattern of patterns) {
      const isReviewed = reviewedPatterns[pattern.id]
      const definitions = questionsForPattern(pattern, patternQuestions, warnings)

      if (hasCompleteCellEvidence(pattern, warnings)) {
        const id = patternFormulaConfirmationId(pattern.id)
        const question = buildQuestion(
          id,
          {
            id,
            row: null,
            pattern_id: pattern.id,
            pattern_key: `pattern_${pattern.id}`,
            type: 'pattern_confirmation',
            question: 'Confirmacion del patron detectado desde el XLSM',
            suggests_block: false,
          },
          {
            review_status: isReviewed
              ? 'reviewed'
              : (existingByKey.get(id)?.review_status ?? 'pending'),
            reviewed_at: isReviewed ? existingByKey.get(id)?.reviewed_at : undefined,
            reviewed_by: isReviewed ? existingByKey.get(id)?.reviewed_by : undefined,
          }
        )
        next.push(question)
        existingByKey.delete(id)
      }

      for (const definition of definitions) {
        const id = patternQuestionId(pattern.id, definition.idSuffix)
        const question = buildQuestion(
          id,
          {
            id,
            row: null,
            pattern_id: pattern.id,
            pattern_key: `pattern_${pattern.id}`,
            type: 'pattern_question',
            question: patternQuestionText(pattern, definition),
            suggests_block: false,
          },
          {
            review_status: isReviewed
              ? 'reviewed'
              : (existingByKey.get(id)?.review_status ?? 'pending'),
            reviewed_at: isReviewed ? existingByKey.get(id)?.reviewed_at : undefined,
            reviewed_by: isReviewed ? existingByKey.get(id)?.reviewed_by : undefined,
          }
        )
        next.push(question)
        existingByKey.delete(id)
      }
    }

    for (const definition of generalQuestions) {
      const question = buildQuestion(definition.id, {
        id: definition.id,
        row: null,
        type: 'general_question',
        question: definition.text,
        suggests_block: false,
      })
      next.push(question)
      existingByKey.delete(definition.id)
    }

    for (const item of extra) {
      next.push(item)
      existingByKey.delete(questionKey(item))
    }

    return [...next, ...existingByKey.values()]
  }

  const saveMutation = useMutation({
    mutationFn: (questions: CalibrationQuestion[]) =>
      calibrationService.savePatternQuestions(sheet, section, questions),
    onSuccess: () => {
      toast.success('Respuestas guardadas correctamente')
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', sheet, section] })
    },
    onError: () => toast.error('Error al guardar respuestas'),
  })

  const patternProgress = useMemo(() => {
    return patterns.map((pattern) => {
      const hasConfirmedEvidence = hasCompleteCellEvidence(pattern, warnings)
      const definitions = questionsForPattern(pattern, patternQuestions, warnings)
      const rows = definitions.map((definition) => {
        const id = patternQuestionId(pattern.id, definition.idSuffix)
        const response = responses[id] ?? initialByKey.get(id)?.response ?? ''
        const observation = observations[id] ?? initialByKey.get(id)?.observation ?? ''
        const legacy = !isKnownOption(definition.kind, response)
        const observationMissing =
          needsObservation(definition.kind, response) && !observation.trim()
        return { definition, id, response, observation, legacy, observationMissing }
      })

      const confirmationId = patternFormulaConfirmationId(pattern.id)
      const confirmationResponse =
        responses[confirmationId] ?? initialByKey.get(confirmationId)?.response ?? ''
      const confirmationObservation =
        observations[confirmationId] ?? initialByKey.get(confirmationId)?.observation ?? ''
      const confirmationObservationMissing =
        hasConfirmedEvidence &&
        confirmationResponse === 'reported' &&
        !confirmationObservation.trim()
      const confirmationAnswered = hasConfirmedEvidence && confirmationResponse.trim() ? 1 : 0
      const total = rows.length + (hasConfirmedEvidence ? 1 : 0)
      const answered = rows.filter((row) => row.response.trim()).length + confirmationAnswered
      const pending = total - answered
      const pendingObservation =
        rows.filter((row) => row.observationMissing).length +
        (confirmationObservationMissing ? 1 : 0)
      const reviewed =
        reviewedPatterns[pattern.id] ||
        rows.some((row) => initialByKey.get(row.id)?.review_status === 'reviewed')
      const percent = total === 0 ? 0 : Math.round((answered / total) * 100)

      return {
        pattern,
        rows,
        hasConfirmedEvidence,
        confirmationId,
        confirmationResponse,
        confirmationObservation,
        confirmationObservationMissing,
        total,
        answered,
        pending,
        pendingObservation,
        observations:
          rows.filter((row) => row.observation.trim()).length +
          (confirmationObservation.trim() ? 1 : 0),
        reviewed,
        percent,
        canReview:
          pattern.source !== 'structure_inferred' && answered === total && pendingObservation === 0,
        state: stateLabel({ reviewed, answered, total, pendingObservation }),
      }
    })
  }, [
    initialByKey,
    observations,
    patternQuestions,
    patterns,
    responses,
    reviewedPatterns,
    warnings,
  ])

  const sectionSummary = useMemo(() => {
    const totalQuestions = patternProgress.reduce((acc, item) => acc + item.total, 0)
    const answered = patternProgress.reduce((acc, item) => acc + item.answered, 0)
    const reviewed = patternProgress.filter((item) => item.reviewed).length
    const observationsCount = patternProgress.reduce((acc, item) => acc + item.observations, 0)
    const hasClarifications = initialQuestions.some(
      (question) => question.status === 'clarification'
    )
    const allReviewed = patternProgress.length > 0 && reviewed === patternProgress.length
    const state: ReviewState = hasClarifications
      ? 'Con observaciones'
      : allReviewed
        ? 'Revisado'
        : answered === 0
          ? 'Sin iniciar'
          : 'En revisión'

    return {
      totalPatterns: patternProgress.length,
      reviewedPatterns: reviewed,
      pendingPatterns: patternProgress.length - reviewed,
      totalQuestions,
      answeredQuestions: answered,
      pendingQuestions: totalQuestions - answered,
      observations: observationsCount,
      allReviewed,
      state,
    }
  }, [initialQuestions, patternProgress])

  const handleSave = () => {
    saveMutation.mutate(buildAllQuestions())
  }

  const fillPatternSuggestions = (pattern: PatternGroup) => {
    if (!isSuggestionEligible(pattern)) {
      toast.warning('Este patrón no tiene evidencia suficiente para sugerencias automáticas.')
      return
    }

    let changed = 0
    setResponseChanges((prev) => {
      const next = { ...prev }
      for (const definition of questionsForPattern(pattern, patternQuestions, warnings)) {
        const suggested = suggestedResponseFor(definition, pattern)
        if (!suggested) continue
        const id = patternQuestionId(pattern.id, definition.idSuffix)
        const current = responses[id] ?? initialByKey.get(id)?.response ?? ''
        if (current.trim()) continue
        next[id] = suggested
        changed++
      }
      return next
    })

    toast.info(
      changed > 0
        ? 'Sugerencias aplicadas en pantalla. Revise antes de guardar.'
        : 'No hay campos vacíos para completar.'
    )
  }

  const fillSectionSuggestions = () => {
    let changed = 0
    setResponseChanges((prev) => {
      const next = { ...prev }
      for (const pattern of patterns) {
        if (!isSuggestionEligible(pattern)) continue
        for (const definition of questionsForPattern(pattern, patternQuestions, warnings)) {
          const suggested = suggestedResponseFor(definition, pattern)
          if (!suggested) continue
          const id = patternQuestionId(pattern.id, definition.idSuffix)
          const current = responses[id] ?? initialByKey.get(id)?.response ?? ''
          if (current.trim()) continue
          next[id] = suggested
          changed++
        }
      }
      if (sectionHasCompleteCellEvidence(patterns, warnings)) {
        for (const question of generalQuestions) {
          const suggested = suggestedGeneralResponse(question.id)
          if (!suggested) continue
          const current = responses[question.id] ?? initialByKey.get(question.id)?.response ?? ''
          if (current.trim()) continue
          next[question.id] = suggested
          changed++
        }
      }
      return next
    })

    toast.info(
      changed > 0
        ? 'Sugerencias de la sección aplicadas en pantalla. Revise antes de guardar.'
        : 'No hay campos elegibles para completar.'
    )
  }

  const applyToEquivalentPatterns = (sourcePattern: PatternGroup) => {
    if (!isSuggestionEligible(sourcePattern)) {
      toast.warning('Este patrón no es elegible para herencia.')
      return
    }

    const sourceSignature = patternEquivalenceSignature(sourcePattern)
    const targets = patterns.filter(
      (pattern) =>
        pattern.id !== sourcePattern.id &&
        isSuggestionEligible(pattern) &&
        patternEquivalenceSignature(pattern) === sourceSignature
    )

    if (targets.length === 0) {
      toast.info('No se encontraron patrones equivalentes.')
      return
    }

    const formula = patternFormulaSignature(sourcePattern)
    const totals = sortedCsv(
      sourcePattern.total_columns?.length
        ? sourcePattern.total_columns
        : [sourcePattern.columna_total]
    )
    const origins = sortedCsv(
      sourcePattern.origin_columns?.length
        ? sourcePattern.origin_columns
        : sourcePattern.columnas_origen
    )
    const detail = targets
      .map((pattern) => `Patrón ${pattern.id}: filas ${pattern.filas.join(', ')}`)
      .join('\n')
    const confirmed = window.confirm(
      `Se aplicará a ${targets.length} patrón(es) equivalente(s).\n` +
        `Hoja: ${sheet}\nSección: ${section}\nFórmula: ${formula}\nDestino: ${totals}\nOrigen: ${origins}\n${detail}\n\nNo se sobrescribirán respuestas existentes.`
    )

    if (!confirmed) return

    let changed = 0
    setResponseChanges((prev) => {
      const next = { ...prev }
      for (const target of targets) {
        for (const definition of questionsForPattern(sourcePattern, patternQuestions, warnings)) {
          if (
            !questionsForPattern(target, patternQuestions, warnings).some(
              (candidate) => candidate.idSuffix === definition.idSuffix
            )
          )
            continue
          const sourceId = patternQuestionId(sourcePattern.id, definition.idSuffix)
          const targetId = patternQuestionId(target.id, definition.idSuffix)
          const sourceValue = responses[sourceId] ?? initialByKey.get(sourceId)?.response ?? ''
          const targetValue = responses[targetId] ?? initialByKey.get(targetId)?.response ?? ''
          if (!sourceValue.trim() || targetValue.trim()) continue
          if (!isKnownOption(definition.kind, sourceValue)) continue
          if (definition.idSuffix === 'exceptions' && sourceValue !== 'no') continue
          next[targetId] = sourceValue
          changed++
        }
      }
      return next
    })

    toast.info(
      changed > 0
        ? 'Respuestas copiadas en pantalla. Revise antes de guardar.'
        : 'Los patrones equivalentes ya tenían respuestas.'
    )
  }

  const markPatternReviewed = (patternId: number) => {
    const reviewedAt = new Date().toISOString()
    setReviewedPatternChanges((prev) => ({ ...prev, [patternId]: true }))

    const questions = buildAllQuestions().map((question) => {
      if (question.pattern_id !== patternId) return question
      return {
        ...question,
        review_status: 'reviewed' as const,
        reviewed_at: question.reviewed_at ?? reviewedAt,
        reviewed_by: question.reviewed_by ?? userName,
      }
    })

    saveMutation.mutate(questions)
  }

  const markSectionReviewed = () => {
    const reviewedAt = new Date().toISOString()
    setSectionReviewedChange(true)
    saveMutation.mutate(
      buildAllQuestions([
        {
          ...(initialByKey.get('section_review') ?? {}),
          id: 'section_review',
          row: null,
          type: 'section_review',
          question: `Sección ${section} revisada funcionalmente`,
          suggests_block: false,
          response: 'revisada',
          observation:
            observations.section_review ?? initialByKey.get('section_review')?.observation ?? '',
          review_status: 'section_reviewed',
          reviewed_at: reviewedAt,
          reviewed_by: userName,
          status: 'answered',
        },
      ])
    )
  }

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
      <div className="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-gray-900">
            Preguntas funcionales por patrón
          </h3>
          <p className="text-sm text-gray-500 mt-1">Definiciones pendientes para Estadística</p>
        </div>
        <div className="flex items-center gap-2">
          {readOnly && (
            <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700">
              <Lock className="h-3.5 w-3.5" />
              Solo lectura
            </span>
          )}
          {!readOnly && (
            <button
              onClick={handleSave}
              disabled={saveMutation.isPending}
              className="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
            >
              <Save className="h-4 w-4" />
              {saveMutation.isPending ? 'Guardando...' : 'Guardar respuestas'}
            </button>
          )}
        </div>
      </div>

      <div className="border-b border-gray-200 bg-gray-50 px-6 py-4">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-7">
          <SummaryStat label="Estado" value={sectionReviewed ? 'Revisada' : sectionSummary.state} />
          <SummaryStat label="Patrones" value={String(sectionSummary.totalPatterns)} />
          <SummaryStat label="Revisados" value={String(sectionSummary.reviewedPatterns)} />
          <SummaryStat label="Pendientes" value={String(sectionSummary.pendingPatterns)} />
          <SummaryStat
            label="Respondidas"
            value={`${sectionSummary.answeredQuestions}/${sectionSummary.totalQuestions}`}
          />
          <SummaryStat
            label="Preguntas pendientes"
            value={String(sectionSummary.pendingQuestions)}
          />
          <SummaryStat label="Observaciones" value={String(sectionSummary.observations)} />
        </div>
        {!readOnly && (
          <div className="mt-4 flex flex-wrap justify-end gap-2">
            <button
              onClick={fillSectionSuggestions}
              disabled={saveMutation.isPending}
              className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-white px-3 py-2 text-sm font-medium text-indigo-700 transition-colors hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Aplicar configuración estándar REM
            </button>
            <button
              onClick={markSectionReviewed}
              disabled={!sectionSummary.allReviewed || sectionReviewed || saveMutation.isPending}
              className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <CheckCircle2 className="h-4 w-4" />
              {sectionReviewed ? 'Sección revisada' : 'Marcar sección como revisada'}
            </button>
          </div>
        )}
      </div>

      <div className="p-6 space-y-6">
        <div className="border border-gray-200 rounded-lg p-4">
          <h4 className="text-sm font-semibold text-gray-700 mb-3">Preguntas generales</h4>
          <div className="space-y-3">
            {generalQuestions.map((question) => {
              const value = responses[question.id] ?? initialByKey.get(question.id)?.response ?? ''
              const observation =
                observations[question.id] ?? initialByKey.get(question.id)?.observation ?? ''
              return (
                <QuestionRow
                  key={question.id}
                  label={question.text}
                  kind={question.kind}
                  value={value}
                  observation={observation}
                  legacy={!isKnownOption(question.kind, value)}
                  observationMissing={needsObservation(question.kind, value) && !observation.trim()}
                  helperText={observationHelp(question.kind, value)}
                  readOnly={readOnly}
                  onValueChange={(nextValue) =>
                    setResponseChanges((prev) => ({ ...prev, [question.id]: nextValue }))
                  }
                  onObservationChange={(nextValue) =>
                    setObservationChanges((prev) => ({ ...prev, [question.id]: nextValue }))
                  }
                />
              )
            })}
          </div>
        </div>

        {patternProgress.map(
          ({
            pattern,
            rows,
            total,
            answered,
            pending,
            percent,
            observations: observationCount,
            state,
            canReview,
            reviewed,
            pendingObservation,
            hasConfirmedEvidence,
            confirmationId,
            confirmationResponse,
            confirmationObservation,
            confirmationObservationMissing,
          }) => {
            const humanRows = rows.filter(({ definition }) =>
              HUMAN_DECISION_SUFFIXES.has(definition.idSuffix)
            )
            const suggestedRows = rows.filter(({ definition }) =>
              SUGGESTED_SUFFIXES.has(definition.idSuffix)
            )
            const eligible = isSuggestionEligible(pattern)
            const equivalentCount = patterns.filter(
              (candidate) =>
                candidate.id !== pattern.id &&
                isSuggestionEligible(candidate) &&
                patternEquivalenceSignature(candidate) === patternEquivalenceSignature(pattern)
            ).length

            return (
              <div key={pattern.id} className="border border-gray-200 rounded-lg p-4">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h4 className="text-sm font-semibold text-indigo-700">
                      {patternDisplayName(pattern)}
                    </h4>
                    <p className="mt-1 text-xs text-gray-500">
                      {answered}/{total} preguntas respondidas · {pending} pendientes ·{' '}
                      {observationCount} observaciones
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                      {percent}%
                    </span>
                    <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">
                      {state}
                    </span>
                    {!readOnly && (
                      <>
                        <button
                          onClick={() => fillPatternSuggestions(pattern)}
                          disabled={!eligible || reviewed || saveMutation.isPending}
                          className="rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          Aplicar estándar REM al patrón
                        </button>
                        <button
                          onClick={() => applyToEquivalentPatterns(pattern)}
                          disabled={
                            !eligible || equivalentCount === 0 || reviewed || saveMutation.isPending
                          }
                          className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          Aplicar a patrones equivalentes
                        </button>
                        <button
                          onClick={() => markPatternReviewed(pattern.id)}
                          disabled={!canReview || reviewed || saveMutation.isPending}
                          className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {reviewed ? 'Patrón revisado' : 'Marcar patrón como revisado'}
                        </button>
                      </>
                    )}
                  </div>
                </div>

                <PatternExplanation pattern={pattern} section={section} />

                {pendingObservation > 0 && (
                  <div className="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                    Complete las observaciones requeridas para respuestas que dependen de
                    condiciones, excepciones o definiciones pendientes.
                  </div>
                )}

                <div className="space-y-4">
                  <QuestionGroup title="Decisiones que debe confirmar Estadística">
                    {hasConfirmedEvidence && (
                      <ConfirmedPatternActions
                        pattern={pattern}
                        columnGroups={columnGroups}
                        value={confirmationResponse}
                        observation={confirmationObservation}
                        observationMissing={confirmationObservationMissing}
                        readOnly={readOnly || reviewed}
                        onConfirm={() =>
                          setResponseChanges((prev) => ({ ...prev, [confirmationId]: 'confirmed' }))
                        }
                        onReport={() =>
                          setResponseChanges((prev) => ({ ...prev, [confirmationId]: 'reported' }))
                        }
                        onObservationChange={(value) =>
                          setObservationChanges((prev) => ({ ...prev, [confirmationId]: value }))
                        }
                      />
                    )}
                    {humanRows.map(
                      ({ definition, id, response, observation, legacy, observationMissing }) => (
                        <QuestionRow
                          key={id}
                          label={definition.text}
                          kind={definition.kind}
                          value={response}
                          observation={observation}
                          legacy={legacy}
                          observationMissing={observationMissing}
                          helperText={observationHelp(definition.kind, response)}
                          readOnly={readOnly || reviewed}
                          onValueChange={(value) =>
                            setResponseChanges((prev) => ({ ...prev, [id]: value }))
                          }
                          onObservationChange={(value) =>
                            setObservationChanges((prev) => ({ ...prev, [id]: value }))
                          }
                        />
                      )
                    )}
                  </QuestionGroup>

                  {suggestedRows.length > 0 && (
                    <QuestionGroup
                      title="Configuración sugerida"
                      hint="Sugerencia del sistema — revise antes de guardar."
                    >
                      {suggestedRows.map(
                        ({ definition, id, response, observation, legacy, observationMissing }) => (
                          <QuestionRow
                            key={id}
                            label={definition.text}
                            kind={definition.kind}
                            value={response}
                            observation={observation}
                            legacy={legacy}
                            observationMissing={observationMissing}
                            helperText={
                              response
                                ? observationHelp(definition.kind, response)
                                : suggestedResponseFor(definition, pattern)
                                  ? `Sugerido: ${optionLabel(definition.kind, suggestedResponseFor(definition, pattern))}`
                                  : ''
                            }
                            readOnly={readOnly || reviewed}
                            onValueChange={(value) =>
                              setResponseChanges((prev) => ({ ...prev, [id]: value }))
                            }
                            onObservationChange={(value) =>
                              setObservationChanges((prev) => ({ ...prev, [id]: value }))
                            }
                          />
                        )
                      )}
                    </QuestionGroup>
                  )}
                </div>
              </div>
            )
          }
        )}
      </div>
    </div>
  )
}

function ConfirmedPatternActions({
  pattern,
  columnGroups,
  value,
  observation,
  observationMissing,
  readOnly,
  onConfirm,
  onReport,
  onObservationChange,
}: {
  pattern: PatternGroup
  columnGroups?: ColumnGroup[]
  value: string
  observation: string
  observationMissing: boolean
  readOnly: boolean
  onConfirm: () => void
  onReport: () => void
  onObservationChange: (value: string) => void
}) {
  const rules = pattern.rows[0]?.functional_rules ?? []

  return (
    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">
        Reglas confirmadas desde el XLSM
      </p>
      <div className="mt-2 space-y-1 text-sm text-emerald-950">
        {rules.map((rule) => (
          <p key={`${pattern.id}-${rule.total_column}`}>
            ✓ {conciseConfirmedRuleLabel(rule, columnGroups)}
          </p>
        ))}
        {groupRange(columnGroups, 'age_range') && (
          <p>✓ Rango etario detectado: {groupRange(columnGroups, 'age_range')}</p>
        )}
        {groupRange(columnGroups, 'complementary') && (
          <p>✓ Variables complementarias: {groupRange(columnGroups, 'complementary')}</p>
        )}
      </div>
      <p className="mt-2 text-xs font-medium text-emerald-800">
        Lectura técnica confirmada desde el XLSM
      </p>
      <details className="mt-3 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs">
        <summary className="cursor-pointer font-medium text-emerald-700">
          Ver detalle técnico
        </summary>
        <div className="mt-2 space-y-2">
          {rules.map((rule) => (
            <div
              key={`${pattern.id}-${rule.total_column}-technical`}
              className="rounded-md bg-gray-50 p-2"
            >
              <div>
                <span className="font-medium text-gray-600">Destino:</span>{' '}
                <span className="font-mono">{rule.destination || rule.total_column}</span>
              </div>
              <div>
                <span className="font-medium text-gray-600">Origen:</span>{' '}
                <span className="font-mono">
                  {rule.origin_coordinates?.join(', ') || rule.origin_columns.join(', ')}
                </span>
              </div>
              <div>
                <span className="font-medium text-gray-600">Fórmula:</span>{' '}
                <span className="font-mono">{rule.formula_exacta || rule.formula_template}</span>
              </div>
            </div>
          ))}
        </div>
      </details>
      <div className="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          onClick={onConfirm}
          disabled={readOnly}
          className={`rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
            value === 'confirmed'
              ? 'border-emerald-600 bg-emerald-600 text-white'
              : 'border-emerald-300 bg-white text-emerald-700 hover:bg-emerald-100'
          }`}
        >
          Confirmar lectura del Excel
        </button>
        <button
          type="button"
          onClick={onReport}
          disabled={readOnly}
          className={`rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
            value === 'reported'
              ? 'border-amber-600 bg-amber-600 text-white'
              : 'border-amber-300 bg-white text-amber-700 hover:bg-amber-100'
          }`}
        >
          Reportar problema
        </button>
      </div>
      {value === 'reported' && (
        <div className="mt-3">
          <input
            type="text"
            value={observation}
            onChange={(event) => onObservationChange(event.target.value)}
            disabled={readOnly}
            className="w-full rounded-lg border border-amber-300 px-3 py-2 text-xs focus:border-amber-500 focus:ring-1 focus:ring-amber-500 disabled:bg-gray-100 disabled:text-gray-500"
            placeholder="Describa el problema detectado en la estructura, fórmula u origen."
          />
          {observationMissing && (
            <p className="mt-1 text-[11px] font-medium text-amber-700">
              La observación es obligatoria al reportar un problema.
            </p>
          )}
        </div>
      )}
    </div>
  )
}

function PatternExplanation({ pattern, section }: { pattern: PatternGroup; section: string }) {
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

  return (
    <div className="mb-4 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-xs text-indigo-950">
      <p className="font-medium">
        Este patrón representa las {formatRows(pattern.filas)} de la Sección {section}. Todas
        comparten la misma lógica de cálculo. Las respuestas registradas aquí se aplicarán a todas
        esas filas.
      </p>
      <p className="mt-2 text-indigo-800">
        Está calibrando el comportamiento funcional del patrón completo, no una sola fila.
      </p>
      {pattern.source === 'structure_inferred' && (
        <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-amber-800">
          No existe evidencia de celdas escaneadas para esta sección. Los patrones mostrados son
          preliminares y no pueden certificarse todavía.
        </p>
      )}
      <dl className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
        <PatternFact label="Cantidad de filas" value={String(pattern.cantidad_filas)} />
        <PatternFact label="Fórmula" value={formulaText || 'Sin fórmula informada'} />
        <PatternFact
          label="Totales"
          value={totalColumns.length > 0 ? totalColumns.join(', ') : 'Sin columnas total'}
        />
        <PatternFact
          label="Columnas origen"
          value={originColumns.length > 0 ? originColumns.join(', ') : 'Sin columnas origen'}
        />
        <PatternFact
          label="Conceptos"
          value={listOrFallback(pattern.conceptos, 'Sin conceptos informados')}
        />
        <PatternFact
          label="Profesionales"
          value={listOrFallback(pattern.profesionales, 'Sin profesionales informados')}
        />
        <PatternFact label="Fuente de evidencia" value={pattern.source ?? 'Sin fuente informada'} />
      </dl>
    </div>
  )
}

function QuestionGroup({
  title,
  hint,
  children,
}: {
  title: string
  hint?: string
  children: ReactNode
}) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-3">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-600">{title}</h5>
        {hint && (
          <span className="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-700">
            {hint}
          </span>
        )}
      </div>
      <div className="space-y-3">{children}</div>
    </div>
  )
}

function PatternFact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="font-medium text-indigo-700">{label}</dt>
      <dd className="mt-0.5 text-indigo-950">{value}</dd>
    </div>
  )
}

function SummaryStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white px-3 py-2">
      <div className="text-[11px] font-medium text-gray-500">{label}</div>
      <div className="mt-0.5 text-sm font-semibold text-gray-900">{value}</div>
    </div>
  )
}

function QuestionRow({
  label,
  kind,
  value,
  observation,
  legacy,
  observationMissing,
  helperText,
  readOnly,
  onValueChange,
  onObservationChange,
}: {
  label: string
  kind: keyof typeof OPTION_SETS
  value: string
  observation: string
  legacy: boolean
  observationMissing: boolean
  helperText: string
  readOnly: boolean
  onValueChange: (value: string) => void
  onObservationChange: (value: string) => void
}) {
  return (
    <div className="grid grid-cols-1 gap-3 rounded-lg border border-gray-100 bg-gray-50 p-3 lg:grid-cols-3">
      <div>
        <label className="text-xs font-medium text-gray-700">{label}</label>
        {legacy && value && (
          <div className="mt-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
            Respuesta anterior: {value}
          </div>
        )}
        {observationMissing && (
          <div className="mt-1 text-[11px] font-medium text-amber-700">{helperText}</div>
        )}
        {!observationMissing && helperText && (
          <div className="mt-1 text-[11px] text-gray-500">{helperText}</div>
        )}
      </div>
      <div>
        <select
          value={legacy ? '' : value}
          onChange={(event) => onValueChange(event.target.value)}
          disabled={readOnly}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-100 disabled:text-gray-500"
        >
          <option value="">
            {legacy && value ? 'Mantener respuesta anterior' : 'Seleccione una respuesta'}
          </option>
          {OPTION_SETS[kind].map(([option, optionLabel]) => (
            <option key={option} value={option}>
              {optionLabel}
            </option>
          ))}
        </select>
      </div>
      <div>
        <input
          type="text"
          value={observation}
          onChange={(event) => onObservationChange(event.target.value)}
          disabled={readOnly}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-100 disabled:text-gray-500"
          placeholder="Observación opcional"
        />
      </div>
    </div>
  )
}
