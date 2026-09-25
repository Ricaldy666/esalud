import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { AlertTriangle, Info, Save } from 'lucide-react'
import { toast } from 'sonner'
import { useAuthStore } from '@/app/store/authStore'
import { DataTable } from '@/shared/components/DataTable'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { calibrationService } from '../../services/calibration'
import type { RowFunctionalDecision } from '../../types/calibration'

type EditableDecision =
  | 'debe_registrar_cero'
  | 'puede_quedar_vacio'
  | 'no_se_puede_ingresar_informacion'
  | 'heredar_patron'
  | 'heredar_seccion'
  | 'requiere_revision'

interface Props {
  serie: string
  sheet: string
  section: string
  readOnly?: boolean
  // BM-11.17: metadata explicita ya generada por BM-11.15
  // (PatternMatrixResponse.capture_mode) -- nunca inferido por titulo/serie/
  // ausencia de decisiones. Cuando es 'derived_auto_fill', el eje
  // empty_behavior que esta tabla representa no aplica (la seccion es 100%
  // calculada desde otra hoja, sin ninguna nocion de "fila vacia por
  // decision humana" -- ver FunctionalRuleService::patternQuestionsToFunctionalRule()).
  // BM-11.25: 'hybrid' es un valor de seccion valido (mezcla de patrones
  // normales y derivados) -- esta prop solo se usa como RESPALDO por fila
  // (ver rowIsDerived()); la fuente primaria es RowFunctionalDecision.capture_mode,
  // propio de cada fila.
  captureMode?: 'standard' | 'derived_auto_fill' | 'hybrid'
}

const DECISION_LABELS: Record<string, string> = {
  debe_registrar_cero: 'Debe registrar 0',
  puede_quedar_vacio: 'Puede quedar vacio',
  pendiente_definicion: 'Requiere revision',
  heredar_patron: 'Usar la decisión del grupo',
  heredar_seccion: 'Usar la decisión de la sección',
  requiere_revision: 'Requiere revision',
  no_aplica: 'No aplica',
  no_se_puede_ingresar_informacion: 'No se puede ingresar información',
}

// Solo presentacion: el valor almacenado/enviado no cambia.
const STATUS_LABELS: Record<string, string> = {
  pending: 'Pendiente',
  propuesta: 'Propuesta',
  aprobada: 'Aprobada',
  rechazada: 'Rechazada',
  validada: 'Validada',
}

const ORIGIN_LABELS: Record<string, string> = {
  row: 'Fila',
  pattern: 'Grupo de filas',
  section: 'Sección',
  none: 'Sin decision',
}

function label(value?: string | null) {
  if (!value) return 'Sin decision'
  return DECISION_LABELS[value] ?? value
}

function effect(value: EditableDecision) {
  if (value === 'debe_registrar_cero') return 'exigira registrar 0 cuando no existan prestaciones'
  if (value === 'puede_quedar_vacio') return 'podra quedar vacia'
  if (value === 'no_se_puede_ingresar_informacion')
    return 'no podra recibir informacion (0 o cualquier valor sera incumplimiento)'
  if (value === 'requiere_revision') return 'quedara pendiente de revision'
  return 'heredara el criterio funcional'
}

function defaultSelection(row: RowFunctionalDecision): EditableDecision {
  if (
    row.explicit_decision === 'debe_registrar_cero' ||
    row.explicit_decision === 'puede_quedar_vacio' ||
    row.explicit_decision === 'no_se_puede_ingresar_informacion'
  ) {
    return row.explicit_decision
  }
  if (row.explicit_decision === 'pendiente_definicion') return 'requiere_revision'
  if (row.origin === 'pattern') return 'heredar_patron'
  if (row.origin === 'section') return 'heredar_seccion'
  return 'requiere_revision'
}

function statusLabel(row: RowFunctionalDecision, derived: boolean) {
  if (derived) return 'No aplica'
  if (!row.status) return '-'
  return STATUS_LABELS[row.status] ?? row.status
}

function originLabel(row: RowFunctionalDecision, derived: boolean) {
  if (derived) return 'Técnica / Automática'
  return ORIGIN_LABELS[row.origin] ?? row.origin
}

