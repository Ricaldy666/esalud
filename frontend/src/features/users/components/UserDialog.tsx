import { UserCog, UserPlus } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
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
      <DialogContent className="w-full border border-slate-200 bg-white shadow-xl sm:max-w-3xl">
        <DialogHeader className="border-b border-slate-100 pb-4">
          <div className="flex items-center gap-3">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white">
              {isEditing ? <UserCog className="size-5" /> : <UserPlus className="size-5" />}
            </div>
            <div>
              <DialogTitle className="text-lg font-bold text-slate-900">
                {isEditing ? 'Editar Usuario' : 'Nuevo Usuario'}
              </DialogTitle>
              <DialogDescription className="text-slate-500">
                {isEditing
                  ? 'Actualice los datos del usuario seleccionado.'
                  : 'Complete los datos para registrar un nuevo usuario.'}
              </DialogDescription>
            </div>
          </div>
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
