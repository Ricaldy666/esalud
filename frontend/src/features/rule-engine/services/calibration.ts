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
  HumanReviewAnswer,
  HumanReviewResolutionResponse,
  MigrationPlanResponse,
  MismatchResolutionConfirmResponse,
  MismatchResolutionDetails,
  PatternMatrixResponse,
  QuickRevalidationConfirmResponse,
  RowFunctionalDecisionResponse,
  RowFunctionalRulePayload,
  RowFunctionalVersion,
} from '../types/calibration'

// BM-2 (2026-09-11): todas las funciones de este servicio ahora exigen
// `serie` como primer parametro -- el backend generalizado (routes/api.php,
// CalibrationViewController, CatalogController) ya requiere {serie} en la
// URL para las 22 rutas de este dominio (calibracion/escaneo/matrices/
// certificacion). TypeScript obliga en tiempo de compilacion a que todo
// caller reenvie explicitamente el `series` que el router ya trae
// (useParams() en CalibrationSectionPage) -- ningun caller puede omitirlo
// ni asumir 'A' en silencio (a diferencia del backend, que si mantiene un
// default 'A' por compatibilidad historica en las rutas que no pasan por
// este servicio, ej. comandos artisan).
export const calibrationService = {
  getCalibrationSummary: async (serie: string): Promise<CalibrationSummaryResponse> => {
    const { data } = await api.get<ApiResponse<CalibrationSummaryResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/calibration-summary`
    )
    return data.data
  },

  getMatrix: async (
    serie: string,
    sheet: string,
    section: string
  ): Promise<CalibrationMatrixResponse> => {
    const { data } = await api.get<ApiResponse<CalibrationMatrixResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/matrix`
    )
    return data.data
  },

  getRowDetail: async (serie: string, sheet: string, section: string, row: number) => {
    const { data } = await api.get<
      ApiResponse<{ row: CalibrationRow; funcional: CalibrationFunctionalRule | null }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/rows/${row}`
    )
    return data.data
  },

  saveRowFunctionalRule: async (
    serie: string,
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
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/rows/${row}/functional-rules`,
      payload
    )
    return data.data
  },

  getQuestions: async (
    serie: string,
    sheet: string,
    section: string
  ): Promise<CalibrationQuestionResponse> => {
    const { data } = await api.get<ApiResponse<CalibrationQuestionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/questions`
    )
    return data.data
  },

  saveQuestions: async (
    serie: string,
    sheet: string,
    section: string,
    questions: CalibrationQuestion[]
  ) => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<{ success: boolean; message: string }>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/questions`,
      { questions }
    )
    return data.data
  },

  bulkFunctional: async (
    serie: string,
    sheet: string,
    section: string,
    payload: BulkFunctionalPayload
  ) => {
    await fetchCsrfCookie()

    const { data } = await api.post<
      ApiResponse<{
        success: boolean
        message: string
        total_affected: number
        rows_affected: number[]
      }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/bulk-functional`,
      payload
    )
    return data.data
  },

  getPatterns: async (
    serie: string,
    sheet: string,
    section: string
  ): Promise<PatternMatrixResponse> => {
    const { data } = await api.get<ApiResponse<PatternMatrixResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/patterns`
    )
    return data.data
  },

  getRowFunctionalDecisions: async (
    serie: string,
    sheet: string,
    section: string
  ): Promise<RowFunctionalDecisionResponse> => {
    const { data } = await api.get<ApiResponse<RowFunctionalDecisionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/row-functional-decisions`
    )
    return data.data
  },

  savePatternQuestions: async (
    serie: string,
    sheet: string,
    section: string,
    questions: CalibrationQuestion[]
  ) => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<{ success: boolean; message: string }>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/pattern-questions`,
      { questions }
    )
    return data.data
  },

  getCalibrationExportUrl: (serie: string, sheet: string, section: string): string => {
    return `${api.defaults.baseURL}/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/export-calibration`
  },

  getRowFunctionalVersions: async (serie: string, sheet: string, section: string, row: number) => {
    const { data } = await api.get<
      ApiResponse<{ versions: RowFunctionalVersion[]; total: number }>
    >(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/rows/${row}/functional-rules/versions`
    )
    return data.data
  },

  getMigrationPlan: async (
    serie: string,
    sheet: string,
    section: string
  ): Promise<MigrationPlanResponse> => {
    const { data } = await api.get<ApiResponse<MigrationPlanResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/migration-plan`
    )
    return data.data
  },

  confirmQuickRevalidation: async (
    serie: string,
    sheet: string,
    section: string,
    patternId: number
  ): Promise<QuickRevalidationConfirmResponse> => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<QuickRevalidationConfirmResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/patterns/${patternId}/quick-revalidation`
    )
    return data.data
  },

  getMismatchResolutionDetails: async (
    serie: string,
    sheet: string,
    section: string,
    patternId: number
  ): Promise<MismatchResolutionDetails> => {
    const { data } = await api.get<ApiResponse<MismatchResolutionDetails>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/patterns/${patternId}/mismatch-resolution`
    )
    return data.data
  },

  confirmMismatchResolution: async (
    serie: string,
    sheet: string,
    section: string,
    patternId: number
  ): Promise<MismatchResolutionConfirmResponse> => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<MismatchResolutionConfirmResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/patterns/${patternId}/mismatch-resolution/confirm`
    )
    return data.data
  },

  // Resuelve formalmente un patron MISMATCH clasificado human_review
  // (2026-09-11) -- a diferencia de confirmQuickRevalidation() (sin body,
  // nunca toca la respuesta funcional), este endpoint exige la revision
  // completa: una respuesta nueva por CADA pregunta del patron. Usar
  // exclusivamente para patrones cuyo tag de auditoria (ver
  // getMismatchResolutionDetails) sea category='human_review' -- para
  // 'safe_reconfirm'/'structural_row_exclusion' sigue correspondiendo
  // confirmQuickRevalidation()/confirmMismatchResolution(); la calibracion
  // normal de cualquier otra seccion sigue usando savePatternQuestions().
  confirmHumanReviewResolution: async (
    serie: string,
    sheet: string,
    section: string,
    patternId: number,
    questions: HumanReviewAnswer[]
  ): Promise<HumanReviewResolutionResponse> => {
    await fetchCsrfCookie()

    const { data } = await api.post<ApiResponse<HumanReviewResolutionResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(sheet)}/sections/${encodeURIComponent(section)}/patterns/${patternId}/mismatch-resolution/full-review`,
      { questions }
    )
    return data.data
  },
}
