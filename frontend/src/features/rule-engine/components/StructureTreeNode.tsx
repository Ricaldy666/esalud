import { useState } from 'react'
import { ChevronRight, ChevronDown, FileSpreadsheet, Layers } from 'lucide-react'
import type { StructureForm } from '../types/structure'

interface StructureTreeNodeProps {
  form: StructureForm
}

export function StructureTreeNode({ form }: StructureTreeNodeProps) {
  const [expanded, setExpanded] = useState(false)
  const totalFields = form.sections.reduce((acc, s) => acc + s.campos, 0)
  const totalRules = form.sections.reduce((acc, s) => acc + s.reglas, 0)

  return (
    <div className="border border-slate-200 rounded-lg overflow-hidden">
      <button
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center gap-3 px-4 py-3 bg-slate-50 hover:bg-slate-100 transition-colors text-left"
      >
        {expanded ? (
          <ChevronDown className="h-4 w-4 text-slate-500 shrink-0" />
        ) : (
          <ChevronRight className="h-4 w-4 text-slate-500 shrink-0" />
        )}
        <FileSpreadsheet className="h-4 w-4 text-blue-500 shrink-0" />
        <span className="font-mono text-sm font-medium text-slate-900">{form.sheetName}</span>
        <span className="text-xs text-slate-500">
          {form.sections.length} secciones · {totalFields} campos
          {totalRules > 0 && <span className="text-blue-600 ml-1">· {totalRules} reglas</span>}
        </span>
      </button>

      {expanded && (
        <div className="divide-y divide-slate-100">
          {form.sections.map((section) => (
            <SectionNode key={section.codigo} section={section} />
          ))}
        </div>
      )}
    </div>
  )
}

function SectionNode({
  section,
}: {
  section: {
    codigo: string
    titulo: string
    filaInicioDatos?: number | null
    filaFinDatos?: number | null
    campos: number
    reglas: number
    fields: Array<{ codigo: string; nombre?: string; letra?: string; reglaDetectada?: unknown }>
  }
}) {
  const [expanded, setExpanded] = useState(false)
  const rows =
    section.filaFinDatos && section.filaInicioDatos
      ? section.filaFinDatos - section.filaInicioDatos + 1
      : 0

  return (
    <div className="ml-6">
      <button
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition-colors text-left"
      >
        {expanded ? (
          <ChevronDown className="h-3.5 w-3.5 text-slate-400 shrink-0" />
        ) : (
          <ChevronRight className="h-3.5 w-3.5 text-slate-400 shrink-0" />
        )}
        <Layers className="h-3.5 w-3.5 text-emerald-500 shrink-0" />
        <span className="font-mono text-xs font-medium text-slate-700">{section.codigo}</span>
        <span className="text-xs text-slate-500 truncate">{section.titulo}</span>
        <span className="text-xs text-slate-400 ml-auto shrink-0">
          {section.campos} campos
          {rows > 0 && <span className="ml-1">· {rows} filas</span>}
          {section.reglas > 0 && (
            <span className="text-blue-600 ml-1">· {section.reglas} reglas</span>
          )}
        </span>
      </button>

      {expanded && (
        <div className="ml-6 pb-2">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-slate-400 uppercase">
                <th className="py-1 px-2 font-medium">Código</th>
                <th className="py-1 px-2 font-medium">Nombre</th>
                <th className="py-1 px-2 font-medium">Regla</th>
              </tr>
            </thead>
            <tbody>
              {section.fields.map((field, idx) => {
                const regla = field.reglaDetectada
                const reglaTipo = regla
                  ? typeof regla === 'object' && regla !== null
                    ? (regla as { tipo?: string }).tipo
                    : String(regla)
                  : null
                return (
                  <tr key={`${field.codigo}-${idx}`} className="border-t border-slate-50">
                    <td className="py-1 px-2 font-mono text-slate-700">{field.codigo}</td>
                    <td className="py-1 px-2 text-slate-600">
                      {field.nombre ?? field.letra ?? '—'}
                    </td>
                    <td className="py-1 px-2">
                      {reglaTipo ? (
                        <span
                          className={`inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium ${
                            reglaTipo === 'sum_equals'
                              ? 'bg-blue-50 text-blue-600 border-blue-200'
                              : reglaTipo === 'required_and_le_parent'
                                ? 'bg-amber-50 text-amber-600 border-amber-200'
                                : 'bg-slate-50 text-slate-500 border-slate-200'
                          }`}
                        >
                          {reglaTipo}
                        </span>
                      ) : (
                        <span className="text-slate-300">—</span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
