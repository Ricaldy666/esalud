import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/shared/components/ui/dialog'
import { UserForm } from './UserForm'
import type { User } from '../types'
import type { UserCreateFormData, UserUpdateFormData } from '../schemas'

interface UserDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  user?: User | null
  roles: string[]
  onSubmit: (data: UserCreateFormData | UserUpdateFormData) => void
  loading?: boolean
}

export function UserDialog({
  open,
  onOpenChange,
  user,
  roles,
  onSubmit,
  loading,
}: UserDialogProps) {
  const isEditing = !!user

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEditing ? 'Editar Usuario' : 'Nuevo Usuario'}</DialogTitle>
        </DialogHeader>
        <UserForm
          user={user}
          roles={roles}
          onSubmit={onSubmit}
          onCancel={() => onOpenChange(false)}
          loading={loading}
        />
      </DialogContent>
    </Dialog>
  )
}
