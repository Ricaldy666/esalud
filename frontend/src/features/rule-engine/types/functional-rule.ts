export interface FunctionalRule {
  rule_key: string
  empty_behavior: string | null
  applies_to_types: string[]
  included_health_centers: string[]
  excluded_health_centers: string[]
  functional_condition: string
  justification: string
  informed_by: string
  informed_at: string | null
  status: 'pending' | 'propuesta' | 'aprobada' | 'rechazada'
  updated_by: string
  updated_at: string | null
}

export type EmptyBehavior =
  | 'puede_quedar_vacio'
  | 'debe_registrar_cero'
  | 'debe_tener_al_menos_un_valor'
  | 'no_aplica'
  | 'pendiente_definicion'

export type FunctionalStatus = 'pending' | 'propuesta' | 'aprobada' | 'rechazada'

export const EMPTY_BEHAVIOR_LABELS: Record<string, string> = {
  puede_quedar_vacio: 'Puede quedar vacía',
  debe_registrar_cero: 'Debe registrar 0',
  debe_tener_al_menos_un_valor: 'Debe tener al menos un valor',
  no_aplica: 'No aplica',
  pendiente_definicion: 'Pendiente de definir',
}

export const EMPTY_BEHAVIOR_DESCRIPTIONS: Record<string, { tooltip: string; example: string }> = {
  debe_registrar_cero: {
    tooltip:
      'El establecimiento debe anotar explícitamente un 0 cuando no tenga casos en el período.',
    example:
      'Si un CESFAM no realizó controles preconcepcional este mes, debe registrar 0 en la fila.',
  },
  puede_quedar_vacio: {
    tooltip:
      'Se permite que la fila no tenga datos registrados. El motor no marcará error si está vacía.',
    example:
      'Si la fila corresponde a una prestación que no aplica a este tipo de establecimiento.',
  },
  no_aplica: {
    tooltip:
      'La fila no corresponde a la realidad del establecimiento. Se omitirá completamente de la validación.',
    example: 'Una fila de "partos" en un establecimiento que no realiza atención de partos.',
  },
  pendiente_definicion: {
    tooltip:
      'No tienes la información suficiente ahora. La fila queda pendiente para una próxima revisión.',
    example: 'Necesitas consultar con la referente técnica antes de decidir.',
  },
}

export const APPLIES_TO_OPTIONS = [
  { value: 'todos', label: 'Todos los establecimientos' },
  { value: 'CESFAM', label: 'Solo CESFAM' },
  { value: 'SAPU', label: 'Solo SAPU' },
  { value: 'SAR', label: 'Solo SAR' },
  { value: 'CECOF', label: 'Solo CECOF' },
  { value: 'Posta', label: 'Solo Posta' },
  { value: 'especificos', label: 'Establecimientos específicos' },
]

export const FUNCTIONAL_STATUS_LABELS: Record<string, string> = {
  pending: 'Sin revisar',
  propuesta: 'Propuesta',
  aprobada: 'Aprobada',
  rechazada: 'Rechazada',
}

export const FUNCTIONAL_STATUS_DESCRIPTIONS: Record<string, string> = {
  pending: 'La fila aún no ha sido revisada. Usa la regla técnica por defecto.',
  propuesta: 'Guardada como borrador. No afecta al motor de validación hasta que sea aprobada.',
  aprobada: 'Decisión final. El motor de validación aplicará este criterio.',
  rechazada: 'La propuesta no corresponde. La fila vuelve a comportamiento por defecto.',
}

export interface SectionInfo {
  codigo: string
  titulo: string
  fila_inicio: number | null
  fila_fin: number | null
  total_campos: number
  reglas_detectadas_estructura: number
  columnas: SectionColumn[]
}

export interface SectionColumn {
  letra: string
  label: string
  es_total: boolean
  es_control_oculto: boolean
}

export interface SectionResponse {
  section: SectionInfo
  reglas: import('./certification').CertificationCard[]
  stats: SectionStats
  funcional_rules: Record<string, FunctionalRule>
  filters: {
    fila: string
    estado: string
    search: string
  }
}

export interface SectionStats {
  total: number
  pendientes: number
  certificadas: number
  requiere_revision: number
  horizontales: number
  verticales: number
  obligatoriedad: number
}
