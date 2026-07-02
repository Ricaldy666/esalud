import { useState, useMemo } from 'react'
import { FileSpreadsheet, Eye, Calendar, Building2 } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { EmptyState } from '@/shared/components/EmptyState'
import { Skeleton } from '@/shared/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/components/ui/table'
import { Button } from '@/shared/components/ui/button'
import { useRemUploads } from '../hooks/useRemUploads'
import { RemUploadForm } from '../components/RemUploadForm'
import { RemValidationModal } from '../components/RemValidationModal'
import { REM_TYPE_LABELS, type RemType } from '../types/rem'
import type { RemUpload, RemUploadStatus } from '../types/rem'
import { useHealthCenters } from '@/features/health-centers/hooks/useHealthCenters'

const MONTHS = [
  { value: 1, label: 'Enero' },
  { value: 2, label: 'Febrero' },
  { value: 3, label: 'Marzo' },
  { value: 4, label: 'Abril' },
  { value: 5, label: 'Mayo' },
  { value: 6, label: 'Junio' },
  { value: 7, label: 'Julio' },
  { value: 8, label: 'Agosto' },
  { value: 9, label: 'Septiembre' },
  { value: 10, label: 'Octubre' },
  { value: 11, label: 'Noviembre' },
  { value: 12, label: 'Diciembre' },
]

const YEARS = Array.from({ length: 16 }, (_, i) => 2015 + i)

const statusStyles: Record<RemUploadStatus, string> = {
  pending: 'bg-slate-100 text-slate-600 border border-slate-200',
  processing: 'bg-blue-50 text-blue-700 border border-blue-200',
  success: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
  with_errors: 'bg-red-50 text-red-700 border border-red-200',
  failed: 'bg-red-100 text-red-800 border border-red-300',
}

const statusLabel: Record<RemUploadStatus, string> = {
  pending: 'Pendiente',
  processing: 'Procesando',
  success: 'Éxito',
  with_errors: 'Con errores',
  failed: 'Fallido',
}

