import { useEffect, useState } from 'react'
import { AlertTriangle, Loader2, Save, ShieldAlert } from 'lucide-react'
import { toast } from 'sonner'
import { PageHeader } from '@/shared/components/PageHeader'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { useFeatureFlagConfig } from '../hooks/useFeatureFlagConfig'
import { useUpdateFeatureFlagConfig } from '../hooks/useUpdateFeatureFlagConfig'
import { HelpTooltip } from '../components/HelpTooltip'
import { TechnicalInfo } from '../components/TechnicalInfo'
import type { FeatureFlagConfig } from '../types/feature-flag'
import { getHelpText, MODE_LABELS, LOG_MODE_LABELS } from '../utils/labels'

export default function FeatureFlagsPage() {
  const { data: config, isLoading, isError } = useFeatureFlagConfig()
  const updateConfig = useUpdateFeatureFlagConfig()
  const { isAdmin } = usePermissions()

  const [form, setForm] = useState<FeatureFlagConfig>({
    enabled: false,
    mode: 'disabled',
    fail_open: false,
    log_mode: 'all',
  })
  const [dirty, setDirty] = useState(false)

  useEffect(() => {
    if (config) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- sincroniza el form local con la config recien cargada del servidor
      setForm(config)
    }
  }, [config])

  const handleChange = <K extends keyof FeatureFlagConfig>(key: K, value: FeatureFlagConfig[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }))
    setDirty(true)
  }

  const handleSubmit = async () => {
    try {
      await updateConfig.mutateAsync(form)
      toast.success('Configuración actualizada correctamente')
      setDirty(false)
    } catch {
      toast.error('Error al actualizar la configuración')
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
      </div>
    )
  }

  if (isError || !config) {
    return (
      <div>
        <PageHeader title="Configuración del Motor de Reglas" />
        <div className="flex items-center gap-3 mt-8 p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          <AlertTriangle className="w-6 h-6 shrink-0" />
          <div>
            <p className="font-semibold">Error al cargar configuración</p>
            <p className="text-sm mt-1">Verifica que el backend esté disponible.</p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <PageHeader title="Configuración del Motor de Reglas" />
        <HelpTooltip text={getHelpText('config') ?? ''} />
      </div>

      {!isAdmin && (
        <div className="flex items-center gap-2 p-3 bg-slate-50 border border-slate-200 rounded-lg text-slate-500 text-sm">
          <ShieldAlert className="w-4 h-4" />
          Solo el Administrador puede modificar la configuración
        </div>
      )}

      <div className="rounded-xl border border-slate-200 bg-white p-6 space-y-6">
        {/* Enabled */}
        <div className="flex items-center justify-between">
          <div className="flex items-start gap-1.5">
            <div>
              <p className="text-sm font-medium text-slate-900">Motor habilitado</p>
              <p className="text-xs text-slate-500 mt-0.5">
                Habilita o deshabilita la ejecución del motor de reglas
              </p>
            </div>
            <HelpTooltip text="Controla si el motor de reglas está activo para validar los archivos REM." />
          </div>
          <button
            type="button"
            disabled={!isAdmin}
            onClick={() => handleChange('enabled', !form.enabled)}
            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
              form.enabled ? 'bg-emerald-500' : 'bg-slate-300'
            }`}
          >
            <span
              className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform ${
                form.enabled ? 'translate-x-5' : 'translate-x-0'
              }`}
            />
          </button>
        </div>

        <hr className="border-slate-100" />

        {/* Mode */}
        <div>
          <div className="flex items-center gap-1.5 mb-1.5">
            <label className="block text-sm font-medium text-slate-900">Modo de ejecución</label>
            <HelpTooltip text="Define cómo se ejecuta el motor de reglas en relación al proceso actual." />
          </div>
          <p className="text-xs text-slate-500 mb-2">
            Controla la interacción del motor con el proceso de validación existente
          </p>
          <select
            value={form.mode}
            onChange={(e) => handleChange('mode', e.target.value as FeatureFlagConfig['mode'])}
            disabled={!isAdmin}
            className="block w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400 disabled:cursor-not-allowed"
          >
            {Object.entries(MODE_LABELS).map(([value, opt]) => (
              <option key={value} value={value}>
                {opt.label}
              </option>
            ))}
          </select>
          <p className="text-xs text-slate-400 mt-1">{MODE_LABELS[form.mode]?.description ?? ''}</p>
        </div>

        <hr className="border-slate-100" />

        {/* Fail Open */}
        <div className="flex items-center justify-between">
          <div className="flex items-start gap-1.5">
            <div>
              <p className="text-sm font-medium text-slate-900">Si ocurre un error interno</p>
              <p className="text-xs text-slate-500 mt-0.5">
                Define si el proceso continúa o se detiene cuando el motor presenta un error
              </p>
            </div>
            <HelpTooltip text="Si se activa, el proceso continúa aunque el motor tenga errores. Si se desactiva, el proceso se detiene." />
          </div>
          <button
            type="button"
            disabled={!isAdmin}
            onClick={() => handleChange('fail_open', !form.fail_open)}
            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed ${
              form.fail_open ? 'bg-emerald-500' : 'bg-slate-300'
            }`}
          >
            <span
              className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform ${
                form.fail_open ? 'translate-x-5' : 'translate-x-0'
              }`}
            />
          </button>
        </div>

        <div className="flex items-center gap-3">
          <span
            className={`text-xs font-medium ${form.fail_open ? 'text-emerald-600' : 'text-slate-500'}`}
          >
            ☑ Continuar validación
          </span>
          <span className="text-xs text-slate-300">|</span>
          <span
            className={`text-xs font-medium ${!form.fail_open ? 'text-rose-600' : 'text-slate-500'}`}
          >
            ☐ Detener proceso
          </span>
        </div>

        <hr className="border-slate-100" />

        {/* Log Mode */}
        <div>
          <div className="flex items-center gap-1.5 mb-1.5">
            <label className="block text-sm font-medium text-slate-900">Nivel de registro</label>
            <HelpTooltip text="Controla la cantidad de información que se guarda en el historial de ejecución." />
          </div>
          <p className="text-xs text-slate-500 mb-2">
            Define qué información se registra durante la ejecución del motor
          </p>
          <select
            value={form.log_mode}
            onChange={(e) =>
              handleChange('log_mode', e.target.value as FeatureFlagConfig['log_mode'])
            }
            disabled={!isAdmin}
            className="block w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400 disabled:cursor-not-allowed"
          >
            {Object.entries(LOG_MODE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
      </div>

      {isAdmin && (
        <div className="flex justify-end">
          <button
            onClick={handleSubmit}
            disabled={!dirty || updateConfig.isPending}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {updateConfig.isPending ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            Guardar cambios
          </button>
        </div>
      )}

      <TechnicalInfo title="Valores actuales">
        <div className="p-4">
          <div className="flex flex-wrap gap-4">
            {(
              [
                { key: 'enabled', label: 'Motor habilitado', value: form.enabled ? 'Sí' : 'No' },
                {
                  key: 'mode',
                  label: 'Modo de ejecución',
                  value: MODE_LABELS[form.mode]?.label ?? form.mode,
                },
                {
                  key: 'fail_open',
                  label: 'Continuar en caso de error',
                  value: form.fail_open ? 'Sí' : 'No',
                },
                {
                  key: 'log_mode',
                  label: 'Nivel de registro',
                  value: LOG_MODE_LABELS[form.log_mode] ?? form.log_mode,
                },
              ] as const
            ).map(({ key, label, value }) => (
              <div key={key} className="flex items-center gap-2">
                <span className="text-xs text-gray-500">{label}:</span>
                <span
                  className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${
                    value === 'Sí'
                      ? 'bg-emerald-100 text-emerald-700'
                      : key === 'mode' || key === 'log_mode'
                        ? 'bg-blue-100 text-blue-700'
                        : 'bg-slate-100 text-slate-500'
                  }`}
                >
                  {value}
                </span>
              </div>
            ))}
          </div>
        </div>
      </TechnicalInfo>
    </div>
  )
}
