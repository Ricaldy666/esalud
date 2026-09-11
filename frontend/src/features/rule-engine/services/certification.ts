import { api } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type {
  CatalogResponse,
  CertificationDetailResponse,
  CertificationUpdateResponse,
  CertificationPayload,
} from '../types/certification'

// BM-2 (2026-09-11): estas rutas cuelgan del mismo grupo generalizado
// backend/routes/api.php `catalog` (prefijo {serie}), por lo tanto exigen
// `serie` igual que calibrationService. Ninguna pantalla que consume este
// servicio (RuleCatalogPage/RuleCatalogTable/RuleCertificationCard/
// RuleCertificationDetailPage) tiene hoy un selector de serie en su ruta
// -- son catalogo/certificacion de reglas, no calibracion -- por lo que
// el `serie` se resuelve como 'A' explicito en el punto de llamada de
// cada pantalla (no aqui, para no reintroducir un default implicito).
// Anadir un selector de serie a estas pantallas es una mejora futura,
// fuera de alcance de BM-2 (que solo generaliza transporte).
export const certificationService = {
  list: async (serie: string, params?: Record<string, string>): Promise<CatalogResponse> => {
    const query = params ? '?' + new URLSearchParams(params).toString() : ''
    const { data } = await api.get<ApiResponse<CatalogResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}${query}`
    )
    return data.data
  },

  get: async (serie: string, ruleKey: string): Promise<CertificationDetailResponse> => {
    const { data } = await api.get<ApiResponse<CertificationDetailResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(ruleKey)}`
    )
    return data.data
  },

  updateStatus: async (
    serie: string,
    ruleKey: string,
    payload: CertificationPayload
  ): Promise<CertificationUpdateResponse> => {
    const { data } = await api.post<ApiResponse<CertificationUpdateResponse>>(
      `/rule-engine/catalog/${encodeURIComponent(serie)}/${encodeURIComponent(ruleKey)}/status`,
      payload
    )
    return data.data
  },

  getExportUrl: (serie: string): string => {
    return `${api.defaults.baseURL}/rule-engine/catalog/${encodeURIComponent(serie)}/export`
  },
}
