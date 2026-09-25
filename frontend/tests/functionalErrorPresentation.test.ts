// Tests de presentacion del detalle de error funcional segun la decision.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  emptyBehaviorLabel,
  pendingCellsPresentation,
  pendingCellViews,
} from '../src/features/rule-engine/components/functionalErrorPresentation.ts'

// Mismo contenido que la carga real #209, BM18/D fila 61.
const ROW_61_CELLS = [
  {
    coordinate: 'C61',
    column: 'C',
    label: 'TOTAL',
    editable: true,
    blocked: false,
    color: 'blanco',
  },
  {
    coordinate: 'D61',
    column: 'D',
    label: 'POR COMPRA DE SERVICIO',
    editable: true,
    blocked: false,
    color: 'blanco',
  },
]

test('debe_registrar_cero conserva exactamente sus textos actuales', () => {
  const p = pendingCellsPresentation('debe_registrar_cero')
  assert.equal(p.title, 'Celdas editables vacias que deben registrar 0')
  assert.equal(
    p.description,
    'Estas son las coordenadas exactas que deben completarse en el Excel.'
  )
  assert.equal(p.action, 'Registrar 0')
  assert.equal(p.countLabel(1), '1 pendiente')
  assert.equal(p.countLabel(2), '2 pendientes')
  assert.equal(p.summaryLabel, 'Celdas pendientes')
})

test('no_se_puede_ingresar_informacion muestra "Eliminar información"', () => {
  const p = pendingCellsPresentation('no_se_puede_ingresar_informacion')
  assert.equal(p.title, 'Celdas con información que deben quedar vacías')
  assert.equal(
    p.description,
    'Estas celdas no deben contener información según el criterio funcional aprobado.'
  )
  assert.equal(p.action, 'Eliminar información')
  assert.equal(p.countLabel(2), '2 con información')
})

test('no se mezclan los mensajes entre ambos tipos', () => {
  const zero = pendingCellsPresentation('debe_registrar_cero')
  const forbidden = pendingCellsPresentation('no_se_puede_ingresar_informacion')
  assert.doesNotMatch(forbidden.title + forbidden.description + forbidden.action, /registrar 0/i)
  assert.doesNotMatch(zero.title + zero.description + zero.action, /eliminar/i)
  for (const view of pendingCellViews(ROW_61_CELLS, 'no_se_puede_ingresar_informacion')) {
    assert.equal(view.action, 'Eliminar información')
  }
  for (const view of pendingCellViews(ROW_61_CELLS, 'debe_registrar_cero')) {
    assert.equal(view.action, 'Registrar 0')
  }
})

test('las coordenadas C61/D61 y sus encabezados permanecen correctos', () => {
  const views = pendingCellViews(ROW_61_CELLS, 'no_se_puede_ingresar_informacion')
  assert.deepEqual(
    views.map((v) => [v.coordinate, v.label, v.editable, v.blocked]),
    [
      ['C61', 'TOTAL', true, false],
      ['D61', 'POR COMPRA DE SERVICIO', true, false],
    ]
  )
})

test('una celda sin encabezado usa "Columna X"', () => {
  const [view] = pendingCellViews([{ coordinate: 'E61', column: 'E' }], 'debe_registrar_cero')
  assert.equal(view.label, 'Columna E')
})

test('la decision funcional se muestra legible, sin el nombre tecnico', () => {
  assert.equal(
    emptyBehaviorLabel('no_se_puede_ingresar_informacion'),
    'No se puede ingresar información'
  )
  assert.equal(emptyBehaviorLabel('debe_registrar_cero'), 'Debe registrar 0')
  assert.equal(emptyBehaviorLabel('puede_quedar_vacio'), 'Puede quedar vacío')
  assert.equal(emptyBehaviorLabel(null), '-')
})

test('una decision sin textos propios usa una instruccion neutra, nunca "Registrar 0"', () => {
  const p = pendingCellsPresentation('incluir')
  assert.equal(p.action, 'Revisar')
  assert.doesNotMatch(p.title, /registrar 0/i)
})
