import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { AlertTriangle, Save } from 'lucide-react'
import { toast } from 'sonner'
import { useAuthStore } from '@/app/store/authStore'
import { DataTable } from '@/shared/components/DataTable'
import { calibrationService } from '../../services/calibration'
import type { RowFunctionalDecision } from '../../types/calibration'

type EditableDecision =
  | 'debe_registrar_cero'
  | 'puede_quedar_vacio'
  | 'heredar_patron'
  | 'heredar_seccion'
  | 'requiere_revision'

interface Props {
  sheet: string
  section: string
  readOnly?: boolean
}

const DECISION_LABELS: Record<string, string> = {
  debe_registrar_cero: 'Debe registrar 0',
  puede_quedar_vacio: 'Puede quedar vacio',
  pendiente_definicion: 'Requiere revision',
  heredar_patron: 'Heredar del patron',
  heredar_seccion: 'Heredar de la seccion',
  requiere_revision: 'Requiere revision',
}

const ORIGIN_LABELS: Record<string, string> = {
  row: 'Fila',
  pattern: 'Patron',
  section: 'Seccion',
  none: 'Sin decision',
}

function label(value?: string | null) {
  if (!value) return 'Sin decision'
  return DECISION_LABELS[value] ?? value
}

function effect(value: EditableDecision) {
  if (value === 'debe_registrar_cero') return 'exigira registrar 0 cuando no existan prestaciones'
  if (value === 'puede_quedar_vacio') return 'podra quedar vacia'
  if (value === 'requiere_revision') return 'quedara pendiente de revision'
  return 'heredara el criterio funcional'
}

function defaultSelection(row: RowFunctionalDecision): EditableDecision {
  if (
    row.explicit_decision === 'debe_registrar_cero' ||
    row.explicit_decision === 'puede_quedar_vacio'
  ) {
    return row.explicit_decision
  }
  if (row.explicit_decision === 'pendiente_definicion') return 'requiere_revision'
  if (row.origin === 'pattern') return 'heredar_patron'
  if (row.origin === 'section') return 'heredar_seccion'
  return 'requiere_revision'
}

function reviewedText(row: RowFunctionalDecision) {
  const user = row.reviewed_by?.trim()
  const date = row.reviewed_at ? new Date(row.reviewed_at).toLocaleString('es-CL') : ''
  if (user && date) return `${user} - ${date}`
  return user || date || '-'
}

function inheritedText(row: RowFunctionalDecision) {
  if (row.has_explicit_decision) return 'Decision propia'
  if (!row.inherited_decision) return 'Sin decision heredada'
  if (row.source_row) return `Hereda desde fila ${row.source_row}`
  if (row.origin === 'pattern') return 'Hereda desde el patron'
  if (row.origin === 'section') return 'Hereda desde la seccion'
  return 'Sin decision heredada'
}

