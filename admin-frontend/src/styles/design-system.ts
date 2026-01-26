/**
 * Design System & Theme Configuration
 * Based on prototypes/frgs-admin.html specifications
 */

export const theme = {
  colors: {
    // Background colors
    bg: {
      primary: '#0a1628',    // Main background
      secondary: '#0f1d32',  // Cards, panels
      card: '#1a2744',       // Content cards
      input: '#0d1829',      // Form fields
      hover: '#15213f',      // Hover states
    },

    // Semantic colors
    semantic: {
      primary: '#3b82f6',    // Blue - primary action, info
      success: '#22c55e',    // Green - success
      warning: '#f97316',    // Orange - warning, balance
      danger: '#ef4444',     // Red - danger, errors
      info: '#0ea5e9',       // Cyan - informational
    },

    // Text colors
    text: {
      primary: '#f1f5f9',    // Primary text
      secondary: '#94a3b8',  // Secondary text
      muted: '#64748b',      // Muted text
      dark: '#0f172a',       // Dark text on light bg
    },

    // Border & divider colors
    border: {
      light: '#334155',      // Light border
      dark: '#1e293b',       // Dark border
      focus: '#3b82f6',      // Focus border
    }
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
  }
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
 */
export function formatDate(dateString: string, locale: string = 'de-DE'): string {
  try {
    const date = new Date(dateString)
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
    const date = new Date(dateString)
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
    case 'manual_adjustment':
      return theme.colors.semantic.info
    case 'reversal':
      return theme.colors.semantic.warning
    case 'correction':
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
