import type { ReactNode } from 'react'
import { BarChart3, ShieldCheck } from 'lucide-react'

interface AuthLayoutProps {
  children: ReactNode
  title: string
  description?: string
}

export default function AuthLayout({ children, title, description }: AuthLayoutProps) {
  return (
    <div className="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-blue-50 via-white to-blue-100 px-4 py-10">
      {/* Formas suaves decorativas en las esquinas */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -top-24 -left-24 size-72 rounded-full bg-blue-300/30 blur-3xl"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-24 -bottom-24 size-80 rounded-full bg-blue-400/20 blur-3xl"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute top-1/3 -right-16 size-56 rounded-full bg-sky-200/30 blur-3xl"
      />

      {/* Puntos decorativos discretos, estilo dashboard/analitica */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.15]"
        style={{
          backgroundImage: 'radial-gradient(circle, #64748b 1px, transparent 1px)',
          backgroundSize: '28px 28px',
        }}
      />

      {/* Contenido */}
      <div className="relative z-10 w-full max-w-md">
        {/* Identidad ATENEA */}
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-md shadow-blue-600/20">
            <BarChart3 className="size-7" />
          </div>
          <h1 className="text-3xl font-bold tracking-tight text-slate-900">ATENEA</h1>
          <p className="mt-1 text-base font-medium text-blue-600">Estadística APS</p>
          <p className="mt-1 text-sm text-slate-500">Sistema de Gestión de Estadística en Salud</p>
        </div>

        {/* Card */}
        <div className="rounded-2xl border border-slate-100 bg-white p-8 shadow-xl shadow-slate-900/5">
          <div className="mb-6 flex flex-col items-center text-center">
            <div className="mb-3 flex size-11 items-center justify-center rounded-full bg-blue-50 text-blue-600">
              <ShieldCheck className="size-5" />
            </div>
            <h2 className="text-xl font-bold text-slate-900">{title}</h2>
            {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
          </div>

          {children}

          <div className="mt-6 border-t border-slate-100 pt-4 text-center">
            <p className="text-xs font-medium text-slate-600">Acceso seguro y autorizado</p>
            <p className="mt-0.5 text-xs text-slate-400">Solo personal autorizado del sistema</p>
          </div>
        </div>
      </div>
    </div>
  )
}
