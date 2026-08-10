/**
 * Design System & Theme Configuration
 * Based on prototypes/frgs-admin.html specifications
 */

import { parseApiDate } from '../utils/dates'

/**
 * Compose a hex color with an alpha channel into a canonical `rgba()` string.
 * Derive new tint/border/backdrop tokens with this instead of hand-writing an
 * `rgba()` literal, so every consumer gets identical comma-spacing — hand-written
 * literals for the same color have drifted into two spellings before (#289).
 */
export function withAlpha(hex: string, alpha: number): string {
  const normalized = hex.replace('#', '')
  const r = parseInt(normalized.slice(0, 2), 16)
  const g = parseInt(normalized.slice(2, 4), 16)
  const b = parseInt(normalized.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

export const theme = {
  colors: {
    // Background colors
    bg: {
      primary: '#0a1628',    // Main background
      secondary: '#0f1d32',  // Cards, panels
      tertiary: '#11233a',   // Tertiary background (for disabled states)
      card: '#1a2744',       // Content cards
      input: '#0d1829',      // Form fields
      inputAlt: '#1e293b',   // Form fields on a card/modal background
      hover: '#15213f',      // Hover states
      gradientStart: '#1e3a5f', // Hero/summary card gradient start (paired with bg.input as the end stop)
      tooltip: '#1f2937',    // Tooltip surface
    },

    // Semantic colors
    semantic: {
      primary: '#3b82f6',      // Blue - primary action, info
      primaryHover: '#2563eb', // Blue - primary hover state
      success: '#22c55e',      // Green - success
      warning: '#f97316',      // Orange - warning, balance
      warningLight: '#fdba74', // Orange - warning text on dark backgrounds
      danger: '#ef4444',       // Red - danger, errors
      dangerHover: '#dc2626',  // Red - danger hover state / error text
      info: '#0ea5e9',         // Cyan - informational
      emerald: '#10b981',      // Green - secondary success action (e.g. CSV export)
      emeraldHover: '#059669', // Green - emerald hover state
      purple: '#8b5cf6',       // Purple - secondary action (e.g. detailed export)
      purpleHover: '#7c3aed',  // Purple - purple hover state
      neutral: '#6b7280',      // Gray - disabled/neutral action
      blocked: '#78716c',      // Stone - action the backend gate refuses (not merely disabled)
      blockedHover: '#57534e', // Stone - blocked hover state
      violet: '#a855f7',       // Violet - a third category alongside primary/purple (e.g. payout transactions)
      amber: '#f59e0b',        // Amber - a third warning-adjacent category, distinct from `warning`
      teal: '#14b8a6',         // Teal - mirrors the terminal UI's price color (product preview)
    },

    // Text colors
    text: {
      primary: '#f1f5f9',    // Primary text
      secondary: '#94a3b8',  // Secondary text
      subtle: '#a0aec0',     // Idle text, slightly lighter than secondary (e.g. dot-variant pill filters)
      muted: '#64748b',      // Muted text
      dark: '#0f172a',       // Dark text on light bg
    },

    // Border & divider colors
    border: {
      light: '#334155',      // Light border
      dark: '#1e293b',       // Dark border
      focus: '#3b82f6',      // Focus border
      input: '#2d3748',      // Input field border
      muted: '#4b5563',      // Muted input/control border
    },

    // Alert/banner colors (opaque, for banners over the dark background)
    alert: {
      dangerBg: '#fee2e2',   // Error banner background
    },

    // Full-width status banners (dark bg, light text on the dark theme)
    banner: {
      dangerBg: '#7f1d1d',
      dangerText: '#fca5a5',
      warningBg: '#78350f',
      warningText: '#fcd34d',
      successBg: '#064e3b',
      successText: '#6ee7b7',
    },

    // Light, saturated text on a translucent tinted background — small badges
    // and highlighted text (e.g. mandate-extraction confidence, matched IBAN
    // candidates), distinct from the `banner` family's full-width bars.
    pastel: {
      green: '#86efac',
      yellow: '#fde047',
      purple: '#c4b5fd',
      blue: '#93c5fd',
      cyan: '#7dd3fc',
    },
  },

  typography: {
    fontFamily: {
      base: '-apple-system, BlinkMacSystemFont, "Segoe UI", "Roboto", "Helvetica Neue", sans-serif',
      mono: '"Monaco", "Menlo", "Ubuntu Mono", monospace'
    },
    fontSize: {
      xs: '12px',
      sm: '13px',
      base: '14px',
      lg: '16px',
      xl: '18px',
      '2xl': '20px',
      '3xl': '24px',
    },
    fontWeight: {
      normal: 400,
      medium: 500,
      semibold: 600,
      bold: 700,
    },
    lineHeight: {
      tight: 1.2,
      normal: 1.5,
      relaxed: 1.75,
    }
  },

  spacing: {
    xs: '4px',
    sm: '8px',
    md: '12px',
    lg: '16px',
    xl: '20px',
    '2xl': '24px',
    '3xl': '32px',
  },

  borderRadius: {
    sm: '8px',
    md: '12px',
    lg: '16px',
    xl: '20px',
    full: '9999px',
  },

  shadows: {
    none: 'none',
    sm: '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
    md: '0 4px 6px -1px rgba(0, 0, 0, 0.1)',
    lg: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
    xl: '0 20px 25px -5px rgba(0, 0, 0, 0.1)',
    modal: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
  },

  transitions: {
    default: '150ms ease-in-out',
    fast: '100ms ease-in-out',
    slow: '200ms ease-in-out',
  },

  // Avatar gradients (5 color schemes)
  avatars: {
    gradients: {
      blue: 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%)',
      green: 'linear-gradient(135deg, #22c55e 0%, #10b981 100%)',
      orange: 'linear-gradient(135deg, #f97316 0%, #fb923c 100%)',
      pink: 'linear-gradient(135deg, #ec4899 0%, #f472b6 100%)',
      gray: 'linear-gradient(135deg, #64748b 0%, #94a3b8 100%)',
    },
    sizes: {
      sm: '32px',
      md: '40px',
      lg: '48px',
    },
  },

  // Status badge styles
  badges: {
    success: {
      bg: 'rgba(34, 197, 94, 0.1)',
      text: '#22c55e',
      dot: '#22c55e',
    },
    warning: {
      bg: 'rgba(251, 146, 60, 0.1)',
      text: '#f97316',
      dot: '#f97316',
    },
    danger: {
      bg: 'rgba(239, 68, 68, 0.1)',
      text: '#ef4444',
      dot: '#ef4444',
    },
    info: {
      bg: 'rgba(59, 130, 246, 0.1)',
      text: '#3b82f6',
      dot: '#3b82f6',
    },
    neutral: {
      bg: 'rgba(107, 114, 128, 0.1)',
      text: '#64748b',
      dot: '#64748b',
    },
  },

  // Full-screen overlays. Every modal needs an identical dimming backdrop;
  // add further (color, opacity) tints here via withAlpha() as they're
  // migrated off raw rgba() literals (#289).
  overlay: {
    backdrop: withAlpha('#000000', 0.5),
  },
}

/**
 * Responsive Breakpoints (Phase 5)
 * Used by useBreakpoint hook for responsive behavior
 */
export const breakpoints = {
  smallMobile: 480,   // iPhone SE and smaller
  mobile: 768,        // iPad portrait
  tablet: 1500,       // iPad landscape / narrow desktop — nav labels need ~1500px
  desktop: 1440,      // Large screens
}

/**
 * Media Query Utilities (for styled-components or emotion)
 * Usage: const Button = styled.button`${mediaQuery.mobile} { ... }`
 */
export const mediaQuery = {
  smallMobile: `@media (max-width: ${breakpoints.smallMobile}px)`,
  mobile: `@media (max-width: ${breakpoints.mobile}px)`,
  tablet: `@media (max-width: ${breakpoints.tablet}px)`,
  desktop: `@media (min-width: ${breakpoints.tablet + 1}px)`,
}

/**
 * Utility function to format prices (EUR with German locale)
 */
export function formatPrice(centAmount: number, locale: string = 'de-DE'): string {
  const euros = centAmount / 100
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(euros)
}

/**
 * Utility function to format IBAN (mask except last 4 digits)
 */
export function formatIban(iban: string): string {
  if (!iban || iban.length < 10) return iban
  return iban.slice(0, -4).replace(/./g, '*') + iban.slice(-4)
}

/**
 * Utility function to format dates (German DD.MM.YYYY format)
 *
 * Date-only values are parsed as local calendar days (see `parseApiDate`), so a
 * `settlement_date` of 2026-08-05 reads as the 5th in every timezone.
 */
export function formatDate(dateString: string, locale: string = 'de-DE'): string {
  try {
    const date = parseApiDate(dateString)
    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(date)
  } catch {
    return dateString
  }
}

/**
 * Utility function to format datetime (with time)
 */
export function formatDateTime(dateString: string, locale: string = 'de-DE'): string {
  try {
    const date = parseApiDate(dateString)
    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(date)
  } catch {
    return dateString
  }
}

/*
 * There is deliberately no `formatRelativeDate` here. Today/Yesterday/Never are
 * words, not formats, so the relative variant needs the active locale's
 * translations rather than an Intl locale tag: it lives in `useFormatters()`.
 */

/**
 * Get balance color based on amount (in cents)
 */
export function getBalanceColor(balanceCents: number): string {
  if (balanceCents > 0) return theme.colors.semantic.success
  if (balanceCents < 0) return theme.colors.semantic.warning
  return theme.colors.text.secondary
}

/**
 * Get transaction color based on type
 */
export function getTransactionColor(type: string): string {
  switch (type) {
    case 'purchase':
      return theme.colors.semantic.danger
    case 'reversal':
      return theme.colors.semantic.warning
    case 'storno':
      return theme.colors.semantic.info
    case 'payout':
      return theme.colors.semantic.info
    default:
      return theme.colors.text.secondary
  }
}

/**
 * CSS-in-JS helper for styled components (using template literals)
 */
export const styles = {
  // Layout
  container: `
    max-width: 1400px;
    margin: 0 auto;
    padding: ${theme.spacing.lg};
  `,

  // Flexbox utilities
  flex: `
    display: flex;
    gap: ${theme.spacing.md};
  `,

  flexColumn: `
    display: flex;
    flex-direction: column;
    gap: ${theme.spacing.md};
  `,

  // Grid utilities
  grid2: `
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: ${theme.spacing.lg};
  `,

  grid3: `
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: ${theme.spacing.lg};
  `,

  // Cards
  card: `
    background: ${theme.colors.bg.card};
    border: 1px solid ${theme.colors.border.light};
    border-radius: ${theme.borderRadius.lg};
    padding: ${theme.spacing.lg};
  `,

  cardInteractive: `
    background: ${theme.colors.bg.card};
    border: 1px solid ${theme.colors.border.light};
    border-radius: ${theme.borderRadius.lg};
    padding: ${theme.spacing.lg};
    cursor: pointer;
    transition: all ${theme.transitions.default};

    &:hover {
      background: ${theme.colors.bg.hover};
      border-color: ${theme.colors.border.focus};
    }
  `,

  // Buttons
  button: `
    padding: ${theme.spacing.md} ${theme.spacing.lg};
    border: none;
    border-radius: ${theme.borderRadius.md};
    font-family: ${theme.typography.fontFamily.base};
    font-size: ${theme.typography.fontSize.base};
    font-weight: ${theme.typography.fontWeight.semibold};
    cursor: pointer;
    transition: all ${theme.transitions.default};
  `,

  buttonPrimary: `
    padding: ${theme.spacing.md} ${theme.spacing.lg};
    background: ${theme.colors.semantic.primary};
    color: white;
    border: none;
    border-radius: ${theme.borderRadius.md};
    font-family: ${theme.typography.fontFamily.base};
    font-size: ${theme.typography.fontSize.base};
    font-weight: ${theme.typography.fontWeight.semibold};
    cursor: pointer;
    transition: all ${theme.transitions.default};

    &:hover {
      background: #2563eb;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
  `,

  // Inputs
  input: `
    background: ${theme.colors.bg.input};
    border: 1px solid ${theme.colors.border.light};
    border-radius: ${theme.borderRadius.md};
    padding: ${theme.spacing.md} ${theme.spacing.lg};
    color: ${theme.colors.text.primary};
    font-family: ${theme.typography.fontFamily.base};
    font-size: ${theme.typography.fontSize.base};
    transition: all ${theme.transitions.default};

    &:focus {
      outline: none;
      border-color: ${theme.colors.border.focus};
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    &::placeholder {
      color: ${theme.colors.text.muted};
    }
  `,

  // Tables
  table: `
    width: 100%;
    border-collapse: collapse;
    color: ${theme.colors.text.primary};
  `,

  tableRow: `
    border-bottom: 1px solid ${theme.colors.border.light};
    transition: background ${theme.transitions.default};

    &:hover {
      background: ${theme.colors.bg.hover};
    }
  `,

  tableCell: `
    padding: ${theme.spacing.lg} ${theme.spacing.md};
    text-align: left;
  `,

  tableHeader: `
    background: ${theme.colors.bg.secondary};
    padding: ${theme.spacing.lg} ${theme.spacing.md};
    font-weight: ${theme.typography.fontWeight.semibold};
    font-size: ${theme.typography.fontSize.sm};
    text-transform: uppercase;
    color: ${theme.colors.text.secondary};
  `,
}
