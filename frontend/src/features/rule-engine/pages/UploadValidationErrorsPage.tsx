import React, { useState, useMemo } from 'react'
import { useParams, useSearchParams, Link } from 'react-router-dom'
import { useValidationErrors } from '../hooks/useValidationErrors'
import { useValidationSummary } from '../hooks/useValidationSummary'
import { useGroupedErrors } from '../hooks/useGroupedErrors'
import { ExecutiveSummaryCard } from '../components/ExecutiveSummaryCard'
import { ValidationErrorsTable } from '../components/ValidationErrorsTable'
import { Code, AlertTriangle, Info } from 'lucide-react'

const SEVERIDAD_OPTIONS = ['error', 'warning', 'info']

type ErrorTab = 'todas' | 'tecnico' | 'funcional'

function computeFormStatus(
  errors: import('../types/validation').ValidationError[]
): 'error' | 'warning' | 'success' | null {
  if (errors.length === 0) return 'success'
  for (const e of errors) {
    if (e.severidad === 'error') return 'error'
  }
  for (const e of errors) {
    if (e.severidad === 'warning') return 'warning'
  }
  return 'success'
}

const UploadValidationErrorsPage: React.FC = () => {
  const { uploadId } = useParams<{ uploadId: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const id = Number(uploadId)

  const formParam = searchParams.get('form') || ''
  const [form, setForm] = useState(formParam)
  const [severidad, setSeveridad] = useState('')
  const [errorTab, setErrorTab] = useState<ErrorTab>('funcional')
  const [sectionFilter, setSectionFilter] = useState('')

  const filters = useMemo(
    () =>
      ({
        ...(form ? { form } : {}),
        ...(severidad ? { severidad } : {}),
        ...(errorTab !== 'todas' ? { tipo_error: errorTab } : {}),
      }) as import('../types/validation').ValidationErrorFilters,
    [form, severidad, errorTab]
  )

  const { data: errors, loading, error } = useValidationErrors(id, filters)
  const { data: summary } = useValidationSummary(id)
  const { groups, summary: execSummary } = useGroupedErrors(errors)

  const formOptions = useMemo(
    () =>
      (summary?.por_formulario ?? []).map((f) => f.form).sort((a, b) => a.localeCompare(b, 'es')),
    [summary]
  )

  const formSummary = summary?.por_formulario?.find((fs) => fs.form === formParam)
  const summaryPassed = formSummary?.passed ?? summary?.passed ?? 0
  const summaryTotal = formSummary
    ? formSummary.passed + formSummary.failed
    : (summary?.total_rules ?? 0)

  const title = formParam ? `Errores del formulario ${formParam}` : 'Errores de Validación'

  const tecnicoErrors = useMemo(() => errors.filter((e) => e.tipo_error === 'tecnico'), [errors])
  const funcionalErrors = useMemo(
    () => errors.filter((e) => e.tipo_error === 'funcional'),
    [errors]
  )
  const sectionOptions = useMemo(() => {
    const map = new Map<string, { code: string; name: string; order: number }>()
    for (const e of funcionalErrors) {
      if (!e.section_code) continue
      map.set(e.section_code, {
        code: e.section_code,
        name: e.section_name || e.section_code,
        order: e.section_order ?? Number.MAX_SAFE_INTEGER,
      })
    }

    return Array.from(map.values()).sort(
      (a, b) => a.order - b.order || a.code.localeCompare(b.code, 'es')
    )
  }, [funcionalErrors])

  const visibleErrors = useMemo(() => {
    if (errorTab !== 'funcional' || !sectionFilter) return errors
    return errors.filter((e) => e.tipo_error !== 'funcional' || e.section_code === sectionFilter)
  }, [errors, errorTab, sectionFilter])

  const tabs: { key: ErrorTab; label: string; count: number; icon: React.ReactNode }[] = [
    { key: 'todas', label: 'Todas', count: errors.length, icon: <Info className="w-3.5 h-3.5" /> },
    {
      key: 'tecnico',
      label: 'Técnicas',
      count: tecnicoErrors.length,
      icon: <Code className="w-3.5 h-3.5" />,
    },
    {
      key: 'funcional',
      label: 'Funcionales',
      count: funcionalErrors.length,
      icon: <AlertTriangle className="w-3.5 h-3.5" />,
    },
  ]

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-900">{title}</h1>
          <p className="text-sm text-gray-500">Upload #{uploadId}</p>
        </div>
        <div className="flex items-center gap-3">
          <Link
            to={`/rule-engine/uploads/${id}/validation-summary`}
            className="text-sm text-indigo-600 hover:text-indigo-800 underline"
          >
            Volver al resumen
          </Link>
        </div>
      </div>

      <div className="bg-white rounded-lg border p-4">
        <div className="flex flex-wrap gap-4 items-end">
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">Formulario</label>
            <select
              value={form}
              onChange={(e) => setForm(e.target.value)}
              className="border rounded px-2 py-1 text-sm min-w-32"
            >
              <option value="">Todos</option>
              {formOptions.map((f) => (
                <option key={f} value={f}>
                  {f}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">Severidad</label>
            <select
              value={severidad}
              onChange={(e) => setSeveridad(e.target.value)}
              className="border rounded px-2 py-1 text-sm"
            >
              <option value="">Todas</option>
              {SEVERIDAD_OPTIONS.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </div>
          <button
            onClick={() => {
              setForm('')
              setSeveridad('')
              setErrorTab('todas')
              setSectionFilter('')
              setSearchParams({})
            }}
            className="text-xs text-gray-500 hover:text-gray-700 border rounded px-2 py-1"
          >
            Limpiar filtros
          </button>
        </div>
      </div>

      {/* Tabs por tipo de error */}
      <div className="flex gap-1 border-b border-gray-200">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setErrorTab(tab.key)}
            className={`flex items-center gap-1.5 px-4 py-2 text-xs font-medium border-b-2 transition-colors ${
              errorTab === tab.key
                ? 'border-indigo-600 text-indigo-700'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            {tab.icon}
            {tab.label}
            <span
              className={`ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold ${
                errorTab === tab.key ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'
              }`}
            >
              {tab.count}
            </span>
          </button>
        ))}
      </div>

      {errorTab === 'funcional' && sectionOptions.length > 0 && (
        <div className="bg-white rounded-lg border p-4">
          <label className="block text-xs font-medium text-gray-600 mb-1">Sección REM</label>
          <select
            value={sectionFilter}
            onChange={(e) => setSectionFilter(e.target.value)}
            className="border rounded px-2 py-1 text-sm min-w-52"
          >
            <option value="">Todas las secciones</option>
            {sectionOptions.map((section) => (
              <option key={section.code} value={section.code}>
                Sección {section.code}
              </option>
            ))}
          </select>
        </div>
      )}

      {loading ? (
        <div className="text-sm text-gray-500">Cargando errores...</div>
      ) : error ? (
        <div className="text-sm text-red-600">Error: {error}</div>
      ) : (
        <div className="space-y-4">
          {summary && (
            <ExecutiveSummaryCard
              summary={execSummary}
              passed={summaryPassed}
              total={summaryTotal}
              uploadId={id}
              formFilter={formParam || undefined}
              formStatus={computeFormStatus(errors)}
            />
          )}

          <div className="flex items-center gap-4 text-xs">
            <span className="text-gray-500 font-medium">
              {visibleErrors.length} inconsistencia{visibleErrors.length !== 1 ? 's' : ''}
            </span>
            {errorTab === 'todas' && tecnicoErrors.length > 0 && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
                <Code className="w-3 h-3" />
                {tecnicoErrors.length} técnica{tecnicoErrors.length !== 1 ? 's' : ''}
              </span>
            )}
            {errorTab === 'todas' && funcionalErrors.length > 0 && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
                <AlertTriangle className="w-3 h-3" />
                {funcionalErrors.length} funcional{funcionalErrors.length !== 1 ? 'es' : ''}
              </span>
            )}
            {(errorTab === 'tecnico' || errorTab === 'funcional') && (
              <span className="text-gray-400">{groups.length} grupo(s)</span>
            )}
          </div>

          <div className="bg-white rounded-lg border p-4">
            <ValidationErrorsTable
              errors={visibleErrors}
              groupFunctionalBySection={errorTab === 'funcional'}
            />
          </div>
        </div>
      )}
    </div>
  )
}

export default UploadValidationErrorsPage
