import type { RemValidationResult } from '../types/rem'

export function getSeccionLabel(result: RemValidationResult): string {
  const ctx = result.context as Record<string, unknown> | null
  if (result.rule_type === 'cross_sheet') {
    const src = (ctx?.source_section as string) ?? ''
    const tgt = (ctx?.target_section as string) ?? ''
    if (src && tgt && src !== tgt) return `${src} ↔ ${tgt}`
    if (src) return src
  }
  if (ctx?.section) return String(ctx.section)
  const match = result.rule_key.match(/^(a\d+[a-z]*)/i)
  return match ? match[1].toUpperCase() : result.rule_key
}

export function getUbicacionLabel(result: RemValidationResult): string {
  const ctx = result.context as Record<string, unknown> | null
  if (!ctx) return '-'
  if (result.rule_type === 'cross_sheet') {
    const rows = Array.isArray(ctx.source_rows)
      ? `Fila(s) ${(ctx.source_rows as number[]).join(', ')}`
      : ''
    const cols = Array.isArray(ctx.source_columns)
      ? `Col. ${(ctx.source_columns as string[]).join('+')}`
      : ''
    return [rows, cols].filter(Boolean).join(' · ') || '-'
  }
  const concept = ctx.concept as string | null
  const professional = ctx.professional as string | null
  if (concept) return `${concept}${professional ? ` / ${professional}` : ''}`
  return '-'
}

export function getDescripcionLabel(result: RemValidationResult): string {
  const ctx = result.context as Record<string, unknown> | null
  if (result.rule_type === 'cross_sheet' && ctx) {
    const desc = (ctx.description as string) ?? ''
    const src = (ctx.source_sum as number) ?? 0
    const tgt = (ctx.target_sum as number) ?? 0
    const cleanDesc = desc
      .replace(/\([A-Z0-9]+![A-Z]+![A-Z]\d+\)/g, '')
      .replace(/\([A-Z0-9]+![A-Z]+![A-Z]\d+\+[A-Z]\d+\)/g, '')
      .trim()
    return `${cleanDesc} — Registrado: ${src} | Esperado: ${tgt}`
  }
  if (result.message) {
    return result.message.replace(/^\[.*?\]\s*/, '')
  }
  return '-'
}
