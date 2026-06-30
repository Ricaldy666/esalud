import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/shared/components/ui/dialog'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { EmptyState } from '@/shared/components/EmptyState'
import { FileSpreadsheet, AlertCircle } from 'lucide-react'
import { useRemUploadValidation } from '../hooks/useRemUploads'

interface RemValidationModalProps {
  uploadId: number
  open: boolean
  onClose: () => void
}

export function RemValidationModal({ uploadId, open, onClose }: RemValidationModalProps) {
  const { data, isLoading, isError } = useRemUploadValidation(uploadId, open)

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) onClose()
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Resultados de validación</DialogTitle>
          <DialogDescription>Carga REM #{uploadId}</DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="space-y-3 py-4">
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-2/3" />
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
            <div
              className={`rounded-lg p-4 text-sm ${
                data.total_errors === 0 && data.total_warnings === 0
                  ? 'bg-green-50 text-green-800'
                  : 'bg-red-50 text-red-800'
              }`}
            >
              {data.total_errors === 0 && data.total_warnings === 0
                ? `✅ Sin errores. Todas las reglas (${data.total_rules}) se cumplieron.`
                : `❌ ${data.total_errors} error(es), ${data.total_warnings} advertencia(s) de ${data.total_rules} reglas evaluadas.`}
            </div>

            {data.results.filter((r) => !r.passed).length > 0 && (
              <div className="mt-4 max-h-[300px] space-y-2 overflow-y-auto">
                {data.results
                  .filter((r) => !r.passed)
                  .map((result) => (
                    <div key={result.id} className="rounded-md border p-3 text-sm">
                      <div className="flex items-center gap-2 font-medium">
                        <span>{result.severity === 'error' ? '🔴' : '🟡'}</span>
                        <span>{result.rule_key}</span>
                      </div>
                      {result.message && <p className="mt-1 text-gray-600">{result.message}</p>}
                    </div>
                  ))}
              </div>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
