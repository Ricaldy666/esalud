import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from '@/shared/components/DataTable'
import { Pencil, Trash2 } from 'lucide-react'
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
      searchPlaceholder="Buscar por nombre o código DEIS..."
    />
  )
}
