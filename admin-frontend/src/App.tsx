/**
 * Main App Component
 * Sets up routing and renders pages
 */

import React from 'react'
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import { LoadingProvider } from './context/LoadingContext'
import { theme } from './styles/design-system'

// Pages
import { LoginPage } from './pages/LoginPage'
import { MembersPage } from './pages/MembersPage'
import { ProductsPage } from './pages/ProductsPage'
import { CategoriesPage } from './pages/CategoriesPage'
import { JournalPage } from './pages/JournalPage'
import { SettlementsPage } from './pages/SettlementsPage'
import { StatisticsPage } from './pages/StatisticsPage'
import { SettingsPage } from './pages/SettingsPage'
import { AuditLogPage } from './pages/AuditLogPage'
import { ProfilePage } from './pages/ProfilePage'

// Layout
import { MainLayout } from './components/layout/MainLayout'

/**
 * Protected Route Component
 * Redirects to login if not authenticated
 */
function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, loading } = useAuth()

  if (loading) {
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
        Loading...
      </div>
    )
  }

  return isAuthenticated ? <MainLayout>{children}</MainLayout> : <Navigate to="/login" />
}

/**
 * Main App Routes
 */
function AppRoutes() {
  const { isAuthenticated, loading } = useAuth()

  if (loading) {
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
        Loading...
      </div>
    )
  }

  return (
    <Routes>
      <Route path="/login" element={isAuthenticated ? <Navigate to="/members" /> : <LoginPage />} />
      <Route path="/" element={isAuthenticated ? <Navigate to="/members" /> : <Navigate to="/login" />} />

      {/* Protected Routes */}
      <Route
        path="/members"
        element={
          <ProtectedRoute>
            <MembersPage />
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
        path="/categories"
        element={
          <ProtectedRoute>
            <CategoriesPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/journal"
        element={
          <ProtectedRoute>
            <JournalPage />
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
        path="/statistics"
        element={
          <ProtectedRoute>
            <StatisticsPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/settings"
        element={
          <ProtectedRoute>
            <SettingsPage />
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
      <Route path="*" element={<Navigate to="/members" />} />
    </Routes>
  )
}

/**
 * Root App Component
 */
export default function App() {
  return (
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
            <AppRoutes />
          </div>
        </LoadingProvider>
      </AuthProvider>
    </Router>
  )
}
