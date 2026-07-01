import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/shared/components/ui/dialog'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { EmptyState } from '@/shared/components/EmptyState'
import { FileSpreadsheet, AlertCircle, CheckCircle2, XCircle } from 'lucide-react'
import { useRemUploadValidation } from '../hooks/useRemUploads'
import type { RemValidationResult } from '../types/rem'

interface RemValidationModalProps {
  uploadId: number
  open: boolean
  onClose: () => void
}

function getSeccionLabel(result: RemValidationResult): string {
  const ctx = result.context as Record<string, unknown> | null
  if (result.rule_type === 'cross_sheet') {
    const src = (ctx?.source_section as string) ?? ''
    const tgt = (ctx?.target_section as string) ?? ''
    if (src && tgt && src !== tgt) return `${src} ↔ ${tgt}`
    if (src) return src
  }
  if (ctx?.section) return String(ctx.section)
  const match = result.rule_key.match(/^(a\d+[a-z]*)/i)
  return match ? match[1].toUpperCase() : result.rule_key
}

function getDescripcionLabel(result: RemValidationResult): string {
  const ctx = result.context as Record<string, unknown> | null
  if (result.rule_type === 'cross_sheet' && ctx) {
    const desc = (ctx.description as string) ?? ''
    const src = (ctx.source_sum as number) ?? 0
    const tgt = (ctx.target_sum as number) ?? 0
    const op =
      ctx.operator === 'equals'
        ? 'debe ser igual a'
        : ctx.operator === 'lte'
          ? 'no puede superar a'
          : ctx.operator === 'gte'
            ? 'debe ser mayor o igual a'
            : '='
    return `${desc} — valor registrado: ${src}, valor esperado: ${tgt} (${op})`
  }
  if (result.message) {
    return result.message.replace(/^\[.*?\]\s*/, '')
  }
  return '-'
}

export function RemValidationModal({ uploadId, open, onClose }: RemValidationModalProps) {
  const { data, isLoading, isError } = useRemUploadValidation(uploadId, open)

  const failedResults = data?.results.filter((r) => !r.passed) ?? []

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) onClose()
      }}
    >
      <DialogContent className="max-w-3xl max-h-[85vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle>Resultados de validación</DialogTitle>
          <DialogDescription>Carga REM #{uploadId}</DialogDescription>
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
          <div className="flex flex-col gap-4 overflow-hidden">
            {/* Banner resumen */}
            {data.total_errors === 0 && data.total_warnings === 0 ? (
              <div className="flex items-center gap-3 rounded-lg bg-emerald-50 border border-emerald-200 p-4">
                <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
                <div>
                  <p className="text-sm font-semibold text-emerald-800">Validación Exitosa</p>
                  <p className="text-xs text-emerald-700">
                    Todas las reglas ({data.total_rules}) se cumplieron correctamente.
                  </p>
                </div>
              </div>
            ) : (
              <div className="flex items-center gap-3 rounded-lg bg-red-50 border border-red-200 p-4">
                <XCircle className="w-5 h-5 text-red-600 shrink-0" />
                <div>
                  <p className="text-sm font-semibold text-red-800">
                    Estructura de Archivo Rechazada
                  </p>
                  <p className="text-xs text-red-700">
                    Se detectaron {data.total_errors} error(es) que deben corregirse antes de enviar
                    al Servicio de Salud.
                  </p>
                </div>
              </div>
            )}

            {/* Tabla de errores estilo Don Amador */}
            {failedResults.length > 0 && (
              <div className="overflow-y-auto flex-1 rounded-md border">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50 border-b">
                    <tr>
                      <th className="text-left px-3 py-2 text-xs font-semibold text-slate-600 uppercase tracking-wide">
                        ARCHIVO ORIGEN
                      </th>
                      <th className="text-left px-3 py-2 text-xs font-semibold text-slate-600 uppercase tracking-wide">
                        SECCIÓN / PESTAÑA
                      </th>
                      <th className="text-left px-3 py-2 text-xs font-semibold text-slate-600 uppercase tracking-wide">
                        INCONSISTENCIA DETALLADA
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {failedResults.map((result) => (
                      <tr key={result.id} className="hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-600">
                          {result.rule_type === 'cross_sheet' ? 'REM A (entre secciones)' : 'REM A'}
                        </td>
                        <td className="px-3 py-2 font-medium text-red-600">
                          {getSeccionLabel(result)}
                        </td>
                        <td className="px-3 py-2 text-red-700">{getDescripcionLabel(result)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
