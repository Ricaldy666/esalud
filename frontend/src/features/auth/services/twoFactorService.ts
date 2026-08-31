import { api } from '@/shared/services/api'
import type { ApiResponse } from '@/shared/types/api'
import type { LoginResult, User } from '../types'

interface EnrollResponse {
  secret: string
  otpauth_uri: string
}

interface RecoveryCodesResponse {
  recovery_codes: string[]
}

type RawAuthPayload = User | { requires_2fa: true }

export const twoFactorService = {
  /** Resuelve el desafio de login (POST /auth/2fa/verify). */
  verify: async (code: string): Promise<LoginResult> => {
    const response = await api.post<ApiResponse<RawAuthPayload>>('/auth/2fa/verify', { code })
    const data = response.data.data
    if ('requires_2fa' in data) {
      return { status: 'requires_2fa' }
    }
    return { status: 'authenticated', user: data }
  },

  enroll: async (currentPassword: string): Promise<EnrollResponse> => {
    const response = await api.post<ApiResponse<EnrollResponse>>('/auth/2fa/enroll', {
      current_password: currentPassword,
    })
    return response.data.data
  },

  confirm: async (code: string): Promise<RecoveryCodesResponse> => {
    const response = await api.post<ApiResponse<RecoveryCodesResponse>>('/auth/2fa/confirm', {
      code,
    })
    return response.data.data
  },

  disable: async (currentPassword: string): Promise<void> => {
    await api.post('/auth/2fa/disable', { current_password: currentPassword })
  },

  regenerateRecoveryCodes: async (currentPassword: string): Promise<RecoveryCodesResponse> => {
    const response = await api.post<ApiResponse<RecoveryCodesResponse>>(
      '/auth/2fa/recovery-codes/regenerate',
      { current_password: currentPassword }
    )
    return response.data.data
  },
}