export default function RowFunctionalDecisionTable({ sheet, section, readOnly = false }: Props) {
  const queryClient = useQueryClient()
  const user = useAuthStore((state) => state.user)
  const [drafts, setDrafts] = useState<Record<number, EditableDecision>>({})

  const { data, isLoading } = useQuery({
    queryKey: ['row-functional-decisions', sheet, section],
    queryFn: () => calibrationService.getRowFunctionalDecisions(sheet, section),
    enabled: Boolean(sheet) && Boolean(section),
  })

  const rows = useMemo(() => [...(data?.rows ?? [])].sort((a, b) => a.row - b.row), [data?.rows])
  const inconsistentCount = useMemo(
    () => rows.filter((row) => row.possible_inconsistency).length,
    [rows]
  )

  const saveMutation = useMutation({
    mutationFn: ({ row, decision }: { row: RowFunctionalDecision; decision: EditableDecision }) => {
      const payload: Record<string, unknown> = {
        empty_behavior: decision,
        updated_by: user?.name ?? 'Usuario funcional',
        informed_by: user?.name ?? 'Usuario funcional',
      }

      if (decision === 'debe_registrar_cero') {
        payload.status = 'aprobada'
        payload.functional_condition = 'Debe registrar 0 segun criterio de Estadistica'
      } else if (decision === 'puede_quedar_vacio') {
        payload.status = 'aprobada'
        payload.functional_condition = 'Puede quedar vacia segun criterio de Estadistica'
      } else if (decision === 'requiere_revision') {
        payload.status = 'propuesta'
        payload.functional_condition = 'Requiere revision de Estadistica'
      }

      return calibrationService.saveRowFunctionalRule(sheet, section, row.row, payload)
    },
    onSuccess: (_data, variables) => {
      toast.success('Decision funcional guardada')
      setDrafts((current) => {
        const next = { ...current }
        delete next[variables.row.row]
        return next
      })
      queryClient.invalidateQueries({ queryKey: ['row-functional-decisions', sheet, section] })
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', sheet, section] })
      queryClient.invalidateQueries({ queryKey: ['calibration-matrix'] })
    },
    onError: () => toast.error('No se pudo guardar la decision funcional'),
  })

  const handleSave = (row: RowFunctionalDecision) => {
    const decision = drafts[row.row] ?? defaultSelection(row)
    const ok = window.confirm(
      [
        `Fila ${row.row}: ${row.concept} / ${row.professional}`,
        `Decision actual: ${label(row.effective_decision)}`,
        `Nueva decision: ${label(decision)}`,
        `Efecto: ${effect(decision)}.`,
        'Solo se modificara esta fila.',
      ].join('\n')
    )
    if (!ok) return
    saveMutation.mutate({ row, decision })
  }

  if (isLoading) {
    return (
      <div className="h-24 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">
        Cargando decisiones funcionales...
      </div>
    )
  }

  if (rows.length === 0) return null

  // Sin useMemo deliberadamente: las celdas cierran sobre `drafts` y
  // `saveMutation` (via handleSave), que cambian en cada render -- memoizar
  // con una lista de dependencias arriesgaria capturar un closure obsoleto.
  // El comportamiento (recalcular en cada render) es identico al original,
  // que tampoco memoizaba el mapeo de filas.
  const columns: ColumnDef<RowFunctionalDecision>[] = [
    {
      header: 'Fila',
      accessorKey: 'row',
      cell: ({ row }) => (
        <span className="whitespace-nowrap font-semibold text-slate-800">{row.original.row}</span>
      ),
    },
    {
      header: 'Concepto',
      accessorKey: 'concept',
      cell: ({ row }) => (
        <div className="min-w-44 text-slate-700">{row.original.concept || '-'}</div>
      ),
    },
    {
      header: 'Profesional',
      accessorKey: 'professional',
      cell: ({ row }) => (
        <div className="min-w-36 text-slate-700">{row.original.professional || '-'}</div>
      ),
    },
    {
      header: () => (
        <span title="Decision propia: configurada manualmente para esa fila.">Decision propia</span>
      ),
      id: 'explicit_decision',
      cell: ({ row }) => (
        <DecisionBadge
          value={row.original.explicit_decision}
          explicit={row.original.has_explicit_decision}
        />
      ),
    },
    {
      header: () => (
        <span title="Hereda de: origen de la decision cuando la fila no tiene una configuracion propia.">
          Hereda de
        </span>
      ),
      id: 'inherited',
      cell: ({ row }) => (
        <>
          <div className="text-slate-600">{inheritedText(row.original)}</div>
          {!row.original.has_explicit_decision && row.original.inherited_decision && (
            <div className="mt-0.5 text-[11px] text-slate-500">
              {label(row.original.inherited_decision)}
            </div>
          )}
        </>
      ),
    },
    {
      header: () => (
        <span title="Regla aplicada: criterio que finalmente utiliza el motor durante la validacion.">
          Regla aplicada
        </span>
      ),
      id: 'effective_decision',
      cell: ({ row }) => (
        <span className="inline-flex rounded-md bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
          {label(row.original.effective_decision)}
        </span>
      ),
    },
    {
      header: 'Origen',
      accessorKey: 'origin',
      cell: ({ row }) => (
        <span className="text-slate-700">
          {ORIGIN_LABELS[row.original.origin] ?? row.original.origin}
        </span>
      ),
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => <span className="text-slate-700">{row.original.status ?? '-'}</span>,
    },
    {
      header: 'Revision',
      id: 'reviewed',
      cell: ({ row }) => (
        <div className="min-w-40 text-slate-600">{reviewedText(row.original)}</div>
      ),
    },
    {
      header: 'Observacion',
      id: 'observation',
      cell: ({ row }) => (
        <div className="min-w-44 text-slate-600">
          {row.original.observation || row.original.condition || '-'}
        </div>
      ),
    },
    ...(!readOnly
      ? [
          {
            header: 'Accion rapida',
            id: 'accion',
            cell: ({ row }: { row: { original: RowFunctionalDecision } }) => {
              const selected = drafts[row.original.row] ?? defaultSelection(row.original)
              return (
                <div className="min-w-56">
                  <div className="flex items-center gap-2">
                    <select
                      value={selected}
                      onChange={(event) =>
                        setDrafts((current) => ({
                          ...current,
                          [row.original.row]: event.target.value as EditableDecision,
                        }))
                      }
                      className="h-8 rounded-md border border-slate-200 bg-white px-2 text-xs text-slate-700 outline-none focus:border-indigo-300 focus:ring-1 focus:ring-indigo-300"
                    >
                      <option value="debe_registrar_cero">Debe registrar 0</option>
                      <option value="puede_quedar_vacio">Puede quedar vacio</option>
                      <option value="heredar_patron">Heredar del patron</option>
                      <option value="heredar_seccion">Heredar de la seccion</option>
                      <option value="requiere_revision">Requiere revision</option>
                    </select>
                    <button
                      type="button"
                      onClick={() => handleSave(row.original)}
                      disabled={saveMutation.isPending}
                      className="inline-flex h-8 items-center gap-1 rounded-md bg-indigo-600 px-2.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      <Save className="h-3.5 w-3.5" />
                      Guardar
                    </button>
                  </div>
                </div>
              )
            },
          } satisfies ColumnDef<RowFunctionalDecision>,
        ]
      : []),
  ]

  const getRowClassName = (row: RowFunctionalDecision) =>
    row.possible_inconsistency ? 'bg-amber-50/60 hover:bg-amber-50/60' : 'hover:bg-white'

  return (
    <section className="rounded-lg border border-slate-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Decisiones funcionales por fila</h2>
          <p className="text-xs text-slate-500">
            Revise por que cada fila exige 0, permite vacio o hereda una decision.
          </p>
        </div>
        {inconsistentCount > 0 && (
          <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">
            <AlertTriangle className="h-3.5 w-3.5" />
            {inconsistentCount} posibles inconsistencias
          </span>
        )}
      </div>

      <DataTable columns={columns} data={rows} getRowClassName={getRowClassName} />
    </section>
  )
}

function DecisionBadge({ value, explicit }: { value: string | null; explicit: boolean }) {
  if (!value) return <span className="text-slate-400">Sin decision</span>
  return (
    <span
      className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium ${
        explicit
          ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200'
          : 'bg-slate-100 text-slate-600'
      }`}
    >
      {label(value)}
    </span>
  )
}
