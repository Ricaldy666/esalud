import React from 'react'

interface Props {
  form: string
  section: string
  camp: string
  mensaje: string
  recomendacion: string
  affected: number
  total: number
}

export const FormularioErrorCard: React.FC<Props> = ({
  form,
  section,
  camp,
  mensaje,
  recomendacion,
  affected,
  total,
}) => {
  return (
    <div className="border rounded-lg p-4 bg-white hover:shadow-sm transition-shadow">
      <div className="flex items-center gap-2 text-xs text-slate-500 mb-1">
        <span className="font-semibold text-slate-700">{form}</span>
        <span>/</span>
        <span>{section}</span>
      </div>
      <p className="text-sm font-medium text-slate-900 mb-1">{camp}</p>
      <p className="text-sm text-slate-600 mb-1">{mensaje}</p>
      <p className="text-xs text-blue-700 mb-2">{recomendacion}</p>
      <div className="flex gap-4 text-xs">
        <span className="text-red-600">
          Afectadas: {affected}/{total}
        </span>
      </div>
    </div>
  )
}
