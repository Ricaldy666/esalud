import { useState, useMemo } from 'react'
import { FileSpreadsheet, ExternalLink, CheckCircle2, AlertTriangle } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { EmptyState } from '@/shared/components/EmptyState'
import { Skeleton } from '@/shared/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/components/ui/table'
import { Button } from '@/shared/components/ui/button'
import { useRemUploads } from '../hooks/useRemUploads'
import { RemUploadForm } from '../components/RemUploadForm'
import { REM_TYPE_LABELS, type RemType } from '../types/rem'
import type { RemUpload, RemUploadStatus } from '../types/rem'
import { useHealthCenters } from '@/features/health-centers/hooks/useHealthCenters'
import { useNavigate } from 'react-router-dom'

const CURRENT_YEAR = new Date().getFullYear()
const YEARS = Array.from({ length: 6 }, (_, i) => CURRENT_YEAR - i)
const MONTHS = [
  { value: 0, label: 'Mes' },
  { value: 1, label: 'Enero' },
  { value: 2, label: 'Febrero' },
  { value: 3, label: 'Marzo' },
  { value: 4, label: 'Abril' },
  { value: 5, label: 'Mayo' },
  { value: 6, label: 'Junio' },
  { value: 7, label: 'Julio' },
  { value: 8, label: 'Agosto' },
  { value: 9, label: 'Septiembre' },
  { value: 10, label: 'Octubre' },
  { value: 11, label: 'Noviembre' },
  { value: 12, label: 'Diciembre' },
]

const statusStyles: Record<RemUploadStatus, string> = {
  pending: 'bg-slate-100 text-slate-600 border border-slate-200',
  processing: 'bg-blue-50 text-blue-700 border border-blue-200',
  validating: 'bg-blue-50 text-blue-700 border border-blue-200',
  success: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
  with_errors: 'bg-amber-50 text-amber-700 border border-amber-200',
  rejected: 'bg-red-100 text-red-800 border border-red-300',
  failed: 'bg-red-100 text-red-800 border border-red-300',
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

  if (isError) {
    return (
      <div>
        <PageHeader title="Cargas REM" description="Archivos REM subidos al sistema" />
        <EmptyState
          icon={<FileSpreadsheet className="h-12 w-12" />}
          title="Error al cargar"
          description="No se pudieron obtener las cargas REM. Verifica la conexión con el servidor."
        />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">Carga de Datos REM</h1>
        <p className="text-sm text-slate-500 mt-1">
          Sube y valida archivos REM antes del envío formal
        </p>
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <p className="text-sm text-blue-800">
          Selecciona un archivo Excel REM. El sistema detectará automáticamente la serie, el período
          y el establecimiento desde el contenido del archivo.
        </p>
      </div>

      <RemUploadForm onClose={() => {}} alwaysVisible />

      {/* Historial de cargas */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-lg font-semibold text-slate-900">Historial de cargas</h2>
        </div>

        <div className="flex items-center gap-2 mb-3">
          <select
            value={filterType}
            onChange={(e) => {
              setFilterType(e.target.value)
              setPage(1)
            }}
            className="h-8 rounded-md border border-slate-200 bg-white px-2 text-sm"
          >
            <option value="">Tipo REM</option>
            {Object.entries(REM_TYPE_LABELS).map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
          <select
            value={filterYear}
            onChange={(e) => {
              setFilterYear(Number(e.target.value))
              setPage(1)
            }}
            className="h-8 rounded-md border border-slate-200 bg-white px-2 text-sm"
          >
            <option value={0}>Año</option>
            {YEARS.map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
          <select
            value={filterMonth}
            onChange={(e) => {
              setFilterMonth(Number(e.target.value))
              setPage(1)
            }}
            className="h-8 rounded-md border border-slate-200 bg-white px-2 text-sm"
          >
            {MONTHS.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
          <select
            value={filterCenter}
            onChange={(e) => {
              setFilterCenter(Number(e.target.value))
              setPage(1)
            }}
            className="h-8 rounded-md border border-slate-200 bg-white px-2 text-sm"
          >
            <option value={0}>Todos los centros</option>
            {healthCenters.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
          <span className="text-xs text-slate-400 italic ml-1">
            Use estos filtros solo para buscar cargas anteriores. La carga nueva se detecta
            automáticamente desde el archivo.
          </span>
        </div>

        <div className="rounded-md border overflow-x-auto">
          <Table className="min-w-[900px]">
            <TableHeader>
              <TableRow>
                <TableHead className="w-16">ID</TableHead>
                <TableHead>Archivo</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Periodo</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Cumplimiento</TableHead>
                <TableHead>Centro</TableHead>
                <TableHead>Fecha</TableHead>
                <TableHead>Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <TableRow key={i}>
                    {Array.from({ length: 9 }).map((_, j) => (
                      <TableCell key={j}>
                        <Skeleton className="h-4 w-full" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              ) : !filteredUploads.length ? (
                <TableRow>
                  <TableCell colSpan={9}>
                    <EmptyState
                      icon={<FileSpreadsheet className="h-12 w-12" />}
                      title="No hay cargas REM"
                      description="Sube un archivo REM para comenzar."
                    />
                  </TableCell>
                </TableRow>
              ) : (
                filteredUploads.map((upload: RemUpload) => (
                  <TableRow key={upload.id}>
                    <TableCell className="font-mono text-xs">{upload.id}</TableCell>
                    <TableCell className="max-w-[200px] truncate font-medium">
                      {upload.original_filename}
                    </TableCell>
                    <TableCell>
                      <span className="text-xs font-medium text-slate-600">
                        {REM_TYPE_LABELS[upload.rem_type as RemType] ?? upload.rem_type}
                      </span>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      {String(upload.month).padStart(2, '0')}/{upload.year}
                    </TableCell>
                    <TableCell>
                      <span
                        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusStyles[upload.status]}`}
                      >
                        {statusLabel[upload.status]}
                      </span>
                    </TableCell>
                    <TableCell>
                      {upload.status === 'success' ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700">
                          <CheckCircle2 className="w-3.5 h-3.5" /> Ok
                        </span>
                      ) : upload.status === 'with_errors' ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700">
                          <AlertTriangle className="w-3.5 h-3.5" /> Observado
                        </span>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      {upload.health_center?.name ?? '-'}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-xs">
                      {new Date(upload.created_at).toLocaleDateString('es-CL')}
                    </TableCell>
                    <TableCell>
                      {upload.status !== 'pending' && upload.status !== 'processing' && (
                        <Button
                          variant="outline"
                          size="xs"
                          onClick={() =>
                            navigate(`/rule-engine/uploads/${upload.id}/validation-summary`)
                          }
                          className="text-xs gap-1"
                        >
                          <ExternalLink className="w-3 h-3" />
                          Ver resultado
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>

        {data?.meta && data.meta.last_page > 1 && (
          <div className="flex items-center justify-between mt-4">
            <p className="text-sm text-gray-500">
              Página {data.meta.current_page} de {data.meta.last_page} ({data.meta.total} registros)
            </p>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                Anterior
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page >= data.meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
