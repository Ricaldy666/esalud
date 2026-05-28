import { createBrowserRouter } from 'react-router-dom'
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'
import HealthCheckPage from '@/pages/HealthCheckPage'
import { ProtectedRoute } from './ProtectedRoute'

const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    element: <ProtectedRoute />,
    children: [
      {
        path: '/',
        element: <DashboardPage />,
      },
      {
        path: '/health',
        element: <HealthCheckPage />,
      },
    ],
  },
])

export default router
