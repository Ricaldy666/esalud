import { ShieldCheck } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import TwoFactorSettingsPanel from '@/features/auth/components/TwoFactorSettingsPanel'

export default function SecurityPage() {
  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Seguridad de la cuenta"
        description="Administra la verificación en dos pasos de tu cuenta."
        icon={ShieldCheck}
      />
      <TwoFactorSettingsPanel />
    </div>
  )
}
