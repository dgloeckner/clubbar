/**
 * Main App Component
 * Sets up routing and renders pages
 */

import React, { Suspense, lazy, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { LoadingProvider } from './context/LoadingContext'
import { InstanceConfigProvider, useInstanceConfig } from './context/InstanceConfigContext'
import { theme } from './styles/design-system'

// Pages
import { LoginPage } from './pages/LoginPage'
import { MembersPage } from './pages/MembersPage'
import { ExcludedFromCollectionPage } from './pages/ExcludedFromCollectionPage'
import { ProductsPage } from './pages/ProductsPage'
import { CategoriesPage } from './pages/CategoriesPage'
import { JournalPage } from './pages/JournalPage'
import { SettlementsPage } from './pages/SettlementsPage'
import { NewSettlementPage } from './pages/NewSettlementPage'
import { SettingsPage } from './pages/SettingsPage'
import { BackupsPage } from './pages/BackupsPage'
import { AuditLogPage } from './pages/AuditLogPage'
import { NotificationsPage } from './pages/NotificationsPage'
import { ProfilePage } from './pages/ProfilePage'
import { DashboardPage } from './pages/DashboardPage'
import { InsufficientRolePage } from './pages/InsufficientRolePage'

// Reports is the only page that imports Recharts, and Recharts arrives with a
// complete Redux stack of its own (@reduxjs/toolkit, react-redux, redux,
// redux-thunk, reselect, immer) plus a dozen d3 packages — 103 KB gzipped,
// 29% of the bundle, on roughly half the production dependency tree. In one
// chunk that was downloaded and parsed by every admin on every page, for a
// page most of them never open (#655).
//
// Split at the route rather than by a manual chunk rule: the router already
// draws this boundary, and React.lazy makes the fetch follow the navigation
// instead of the build config.
const ReportsPage = lazy(() =>
  import('./pages/ReportsPage').then((module) => ({ default: module.ReportsPage })),
)

// Layout
import { MainLayout } from './components/layout/MainLayout'
import { landingPath, permitsPath } from './utils/adminRoles'

/**
 * Fallback for a route whose chunk is still in flight (#655).
 *
 * Rendered inside MainLayout, so the sidebar and header stay put and only the
 * content area waits — and it reuses `common.loading`, the same string every
 * other pending view in the panel shows.
 */
function PageLoading() {
  const { t } = useTranslation()

  return (
    <div
      data-testid="page-loading"
      style={{
        textAlign: 'center',
        color: theme.colors.text.secondary,
        padding: theme.spacing.xl,
      }}
    >
      {t('common.loading')}
    </div>
  )
}

/**
 * Protected Route Component
 * Redirects to login if not authenticated, and refuses — by name — a page the
 * caller's roles do not cover (ADR-0044, #516).
 *
 * The refusal renders *inside* the layout rather than redirecting: a redirect
 * would leave the caller on a working page with no explanation of why the one
 * they asked for vanished, and would make a bookmark silently unfixable. This
 * is a courtesy, not a control — the server refuses the underlying requests
 * whatever this component decides.
 */
function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, initializing, roles } = useAuth()
  const { pathname } = useLocation()
  const { t } = useTranslation()

  if (initializing) {
    return (
      <div
        style={{
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'center',
          minHeight: '100vh',
          background: theme.colors.bg.primary,
          color: theme.colors.text.secondary,
        }}
      >
        {t('common.loading')}
      </div>
    )
  }

  if (!isAuthenticated) return <Navigate to="/login" />

  return <MainLayout>{permitsPath(roles, pathname) ? children : <InsufficientRolePage />}</MainLayout>
}

/**
 * Main App Routes
 */
function AppRoutes() {
  const { isAuthenticated, initializing, roles } = useAuth()
  const { t } = useTranslation()

  // Where "home" is depends on the office: a Getränkewart's dashboard is a 403
  // (it carries named members and their Deckel), so they land on the drinks
  // list they actually came for.
  const home = landingPath(roles)

  if (initializing) {
    return (
      <div
        style={{
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'center',
          minHeight: '100vh',
          background: theme.colors.bg.primary,
          color: theme.colors.text.secondary,
        }}
      >
        {t('common.loading')}
      </div>
    )
  }

  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to={home} /> : <LoginPage />} />
      <Route path="/" element={isAuthenticated ? <Navigate to={home} /> : <Navigate to="/login" />} />

      {/* Protected Routes */}
      <Route
        path="/dashboard"
        element={
          <ProtectedRoute>
            <DashboardPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/members"
        element={
          <ProtectedRoute>
            <MembersPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/members/excluded"
        element={
          <ProtectedRoute>
            <ExcludedFromCollectionPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/products"
        element={
          <ProtectedRoute>
            <ProductsPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/products/categories"
        element={
          <ProtectedRoute>
            <CategoriesPage />
          </ProtectedRoute>
        }
      />
      <Route path="/categories" element={<Navigate to="/products/categories" />} />
      <Route
        path="/journal"
        element={
          <ProtectedRoute>
            <JournalPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/settlements/new"
        element={
          <ProtectedRoute>
            <NewSettlementPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/settlements"
        element={
          <ProtectedRoute>
            <SettlementsPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/reports"
        element={
          <ProtectedRoute>
            <Suspense fallback={<PageLoading />}>
              <ReportsPage />
            </Suspense>
          </ProtectedRoute>
        }
      />
      <Route path="/statistics" element={<Navigate to="/reports" />} />
      <Route
        path="/settings"
        element={
          <ProtectedRoute>
            <SettingsPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/notifications"
        element={
          <ProtectedRoute>
            <NotificationsPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/backups"
        element={
          <ProtectedRoute>
            <BackupsPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/audit-log"
        element={
          <ProtectedRoute>
            <AuditLogPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/profile"
        element={
          <ProtectedRoute>
            <ProfilePage />
          </ProtectedRoute>
        }
      />

      {/* 404 Fallback */}
      <Route path="*" element={<Navigate to={home} />} />
    </Routes>
  )
}

/**
 * Sets the browser tab title once the instance name has loaded. `index.html`
 * keeps its own static "Club Bar Admin" title as the pre-JS fallback — this
 * only ever overwrites it after the fetch resolves.
 */
function DocumentTitle() {
  const { instanceName, loading } = useInstanceConfig()

  useEffect(() => {
    if (!loading) {
      document.title = `${instanceName} Admin`
    }
  }, [instanceName, loading])

  return null
}

/**
 * Root App Component
 */
export default function App() {
  return (
    <InstanceConfigProvider>
      <Router>
        <AuthProvider>
          <LoadingProvider>
            <div
              style={{
                backgroundColor: theme.colors.bg.primary,
                color: theme.colors.text.primary,
                fontFamily: theme.typography.fontFamily.base,
                minHeight: '100vh',
              }}
            >
              <DocumentTitle />
              <AppRoutes />
            </div>
          </LoadingProvider>
        </AuthProvider>
      </Router>
    </InstanceConfigProvider>
  )
}
