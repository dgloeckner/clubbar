/**
 * Main Layout Component
 * Provides header, navigation, and main content area for all pages
 */

import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { theme } from '../../styles/design-system'
import { useAuth } from '../../context/AuthContext'
import { useLoading } from '../../context/LoadingContext'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { LoadingIndicator } from '../common/LoadingIndicator'
import { BottomTabBar } from './BottomTabBar'
import {
  AuditLogIcon,
  UsersIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  SettingsIcon,
  UserIcon,
  LogoutIcon,
} from '../icons'
import { NavigationIconRegistry } from '../icons/IconRegistry'

interface MainLayoutProps {
  children: React.ReactNode
}

export function MainLayout({ children }: MainLayoutProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { displayName, logout } = useAuth()
  const { isLoading, setIsLoading } = useLoading()
  const breakpoint = useBreakpoint()

  // Trigger loading indicator on navigation
  useEffect(() => {
    setIsLoading(true)
    const timer = setTimeout(() => {
      setIsLoading(false)
    }, 200)
    return () => clearTimeout(timer)
  }, [location.pathname, setIsLoading])

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  const isActive = (path: string): boolean => location.pathname === path

  const navItems = [
    { label: t('nav.members'), path: '/members', icon: <UsersIcon size={20} />, testId: 'nav-members' },
    { label: t('nav.products'), path: '/products', icon: <PackageIcon size={20} />, testId: 'nav-products' },
    { label: t('nav.categories'), path: '/categories', icon: <NavigationIconRegistry.CategoryIcon size={20} />, testId: 'nav-categories' },
    { label: t('nav.journal'), path: '/journal', icon: <BookIcon size={20} />, testId: 'nav-journal' },
    { label: t('nav.settlements'), path: '/settlements', icon: <ReceiptIcon size={20} />, testId: 'nav-settlements' },
    { label: t('nav.statistics'), path: '/statistics', icon: <ChartIcon size={20} />, testId: 'nav-statistics' },
    { label: t('nav.settings'), path: '/settings', icon: <SettingsIcon size={20} />, testId: 'nav-settings' },
    { label: t('nav.auditLog'), path: '/audit-log', icon: <AuditLogIcon size={20} />, testId: 'nav-audit-log' },
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
            data-testid="header-logo-link"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.md,
              textDecoration: 'none',
            }}
          >
            <img
              src="/logo.svg"
              alt="Ruderbar Logo"
              data-testid="header-logo"
              style={{
                width: '60px',
                height: '60px',
              }}
            />
            <div>
              <h1
                data-testid="header-brand-name"
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
                data-testid="header-brand-subtitle"
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
              data-testid="header-logout-button-mobile"
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
              {!isSmallMobile && <span>{t('nav.logout')}</span>}
            </button>
          )}
        </div>

        {/* Nav Tabs */}
        <nav
          data-testid="desktop-nav"
          style={{
            display: isMobile ? 'none' : 'flex',
            gap: '2px',
            width: isMobile ? '100%' : 'auto',
            overflowX: isMobile ? 'auto' : 'visible',
            WebkitOverflowScrolling: 'touch',
            scrollBehavior: 'smooth',
            flex: 1,
            minWidth: 0,
            justifyContent: 'center',
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
              data-testid={item.testId}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                padding: isSmallMobile ? `${theme.spacing.sm} ${theme.spacing.md}` : `${theme.spacing.sm} ${theme.spacing.md}`,
                borderRadius: theme.borderRadius.md,
                background: isActive(item.path) ? 'rgba(59, 130, 246, 0.2)' : 'transparent',
                color: isActive(item.path) ? theme.colors.semantic.primary : theme.colors.text.secondary,
                textDecoration: 'none',
                fontSize: theme.typography.fontSize.sm,
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
              gap: theme.spacing.sm,
              justifyContent: 'flex-end',
              flexShrink: 0,
            }}
          >
            {/* User Badge - clickable link to profile - hide on tablet smaller than 768px */}
            {!isTablet && (
              <Link
                to="/profile"
                data-testid="header-user-badge"
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: theme.spacing.sm,
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: isActive('/profile') ? 'rgba(59, 130, 246, 0.25)' : 'rgba(59, 130, 246, 0.15)',
                  border: `1px solid rgba(59, 130, 246, 0.3)`,
                  borderRadius: theme.borderRadius.full,
                  fontSize: theme.typography.fontSize.sm,
                  color: theme.colors.semantic.primary,
                  whiteSpace: 'nowrap',
                  textDecoration: 'none',
                  cursor: 'pointer',
                  transition: `all ${theme.transitions.default}`,
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = 'rgba(59, 130, 246, 0.25)'
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = isActive('/profile') ? 'rgba(59, 130, 246, 0.25)' : 'rgba(59, 130, 246, 0.15)'
                }}
              >
                <UserIcon size={20} data-testid="header-user-icon" />
                {displayName || 'Admin'}
              </Link>
            )}

            {/* Logout Button */}
            <button
              data-testid="header-logout-button"
              onClick={handleLogout}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.sm,
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
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
              <span>{t('nav.logout')}</span>
            </button>
          </div>
        )}
      </header>

      {/* Main Content */}
      <main
        style={{
          flex: 1,
          padding: isMobile ? theme.spacing.lg : theme.spacing.xl,
          paddingBottom: isMobile ? '72px' : undefined,
          maxWidth: '1400px',
          margin: '0 auto',
          width: '100%',
        }}
      >
        {children}
      </main>

      {/* Footer */}
      {!isMobile && (
        <footer
          data-testid="app-footer"
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
      )}

      {isMobile && <BottomTabBar />}
    </div>
  )
}
