/**
 * Alert Component
 * Displays alert messages with icon and optional dismiss button
 */

import { ReactNode, useState } from 'react'
import { theme } from '../../styles/design-system'

export interface AlertProps {
  variant?: 'warning' | 'danger' | 'info' | 'success'
  icon?: ReactNode
  title?: string
  message: string
  dismissible?: boolean
  testId?: string
}

export function Alert({ variant = 'warning', icon, title, message, dismissible = false, testId }: AlertProps) {
  const [isDismissed, setIsDismissed] = useState(false)

  const badgeStyle = theme.badges[variant === 'danger' ? 'danger' : variant === 'success' ? 'success' : variant === 'info' ? 'info' : 'warning']

  if (isDismissed) {
    return null
  }

  return (
    <div
      data-testid={testId}
      style={{
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        marginBottom: theme.spacing.lg,
        background: badgeStyle.bg,
        border: `1px solid ${badgeStyle.dot}`,
        borderRadius: theme.borderRadius.md,
        color: badgeStyle.text,
        fontSize: theme.typography.fontSize.sm,
      }}
    >
      <div
        style={{
          display: 'flex',
          gap: theme.spacing.md,
          alignItems: 'flex-start',
          justifyContent: 'space-between',
        }}
      >
        <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'flex-start', flex: 1 }}>
          {icon && <div style={{ display: 'flex', alignItems: 'center', flexShrink: 0 }}>{icon}</div>}
          <div style={{ flex: 1 }}>
            {title && (
              <div
                style={{
                  fontWeight: theme.typography.fontWeight.semibold,
                  marginBottom: theme.spacing.xs,
                }}
              >
                {title}
              </div>
            )}
            {message}
          </div>
        </div>
        {dismissible && (
          <button
            onClick={() => setIsDismissed(true)}
            style={{
              background: 'transparent',
              border: 'none',
              color: badgeStyle.text,
              cursor: 'pointer',
              fontSize: theme.typography.fontSize.lg,
              padding: 0,
              display: 'flex',
              alignItems: 'center',
              flexShrink: 0,
            }}
          >
            ✕
          </button>
        )}
      </div>
    </div>
  )
}
