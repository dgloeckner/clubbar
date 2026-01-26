/**
 * Card Component
 * Reusable card wrapper with consistent styling
 */

import React from 'react'
import { theme } from '../../styles/design-system'

interface CardProps {
  children: React.ReactNode
  title?: string
  subtitle?: string
  variant?: 'default' | 'interactive'
  className?: string
  style?: React.CSSProperties
}

export function Card({
  children,
  title,
  subtitle,
  variant = 'default',
  className = '',
  style = {},
}: CardProps) {
  const getCardStyles = (): React.CSSProperties => ({
    background: theme.colors.bg.card,
    border: `1px solid ${theme.colors.border.light}`,
    borderRadius: theme.borderRadius.lg,
    padding: theme.spacing.lg,
    transition: variant === 'interactive' ? `all ${theme.transitions.default}` : 'none',
    cursor: variant === 'interactive' ? 'pointer' : 'default',
    ...style,
  })

  const handleHover = (e: React.MouseEvent<HTMLDivElement>) => {
    if (variant === 'interactive') {
      e.currentTarget.style.background = theme.colors.bg.hover
      e.currentTarget.style.borderColor = theme.colors.border.focus
    }
  }

  const handleHoverOut = (e: React.MouseEvent<HTMLDivElement>) => {
    if (variant === 'interactive') {
      e.currentTarget.style.background = theme.colors.bg.card
      e.currentTarget.style.borderColor = theme.colors.border.light
    }
  }

  return (
    <div
      style={getCardStyles()}
      className={className}
      onMouseEnter={handleHover}
      onMouseLeave={handleHoverOut}
    >
      {title && (
        <div style={{ marginBottom: theme.spacing.lg }}>
          <h3
            style={{
              margin: 0,
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              marginBottom: subtitle ? theme.spacing.xs : 0,
            }}
          >
            {title}
          </h3>
          {subtitle && (
            <p
              style={{
                margin: 0,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.secondary,
              }}
            >
              {subtitle}
            </p>
          )}
        </div>
      )}
      {children}
    </div>
  )
}
