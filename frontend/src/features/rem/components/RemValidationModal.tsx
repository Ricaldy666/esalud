import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/shared/components/ui/dialog'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { EmptyState } from '@/shared/components/EmptyState'
import { DataTable } from '@/shared/components/DataTable'
import { FileSpreadsheet, AlertCircle, CheckCircle2, XCircle, AlertTriangle } from 'lucide-react'
import { useRemUploadValidation } from '../hooks/useRemUploads'
import {
  getSeccionLabel,
  getUbicacionLabel,
  getDescripcionLabel,
} from '../utils/validation-display'
import type { RemValidationResult } from '../types/rem'

interface RemValidationModalProps {
  uploadId: number
  open: boolean
  onClose: () => void
}

export function RemValidationModal({ uploadId, open, onClose }: RemValidationModalProps) {
  const { data, isLoading, isError } = useRemUploadValidation(uploadId, open)

  const failedResults = data?.results.filter((r) => !r.passed) ?? []
  const hasBlockingErrors = failedResults.some((r) => (r.severity ?? 'error') === 'error')
  const hasWarningsOnly = failedResults.length > 0 && !hasBlockingErrors

  const errorColumns = useMemo<ColumnDef<RemValidationResult>[]>(
    () => [
      {
        header: 'Archivo Origen',
        id: 'archivo',
        cell: () => <span className="text-sm text-slate-700">REM A</span>,
      },
      {
        header: 'Sección / Pestaña',
        id: 'seccion',
        cell: ({ row }) => {
          const severity = row.original.severity ?? 'error'
          const isError = severity === 'error'
          return (
            <span
              className={`inline-flex items-center gap-1.5 text-sm font-medium ${isError ? 'text-red-600' : 'text-amber-600'}`}
            >
              {isError ? (
                <XCircle className="w-3.5 h-3.5 shrink-0" />
              ) : (
                <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
              )}
              {getSeccionLabel(row.original)}
            </span>
          )
        },
      },
      {
        header: 'Ubicación Celda',
        id: 'ubicacion',
        cell: ({ row }) => (
          <span className="text-sm text-slate-600">{getUbicacionLabel(row.original)}</span>
        ),
      },
      {
        header: 'Inconsistencia Detectada',
        id: 'descripcion',
        cell: ({ row }) => {
          const severity = row.original.severity ?? 'error'
          const isError = severity === 'error'
          return (
            <span
              className={`block text-sm whitespace-normal ${isError ? 'text-red-700' : 'text-amber-700'}`}
            >
              {getDescripcionLabel(row.original)}
            </span>
          )
        },
      },
    ],
    []
  )

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) onClose()
      }}
    >
      <DialogContent
        className="max-w-3xl w-full sm:max-w-3xl"
        style={{ maxWidth: '800px', width: '90vw' }}
      >
        <DialogHeader className="pb-2 border-b">
          <DialogTitle className="text-base font-semibold">Resultados de validación</DialogTitle>
          <DialogDescription className="text-xs text-slate-500">
            Carga REM #{uploadId}
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="space-y-3 py-4">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-full" />
          </div>
        ) : isError ? (
          <EmptyState
            icon={<AlertCircle className="h-10 w-10" />}
            title="Error al cargar"
            description="No se pudieron obtener los resultados de validación."
          />
        ) : !data ? (
          <EmptyState
            icon={<FileSpreadsheet className="h-10 w-10" />}
            title="Sin resultados"
            description="No hay información de validación disponible."
          />
        ) : (
          <>
            {/* Banner resumen */}
            {data.total_errors === 0 && data.total_warnings === 0 ? (
              <div className="flex items-start gap-3 rounded-lg bg-emerald-50 border border-emerald-200 p-3 mt-3">
                <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-semibold text-emerald-800">Validación Exitosa</p>
                  <p className="text-xs text-emerald-700 mt-0.5">
                    Todas las reglas ({data.total_rules}) se cumplieron correctamente.
                  </p>
                </div>
              </div>
            ) : hasWarningsOnly ? (
              <div className="flex items-start gap-3 rounded-lg bg-amber-50 border border-amber-200 p-3 mt-3">
                <AlertTriangle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-semibold text-amber-800">Advertencias Detectadas</p>
                  <p className="text-xs text-amber-700 mt-0.5">
                    Se detectaron {data.total_warnings} advertencia(s). Puede continuar con el
                    envío.
                  </p>
                </div>
              </div>
            ) : (
              <div className="flex items-start gap-3 rounded-lg bg-red-50 border border-red-200 p-3 mt-3">
                <XCircle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-semibold text-red-800">
                    Estructura de Archivo Rechazada
                  </p>
                  <p className="text-xs text-red-700 mt-0.5">
                    Se detectaron {data.total_errors} error(es) que deben corregirse antes de enviar
                    al Servicio de Salud.
                    {data.total_warnings > 0 &&
                      ` Additionally, ${data.total_warnings} advertencia(s).`}
                  </p>
                </div>
              </div>
            )}

            {/* Tabla de errores */}
            {failedResults.length > 0 && (
              <div className="mt-4 rounded-md border border-slate-200 overflow-hidden">
                <DataTable columns={errorColumns} data={failedResults} />
              </div>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
