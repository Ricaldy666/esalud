import { createBrowserRouter } from 'react-router-dom'
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'
import UsersPage from '@/pages/UsersPage'
import HealthCentersPage from '@/pages/HealthCentersPage'
import AuditPage from '@/pages/AuditPage'
import ComingSoonPage from '@/pages/ComingSoonPage'
import { RemUploadsPage } from '@/features/rem'
import {
  RuleEngineDashboardPage,
  RulesPage,
  RuleDetailPage,
  ExecutionLogsPage,
  ExecutionLogDetailPage,
  StructuresPage,
  StructureDetailPage,
  BindingsPage,
  BindingDetailPage,
  ComparisonPage,
  FeatureFlagsPage,
  UploadValidationSummaryPage,
  UploadValidationErrorsPage,
  RuleCatalogPage,
  RuleCertificationDetailPage,
  RuleSectionPage,
  CriteriosFuncionalesPage,
  SeccionRevisionPage,
  CalibrationDashboardPage,
  CalibrationTemplatePage,
  CalibrationSeriesPage,
  CalibrationSheetPage,
  CalibrationSectionPage,
} from '@/features/rule-engine'
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
        path: '/criterios-funcionales',
        element: <CriteriosFuncionalesPage />,
      },
      {
        path: '/criterios-funcionales/:sheet/sections/:section',
        element: <SeccionRevisionPage />,
      },
      {
        // Accesos de demostracion visual (GES, Metas APS) -- pagina placeholder,
        // sin funcionalidad real. Ver AppLayout.tsx MENU_ITEMS.
        path: '/ges',
        element: <ComingSoonPage title="GES" />,
      },
      {
        path: '/metas-aps',
        element: <ComingSoonPage title="Metas APS" />,
      },
      {
        element: (
          <RoleProtectedRoute allowedRoles={['Superadmin', 'Analista', 'Revisor', 'Auditor']} />
        ),
        children: [
          {
            path: '/calibracion',
            element: <CalibrationDashboardPage />,
          },
          {
            path: '/calibracion/templates/:templateId',
            element: <CalibrationTemplatePage />,
          },
          {
            path: '/calibracion/templates/:templateId/series/:series',
            element: <CalibrationSeriesPage />,
          },
          {
            path: '/calibracion/templates/:templateId/series/:series/sheets/:sheet',
            element: <CalibrationSheetPage />,
          },
          {
            path: '/calibracion/templates/:templateId/series/:series/sheets/:sheet/sections/:section',
            element: <CalibrationSectionPage />,
          },
        ],
      },
      {
        path: '/rule-engine/uploads/:uploadId/validation-summary',
        element: <UploadValidationSummaryPage />,
      },
      {
        path: '/rule-engine/uploads/:uploadId/validation-errors',
        element: <UploadValidationErrorsPage />,
      },
      {
        element: (
          <RoleProtectedRoute
            allowedRoles={['Administrador', 'Superadmin', 'Revisor', 'Auditor']}
          />
        ),
        children: [
          {
            path: '/rule-engine',
            element: <RuleEngineDashboardPage />,
          },
        ],
      },
      {
        element: <RoleProtectedRoute allowedRoles={['Administrador', 'Superadmin']} />,
        children: [
          {
            path: '/rule-engine/rules',
            element: <RulesPage />,
          },
          {
            path: '/rule-engine/rules/:id',
            element: <RuleDetailPage />,
          },
          {
            path: '/rule-engine/logs',
            element: <ExecutionLogsPage />,
          },
          {
            path: '/rule-engine/logs/:id',
            element: <ExecutionLogDetailPage />,
          },
          {
            path: '/rule-engine/structures',
            element: <StructuresPage />,
          },
          {
            path: '/rule-engine/structures/:id',
            element: <StructureDetailPage />,
          },
          {
            path: '/rule-engine/bindings',
            element: <BindingsPage />,
          },
          {
            path: '/rule-engine/bindings/:id',
            element: <BindingDetailPage />,
          },
          {
            path: '/rule-engine/comparison',
            element: <ComparisonPage />,
          },
          {
            path: '/rule-engine/config',
            element: <FeatureFlagsPage />,
          },
          {
            path: '/rule-engine/catalog',
            element: <RuleCatalogPage />,
          },
          {
            path: '/rule-engine/catalog/:sheet/sections/:section',
            element: <RuleSectionPage />,
          },
          {
            path: '/rule-engine/catalog/:ruleKey',
            element: <RuleCertificationDetailPage />,
          },
        ],
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
