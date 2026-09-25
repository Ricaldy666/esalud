// Presentacion del detalle de un error funcional segun la decision funcional
// aplicada (criterio.empty_behavior). Solo textos: el valor interno/persistido
// nunca cambia. Logica pura (sin React) para probarla con el runner de Node.

/** Etiquetas legibles de las decisiones funcionales (nunca el nombre tecnico). */
export const EMPTY_BEHAVIOR_LABELS: Record<string, string> = {
  debe_registrar_cero: 'Debe registrar 0',
  puede_quedar_vacio: 'Puede quedar vacío',
  no_se_puede_ingresar_informacion: 'No se puede ingresar información',
  pendiente_definicion: 'Requiere revisión',
  incluir: 'Incluir',
  excluir: 'Excluir',
  informativo: 'Informativo',
  no_aplica: 'No aplica',
}

export function emptyBehaviorLabel(value: string | null | undefined): string {
  if (!value) return '-'
  return EMPTY_BEHAVIOR_LABELS[value] ?? value
}

export interface PendingCellsPresentation {
  title: string
  description: string
  action: string
  countLabel: (count: number) => string
  summaryLabel: string
}

const PENDING_CELLS_PRESENTATION: Record<string, PendingCellsPresentation> = {
  // Textos identicos a los que ya mostraba la pantalla para esta decision.
  debe_registrar_cero: {
    title: 'Celdas editables vacias que deben registrar 0',
    description: 'Estas son las coordenadas exactas que deben completarse en el Excel.',
    action: 'Registrar 0',
    countLabel: (count) => `${count} pendiente${count === 1 ? '' : 's'}`,
    summaryLabel: 'Celdas pendientes',
  },
  no_se_puede_ingresar_informacion: {
    title: 'Celdas con información que deben quedar vacías',
    description: 'Estas celdas no deben contener información según el criterio funcional aprobado.',
    action: 'Eliminar información',
    countLabel: (count) => `${count} con información`,
    summaryLabel: 'Celdas con información',
  },
}

const DEFAULT_PRESENTATION: PendingCellsPresentation = {
  title: 'Celdas a revisar',
  description: 'Revise estas celdas según el criterio funcional aprobado.',
  action: 'Revisar',
  countLabel: (count) => `${count} a revisar`,
  summaryLabel: 'Celdas a revisar',
}

/** Textos del bloque de celdas segun la decision funcional aplicada. */
export function pendingCellsPresentation(
  emptyBehavior: string | null | undefined
): PendingCellsPresentation {
  return (emptyBehavior && PENDING_CELLS_PRESENTATION[emptyBehavior]) || DEFAULT_PRESENTATION
}

export interface PendingCellView {
  coordinate: string
  label: string
  action: string
  editable: boolean
  blocked: boolean
  color: string | null
}

interface PendingCellInput {
  coordinate: string
  column: string
  label?: string | null
  editable?: boolean
  blocked?: boolean
  color?: string | null
}

/** Modelo de vista de cada celda: coordenada y encabezado tal cual vienen,
 * con la accion correspondiente a la decision funcional. */
export function pendingCellViews(
  cells: readonly PendingCellInput[],
  emptyBehavior: string | null | undefined
): PendingCellView[] {
  const { action } = pendingCellsPresentation(emptyBehavior)
  return cells.map((cell) => ({
    coordinate: cell.coordinate,
    label: cell.label || `Columna ${cell.column}`,
    action,
    editable: Boolean(cell.editable),
    blocked: Boolean(cell.blocked),
    color: cell.color ?? null,
  }))
}