export default function RemUploadsPage() {
  const [page, setPage] = useState(1)
  const [validationModalUploadId, setValidationModalUploadId] = useState<number | null>(null)
  const [filterYear, setFilterYear] = useState<number | null>(null)
  const [filterMonth, setFilterMonth] = useState<number | null>(null)
  const [filterCenter, setFilterCenter] = useState<number | null>(null)
  const { data, isLoading, isError } = useRemUploads({ page, per_page: 15 })
  const { data: healthCentersPage } = useHealthCenters()
  const healthCenters = healthCentersPage?.data ?? []

  const filteredUploads = useMemo(() => {
    if (!data?.data?.length) return []
    return data.data.filter((upload: RemUpload) => {
      if (filterYear && upload.year !== filterYear) return false
      if (filterMonth && upload.month !== filterMonth) return false
      if (filterCenter && upload.health_center?.id !== filterCenter) return false
      return true
    })
  }, [data, filterYear, filterMonth, filterCenter])

  if (isError) {
    return (
      <div>
        <PageHeader title="Cargas REM" description="Archivos REM subidos al sistema" />
        <EmptyState
          icon={<FileSpreadsheet className="h-12 w-12" />}
          title="Error al cargar"
          description="No se pudieron obtener las cargas REM. Verifica la conexión con el servidor."
        />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Carga de Datos REM</h1>
          <p className="text-sm text-slate-500">Archivos REM subidos al sistema</p>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1.5">
            <Calendar className="w-4 h-4 text-slate-500" />
            <select
              value={filterYear?.toString() ?? ''}
              onChange={(e) => setFilterYear(e.target.value ? Number(e.target.value) : null)}
              className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option value="">Todos los años</option>
              {YEARS.map((y) => (
                <option key={y} value={y.toString()}>
                  {y}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-1.5">
            <Calendar className="w-4 h-4 text-slate-500" />
            <select
              value={filterMonth?.toString() ?? ''}
              onChange={(e) => setFilterMonth(e.target.value ? Number(e.target.value) : null)}
              className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option value="">Todos los meses</option>
              {MONTHS.map((m) => (
                <option key={m.value} value={m.value.toString()}>
                  {m.label}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-1.5">
            <Building2 className="w-4 h-4 text-slate-500" />
            <select
              value={filterCenter?.toString() ?? ''}
              onChange={(e) => setFilterCenter(e.target.value ? Number(e.target.value) : null)}
              className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            >
              <option value="">Todos los centros</option>
              {healthCenters.map((hc) => (
                <option key={hc.id} value={hc.id.toString()}>
                  {hc.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      <RemUploadForm onClose={() => {}} alwaysVisible />

      <div className="rounded-md border overflow-x-auto">
        <Table className="min-w-[900px]">
          <TableHeader>
            <TableRow>
              <TableHead className="w-16">ID</TableHead>
              <TableHead>Archivo</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Periodo</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead className="text-right">Filas</TableHead>
              <TableHead className="text-right">Celdas</TableHead>
              <TableHead className="text-right">Val.</TableHead>
              <TableHead>Centro</TableHead>
              <TableHead>Fecha</TableHead>
              <TableHead>Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <TableRow key={i}>
                  {Array.from({ length: 10 }).map((_, j) => (
                    <TableCell key={j}>
                      <Skeleton className="h-4 w-full" />
                    </TableCell>
                  ))}
                </TableRow>
              ))
            ) : !filteredUploads.length ? (
              <TableRow>
                <TableCell colSpan={11}>
                  <EmptyState
                    icon={<FileSpreadsheet className="h-12 w-12" />}
                    title="No hay cargas REM"
                    description="Sube un archivo REM para comenzar."
                  />
                </TableCell>
              </TableRow>
            ) : (
              filteredUploads.map((upload: RemUpload) => (
                <TableRow key={upload.id}>
                  <TableCell className="font-mono text-xs">{upload.id}</TableCell>
                  <TableCell className="max-w-[200px] truncate font-medium">
                    {upload.original_filename}
                  </TableCell>
                  <TableCell>
                    <span className="text-xs font-medium text-slate-600">
                      {REM_TYPE_LABELS[upload.rem_type as RemType] ?? upload.rem_type}
                    </span>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    {String(upload.month).padStart(2, '0')}/{upload.year}
                  </TableCell>
                  <TableCell>
                    <span
                      className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusStyles[upload.status]}`}
                    >
                      {statusLabel[upload.status]}
                    </span>
                  </TableCell>
                  <TableCell className="text-right">
                    {upload.error_report?.summary?.total_rows_processed ?? '-'}
                  </TableCell>
                  <TableCell className="text-right">
                    {upload.error_report?.summary?.total_cells_parsed ?? '-'}
                  </TableCell>
                  <TableCell className="text-right">
                    {upload.status === 'with_errors' ? (
                      <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">
                        {upload.error_report?.summary?.total_error_cells
                          ? upload.error_report!.summary!.total_error_cells
                          : '!'}
                      </span>
                    ) : upload.status === 'failed' ? (
                      <span className="text-red-500 text-xs">Error</span>
                    ) : (
                      <span className="text-emerald-600 text-xs">✓</span>
                    )}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    {upload.health_center?.name ?? '-'}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-xs">
                    {new Date(upload.created_at).toLocaleDateString('es-CL')}
                  </TableCell>
                  <TableCell>
                    {upload.status !== 'pending' && upload.status !== 'processing' && (
                      <button
                        onClick={() => setValidationModalUploadId(upload.id)}
                        title="Ver resultados de validación"
                        className="p-1.5 rounded-md text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                      >
                        <Eye className="w-4 h-4" />
                      </button>
                    )}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {data?.meta && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-500">
            Página {data.meta.current_page} de {data.meta.last_page} ({data.meta.total} registros)
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={data.meta.current_page <= 1}
              onClick={() => setPage((p) => p - 1)}
            >
              Anterior
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={data.meta.current_page >= data.meta.last_page}
              onClick={() => setPage((p) => p + 1)}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}

      <RemValidationModal
        uploadId={validationModalUploadId ?? 0}
        open={validationModalUploadId !== null}
        onClose={() => setValidationModalUploadId(null)}
      />
    </div>
  )
}
