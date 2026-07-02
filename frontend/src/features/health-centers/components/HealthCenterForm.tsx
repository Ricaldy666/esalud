import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import type { HealthCenter } from '../types'
import type { HealthCenterCreateFormData, HealthCenterUpdateFormData } from '../schemas'
import { healthCenterCreateSchema, healthCenterUpdateSchema } from '../schemas'

const CENTER_TYPES = ['CESFAM', 'CECOSF', 'PSR', 'SAPU', 'SAR', 'OTRO']

interface HealthCenterFormProps {
  center?: HealthCenter | null
  onSubmit: (data: HealthCenterCreateFormData | HealthCenterUpdateFormData) => void
  onCancel: () => void
  loading?: boolean
}

export function HealthCenterForm({ center, onSubmit, onCancel, loading }: HealthCenterFormProps) {
  const isEditing = !!center

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(isEditing ? healthCenterUpdateSchema : healthCenterCreateSchema),
    defaultValues: {
      name: center?.name ?? '',
      code_deis: center?.code_deis ?? '',
      type: center?.type ?? '',
      address: center?.address ?? '',
      commune: center?.commune ?? '',
      is_active: center?.is_active ?? true,
    },
  })

  // eslint-disable-next-line react-hooks/incompatible-library
  const watchedType = watch('type')
  const watchedIsActive = watch('is_active')

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="name">Nombre</Label>
          <Input id="name" {...register('name')} placeholder="Nombre del centro" />
          {errors.name && <p className="text-xs text-red-500">{errors.name.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="code_deis">Código DEIS</Label>
          <Input id="code_deis" {...register('code_deis')} placeholder="Código DEIS" />
          {errors.code_deis && <p className="text-xs text-red-500">{errors.code_deis.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="type">Tipo</Label>
          <select
            value={watchedType}
            onChange={(e) => setValue('type', e.target.value)}
            className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="" disabled>
              Seleccionar tipo
            </option>
            {CENTER_TYPES.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </select>
          {errors.type && <p className="text-xs text-red-500">{errors.type.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="is_active">Estado</Label>
          <select
            value={watchedIsActive ? 'active' : 'inactive'}
            onChange={(e) => setValue('is_active', e.target.value === 'active')}
            className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="active">Activo</option>
            <option value="inactive">Inactivo</option>
          </select>
        </div>

        <div className="space-y-2">
          <Label htmlFor="address">Dirección</Label>
          <Input id="address" {...register('address')} placeholder="Dirección (opcional)" />
        </div>

        <div className="space-y-2">
          <Label htmlFor="commune">Comuna</Label>
          <Input id="commune" {...register('commune')} placeholder="Comuna (opcional)" />
        </div>
      </div>

      <div className="flex justify-end gap-3 pt-4">
        <Button type="button" variant="outline" onClick={onCancel} disabled={loading}>
          Cancelar
        </Button>
        <Button type="submit" disabled={loading}>
          {loading ? 'Guardando...' : isEditing ? 'Actualizar' : 'Crear Centro'}
        </Button>
      </div>
    </form>
  )
}
