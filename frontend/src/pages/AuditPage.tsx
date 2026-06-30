import { useState } from 'react'
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
  { value: '', label: 'Todos los eventos' },
  { value: 'created', label: 'Creado' },
  { value: 'updated', label: 'Actualizado' },
  { value: 'deleted', label: 'Eliminado' },
]

const ENTITY_OPTIONS = [
  { value: '', label: 'Todas las entidades' },
  { value: 'App\\Models\\User', label: 'Usuario' },
  { value: 'App\\Domain\\HealthCenters\\Models\\HealthCenter', label: 'Centro de Salud' },
]

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
    <div>
      <PageHeader title="Auditoría" description="Historial de cambios en el sistema" />

      <div className="mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div className="space-y-1">
          <Label className="text-xs">Evento</Label>
          <Select
            value={event}
            onValueChange={(v: string | null) => {
              setEvent(v ?? '')
              setPage(1)
            }}
          >
            <SelectTrigger className="h-9">
              <SelectValue placeholder="Evento" />
            </SelectTrigger>
            <SelectContent>
              {EVENT_OPTIONS.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1">
          <Label className="text-xs">Entidad</Label>
          <Select
            value={subjectType}
            onValueChange={(v: string | null) => {
              setSubjectType(v ?? '')
              setPage(1)
            }}
          >
            <SelectTrigger className="h-9">
              <SelectValue placeholder="Entidad" />
            </SelectTrigger>
            <SelectContent>
              {ENTITY_OPTIONS.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1">
          <Label className="text-xs">Desde</Label>
          <Input
            type="date"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value)
              setPage(1)
            }}
            className="h-9"
          />
        </div>

        <div className="space-y-1">
          <Label className="text-xs">Hasta</Label>
          <Input
            type="date"
            value={to}
            onChange={(e) => {
              setTo(e.target.value)
              setPage(1)
            }}
            className="h-9"
          />
        </div>

        <div className="space-y-1">
          <Label className="text-xs">Usuario</Label>
          <Input
            placeholder="Nombre o email"
            value={causerSearch}
            onChange={(e) => setCauserSearch(e.target.value)}
            className="h-9"
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
  )
}
