/**
 * Button Component
 * Reusable button with multiple variants
 */

import React from 'react'
import { theme } from '../../styles/design-system'

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  children: React.ReactNode
  variant?: 'primary' | 'secondary' | 'danger' | 'outline'
  size?: 'sm' | 'md' | 'lg'
  loading?: boolean
  disabled?: boolean
}

export function Button({
  children,
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  className = '',
  ...props
}: ButtonProps) {
  const getStyles = (): React.CSSProperties => {
    const baseStyles: React.CSSProperties = {
      fontFamily: theme.typography.fontFamily.base,
      fontSize: theme.typography.fontSize.base,
      fontWeight: theme.typography.fontWeight.semibold,
      border: 'none',
      borderRadius: theme.borderRadius.md,
      cursor: disabled || loading ? 'not-allowed' : 'pointer',
      transition: `all ${theme.transitions.default}`,
      opacity: disabled || loading ? 0.6 : 1,
    }

    const sizeStyles: React.CSSProperties = {
      sm: {
        padding: `${theme.spacing.sm} ${theme.spacing.md}`,
        fontSize: theme.typography.fontSize.sm,
      },
      md: {
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
      },
      lg: {
        padding: `${theme.spacing.lg} ${theme.spacing.xl}`,
        fontSize: theme.typography.fontSize.lg,
      },
    }[size]

    const variantStyles: React.CSSProperties = {
      primary: {
        background: theme.colors.semantic.primary,
        color: 'white',
      },
      secondary: {
        background: theme.colors.bg.secondary,
        color: theme.colors.text.primary,
        border: `1px solid ${theme.colors.border.light}`,
      },
      danger: {
        background: theme.colors.semantic.danger,
        color: 'white',
      },
      outline: {
        background: 'transparent',
        color: theme.colors.semantic.primary,
        border: `1px solid ${theme.colors.semantic.primary}`,
      },
    }[variant]

    return { ...baseStyles, ...sizeStyles, ...variantStyles }
  }

  const handleHover = (e: React.MouseEvent<HTMLButtonElement>) => {
    if (!disabled && !loading) {
      if (variant === 'primary') {
        e.currentTarget.style.background = theme.colors.semantic.primaryHover
      } else if (variant === 'danger') {
        e.currentTarget.style.background = theme.colors.semantic.dangerHover
      }
    }
  }

  const handleHoverOut = (e: React.MouseEvent<HTMLButtonElement>) => {
    if (!disabled && !loading) {
      if (variant === 'primary') {
        e.currentTarget.style.background = theme.colors.semantic.primary
      } else if (variant === 'danger') {
        e.currentTarget.style.background = theme.colors.semantic.danger
      }
    }
  }

  return (
    <button
      {...props}
      style={getStyles()}
      disabled={disabled || loading}
      onMouseEnter={handleHover}
      onMouseLeave={handleHoverOut}
      className={className}
    >
      {loading ? '⏳ ...' : children}
    </button>
  )
}
