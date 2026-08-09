import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useBreakpoint } from '../../hooks/useBreakpoint'

interface MembersTabsProps {
  /**
   * Members the next collection run will leave out. Omitted while unknown, so
   * the badge appears once rather than flicking through a wrong number first.
   */
  excludedCount?: number
}

const TABS = [
  { path: '/members', labelKey: 'excluded.tabs.all', shortKey: 'excluded.tabs.allShort', testId: 'members-tab-all' },
  {
    path: '/members/excluded',
    labelKey: 'excluded.tabs.excluded',
    shortKey: 'excluded.tabs.excludedShort',
    testId: 'members-tab-excluded',
  },
] as const

/**
 * The two faces of the Members area: the roster, and the members the next
 * collection run will leave out.
 *
 * A tab rather than a tenth sidebar entry — both answer a question about
 * members, and the sidebar is full. It lives in its own component because
 * `MembersPage` is already long enough that anything added to it is a cost.
 */
export function MembersTabs({ excludedCount }: MembersTabsProps) {
  const { t } = useTranslation()
  const location = useLocation()
  const breakpoint = useBreakpoint()
  const isNarrow = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  return (
    <div
      data-testid="members-tabs"
      role="tablist"
      style={{
        display: 'flex',
        gap: theme.spacing.xs,
        borderBottom: `1px solid ${theme.colors.border.dark}`,
        marginBottom: theme.spacing.xl,
      }}
    >
      {TABS.map((tab) => {
        const isActive = location.pathname === tab.path
        const showBadge = tab.path === '/members/excluded' && (excludedCount ?? 0) > 0

        return (
          <Link
            key={tab.path}
            to={tab.path}
            role="tab"
            aria-selected={isActive}
            data-testid={tab.testId}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.sm,
              padding: isNarrow ? '8px 10px 10px' : '9px 14px 11px',
              marginBottom: '-1px',
              fontSize: isNarrow ? theme.typography.fontSize.sm : theme.typography.fontSize.base,
              fontWeight: isActive
                ? theme.typography.fontWeight.semibold
                : theme.typography.fontWeight.normal,
              color: isActive ? theme.colors.text.primary : theme.colors.text.secondary,
              borderBottom: `2px solid ${isActive ? theme.colors.semantic.primary : 'transparent'}`,
              textDecoration: 'none',
              transition: theme.transitions.default,
            }}
          >
            {isNarrow ? t(tab.shortKey) : t(tab.labelKey)}
            {showBadge && (
              <span
                data-testid="members-tab-excluded-count"
                style={{
                  fontFamily: theme.typography.fontFamily.mono,
                  fontSize: theme.typography.fontSize.xs,
                  fontWeight: theme.typography.fontWeight.semibold,
                  padding: '1px 7px',
                  borderRadius: theme.borderRadius.full,
                  background: 'rgba(249, 115, 22, 0.16)',
                  color: '#fdba74',
                }}
              >
                {excludedCount}
              </span>
            )}
          </Link>
        )
      })}
    </div>
  )
}
