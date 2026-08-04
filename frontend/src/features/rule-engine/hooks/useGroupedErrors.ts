import { useMemo } from 'react'
import type { ValidationError, ErrorGroup, ExecutiveSummary } from '../types/validation'

const severityRank: Record<string, number> = { error: 0, warning: 1, info: 2 }

function groupKey(e: ValidationError): string {
  return `${e.tipo_error}|${e.form}|${e.section}|${e.tipo}|${e.severidad}`
}

function mostFrequent(items: string[]): string {
  const freq = new Map<string, number>()
  for (const item of items) freq.set(item, (freq.get(item) ?? 0) + 1)
  let best = ''
  let bestCount = 0
  for (const [item, count] of freq) {
    if (count > bestCount) {
      best = item
      bestCount = count
    }
  }
  return best
}

function buildGroups(errors: ValidationError[]): ErrorGroup[] {
  const groups = new Map<string, ValidationError[]>()

  for (const e of errors) {
    const k = groupKey(e)
    if (!groups.has(k)) groups.set(k, [])
    groups.get(k)!.push(e)
  }

  const result: ErrorGroup[] = []

  for (const [key, group] of groups) {
    const first = group[0]
    const variables = [...new Set(group.map((e) => e.campo))]
    const recomendaciones = group.map((e) => e.recomendacion).filter(Boolean)
    const topRecomendacion = mostFrequent(recomendaciones)

    result.push({
      key,
      form: first.form,
      section: first.section,
      tipo_error: first.tipo_error,
      tipo: first.tipo,
      recomendacion: topRecomendacion,
      errors: group,
      count: group.length,
      variables,
      severidad: first.severidad,
    })
  }

  return result.sort((a, b) => {
    const sevDiff = (severityRank[a.severidad] ?? 9) - (severityRank[b.severidad] ?? 9)
    if (sevDiff !== 0) return sevDiff
    return b.count - a.count
  })
}

function buildSummary(groups: ErrorGroup[], totalErrors: number): ExecutiveSummary {
  const formCounts = new Map<string, number>()
  for (const g of groups) {
    formCounts.set(g.form, (formCounts.get(g.form) ?? 0) + g.count)
  }

  const topForms = [...formCounts.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)
    .map(([form, count]) => ({ form, count }))

  const topForm = topForms[0]
  const topRec = topForm
    ? `Revise primero el formulario ${topForm.form} porque concentra la mayor cantidad de observaciones.`
    : ''

  return { total_errors: totalErrors, top_forms: topForms, top_recommendation: topRec }
}

export function useGroupedErrors(errors: ValidationError[]) {
  return useMemo(() => {
    const groups = buildGroups(errors)
    const summary = buildSummary(groups, errors.length)
    return { groups, summary }
  }, [errors])
}
