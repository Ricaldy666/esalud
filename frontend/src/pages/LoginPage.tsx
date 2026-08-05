import AuthLayout from '@/shared/components/layout/AuthLayout'
import LoginForm from '@/features/auth/components/LoginForm'

export default function LoginPage() {
  return (
    <AuthLayout title="Bienvenido" description="Inicia sesión para continuar">
      <LoginForm />
    </AuthLayout>
  )
}
