import { RouterProvider } from 'react-router-dom'
import router from '@/app/router'
import { useAuthInit } from '@/features/auth'

export default function App() {
  const { isInitializing } = useAuthInit()

  if (isInitializing) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50">
        <div className="flex items-center gap-3">
          <div className="h-4 w-4 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
          <span className="text-lg text-gray-600">Cargando...</span>
        </div>
      </div>
    )
  }

  return <RouterProvider router={router} />
}
