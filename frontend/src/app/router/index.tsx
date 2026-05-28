import { createBrowserRouter } from 'react-router-dom'
import HealthCheckPage from '@/pages/HealthCheckPage'

const router = createBrowserRouter([
  {
    path: '/',
    element: <HealthCheckPage />,
  },
])

export default router
