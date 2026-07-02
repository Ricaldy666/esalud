import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from '@/shared/components/DataTable'
import { Pencil, Trash2 } from 'lucide-react'
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
              <span
                key={role}
                className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                  role === 'Administrador'
                    ? 'bg-blue-50 text-blue-700 border border-blue-200'
                    : 'bg-slate-100 text-slate-600 border border-slate-200'
                }`}
              >
                {role}
              </span>
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
          <span
            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
              row.original.is_active
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                : 'bg-slate-100 text-slate-500 border border-slate-200'
            }`}
          >
            {row.original.is_active ? '● Activo' : '○ Inactivo'}
          </span>
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
          <div className="flex items-center gap-1">
            <button
              onClick={() => onEdit?.(row.original)}
              title="Editar"
              className="p-1.5 rounded-md text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
            >
              <Pencil className="w-4 h-4" />
            </button>
            <button
              onClick={() => onDelete?.(row.original)}
              title="Eliminar"
              className="p-1.5 rounded-md text-slate-500 hover:text-red-600 hover:bg-red-50 transition-colors"
            >
              <Trash2 className="w-4 h-4" />
            </button>
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
