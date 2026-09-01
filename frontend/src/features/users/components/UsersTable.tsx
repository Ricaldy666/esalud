import { useMemo } from 'react'
import type { ReactNode } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from '@/shared/components/DataTable'
import { Pencil, Trash2 } from 'lucide-react'
import { Badge } from '@/shared/components/ui/badge'
import { Button } from '@/shared/components/ui/button'
import { getRoleDisplayLabel } from '@/shared/utils/roleLabels'
import type { User } from '../types'

const ROLE_BADGE_STYLES: Record<string, string> = {
  Superadmin: 'bg-violet-50 text-violet-700 border-violet-200',
  Administrador: 'bg-blue-50 text-blue-700 border-blue-200',
  Analista: 'bg-indigo-50 text-indigo-700 border-indigo-200',
}
const DEFAULT_ROLE_BADGE_STYLE = 'bg-slate-100 text-slate-600 border-slate-200'

// El fondo del encabezado ya lo aporta TableHeader (compartido). Este wrapper
// solo controla alineacion/centrado por columna (ej. Estado y Acciones).
function HeaderCell({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={`flex h-10 items-center text-xs font-semibold text-slate-600 ${className}`}>
      {children}
    </div>
  )
}

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
        header: () => <HeaderCell>Nombre</HeaderCell>,
        accessorKey: 'name',
        cell: ({ row }) => <span className="font-medium text-slate-900">{row.original.name}</span>,
      },
      {
        header: () => <HeaderCell>RUT</HeaderCell>,
        accessorKey: 'rut',
        cell: ({ row }) => <span className="text-slate-600">{row.original.rut}</span>,
      },
      {
        header: () => <HeaderCell>Email</HeaderCell>,
        accessorKey: 'email',
        cell: ({ row }) => <span className="text-slate-600">{row.original.email}</span>,
      },
      {
        header: () => <HeaderCell>Rol</HeaderCell>,
        accessorKey: 'roles',
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1.5">
            {row.original.roles.map((role) => (
              <Badge
                key={role}
                variant="outline"
                className={`font-medium ${ROLE_BADGE_STYLES[role] ?? DEFAULT_ROLE_BADGE_STYLE}`}
              >
                {getRoleDisplayLabel(role)}
              </Badge>
            ))}
          </div>
        ),
      },
      {
        header: () => <HeaderCell>Centro</HeaderCell>,
        accessorKey: 'health_center',
        cell: ({ row }) =>
          row.original.health_center?.name ?? (
            <Badge
              variant="secondary"
              className="border border-slate-200 bg-slate-100 font-normal text-slate-500"
            >
              Toda la red APS
            </Badge>
          ),
      },
      {
        header: () => <HeaderCell className="justify-center text-center">Estado</HeaderCell>,
        accessorKey: 'is_active',
        cell: ({ row }) => (
          <div className="flex justify-center">
            <Badge
              variant="outline"
              className={
                row.original.is_active
                  ? 'gap-1 border-emerald-200 bg-emerald-50 text-emerald-700'
                  : 'gap-1 border-slate-200 bg-slate-100 text-slate-500'
              }
            >
              <span
                className={`size-1.5 rounded-full ${
                  row.original.is_active ? 'bg-emerald-500' : 'bg-slate-400'
                }`}
              />
              {row.original.is_active ? 'Activo' : 'Inactivo'}
            </Badge>
          </div>
        ),
      },
      {
        header: () => <HeaderCell>Último login</HeaderCell>,
        accessorKey: 'last_login_at',
        cell: ({ row }) => (
          <span className="text-sm text-slate-500 tabular-nums whitespace-nowrap">
            {row.original.last_login_at
              ? new Date(row.original.last_login_at).toLocaleString('es-CL')
              : '-'}
          </span>
        ),
      },
      {
        id: 'acciones',
        header: () => <HeaderCell className="justify-end text-right">Acciones</HeaderCell>,
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1.5">
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              onClick={() => onEdit?.(row.original)}
              title={`Editar a ${row.original.name}`}
              className="border-blue-200 bg-white text-blue-600 hover:bg-blue-50"
            >
              <Pencil className="size-4" />
            </Button>
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              onClick={() => onDelete?.(row.original)}
              title={`Eliminar a ${row.original.name}`}
              className="border-red-200 bg-white text-red-600 hover:bg-red-50"
            >
              <Trash2 className="size-4" />
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
