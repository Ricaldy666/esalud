import { useState, useCallback, useEffect } from 'react'
import { useDropzone, type FileRejection } from 'react-dropzone'
import {
  FileSpreadsheet,
  Upload,
  X,
  Loader2,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  XCircle,
} from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { useCreateRemUpload } from '../hooks/useRemUploads'
import { type RemUpload, type RemType } from '../types/rem'
import type { RemValidationResultsResponse } from '../types/rem'
import { remUploadsService } from '../services/rem-uploads'
import {
  getSeccionLabel,
  getUbicacionLabel,
  getDescripcionLabel,
} from '../utils/validation-display'

interface RemUploadFormProps {
  onClose: () => void
  alwaysVisible?: boolean
  remType?: string
  healthCenterId?: number
}

export function RemUploadForm({
  onClose,
  alwaysVisible,
  remType = 'A',
  healthCenterId,
}: RemUploadFormProps) {
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [uploadResult, setUploadResult] = useState<{
    upload: RemUpload
    validation: RemValidationResultsResponse | null
  } | null>(null)

  const createMutation = useCreateRemUpload()

  const currentYear = new Date().getFullYear()
  const currentMonth = new Date().getMonth() + 1

  useEffect(() => {
    if (alwaysVisible && uploadResult) {
      const timer = setTimeout(() => {
        setFile(null)
        setUploadResult(null)
      }, 3000)
      return () => clearTimeout(timer)
    }
  }, [alwaysVisible, uploadResult])

  const onDrop = useCallback((acceptedFiles: File[], rejectedFiles: FileRejection[]) => {
    setFileError(null)

    if (rejectedFiles.length > 0) {
      const rejection = rejectedFiles[0]
      if (rejection.errors[0]?.code === 'file-too-large') {
        setFileError('El archivo no debe superar los 10 MB')
      } else if (rejection.errors[0]?.code === 'file-invalid-type') {
        setFileError('Solo se permiten archivos .xlsx, .xlsm o .xls')
      } else {
        setFileError('Archivo no válido')
      }
      return
    }

    if (acceptedFiles.length > 0) {
      setFile(acceptedFiles[0])
    }
  }, [])

  const { getRootProps, getInputProps, isDragActive, isDragReject } = useDropzone({
    onDrop,
    accept: {
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
      'application/vnd.ms-excel.sheet.macroenabled.12': ['.xlsm'],
      'application/vnd.ms-excel': ['.xls'],
    },
    maxSize: 10 * 1024 * 1024,
    multiple: false,
  })

  const onSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!file) {
      setFileError('Debés seleccionar un archivo')
      return
    }

    createMutation.mutate(
      {
        file,
        rem_type: remType as RemType,
        health_center_id: healthCenterId ?? 0,
        year: currentYear,
        month: currentMonth,
      },
      {
        onSuccess: async (data: RemUpload) => {
          try {
            const validation = await remUploadsService.getValidationResults(data.id)
            setUploadResult({ upload: data, validation })
          } catch {
            setUploadResult({ upload: data, validation: null })
          }
        },
      }
    )
  }

  const totalErrors = uploadResult?.validation?.results
    ? uploadResult.validation.results.filter(
        (r) => !r.passed && (r.severity ?? 'error') === 'error'
      ).length
    : 0
  const totalWarnings = uploadResult?.validation?.results
    ? uploadResult.validation.results.filter(
        (r) => !r.passed && (r.severity ?? 'error') === 'warning'
      ).length
    : 0
  const isSuccess = uploadResult && totalErrors === 0

  if (uploadResult) {
    const u = uploadResult.upload
    const hcName = u.health_center?.name ?? '—'
    const monthYear = `${u.month}/${u.year}`
    return (
      <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-lg font-bold text-slate-900">
            Importador de Archivos Estadísticos REM
          </h3>
          {!alwaysVisible && (
            <button
              onClick={onClose}
              className="p-1 hover:bg-slate-100 rounded-lg transition-colors"
              aria-label="Cerrar"
            >
              <X className="w-5 h-5 text-slate-500" />
            </button>
          )}
        </div>
        <p className="text-sm text-slate-500 mb-6">
          Subí archivos Excel (.xlsx, .xlsm) para procesar y validar antes del envío formal al
          Servicio de Salud.
        </p>

        {/* Banner de éxito */}
        {isSuccess && (
          <div className="flex items-start gap-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
            <div className="p-2 bg-emerald-500 text-white rounded-full shrink-0">
              <CheckCircle2 className="w-5 h-5" />
            </div>
            <div className="flex-1">
              <h4 className="font-bold text-emerald-950 text-sm">Validación Exitosa</h4>
              <p className="text-sm text-emerald-800 mt-0.5">
                Archivo procesado correctamente. {uploadResult.validation?.total_rules ?? 0} reglas
                de consistencia validadas.
              </p>
            </div>
          </div>
        )}

        {/* Banner de error */}
        {!isSuccess && totalErrors > 0 && (
          <div className="flex items-start gap-4 p-4 bg-rose-50 border border-rose-200 rounded-lg">
            <div className="p-2 bg-rose-500 text-white rounded-full shrink-0">
              <AlertTriangle className="w-5 h-5" />
            </div>
            <div className="flex-1">
              <h4 className="font-bold text-rose-950 text-sm">Se detectaron errores</h4>
              <p className="text-sm text-rose-800 mt-0.5">
                {u.original_filename} — {hcName} {monthYear}. {totalErrors} error(es) que deben
                corregirse.
              </p>
            </div>
          </div>
        )}

        {/* Banner de advertencias */}
        {totalWarnings > 0 && (
          <div className="flex items-start gap-4 p-4 bg-amber-50 border border-amber-200 rounded-lg mt-2">
            <div className="p-2 bg-amber-500 text-white rounded-full shrink-0">
              <AlertTriangle className="w-5 h-5" />
            </div>
            <div className="flex-1">
              <h4 className="font-bold text-amber-950 text-sm">Advertencias</h4>
              <p className="text-sm text-amber-800 mt-0.5">
                {totalWarnings} advertencia(s) detectada(s). Puede continuar con el envío.
              </p>
            </div>
          </div>
        )}

        {/* Tabla de errores */}
        {!isSuccess && uploadResult.validation && (
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
                  <th className="text-left px-4 py-2.5 text-xs font-semibold text-slate-600 uppercase tracking-wide w-36">
                    Ubicación Celda
                  </th>
                  <th className="text-left px-4 py-2.5 text-xs font-semibold text-slate-600 uppercase tracking-wide">
                    Inconsistencia Detectada
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {uploadResult.validation.results
                  .filter((r) => !r.passed)
                  .map((r) => {
                    const severity = r.severity ?? 'error'
                    const isError = severity === 'error'
                    return (
                      <tr key={r.id} className="bg-white hover:bg-slate-50">
                        <td className="px-4 py-3 text-sm text-slate-700 align-top">REM A</td>
                        <td className="px-4 py-3 text-sm font-medium align-top">
                          <span
                            className={`inline-flex items-center gap-1.5 ${isError ? 'text-red-600' : 'text-amber-600'}`}
                          >
                            {isError ? (
                              <XCircle className="w-3.5 h-3.5 shrink-0" />
                            ) : (
                              <AlertTriangle className="w-3.5 h-3.5 shrink-0" />
                            )}
                            {getSeccionLabel(r)}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-sm text-slate-600 align-top">
                          {getUbicacionLabel(r)}
                        </td>
                        <td
                          className={`px-4 py-3 text-sm align-top ${isError ? 'text-red-700' : 'text-amber-700'}`}
                        >
                          {getDescripcionLabel(r)}
                        </td>
                      </tr>
                    )
                  })}
              </tbody>
            </table>
          </div>
        )}

        {/* Botones */}
        <div className="flex justify-end gap-2 mt-4">
          <Button variant="outline" onClick={() => setUploadResult(null)}>
            Subir otro archivo
          </Button>
          {!alwaysVisible && <Button onClick={onClose}>Cerrar</Button>}
        </div>
      </div>
    )
  }

  return (
    <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
      <div className="flex items-center justify-between mb-2">
        <h3 className="text-lg font-bold text-slate-900">
          Importador de Archivos Estadísticos REM
        </h3>
        {!alwaysVisible && (
          <button
            onClick={onClose}
            className="p-1 hover:bg-slate-100 rounded-lg transition-colors"
            aria-label="Cerrar"
          >
            <X className="w-5 h-5 text-slate-500" />
          </button>
        )}
      </div>
      <p className="text-sm text-slate-500 mb-6">
        Subí archivos Excel (.xlsx, .xlsm) para procesar y validar antes del envío formal al
        Servicio de Salud.
      </p>

      <form onSubmit={onSubmit} className="space-y-4">
        <div
          {...getRootProps()}
          className={`
            border-2 border-dashed rounded-xl p-12 text-center cursor-pointer
            transition-all
            ${
              isDragReject || fileError
                ? 'border-rose-400 bg-rose-50'
                : createMutation.isPending
                  ? 'border-blue-300 bg-blue-50'
                  : isDragActive
                    ? 'border-blue-500 bg-blue-50'
                    : file
                      ? 'border-emerald-400 bg-emerald-50/50'
                      : 'border-slate-300 hover:border-blue-500 bg-slate-50 hover:bg-blue-50'
            }
          `}
        >
          <input {...getInputProps()} disabled={createMutation.isPending} />
          {createMutation.isPending ? (
            <div className="flex flex-col items-center justify-center gap-4 py-8">
              <div className="w-10 h-10 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin" />
              <p className="text-sm text-blue-600 font-medium animate-pulse">
                Validando integridad estructural y tipos de celdas según matriz MINSAL 2026...
              </p>
            </div>
          ) : (
            <div className="flex flex-col items-center gap-3">
              <div
                className={`p-4 shadow-sm rounded-full border ${
                  file ? 'bg-emerald-100 border-emerald-200' : 'bg-white border-slate-200'
                }`}
              >
                {file ? (
                  <CheckCircle2 className="w-10 h-10 text-emerald-600" />
                ) : (
                  <FileSpreadsheet className="w-10 h-10 text-emerald-600" />
                )}
              </div>

              {file ? (
                <>
                  <p className="text-sm font-medium text-emerald-900">{file.name}</p>
                  <p className="text-xs text-emerald-700">
                    {(file.size / 1024 / 1024).toFixed(2)} MB · Listo para subir
                  </p>
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation()
                      setFile(null)
                    }}
                    className="text-xs text-rose-600 hover:underline mt-1"
                  >
                    Cambiar archivo
                  </button>
                </>
              ) : (
                <>
                  <p className="text-sm font-medium text-slate-700">
                    {isDragActive ? (
                      '¡Soltá el archivo acá!'
                    ) : (
                      <>
                        Arrastrá el archivo Excel acá o{' '}
                        <span className="text-blue-600 underline">buscá en tu PC</span>
                      </>
                    )}
                  </p>
                  <p className="text-xs text-slate-400">Solo .xlsx, .xlsm, .xls (Máx. 10MB)</p>
                </>
              )}
            </div>
          )}
        </div>

        {fileError && (
          <div className="flex items-start gap-2 p-3 bg-rose-50 border border-rose-200 rounded-lg">
            <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
            <p className="text-sm text-rose-800">{fileError}</p>
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2">
          {!alwaysVisible && (
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={createMutation.isPending}
            >
              Cancelar
            </Button>
          )}
          <Button
            type="submit"
            disabled={createMutation.isPending || !file}
            className="bg-blue-600 hover:bg-blue-700"
          >
            {createMutation.isPending ? (
              <>
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                Subiendo...
              </>
            ) : (
              <>
                <Upload className="w-4 h-4 mr-2" />
                Subir REM
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  )
}
