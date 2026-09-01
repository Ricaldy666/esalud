import React from 'react'
import type { ValidationError } from '../types/validation'

interface FunctionalErrorDetailProps {
  error: ValidationError
}

const EMPTY_BEHAVIOR_LABELS: Record<string, string> = {
  debe_registrar_cero: 'Debe registrar 0',
  incluir: 'Incluir',
  excluir: 'Excluir',
  informativo: 'Informativo',
  no_aplica: 'No aplica',
}

function parseFila(campo: string): string {
  return campo.match(/Fila (\d+)/)?.[1] || '-'
}

function formatDate(dateStr: string | null | undefined): string {
  if (!dateStr) return '-'
  try {
    return new Date(dateStr).toLocaleDateString('es-CL', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return dateStr
  }
}

export const FunctionalErrorDetail: React.FC<FunctionalErrorDetailProps> = ({ error }) => {
  const c = error.criterio
  if (!c) return null

  const section = error.section_code || error.section || error.form
  const pendingCells = error.pending_cells ?? []
  const pendingCount = error.pending_cells_count ?? pendingCells.length

  return (
    <div className="mt-3 space-y-3 rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
      <h4 className="text-xs font-semibold uppercase tracking-wider text-indigo-700">
        Criterio funcional aplicado
      </h4>

      <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-xs">
        <Detail label="Serie" value={error.form || '-'} />
        <Detail
          label="Seccion"
          value={error.section_code ? `Sección ${error.section_code}` : section}
        />
        <Detail
          className="col-span-2"
          label="Nombre de sección"
          value={error.section_name || '-'}
        />
        <Detail label="Fila" value={error.row_number ?? parseFila(error.campo)} />
        <Detail label="Concepto" value={error.row_concept || '-'} />
        <Detail label="Profesional" value={error.row_profesional || '-'} />
        <Detail label="Total de la fila" value={error.row_total ?? '-'} />
        <Detail
          label="Decision funcional"
          value={EMPTY_BEHAVIOR_LABELS[c.empty_behavior] || c.empty_behavior}
        />
        <Detail label="Celdas pendientes" value={pendingCount} />
        <Detail className="col-span-2" label="Justificacion" value={c.justification || '-'} />
        <Detail label="Fuente" value={c.acquisition_method || '-'} />
        <Detail label="Calibrado por" value={c.informed_by || '-'} />
        <Detail label="Fecha" value={formatDate(c.informed_at)} />
        <Detail label="Estado del criterio" value={c.status || '-'} />
      </div>

      {pendingCells.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
          <div className="mb-2 flex items-center justify-between gap-3">
            <div>
              <h5 className="text-xs font-semibold text-amber-900">
                Celdas editables vacias que deben registrar 0
              </h5>
              <p className="text-[11px] text-amber-800">
                Estas son las coordenadas exactas que deben completarse en el Excel.
              </p>
            </div>
            <span className="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200">
              {pendingCount} pendiente{pendingCount === 1 ? '' : 's'}
            </span>
          </div>

          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {pendingCells.map((cell) => (
              <div
                key={cell.coordinate}
                className="rounded-md border-2 border-amber-500 bg-white px-3 py-2 shadow-sm"
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="font-mono text-sm font-bold text-amber-900">
                    {cell.coordinate}
                  </span>
                  <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                    Registrar 0
                  </span>
                </div>
                <div className="mt-1 text-xs font-medium text-slate-800">
                  {cell.label || `Columna ${cell.column}`}
                </div>
                <div className="mt-1 text-[11px] text-slate-500">
                  Editable: {cell.editable ? 'Si' : 'No'} | Bloqueada: {cell.blocked ? 'Si' : 'No'}
                  {cell.color ? ` | Color: ${cell.color}` : ''}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

function Detail({
  label,
  value,
  className = '',
}: {
  label: string
  value: React.ReactNode
  className?: string
}) {
  return (
    <div className={className}>
      <span className="text-slate-500">{label}:</span>
      <span className="ml-2 font-medium text-slate-800">{value}</span>
    </div>
  )
}
