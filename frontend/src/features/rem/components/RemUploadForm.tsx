import { useState, useCallback } from 'react'
import { useDropzone, type FileRejection } from 'react-dropzone'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import {
  FileSpreadsheet,
  Upload,
  X,
  Loader2,
  CheckCircle2,
  AlertCircle,
  Calendar,
  Building2,
  FileType,
} from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { useCreateRemUpload } from '../hooks/useRemUploads'
import { useHealthCenters } from '@/features/health-centers/hooks/useHealthCenters'
import { REM_TYPE_LABELS, type RemType } from '../types/rem'

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

const uploadSchema = z.object({
  rem_type: z.enum(['A', 'BM', 'D', 'P'] as const),
  health_center_id: z.number().int().positive(),
  year: z.number().int().min(2015).max(2030),
  month: z.number().int().min(1).max(12),
})

type UploadFormValues = z.infer<typeof uploadSchema>

interface RemUploadFormProps {
  onClose: () => void
}

export function RemUploadForm({ onClose }: RemUploadFormProps) {
  const [file, setFile] = useState<File | null>(null)
  const [fileError, setFileError] = useState<string | null>(null)

  const { data: healthCentersPage, isLoading: loadingCenters } = useHealthCenters()
  const healthCenters = healthCentersPage?.data ?? []
  const createMutation = useCreateRemUpload()

  const today = new Date()
  const currentMonth = today.getMonth() + 1
  const currentYear = today.getFullYear()

  const {
    control,
    handleSubmit,
    formState: { errors },
  } = useForm<UploadFormValues>({
    resolver: zodResolver(uploadSchema),
    defaultValues: {
      rem_type: 'A' as const,
      year: currentYear,
      month: currentMonth,
    },
  })

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

  const onSubmit = (values: UploadFormValues) => {
    if (!file) {
      setFileError('Debés seleccionar un archivo')
      return
    }

    createMutation.mutate(
      { ...values, file },
      {
        onSuccess: () => {
          onClose()
        },
      }
    )
  }

  return (
    <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
      <div className="flex items-center justify-between mb-2">
        <h3 className="text-lg font-bold text-slate-900">
          Importador de Archivos Estadísticos REM
        </h3>
        <button
          onClick={onClose}
          className="p-1 hover:bg-slate-100 rounded-lg transition-colors"
          aria-label="Cerrar"
        >
          <X className="w-5 h-5 text-slate-500" />
        </button>
      </div>
      <p className="text-sm text-slate-500 mb-6">
        Subí archivos Excel (.xlsx, .xlsm) para procesar y validar antes del envío formal al
        Servicio de Salud.
      </p>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <div className="flex flex-wrap gap-3 items-end">
          {/* Tipo REM */}
          <div className="w-52 shrink-0">
            <label className="text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
              <FileType className="w-3.5 h-3.5" />
              Tipo REM
            </label>
            <Controller
              name="rem_type"
              control={control}
              render={({ field }) => (
                <select
                  {...field}
                  className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option value="">Seleccionar tipo...</option>
                  {(Object.entries(REM_TYPE_LABELS) as [RemType, string][]).map(
                    ([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    )
                  )}
                </select>
              )}
            />
            {errors.rem_type && (
              <p className="text-xs text-rose-600 mt-1">{errors.rem_type.message}</p>
            )}
          </div>

          {/* Centro de Salud */}
          <div className="w-72 shrink-0">
            <label className="text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5" />
              Centro de Salud
            </label>
            <Controller
              name="health_center_id"
              control={control}
              render={({ field }) => (
                <select
                  value={field.value?.toString() ?? ''}
                  onChange={(e) =>
                    field.onChange(e.target.value ? Number(e.target.value) : undefined)
                  }
                  disabled={loadingCenters}
                  className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50"
                >
                  <option value="">
                    {loadingCenters ? 'Cargando...' : 'Seleccionar centro...'}
                  </option>
                  {healthCenters.map((hc) => (
                    <option key={hc.id} value={hc.id.toString()}>
                      {hc.name}
                    </option>
                  ))}
                </select>
              )}
            />
            {errors.health_center_id && (
              <p className="text-xs text-rose-600 mt-1">Centro requerido</p>
            )}
          </div>

          {/* Año */}
          <div className="w-28 shrink-0">
            <label className="text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
              <Calendar className="w-3.5 h-3.5" />
              Año
            </label>
            <Controller
              name="year"
              control={control}
              render={({ field }) => (
                <select
                  value={field.value?.toString()}
                  onChange={(e) => field.onChange(Number(e.target.value))}
                  className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  {YEARS.map((y) => (
                    <option key={y} value={y.toString()}>
                      {y}
                    </option>
                  ))}
                </select>
              )}
            />
          </div>

          {/* Mes */}
          <div className="w-36 shrink-0">
            <label className="text-xs font-semibold text-slate-700 uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
              <Calendar className="w-3.5 h-3.5" />
              Mes
            </label>
            <Controller
              name="month"
              control={control}
              render={({ field }) => (
                <select
                  value={field.value?.toString()}
                  onChange={(e) => field.onChange(Number(e.target.value))}
                  className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  {MONTHS.map((m) => (
                    <option key={m.value} value={m.value.toString()}>
                      {m.label}
                    </option>
                  ))}
                </select>
              )}
            />
          </div>
        </div>

        <div
          {...getRootProps()}
          className={`
            border-2 border-dashed rounded-xl p-12 text-center cursor-pointer
            transition-all
            ${
              isDragReject || fileError
                ? 'border-rose-400 bg-rose-50'
                : isDragActive
                  ? 'border-blue-500 bg-blue-50'
                  : file
                    ? 'border-emerald-400 bg-emerald-50/50'
                    : 'border-slate-300 hover:border-blue-500 bg-slate-50 hover:bg-blue-50'
            }
          `}
        >
          <input {...getInputProps()} />
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
        </div>

        {fileError && (
          <div className="flex items-start gap-2 p-3 bg-rose-50 border border-rose-200 rounded-lg">
            <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
            <p className="text-sm text-rose-800">{fileError}</p>
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={createMutation.isPending}
          >
            Cancelar
          </Button>
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
