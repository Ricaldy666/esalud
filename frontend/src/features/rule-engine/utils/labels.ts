export const RULE_TYPE_LABELS: Record<string, string> = {
  sum_equals: 'La suma debe ser igual al Total',
  required_and_le_parent: 'Debe ingresar un valor válido',
  functional_rule: 'Validación funcional',
}

export const ERROR_TYPE_LABELS: Record<string, { label: string; short: string }> = {
  tecnico: { label: 'Validación técnica', short: 'Técnico' },
  funcional: { label: 'Validación funcional', short: 'Funcional' },
}

export function getErrorTypeLabel(type: string | null | undefined): string {
  return ERROR_TYPE_LABELS[type ?? '']?.label ?? type ?? '—'
}

export function getErrorTypeShort(type: string | null | undefined): string {
  return ERROR_TYPE_LABELS[type ?? '']?.short ?? type ?? '—'
}

export function getRuleTypeLabel(type: string | null | undefined): string {
  return RULE_TYPE_LABELS[type ?? ''] ?? type ?? '—'
}

export const SEVERITY_LABELS: Record<string, string> = {
  error: 'Error',
  warning: 'Advertencia',
  info: 'Informativo',
}

export function getSeverityLabel(severity: string | null | undefined): string {
  return SEVERITY_LABELS[severity ?? ''] ?? severity ?? '—'
}

export const STATUS_LABELS: Record<string, string> = {
  passed: 'Correctas',
  failed: 'Con observaciones',
  skipped: 'No aplica',
  active: 'Activo',
  inactive: 'Inactivo',
  deprecated: 'Deprecado',
}

export function getStatusLabel(status: string | null | undefined): string {
  return STATUS_LABELS[status ?? ''] ?? status ?? '—'
}

export const TRIGGER_LABELS: Record<string, string> = {
  cli: 'Línea de comandos',
  job: 'Tarea programada',
  manual: 'Manual',
}

export function getTriggerLabel(trigger: string | null | undefined): string {
  return TRIGGER_LABELS[trigger ?? ''] ?? trigger ?? '—'
}

export const BINDABLE_TYPE_LABELS: Record<string, string> = {
  structure: 'Estructura',
  serie: 'Serie',
  global: 'Global',
}

export function getBindableTypeLabel(type: string | null | undefined): string {
  return BINDABLE_TYPE_LABELS[type ?? ''] ?? type ?? '—'
}

export const SOURCE_LABELS: Record<string, string> = {
  excel_formula: 'Importada automáticamente desde estructura REM',
  manual: 'Creada manualmente',
}

export function getSourceLabel(source: string | null | undefined): string {
  return SOURCE_LABELS[source ?? ''] ?? source ?? '—'
}

export const STRUCTURE_STATUS_LABELS: Record<string, string> = {
  active: 'Activa',
  approved: 'Aprobada',
  draft: 'Borrador',
  superseded: 'Reemplazada',
}

export function getStructureStatusLabel(status: string | null | undefined): string {
  return STRUCTURE_STATUS_LABELS[status ?? ''] ?? status ?? '—'
}

export const STRUCTURE_STATUS_STYLES: Record<string, string> = {
  active: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  approved: 'bg-blue-100 text-blue-700 border-blue-200',
  draft: 'bg-slate-100 text-slate-600 border-slate-200',
  superseded: 'bg-amber-100 text-amber-700 border-amber-200',
}

export function getStructureStatusStyle(status: string | null | undefined): string {
  return STRUCTURE_STATUS_STYLES[status ?? ''] ?? 'bg-slate-100 text-slate-600 border-slate-200'
}

/**
 * Estado agregado de progreso de calibracion (hoja o total) -- ver
 * CalibrationApplicabilityStatus/backend SectionCalibrationMatrixService::buildStructureCalibrationSummary().
 * Union deliberadamente distinta de STRUCTURE_STATUS_LABELS: son dos
 * dominios distintos (estado de publicacion de una estructura vs. estado
 * de avance de calibracion de una hoja/seccion), no deben compartir mapa.
 */
export type CalibrationProgressStatus = 'completada' | 'en_revision' | 'pendiente'

export const CALIBRATION_PROGRESS_LABELS: Record<CalibrationProgressStatus, string> = {
  completada: 'Completada',
  en_revision: 'En revisión',
  pendiente: 'Pendiente',
}

export function getCalibrationProgressLabel(status: string | null | undefined): string {
  return CALIBRATION_PROGRESS_LABELS[status as CalibrationProgressStatus] ?? status ?? '—'
}

