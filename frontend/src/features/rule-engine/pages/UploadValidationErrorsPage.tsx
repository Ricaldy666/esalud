import React, { useState, useMemo } from 'react'
import { useParams, useSearchParams, Link } from 'react-router-dom'
import { useValidationErrors } from '../hooks/useValidationErrors'
import { useValidationSummary } from '../hooks/useValidationSummary'
import { useGroupedErrors } from '../hooks/useGroupedErrors'
import { ExecutiveSummaryCard } from '../components/ExecutiveSummaryCard'
import { ValidationErrorsTable } from '../components/ValidationErrorsTable'
import { Label } from '@/shared/components/ui/label'
import { Button } from '@/shared/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import { Code, AlertTriangle, Info, AlertCircle } from 'lucide-react'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { EmptyState } from '@/shared/components/EmptyState'
import { PageHeader } from '@/shared/components/PageHeader'

const SEVERIDAD_OPTIONS = ['error', 'warning', 'info']

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const LABEL_CLASS = 'text-xs text-slate-500 mb-1 block'

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

  const { data: errors, loading, error, refetch } = useValidationErrors(id, filters)
  // "Sin datos disponibles" = todavia no llego ninguna respuesta exitosa
  // (ni siquiera de una carga previa) -- distinto de "cargando de nuevo"
  // tras cambiar un filtro, donde ya hay datos previos en pantalla.
  const hasNoDataYet = loading && errors.length === 0
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
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title={title}
        description={`Upload #${uploadId}`}
        actions={
          <Link
            to={`/rule-engine/uploads/${id}/validation-summary`}
            className="text-sm text-indigo-600 hover:text-indigo-800 underline"
          >
            Volver al resumen
          </Link>
        }
      />

      <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
        <div className="flex flex-wrap gap-4 items-end">
          <div className="w-40">
            <Label className={LABEL_CLASS}>Formulario</Label>
            <Select
              value={form || 'all'}
              onValueChange={(v: string | null) => setForm(v && v !== 'all' ? v : '')}
              disabled={hasNoDataYet}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todos
                </SelectItem>
                {formOptions.map((f) => (
                  <SelectItem key={f} value={f} className={SELECT_ITEM_CLASS}>
                    {f}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="w-36">
            <Label className={LABEL_CLASS}>Severidad</Label>
            <Select
              value={severidad || 'all'}
              onValueChange={(v: string | null) => setSeveridad(v && v !== 'all' ? v : '')}
              disabled={hasNoDataYet}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todas
                </SelectItem>
                {SEVERIDAD_OPTIONS.map((s) => (
                  <SelectItem key={s} value={s} className={SELECT_ITEM_CLASS}>
                    {s}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button
            variant="outline"
            disabled={hasNoDataYet}
            onClick={() => {
              setForm('')
              setSeveridad('')
              setErrorTab('todas')
              setSectionFilter('')
              setSearchParams({})
            }}
            className="border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900"
          >
            Limpiar filtros
          </Button>
        </div>
      </div>

      {/* Tabs por tipo de error */}
      <div className="flex gap-1 border-b border-slate-200">
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setErrorTab(tab.key)}
            disabled={hasNoDataYet}
            className={`flex items-center gap-1.5 px-4 py-2 text-xs font-medium border-b-2 transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
              errorTab === tab.key
                ? 'border-indigo-600 text-indigo-700'
                : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
            }`}
          >
            {tab.icon}
            {tab.label}
            <span
              className={`ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold ${
                errorTab === tab.key
                  ? 'bg-indigo-100 text-indigo-700'
                  : 'bg-slate-100 text-slate-600'
              }`}
            >
              {tab.count}
            </span>
          </button>
        ))}
      </div>

      {errorTab === 'funcional' && sectionOptions.length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <Label className={LABEL_CLASS}>Sección REM</Label>
          <div className="w-64">
            <Select
              value={sectionFilter || 'all'}
              onValueChange={(v: string | null) => setSectionFilter(v && v !== 'all' ? v : '')}
              disabled={hasNoDataYet}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todas las secciones" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                <SelectItem value="all" className={SELECT_ITEM_CLASS}>
                  Todas las secciones
                </SelectItem>
                {sectionOptions.map((section) => (
                  <SelectItem key={section.code} value={section.code} className={SELECT_ITEM_CLASS}>
                    Sección {section.code}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      )}

      {loading ? (
        <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
          <p className="text-sm font-semibold text-slate-700">Cargando resultados</p>
          <p className="mt-1 text-xs text-slate-500">
            El sistema está consultando y organizando las observaciones del formulario. Esto puede
            tardar unos segundos.
          </p>
          <div className="mt-4 space-y-3">
            <Skeleton className="h-5 w-2/3" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-5/6" />
          </div>
        </div>
      ) : error ? (
        <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
          <EmptyState
            icon={<AlertCircle className="h-10 w-10 text-red-400" />}
            title="No se pudieron cargar los resultados"
            description={error}
            action={
              <Button variant="outline" onClick={refetch}>
                Reintentar
              </Button>
            }
          />
        </div>
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
            <span className="text-slate-500 font-medium">
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
              <span className="text-slate-400">{groups.length} grupo(s)</span>
            )}
          </div>

          <div className="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
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
