import { apiPrime } from '@/shared/services/apiPrime'

export interface FuncionalCriterion {
  id: number
  year: number
  serie: string
  sheet: string
  section: string
  row_number: number
  concepto: string
  profesional: string
  empty_behavior: string | null
  applies_to_types: string[] | null
  included_health_centers: string[] | null
  excluded_health_centers: string[] | null
  functional_condition: string | null
  justification: string | null
  scope: string
  pattern_id: string | null
  parent_criterion_id: number | null
  establishment_type_code: string | null
  node_code: string | null
  confidence_level: string | null
  acquisition_method: string | null
  sync_status: string | null
  metadata: Record<string, unknown> | null
  versions?: Array<Record<string, unknown>>
  workflow_instance: {
    id: number
    criterion_id: number
    current_state_id: number
    current_state: {
      id: number
      code: string
      name: string
    } | null
  } | null
  created_by: number
}

export interface CriteriaListResponse {
  data: FuncionalCriterion[]
  current_page: number
  per_page: number
  total: number
}

export const criteriaService = {
  list: async (params: Record<string, string | number> = {}): Promise<CriteriaListResponse> => {
    const { data } = await apiPrime.get('/api/functional-criteria', { params })
    return data
  },

  get: async (id: number): Promise<FuncionalCriterion> => {
    const { data } = await apiPrime.get(`/api/functional-criteria/${id}`)
    return data
  },

  update: async (id: number, payload: Record<string, unknown>): Promise<FuncionalCriterion> => {
    const { data } = await apiPrime.put(`/api/functional-criteria/${id}`, payload)
    return data
  },

  getWorkflow: async (id: number): Promise<Record<string, unknown>> => {
    const { data } = await apiPrime.get(`/api/functional-criteria/${id}/workflow`)
    return data
  },

  transition: async (
    id: number,
    transitionId: number,
    comments = ''
  ): Promise<Record<string, unknown>> => {
    const { data } = await apiPrime.post(`/api/functional-criteria/${id}/workflow/transition`, {
      transition_id: transitionId,
      comments,
    })
    return data
  },

  evaluate: async (id: number): Promise<Record<string, unknown>> => {
    const { data } = await apiPrime.get(`/api/functional-criteria/evaluate/${id}`)
    return data
  },
}
