import { useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/shared/components/ui/dialog'
import { Badge } from '@/shared/components/ui/badge'
import { DataTable } from '@/shared/components/DataTable'
import type { ActivityLogEntry } from '../types'

interface AuditTableProps {
  data: ActivityLogEntry[]
  loading?: boolean
  pagination?: { current_page: number; last_page: number; per_page: number; total: number }
  onPageChange?: (page: number) => void
}

function EventBadge({ event }: { event: string | null }) {
  const map: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' }> = {
    created: { label: 'Creado', variant: 'default' },
    updated: { label: 'Actualizado', variant: 'secondary' },
    deleted: { label: 'Eliminado', variant: 'destructive' },
  }
  const config = map[event ?? ''] ?? { label: event ?? '-', variant: 'secondary' as const }
  return <Badge variant={config.variant}>{config.label}</Badge>
}

function SubjectLabel(subjectType: string): string {
  const map: Record<string, string> = {
    'App\\Models\\User': 'Usuario',
    'App\\Domain\\HealthCenters\\Models\\HealthCenter': 'Centro de Salud',
  }
  return map[subjectType] ?? subjectType.split('\\').pop() ?? subjectType
}

function AuditDetailDialog({
  entry,
  open,
  onOpenChange,
}: {
  entry: ActivityLogEntry | null
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  if (!entry) return null

  const oldData = entry.properties?.old
  const newData = entry.properties?.attributes ?? entry.properties?.new

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Detalle de Auditoría</DialogTitle>
        </DialogHeader>
        <div className="space-y-4">
          <div>
            <p className="text-sm font-medium">Descripción</p>
            <p className="text-sm text-gray-500">{entry.description}</p>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-sm font-medium">Evento</p>
              <EventBadge event={entry.event} />
            </div>
            <div>
              <p className="text-sm font-medium">Entidad</p>
              <p className="text-sm text-gray-500">
                {SubjectLabel(entry.subject_type)} #{entry.subject_id}
              </p>
            </div>
            <div>
              <p className="text-sm font-medium">Usuario</p>
              <p className="text-sm text-gray-500">{entry.causer?.name ?? 'Sistema'}</p>
            </div>
            <div>
              <p className="text-sm font-medium">Fecha</p>
              <p className="text-sm text-gray-500">
                {new Date(entry.created_at).toLocaleString('es-CL')}
              </p>
            </div>
          </div>
          {Boolean(oldData || newData) && (
            <div>
              <p className="text-sm font-medium mb-2">Cambios</p>
              <div className="rounded-md bg-gray-50 p-3">
                <pre className="text-xs text-gray-600 overflow-auto max-h-60">
                  {JSON.stringify({ old: oldData, new: newData }, null, 2)}
                </pre>
              </div>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}

export function AuditTable({ data, loading, pagination, onPageChange }: AuditTableProps) {
  const [selectedEntry, setSelectedEntry] = useState<ActivityLogEntry | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)

  const columns = useMemo<ColumnDef<ActivityLogEntry>[]>(
    () => [
      {
        header: 'Fecha',
        accessorKey: 'created_at',
        cell: ({ row }) => new Date(row.original.created_at).toLocaleString('es-CL'),
      },
      {
        header: 'Usuario',
        accessorKey: 'causer',
        cell: ({ row }) => row.original.causer?.name ?? 'Sistema',
      },
      {
        header: 'Acción',
        accessorKey: 'event',
        cell: ({ row }) => <EventBadge event={row.original.event} />,
      },
      {
        header: 'Entidad',
        cell: ({ row }) => `${SubjectLabel(row.original.subject_type)} #${row.original.subject_id}`,
      },
      {
        header: 'Descripción',
        accessorKey: 'description',
        cell: ({ row }) => (
          <button
            className="text-left text-sm text-blue-600 hover:underline"
            onClick={() => {
              setSelectedEntry(row.original)
              setDetailOpen(true)
            }}
          >
            {row.original.description}
          </button>
        ),
      },
    ],
    []
  )

  return (
    <>
      <DataTable
        columns={columns}
        data={data}
        loading={loading}
        pagination={pagination}
        onPageChange={onPageChange}
      />
      <AuditDetailDialog entry={selectedEntry} open={detailOpen} onOpenChange={setDetailOpen} />
    </>
  )
}
