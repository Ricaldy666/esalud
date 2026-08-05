import { useState } from 'react'
import { toast } from 'sonner'
import { functionalRuleService } from '../services/functional-rule'
import {
  EMPTY_BEHAVIOR_LABELS,
  APPLIES_TO_OPTIONS,
  FUNCTIONAL_STATUS_LABELS,
} from '../types/functional-rule'
import type { FunctionalRule } from '../types/functional-rule'

interface FunctionalRuleFormProps {
  ruleKey: string
  initial: FunctionalRule | null
  onSaved: () => void
}

const EMPTY_BEHAVIOR_OPTIONS = Object.entries(EMPTY_BEHAVIOR_LABELS).map(([value, label]) => ({
  value,
  label,
}))

export function FunctionalRuleForm({ ruleKey, initial, onSaved }: FunctionalRuleFormProps) {
  const [emptyBehavior, setEmptyBehavior] = useState(initial?.empty_behavior ?? '')
  const [appliesToTypes, setAppliesToTypes] = useState<string[]>(initial?.applies_to_types ?? [])
  const [includedCenters, setIncludedCenters] = useState(
    initial?.included_health_centers?.join(', ') ?? ''
  )
  const [excludedCenters, setExcludedCenters] = useState(
    initial?.excluded_health_centers?.join(', ') ?? ''
  )
  const [functionalCondition, setFunctionalCondition] = useState(
    initial?.functional_condition ?? ''
  )
  const [justification, setJustification] = useState(initial?.justification ?? '')
  const [informedBy, setInformedBy] = useState(initial?.informed_by ?? '')
  const [status, setStatus] = useState<FunctionalRule['status']>(initial?.status ?? 'pending')
  const [saving, setSaving] = useState(false)

  const handleApplyTypeToggle = (type: string) => {
    setAppliesToTypes((prev) =>
      prev.includes(type) ? prev.filter((t) => t !== type) : [...prev, type]
    )
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      await functionalRuleService.saveFunctionalRule(ruleKey, {
        empty_behavior: emptyBehavior || null,
        applies_to_types: appliesToTypes,
        included_health_centers: includedCenters
          ? includedCenters
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        excluded_health_centers: excludedCenters
          ? excludedCenters
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean)
          : [],
        functional_condition: functionalCondition,
        justification,
        informed_by: informedBy,
        status: status as FunctionalRule['status'],
      })
      toast.success('Restricción funcional guardada')
      onSaved()
    } catch {
      toast.error('Error al guardar')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">
            Comportamiento cuando no hay datos
          </label>
          <select
            value={emptyBehavior}
            onChange={(e) => setEmptyBehavior(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
          >
            <option value="">Seleccionar...</option>
            {EMPTY_BEHAVIOR_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">
            Estado de validación
          </label>
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value as FunctionalRule['status'])}
            className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
          >
            {Object.entries(FUNCTIONAL_STATUS_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div>
        <label className="block text-xs font-medium text-slate-500 mb-1">
          Aplicabilidad por tipo de establecimiento
        </label>
        <div className="flex flex-wrap gap-2">
          {APPLIES_TO_OPTIONS.map((opt) => (
            <button
              key={opt.value}
              type="button"
              onClick={() => handleApplyTypeToggle(opt.value)}
              className={`px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors ${
                appliesToTypes.includes(opt.value)
                  ? 'bg-blue-100 text-blue-700 border-blue-300'
                  : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'
              }`}
            >
              {opt.label}
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">
            Establecimientos incluidos
          </label>
          <input
            value={includedCenters}
            onChange={(e) => setIncludedCenters(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
            placeholder="Separar por coma: CESFAM A, CESFAM B"
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">
            Establecimientos excluidos
          </label>
          <input
            value={excludedCenters}
            onChange={(e) => setExcludedCenters(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
            placeholder="Ej: Chanavayita, Caleta Buena"
          />
        </div>
      </div>

      <div>
        <label className="block text-xs font-medium text-slate-500 mb-1">
          Condición funcional (lenguaje natural)
        </label>
        <textarea
          value={functionalCondition}
          onChange={(e) => setFunctionalCondition(e.target.value)}
          rows={2}
          className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
          placeholder="Ej: En Chanavayita esta fila puede quedar vacía."
        />
      </div>

      <div>
        <label className="block text-xs font-medium text-slate-500 mb-1">Justificación</label>
        <textarea
          value={justification}
          onChange={(e) => setJustification(e.target.value)}
          rows={2}
          className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
          placeholder="Motivo de esta restricción..."
        />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-medium text-slate-500 mb-1">
            Responsable que informó
          </label>
          <input
            value={informedBy}
            onChange={(e) => setInformedBy(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
            placeholder="Nombre del informante"
          />
        </div>
        <div className="flex items-end justify-end">
          <button
            onClick={handleSave}
            disabled={saving}
            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50"
          >
            {saving ? 'Guardando...' : 'Guardar restricción'}
          </button>
        </div>
      </div>
    </div>
  )
}
