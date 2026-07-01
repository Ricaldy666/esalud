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
    const cleanDesc = desc
      .replace(/\([A-Z0-9]+![A-Z]+![A-Z]\d+\)/g, '')
      .replace(/\([A-Z0-9]+![A-Z]+![A-Z]\d+\+[A-Z]\d+\)/g, '')
      .trim()
    return `${cleanDesc} — Registrado: ${src} | Esperado: ${tgt}`
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
                  </p>
                </div>
              </div>
            )}

            {/* Tabla de errores */}
            {failedResults.length > 0 && (
              <div className="mt-4 rounded-md border border-slate-200 overflow-hidden">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-slate-100 border-b border-slate-200">
                      <th className="text-left px-4 py-2.5 text-xs font-semibold text-slate-600 uppercase tracking-wide w-28">
                        Archivo Origen
                      </th>
                      <th className="text-left px-4 py-2.5 text-xs font-semibold text-slate-600 uppercase tracking-wide w-32">
                        Sección / Pestaña
                      </th>
                      <th className="text-left px-4 py-2.5 text-xs font-semibold text-slate-600 uppercase tracking-wide">
                        Inconsistencia Detectada
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {failedResults.map((result) => (
                      <tr key={result.id} className="bg-white hover:bg-slate-50">
                        <td className="px-4 py-3 text-sm text-slate-700 align-top">REM A</td>
                        <td className="px-4 py-3 text-sm font-medium text-red-600 align-top">
                          {getSeccionLabel(result)}
                        </td>
                        <td className="px-4 py-3 text-sm text-red-700 align-top">
                          {getDescripcionLabel(result)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
