import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import { useLogin } from '../hooks/useLogin'
import type { LoginCredentials } from '../types'

export default function LoginForm() {
  const navigate = useNavigate()
  const login = useLogin()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginCredentials>()

  const onSubmit = async (data: LoginCredentials) => {
    try {
      await login.mutateAsync(data)
      navigate('/')
    } catch (err: unknown) {
      const apiError = err as {
        response?: { data?: { errors?: Record<string, string[]> } }
      }
      const fieldErrors = apiError?.response?.data?.errors
      if (fieldErrors) {
        for (const [field, messages] of Object.entries(fieldErrors)) {
          setError(field as keyof LoginCredentials, {
            message: messages[0],
          })
        }
      }
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="email">Correo electrónico</Label>
        <Input
          id="email"
          type="email"
          placeholder="admin@esalud.cl"
          {...register('email', {
            required: 'El correo electrónico es obligatorio',
            pattern: {
              value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
              message: 'Ingrese un correo válido',
            },
          })}
        />
        {errors.email && <p className="text-sm text-red-500">{errors.email.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="password">Contraseña</Label>
        <Input
          id="password"
          type="password"
          placeholder="••••••••"
          {...register('password', {
            required: 'La contraseña es obligatoria',
          })}
        />
        {errors.password && <p className="text-sm text-red-500">{errors.password.message}</p>}
      </div>

      <Button type="submit" className="w-full" disabled={isSubmitting}>
        {isSubmitting ? 'Iniciando sesión...' : 'Iniciar sesión'}
      </Button>
    </form>
  )
}
