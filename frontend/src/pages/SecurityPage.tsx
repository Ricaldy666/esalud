import TwoFactorSettingsPanel from '@/features/auth/components/TwoFactorSettingsPanel'

export default function SecurityPage() {
  return (
    <div className="space-y-4 p-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Seguridad de la cuenta</h1>
        <p className="text-sm text-slate-500">
          Administra la verificación en dos pasos de tu cuenta.
        </p>
      </div>
      <TwoFactorSettingsPanel />
    </div>
  )
}
