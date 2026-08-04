import { FileSpreadsheet, CheckCircle2, AlertTriangle, XCircle } from 'lucide-react'
import type { RemUploadPreview } from '../types/rem'
import { Button } from '@/shared/components/ui/button'

interface RemUploadPreviewCardProps {
  preview: RemUploadPreview
  healthCenterId?: number
  onConfirm: () => void
  onCancel: () => void
  loading: boolean
}

function formatDate(dateStr: string): string {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-')
  return `${d}-${m}-${y}`
}

export function RemUploadPreviewCard({
  preview,
  healthCenterId,
  onConfirm,
  onCancel,
  loading,
}: RemUploadPreviewCardProps) {
  const hasErrors = preview.errors.length > 0
  const hasWarnings = preview.warnings.length > 0
  const canSubmit = !hasErrors && (!!healthCenterId || !!preview.health_center_detected)

  const typeLabel = preview.has_macros
    ? 'Archivo oficial con macros (.xlsm)'
    : 'Archivo sin macros (.xlsx)'

  const validationItems = [
    { label: 'Archivo Excel válido', pass: preview.is_valid_excel },
    { label: 'Contiene macros', pass: preview.has_macros },
    { label: 'Serie detectada', pass: !!preview.serie_detected },
    {
      label: 'Período detectado desde el Excel',
      pass: !!preview.month_detected && !!preview.year_detected,
    },
    { label: 'Establecimiento detectado', pass: !!preview.health_center_detected },
    { label: 'Versión vigente', pass: preview.version_status === 'current' },
  ]

  return (
    <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
      <h3 className="text-lg font-bold text-slate-900 mb-4">Vista previa del archivo</h3>

      {/* Basic info */}
      <div className="grid grid-cols-2 gap-4 mb-4">
        <div className="p-3 bg-slate-50 rounded-lg">
          <p className="text-xs text-slate-500 mb-1">Archivo</p>
          <p className="text-sm font-medium text-slate-900 flex items-center gap-2">
            <FileSpreadsheet className="w-4 h-4 text-blue-600" />
            {preview.filename}
          </p>
          <p className="text-xs text-slate-400 mt-0.5">
            {preview.size_mb} MB · {preview.extension.toUpperCase()}
          </p>
        </div>

        <div className="p-3 bg-slate-50 rounded-lg">
          <p className="text-xs text-slate-500 mb-1">Tipo</p>
          <p className="text-sm font-semibold text-slate-900">{typeLabel}</p>
        </div>

        <div className="p-3 bg-slate-50 rounded-lg">
          <p className="text-xs text-slate-500 mb-1">Serie detectada</p>
          <p className="text-sm font-semibold text-slate-900">
            {preview.rem_type_detected ?? <span className="text-amber-600">No detectada</span>}
          </p>
        </div>

        <div className="p-3 bg-slate-50 rounded-lg">
          <p className="text-xs text-slate-500 mb-1">Período informado en el REM</p>
          <p className="text-sm font-semibold text-slate-900">
            {preview.period_label || <span className="text-amber-600">No detectado</span>}
          </p>
        </div>

        <div className="p-3 bg-slate-50 rounded-lg">
          <p className="text-xs text-slate-500 mb-1">Fecha de carga</p>
          <p className="text-sm font-semibold text-slate-900">{formatDate(preview.upload_date)}</p>
        </div>

        <div className="p-3 bg-slate-50 rounded-lg">
          <p className="text-xs text-slate-500 mb-1">Establecimiento detectado</p>
          {preview.health_center_detected ? (
            <div>
              <p className="text-sm font-semibold text-slate-900">
                {preview.health_center_detected.name}
              </p>
              <p className="text-xs text-slate-400">
                Código DEIS: {preview.health_center_detected.code}
              </p>
            </div>
          ) : (
            <p className="text-sm text-amber-600">No detectado</p>
          )}
        </div>
      </div>

      {/* Validation checklist */}
      <div className="mb-4 p-4 bg-slate-50 rounded-lg border border-slate-100">
        <p className="text-xs font-semibold text-slate-600 mb-2">Validaciones</p>
        <div className="grid grid-cols-2 gap-x-4 gap-y-1.5">
          {validationItems.map((item) => (
            <div key={item.label} className="flex items-center gap-2 text-xs">
              {item.pass ? (
                <CheckCircle2 className="w-4 h-4 text-emerald-500 shrink-0" />
              ) : (
                <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0" />
              )}
              <span className={item.pass ? 'text-slate-700' : 'text-amber-700'}>{item.label}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Warnings */}
      {hasWarnings && (
        <div className="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
          <p className="text-xs font-semibold text-amber-800 mb-1 flex items-center gap-1">
            <AlertTriangle className="w-4 h-4" /> Advertencias
          </p>
          <ul className="space-y-1">
            {preview.warnings.map((w, i) => (
              <li key={i} className="text-xs text-amber-700 flex items-start gap-1">
                <span className="mt-0.5 shrink-0">•</span>
                <span>{w}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Errors */}
      {hasErrors && (
        <div className="mb-4 p-3 bg-rose-50 border border-rose-200 rounded-lg">
          <p className="text-xs font-semibold text-rose-800 mb-1 flex items-center gap-1">
            <XCircle className="w-4 h-4" /> Errores
          </p>
          <ul className="space-y-1">
            {preview.errors.map((e, i) => (
              <li key={i} className="text-xs text-rose-700 flex items-start gap-1">
                <span className="mt-0.5 shrink-0">•</span>
                <span>{e}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex justify-end gap-2">
        <Button variant="outline" onClick={onCancel} disabled={loading}>
          Cancelar
        </Button>
        <Button
          onClick={onConfirm}
          disabled={!canSubmit || loading}
          className="bg-blue-600 hover:bg-blue-700"
        >
          {loading ? 'Confirmando...' : 'Confirmar y cargar REM'}
        </Button>
      </div>
    </div>
  )
}
