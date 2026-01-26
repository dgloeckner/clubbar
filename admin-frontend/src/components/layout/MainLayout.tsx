/**
 * Main Layout Component
 * Provides header, navigation, and main content area for all pages
 */

import React from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { theme } from '../../styles/design-system'
import { useAuth } from '../../context/AuthContext'
import { useLoading } from '../../context/LoadingContext'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { LoadingIndicator } from '../common/LoadingIndicator'
import {
  UsersIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  UserIcon,
  LogoutIcon,
} from '../icons'

interface MainLayoutProps {
  children: React.ReactNode
}

export function MainLayout({ children }: MainLayoutProps) {
  const navigate = useNavigate()
  const location = useLocation()
  const { displayName, logout } = useAuth()
  const { isLoading } = useLoading()
  const breakpoint = useBreakpoint()

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const isActive = (path: string): boolean => location.pathname === path

  const navItems = [
    { label: 'Mitglieder', path: '/members', icon: <UsersIcon size={20} /> },
    { label: 'Produkte', path: '/products', icon: <PackageIcon size={20} /> },
    {
      label: 'Buchungsjournal',
      path: '/journal',
      icon: <BookIcon size={20} />,
    },
    { label: 'Abrechnungen', path: '/settlements', icon: <ReceiptIcon size={20} /> },
    { label: 'Statistik', path: '/statistics', icon: <ChartIcon size={20} /> },
  ]

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const isTablet = breakpoint === 'tablet'
  const isSmallMobile = breakpoint === 'smallMobile'

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        minHeight: '100vh',
        background: theme.colors.bg.primary,
      }}
    >
      <LoadingIndicator show={isLoading} />

      {/* Header */}
      <header
        style={{
          background: theme.colors.bg.secondary,
          borderBottom: `1px solid ${theme.colors.border.light}`,
          padding: isMobile ? `${theme.spacing.md} ${theme.spacing.lg}` : `${theme.spacing.lg} ${theme.spacing.xl}`,
          display: 'flex',
          flexDirection: isMobile ? 'column' : 'row',
          gap: isMobile ? theme.spacing.md : theme.spacing.xl,
          alignItems: 'center',
        }}
      >
        {/* Logo + Nav */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: theme.spacing.xl,
            width: isMobile ? '100%' : 'auto',
            justifyContent: isMobile ? 'space-between' : 'flex-start',
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
                Admin
              </p>
            </div>
          </Link>

          {/* User Badge on mobile - show logout icon next to logo */}
          {isMobile && (
            <button
              onClick={handleLogout}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: isSmallMobile ? '0' : theme.spacing.sm,
                padding: isSmallMobile ? '8px' : `${theme.spacing.sm} ${theme.spacing.md}`,
                background: 'rgba(239, 68, 68, 0.1)',
                border: '1px solid rgba(239, 68, 68, 0.3)',
                borderRadius: theme.borderRadius.md,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.semantic.danger,
                cursor: 'pointer',
                transition: `all ${theme.transitions.default}`,
              }}
            >
              <LogoutIcon size={20} />
              {!isSmallMobile && <span>Abmelden</span>}
            </button>
          )}
        </div>

        {/* Nav Tabs */}
        <nav
          style={{
            display: 'flex',
            gap: theme.spacing.sm,
            width: isMobile ? '100%' : 'auto',
            overflowX: isMobile ? 'auto' : 'visible',
            WebkitOverflowScrolling: 'touch',
            scrollBehavior: 'smooth',
            // Hide scrollbar for mobile
            msOverflowStyle: 'none',
            scrollbarWidth: 'none',
          }}
        >
          <style>{`nav::-webkit-scrollbar { display: none; }`}</style>
          {navItems.map((item) => (
            <Link
              key={item.path}
              to={item.path}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.sm,
                padding: isSmallMobile ? `${theme.spacing.sm} ${theme.spacing.md}` : `${theme.spacing.md} ${theme.spacing.lg}`,
                borderRadius: theme.borderRadius.md,
                background: isActive(item.path) ? 'rgba(59, 130, 246, 0.2)' : 'transparent',
                color: isActive(item.path) ? theme.colors.semantic.primary : theme.colors.text.secondary,
                textDecoration: 'none',
                fontSize: isSmallMobile || isTablet ? theme.typography.fontSize.sm : theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.medium,
                transition: `all ${theme.transitions.default}`,
                whiteSpace: 'nowrap',
                flexShrink: 0,
              }}
              onMouseEnter={(e) => {
                if (!isActive(item.path)) {
                  e.currentTarget.style.backgroundColor = 'rgba(148, 163, 184, 0.1)'
                }
              }}
              onMouseLeave={(e) => {
                if (!isActive(item.path)) {
                  e.currentTarget.style.backgroundColor = 'transparent'
                }
              }}
            >
              {item.icon}
              {/* Show text on desktop and mobile, hide on tablet */}
              {!(isTablet || isSmallMobile) && <span>{item.label}</span>}
            </Link>
          ))}
        </nav>

        {/* User Badge + Logout - Desktop & Tablet only */}
        {!isMobile && (
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.lg,
              width: isMobile ? '100%' : 'auto',
              justifyContent: 'flex-end',
              marginLeft: 'auto',
            }}
          >
            {/* User Badge - hide on tablet smaller than 768px */}
            {!isTablet && (
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: theme.spacing.md,
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: 'rgba(59, 130, 246, 0.15)',
                  border: `1px solid rgba(59, 130, 246, 0.3)`,
                  borderRadius: theme.borderRadius.full,
                  fontSize: theme.typography.fontSize.sm,
                  color: theme.colors.semantic.primary,
                  whiteSpace: 'nowrap',
                }}
              >
                <UserIcon size={20} />
                {displayName || 'Admin'}
              </div>
            )}

            {/* Logout Button */}
            <button
              onClick={handleLogout}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.md,
                padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                background: 'rgba(239, 68, 68, 0.1)',
                border: `1px solid rgba(239, 68, 68, 0.3)`,
                borderRadius: theme.borderRadius.md,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.semantic.danger,
                cursor: 'pointer',
                transition: `all ${theme.transitions.default}`,
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = 'rgba(239, 68, 68, 0.2)'
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = 'rgba(239, 68, 68, 0.1)'
              }}
            >
              <LogoutIcon size={20} />
              <span>Abmelden</span>
            </button>
          </div>
        )}
      </header>

      {/* Main Content */}
      <main
        style={{
          flex: 1,
          padding: isMobile ? theme.spacing.lg : theme.spacing.xl,
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
