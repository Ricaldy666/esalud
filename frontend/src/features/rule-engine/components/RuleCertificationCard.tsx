import {
  ArrowLeft,
  CheckCircle2,
  AlertTriangle,
  RotateCcw,
  FileSpreadsheet,
  Save,
  Info,
  AlertCircle,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { toast } from 'sonner'
import type { CertificationCard as CertificationCardType } from '../types/certification'
import type { FunctionalRule } from '../types/functional-rule'
import { CertificationStatusBadge } from './CertificationStatusBadge'
import { FunctionalRuleForm } from './FunctionalRuleForm'
import { certificationService } from '../services/certification'

interface RuleCertificationCardProps {
  card: CertificationCardType
  funcional: FunctionalRule | null
  estructura: { hash: string; version: number; anio: number; serie: string } | null
  onStatusUpdated: () => void
  onFuncionalSaved: () => void
}

const STATUS_ACTIONS = [
  {
    estado: 'Certificada técnicamente' as const,
    icon: CheckCircle2,
    label: 'Certificar técnicamente',
    color: 'text-emerald-600 hover:bg-emerald-50 border-emerald-200',
  },
  {
    estado: 'Requiere revisión' as const,
    icon: AlertTriangle,
    label: 'Requiere revisión',
    color: 'text-red-600 hover:bg-red-50 border-red-200',
  },
  {
    estado: 'Pendiente' as const,
    icon: RotateCcw,
    label: 'Volver a pendiente',
    color: 'text-yellow-600 hover:bg-yellow-50 border-yellow-200',
  },
]

export function RuleCertificationCard({
  card,
  funcional,
  estructura,
  onStatusUpdated,
  onFuncionalSaved,
}: RuleCertificationCardProps) {
  const navigate = useNavigate()
  const [observaciones, setObservaciones] = useState(card.observaciones)
  const [certificadoPor, setCertificadoPor] = useState(card.certificado_por)
  const [saving, setSaving] = useState(false)

  const handleStatusChange = async (estado: CertificationCardType['estado']) => {
    setSaving(true)
    try {
      await certificationService.updateStatus(card.rule_key, {
        estado,
        observaciones,
        certificado_por: certificadoPor,
      })
      toast.success(`Estado actualizado a "${estado}"`)
      onStatusUpdated()
    } catch {
      toast.error('Error al actualizar el estado')
    } finally {
      setSaving(false)
    }
  }

  const handleSaveObservaciones = async () => {
    setSaving(true)
    try {
      await certificationService.updateStatus(card.rule_key, {
        estado: card.estado,
        observaciones,
        certificado_por: certificadoPor,
      })
      toast.success('Observaciones guardadas')
    } catch {
      toast.error('Error al guardar')
    } finally {
      setSaving(false)
    }
  }

  const formulaBg = card.evidencia_xlsm?.encontrada
    ? 'bg-blue-50 border-blue-200'
    : 'bg-gray-50 border-gray-200'

  const sectionPath = `/rule-engine/catalog/${card.hoja}/sections/${card.seccion}`

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2">
        <button
          onClick={() => navigate('/rule-engine/catalog')}
          className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
        >
          <ArrowLeft className="w-4 h-4" />
          Catálogo
        </button>
        <span className="text-gray-300 text-sm">/</span>
        <button
          onClick={() => navigate(sectionPath)}
          className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
        >
          {card.hoja} / Sección {card.seccion}
        </button>
      </div>

      <div className="space-y-6">
        <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
          <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4 text-blue-600" />
            Regla técnica
          </h2>

          <div className="flex items-start justify-between mb-4">
            <div>
              <div className="flex items-center gap-3 mb-2">
                <h1 className="text-xl font-bold text-gray-900 font-mono">{card.rule_key}</h1>
                <CertificationStatusBadge estado={card.estado} />
              </div>
              <div className="flex items-center gap-4 text-sm text-gray-500">
                <span className="font-mono font-semibold text-indigo-600">{card.hoja}</span>
                <span>Sección {card.seccion}</span>
                <span
                  className={`inline-block px-1.5 py-0.5 rounded text-xs font-medium ${
                    card.rule_type === 'sum_equals'
                      ? 'text-amber-600 bg-amber-50'
                      : 'text-rose-600 bg-rose-50'
                  }`}
                >
                  {card.rule_type === 'sum_equals' ? 'Sum_Equals' : 'Required ≤ Parent'}
                </span>
                {card.severity && (
                  <span
                    className={`text-xs uppercase tracking-wider ${card.severity === 'error' ? 'text-red-500' : 'text-gray-400'}`}
                  >
                    {card.severity}
                  </span>
                )}
              </div>
            </div>
          </div>

          {card.description && (
            <div className="mb-4">
              <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                Descripción
              </h3>
              <p className="text-sm text-gray-700">{card.description}</p>
            </div>
          )}

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-4">
            <div>
              <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                Fórmula Interpretada
              </h3>
              <div className={`rounded-lg border p-3 font-mono text-sm text-gray-800 ${formulaBg}`}>
                {card.formula_interpretada}
              </div>
            </div>
            <div>
              <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                Detalles
              </h3>
              <table className="w-full text-sm">
                <tbody>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4 align-top">Columnas origen</td>
                    <td className="py-1">
                      <div className="flex flex-wrap gap-1">
                        {card.columnas_origen.length > 0 ? (
                          card.columnas_origen.map((c) => (
                            <span
                              key={c}
                              className="inline-block bg-gray-100 rounded px-1.5 py-0.5 text-xs font-mono"
                            >
                              {c}
                            </span>
                          ))
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Columna destino</td>
                    <td className="py-1 font-mono font-medium text-gray-800">
                      {card.columna_destino ?? '—'}
                    </td>
                  </tr>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Rango filas</td>
                    <td className="py-1 font-mono text-gray-800">{card.rango_filas ?? '—'}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          {card.evidencia_xlsm?.encontrada && (
            <details className="border border-gray-200 rounded-lg mb-4 group">
              <summary className="flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 rounded-lg cursor-pointer text-sm font-semibold text-gray-700 list-none">
                <span className="flex items-center gap-2">
                  <FileSpreadsheet className="w-4 h-4 text-emerald-600" />
                  Evidencia XLSM
                </span>
              </summary>
              <div className="px-4 pb-3 pt-2">
                <table className="w-full text-sm">
                  <tbody>
                    <tr>
                      <td className="py-1 text-gray-500 pr-4">Sección XLSM</td>
                      <td className="py-1 text-gray-800">
                        {card.evidencia_xlsm.titulo_seccion ?? '—'}
                      </td>
                    </tr>
                    <tr>
                      <td className="py-1 text-gray-500 pr-4">Columna XLSM</td>
                      <td className="py-1 text-gray-800">
                        {card.evidencia_xlsm.label_columna ?? '—'}
                      </td>
                    </tr>
                    <tr>
                      <td className="py-1 text-gray-500 pr-4">Es Total</td>
                      <td className="py-1">
                        {card.evidencia_xlsm.es_total ? (
                          <span className="inline-block w-2 h-2 rounded-full bg-emerald-400" />
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </td>
                    </tr>
                    <tr>
                      <td className="py-1 text-gray-500 pr-4">Control oculto</td>
                      <td className="py-1">
                        {card.evidencia_xlsm.es_control_oculto ? (
                          <span className="inline-block w-2 h-2 rounded-full bg-teal-400" />
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </td>
                    </tr>
                    {card.evidencia_xlsm.regla_detectada && (
                      <tr>
                        <td className="py-1 text-gray-500 pr-4">Regla detectada</td>
                        <td className="py-1 font-mono text-xs text-gray-600">
                          {card.evidencia_xlsm.regla_detectada.tipo}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </details>
          )}
        </div>

        <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
          <h2 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
            <AlertCircle className="w-4 h-4 text-amber-600" />
            Restricción funcional
          </h2>
          <FunctionalRuleForm
            ruleKey={card.rule_key}
            initial={funcional}
            onSaved={onFuncionalSaved}
          />
        </div>

        {estructura && (
          <details className="bg-white rounded-xl border border-gray-200 shadow-sm group">
            <summary className="flex items-center justify-between px-6 py-4 cursor-pointer text-sm font-semibold text-gray-700 list-none hover:bg-gray-50 rounded-xl">
              <span className="flex items-center gap-2">
                <Info className="w-4 h-4 text-gray-500" />
                Impacto de cambio de plantilla
              </span>
            </summary>
            <div className="px-6 pb-4 border-t border-gray-100 pt-3">
              <table className="w-full text-sm">
                <tbody>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Fórmula técnica actual</td>
                    <td className="py-1 font-mono text-gray-800">{card.formula_interpretada}</td>
                  </tr>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Rango actual</td>
                    <td className="py-1 font-mono text-gray-800">{card.rango_filas ?? '—'}</td>
                  </tr>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Hash de estructura</td>
                    <td className="py-1 font-mono text-xs text-gray-500">{estructura.hash}</td>
                  </tr>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Versión de plantilla</td>
                    <td className="py-1 font-mono text-gray-800">
                      v{estructura.version} ({estructura.serie} {estructura.anio})
                    </td>
                  </tr>
                  <tr>
                    <td className="py-1 text-gray-500 pr-4">Estado</td>
                    <td className="py-1">
                      <span className="inline-flex items-center rounded-full border border-gray-200 bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">
                        Sin comparación
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        )}

        <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Acciones</h2>
          <div className="flex flex-wrap gap-2 mb-4">
            {STATUS_ACTIONS.map(({ estado, icon: Icon, label, color }) => (
              <button
                key={estado}
                onClick={() => handleStatusChange(estado)}
                disabled={saving || card.estado === estado}
                className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${color}`}
              >
                <Icon className="w-4 h-4" />
                {label}
              </button>
            ))}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Observaciones</label>
              <textarea
                value={observaciones}
                onChange={(e) => setObservaciones(e.target.value)}
                rows={3}
                className="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                placeholder="Detalles sobre la certificación..."
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">
                Certificado por
              </label>
              <input
                value={certificadoPor}
                onChange={(e) => setCertificadoPor(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none"
                placeholder="Nombre del responsable"
              />
            </div>
          </div>

          <div className="flex justify-end mt-3">
            <button
              onClick={handleSaveObservaciones}
              disabled={saving}
              className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors disabled:opacity-50"
            >
              <Save className="w-4 h-4" />
              {saving ? 'Guardando...' : 'Guardar'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
