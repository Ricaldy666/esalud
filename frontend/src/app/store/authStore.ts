import { create } from 'zustand'
import type { User } from '@/features/auth/types'

interface AuthState {
  user: User | null
  isAuthenticated: boolean
  /**
   * true cuando la password ya fue validada pero el usuario tiene 2FA
   * activo y aun no supero el desafio TOTP/recovery-code -- mientras esto
   * sea true, isAuthenticated permanece false (nunca se marca autenticado
   * solo con la password). Fase Seguridad 2 (2026-08-31).
   */
  twoFactorPending: boolean
  setUser: (user: User | null) => void
  setTwoFactorPending: (pending: boolean) => void
  clearAuth: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  isAuthenticated: false,
  twoFactorPending: false,
  setUser: (user) => set({ user, isAuthenticated: !!user, twoFactorPending: false }),
  setTwoFactorPending: (pending) =>
    set({ twoFactorPending: pending, isAuthenticated: false, user: null }),
  clearAuth: () => set({ user: null, isAuthenticated: false, twoFactorPending: false }),
}))
