import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import { useHealthCenters } from '@/features/health-centers'
import type { User } from '../types'
import type { UserCreateFormData, UserUpdateFormData } from '../schemas'
import type { FieldError } from 'react-hook-form'
import { userCreateSchema, userUpdateSchema } from '../schemas'

interface UserFormProps {
  user?: User | null
  roles: string[]
  onSubmit: (data: UserCreateFormData | UserUpdateFormData) => void
  onCancel: () => void
  loading?: boolean
}

export function UserForm({ user, roles, onSubmit, onCancel, loading }: UserFormProps) {
  const isEditing = !!user
  const schema = isEditing ? userUpdateSchema : userCreateSchema

  const { data: centersData } = useHealthCenters()
  const centers = centersData?.data ?? []

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      name: user?.name ?? '',
      rut: user?.rut ?? '',
      email: user?.email ?? '',
      password: '',
      password_confirmation: '',
      health_center_id: user?.health_center_id ?? null,
      role: user?.roles?.[0] ?? '',
      is_active: user?.is_active ?? true,
    },
  })

  // eslint-disable-next-line react-hooks/incompatible-library
  const watchedRole = watch('role')
  const watchedHealthCenterId = watch('health_center_id')
  const watchedIsActive = watch('is_active')

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="name">Nombre</Label>
          <Input id="name" {...register('name')} placeholder="Nombre completo" />
          {errors.name && <p className="text-xs text-red-500">{errors.name.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="rut">RUT</Label>
          <Input id="rut" {...register('rut')} placeholder="12345678-5" />
          {errors.rut && <p className="text-xs text-red-500">{errors.rut.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" {...register('email')} placeholder="correo@ejemplo.cl" />
          {errors.email && <p className="text-xs text-red-500">{errors.email.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="role">Rol</Label>
          <Select
            value={watchedRole}
            onValueChange={(value: string | null) => value && setValue('role', value)}
          >
            <SelectTrigger>
              <SelectValue placeholder="Seleccionar rol" />
            </SelectTrigger>
            <SelectContent>
              {roles.map((role) => (
                <SelectItem key={role} value={role}>
                  {role}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.role && (
            <p className="text-xs text-red-500">{(errors.role as FieldError).message}</p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="password">
            {isEditing ? 'Nueva contraseña (opcional)' : 'Contraseña'}
          </Label>
          <Input id="password" type="password" {...register('password')} />
          {errors.password && <p className="text-xs text-red-500">{errors.password.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="password_confirmation">Confirmar contraseña</Label>
          <Input
            id="password_confirmation"
            type="password"
            {...register('password_confirmation')}
          />
          {errors.password_confirmation && (
            <p className="text-xs text-red-500">{errors.password_confirmation.message}</p>
          )}
        </div>

        <div className="space-y-2">
          <Label htmlFor="health_center_id">Centro de Salud</Label>
          <Select
            value={String(watchedHealthCenterId ?? '')}
            onValueChange={(value: string | null) =>
              setValue('health_center_id', value && value !== 'none' ? Number(value) : null)
            }
          >
            <SelectTrigger>
              <SelectValue placeholder="Sin centro" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="none">Sin centro</SelectItem>
              {centers.map((center) => (
                <SelectItem key={center.id} value={String(center.id)}>
                  {center.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label htmlFor="is_active">Estado</Label>
          <Select
            value={watchedIsActive ? 'active' : 'inactive'}
            onValueChange={(value: string | null) => setValue('is_active', value === 'active')}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="active">Activo</SelectItem>
              <SelectItem value="inactive">Inactivo</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={loading}>
          Cancelar
        </Button>
        <Button type="submit" disabled={loading}>
          {loading ? 'Guardando...' : isEditing ? 'Actualizar' : 'Crear Usuario'}
        </Button>
      </div>
    </form>
  )
}
