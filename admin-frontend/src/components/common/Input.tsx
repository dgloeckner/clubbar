/**
 * Input Component
 * Reusable text input with label and error handling
 */

import React from 'react'
import { theme } from '../../styles/design-system'

interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string
  error?: string
  helpText?: string
}

export function Input({
  label,
  error,
  helpText,
  id,
  className = '',
  ...props
}: InputProps) {
  const inputId = id || label?.toLowerCase().replace(/\s+/g, '_')

  const getInputStyles = (): React.CSSProperties => ({
    width: '100%',
    background: theme.colors.bg.input,
    border: `1px solid ${error ? theme.colors.semantic.danger : theme.colors.border.light}`,
    borderRadius: theme.borderRadius.md,
    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
    color: theme.colors.text.primary,
    fontFamily: theme.typography.fontFamily.base,
    fontSize: theme.typography.fontSize.base,
    boxSizing: 'border-box',
    transition: `all ${theme.transitions.default}`,
  })

  const handleFocus = (e: React.FocusEvent<HTMLInputElement>) => {
    e.currentTarget.style.borderColor = theme.colors.border.focus
    e.currentTarget.style.boxShadow = '0 0 0 3px rgba(59, 130, 246, 0.1)'
    props.onFocus?.(e)
  }

  const handleBlur = (e: React.FocusEvent<HTMLInputElement>) => {
    e.currentTarget.style.borderColor = error ? theme.colors.semantic.danger : theme.colors.border.light
    e.currentTarget.style.boxShadow = 'none'
    props.onBlur?.(e)
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
      {label && (
        <label
          htmlFor={inputId}
          style={{
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {label}
        </label>
      )}
      <input
        {...props}
        id={inputId}
        style={getInputStyles()}
        className={className}
        onFocus={handleFocus}
        onBlur={handleBlur}
      />
      {error && (
        <span
          style={{
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.semantic.danger,
          }}
        >
          {error}
        </span>
      )}
      {helpText && !error && (
        <span
          style={{
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.muted,
          }}
        >
          {helpText}
        </span>
      )}
    </div>
  )
}
