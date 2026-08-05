import { useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  FileSpreadsheet,
  UploadCloud,
} from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { EmptyState } from '@/shared/components/EmptyState'
import { DataTable } from '@/shared/components/DataTable'
import { Button } from '@/shared/components/ui/button'
import { Badge } from '@/shared/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/shared/components/ui/select'
import { useHealthCenters } from '@/features/health-centers/hooks/useHealthCenters'
import { useRemUploads } from '../hooks/useRemUploads'
import { RemUploadForm } from '../components/RemUploadForm'
import { REM_TYPE_LABELS, type RemType } from '../types/rem'
import type { RemUpload, RemUploadStatus } from '../types/rem'

const CURRENT_YEAR = new Date().getFullYear()
const YEARS = Array.from({ length: 6 }, (_, i) => CURRENT_YEAR - i)
const MONTHS = [
  { value: '1', label: 'Enero' },
  { value: '2', label: 'Febrero' },
  { value: '3', label: 'Marzo' },
  { value: '4', label: 'Abril' },
  { value: '5', label: 'Mayo' },
  { value: '6', label: 'Junio' },
  { value: '7', label: 'Julio' },
  { value: '8', label: 'Agosto' },
  { value: '9', label: 'Septiembre' },
  { value: '10', label: 'Octubre' },
  { value: '11', label: 'Noviembre' },
  { value: '12', label: 'Diciembre' },
]

const SELECT_TRIGGER_CLASS =
  'h-8 w-full border-slate-300 bg-white text-sm text-slate-900 focus-visible:border-blue-500 focus-visible:ring-blue-500/30'
const SELECT_CONTENT_CLASS = 'border border-slate-200 bg-white shadow-lg'
const SELECT_ITEM_CLASS = 'text-slate-700 focus:bg-blue-50 focus:text-blue-700'

const statusStyles: Record<RemUploadStatus, string> = {
  pending: 'bg-slate-100 text-slate-600 border-slate-200',
  processing: 'bg-blue-50 text-blue-700 border-blue-200',
  validating: 'bg-blue-50 text-blue-700 border-blue-200',
  success: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  with_errors: 'bg-amber-50 text-amber-700 border-amber-200',
  rejected: 'bg-red-50 text-red-700 border-red-200',
  failed: 'bg-red-50 text-red-700 border-red-200',
}

const statusLabel: Record<RemUploadStatus, string> = {
  pending: 'Pendiente',
  processing: 'Procesando',
  validating: 'Validando',
  success: 'Correcto',
  with_errors: 'Con observaciones',
  rejected: 'Rechazado',
  failed: 'Fallido',
}