export const CALIBRATION_PROGRESS_STYLES: Record<CalibrationProgressStatus, string> = {
  completada: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  en_revision: 'bg-indigo-100 text-indigo-700 border-indigo-200',
  pendiente: 'bg-slate-100 text-slate-600 border-slate-200',
}

export function getCalibrationProgressStyle(status: string | null | undefined): string {
  return (
    CALIBRATION_PROGRESS_STYLES[status as CalibrationProgressStatus] ??
    'bg-slate-100 text-slate-600 border-slate-200'
  )
}

export const COMPARISON_STATUS_LABELS: Record<string, string> = {
  match: 'Coincide',
  diff: 'Diferencia',
}

export function getComparisonStatusLabel(status: string | null | undefined): string {
  return COMPARISON_STATUS_LABELS[status ?? ''] ?? status ?? '—'
}

/**
 * Etiqueta/estilo de "hoja no utilizada" (ver RemSheetUsageStatusService,
 * CalibrationNoUtilizadaSheet). Deliberadamente FUERA de
 * CalibrationProgressStatus/CALIBRATION_PROGRESS_LABELS: no es un estado de
 * avance de calibración (nunca Pendiente/En revisión/Completada), es una
 * determinación de negocio de Estadística APS de que la hoja no se usa. No
 * debe mezclarse visualmente con esos estados.
 */
export const NO_UTILIZADA_LABEL = 'No utilizada'

export const NO_UTILIZADA_STYLE = 'bg-slate-100 text-slate-500 border-slate-200 border-dashed'

export const MODE_LABELS: Record<string, { label: string; description: string }> = {
  disabled: { label: 'Deshabilitado', description: 'El motor no ejecuta validaciones' },
  parallel: {
    label: 'Más rápido',
    description: 'Ejecuta en paralelo sin afectar el proceso actual',
  },
  parallel_write: {
    label: 'Más rápido + guardar resultados',
    description: 'Ejecuta en paralelo y guarda resultados en la base de datos',
  },
  replace: {
    label: 'Modo diagnóstico',
    description:
      'Reemplaza completamente la validación legacy. Solo habilitar si hay total coincidencia',
  },
}

export const LOG_MODE_LABELS: Record<string, string> = {
  off: 'Básico',
  diff: 'Completo',
  all: 'Diagnóstico',
}

export const HELP_TEXT: Record<string, string> = {
  'rule-engine': 'Componente encargado de validar automáticamente la información del REM.',
  rules: 'Reglas de consistencia que definen cómo deben validarse los datos del REM.',
  'rule-detail': 'Muestra toda la información de una regla de consistencia y su comportamiento.',
  bindings: 'Indica en qué formularios REM se aplica cada regla de consistencia.',
  'binding-detail': 'Relación entre una regla de consistencia y la estructura REM donde se aplica.',
  structures:
    'Representan la estructura oficial utilizada para construir las reglas de consistencia.',
  'structure-detail':
    'Información detallada de una estructura REM, incluyendo formularios y campos.',
  logs: 'Muestra todas las veces que una regla ha sido ejecutada y su resultado.',
  'log-detail': 'Detalle de una ejecución específica del motor de reglas.',
  execution: 'Registro de cada vez que el motor ejecuta una validación sobre un archivo REM.',
  comparison:
    'Compara los resultados del motor actual con el sistema anterior para verificar su precisión.',
  config: 'Configuración general del comportamiento del motor de reglas.',
  validation: 'Resultado de la validación automática de un archivo REM subido al sistema.',
  'validation-errors':
    'Lista detallada de las observaciones encontradas durante la validación del archivo REM.',
  'validation-summary':
    'Resumen de cumplimiento del archivo REM después de la validación automática.',
  status:
    'Estado actual de la regla: activa (en uso), inactiva (deshabilitada) o deprecada (reemplazada).',
  severity:
    'Nivel de importancia de la regla. Las reglas con nivel Error requieren corrección obligatoria.',
  'rule-type':
    'Tipo de validación que realiza la regla. Define la fórmula o condición que se aplica.',
  'rule-key': 'Código interno único que identifica a cada regla de consistencia en el sistema.',
  campo: 'Variable del formulario REM que está siendo validada por esta regla.',
  'technical-info': 'Información interna del sistema utilizada por el equipo de desarrollo.',
}

export function getHelpText(key: string): string | undefined {
  return HELP_TEXT[key]
}
