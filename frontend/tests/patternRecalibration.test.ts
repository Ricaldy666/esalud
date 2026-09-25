// Tests de la logica de recalibracion funcional de patrones.
// Runner nativo de Node (sin dependencias): npm test
import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  applyPatternReview,
  canRecalibratePattern,
  finishRecalibration,
  isCalibrationReadOnly,
  isPatternLocked,
  startRecalibration,
} from '../src/features/rule-engine/components/patterns/patternRecalibration.ts'

const NOW = '2026-09-25T18:00:00.000Z'
const OLD = '2026-09-15T20:24:28.170Z'

function question(overrides: Record<string, unknown>) {
  return {
    row: null,
    question: 'q',
    type: 'pattern_question',
    review_status: 'reviewed',
    reviewed_at: OLD,
    reviewed_by: 'Administrador Esalud',
    ...overrides,
  } as never
}

test('un patron revisado queda inicialmente bloqueado', () => {
  assert.equal(isPatternLocked({ readOnly: false, reviewed: true, recalibrating: false }), true)
})

test('un patron no revisado sigue editable (sin cambios)', () => {
  assert.equal(isPatternLocked({ readOnly: false, reviewed: false, recalibrating: false }), false)
})

test('"Recalibrar patron" visible para un usuario autorizado sobre un patron revisado', () => {
  const readOnly = isCalibrationReadOnly(['Administrador'])
  assert.equal(readOnly, false)
  assert.equal(canRecalibratePattern({ readOnly, reviewed: true, recalibrating: false }), true)
})

test('"Recalibrar patron" ausente para Revisor y Auditor', () => {
  for (const role of ['Revisor', 'Auditor']) {
    const readOnly = isCalibrationReadOnly([role])
    assert.equal(readOnly, true, role)
    assert.equal(canRecalibratePattern({ readOnly, reviewed: true, recalibrating: false }), false)
    assert.equal(isPatternLocked({ readOnly, reviewed: true, recalibrating: true }), true)
  }
})

test('"Recalibrar patron" no se ofrece sobre un patron no revisado ni en recalibracion', () => {
  assert.equal(
    canRecalibratePattern({ readOnly: false, reviewed: false, recalibrating: false }),
    false
  )
  assert.equal(
    canRecalibratePattern({ readOnly: false, reviewed: true, recalibrating: true }),
    false
  )
})

test('activar la recalibracion desbloquea solo ese patron', () => {
  const state = startRecalibration({}, 1)
  assert.deepEqual(state, { 1: true })
  assert.equal(
    isPatternLocked({ readOnly: false, reviewed: true, recalibrating: Boolean(state[1]) }),
    false
  )
  assert.equal(
    isPatternLocked({ readOnly: false, reviewed: true, recalibrating: Boolean(state[2]) }),
    true
  )
})

test('activar la recalibracion no escribe nada: solo devuelve estado nuevo', () => {
  const before = { 3: true }
  const after = startRecalibration(before, 1)
  assert.deepEqual(before, { 3: true }, 'el estado anterior no se muta')
  assert.deepEqual(after, { 1: true, 3: true })
  assert.deepEqual(finishRecalibration(after, 1), { 3: true })
})

test('guardar una recalibracion deja la revision con el usuario y fecha actuales', () => {
  const questions = [
    question({ id: 'patron_1_empty', pattern_id: 1, response: 'no_se_puede_ingresar_informacion' }),
    question({
      id: 'patron_1_formula_confirmation',
      pattern_id: 1,
      type: 'pattern_confirmation',
      response: 'confirmed',
    }),
  ]
  const result = applyPatternReview(questions, 1, {
    recalibrating: true,
    reviewedAt: NOW,
    reviewedBy: 'Francisco Arcos',
  })

  for (const q of result as Array<Record<string, unknown>>) {
    assert.equal(q.review_status, 'reviewed')
    assert.equal(q.reviewed_at, NOW)
    assert.equal(q.reviewed_by, 'Francisco Arcos')
  }
  assert.equal(
    (result[0] as Record<string, unknown>).response,
    'no_se_puede_ingresar_informacion',
    'la nueva decision se conserva'
  )
})

test('marcar revisado sin recalibracion conserva la revision existente (sin cambios)', () => {
  const result = applyPatternReview([question({ id: 'patron_1_empty', pattern_id: 1 })], 1, {
    recalibrating: false,
    reviewedAt: NOW,
    reviewedBy: 'Otro',
  })
  assert.equal((result[0] as Record<string, unknown>).reviewed_at, OLD)
  assert.equal((result[0] as Record<string, unknown>).reviewed_by, 'Administrador Esalud')
})

test('la seccion confirmada y los otros patrones no cambian', () => {
  const sectionReview = question({
    id: 'section_review',
    type: 'section_review',
    review_status: 'section_reviewed',
    pattern_id: 1,
  })
  const general = question({
    id: 'general_x',
    type: 'general_question',
    pattern_id: 1,
    review_status: undefined,
  })
  const otherPattern = question({ id: 'patron_2_empty', pattern_id: 2 })
  const questions = [
    question({ id: 'patron_1_empty', pattern_id: 1 }),
    sectionReview,
    general,
    otherPattern,
  ]

  const result = applyPatternReview(questions, 1, {
    recalibrating: true,
    reviewedAt: NOW,
    reviewedBy: 'Francisco Arcos',
  })

  assert.equal(result[1], sectionReview, 'section_review intacta aunque arrastre pattern_id')
  assert.equal((result[1] as Record<string, unknown>).review_status, 'section_reviewed')
  assert.equal(result[2], general, 'pregunta general intacta')
  assert.equal(result[3], otherPattern, 'otro patron intacto')
})
