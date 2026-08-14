import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useBreakpoint } from '../../hooks/useBreakpoint'

const TABS = [
  { path: '/products', labelKey: 'products.title', testId: 'products-tab-products' },
  { path: '/products/categories', labelKey: 'categories.title', testId: 'products-tab-categories' },
] as const

/**
 * The two faces of the catalogue: products, and the categories they're
 * grouped into (#366).
 *
 * A tab rather than a tenth sidebar entry — both answer a question about the
 * catalogue, and the sidebar is full. Mirrors MembersTabs, which does the
 * same for Members/Excluded.
 */
export function ProductsTabs() {
  const { t } = useTranslation()
  const location = useLocation()
  const breakpoint = useBreakpoint()
  const isNarrow = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  return (
    <div
      data-testid="products-tabs"
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
            {t(tab.labelKey)}
          </Link>
        )
      })}
    </div>
  )
}
