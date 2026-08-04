const ROLE_DISPLAY_LABELS: Record<string, string> = {
  Analista: 'Estadística APS',
}

export function getRoleDisplayLabel(role: string): string {
  return ROLE_DISPLAY_LABELS[role] ?? role
}
