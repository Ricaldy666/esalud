import { useHealthCheck } from '@/features/health'

export default function HealthCheckPage() {
  const { data, isLoading, isError, error } = useHealthCheck()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50">
        <div className="rounded-lg bg-white p-8 shadow-lg">
          <div className="flex items-center gap-3">
            <div className="h-4 w-4 animate-pulse rounded-full bg-yellow-400" />
            <span className="text-lg text-gray-600">Verificando conexión con el servidor...</span>
          </div>
        </div>
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50">
        <div className="rounded-lg bg-white p-8 shadow-lg">
          <div className="flex items-center gap-3">
            <div className="h-4 w-4 rounded-full bg-red-500" />
            <span className="text-lg text-red-600">Error de conexión</span>
          </div>
          <p className="mt-2 text-sm text-gray-500">
            {error instanceof Error ? error.message : 'No se pudo conectar con la API'}
          </p>
        </div>
      </div>
    )
  }

  const health = data?.data

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50">
      <div className="w-full max-w-md rounded-lg bg-white p-8 shadow-lg">
        <div className="mb-6 flex items-center gap-3">
          <div
            className={`h-4 w-4 rounded-full ${
              health?.status === 'ok' ? 'bg-green-500' : 'bg-red-500'
            }`}
          />
          <h1 className="text-2xl font-bold text-gray-900">Estado del Servicio</h1>
        </div>

        {health && (
          <div className="space-y-3">
            <div className="flex justify-between border-b pb-2">
              <span className="text-gray-500">Servicio</span>
              <span className="font-medium text-gray-900">{health.service}</span>
            </div>
            <div className="flex justify-between border-b pb-2">
              <span className="text-gray-500">Versión</span>
              <span className="font-medium text-gray-900">{health.version}</span>
            </div>
            <div className="flex justify-between border-b pb-2">
              <span className="text-gray-500">Estado</span>
              <span
                className={`font-medium ${
                  health.status === 'ok' ? 'text-green-600' : 'text-red-600'
                }`}
              >
                {health.status === 'ok' ? 'Operativo' : health.status}
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Última verificación</span>
              <span className="font-medium text-gray-900">
                {new Date(health.timestamp).toLocaleString('es-CL')}
              </span>
            </div>
          </div>
        )}

        {data?.message && <p className="mt-4 text-sm text-gray-500 italic">{data.message}</p>}
      </div>
    </div>
  )
}
