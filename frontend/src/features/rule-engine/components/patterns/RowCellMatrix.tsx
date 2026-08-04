import type { PatternRow } from '../../types/calibration'

interface Props {
  row: PatternRow
  colTotal?: string
  totalColumns?: string[]
  colsOrigen: string[]
}

export default function RowCellMatrix({ row, colTotal, totalColumns, colsOrigen }: Props) {
  const totals = totalColumns?.length ? totalColumns : ([colTotal].filter(Boolean) as string[])
  const allCols = Array.from(
    new Set([...totals, ...colsOrigen, ...row.especiales.map((e) => e.letra)])
  ).sort()

  const getCellStyle = (lc: string) => {
    if (totals.includes(lc)) return { background: '#FFFFFF', border: '2px solid #4F46E5' }

    const inEditables = row.editables.find((e) => e.letra === lc)
    if (inEditables) return { background: '#FFFFCC', border: '1px solid #ccc' }

    const inBloqueadas = row.bloqueadas.find((b) => b.letra === lc)
    if (inBloqueadas) return { background: '#C0C0C0', border: '1px solid #999' }

    const inEspeciales = row.especiales.find((e) => e.letra === lc)
    if (inEspeciales) {
      return inEspeciales.editable
        ? { background: '#FFFFCC', border: '1px solid #ccc' }
        : { background: '#C0C0C0', border: '1px solid #999' }
    }

    return { background: '#FFFFFF', border: '1px solid #eee' }
  }

  const titles: Record<string, string> = {}
  totals.forEach((total) => {
    titles[total] = `${total} (total)`
  })
  row.editables.forEach((e) => {
    titles[e.letra] = `${e.letra} (editable)`
  })
  row.bloqueadas.forEach((b) => {
    titles[b.letra] = `${b.letra} (bloqueada)`
  })
  row.especiales.forEach((e) => {
    titles[e.letra] = `${e.letra} (especial)`
  })

  return (
    <div className="flex flex-wrap gap-0.5" style={{ maxWidth: 200 }}>
      {allCols.map((lc) => (
        <span
          key={lc}
          className="inline-block w-3 h-3 rounded-sm"
          style={getCellStyle(lc)}
          title={titles[lc] || lc}
        />
      ))}
    </div>
  )
}
