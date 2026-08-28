/**
 * The header navigation, with the entries that do not fit behind "More"
 * (#742).
 *
 * The row used to be a horizontal scroller with `nav::-webkit-scrollbar {
 * display: none }` on top of it. Anything past the right edge was therefore
 * not merely clipped but *unadvertised*: on a 1713px window in German the
 * audit log was off the end, with no scrollbar, no chevron and no hint that
 * the section existed at all. Widening the window was the only discovery
 * mechanism, and below ~1900px there was none.
 *
 * Numbers cannot fix that, because none of the three inputs is fixed: the
 * labels are translated (`Benachrichtigungen` is twice `Notifications`), the
 * set depends on which offices the account holds (ADR-0044), and what is left
 * over depends on how long the club named itself. So the row measures itself
 * — an off-screen copy of every entry at its real width, against the width the
 * nav actually got — and moves the tail into a menu. `fitNavItems` is the
 * arithmetic, unit-tested away from the browser.
 *
 * Two properties this has to keep, and they are why the overflow is a menu
 * rather than a scroller:
 *
 * 1. **Every permitted section stays reachable.** An entry is either in the
 *    row or in the menu; it is never nowhere.
 * 2. **Nothing appears that the roles do not permit.** Filtering happens
 *    before this component sees the list, so overflow cannot resurrect an
 *    entry `permitsPath` removed.
 */

import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { fitNavItems } from '../../utils/navOverflow'
import { MoreIcon } from '../icons'

export interface DesktopNavItem {
  label: string
  path: string
  icon: React.ReactNode
  testId: string
}

interface DesktopNavProps {
  items: DesktopNavItem[]
  /** Tablet and small-mobile widths show the icon alone, as they always have. */
  iconOnly: boolean
  isActive: (path: string) => boolean
}

/** Matches the flex `gap` on the row below; the measurement needs the number. */
const GAP = 2

function itemStyle(active: boolean): React.CSSProperties {
  return {
    display: 'flex',
    alignItems: 'center',
    gap: '4px',
    padding: `${theme.spacing.sm} ${theme.spacing.sm}`,
    borderRadius: theme.borderRadius.md,
    background: active ? theme.activeTint.primaryStrong : 'transparent',
    color: active ? theme.colors.semantic.primary : theme.colors.text.secondary,
    textDecoration: 'none',
    fontSize: theme.typography.fontSize.xs,
    fontWeight: theme.typography.fontWeight.medium,
    transition: `all ${theme.transitions.default}`,
    whiteSpace: 'nowrap',
    flexShrink: 0,
    border: 'none',
    cursor: 'pointer',
    fontFamily: 'inherit',
  }
}

