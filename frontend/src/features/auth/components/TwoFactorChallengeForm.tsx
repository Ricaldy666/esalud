import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { KeyRound, ShieldCheck } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import { useTwoFactorChallenge } from '../hooks/useTwoFactorChallenge'
import { useLogout } from '../hooks/useLogout'
import { useAuthStore } from '@/app/store/authStore'

interface ChallengeFormValues {
  code: string
}

/**
 * Pantalla que se muestra tras una password valida cuando la cuenta tiene
 * 2FA activo -- nunca se llega aqui sin haber pasado el paso de password
 * antes (ver authStore.twoFactorPending). Fase Seguridad 2 (2026-08-31).
 */
export default function TwoFactorChallengeForm() {
  const navigate = useNavigate()
  const challenge = useTwoFactorChallenge()
  const logout = useLogout()
  const clearAuth = useAuthStore((s) => s.clearAuth)
  const [useRecoveryCode, setUseRecoveryCode] = useState(false)

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ChallengeFormValues>()

  const onSubmit = async (data: ChallengeFormValues) => {
    try {
      const result = await challenge.mutateAsync(data.code)
      if (result.status === 'authenticated') {
        navigate('/')
      }
    } catch (err: unknown) {
      const apiError = err as {
        response?: { status?: number; data?: { errors?: Record<string, string[]> } }
      }

      if (apiError.response?.status === 419) {
        // El desafio expiro y el backend ya cerro la sesion -- volver a la
        // pantalla de password, no tiene sentido seguir reintentando aqui.
        clearAuth()
        return
      }

      if (apiError.response?.status === 429) {
        setError('code', {
          message: 'Demasiados intentos. Espere unos minutos antes de volver a intentar.',
        })
        return
      }

      const fieldErrors = apiError?.response?.data?.errors
      if (fieldErrors?.code) {
        setError('code', { message: fieldErrors.code[0] })
      } else {
        setError('code', { message: 'No se pudo verificar el código.' })
      }
    } finally {
      reset({ code: '' })
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="flex items-center gap-2 text-slate-700">
        <ShieldCheck className="size-5 text-blue-600" />
        <p className="text-sm">
          {useRecoveryCode
            ? 'Ingrese uno de sus códigos de recuperación.'
            : 'Ingrese el código de 6 dígitos de su aplicación autenticadora.'}
        </p>
      </div>

      <div className="space-y-2">
        <Label htmlFor="code" className="text-slate-700">
          {useRecoveryCode ? 'Código de recuperación' : 'Código de verificación'}
        </Label>
        <div className="relative">
          <KeyRound className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-slate-400" />
          <Input
            id="code"
            type="text"
            inputMode={useRecoveryCode ? 'text' : 'numeric'}
            autoComplete="one-time-code"
            autoFocus
            placeholder={useRecoveryCode ? 'xxxx-xxxx' : '000000'}
            className="border-slate-300 bg-white pl-9 text-slate-900 tracking-widest placeholder:text-slate-400 placeholder:tracking-normal focus-visible:border-blue-500 focus-visible:ring-blue-500/30"
            {...register('code', { required: 'El código es obligatorio' })}
          />
        </div>
        {errors.code && <p className="text-xs text-red-500">{errors.code.message}</p>}
      </div>

      <Button
        type="submit"
        disabled={isSubmitting}
        className="w-full bg-blue-600 text-white hover:bg-blue-700"
      >
        {isSubmitting ? 'Verificando...' : 'Verificar'}
      </Button>

      <button
        type="button"
        onClick={() => setUseRecoveryCode((v) => !v)}
        className="w-full text-center text-sm text-blue-600 hover:text-blue-700 hover:underline"
      >
        {useRecoveryCode
          ? 'Usar código de mi aplicación autenticadora'
          : '¿Perdió su dispositivo? Use un código de recuperación'}
      </button>

      <button
        type="button"
        onClick={() => logout.mutate()}
        className="w-full text-center text-xs text-slate-400 hover:text-slate-600"
      >
        Cancelar y volver a iniciar sesión
      </button>
    </form>
  )
}
