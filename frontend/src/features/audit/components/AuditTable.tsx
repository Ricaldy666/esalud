import { useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { History } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Badge } from '@/shared/components/ui/badge'
import { DataTable } from '@/shared/components/DataTable'
import type { ActivityLogEntry } from '../types'

interface AuditTableProps {
  data: ActivityLogEntry[]
  loading?: boolean
  pagination?: { current_page: number; last_page: number; per_page: number; total: number }
  onPageChange?: (page: number) => void
}

const EVENT_BADGE_STYLES: Record<string, string> = {
  created: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  updated: 'bg-blue-50 text-blue-700 border-blue-200',
  deleted: 'bg-red-50 text-red-700 border-red-200',
}
const EVENT_LABELS: Record<string, string> = {
  created: 'Creado',
  updated: 'Actualizado',
  deleted: 'Eliminado',
}

function EventBadge({ event }: { event: string | null }) {
  const key = event ?? ''
  return (
    <Badge
      variant="outline"
      className={`font-medium ${EVENT_BADGE_STYLES[key] ?? 'bg-slate-100 text-slate-600 border-slate-200'}`}
    >
      {EVENT_LABELS[key] ?? event ?? '-'}
    </Badge>
  )
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
      <DialogContent className="w-full border border-slate-200 bg-white shadow-xl sm:max-w-lg">
        <DialogHeader className="border-b border-slate-100 pb-4">
          <div className="flex items-center gap-3">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white">
              <History className="size-5" />
            </div>
            <div>
              <DialogTitle className="text-lg font-bold text-slate-900">
                Detalle de Auditoría
              </DialogTitle>
              <DialogDescription className="text-slate-500">
                Registro completo del cambio seleccionado.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>
        <div className="space-y-4">
          <div>
            <p className="text-sm font-medium text-slate-700">Descripción</p>
            <p className="text-sm text-slate-500">{entry.description}</p>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <p className="text-sm font-medium text-slate-700">Evento</p>
              <EventBadge event={entry.event} />
            </div>
            <div>
              <p className="text-sm font-medium text-slate-700">Entidad</p>
              <p className="text-sm text-slate-500">
                {SubjectLabel(entry.subject_type)} #{entry.subject_id}
              </p>
            </div>
            <div>
              <p className="text-sm font-medium text-slate-700">Usuario</p>
              <p className="text-sm text-slate-500">{entry.causer?.name ?? 'Sistema'}</p>
            </div>
            <div>
              <p className="text-sm font-medium text-slate-700">Fecha</p>
              <p className="text-sm text-slate-500">
                {new Date(entry.created_at).toLocaleString('es-CL')}
              </p>
            </div>
          </div>
          {Boolean(oldData || newData) && (
            <div>
              <p className="mb-2 text-sm font-medium text-slate-700">Cambios</p>
              <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                <pre className="max-h-60 overflow-auto text-xs text-slate-600">
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
        cell: ({ row }) => (
          <span className="text-slate-600">
            {new Date(row.original.created_at).toLocaleString('es-CL')}
          </span>
        ),
      },
      {
        header: 'Usuario',
        accessorKey: 'causer',
        cell: ({ row }) => (
          <span className="text-slate-600">{row.original.causer?.name ?? 'Sistema'}</span>
        ),
      },
      {
        header: 'Acción',
        accessorKey: 'event',
        cell: ({ row }) => <EventBadge event={row.original.event} />,
      },
      {
        header: 'Entidad',
        cell: ({ row }) => (
          <span className="text-slate-600">
            {SubjectLabel(row.original.subject_type)} #{row.original.subject_id}
          </span>
        ),
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
