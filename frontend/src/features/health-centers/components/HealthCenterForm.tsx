import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
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
import type { HealthCenter } from '../types'
import type { HealthCenterCreateFormData, HealthCenterUpdateFormData } from '../schemas'
import { healthCenterCreateSchema, healthCenterUpdateSchema } from '../schemas'

const CENTER_TYPES = ['CESFAM', 'CECOSF', 'PSR', 'SAPU', 'SAR', 'OTRO']

const INPUT_CLASS =
  'border-slate-300 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_TRIGGER_CLASS =
  'w-full border-slate-300 bg-white text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const LABEL_CLASS = 'text-slate-700'

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
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      <div className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="name" className={LABEL_CLASS}>
            Nombre
          </Label>
          <Input
            id="name"
            {...register('name')}
            placeholder="Nombre del centro"
            className={INPUT_CLASS}
          />
          {errors.name && <p className="text-xs text-red-500">{errors.name.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="code_deis" className={LABEL_CLASS}>
            Código DEIS
          </Label>
          <Input
            id="code_deis"
            {...register('code_deis')}
            placeholder="Código DEIS"
            className={INPUT_CLASS}
          />
          {errors.code_deis && <p className="text-xs text-red-500">{errors.code_deis.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="type" className={LABEL_CLASS}>
            Tipo
          </Label>
          <Select
            value={watchedType}
            onValueChange={(v: string | null) => v && setValue('type', v)}
          >
            <SelectTrigger className={SELECT_TRIGGER_CLASS}>
              <SelectValue placeholder="Seleccionar tipo" />
            </SelectTrigger>
            <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
              {CENTER_TYPES.map((type) => (
                <SelectItem key={type} value={type} className={SELECT_ITEM_CLASS}>
                  {type}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.type && <p className="text-xs text-red-500">{errors.type.message}</p>}
        </div>

        <div className="space-y-2">
          <Label htmlFor="is_active" className={LABEL_CLASS}>
            Estado
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

        <div className="space-y-2">
          <Label htmlFor="address" className={LABEL_CLASS}>
            Dirección
          </Label>
          <Input
            id="address"
            {...register('address')}
            placeholder="Dirección (opcional)"
            className={INPUT_CLASS}
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="commune" className={LABEL_CLASS}>
            Comuna
          </Label>
          <Input
            id="commune"
            {...register('commune')}
            placeholder="Comuna (opcional)"
            className={INPUT_CLASS}
          />
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
          className="bg-blue-600 text-white hover:bg-blue-700"
        >
          {loading ? 'Guardando...' : isEditing ? 'Actualizar' : 'Crear Centro'}
        </Button>
      </DialogFooter>
    </form>
  )
}
