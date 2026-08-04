import { CheckCircle2, Loader2, Clock } from 'lucide-react'
import { PROCESSING_STEPS, type ProcessingStep } from '../types/rem'

interface RemProcessingStepsProps {
  currentStep: ProcessingStep
  progress: number
  message: string
}

export function RemProcessingSteps({ currentStep, progress, message }: RemProcessingStepsProps) {
  const currentIndex = PROCESSING_STEPS.findIndex((s) => s.key === currentStep)

  return (
    <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
      <h3 className="text-lg font-bold text-slate-900 mb-4">Procesando archivo REM</h3>

      <div className="mb-4">
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm text-slate-500">Progreso general</span>
          <span className="text-sm font-medium text-blue-600">{progress}%</span>
        </div>
        <div className="w-full bg-slate-200 rounded-full h-2">
          <div
            className="bg-blue-600 h-2 rounded-full transition-all duration-500 ease-out"
            style={{ width: `${progress}%` }}
          />
        </div>
      </div>

      <p className="text-sm text-slate-600 mb-4 flex items-center gap-2">
        <Loader2 className="w-4 h-4 text-blue-500 animate-spin" />
        {message}
      </p>

      <div className="space-y-3">
        {PROCESSING_STEPS.map((step, index) => {
          const isCompleted = index < currentIndex
          const isCurrent = index === currentIndex

          return (
            <div key={step.key} className="flex items-center gap-3">
              <div className="flex-shrink-0 w-8 h-8 flex items-center justify-center">
                {isCompleted ? (
                  <div className="w-7 h-7 rounded-full bg-emerald-100 flex items-center justify-center">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                  </div>
                ) : isCurrent ? (
                  <div className="w-7 h-7 rounded-full bg-blue-100 flex items-center justify-center">
                    <Loader2 className="w-4 h-4 text-blue-600 animate-spin" />
                  </div>
                ) : (
                  <div className="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center">
                    <Clock className="w-4 h-4 text-slate-400" />
                  </div>
                )}
              </div>
              <div className="flex-1 min-w-0">
                <p
                  className={`text-sm font-medium ${isCompleted ? 'text-emerald-700' : isCurrent ? 'text-blue-700' : 'text-slate-400'}`}
                >
                  {step.label}
                </p>
                <p
                  className={`text-xs ${isCompleted ? 'text-emerald-600' : isCurrent ? 'text-blue-500' : 'text-slate-400'}`}
                >
                  {isCompleted ? 'Completado' : isCurrent ? step.description : 'Pendiente'}
                </p>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
