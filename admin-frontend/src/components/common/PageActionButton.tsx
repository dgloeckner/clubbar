/**
 * PageActionButton — a page-level action, the kind that sits in `PageHeader`'s
 * actions slot (#375).
 *
 * The same action rendered on two tabs did not look like the same action:
 * "Neue Abrechnung" was an emerald 6px-radius medium-weight button on
 * Settlements and a blue 8px-radius semibold `+ `-prefixed one on Journal.
 * Members' three buttons had a third shape again. So the shape — padding,
 * radius, weight, icon gap, hover — is decided here, and a call site chooses
 * only a `variant`, which says what kind of action it is.
 *
 * `PillActionButton` is the row-level sibling: smaller, one per table row, and
 * colour-per-call-site because a row action's colour tracks its row's state.
 * A page action has no row, so it gets a fixed vocabulary instead.
 */

import React, { useState } from 'react'
import { theme } from '../../styles/design-system'

export type PageActionVariant = 'primary' | 'success' | 'warning' | 'secondary'

/** Background + hover pair per variant. `secondary` is outlined, not filled. */
const variantColors: Record<Exclude<PageActionVariant, 'secondary'>, { bg: string; hover: string }> = {
  primary: { bg: theme.colors.semantic.primary, hover: theme.colors.semantic.primaryHover },
  success: { bg: theme.colors.semantic.emerald, hover: theme.colors.semantic.emeraldHover },
  warning: { bg: theme.colors.semantic.amber, hover: theme.colors.semantic.amberHover },
}

interface PageActionButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  children: React.ReactNode
  /**
   * `primary` for the page's main action, `success` for one that commits money
   * or files, `warning` for one that corrects something after the fact,
   * `secondary` for everything that shouldn't compete with them.
   */
  variant?: PageActionVariant
  /** Rendered before the label, and alone when `iconOnly` is set. */
  icon?: React.ReactNode
  /**
   * Drop the label and keep the icon — for narrow viewports, where three
   * labelled actions do not fit. The caller passes its own breakpoint check;
   * `title`/`aria-label` then carry the name, so set one.
   */
  iconOnly?: boolean
}

export function PageActionButton({
  children,
  variant = 'primary',
  icon,
  iconOnly = false,
  disabled = false,
  style,
  ...props
}: PageActionButtonProps) {
  const [hovered, setHovered] = useState(false)

  const filled = variant !== 'secondary'
  const colors = filled ? variantColors[variant] : null

  // Disabled only greys a *filled* button. An outlined one keeps its outline
  // and dims its label — filling it grey on disable would make the quietest
  // button on the page the most solid thing in the header.
  const background = filled
    ? disabled
      ? theme.colors.semantic.neutral
      : hovered
        ? colors!.hover
        : colors!.bg
    : disabled || !hovered
      ? 'transparent'
      : theme.colors.bg.surfaceSubtle

  return (
    <button
      {...props}
      disabled={disabled}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '7px',
        padding: iconOnly ? '10px' : '10px 20px',
        borderRadius: theme.borderRadius.sm,
        border: filled ? 'none' : `1px solid ${theme.colors.border.subtle}`,
        background,
        color: disabled && !filled ? theme.colors.text.secondary : 'white',
        fontFamily: 'inherit',
        fontSize: theme.typography.fontSize.base,
        fontWeight: theme.typography.fontWeight.semibold,
        whiteSpace: 'nowrap',
        cursor: disabled ? 'not-allowed' : 'pointer',
        transition: `background ${theme.transitions.default}`,
        ...style,
      }}
    >
      {icon}
      {!iconOnly && children}
    </button>
  )
}
