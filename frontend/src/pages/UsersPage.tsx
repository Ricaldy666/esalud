import { useState, useCallback } from 'react'
import { UserPlus, Users as UsersIcon } from 'lucide-react'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Button } from '@/shared/components/ui/button'
import { Card } from '@/shared/components/ui/card'
import { useUsers, useCreateUser, useUpdateUser, useDeleteUser } from '@/features/users'
import { useRoles } from '@/features/roles'
import { UsersTable } from '@/features/users/components/UsersTable'
import { UserDialog } from '@/features/users/components/UserDialog'
import type { User } from '@/features/users/types'
import type { UserCreateFormData, UserUpdateFormData } from '@/features/users/schemas'

export default function UsersPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedUser, setSelectedUser] = useState<User | null>(null)

  const { data: usersData, isLoading } = useUsers({ search, page })
  const { data: rolesData } = useRoles()
  const createUser = useCreateUser()
  const updateUser = useUpdateUser()
  const deleteUser = useDeleteUser()

  const roles = rolesData ?? []

  const handleOpenCreate = useCallback(() => {
    setSelectedUser(null)
    setDialogOpen(true)
  }, [])

  const handleOpenEdit = useCallback((user: User) => {
    setSelectedUser(user)
    setDialogOpen(true)
  }, [])

  const handleOpenDelete = useCallback((user: User) => {
    setSelectedUser(user)
    setDeleteDialogOpen(true)
  }, [])

  const handleSubmit = useCallback(
    (data: UserCreateFormData | UserUpdateFormData) => {
      if (selectedUser) {
        updateUser.mutate(
          { id: selectedUser.id, data: data as UserUpdateFormData },
          {
            onSuccess: () => setDialogOpen(false),
          }
        )
      } else {
        createUser.mutate(data as UserCreateFormData, {
          onSuccess: () => setDialogOpen(false),
        })
      }
    },
    [selectedUser, createUser, updateUser]
  )

  const handleDelete = useCallback(() => {
    if (!selectedUser) return
    deleteUser.mutate(selectedUser.id, {
      onSuccess: () => {
        setDeleteDialogOpen(false)
        setSelectedUser(null)
      },
    })
  }, [selectedUser, deleteUser])

  const isMutating = createUser.isPending || updateUser.isPending || deleteUser.isPending

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <Card className="border border-slate-200 bg-white px-5 py-5 shadow-sm">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-3">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white">
              <UsersIcon className="size-5" />
            </div>
            <div>
              <h1 className="text-xl font-bold text-slate-900">Usuarios</h1>
              <p className="text-sm text-slate-500">
                Gestión de cuentas, roles y acceso al sistema
              </p>
            </div>
          </div>
          <Button
            onClick={handleOpenCreate}
            className="gap-1.5 bg-blue-600 text-white hover:bg-blue-700"
          >
            <UserPlus className="size-4" />
            Nuevo Usuario
          </Button>
        </div>
      </Card>

      <Card className="border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <UsersTable
          data={usersData?.data ?? []}
          loading={isLoading}
          pagination={usersData?.meta}
          onPageChange={setPage}
          search={search}
          onSearch={(value) => {
            setSearch(value)
            setPage(1)
          }}
          onEdit={handleOpenEdit}
          onDelete={handleOpenDelete}
        />
      </Card>

      <UserDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        user={selectedUser}
        roles={roles}
        onSubmit={handleSubmit}
        loading={isMutating}
      />

      <ConfirmDialog
        open={deleteDialogOpen}
        onConfirm={handleDelete}
        onCancel={() => setDeleteDialogOpen(false)}
        title="Eliminar Usuario"
        description={`¿Estás seguro de eliminar a ${selectedUser?.name}? Esta acción es reversible (soft delete).`}
        confirmText="Eliminar"
        variant="destructive"
        loading={isMutating}
      />
    </div>
  )
}
