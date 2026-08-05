import { useState } from 'react'
import { History } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { Input } from '@/shared/components/ui/input'
import { Label } from '@/shared/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import { useActivityLog } from '@/features/audit'
import { AuditTable } from '@/features/audit/components/AuditTable'

const EVENT_OPTIONS = [
  { value: 'all', label: 'Todos los eventos' },
  { value: 'created', label: 'Creado' },
  { value: 'updated', label: 'Actualizado' },
  { value: 'deleted', label: 'Eliminado' },
]

const ENTITY_OPTIONS = [
  { value: 'all', label: 'Todas las entidades' },
  { value: 'App\\Models\\User', label: 'Usuario' },
  { value: 'App\\Domain\\HealthCenters\\Models\\HealthCenter', label: 'Centro de Salud' },
]

const SELECT_TRIGGER_CLASS =
  'h-9 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'
const INPUT_CLASS =
  'h-9 border-slate-300 bg-white text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const LABEL_CLASS = 'text-xs text-slate-600'

export default function AuditPage() {
  const [page, setPage] = useState(1)
  const [event, setEvent] = useState('')
  const [subjectType, setSubjectType] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [causerSearch, setCauserSearch] = useState('')

  const { data, isLoading } = useActivityLog({
    page,
    event: event || undefined,
    subject_type: subjectType || undefined,
    from: from || undefined,
    to: to || undefined,
  })

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Auditoría"
        description="Historial de cambios en el sistema"
        icon={History}
      />

      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <div className="space-y-1">
            <Label className={LABEL_CLASS}>Evento</Label>
            <Select
              value={event || 'all'}
              onValueChange={(v: string | null) => {
                setEvent(v && v !== 'all' ? v : '')
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Evento" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                {EVENT_OPTIONS.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value} className={SELECT_ITEM_CLASS}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className={LABEL_CLASS}>Entidad</Label>
            <Select
              value={subjectType || 'all'}
              onValueChange={(v: string | null) => {
                setSubjectType(v && v !== 'all' ? v : '')
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Entidad" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                {ENTITY_OPTIONS.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value} className={SELECT_ITEM_CLASS}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className={LABEL_CLASS}>Desde</Label>
            <Input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value)
                setPage(1)
              }}
              className={INPUT_CLASS}
            />
          </div>

          <div className="space-y-1">
            <Label className={LABEL_CLASS}>Hasta</Label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value)
                setPage(1)
              }}
              className={INPUT_CLASS}
            />
          </div>

          <div className="space-y-1">
            <Label className={LABEL_CLASS}>Usuario</Label>
            <Input
              placeholder="Nombre o email"
              value={causerSearch}
              onChange={(e) => setCauserSearch(e.target.value)}
              className={INPUT_CLASS}
            />
          </div>
        </div>

        <AuditTable
          data={data?.data ?? []}
          loading={isLoading}
          pagination={data?.meta}
          onPageChange={setPage}
        />
      </div>
    </div>
  )
}
