export interface FeatureFlagConfig {
  enabled: boolean
  mode: 'disabled' | 'parallel' | 'parallel_write' | 'replace'
  fail_open: boolean
  log_mode: 'off' | 'diff' | 'all'
}

export const MODE_OPTIONS: { value: FeatureFlagConfig['mode']; label: string }[] = [
  { value: 'disabled', label: 'Deshabilitado' },
  { value: 'parallel', label: 'Paralelo' },
  { value: 'parallel_write', label: 'Paralelo + escritura' },
  { value: 'replace', label: 'Reemplazo' },
]

export const LOG_MODE_OPTIONS: { value: FeatureFlagConfig['log_mode']; label: string }[] = [
  { value: 'off', label: 'Desactivado' },
  { value: 'diff', label: 'Solo diferencias' },
  { value: 'all', label: 'Todos' },
]
