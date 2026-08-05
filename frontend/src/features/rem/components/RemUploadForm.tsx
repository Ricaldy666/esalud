import { useState, useEffect, useCallback, useRef } from 'react'
import { X, Loader2, AlertTriangle, Info, RefreshCw } from 'lucide-react'
import {
  useCreateRemUpload,
  useRemUploadPreview,
  useRemUploadStatusPolling,
} from '../hooks/useRemUploads'
import { remUploadsService } from '../services/rem-uploads'
import { useNavigate } from 'react-router-dom'
import type { RemUpload, RemUploadPreview } from '../types/rem'
import { TERMINAL_REM_UPLOAD_STATUSES } from '../types/rem'
import { RemUploadDropzone } from './RemUploadDropzone'
import { RemUploadPreviewCard } from './RemUploadPreviewCard'
import { RemProcessingSteps } from './RemProcessingSteps'
import { RemUploadResultCard } from './RemUploadResultCard'
import type { AxiosError } from 'axios'

interface RemUploadFormProps {
  onClose: () => void
  alwaysVisible?: boolean
  healthCenterId?: number
}

export function RemUploadForm({ onClose, alwaysVisible, healthCenterId }: RemUploadFormProps) {
  const navigate = useNavigate()
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<RemUploadPreview | null>(null)
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [uploadedUpload, setUploadedUpload] = useState<RemUpload | null>(null)
  const [validationResult, setValidationResult] = useState<{
    total_rules: number
    applicable?: number
    passed: number
    failed: number
    compliance: number | null
  } | null>(null)
  const [startedProcessing, setStartedProcessing] = useState(false)
  const confirmClickedRef = useRef(false)

  const previewMutation = useRemUploadPreview()
  const createMutation = useCreateRemUpload()

  const handleFileSelected = useCallback(
    async (f: File) => {
      setSelectedFile(f)
      setPreview(null)
      setPreviewError(null)
      setUploadedUpload(null)
      setValidationResult(null)
      setStartedProcessing(false)
      confirmClickedRef.current = false

      try {
        const data = await previewMutation.mutateAsync(f)
        console.log('Preview data:', data)
        setPreview(data)
      } catch (error) {
        console.error('Preview error:', error)
        setPreview(null)
        const axiosError = error as AxiosError<{
          message?: string
          errors?: Record<string, string[]>
        }>
        setPreviewError(
          axiosError?.response?.data?.message ?? 'No fue posible analizar el archivo REM.'
        )
      }
    },
    [previewMutation]
  )

  const handleFileRemoved = useCallback(() => {
    setSelectedFile(null)
    setPreview(null)
    setPreviewError(null)
    previewMutation.reset()
  }, [previewMutation])

  const handleConfirmUpload = useCallback(() => {
    if (!selectedFile || !preview || confirmClickedRef.current) return
    confirmClickedRef.current = true
    setStartedProcessing(true)
    setSelectedFile(null)
    setPreview(null)
    setPreviewError(null)

    createMutation.mutate(
      {
        file: selectedFile,
        rem_type: (preview.serie_detected ?? 'A') as 'A',
        health_center_id: healthCenterId ?? preview.health_center_detected?.id ?? 0,
        year: preview.year_detected,
        month: preview.month_detected ?? parseInt(preview.period_detected.split('-')[1] || '1'),
      },
      {
        onSuccess: (data: RemUpload) => {
          setUploadedUpload(data)
        },
        onError: () => {
          confirmClickedRef.current = false
          setStartedProcessing(false)
          setSelectedFile(selectedFile)
          setPreview(preview)
        },
      }
    )
  }, [selectedFile, preview, healthCenterId, createMutation])

  const statusPoll = useRemUploadStatusPolling(
    uploadedUpload?.id ?? null,
    !!uploadedUpload && !TERMINAL_REM_UPLOAD_STATUSES.includes(uploadedUpload?.status ?? 'pending')
  )

  useEffect(() => {
    const statusData = statusPoll.data
    if (!statusData || !uploadedUpload) return

    if (TERMINAL_REM_UPLOAD_STATUSES.includes(statusData.status)) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- sincroniza el estado local con el resultado del polling al alcanzar un estado terminal
      setUploadedUpload((prev) => (prev ? { ...prev, status: statusData.status } : prev))
      if (statusData.validation_summary) {
        setValidationResult(statusData.validation_summary)
      } else {
        remUploadsService
          .getValidationSummary(uploadedUpload.id)
          .then((s) => setValidationResult(s))
          .catch(() => {})
      }
    }
  }, [statusPoll.data, uploadedUpload?.id])

  const isProcessing =
    !!uploadedUpload && !TERMINAL_REM_UPLOAD_STATUSES.includes(uploadedUpload.status)
  const showProcessing = startedProcessing || isProcessing

  const currentStep = statusPoll.data?.current_step ?? 'upload'
  const progress = statusPoll.data?.progress ?? 10
  const stepMessage = statusPoll.data?.message ?? 'Enviando archivo al servidor...'

  const handleViewSummary = (id: number) => {
    navigate(`/rule-engine/uploads/${id}/validation-summary`)
  }

  const handleRetryPreview = useCallback(() => {
    if (!selectedFile) return
    setPreviewError(null)
    handleFileSelected(selectedFile)
  }, [selectedFile, handleFileSelected])

  const handleUploadAnother = () => {
    setUploadedUpload(null)
    setValidationResult(null)
    setSelectedFile(null)
    setPreview(null)
    setPreviewError(null)
    setStartedProcessing(false)
    confirmClickedRef.current = false
    previewMutation.reset()
    createMutation.reset()
  }

  const showDropzone = !selectedFile && !showProcessing && !uploadedUpload
  const showPreviewLoading =
    selectedFile && !preview && previewMutation.isPending && !showProcessing
  const showPreviewCard = preview && !showProcessing && !uploadedUpload
  const showPreviewError = previewError && selectedFile && !showProcessing
  const showResultCard = uploadedUpload && !isProcessing

  return (
    <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
      <div className="flex items-center justify-between mb-2">
        <h3 className="text-lg font-bold text-slate-900">Carga de Archivos REM</h3>
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
        Seleccione un archivo Excel REM para cargar al sistema. El sistema detectará automáticamente
        la serie, período y establecimiento.
      </p>

      {showDropzone && (
        <RemUploadDropzone
          file={null}
          onFileSelected={handleFileSelected}
          onFileRemoved={handleFileRemoved}
          // eslint-disable-next-line react-hooks/refs -- confirmClickedRef solo evita doble click; el dropzone deja de renderizarse en el mismo evento que lo cambia
          disabled={previewMutation.isPending || confirmClickedRef.current}
          error={null}
        />
      )}

      {showPreviewLoading && (
        <div className="flex flex-col items-center justify-center p-12">
          <Loader2 className="w-8 h-8 text-blue-500 animate-spin mb-3" />
          <p className="text-sm text-blue-600 font-medium">Analizando archivo...</p>
        </div>
      )}

      {showPreviewError && (
        <div className="flex flex-col items-center justify-center p-12">
          <AlertTriangle className="w-10 h-10 text-red-400 mb-3" />
          <p className="text-sm text-red-600 font-medium mb-1">Error al analizar el archivo</p>
          <p className="text-xs text-red-500 mb-4 text-center max-w-md">{previewError}</p>
          <button
            type="button"
            onClick={handleRetryPreview}
            className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
          >
            <RefreshCw className="w-4 h-4" />
            Intentar nuevamente
          </button>
        </div>
      )}

      {showPreviewCard && (
        <RemUploadPreviewCard
          preview={preview!}
          healthCenterId={healthCenterId}
          onConfirm={handleConfirmUpload}
          onCancel={handleUploadAnother}
          loading={createMutation.isPending || startedProcessing}
        />
      )}

      {showProcessing && !showResultCard && (
        <div>
          <p className="text-sm text-slate-600 mb-4">
            Cargando y validando REM. Esto puede tardar unos segundos.
          </p>
          <RemProcessingSteps currentStep={currentStep} progress={progress} message={stepMessage} />
          {statusPoll.isStalled && (
            <div className="mt-4 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4">
              <Info className="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" />
              <div className="flex-1">
                <p className="text-sm font-medium text-blue-800">Procesando información</p>
                <p className="text-xs text-blue-700 mt-1">
                  El archivo fue recibido correctamente y está siendo validado por el sistema.
                  Algunas validaciones pueden tardar unos minutos dependiendo del tamaño y
                  complejidad del REM.
                </p>
                <button
                  type="button"
                  onClick={() => statusPoll.refetch()}
                  className="inline-flex items-center gap-2 mt-3 px-3 py-1.5 text-xs font-medium text-blue-800 bg-blue-100 rounded-lg hover:bg-blue-200 transition-colors"
                >
                  <RefreshCw className="w-3.5 h-3.5" />
                  Consultar estado ahora
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {showResultCard && (
        <RemUploadResultCard
          upload={uploadedUpload}
          validationSummary={validationResult}
          onViewSummary={handleViewSummary}
          onUploadAnother={handleUploadAnother}
        />
      )}
    </div>
  )
}
