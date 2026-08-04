export interface StructureStats {
  total_forms: number
  total_sections: number
  total_fields: number
  total_rules: number
  sum_equals: number
  required_and_le_parent: number
}

export interface StructureField {
  codigo: string
  nombre: string
  letra?: string
  reglaDetectada?:
    | {
        tipo: string
        [key: string]: unknown
      }
    | string
    | null
  [key: string]: unknown
}

export interface StructureSection {
  codigo: string
  titulo: string
  filaHeader?: number | null
  filaInicioDatos?: number | null
  filaFinDatos?: number | null
  campos: number
  reglas: number
  fields: StructureField[]
}

export interface StructureForm {
  sheetName: string
  sections: StructureSection[]
}

export interface Structure {
  id: number
  anio: number
  serie: string
  version_number: number
  status: string
  hash_estructura?: string
  hash_short?: string
  metadata?: Record<string, unknown> | null
  source_filename?: string
  notes?: string | null
  stats?: StructureStats
  forms_detail?: StructureForm[]
  template?: {
    id: number
    year: number
    rem_type: string
    version: string
    is_active: boolean
  } | null
  upload?: {
    id: number
    filename: string
    period: string
  } | null
  rules_count?: number
  rules?: Array<{
    id: number
    rule_key: string
    rule_type: string
    name: string
    severity: string
    status: string
  }>
  uploads_count?: number
  version_history?: Array<{
    id: number
    version_number: number
    status: string
    created_at: string
  }>
  created_at: string
  updated_at?: string
}

export interface StructureFilters {
  page?: number
  per_page?: number
  anio?: number
  serie?: string
  status?: string
  version?: number
  search?: string
}
