/**
 * Main Layout Component
 * Provides header, navigation, and main content area for all pages
 */

import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { theme } from '../../styles/design-system'
import { useAuth } from '../../context/AuthContext'
import { useLoading } from '../../context/LoadingContext'
import { useInstanceConfig } from '../../context/InstanceConfigContext'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { permitsPath } from '../../utils/adminRoles'
import { LoadingIndicator } from '../common/LoadingIndicator'
import { NavCountBadge } from './NavCountBadge'
import { usePendingRegistrationCount } from '../../hooks/usePendingRegistrationCount'
import { BottomTabBar } from './BottomTabBar'
import { DesktopNav } from './DesktopNav'
import { SchedulerBanner } from './SchedulerBanner'
import {
  AuditLogIcon,
  DatabaseIcon,
  MailIcon,
  HomeIcon,
  UsersIcon,
  UserPlusIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  SettingsIcon,
  UserIcon,
  LogoutIcon,
} from '../icons'

interface MainLayoutProps {
  children: React.ReactNode
}

export function MainLayout({ children }: MainLayoutProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const { displayName, logout, roles } = useAuth()
  const { isLoading } = useLoading()
  const { instanceName } = useInstanceConfig()
  const breakpoint = useBreakpoint()
  const pendingRegistrations = usePendingRegistrationCount(roles)

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  // A sub-route keeps its parent lit: /members/excluded is a tab of Members,
  // and an unlit sidebar there would read as having left the section.
  const isActive = (path: string): boolean =>
    location.pathname === path || location.pathname.startsWith(`${path}/`)

  // Sections the caller's roles cannot open are removed, not disabled
  // (ADR-0044, #516). A disabled entry advertises the section and invites the
  // 403 anyway; a Getränkewart has no business knowing the settlement screen is
  // there. This is presentation only — the server refuses independently.
  const navItems = [
    { label: t('nav.dashboard'), path: '/dashboard', icon: <HomeIcon size={20} />, testId: 'nav-dashboard' },
    { label: t('nav.members'), path: '/members', icon: <UsersIcon size={20} />, testId: 'nav-members' },
    {
      label: t('nav.registrations'),
      path: '/registrations',
      icon: (
        <NavCountBadge count={pendingRegistrations} testId="nav-registrations-count">
          <UserPlusIcon size={20} />
        </NavCountBadge>
      ),
      testId: 'nav-registrations',
    },
    { label: t('nav.products'), path: '/products', icon: <PackageIcon size={20} />, testId: 'nav-products' },
    { label: t('nav.journal'), path: '/journal', icon: <BookIcon size={20} />, testId: 'nav-journal' },
    { label: t('nav.settlements'), path: '/settlements', icon: <ReceiptIcon size={20} />, testId: 'nav-settlements' },
    { label: t('nav.reports'), path: '/reports', icon: <ChartIcon size={20} />, testId: 'nav-reports' },
    { label: t('nav.settings'), path: '/settings', icon: <SettingsIcon size={20} />, testId: 'nav-settings' },
    { label: t('nav.notifications'), path: '/notifications', icon: <MailIcon size={20} />, testId: 'nav-notifications' },
    { label: t('nav.backups'), path: '/backups', icon: <DatabaseIcon size={20} />, testId: 'nav-backups' },
    { label: t('nav.auditLog'), path: '/audit-log', icon: <AuditLogIcon size={20} />, testId: 'nav-audit-log' },
  ].filter((item) => permitsPath(roles, item.path))

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const isTablet = breakpoint === 'tablet'
  const isSmallMobile = breakpoint === 'smallMobile'

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        height: '100vh',
        background: theme.colors.bg.primary,
        overflowX: 'hidden',
        maxWidth: '100vw',
      }}
    >
      <LoadingIndicator show={isLoading} />

      {/* Header */}
      <header
        style={{
          flexShrink: 0,
          background: theme.colors.bg.secondary,
          borderBottom: `1px solid ${theme.colors.border.light}`,
          padding: isMobile ? `${theme.spacing.sm} ${theme.spacing.sm}` : `${theme.spacing.lg} ${theme.spacing.xl}`,
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
              alt="Club Bar Logo"
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
                {instanceName}
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
                background: theme.badges.danger.bg,
                border: `1px solid ${theme.badges.danger.border}`,
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

        {/*
          Nav Tabs.

          Overflow lives in DesktopNav (#742): entries that do not fit move
          into a "More" menu instead of being scrolled off the end of a row
          whose scrollbar had been styled away. On mobile the nav is not
          rendered at all — BottomTabBar is the navigation there, and it has
          carried its own "More" since #138.
        */}
        {!isMobile && (
          <DesktopNav items={navItems} iconOnly={isTablet} isActive={isActive} />
        )}

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
            {/*
              User Badge - the only route to /profile between 769px and 1500px:
              the nav has no profile entry and BottomTabBar renders on mobile
              only. Tablet keeps the badge but drops the name, matching the
              icon-only nav tabs at that width.
            */}
            <Link
              to="/profile"
              data-testid="header-user-badge"
              title={isTablet ? t('nav.profile') : undefined}
              aria-label={isTablet ? t('nav.profile') : undefined}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.sm,
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: isActive('/profile') ? theme.activeTint.profileActive : theme.activeTint.primary,
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
                e.currentTarget.style.backgroundColor = theme.activeTint.profileActive
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = isActive('/profile') ? theme.activeTint.profileActive : theme.activeTint.primary
              }}
            >
              <UserIcon size={20} data-testid="header-user-icon" />
              {!isTablet && (displayName || 'Admin')}
            </Link>

            {/* Logout Button */}
            <button
              data-testid="header-logout-button"
              onClick={handleLogout}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.sm,
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.badges.danger.bg,
                border: `1px solid ${theme.badges.danger.border}`,
                borderRadius: theme.borderRadius.md,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.semantic.danger,
                cursor: 'pointer',
                transition: `all ${theme.transitions.default}`,
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = theme.badges.danger.strong
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = theme.badges.danger.bg
              }}
            >
              <LogoutIcon size={20} />
              <span>{t('nav.logout')}</span>
            </button>
          </div>
        )}
      </header>

      {/*
        The scheduler gate (#405). Above the content and below the header, so
        it is the first thing on every page until a drain run has been
        observed — the state in which finalizing a collection is refused.
        Renders nothing at all once verified, which is permanent.
      */}
      <SchedulerBanner />

      {/*
        Main Content.

        Two elements, not one, and the split is the whole point (#379): the
        scrolling element is full width, and the 1400px cap lives on an inner
        wrapper. When `<main>` was itself the capped, centred scroller, the
        scrollbar it produced was drawn at *its* right edge — floating a couple
        of hundred pixels inside a wide window, under a full-width header, which
        reads as a bar that has come loose. The scrollbar belongs at the edge of
        the window.

        The wrapper also owns the page gutter (#375). Half the tabs used to add
        `padding: '20px'` of their own on top of this one and half did not, so
        the same title started at two different x-positions depending on which
        tab you were on. One gutter, defined here, for every page.
      */}
      <main
        style={{
          flex: 1,
          overflowY: 'auto',
          overflowX: 'hidden',
          width: '100%',
        }}
      >
        <div
          data-testid="page-content"
          style={{
            padding: isMobile ? `${theme.spacing.md} ${theme.spacing.sm}` : theme.spacing.xl,
            paddingBottom: isMobile ? '72px' : undefined,
            maxWidth: '1400px',
            margin: '0 auto',
            width: '100%',
            boxSizing: 'border-box',
          }}
        >
          {children}
        </div>
      </main>

      {/* Footer */}
      {!isMobile && (
        <footer
          data-testid="app-footer"
          style={{
            flexShrink: 0,
            background: theme.colors.bg.secondary,
            borderTop: `1px solid ${theme.colors.border.light}`,
            padding: theme.spacing.lg,
            textAlign: 'center',
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
          }}
        >
          {instanceName} Admin &copy; 2026 — Open Source POS System
          {' · '}
          <span data-testid="app-version">{import.meta.env.VITE_APP_VERSION || 'dev'}</span>
          {' · '}
          <a
            href="https://github.com/dgloeckner/clubbar"
            target="_blank"
            rel="noopener noreferrer"
            style={{ color: 'inherit', opacity: 0.5, textDecoration: 'none' }}
          >
            github.com/dgloeckner/clubbar
          </a>
        </footer>
      )}

      {isMobile && <BottomTabBar />}
    </div>
  )
}
