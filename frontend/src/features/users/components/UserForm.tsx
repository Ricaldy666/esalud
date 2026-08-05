import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Building2, Check, Eye, EyeOff, Loader2, Shield } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import { DialogFooter } from '@/shared/components/ui/dialog'
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

// Los tokens semanticos de shadcn (bg-primary, border-input, bg-popover, etc.)
// no generan CSS en este proyecto -- src/styles/index.css no define un bloque
// @theme. Se usan clases Tailwind explicitas en todo este formulario en su
// lugar (ver diagnostico entregado al usuario).
const INPUT_CLASS =
  'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_TRIGGER_CLASS =
  'w-full border-slate-300 bg-white text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const LABEL_CLASS = 'text-slate-700'

function RequiredMark() {
  return (
    <span className="text-red-500" aria-hidden="true">
      *
    </span>
  )
}

export function UserForm({ user, roles, onSubmit, onCancel, loading }: UserFormProps) {
  const isEditing = !!user
  const schema = isEditing ? userUpdateSchema : userCreateSchema

  const { data: centersData } = useHealthCenters()
  const centers = centersData?.data ?? []

  const [showPassword, setShowPassword] = useState(false)
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false)

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
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-8">
      <div className="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="name" className={LABEL_CLASS}>
            Nombre completo <RequiredMark />
          </Label>
          <Input
            id="name"
            {...register('name')}
            placeholder="Ej: Juan Pérez González"
            className={INPUT_CLASS}
          />
          {errors.name && <p className="text-xs text-red-500">{errors.name.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="rut" className={LABEL_CLASS}>
            RUT <RequiredMark />
          </Label>
          <Input
            id="rut"
            {...register('rut')}
            placeholder="Ej: 12345678-5"
            className={INPUT_CLASS}
          />
          {errors.rut && <p className="text-xs text-red-500">{errors.rut.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="email" className={LABEL_CLASS}>
            Correo electrónico <RequiredMark />
          </Label>
          <Input
            id="email"
            type="email"
            {...register('email')}
            placeholder="Ej: nombre.apellido@cormudesi.cl"
            className={INPUT_CLASS}
          />
          {errors.email && <p className="text-xs text-red-500">{errors.email.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="role" className={LABEL_CLASS}>
            Rol del usuario <RequiredMark />
          </Label>
          <Select
            value={watchedRole}
            onValueChange={(value: string | null) => value && setValue('role', value)}
          >
            <SelectTrigger className={SELECT_TRIGGER_CLASS}>
              <Shield className="size-4 text-slate-400" />
              <SelectValue placeholder="Seleccionar rol" />
            </SelectTrigger>
            <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
              {roles.map((role) => (
                <SelectItem key={role} value={role} className={SELECT_ITEM_CLASS}>
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
          <Label htmlFor="password" className={LABEL_CLASS}>
            {isEditing ? (
              'Nueva contraseña (opcional)'
            ) : (
              <>
                Contraseña <RequiredMark />
              </>
            )}
          </Label>
          <div className="relative">
            <Input
              id="password"
              type={showPassword ? 'text' : 'password'}
              {...register('password')}
              placeholder="Mínimo 8 caracteres"
              className={`${INPUT_CLASS} pr-9`}
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              tabIndex={-1}
              title={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-slate-400 hover:text-slate-600"
            >
              {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
          </div>
          {errors.password && <p className="text-xs text-red-500">{errors.password.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="password_confirmation" className={LABEL_CLASS}>
            Confirmar contraseña {!isEditing && <RequiredMark />}
          </Label>
          <div className="relative">
            <Input
              id="password_confirmation"
              type={showPasswordConfirmation ? 'text' : 'password'}
              {...register('password_confirmation')}
              placeholder="Repita la contraseña"
              className={`${INPUT_CLASS} pr-9`}
            />
            <button
              type="button"
              onClick={() => setShowPasswordConfirmation((v) => !v)}
              tabIndex={-1}
              title={showPasswordConfirmation ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-slate-400 hover:text-slate-600"
            >
              {showPasswordConfirmation ? (
                <EyeOff className="size-4" />
              ) : (
                <Eye className="size-4" />
              )}
            </button>
          </div>
          {errors.password_confirmation && (
            <p className="text-xs text-red-500">{errors.password_confirmation.message}</p>
          )}
        </div>

        <div className="space-y-2 sm:col-span-2">
          <Label htmlFor="health_center_id" className={LABEL_CLASS}>
            Centro de Salud
          </Label>
          <Select
            value={String(watchedHealthCenterId ?? '')}
            onValueChange={(value: string | null) =>
              setValue('health_center_id', value && value !== 'none' ? Number(value) : null)
            }
          >
            <SelectTrigger className={SELECT_TRIGGER_CLASS}>
              <Building2 className="size-4 text-slate-400" />
              <SelectValue placeholder="Sin centro específico / Toda la red APS" />
            </SelectTrigger>
            <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
              <SelectItem value="none" className={SELECT_ITEM_CLASS}>
                Sin centro específico / Toda la red APS
              </SelectItem>
              {centers.map((center) => (
                <SelectItem key={center.id} value={String(center.id)} className={SELECT_ITEM_CLASS}>
                  {center.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-slate-500">
            Para usuarios que trabajan a nivel de toda la red APS.
          </p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="is_active" className={LABEL_CLASS}>
            Estado de la cuenta
          </Label>
          <div className="flex h-8 items-center gap-3">
            <button
              id="is_active"
              type="button"
              role="switch"
              aria-checked={watchedIsActive}
              onClick={() => setValue('is_active', !watchedIsActive)}
              className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                watchedIsActive ? 'bg-emerald-500' : 'bg-slate-300'
              }`}
            >
              <span
                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform ${
                  watchedIsActive ? 'translate-x-5' : 'translate-x-0'
                }`}
              />
            </button>
            <span
              className={`text-sm leading-none font-medium ${
                watchedIsActive ? 'text-emerald-600' : 'text-slate-500'
              }`}
            >
              {watchedIsActive ? 'Activo' : 'Inactivo'}
            </span>
          </div>
        </div>
      </div>

      <DialogFooter className="border-slate-100 bg-slate-50">
        <Button
          type="button"
          variant="outline"
          onClick={onCancel}
          disabled={loading}
          className="border-slate-300 bg-white text-slate-700 hover:bg-slate-50 hover:text-slate-900"
        >
          Cancelar
        </Button>
        <Button
          type="submit"
          disabled={loading}
          className="gap-1.5 bg-blue-600 text-white hover:bg-blue-700"
        >
          {loading ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
          {loading ? 'Guardando...' : isEditing ? 'Actualizar' : 'Crear Usuario'}
        </Button>
      </DialogFooter>
    </form>
  )
}
