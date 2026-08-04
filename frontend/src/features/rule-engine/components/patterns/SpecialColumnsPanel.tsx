import type { CalibrationRow, ColumnGroup, PatternGroup } from '../../types/calibration'

interface Props {
  columns: string[]
  columnGroups?: ColumnGroup[]
  allRows: CalibrationRow[]
  patterns: PatternGroup[]
}

function legacyGroup(
  columns: string[],
  allRows: CalibrationRow[],
  patterns: PatternGroup[]
): ColumnGroup[] {
  if (columns.length === 0) return []

  return [
    {
      key: 'legacy_special_columns',
      label: 'Columnas especiales U:AH',
      type: 'legacy_special',
      start_column: columns[0] ?? '',
      end_column: columns[columns.length - 1] ?? '',
      columns: columns.map((letter) => {
        const stats = getLegacyColStats(letter, allRows, patterns)
        return {
          letter,
          label: stats.colLabel,
          editable_rows: stats.editableRows,
          blocked_rows: stats.blockedRows,
        }
      }),
      editable_rows: columns.reduce(
        (acc, letter) => acc + getLegacyColStats(letter, allRows, patterns).editableRows,
        0
      ),
      blocked_rows: columns.reduce(
        (acc, letter) => acc + getLegacyColStats(letter, allRows, patterns).blockedRows,
        0
      ),
      source: 'legacy_a01_a',
      description:
        'Estas columnas no forman parte de la formula de C y se revisan como definicion funcional independiente.',
    },
  ]
}

function getLegacyColStats(colLetter: string, allRows: CalibrationRow[], patterns: PatternGroup[]) {
  let editableRows = 0
  let blockedRows = 0
  let firstType = ''

  for (const p of patterns) {
    for (const pr of p.rows) {
      for (const ec of pr.especiales) {
        if (ec.letra === colLetter) {
          if (ec.editable) editableRows++
          else blockedRows++
          if (!firstType) firstType = ec.tipo_celda
        }
      }
    }
  }

  let colLabel = ''
  for (const r of allRows) {
    for (const ch of r.columnas_habilitadas ?? []) {
      if (ch.letra === colLetter) {
        colLabel = ch.label
        break
      }
    }
    if (colLabel) break
  }

  return { colLabel, editableRows, blockedRows, firstType }
}

function groupQuestion(group: ColumnGroup) {
  if (group.type === 'main_rule') {
    const total = group.total ?? group.start_column
    const labels = group.labels ?? {}
    const totalLabel = labels[total] || 'Total'
    const components = group.components ?? []
    const componentText = components
      .map((column) => `${labels[column] || column} (${column})`)
      .join(' más ')
    return `¿El ${totalLabel} (${total}) debe ser igual a ${componentText}?`
  }
  if (group.type === 'age_range') {
    return `¿Las columnas del rango etario ${group.start_column}:${group.end_column} deben completarse obligatoriamente con 0 cuando no existan datos o pueden quedar vacías?`
  }
  if (group.type === 'complementary') {
    return `¿Las variables complementarias ${group.start_column}:${group.end_column} se validan de forma independiente?`
  }
  return `¿Las columnas ${group.start_column}:${group.end_column} se validan de manera independiente?`
}

function statusText(editableRows: number, blockedRows: number) {
  if (editableRows > 0 && blockedRows > 0)
    return 'Mixta: editable en algunas filas y bloqueada en otras'
  if (editableRows > 0) return 'Editable'
  return 'Bloqueada'
}

export default function SpecialColumnsPanel({ columns, columnGroups, allRows, patterns }: Props) {
  const groups = columnGroups?.length ? columnGroups : legacyGroup(columns, allRows, patterns)
  if (groups.length === 0) return null

  return (
    <div className="space-y-4">
      {groups.map((group) => (
        <div
          key={group.key}
          className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
        >
          <div className="border-b border-gray-200 px-6 py-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-base font-semibold text-gray-900">{group.label}</h3>
              <span className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                {group.start_column}:{group.end_column}
              </span>
            </div>
            <p className="mt-1 text-sm text-gray-500">{group.description}</p>
            {group.type === 'main_rule' && group.formula && (
              <p className="mt-2 text-sm font-medium text-indigo-700">
                Regla principal: {group.formula}
              </p>
            )}
            {group.type === 'age_range' && group.subgroups?.length ? (
              <div className="mt-3 grid gap-2 md:grid-cols-2">
                {group.subgroups.map((subgroup) => (
                  <div
                    key={subgroup.key}
                    className="rounded-lg border border-gray-100 bg-gray-50 p-3"
                  >
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                      {subgroup.label}
                    </p>
                    <p className="mt-1 font-mono text-xs text-gray-700">
                      {subgroup.columns.map((column) => column.letter).join(', ')}
                    </p>
                  </div>
                ))}
              </div>
            ) : null}
          </div>

          <div className="overflow-x-auto p-6">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-gray-50 text-gray-500 uppercase tracking-wider">
                  <th className="px-3 py-2 text-left font-mono">Col</th>
                  <th className="px-3 py-2 text-left">Encabezado</th>
                  <th className="px-3 py-2 text-center">Filas editables</th>
                  <th className="px-3 py-2 text-center">Filas bloqueadas</th>
                  <th className="px-3 py-2 text-left">Estado</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {group.columns.map((column) => (
                  <tr key={`${group.key}-${column.letter}`} className="hover:bg-gray-50">
                    <td className="px-3 py-2 font-mono font-bold text-gray-700">{column.letter}</td>
                    <td className="max-w-[260px] px-3 py-2 text-gray-600">
                      {column.label || 'Sin encabezado informado'}
                    </td>
                    <td className="px-3 py-2 text-center font-mono text-green-600">
                      {column.editable_rows}
                    </td>
                    <td className="px-3 py-2 text-center font-mono text-gray-500">
                      {column.blocked_rows}
                    </td>
                    <td className="px-3 py-2 text-gray-500">
                      {statusText(column.editable_rows, column.blocked_rows)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="mt-4 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-700">
              <strong>Pregunta:</strong> {groupQuestion(group)}
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}
