import { useCallback, useState } from 'react'
import { useDropzone, type FileRejection } from 'react-dropzone'
import { FileSpreadsheet, CheckCircle2, AlertCircle } from 'lucide-react'

interface RemUploadDropzoneProps {
  onFileSelected: (file: File) => void
  onFileRemoved: () => void
  file: File | null
  disabled: boolean
  error?: string | null
}

export function RemUploadDropzone({
  onFileSelected,
  onFileRemoved,
  file,
  disabled,
  error,
}: RemUploadDropzoneProps) {
  const [fileError, setFileError] = useState<string | null>(null)

  const onDrop = useCallback(
    (acceptedFiles: File[], rejectedFiles: FileRejection[]) => {
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
        onFileSelected(acceptedFiles[0])
      }
    },
    [onFileSelected]
  )

  const { getRootProps, getInputProps, isDragActive, isDragReject } = useDropzone({
    onDrop,
    accept: {
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
      'application/vnd.ms-excel.sheet.macroenabled.12': ['.xlsm'],
      'application/vnd.ms-excel': ['.xls'],
    },
    maxSize: 10 * 1024 * 1024,
    multiple: false,
    disabled,
  })

  const displayError = error || fileError

  return (
    <div
      {...getRootProps()}
      className={`
        border-2 border-dashed rounded-xl p-10 text-center cursor-pointer transition-all
        ${disabled ? 'opacity-50 cursor-not-allowed' : ''}
        ${isDragReject || displayError ? 'border-rose-400 bg-rose-50' : ''}
        ${isDragActive && !isDragReject ? 'border-blue-500 bg-blue-50' : ''}
        ${!isDragActive && !isDragReject && !displayError && file ? 'border-emerald-400 bg-emerald-50/50' : ''}
        ${!isDragActive && !isDragReject && !displayError && !file ? 'border-slate-300 hover:border-blue-500 bg-slate-50 hover:bg-blue-50' : ''}
      `}
    >
      <input {...getInputProps()} />
      <div className="flex flex-col items-center gap-3">
        <div
          className={`p-4 shadow-sm rounded-full border ${file ? 'bg-emerald-100 border-emerald-200' : 'bg-white border-slate-200'}`}
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
            <p className="text-xs text-emerald-700">{(file.size / 1024 / 1024).toFixed(2)} MB</p>
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation()
                onFileRemoved()
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
                'Suelta el archivo aquí'
              ) : (
                <>
                  Arrastra el archivo Excel aquí o{' '}
                  <span className="text-blue-600 underline">selecciónalo en tu PC</span>
                </>
              )}
            </p>
            <p className="text-xs text-slate-400">Solo .xlsx, .xlsm, .xls (Máx. 10MB)</p>
          </>
        )}
      </div>

      {displayError && (
        <div className="flex items-start gap-2 p-3 mt-4 bg-rose-50 border border-rose-200 rounded-lg">
          <AlertCircle className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
          <p className="text-sm text-rose-800">{displayError}</p>
        </div>
      )}
    </div>
  )
}
