import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { Button } from '@/shared/components/ui/button'
import { Badge } from '@/shared/components/ui/badge'
import { DataTable } from '@/shared/components/DataTable'
import type { HealthCenter } from '../types'

interface HealthCentersTableProps {
  data: HealthCenter[]
  loading?: boolean
  pagination?: { current_page: number; last_page: number; per_page: number; total: number }
  onPageChange?: (page: number) => void
  onSearch?: (value: string) => void
  search?: string
  onEdit?: (center: HealthCenter) => void
  onDelete?: (center: HealthCenter) => void
}

export function HealthCentersTable({
  data,
  loading,
  pagination,
  onPageChange,
  onSearch,
  search,
  onEdit,
  onDelete,
}: HealthCentersTableProps) {
  const columns = useMemo<ColumnDef<HealthCenter>[]>(
    () => [
      {
        header: 'Nombre',
        accessorKey: 'name',
      },
      {
        header: 'Código DEIS',
        accessorKey: 'code_deis',
      },
      {
        header: 'Tipo',
        accessorKey: 'type',
      },
      {
        header: 'Dirección',
        accessorKey: 'address',
        cell: ({ row }) => row.original.address ?? '-',
      },
      {
        header: 'Comuna',
        accessorKey: 'commune',
        cell: ({ row }) => row.original.commune ?? '-',
      },
      {
        header: 'Usuarios',
        accessorKey: 'users_count',
        cell: ({ row }) => row.original.users_count ?? 0,
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
      searchPlaceholder="Buscar por nombre o código DEIS..."
    />
  )
}
