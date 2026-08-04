export interface CertificationCard {
  rule_key: string
  rule_type: 'sum_equals' | 'required_and_le_parent'
  severity: string | null
  description: string | null
  hoja: string
  seccion: string
  columnas_origen: string[]
  columna_destino: string | null
  rango_filas: string | null
  formula_interpretada: string
  evidencia_xlsm: {
    encontrada: boolean
    titulo_seccion?: string
    label_columna?: string
    es_total?: boolean
    es_control_oculto?: boolean
    regla_detectada?: {
      tipo: string
      columnas_origen: string[]
      columna_destino: string | null
      rango_filas: string | null
    } | null
  } | null
  evidencia_manual_rem: string
  estado: 'Pendiente' | 'Certificada técnicamente' | 'Requiere revisión'
  observaciones: string
  certificado_por: string
  certificado_en: string | null
}

export interface CatalogStats {
  total: number
  pendientes: number
  certificadas: number
  requiere_revision: number
}

export interface CatalogFilters {
  sheet: string
  rule_type: string
  status: string
  search: string
}

export interface CertificationPayload {
  estado: 'Pendiente' | 'Certificada técnicamente' | 'Requiere revisión'
  observaciones?: string
  certificado_por?: string
}

export interface StatusDefinition {
  key: string
  label: string
}

export interface CatalogResponse {
  reglas: CertificationCard[]
  sheets: string[]
  rule_types: string[]
  statuses: StatusDefinition[]
  stats: CatalogStats
  filters: CatalogFilters
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface CertificationDetailResponse {
  regla: CertificationCard
  funcional: import('./functional-rule').FunctionalRule | null
  estructura: {
    hash: string
    version: number
    anio: number
    serie: string
  } | null
}

export interface CertificationUpdateResponse {
  success: boolean
  message: string
  certification: {
    rule_key: string
    estado: string
    observaciones: string
    certificado_por: string
    certificado_en: string
  }
}
