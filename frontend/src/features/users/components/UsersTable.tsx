import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { Button } from '@/shared/components/ui/button'
import { Badge } from '@/shared/components/ui/badge'
import { DataTable } from '@/shared/components/DataTable'
import type { User } from '../types'

interface UsersTableProps {
  data: User[]
  loading?: boolean
  pagination?: { current_page: number; last_page: number; per_page: number; total: number }
  onPageChange?: (page: number) => void
  onSearch?: (value: string) => void
  search?: string
  onEdit?: (user: User) => void
  onDelete?: (user: User) => void
}

export function UsersTable({
  data,
  loading,
  pagination,
  onPageChange,
  onSearch,
  search,
  onEdit,
  onDelete,
}: UsersTableProps) {
  const columns = useMemo<ColumnDef<User>[]>(
    () => [
      {
        header: 'Nombre',
        accessorKey: 'name',
      },
      {
        header: 'RUT',
        accessorKey: 'rut',
      },
      {
        header: 'Email',
        accessorKey: 'email',
      },
      {
        header: 'Rol',
        accessorKey: 'roles',
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1">
            {row.original.roles.map((role) => (
              <Badge key={role} variant={role === 'Administrador' ? 'default' : 'secondary'}>
                {role}
              </Badge>
            ))}
          </div>
        ),
      },
      {
        header: 'Centro',
        accessorKey: 'health_center',
        cell: ({ row }) => row.original.health_center?.name ?? '-',
      },
      {
        header: 'Estado',
        accessorKey: 'is_active',
        cell: ({ row }) => (
          <Badge variant={row.original.is_active ? 'default' : 'secondary'}>
            {row.original.is_active ? 'Activo' : 'Inactivo'}
          </Badge>
        ),
      },
      {
        header: 'Último login',
        accessorKey: 'last_login_at',
        cell: ({ row }) => {
          if (!row.original.last_login_at) return '-'
          return new Date(row.original.last_login_at).toLocaleString('es-CL')
        },
      },
      {
        header: 'Acciones',
        cell: ({ row }) => (
          <div className="flex gap-2">
            <Button variant="ghost" size="sm" onClick={() => onEdit?.(row.original)}>
              Editar
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="text-red-600 hover:text-red-700"
              onClick={() => onDelete?.(row.original)}
            >
              Eliminar
            </Button>
          </div>
        ),
      },
    ],
    [onEdit, onDelete]
  )

  return (
    <DataTable
      columns={columns}
      data={data}
      loading={loading}
      pagination={pagination}
      onPageChange={onPageChange}
      search={search}
      onSearch={onSearch}
      searchPlaceholder="Buscar por nombre, email o RUT..."
    />
  )
}
