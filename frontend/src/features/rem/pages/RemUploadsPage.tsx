import { useState } from 'react'
import { FileSpreadsheet, Plus } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { EmptyState } from '@/shared/components/EmptyState'
import { Badge } from '@/shared/components/ui/badge'
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
import type { RemUpload, RemUploadStatus } from '../types/rem'

const statusVariant: Record<RemUploadStatus, 'default' | 'secondary' | 'destructive' | 'outline'> =
  {
    pending: 'outline',
    processing: 'default',
    success: 'secondary',
    failed: 'destructive',
  }

const statusLabel: Record<RemUploadStatus, string> = {
  pending: 'Pendiente',
  processing: 'Procesando',
  success: 'Éxito',
  failed: 'Fallido',
}

export default function RemUploadsPage() {
  const [page, setPage] = useState(1)
  const [showUploadForm, setShowUploadForm] = useState(false)
  const { data, isLoading, isError } = useRemUploads({ page, per_page: 15 })

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
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Cargas REM</h1>
          <p className="text-sm text-slate-500">Archivos REM subidos al sistema</p>
        </div>
        {!showUploadForm && (
          <Button onClick={() => setShowUploadForm(true)} className="bg-blue-600 hover:bg-blue-700">
            <Plus className="w-4 h-4 mr-2" />
            Subir REM
          </Button>
        )}
      </div>

      {showUploadForm && <RemUploadForm onClose={() => setShowUploadForm(false)} />}

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-16">ID</TableHead>
              <TableHead>Archivo</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Periodo</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead className="text-right">Filas</TableHead>
              <TableHead className="text-right">Celdas</TableHead>
              <TableHead className="text-right">Errores</TableHead>
              <TableHead>Centro</TableHead>
              <TableHead>Fecha</TableHead>
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
            ) : !data?.data?.length ? (
              <TableRow>
                <TableCell colSpan={10}>
                  <EmptyState
                    icon={<FileSpreadsheet className="h-12 w-12" />}
                    title="No hay cargas REM"
                    description="Sube un archivo REM para comenzar."
                  />
                </TableCell>
              </TableRow>
            ) : (
              data.data.map((upload: RemUpload) => (
                <TableRow key={upload.id}>
                  <TableCell className="font-mono text-xs">{upload.id}</TableCell>
                  <TableCell className="max-w-[200px] truncate font-medium">
                    {upload.original_filename}
                  </TableCell>
                  <TableCell>{upload.rem_type}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    {String(upload.month).padStart(2, '0')}/{upload.year}
                  </TableCell>
                  <TableCell>
                    <Badge variant={statusVariant[upload.status]}>
                      {statusLabel[upload.status]}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    {upload.error_report?.summary?.total_rows_processed ?? '-'}
                  </TableCell>
                  <TableCell className="text-right">
                    {upload.error_report?.summary?.total_cells_parsed ?? '-'}
                  </TableCell>
                  <TableCell className="text-right">
                    {upload.error_report?.summary?.total_error_cells ?? '-'}
                  </TableCell>
                  <TableCell className="max-w-[120px] truncate">
                    {upload.health_center?.name ?? '-'}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-xs">
                    {new Date(upload.created_at).toLocaleDateString('es-CL')}
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
    </div>
  )
}
