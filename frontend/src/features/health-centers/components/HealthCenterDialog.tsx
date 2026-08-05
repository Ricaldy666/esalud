import { Building2 } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { HealthCenterForm } from './HealthCenterForm'
import type { HealthCenter } from '../types'
import type { HealthCenterCreateFormData, HealthCenterUpdateFormData } from '../schemas'

interface HealthCenterDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  center?: HealthCenter | null
  onSubmit: (data: HealthCenterCreateFormData | HealthCenterUpdateFormData) => void
  loading?: boolean
}

export function HealthCenterDialog({
  open,
  onOpenChange,
  center,
  onSubmit,
  loading,
}: HealthCenterDialogProps) {
  const isEditing = !!center

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="w-full border border-slate-200 bg-white shadow-xl sm:max-w-2xl">
        <DialogHeader className="border-b border-slate-100 pb-4">
          <div className="flex items-center gap-3">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white">
              <Building2 className="size-5" />
            </div>
            <div>
              <DialogTitle className="text-lg font-bold text-slate-900">
                {isEditing ? 'Editar Centro de Salud' : 'Nuevo Centro de Salud'}
              </DialogTitle>
              <DialogDescription className="text-slate-500">
                {isEditing
                  ? 'Actualice los datos del centro de salud seleccionado.'
                  : 'Complete los datos para registrar un nuevo centro de salud.'}
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>
        <HealthCenterForm
          center={center}
          onSubmit={onSubmit}
          onCancel={() => onOpenChange(false)}
          loading={loading}
        />
      </DialogContent>
    </Dialog>
  )
}
