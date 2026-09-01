import {
  flexRender,
  getCoreRowModel,
  useReactTable,
  type ColumnDef,
  type PaginationState,
} from '@tanstack/react-table'
import { ArrowRightToLine } from 'lucide-react'
import { Input } from '@/shared/components/ui/input'
import { Button } from '@/shared/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/components/ui/table'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { EmptyState } from './EmptyState'
import type { PaginationMeta } from '@/shared/types/api'
import { useCallback, useEffect, useRef, useState } from 'react'
import { cn } from '@/shared/lib/utils'

interface DataTableProps<T> {
  columns: ColumnDef<T>[]
  data: T[]
  loading?: boolean
  pagination?: PaginationMeta
  onPageChange?: (page: number) => void
  search?: string
  onSearch?: (value: string) => void
  searchPlaceholder?: string
  emptyMessage?: string
  /** Si se provee, cada fila navega/reacciona al hacer clic en cualquier
   * punto (no solo en una celda) y muestra cursor-pointer. Sin esta prop,
   * el comportamiento es idéntico al de antes de esta extensión. */
  onRowClick?: (row: T) => void
  /** className adicional por fila (ej. fondo condicional). Se fusiona con
   * los estilos por defecto de TableRow via tailwind-merge -- puede incluir
   * variantes como hover: para anular el hover por defecto si la fila
   * original no lo tenia. */
  getRowClassName?: (row: T) => string
}

export function DataTable<T>({
  columns,
  data,
  loading = false,
  pagination,
  onPageChange,
  search,
  onSearch,
  searchPlaceholder = 'Buscar...',
  emptyMessage = 'No se encontraron registros',
  onRowClick,
  getRowClassName,
}: DataTableProps<T>) {
  const [paginationState, setPaginationState] = useState<PaginationState>({
    pageIndex: 0,
    pageSize: 20,
  })

  // Deteccion real de overflow horizontal (scrollWidth > clientWidth) para
  // mostrar un indicador discreto de que hay columnas ocultas -- no depende
  // de ningun supuesto sobre el numero de columnas, se mide el DOM real.
  const scrollRef = useRef<HTMLDivElement>(null)
  const [overflow, setOverflow] = useState({ left: false, right: false })

  const updateOverflow = useCallback(() => {
    const el = scrollRef.current
    if (!el) return
    const { scrollLeft, scrollWidth, clientWidth } = el
    setOverflow({
      left: scrollLeft > 1,
      right: scrollLeft + clientWidth < scrollWidth - 1,
    })
  }, [])

  useEffect(() => {
    const el = scrollRef.current
    if (!el) return

    updateOverflow()

    const resizeObserver = new ResizeObserver(() => updateOverflow())
    resizeObserver.observe(el)
    el.addEventListener('scroll', updateOverflow, { passive: true })

    return () => {
      resizeObserver.disconnect()
      el.removeEventListener('scroll', updateOverflow)
    }
  }, [updateOverflow])

  // Los cambios de datos/columnas/loading pueden alterar el ancho del
  // contenido (ej. skeleton -> datos reales) sin que el contenedor de scroll
  // cambie de tamano -- el ResizeObserver de arriba no lo detecta solo.
  useEffect(() => {
    updateOverflow()
  }, [data, columns, loading, updateOverflow])

  // eslint-disable-next-line react-hooks/incompatible-library
  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    state: { pagination: paginationState },
    onPaginationChange: setPaginationState,
    pageCount: pagination?.last_page ?? -1,
  })

  return (
    <div className="space-y-4">
      {onSearch && (
        <div className="w-full max-w-sm">
          <Input
            placeholder={searchPlaceholder}
            value={search ?? ''}
            onChange={(e) => onSearch(e.target.value)}
          />
        </div>
      )}

      <div className="space-y-1.5">
        {overflow.right && (
          <div className="flex justify-end">
            <span className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-500">
              <ArrowRightToLine className="h-3.5 w-3.5 text-slate-400" />
              Más columnas
            </span>
          </div>
        )}

        <div className="relative rounded-md border">
          <Table ref={scrollRef}>
            <TableHeader>
              {table.getHeaderGroups().map((headerGroup) => (
                <TableRow key={headerGroup.id}>
                  {headerGroup.headers.map((header) => (
                    <TableHead key={header.id}>
                      {header.isPlaceholder
                        ? null
                        : flexRender(header.column.columnDef.header, header.getContext())}
                    </TableHead>
                  ))}
                </TableRow>
              ))}
            </TableHeader>
            <TableBody>
              {loading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <TableRow key={i}>
                    {columns.map((_, j) => (
                      <TableCell key={j}>
                        <Skeleton className="h-4 w-full" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              ) : data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={columns.length}>
                    <EmptyState title={emptyMessage} />
                  </TableCell>
                </TableRow>
              ) : (
                table.getRowModel().rows.map((row) => (
                  <TableRow
                    key={row.id}
                    onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                    className={cn(onRowClick && 'cursor-pointer', getRowClassName?.(row.original))}
                  >
                    {row.getVisibleCells().map((cell) => (
                      <TableCell key={cell.id}>
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>

          {overflow.left && (
            <div
              aria-hidden="true"
              className="pointer-events-none absolute inset-y-0 left-0 z-10 w-8 bg-gradient-to-r from-black/10 to-transparent"
            />
          )}
          {overflow.right && (
            <div
              aria-hidden="true"
              className="pointer-events-none absolute inset-y-0 right-0 z-10 w-10 bg-gradient-to-l from-black/20 to-transparent"
            />
          )}
        </div>
      </div>

      {pagination && onPageChange && pagination.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-500">
            Página {pagination.current_page} de {pagination.last_page} ({pagination.total}{' '}
            registros)
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={pagination.current_page <= 1}
              onClick={() => onPageChange(pagination.current_page - 1)}
            >
              Anterior
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={pagination.current_page >= pagination.last_page}
              onClick={() => onPageChange(pagination.current_page + 1)}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
