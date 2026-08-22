/**
 * Badge Component
 * Status badge with colored dot indicator
 */

import { theme } from '../../styles/design-system'

export interface BadgeProps {
  label: string
  variant?: 'success' | 'warning' | 'danger' | 'info' | 'neutral'
  showDot?: boolean
  testId?: string
  /**
   * Pill shape, tighter type and a tinted border instead of a filled block —
   * for the mobile cards, where a filled badge sat at the same visual weight
   * as the row's action buttons and the two competed. Same shape as
   * `MemberGapChips`, so a card's chips read as one family.
   */
  compact?: boolean
}

export function Badge({ label, variant = 'neutral', showDot = true, testId, compact = false }: BadgeProps) {
  const style = theme.badges[variant]

  return (
    <div
      data-testid={testId}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: compact ? theme.spacing.xs : theme.spacing.sm,
        padding: compact ? `2px ${theme.spacing.sm}` : `${theme.spacing.sm} ${theme.spacing.md}`,
        background: style.bg,
        color: style.text,
        border: compact ? `1px solid ${style.border}` : undefined,
        borderRadius: compact ? theme.borderRadius.full : theme.borderRadius.sm,
        fontSize: theme.typography.fontSize.xs,
        fontWeight: theme.typography.fontWeight.semibold,
        whiteSpace: compact ? 'nowrap' : undefined,
      }}
    >
      {showDot && (
        <div
          style={{
            width: '8px',
            height: '8px',
            borderRadius: theme.borderRadius.full,
            backgroundColor: style.dot,
            flexShrink: 0,
          }}
        />
      )}
      {label}
    </div>
  )
}
