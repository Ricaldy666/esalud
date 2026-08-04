export interface Binding {
  id: number
  rule_id: number
  bindable_type: 'structure' | 'serie' | 'global'
  bindable_id: string | null
  serie: string | null
  anio: number | null
  active: boolean
  conditions: Record<string, unknown> | null
  created_at: string
  rule?: {
    id: number
    rule_key: string
    rule_type: string
    name: string
    severity: string
    status: string
  } | null
  structure?: {
    id: number
    anio: number
    serie: string
    version_number: number
    status: string
    source_filename: string | null
  } | null
}

export interface BindingFilters {
  page?: number
  per_page?: number
  rule_id?: number
  bindable_type?: string
  serie?: string
  anio?: number
  active?: boolean
  search?: string
  sort?: string
  order?: 'asc' | 'desc'
}
