/**
 * The phone's navigation: a row of tabs plus a "More" popup for the tail.
 *
 * The entries come from `NAV_SECTIONS` (see `navSections.tsx`) rather than from
 * a list of its own. That is the fix for #782's blind spot — the registration
 * inbox reached the header nav and never reached this bar, so on a phone the
 * section existed, was permitted and was unreachable.
 */

import { useState, useEffect, useRef } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useAuth } from '../../context/AuthContext'
import {
  mobileMoreSections,
  mobilePrimarySections,
  renderSectionIcon,
  type NavSection,
} from './navSections'
import { MoreIcon } from '../icons'

interface BottomTabBarProps {
  /**
   * The registration inbox's badge count, passed down rather than fetched
   * here: `MainLayout` already holds it for the header nav, and the two
   * navigations are never on screen together, so a second `useEffect` would
   * only mean a second identical request on every navigation a phone makes.
   */
  pendingRegistrations: number
}

export function BottomTabBar({ pendingRegistrations }: BottomTabBarProps) {
  const { t } = useTranslation()
  const location = useLocation()
  const { roles } = useAuth()
  const [showMore, setShowMore] = useState(false)
  const moreRef = useRef<HTMLDivElement>(null)

  const isActive = (path: string) => location.pathname === path

  const counts = { pendingRegistrations }
  const primaryTabs = mobilePrimarySections(roles)
  const moreItems = mobileMoreSections(roles)

  // The bar is narrow, so the primary row prefers an abbreviation where the
  // section has one; the popup has room for the full label.
  const tabLabel = (section: NavSection) => t(section.shortLabelKey ?? section.labelKey)

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
        <Link key={tab.path} to={tab.path} data-testid={`tab-${tab.id}`} style={tabStyle(isActive(tab.path))}>
          {renderSectionIcon(tab, 22, counts)}
          <span style={{ maxWidth: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }}>{tabLabel(tab)}</span>
        </Link>
      ))}

      {moreItems.length > 0 && (
      <div ref={moreRef} style={{ flex: 1, position: 'relative' }}>
        <button
          data-testid="tab-more"
          onClick={() => setShowMore(!showMore)}
          style={tabStyle(isMoreActive)}
        >
          <MoreIcon size={22} />
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
                data-testid={`tab-${item.id}`}
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
                {renderSectionIcon(item, 20, counts)}
                <span>{t(item.labelKey)}</span>
              </Link>
            ))}
          </div>
        )}
      </div>
      )}
    </div>
  )
}
