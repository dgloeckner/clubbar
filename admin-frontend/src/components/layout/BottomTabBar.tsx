import { useState, useEffect, useRef } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import {
  HomeIcon,
  UsersIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  SettingsIcon,
  AuditLogIcon,
  UserIcon,
} from '../icons'

function GridDotsIcon({ size = 20, color = 'currentColor' }: { size?: number; color?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill={color}>
      <circle cx="5" cy="5" r="2" />
      <circle cx="12" cy="5" r="2" />
      <circle cx="19" cy="5" r="2" />
      <circle cx="5" cy="12" r="2" />
      <circle cx="12" cy="12" r="2" />
      <circle cx="19" cy="12" r="2" />
      <circle cx="5" cy="19" r="2" />
      <circle cx="12" cy="19" r="2" />
      <circle cx="19" cy="19" r="2" />
    </svg>
  )
}

export function BottomTabBar() {
  const { t } = useTranslation()
  const location = useLocation()
  const [showMore, setShowMore] = useState(false)
  const moreRef = useRef<HTMLDivElement>(null)

  const isActive = (path: string) => location.pathname === path

  const primaryTabs = [
    { label: t('nav.dashboard'), path: '/dashboard', icon: HomeIcon, testId: 'tab-dashboard' },
    { label: t('nav.members'), path: '/members', icon: UsersIcon, testId: 'tab-members' },
    { label: t('nav.products'), path: '/products', icon: PackageIcon, testId: 'tab-products' },
    { label: t('nav.journalShort'), path: '/journal', icon: BookIcon, testId: 'tab-journal' },
  ]

  const moreItems = [
    { label: t('nav.settlements'), path: '/settlements', icon: ReceiptIcon, testId: 'tab-settlements' },
    { label: t('nav.reports'), path: '/reports', icon: ChartIcon, testId: 'tab-reports' },
    { label: t('nav.settings'), path: '/settings', icon: SettingsIcon, testId: 'tab-settings' },
    { label: t('nav.auditLog'), path: '/audit-log', icon: AuditLogIcon, testId: 'tab-audit-log' },
    { label: t('nav.profile'), path: '/profile', icon: UserIcon, testId: 'tab-profile' },
  ]

  const isMoreActive = moreItems.some((item) => isActive(item.path))

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (moreRef.current && !moreRef.current.contains(e.target as Node)) {
        setShowMore(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  useEffect(() => {
    setShowMore(false)
  }, [location.pathname])

  const tabStyle = (active: boolean): React.CSSProperties => ({
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: '2px',
    flex: 1,
    minWidth: 0,
    padding: '8px 2px',
    background: 'transparent',
    border: 'none',
    color: active ? theme.colors.semantic.primary : theme.colors.text.secondary,
    fontSize: '9px',
    fontWeight: active ? 600 : 400,
    textDecoration: 'none',
    cursor: 'pointer',
    transition: `all ${theme.transitions.default}`,
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap',
  })

  return (
    <div
      data-testid="bottom-tab-bar"
      style={{
        position: 'fixed',
        bottom: 0,
        left: 0,
        right: 0,
        height: '56px',
        background: theme.colors.bg.secondary,
        borderTop: `1px solid ${theme.colors.border.light}`,
        display: 'flex',
        alignItems: 'center',
        zIndex: 1000,
        paddingBottom: 'env(safe-area-inset-bottom)',
      }}
    >
      {primaryTabs.map((tab) => (
        <Link key={tab.path} to={tab.path} data-testid={tab.testId} style={tabStyle(isActive(tab.path))}>
          <tab.icon size={22} />
          <span style={{ maxWidth: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{tab.label}</span>
        </Link>
      ))}

      <div ref={moreRef} style={{ flex: 1, position: 'relative' }}>
        <button
          data-testid="tab-more"
          onClick={() => setShowMore(!showMore)}
          style={tabStyle(isMoreActive)}
        >
          <GridDotsIcon size={22} />
          <span>{t('nav.more')}</span>
        </button>

        {showMore && (
          <div
            data-testid="tab-more-popup"
            style={{
              position: 'absolute',
              bottom: '100%',
              right: 0,
              marginBottom: '8px',
              minWidth: '180px',
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              boxShadow: '0 -4px 20px rgba(0,0,0,0.4)',
              padding: '6px',
            }}
          >
            {moreItems.map((item) => (
              <Link
                key={item.path}
                to={item.path}
                data-testid={item.testId}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '10px',
                  padding: '10px 12px',
                  borderRadius: '8px',
                  background: isActive(item.path) ? theme.activeTint.primary : 'transparent',
                  color: isActive(item.path) ? theme.colors.semantic.primary : theme.colors.text.primary,
                  textDecoration: 'none',
                  fontSize: '14px',
                  fontWeight: isActive(item.path) ? 600 : 400,
                  transition: `all ${theme.transitions.default}`,
                }}
              >
                <item.icon size={20} />
                <span>{item.label}</span>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
