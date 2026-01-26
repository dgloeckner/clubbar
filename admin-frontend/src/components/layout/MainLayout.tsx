/**
 * Main Layout Component
 * Provides header, navigation, and main content area for all pages
 */

import React from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { theme } from '../../styles/design-system'
import { useAuth } from '../../context/AuthContext'
import { Button } from '../common/Button'

interface MainLayoutProps {
  children: React.ReactNode
}

export function MainLayout({ children }: MainLayoutProps) {
  const navigate = useNavigate()
  const location = useLocation()
  const { displayName, logout } = useAuth()

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const isActive = (path: string): boolean => location.pathname === path

  const navItems = [
    { label: 'Mitglieder', path: '/members' },
    { label: 'Produkte', path: '/products' },
    { label: 'Buchungsjournal', path: '/journal' },
    { label: 'Abrechnungen', path: '/settlements' },
    { label: 'Statistik', path: '/statistics' },
  ]

  return (
    <div style={{ display: 'flex', flexDirection: 'column', minHeight: '100vh', background: theme.colors.bg.primary }}>
      {/* Header */}
      <header
        style={{
          background: theme.colors.bg.secondary,
          borderBottom: `1px solid ${theme.colors.border.light}`,
          padding: `${theme.spacing.lg} ${theme.spacing.xl}`,
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
        }}
      >
        <Link
          to="/"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: theme.spacing.md,
            textDecoration: 'none',
          }}
        >
          <div
            style={{
              fontSize: theme.typography.fontSize['2xl'],
              fontWeight: theme.typography.fontWeight.bold,
              color: theme.colors.semantic.primary,
            }}
          >
            🚣
          </div>
          <div>
            <h1
              style={{
                margin: 0,
                fontSize: theme.typography.fontSize.lg,
                fontWeight: theme.typography.fontWeight.semibold,
                color: theme.colors.text.primary,
              }}
            >
              Ruderbar
            </h1>
            <p
              style={{
                margin: 0,
                fontSize: theme.typography.fontSize.xs,
                color: theme.colors.text.secondary,
              }}
            >
              Admin Panel
            </p>
          </div>
        </Link>

        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.lg }}>
          <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary }}>
            {displayName}
          </span>
          <Button variant="outline" size="sm" onClick={handleLogout}>
            Logout
          </Button>
        </div>
      </header>

      {/* Navigation Tabs */}
      <nav
        style={{
          background: theme.colors.bg.secondary,
          display: 'flex',
          borderBottom: `1px solid ${theme.colors.border.light}`,
          overflow: 'auto',
        }}
      >
        {navItems.map((item) => (
          <Link
            key={item.path}
            to={item.path}
            style={{
              padding: `${theme.spacing.lg} ${theme.spacing.xl}`,
              color: isActive(item.path) ? theme.colors.semantic.primary : theme.colors.text.secondary,
              textDecoration: 'none',
              borderBottom: isActive(item.path) ? `2px solid ${theme.colors.semantic.primary}` : 'none',
              transition: `all ${theme.transitions.default}`,
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
              whiteSpace: 'nowrap',
            }}
            onMouseEnter={(e) => {
              if (!isActive(item.path)) {
                e.currentTarget.style.color = theme.colors.text.primary
              }
            }}
            onMouseLeave={(e) => {
              if (!isActive(item.path)) {
                e.currentTarget.style.color = theme.colors.text.secondary
              }
            }}
          >
            {item.label}
          </Link>
        ))}
      </nav>

      {/* Main Content */}
      <main
        style={{
          flex: 1,
          padding: theme.spacing.xl,
          maxWidth: '1400px',
          margin: '0 auto',
          width: '100%',
        }}
      >
        {children}
      </main>

      {/* Footer */}
      <footer
        style={{
          background: theme.colors.bg.secondary,
          borderTop: `1px solid ${theme.colors.border.light}`,
          padding: theme.spacing.lg,
          textAlign: 'center',
          fontSize: theme.typography.fontSize.xs,
          color: theme.colors.text.secondary,
        }}
      >
        Ruderbar Admin &copy; 2026 — Open Source POS System
      </footer>
    </div>
  )
}
