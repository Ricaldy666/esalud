import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from '@/shared/components/DataTable'
import { Pencil, Trash2 } from 'lucide-react'
import { Badge } from '@/shared/components/ui/badge'
import { Button } from '@/shared/components/ui/button'
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
        cell: ({ row }) => <span className="font-medium text-slate-900">{row.original.name}</span>,
      },
      {
        header: 'Código DEIS',
        accessorKey: 'code_deis',
        cell: ({ row }) => <span className="text-slate-600">{row.original.code_deis}</span>,
      },
      {
        header: 'Tipo',
        accessorKey: 'type',
        cell: ({ row }) => <span className="text-slate-600">{row.original.type}</span>,
      },
      {
        header: 'Dirección',
        accessorKey: 'address',
        cell: ({ row }) => <span className="text-slate-600">{row.original.address ?? '-'}</span>,
      },
      {
        header: 'Comuna',
        accessorKey: 'commune',
        cell: ({ row }) => <span className="text-slate-600">{row.original.commune ?? '-'}</span>,
      },
      {
        header: 'Usuarios',
        accessorKey: 'users_count',
        cell: ({ row }) => <span className="text-slate-600">{row.original.users_count ?? 0}</span>,
      },
      {
        header: () => <div className="text-center">Estado</div>,
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
        id: 'acciones',
        header: () => <div className="text-right">Acciones</div>,
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1.5">
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              onClick={() => onEdit?.(row.original)}
              title={`Editar ${row.original.name}`}
              className="border-blue-200 bg-white text-blue-600 hover:bg-blue-50"
            >
              <Pencil className="size-4" />
            </Button>
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              onClick={() => onDelete?.(row.original)}
              title={`Eliminar ${row.original.name}`}
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
      searchPlaceholder="Buscar por nombre o código DEIS..."
    />
  )
}
