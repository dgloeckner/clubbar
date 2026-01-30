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
}

export function Badge({ label, variant = 'neutral', showDot = true, testId }: BadgeProps) {
  const style = theme.badges[variant]

  return (
    <div
      data-testid={testId}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: theme.spacing.sm,
        padding: `${theme.spacing.sm} ${theme.spacing.md}`,
        background: style.bg,
        color: style.text,
        borderRadius: theme.borderRadius.sm,
        fontSize: theme.typography.fontSize.xs,
        fontWeight: theme.typography.fontWeight.semibold,
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
