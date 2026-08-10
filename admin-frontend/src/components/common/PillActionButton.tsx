/**
 * PillActionButton Component
 *
 * A small, solid-colour action button (row-level export/undo actions) that
 * carries its own hover state instead of every call site re-declaring
 * onMouseEnter/onMouseLeave handlers that mutate e.currentTarget.style (#124).
 *
 * Colours are passed in rather than fixed variants, since callers like
 * SettlementsPage compute them per-row (e.g. the undo button's colour depends
 * on whether the settlement is cancellable).
 */

import React, { useState } from 'react'
import { theme } from '../../styles/design-system'

interface PillActionButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  children: React.ReactNode
  color: string
  hoverColor: string
  disabledColor?: string
}

export function PillActionButton({
  children,
  color,
  hoverColor,
  disabledColor = theme.colors.semantic.neutral,
  disabled = false,
  ...props
}: PillActionButtonProps) {
  const [hovered, setHovered] = useState(false)

  const backgroundColor = disabled ? disabledColor : hovered ? hoverColor : color

  return (
    <button
      {...props}
      disabled={disabled}
      style={{
        padding: '4px 8px',
        backgroundColor,
        color: 'white',
        border: 'none',
        borderRadius: 4,
        fontSize: 12,
        fontWeight: 500,
        cursor: disabled ? 'not-allowed' : 'pointer',
        transition: 'background-color 0.15s',
      }}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
    >
      {children}
    </button>
  )
}
