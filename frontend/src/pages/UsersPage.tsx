import { useState, useCallback } from 'react'
import { PageHeader } from '@/shared/components/PageHeader'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Button } from '@/shared/components/ui/button'
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
    <div>
      <PageHeader
        title="Usuarios"
        description="Gestión de usuarios del sistema"
        actions={<Button onClick={handleOpenCreate}>Nuevo Usuario</Button>}
      />

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
