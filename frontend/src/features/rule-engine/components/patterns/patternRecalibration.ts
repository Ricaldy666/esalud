// Recalibracion funcional de un patron ya revisado.
//
// "Revisado" = existe una decision funcional revisada vigente; NO significa
// que quede congelada. Un patron revisado se muestra en solo lectura hasta
// que un usuario con permiso de calibracion pulsa "Recalibrar patron": eso
// solo desbloquea ese patron en pantalla (no escribe nada). Al guardar, la
// revision del patron queda con el usuario y la fecha actuales. La
// certificacion tecnica y la marca de seccion revisada no se tocan.
//
// Logica pura (sin React ni imports de runtime) para poder probarla con el
// test runner nativo de Node.

import type { CalibrationQuestion } from '../../types/calibration'

/** Roles que solo pueden consultar la calibracion (nunca editar ni recalibrar). */
export const READ_ONLY_CALIBRATION_ROLES = ['Revisor', 'Auditor']

export function isCalibrationReadOnly(roles: readonly string[] | undefined): boolean {
  return (roles ?? []).some((role) => READ_ONLY_CALIBRATION_ROLES.includes(role))
}

export interface PatternLockState {
  readOnly: boolean
  reviewed: boolean
  recalibrating: boolean
}

/** Las preguntas del patron quedan bloqueadas si el usuario es de solo
 * lectura, o si el patron esta revisado y no se activo su recalibracion. */
export function isPatternLocked({ readOnly, reviewed, recalibrating }: PatternLockState): boolean {
  return readOnly || (reviewed && !recalibrating)
}

/** "Recalibrar patron" solo se ofrece sobre un patron revisado, a usuarios
 * con permiso de edicion, y mientras no este ya en recalibracion. */
export function canRecalibratePattern({
  readOnly,
  reviewed,
  recalibrating,
}: PatternLockState): boolean {
  return !readOnly && reviewed && !recalibrating
}

/** Activa la recalibracion de UN patron. Solo estado de pantalla. */
export function startRecalibration(
  current: Record<number, boolean>,
  patternId: number
): Record<number, boolean> {
  return { ...current, [patternId]: true }
}

/** Termina la recalibracion de UN patron (tras guardar). */
export function finishRecalibration(
  current: Record<number, boolean>,
  patternId: number
): Record<number, boolean> {
  const next = { ...current }
  delete next[patternId]
  return next
}

const PATTERN_QUESTION_TYPES = new Set(['pattern_question', 'pattern_confirmation'])

/** Autor y fecha de revision que corresponde guardar para una pregunta de
 * patron: en recalibracion, siempre la revision actual; si no, la existente. */
export function reviewStamp(
  existing: Pick<CalibrationQuestion, 'reviewed_at' | 'reviewed_by'> | undefined,
  options: { recalibrating: boolean; reviewedAt: string; reviewedBy: string }
): { reviewed_at: string; reviewed_by: string } {
  if (options.recalibrating) {
    return { reviewed_at: options.reviewedAt, reviewed_by: options.reviewedBy }
  }
  return {
    reviewed_at: existing?.reviewed_at ?? options.reviewedAt,
    reviewed_by: existing?.reviewed_by ?? options.reviewedBy,
  }
}

/**
 * Marca como revisadas las preguntas de UN patron. Solo toca preguntas de
 * patron (pattern_question/pattern_confirmation) de ese pattern_id: nunca
 * section_review ni preguntas generales, aunque arrastren un pattern_id, para
 * que la seccion confirmada nunca pierda su marca.
 */
export function applyPatternReview(
  questions: CalibrationQuestion[],
  patternId: number,
  options: { recalibrating: boolean; reviewedAt: string; reviewedBy: string }
): CalibrationQuestion[] {
  return questions.map((question) => {
    if (question.pattern_id !== patternId || !PATTERN_QUESTION_TYPES.has(question.type ?? '')) {
      return question
    }
    return {
      ...question,
      review_status: 'reviewed' as const,
      ...reviewStamp(question, options),
    }
  })
}
