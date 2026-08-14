/**
 * PageHeader — the one top block every admin page opens with (#375).
 *
 * Every tab used to hand-roll its own opening: a bare `<h1>` on Products, an
 * `<h1>` with `margin: 0 0 20px 0` on Journal, a 26px title with a count line
 * under it on Members, a flex row with the primary action beside the title on
 * Settlements, and — one tab further — a *separate* right-aligned action bar
 * under the title on Journal. The titles disagreed on size, the actions
 * disagreed on which row they belong to, and switching tabs moved both. That
 * is what #375 reports: nothing is broken on any single page, but no two pages
 * agree where the page starts.
 *
 * So the header is a component rather than a convention. Every page passes a
 * title, optionally one line under it and optionally its page-level actions;
 * the grid — title left, actions right, one shared bottom margin — is decided
 * here once. `NewSettlementPage` already argued this shape for itself (#378);
 * this generalises it instead of leaving it as one page's local rule.
 *
 * The actions slot takes `PageActionButton`s, which own the button shape for
 * the same reason.
 */

import type { ReactNode } from 'react'
import { theme } from '../../styles/design-system'

/** The page title's type — one size for every tab, defined once. */
const pageTitleStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize['3xl'],
  fontWeight: theme.typography.fontWeight.bold,
  color: theme.colors.text.primary,
  margin: 0,
  letterSpacing: '-0.02em',
}

/** One line under the title. Longer prose belongs to a section, not the header. */
const subtitleStyle: React.CSSProperties = {
  margin: `${theme.spacing.sm} 0 0 0`,
  fontSize: theme.typography.fontSize.base,
  color: theme.colors.text.secondary,
  maxWidth: '76ch',
  lineHeight: theme.typography.lineHeight.normal,
}

interface PageHeaderProps {
  /** The page's name — the same word the nav item uses. */
  title: ReactNode
  /**
   * One line under the title: a result count, a note about what the screen
   * sweeps. Pass a node when it needs its own test id.
   */
  subtitle?: ReactNode
  /**
   * Page-level actions, right-aligned on the title's row. Row-level actions
   * belong in the row, and filters belong in the toolbar — neither here.
   */
  actions?: ReactNode
  /** Defaults to `page-header`; only one page is mounted at a time. */
  testId?: string
}

export function PageHeader({ title, subtitle, actions, testId = 'page-header' }: PageHeaderProps) {
  return (
    <div
      data-testid={testId}
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: theme.spacing.lg,
        // Wraps rather than squeezing: Members carries three actions, and a
        // narrow desktop window would otherwise crush the title into a column
        // of single letters.
        flexWrap: 'wrap',
        margin: `0 0 ${theme.spacing.xl} 0`,
      }}
    >
      <div style={{ minWidth: 0, flex: '1 1 auto' }}>
        <h1 style={pageTitleStyle}>{title}</h1>
        {subtitle !== undefined && subtitle !== null && <div style={subtitleStyle}>{subtitle}</div>}
      </div>

      {actions && (
        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.sm, flexShrink: 0 }}>
          {actions}
        </div>
      )}
    </div>
  )
}
