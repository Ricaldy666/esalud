import { api, fetchCsrfCookie } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type {
  BulkFunctionalPayload,
  CalibrationFunctionalRule,
  CalibrationMatrixResponse,
  CalibrationQuestion,
  CalibrationQuestionResponse,
  CalibrationRow,
  CalibrationSummaryResponse,
  PatternMatrixResponse,
  RowFunctionalDecisionResponse,
  RowFunctionalRulePayload,
  RowFunctionalVersion,
} from '../types/calibration'

export const calibrationService = {
  getCalibrationSummary: async (): Promise<CalibrationSummaryResponse> => {
    const { data } = await api.get<ApiResponse<CalibrationSummaryResponse>>(
      '/rule-engine/catalog/calibration-summary'
    )
    return data.data
  },

  getMatrix: async (sheet: string, section: string): Promise<CalibrationMatrixResponse> => {
    const { data } = await api.get<ApiResponse<CalibrationMatrixResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/matrix`
    )
    return data.data
  },

  getRowDetail: async (sheet: string, section: string, row: number) => {
    const { data } = await api.get<
      ApiResponse<{ row: CalibrationRow; funcional: CalibrationFunctionalRule | null }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/rows/${row}`
    )
    return data.data
  },

  saveRowFunctionalRule: async (
    sheet: string,
    section: string,
    row: number,
    payload: RowFunctionalRulePayload
  ) => {
    await fetchCsrfCookie()

    const { data } = await api.post<
      ApiResponse<{
        success: boolean
        message: string
        funcional: CalibrationFunctionalRule | null
      }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/rows/${row}/functional-rules`,
      payload
    )
    return data.data
  },

  getQuestions: async (sheet: string, section: string): Promise<CalibrationQuestionResponse> => {
    const { data } = await api.get<ApiResponse<CalibrationQuestionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/questions`
    )
    return data.data
  },

  saveQuestions: async (sheet: string, section: string, questions: CalibrationQuestion[]) => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<{ success: boolean; message: string }>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/questions`,
      { questions }
    )
    return data.data
  },

  bulkFunctional: async (sheet: string, section: string, payload: BulkFunctionalPayload) => {
    await fetchCsrfCookie()

    const { data } = await api.post<
      ApiResponse<{
        success: boolean
        message: string
        total_affected: number
        rows_affected: number[]
      }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/bulk-functional`,
      payload
    )
    return data.data
  },

  getPatterns: async (sheet: string, section: string): Promise<PatternMatrixResponse> => {
    const { data } = await api.get<ApiResponse<PatternMatrixResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/patterns`
    )
    return data.data
  },

  getRowFunctionalDecisions: async (
    sheet: string,
    section: string
  ): Promise<RowFunctionalDecisionResponse> => {
    const { data } = await api.get<ApiResponse<RowFunctionalDecisionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/row-functional-decisions`
    )
    return data.data
  },

  savePatternQuestions: async (
    sheet: string,
    section: string,
    questions: CalibrationQuestion[]
  ) => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<{ success: boolean; message: string }>>(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/pattern-questions`,
      { questions }
    )
    return data.data
  },

  getCalibrationExportUrl: (sheet: string, section: string): string => {
    return `${api.defaults.baseURL}/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/export-calibration`
  },

  getRowFunctionalVersions: async (sheet: string, section: string, row: number) => {
    const { data } = await api.get<
      ApiResponse<{ versions: RowFunctionalVersion[]; total: number }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/rows/${row}/functional-rules/versions`
    )
    return data.data
  },
}
