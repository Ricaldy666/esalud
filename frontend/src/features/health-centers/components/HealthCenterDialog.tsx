import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/shared/components/ui/dialog'
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
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{center ? 'Editar Centro de Salud' : 'Nuevo Centro de Salud'}</DialogTitle>
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
