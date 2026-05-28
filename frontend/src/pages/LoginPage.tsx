import AuthLayout from '@/shared/components/layout/AuthLayout'
import LoginForm from '@/features/auth/components/LoginForm'

export default function LoginPage() {
  return (
    <AuthLayout
      title="Iniciar sesión"
      description="Ingrese sus credenciales para acceder al sistema"
    >
      <LoginForm />
    </AuthLayout>
  )
}