function inheritedText(row: RowFunctionalDecision) {
  if (row.has_explicit_decision) return 'Decision propia'
  if (!row.inherited_decision) return 'Sin decision heredada'
  if (row.source_row) return `Hereda desde fila ${row.source_row}`
  if (row.origin === 'pattern') return 'Usa la decisión del grupo'
  if (row.origin === 'section') return 'Usa la decisión de la sección'
  return 'Sin decision heredada'
}

// BM-11.25: cada fila trae su propio capture_mode (autoridad primaria --
// una seccion hibrida devuelve filas derived_auto_fill junto a filas
// normales en la MISMA respuesta). Si la fila no lo trae (respuesta vieja
// en cache, o backend anterior a esta fase), se usa como respaldo el
// captureMode de seccion recibido por props (comportamiento BM-11.17
// exacto para secciones no hibridas).
function rowIsDerived(row: RowFunctionalDecision, sectionFallbackIsDerived: boolean): boolean {
  return row.capture_mode ? row.capture_mode === 'derived_auto_fill' : sectionFallbackIsDerived
}

export default function RowFunctionalDecisionTable({
  serie,
  sheet,
  section,
  readOnly = false,
  captureMode = 'standard',
}: Props) {
  const queryClient = useQueryClient()
  const user = useAuthStore((state) => state.user)
  const [drafts, setDrafts] = useState<Record<number, EditableDecision>>({})
  const [detailRow, setDetailRow] = useState<RowFunctionalDecision | null>(null)
  const isDerived = captureMode === 'derived_auto_fill'

  const { data, isLoading } = useQuery({
    queryKey: ['row-functional-decisions', serie, sheet, section],
    queryFn: () => calibrationService.getRowFunctionalDecisions(serie, sheet, section),
    enabled: Boolean(serie) && Boolean(sheet) && Boolean(section),
  })

  const rows = useMemo(() => [...(data?.rows ?? [])].sort((a, b) => a.row - b.row), [data?.rows])
  const inconsistentCount = useMemo(
    () => rows.filter((row) => row.possible_inconsistency).length,
    [rows]
  )
  // BM-11.25: derivado del capture_mode POR FILA -- una seccion hibrida
  // tiene filas de ambos tipos a la vez (ni "todas derivadas" ni "ninguna
  // derivada"). allRowsDerived preserva el comportamiento exacto de
  // BM-11.17 para secciones 100% derivadas; anyRowDerived habilita el
  // aviso/columna condicional para el caso hibrido nuevo.
  const allRowsDerived = rows.length > 0 && rows.every((row) => rowIsDerived(row, isDerived))
  const anyRowDerived = rows.some((row) => rowIsDerived(row, isDerived))

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
      } else if (decision === 'no_se_puede_ingresar_informacion') {
        payload.status = 'aprobada'
        payload.functional_condition =
          'No se puede ingresar informacion segun criterio de Estadistica'
      } else if (decision === 'requiere_revision') {
        payload.status = 'propuesta'
        payload.functional_condition = 'Requiere revision de Estadistica'
      }

      return calibrationService.saveRowFunctionalRule(serie, sheet, section, row.row, payload)
    },
    onSuccess: (_data, variables) => {
      toast.success('Decision funcional guardada')
      setDrafts((current) => {
        const next = { ...current }
        delete next[variables.row.row]
        return next
      })
      queryClient.invalidateQueries({
        queryKey: ['row-functional-decisions', serie, sheet, section],
      })
      queryClient.invalidateQueries({ queryKey: ['pattern-matrix', serie, sheet, section] })
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
      // TableCell aplica whitespace-nowrap por defecto; las columnas de texto
      // libre deben poder partir linea para que la tabla quepa sin scroll.
      cell: ({ row }) => (
        <div className="min-w-36 max-w-72 whitespace-normal break-words text-slate-700">
          {row.original.concept || '-'}
        </div>
      ),
    },
    {
      header: 'Profesional',
      accessorKey: 'professional',
      cell: ({ row }) => (
        <div className="min-w-24 max-w-48 whitespace-normal break-words text-slate-700">
          {row.original.professional || '-'}
        </div>
      ),
    },
    {
      // Resume lo que antes ocupaba "Decision propia" + "Hereda de": mismas
      // funciones y los mismos campos (has_explicit_decision/explicit_decision/
      // inherited_decision/origin/source_row), solo presentado en una celda.
      header: () => (
        <span title="Decision propia de la fila, o de donde hereda cuando no tiene una.">
          Decisión
        </span>
      ),
      id: 'decision',
      cell: ({ row }) => (
        <DecisionSummary row={row.original} derived={rowIsDerived(row.original, isDerived)} />
      ),
    },
    {
      header: () => (
        <span title="Regla aplicada: criterio que finalmente utiliza el motor durante la validacion.">
          Regla aplicada
        </span>
      ),
      id: 'effective_decision',
      cell: ({ row }) =>
        rowIsDerived(row.original, isDerived) ? (
          <span className="inline-flex rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">
            Lógica automática / Derivada
          </span>
        ) : (
          <span className="inline-flex rounded-md bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
            {label(row.original.effective_decision)}
          </span>
        ),
    },
    {
      header: 'Estado',
      accessorKey: 'status',
      cell: ({ row }) => (
        <span className="text-xs text-slate-700">
          {statusLabel(row.original, rowIsDerived(row.original, isDerived))}
        </span>
      ),
    },
    // Origen, Revision y Observacion ya no ocupan columnas: se consultan en
    // el detalle de la fila (DetailButton -> RowDetailDialog). En modo solo
    // lectura no existe la columna Accion, asi que el detalle va en su propia
    // columna angosta.
    ...(readOnly
      ? [
          {
            header: () => <span className="sr-only">Detalle</span>,
            id: 'detalle',
            cell: ({ row }: { row: { original: RowFunctionalDecision } }) => (
              <DetailButton row={row.original} onOpen={setDetailRow} />
            ),
          } satisfies ColumnDef<RowFunctionalDecision>,
        ]
      : []),
    ...(!readOnly
      ? [
          {
            header: 'Acción',
            id: 'accion',
            cell: ({ row }: { row: { original: RowFunctionalDecision } }) => {
              // BM-11.25: columna disponible siempre que la tabla no sea
              // readOnly, pero por FILA -- una fila derived_auto_fill (en
              // una seccion normal o hibrida) nunca muestra el selector de
              // empty_behavior ni el boton Guardar, porque ese eje no
              // aplica a una fila 100% calculada.
              if (rowIsDerived(row.original, isDerived)) {
                return (
                  <div className="flex items-center gap-1.5">
                    <span className="text-xs text-slate-400">No aplica</span>
                    <DetailButton row={row.original} onOpen={setDetailRow} />
                  </div>
                )
              }
              const selected = drafts[row.original.row] ?? defaultSelection(row.original)
              return (
                <div>
                  <div className="flex items-center gap-1.5">
                    <select
                      aria-label={`Accion rapida para la fila ${row.original.row}`}
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
                      <option value="no_se_puede_ingresar_informacion">
                        No se puede ingresar información
                      </option>
                      <option value="heredar_patron">Usar la decisión del grupo</option>
                      <option value="heredar_seccion">Usar la decisión de la sección</option>
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
                    <DetailButton row={row.original} onOpen={setDetailRow} />
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
            {allRowsDerived
              ? 'Filas de referencia -- esta seccion se completa automaticamente, no requieren decision de vacio.'
              : anyRowDerived
                ? 'Algunas filas se completan automaticamente (ver aviso abajo); el resto requiere revision habitual.'
                : 'Ajuste aquí solo las filas que necesiten un criterio distinto al de su grupo.'}
          </p>
        </div>
        {!allRowsDerived && inconsistentCount > 0 && (
          <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">
            <AlertTriangle className="h-3.5 w-3.5" />
            {inconsistentCount} posibles inconsistencias
          </span>
        )}
      </div>

      {allRowsDerived && (
        <div className="border-b border-indigo-100 bg-indigo-50/60 px-4 py-2.5 text-xs text-indigo-800">
          Esta sección se completa automáticamente. Las decisiones funcionales por fila sobre datos
          vacíos no aplican -- la confirmación de esta sección se registra arriba (Confirmar lógica
          automática).
        </div>
      )}

      {!allRowsDerived && anyRowDerived && (
        <div className="border-b border-indigo-100 bg-indigo-50/60 px-4 py-2.5 text-xs text-indigo-800">
          Las filas marcadas "Lógica automática / Derivada" se completan automáticamente y no
          requieren decisión de vacío por fila -- su confirmación se registra arriba (Confirmar
          lógica automática). El resto de las filas sí requiere revisión habitual.
        </div>
      )}

      <DataTable columns={columns} data={rows} getRowClassName={getRowClassName} />

      <RowDetailDialog
        row={detailRow}
        derived={detailRow ? rowIsDerived(detailRow, isDerived) : false}
        onClose={() => setDetailRow(null)}
      />
    </section>
  )
}

function DecisionSummary({ row, derived }: { row: RowFunctionalDecision; derived: boolean }) {
  if (derived) {
    return (
      <div className="min-w-28 whitespace-normal">
        <div className="text-xs font-medium text-slate-400">No aplica</div>
        <div className="mt-0.5 text-[11px] text-slate-400">Lógica automática</div>
      </div>
    )
  }

  const detail = row.has_explicit_decision ? row.explicit_decision : row.inherited_decision

  return (
    <div className="min-w-28 whitespace-normal">
      <div
        className={`text-xs font-medium ${row.has_explicit_decision ? 'text-indigo-700' : 'text-slate-600'}`}
      >
        {inheritedText(row)}
      </div>
      {detail && <div className="mt-0.5 text-[11px] text-slate-500">{label(detail)}</div>}
    </div>
  )
}

function DetailButton({
  row,
  onOpen,
}: {
  row: RowFunctionalDecision
  onOpen: (row: RowFunctionalDecision) => void
}) {
  return (
    <button
      type="button"
      onClick={() => onOpen(row)}
      title="Ver detalle"
      aria-label={`Ver detalle de la fila ${row.row}`}
      className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300"
    >
      <Info className="h-4 w-4" />
    </button>
  )
}

function DetailField({ term, value }: { term: string; value: string }) {
  return (
    <div className="grid grid-cols-[8.5rem_1fr] gap-3 py-2">
      <dt className="text-xs font-medium text-slate-500">{term}</dt>
      <dd className="text-sm text-slate-800 whitespace-pre-wrap break-words">{value}</dd>
    </div>
  )
}

// Muestra solo campos que ya trae RowFunctionalDecision -- los opcionales
// (revisor, fecha, observacion, condicion) se omiten si no existen.
function RowDetailDialog({
  row,
  derived,
  onClose,
}: {
  row: RowFunctionalDecision | null
  derived: boolean
  onClose: () => void
}) {
  const reviewer = row?.reviewed_by?.trim()
  const reviewedAt = row?.reviewed_at ? new Date(row.reviewed_at).toLocaleString('es-CL') : ''
  const observation = row?.observation?.trim()
  const condition = row?.condition?.trim()

  return (
    <Dialog open={row !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="w-full border border-slate-200 bg-white shadow-xl sm:max-w-lg">
        {row && (
          <>
            <DialogHeader className="border-b border-slate-100 pb-3">
              <DialogTitle className="text-base font-semibold text-slate-900">
                Fila {row.row}
              </DialogTitle>
              <DialogDescription className="text-slate-500">
                {[row.concept, row.professional].filter(Boolean).join(' / ') || 'Sin concepto'}
              </DialogDescription>
            </DialogHeader>
            <dl className="divide-y divide-slate-100">
              <DetailField
                term="Regla aplicada"
                value={derived ? 'Lógica automática / Derivada' : label(row.effective_decision)}
              />
              <DetailField term="Origen" value={originLabel(row, derived)} />
              {!derived && (
                <DetailField
                  term="Decisión propia"
                  value={
                    row.has_explicit_decision ? label(row.explicit_decision) : 'Sin decisión propia'
                  }
                />
              )}
              {!derived && (
                <DetailField
                  term="Hereda de"
                  value={
                    row.has_explicit_decision
                      ? 'No hereda (tiene decisión propia)'
                      : [
                          inheritedText(row),
                          row.inherited_decision ? label(row.inherited_decision) : '',
                        ]
                          .filter(Boolean)
                          .join(' — ')
                  }
                />
              )}
              <DetailField term="Estado" value={statusLabel(row, derived)} />
              {reviewer && <DetailField term="Revisado por" value={reviewer} />}
              {reviewedAt && <DetailField term="Fecha/hora" value={reviewedAt} />}
              {observation && <DetailField term="Observación" value={observation} />}
              {condition && condition !== observation && (
                <DetailField term="Condición funcional" value={condition} />
              )}
            </dl>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
