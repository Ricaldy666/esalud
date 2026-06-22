import { createBrowserRouter } from 'react-router-dom'
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'
import UsersPage from '@/pages/UsersPage'
import HealthCentersPage from '@/pages/HealthCentersPage'
import AuditPage from '@/pages/AuditPage'
import { RemUploadsPage } from '@/features/rem'
import { ProtectedRoute } from './ProtectedRoute'
import { RoleProtectedRoute } from './RoleProtectedRoute'

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
        path: '/rem-uploads',
        element: <RemUploadsPage />,
      },
      {
        element: <RoleProtectedRoute allowedRoles={['Administrador']} />,
        children: [
          {
            path: '/users',
            element: <UsersPage />,
          },
          {
            path: '/health-centers',
            element: <HealthCentersPage />,
          },
          {
            path: '/audit',
            element: <AuditPage />,
          },
        ],
      },
    ],
  },
])

export default router
