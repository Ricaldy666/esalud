import AuthLayout from '@/shared/components/layout/AuthLayout'
import LoginForm from '@/features/auth/components/LoginForm'
import TwoFactorChallengeForm from '@/features/auth/components/TwoFactorChallengeForm'
import { useAuthStore } from '@/app/store/authStore'

export default function LoginPage() {
  const twoFactorPending = useAuthStore((s) => s.twoFactorPending)

  return (
    <AuthLayout
      title={twoFactorPending ? 'Verificación en dos pasos' : 'Bienvenido'}
      description={
        twoFactorPending
          ? 'Ingresa el código de tu aplicación autenticadora'
          : 'Inicia sesión para continuar'
      }
    >
      {twoFactorPending ? <TwoFactorChallengeForm /> : <LoginForm />}
    </AuthLayout>
  )
}