export default function RemUploadsPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [filterCenter, setFilterCenter] = useState<number>(0)
  const [filterType, setFilterType] = useState('')
  const [filterYear, setFilterYear] = useState(0)
  const [filterMonth, setFilterMonth] = useState(0)
  const { data, isLoading, isError } = useRemUploads({ page, per_page: 15 })
  const { data: healthCentersPage } = useHealthCenters()
  const healthCenters = healthCentersPage?.data ?? []

  const filteredUploads = useMemo(() => {
    if (!data?.data?.length) return []
    return data.data.filter((upload: RemUpload) => {
      if (filterType && upload.rem_type !== filterType) return false
      if (filterYear && upload.year !== filterYear) return false
      if (filterMonth && upload.month !== filterMonth) return false
      if (filterCenter && upload.health_center?.id !== filterCenter) return false
      return true
    })
  }, [data, filterCenter, filterType, filterYear, filterMonth])

  const columns = useMemo<ColumnDef<RemUpload>[]>(
    () => [
      {
        header: 'ID',
        accessorKey: 'id',
        cell: ({ row }) => <span className="font-mono text-xs">{row.original.id}</span>,
      },
      {
        header: 'Archivo',
        accessorKey: 'original_filename',
        cell: ({ row }) => (
          <span className="block max-w-[200px] truncate font-medium text-slate-900">
            {row.original.original_filename}
          </span>
        ),
      },
      {
        header: 'Tipo',
        accessorKey: 'rem_type',
        cell: ({ row }) => (
          <span className="text-xs font-medium text-slate-600">
            {REM_TYPE_LABELS[row.original.rem_type as RemType] ?? row.original.rem_type}
          </span>
        ),
      },
      {
        header: 'Periodo',
        accessorKey: 'month',
        cell: ({ row }) => (
          <span className="whitespace-nowrap">
            {String(row.original.month).padStart(2, '0')}/{row.original.year}
          </span>
        ),
      },
      {
        header: 'Estado',
        accessorKey: 'status',
        cell: ({ row }) => (
          <Badge variant="outline" className={`font-medium ${statusStyles[row.original.status]}`}>
            {statusLabel[row.original.status]}
          </Badge>
        ),
      },
      {
        header: 'Cumplimiento',
        id: 'cumplimiento',
        cell: ({ row }) => {
          const status = row.original.status
          if (status === 'success') {
            return (
              <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700">
                <CheckCircle2 className="size-3.5" /> Ok
              </span>
            )
          }
          if (status === 'with_errors') {
            return (
              <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700">
                <AlertTriangle className="size-3.5" /> Observado
              </span>
            )
          }
          return <span className="text-xs text-slate-400">—</span>
        },
      },
      {
        header: 'Centro',
        accessorKey: 'health_center',
        cell: ({ row }) => (
          <span className="whitespace-nowrap">{row.original.health_center?.name ?? '-'}</span>
        ),
      },
      {
        header: 'Fecha',
        accessorKey: 'created_at',
        cell: ({ row }) => (
          <span className="text-xs whitespace-nowrap">
            {new Date(row.original.created_at).toLocaleDateString('es-CL')}
          </span>
        ),
      },
      {
        id: 'acciones',
        header: 'Acciones',
        cell: ({ row }) => {
          const upload = row.original
          if (upload.status === 'pending' || upload.status === 'processing') return null
          return (
            <Button
              type="button"
              variant="outline"
              size="xs"
              onClick={() => navigate(`/rule-engine/uploads/${upload.id}/validation-summary`)}
              className="gap-1 border-slate-300 bg-white text-xs text-slate-700 hover:bg-slate-50"
            >
              <ExternalLink className="size-3" />
              Ver resultado
            </Button>
          )
        },
      },
    ],
    [navigate]
  )

  if (isError) {
    return (
      <div className="mx-auto max-w-6xl">
        <PageHeader
          title="Cargas REM"
          description="Archivos REM subidos al sistema"
          icon={UploadCloud}
        />
        <EmptyState
          icon={<FileSpreadsheet className="h-12 w-12" />}
          title="Error al cargar"
          description="No se pudieron obtener las cargas REM. Verifica la conexión con el servidor."
        />
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Carga de Datos REM"
        description="Sube y valida archivos REM antes del envío formal"
        icon={UploadCloud}
      />

      <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
        <p className="text-sm text-blue-800">
          Selecciona un archivo Excel REM. El sistema detectará automáticamente la serie, el período
          y el establecimiento desde el contenido del archivo.
        </p>
      </div>

      <RemUploadForm onClose={() => {}} alwaysVisible />

      {/* Historial de cargas */}
      <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-900">Historial de cargas</h2>

        <div className="mb-4 flex flex-wrap items-center gap-2">
          <div className="w-36">
            <Select
              value={filterType}
              onValueChange={(v: string | null) => {
                setFilterType(v ?? '')
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Tipo REM" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                {Object.entries(REM_TYPE_LABELS).map(([v, l]) => (
                  <SelectItem key={v} value={v} className={SELECT_ITEM_CLASS}>
                    {l}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="w-28">
            <Select
              value={filterYear ? String(filterYear) : ''}
              onValueChange={(v: string | null) => {
                setFilterYear(v ? Number(v) : 0)
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Año" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                {YEARS.map((y) => (
                  <SelectItem key={y} value={String(y)} className={SELECT_ITEM_CLASS}>
                    {y}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="w-32">
            <Select
              value={filterMonth ? String(filterMonth) : ''}
              onValueChange={(v: string | null) => {
                setFilterMonth(v ? Number(v) : 0)
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Mes" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                {MONTHS.map((m) => (
                  <SelectItem key={m.value} value={m.value} className={SELECT_ITEM_CLASS}>
                    {m.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="w-48">
            <Select
              value={filterCenter ? String(filterCenter) : ''}
              onValueChange={(v: string | null) => {
                setFilterCenter(v ? Number(v) : 0)
                setPage(1)
              }}
            >
              <SelectTrigger className={SELECT_TRIGGER_CLASS}>
                <SelectValue placeholder="Todos los centros" />
              </SelectTrigger>
              <SelectContent alignItemWithTrigger={false} className={SELECT_CONTENT_CLASS}>
                {healthCenters.map((c) => (
                  <SelectItem key={c.id} value={String(c.id)} className={SELECT_ITEM_CLASS}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <span className="ml-1 text-xs text-slate-400 italic">
            Use estos filtros solo para buscar cargas anteriores. La carga nueva se detecta
            automáticamente desde el archivo.
          </span>
        </div>

        <DataTable
          columns={columns}
          data={filteredUploads}
          loading={isLoading}
          pagination={data?.meta}
          onPageChange={setPage}
          emptyMessage="No hay cargas REM. Sube un archivo REM para comenzar."
        />
      </div>
    </div>
  )
}
