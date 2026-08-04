import React, { useMemo, useState } from 'react'
import type { ValidationError } from '../types/validation'
import { SeverityBadge } from './SeverityBadge'
import { getErrorTypeShort, getRuleTypeLabel } from '../utils/labels'
import { FunctionalErrorDetail } from './FunctionalErrorDetail'
import { TechnicalInfo } from './TechnicalInfo'
import { ChevronDown, ChevronRight, AlertTriangle, Code } from 'lucide-react'

interface Props {
  errors: ValidationError[]
  groupFunctionalBySection?: boolean
}

const typeStyles: Record<string, { dot: string; bg: string; text: string }> = {
  tecnico: { dot: 'bg-blue-500', bg: 'bg-blue-100', text: 'text-blue-700' },
  funcional: { dot: 'bg-indigo-500', bg: 'bg-indigo-100', text: 'text-indigo-700' },
}

interface SectionGroup {
  code: string
  name: string
  order: number
  errors: ValidationError[]
}

function buildSectionGroups(errors: ValidationError[]): SectionGroup[] {
  const map = new Map<string, SectionGroup>()

  for (const e of errors) {
    const code = e.section_code || e.section || 'sin-seccion'
    const name = e.section_name || e.section_code || e.section || 'Sin sección asignada'
    const order = e.section_order ?? Number.MAX_SAFE_INTEGER

    if (!map.has(code)) {
      map.set(code, { code, name, order, errors: [] })
    }
    map.get(code)!.errors.push(e)
  }

  const groups = Array.from(map.values())

  for (const group of groups) {
    group.errors.sort((a, b) => {
      const rowA = a.row_number ?? Number.MAX_SAFE_INTEGER
      const rowB = b.row_number ?? Number.MAX_SAFE_INTEGER
      if (rowA !== rowB) return rowA - rowB
      return a.campo.localeCompare(b.campo, 'es')
    })
  }

  return groups.sort((a, b) => a.order - b.order || a.code.localeCompare(b.code, 'es'))
}

export const ValidationErrorsTable: React.FC<Props> = ({ errors, groupFunctionalBySection }) => {
  if (errors.length === 0) {
    return (
      <div className="py-8 text-center text-sm text-gray-400">No hay advertencias que mostrar.</div>
    )
  }

  if (groupFunctionalBySection) {
    return <GroupedFunctionalView errors={errors} />
  }

  return <FlatErrorList errors={errors} />
}

const GroupedFunctionalView: React.FC<{ errors: ValidationError[] }> = ({ errors }) => {
  const groups = useMemo(() => buildSectionGroups(errors), [errors])

  return (
    <div className="space-y-3">
      {groups.map((group) => (
        <SectionGroupCard key={group.code} group={group} />
      ))}
    </div>
  )
}

const SectionGroupCard: React.FC<{ group: SectionGroup }> = ({ group }) => {
  const [open, setOpen] = useState(true)

  return (
    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
      <button
        onClick={() => setOpen(!open)}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50"
      >
        <div className="flex min-w-0 items-center gap-2">
          {open ? (
            <ChevronDown className="h-4 w-4 shrink-0 text-gray-400" />
          ) : (
            <ChevronRight className="h-4 w-4 shrink-0 text-gray-400" />
          )}
          <span className="shrink-0 font-mono text-xs font-semibold text-gray-500">
            Sección {group.code}
          </span>
          <span className="truncate text-sm font-medium text-gray-800">{group.name}</span>
        </div>
        <span className="shrink-0 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">
          {group.errors.length} advertencia{group.errors.length !== 1 ? 's' : ''}
        </span>
      </button>

      {open && (
        <div className="divide-y divide-gray-100 border-t border-gray-100">
          {group.errors.map((error) => (
            <FunctionalWarningCard key={error.id} error={error} />
          ))}
        </div>
      )}
    </div>
  )
}

const FunctionalWarningCard: React.FC<{ error: ValidationError }> = ({ error }) => {
  return (
    <div className="p-4">
      <div className="mb-1 flex flex-wrap items-center gap-2">
        <SeverityBadge severity={error.severidad} />
        <span className="text-xs text-gray-400">Fila {error.row_number ?? '—'}</span>
      </div>

      <p className="text-sm text-gray-800">{error.mensaje}</p>
      {error.recomendacion && <p className="mt-0.5 text-xs text-gray-500">{error.recomendacion}</p>}

      {error.criterio ? (
        <FunctionalErrorDetail error={error} />
      ) : (
        <p className="mt-2 text-xs text-amber-600">
          Esta advertencia aún no tiene un criterio funcional calibrado.
        </p>
      )}

      <div className="mt-2">
        <TechnicalInfo title="Información técnica">
          <div className="space-y-1 p-3 text-xs text-gray-600">
            <div>
              Tipo de regla:{' '}
              <span className="font-medium text-gray-800">{getRuleTypeLabel(error.tipo)}</span>
            </div>
            <div>
              Campo: <span className="font-medium text-gray-800">{error.campo}</span>
            </div>
            <div>
              Columna: <span className="font-medium text-gray-800">{error.columna}</span>
            </div>
          </div>
        </TechnicalInfo>
      </div>
    </div>
  )
}

const FlatErrorList: React.FC<{ errors: ValidationError[] }> = ({ errors }) => {
  return (
    <div className="divide-y divide-gray-100">
      {errors.map((error) => (
        <FlatErrorRow key={error.id} error={error} />
      ))}
    </div>
  )
}

const FlatErrorRow: React.FC<{ error: ValidationError }> = ({ error }) => {
  const [open, setOpen] = useState(false)
  const style = typeStyles[error.tipo_error] ?? typeStyles.tecnico
  const TypeIcon = error.tipo_error === 'funcional' ? AlertTriangle : Code

  return (
    <div>
      <button
        onClick={() => setOpen(!open)}
        className="flex w-full items-start gap-3 px-3 py-3 text-left transition-colors hover:bg-gray-50"
      >
        {open ? (
          <ChevronDown className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
        ) : (
          <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
        )}
        <div className="min-w-0 flex-1">
          <div className="mb-1 flex flex-wrap items-center gap-2">
            <SeverityBadge severity={error.severidad} />
            <span
              className={`inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium ${style.bg} ${style.text}`}
            >
              <TypeIcon className="h-3 w-3" />
              {getErrorTypeShort(error.tipo_error)}
            </span>
            <span className="text-xs text-gray-400">
              {error.form} · {error.section}
            </span>
          </div>
          <p className="text-sm text-gray-800">{error.mensaje}</p>
          <p className="mt-0.5 text-xs text-gray-500">
            {error.filas_afectadas}/{error.filas_totales} fila(s) afectada(s)
          </p>
        </div>
      </button>

      {open && (
        <div className="space-y-2 px-3 pb-4 pl-10">
          {error.recomendacion && (
            <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
              {error.recomendacion}
            </div>
          )}

          <TechnicalInfo title="Información técnica">
            <div className="space-y-1 p-3 text-xs text-gray-600">
              <div>
                Campo: <span className="font-medium text-gray-800">{error.campo}</span>
              </div>
              <div>
                Columna: <span className="font-medium text-gray-800">{error.columna}</span>
              </div>
              <div>
                Tipo de regla:{' '}
                <span className="font-medium text-gray-800">{getRuleTypeLabel(error.tipo)}</span>
              </div>
            </div>
          </TechnicalInfo>

          {error.tipo_error === 'funcional' && error.criterio && (
            <FunctionalErrorDetail error={error} />
          )}
        </div>
      )}
    </div>
  )
}