export function DesktopNav({ items, iconOnly, isActive }: DesktopNavProps) {
  const { t } = useTranslation()
  const location = useLocation()
  const containerRef = useRef<HTMLElement | null>(null)
  const measureRef = useRef<HTMLDivElement | null>(null)
  const moreRef = useRef<HTMLDivElement | null>(null)
  const [visibleCount, setVisibleCount] = useState(items.length)
  const [showMore, setShowMore] = useState(false)

  // Re-measure whenever the row, the labels or the permitted set changes. The
  // labels are in the key because a language switch changes every width
  // without changing anything the ResizeObserver watches.
  const measureKey = `${iconOnly}|${items.map((item) => `${item.testId}:${item.label}`).join('|')}`

  useLayoutEffect(() => {
    const container = containerRef.current
    const measure = measureRef.current
    if (!container || !measure) {
      return
    }

    const recalculate = () => {
      const children = Array.from(measure.children) as HTMLElement[]
      if (children.length === 0) {
        return
      }
      // The measurement row is every entry followed by the More button.
      const widths = children.slice(0, -1).map((child) => child.getBoundingClientRect().width)
      const moreWidth = children[children.length - 1].getBoundingClientRect().width
      setVisibleCount(fitNavItems(widths, moreWidth, container.getBoundingClientRect().width, GAP))
    }

    recalculate()

    if (typeof ResizeObserver === 'undefined') {
      return
    }
    const observer = new ResizeObserver(recalculate)
    observer.observe(container)
    observer.observe(measure)
    return () => observer.disconnect()
  }, [measureKey])

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (moreRef.current && !moreRef.current.contains(event.target as Node)) {
        setShowMore(false)
      }
    }
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setShowMore(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [])

  // Following an entry closes the menu behind it.
  useEffect(() => {
    setShowMore(false)
  }, [location.pathname])

  const inlineItems = items.slice(0, visibleCount)
  const overflowItems = items.slice(visibleCount)
  const isMoreActive = overflowItems.some((item) => isActive(item.path))

  const renderContent = (item: DesktopNavItem) => (
    <>
      {item.icon}
      {!iconOnly && <span>{item.label}</span>}
    </>
  )

  return (
    <nav
      data-testid="desktop-nav"
      ref={containerRef}
      style={{
        display: 'flex',
        position: 'relative',
        gap: `${GAP}px`,
        flex: 1,
        minWidth: 0,
        alignItems: 'center',
        justifyContent: 'flex-start',
      }}
    >
      {inlineItems.map((item) => (
        <Link
          key={item.path}
          to={item.path}
          data-testid={item.testId}
          title={iconOnly ? item.label : undefined}
          style={itemStyle(isActive(item.path))}
          onMouseEnter={(e) => {
            if (!isActive(item.path)) {
              e.currentTarget.style.backgroundColor = theme.activeTint.neutralHover
            }
          }}
          onMouseLeave={(e) => {
            if (!isActive(item.path)) {
              e.currentTarget.style.backgroundColor = 'transparent'
            }
          }}
        >
          {renderContent(item)}
        </Link>
      ))}

      {overflowItems.length > 0 && (
        <div ref={moreRef} style={{ position: 'relative', flexShrink: 0 }}>
          <button
            type="button"
            data-testid="nav-more"
            aria-haspopup="menu"
            aria-expanded={showMore}
            aria-label={t('nav.more')}
            title={t('nav.more')}
            onClick={() => setShowMore((open) => !open)}
            style={itemStyle(isMoreActive)}
          >
            <MoreIcon size={20} />
            {!iconOnly && <span>{t('nav.more')}</span>}
          </button>

          {showMore && (
            <div
              data-testid="nav-more-menu"
              role="menu"
              style={{
                position: 'absolute',
                top: 'calc(100% + 8px)',
                right: 0,
                minWidth: '200px',
                background: theme.colors.bg.card,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.md,
                boxShadow: theme.shadows.lg,
                padding: '6px',
                zIndex: 1000,
              }}
            >
              {overflowItems.map((item) => (
                <Link
                  key={item.path}
                  to={item.path}
                  data-testid={item.testId}
                  role="menuitem"
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                    padding: '10px 12px',
                    borderRadius: '8px',
                    background: isActive(item.path) ? theme.activeTint.primary : 'transparent',
                    color: isActive(item.path) ? theme.colors.semantic.primary : theme.colors.text.primary,
                    textDecoration: 'none',
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: isActive(item.path)
                      ? theme.typography.fontWeight.semibold
                      : theme.typography.fontWeight.normal,
                    whiteSpace: 'nowrap',
                  }}
                >
                  {item.icon}
                  <span>{item.label}</span>
                </Link>
              ))}
            </div>
          )}
        </div>
      )}

      {/*
        The measurement row: every entry at its natural width, plus the More
        button, laid out where it cannot be seen or clicked and cannot affect
        the row above it. It carries no `data-testid` — a second copy of every
        nav ID would make `getVisibleNavTestIds` report entries twice and hide
        exactly the regression this component exists to prevent.
      */}
      <div
        ref={measureRef}
        aria-hidden="true"
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          display: 'flex',
          gap: `${GAP}px`,
          visibility: 'hidden',
          pointerEvents: 'none',
          height: 0,
          overflow: 'hidden',
        }}
      >
        {items.map((item) => (
          <span key={item.path} style={itemStyle(false)}>
            {renderContent(item)}
          </span>
        ))}
        <span style={itemStyle(false)}>
          <MoreIcon size={20} />
          {!iconOnly && <span>{t('nav.more')}</span>}
        </span>
      </div>
    </nav>
  )
}
