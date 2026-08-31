import { useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { ShieldCheck, ShieldOff, KeyRound, Loader2, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import { Badge } from '@/shared/components/ui/badge'
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
} from '@/shared/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/shared/components/ui/dialog'
import { authService } from '../services/authService'
import { twoFactorService } from '../services/twoFactorService'
import { useAuthStore } from '@/app/store/authStore'

type Step =
  | 'idle'
  | 'enroll-password'
  | 'enroll-qr'
  | 'enroll-recovery-codes'
  | 'disable-password'
  | 'regenerate-password'
  | 'regenerate-codes'

/**
 * Administracion basica de 2FA de la propia cuenta -- activar (password →
 * QR → confirmar primer código → códigos de recuperación), desactivar, y
 * regenerar códigos de recuperación. Fase Seguridad 2 (2026-08-31). Solo la
 * interfaz funcional necesaria, sin rediseño de estilo general.
 */
export default function TwoFactorSettingsPanel() {
  const user = useAuthStore((s) => s.user)
  const setUser = useAuthStore((s) => s.setUser)

  const [step, setStep] = useState<Step>('idle')
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [secret, setSecret] = useState('')
  const [otpauthUri, setOtpauthUri] = useState('')
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [savedCodesAck, setSavedCodesAck] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!user) return null

  const closeAndReset = () => {
    setStep('idle')
    setPassword('')
    setCode('')
    setSecret('')
    setOtpauthUri('')
    setRecoveryCodes([])
    setSavedCodesAck(false)
    setError(null)
  }

  const refreshUser = async () => {
    const result = await authService.me()
    if (result.status === 'authenticated') {
      setUser(result.user)
    }
  }

  const errorMessage = (err: unknown, fallback: string): string => {
    const apiError = err as {
      response?: { data?: { errors?: Record<string, string[]>; message?: string } }
    }
    const errors = apiError?.response?.data?.errors
    if (errors) {
      const first = Object.values(errors)[0]?.[0]
      if (first) return first
    }
    return apiError?.response?.data?.message ?? fallback
  }

  const startEnroll = async () => {
    setBusy(true)
    setError(null)
    try {
      const { secret, otpauth_uri } = await twoFactorService.enroll(password)
      setSecret(secret)
      setOtpauthUri(otpauth_uri)
      setStep('enroll-qr')
    } catch (err) {
      setError(errorMessage(err, 'No se pudo iniciar el enrolamiento.'))
    } finally {
      setBusy(false)
    }
  }

  const confirmEnroll = async () => {
    setBusy(true)
    setError(null)
    try {
      const { recovery_codes } = await twoFactorService.confirm(code)
      setRecoveryCodes(recovery_codes)
      setStep('enroll-recovery-codes')
      await refreshUser()
      toast.success('Doble factor activado correctamente.')
    } catch (err) {
      setError(errorMessage(err, 'Código incorrecto.'))
    } finally {
      setBusy(false)
    }
  }

  const disable = async () => {
    setBusy(true)
    setError(null)
    try {
      await twoFactorService.disable(password)
      await refreshUser()
      toast.success('Doble factor desactivado.')
      closeAndReset()
    } catch (err) {
      setError(errorMessage(err, 'No se pudo desactivar el doble factor.'))
    } finally {
      setBusy(false)
    }
  }

  const regenerate = async () => {
    setBusy(true)
    setError(null)
    try {
      const { recovery_codes } = await twoFactorService.regenerateRecoveryCodes(password)
      setRecoveryCodes(recovery_codes)
      setStep('regenerate-codes')
    } catch (err) {
      setError(errorMessage(err, 'No se pudo regenerar los códigos.'))
    } finally {
      setBusy(false)
    }
  }

  const copyCodes = () => {
    navigator.clipboard?.writeText(recoveryCodes.join('\n')).then(
      () => toast.success('Códigos copiados al portapapeles.'),
      () => toast.error('No se pudo copiar automáticamente. Cópielos manualmente.')
    )
  }

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            {user.two_factor_enabled ? (
              <ShieldCheck className="size-5 text-emerald-600" />
            ) : (
              <ShieldOff className="size-5 text-slate-400" />
            )}
            Verificación en dos pasos (2FA)
            <Badge variant={user.two_factor_enabled ? 'default' : 'secondary'}>
              {user.two_factor_enabled ? 'Activo' : 'Inactivo'}
            </Badge>
          </CardTitle>
          <CardDescription>
            Protege tu cuenta exigiendo un código de tu aplicación autenticadora además de tu
            contraseña.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          {!user.two_factor_enabled && (
            <Button onClick={() => setStep('enroll-password')}>Activar doble factor</Button>
          )}
          {user.two_factor_enabled && (
            <>
              <Button variant="outline" onClick={() => setStep('regenerate-password')}>
                Regenerar códigos de recuperación
              </Button>
              <Button variant="destructive" onClick={() => setStep('disable-password')}>
                Desactivar doble factor
              </Button>
            </>
          )}
        </CardContent>
      </Card>

      {/* Paso 1 (enrolar/desactivar/regenerar): confirmar password actual */}
      <Dialog
        open={
          step === 'enroll-password' ||
          step === 'disable-password' ||
          step === 'regenerate-password'
        }
        onOpenChange={(open) => !open && closeAndReset()}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Confirme su contraseña</DialogTitle>
            <DialogDescription>
              Por seguridad, ingrese su contraseña actual para continuar.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="current_password">Contraseña actual</Label>
            <Input
              id="current_password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoFocus
            />
            {error && <p className="text-xs text-red-500">{error}</p>}
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={closeAndReset}>
              Cancelar
            </Button>
            <Button
              disabled={busy || password.length === 0}
              onClick={() => {
                if (step === 'enroll-password') return startEnroll()
                if (step === 'disable-password') return disable()
                return regenerate()
              }}
            >
              {busy && <Loader2 className="mr-2 size-4 animate-spin" />}
              Continuar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Paso 2 (solo enrolamiento): QR + confirmar primer código */}
      <Dialog open={step === 'enroll-qr'} onOpenChange={(open) => !open && closeAndReset()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Escanee el código QR</DialogTitle>
            <DialogDescription>
              Use Google Authenticator, Authy u otra aplicación compatible con TOTP. Si no puede
              escanear el código, ingrese la clave manualmente.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col items-center gap-3">
            <div className="rounded-lg border border-slate-200 bg-white p-3">
              <QRCodeSVG value={otpauthUri} size={180} />
            </div>
            <code className="rounded bg-slate-100 px-2 py-1 text-xs break-all">{secret}</code>
          </div>
          <div className="space-y-2">
            <Label htmlFor="confirm_code">Código de 6 dígitos</Label>
            <Input
              id="confirm_code"
              type="text"
              inputMode="numeric"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="000000"
            />
            {error && <p className="text-xs text-red-500">{error}</p>}
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={closeAndReset}>
              Cancelar
            </Button>
            <Button disabled={busy || code.length === 0} onClick={confirmEnroll}>
              {busy && <Loader2 className="mr-2 size-4 animate-spin" />}
              Confirmar y activar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Paso 3 (enrolamiento) / unico paso (regeneración): mostrar códigos una vez */}
      <Dialog
        open={step === 'enroll-recovery-codes' || step === 'regenerate-codes'}
        onOpenChange={(open) => {
          if (!open && savedCodesAck) closeAndReset()
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <KeyRound className="size-5" />
              Códigos de recuperación
            </DialogTitle>
            <DialogDescription>
              Guárdelos en un lugar seguro. Cada uno puede usarse una sola vez si pierde acceso a su
              aplicación autenticadora. No volverán a mostrarse.
            </DialogDescription>
          </DialogHeader>
          <div className="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 font-mono text-sm">
            {recoveryCodes.map((c) => (
              <span key={c}>{c}</span>
            ))}
          </div>
          <Button type="button" variant="outline" size="sm" onClick={copyCodes} className="w-fit">
            <Copy className="mr-1 size-3.5" /> Copiar
          </Button>
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              checked={savedCodesAck}
              onChange={(e) => setSavedCodesAck(e.target.checked)}
            />
            He guardado estos códigos en un lugar seguro.
          </label>
          <DialogFooter>
            <Button disabled={!savedCodesAck} onClick={closeAndReset}>
              Listo
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
