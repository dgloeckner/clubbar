/**
 * Table Design Tokens
 *
 * Extracted from ProductsPage inline styles
 * Single source of truth for all table styling
 * Usage: Import and apply to table components
 */

// Colors
export const tableColors = {
  // Header row
  headerBg: 'rgba(15, 29, 50, 0.6)',
  headerBorder: '1px solid rgba(71, 85, 105, 0.3)',
  headerText: '#cbd5e1',
  headerFontSize: '12px',
  headerFontWeight: '600',
  headerLetterSpacing: '0.05em',

  // Body row - active (is_active = true)
  rowActiveBg: 'rgba(30, 58, 138, 0.2)',
  rowActiveHoverBg: 'rgba(59, 130, 246, 0.15)',
  rowActiveBorder: '1px solid rgba(71, 85, 105, 0.2)',
  rowActiveOpacity: 1,

  // Body row - inactive (is_active = false)
  rowInactiveBg: 'rgba(30, 58, 138, 0.1)',
  rowInactiveBorder: '1px solid rgba(71, 85, 105, 0.2)',
  rowInactiveOpacity: 0.5,

  // Cell text
  cellText: '#e2e8f0',
  cellSecondaryText: '#a1aec6',

  // Badge/chip styling
  badgeBg: 'rgba(71, 85, 105, 0.3)',
  badgeText: '#a1aec6',
  badgeRadius: '16px',
  badgeFontSize: '13px',
  badgeFontWeight: '500',

  // Action button - primary (edit)
  buttonPrimaryBg: 'rgba(59, 130, 246, 0.15)',
  buttonPrimaryBorder: 'rgba(59, 130, 246, 0.3)',
  buttonPrimaryHoverBg: 'rgba(59, 130, 246, 0.25)',
  buttonPrimaryHoverBorder: 'rgba(59, 130, 246, 0.5)',
  buttonPrimaryText: '#3b82f6',

  // Action button - danger (delete)
  buttonDangerBg: 'rgba(239, 68, 68, 0.15)',
  buttonDangerBorder: 'rgba(239, 68, 68, 0.3)',
  buttonDangerHoverBg: 'rgba(239, 68, 68, 0.25)',
  buttonDangerHoverBorder: 'rgba(239, 68, 68, 0.5)',
  buttonDangerText: '#ef4444',

  // Button border styling
  buttonBorderRadius: '8px',
  buttonBorderWidth: '1px',
}

// Spacing
export const tableSpacing = {
  cellPadding: '14px 16px',
  cellPaddingVertical: '14px',
  cellPaddingHorizontal: '16px',

  actionButtonSize: '40px',
  actionButtonGap: '10px',
  iconGap: '12px',

  badgePaddingVertical: '6px',
  badgePaddingHorizontal: '12px',

  tableWrapperRadius: '16px',
}

// Transitions
export const tableTransitions = {
  standard: '150ms',
  slow: '200ms',
}

// Table wrapper styling
export const tableWrapperStyles = {
  overflowX: 'auto' as const,
  overflowY: 'hidden' as const,
  borderRadius: tableSpacing.tableWrapperRadius,
}

// Table element styling
export const tableElementStyles = {
  width: '100%',
  borderCollapse: 'collapse' as const,
  backgroundColor: 'transparent',
  tableLayout: 'fixed' as const,
}

// Header cell common style object
export const headerCellBaseStyle = {
  padding: tableSpacing.cellPadding,
  fontWeight: tableColors.headerFontWeight,
  color: tableColors.headerText,
  fontSize: tableColors.headerFontSize,
  letterSpacing: tableColors.headerLetterSpacing,
  textTransform: 'uppercase' as const,
}

// Header row style object
export const headerRowStyle = {
  backgroundColor: tableColors.headerBg,
  borderBottom: tableColors.headerBorder,
}

// Utility function to get row styling based on active state
export function getRowStyle(isActive: boolean) {
  return {
    borderBottom: isActive ? tableColors.rowActiveBorder : tableColors.rowInactiveBorder,
    backgroundColor: isActive ? tableColors.rowActiveBg : tableColors.rowInactiveBg,
    opacity: isActive ? tableColors.rowActiveOpacity : tableColors.rowInactiveOpacity,
    transition: `background-color ${tableTransitions.standard}`,
  }
}

// Utility function to get button styling based on variant
export function getButtonStyle(variant: 'primary' | 'danger', isHovered: boolean) {
  const colors = variant === 'primary'
    ? {
        bg: isHovered ? tableColors.buttonPrimaryHoverBg : tableColors.buttonPrimaryBg,
        border: isHovered ? tableColors.buttonPrimaryHoverBorder : tableColors.buttonPrimaryBorder,
        text: tableColors.buttonPrimaryText,
      }
    : {
        bg: isHovered ? tableColors.buttonDangerHoverBg : tableColors.buttonDangerBg,
        border: isHovered ? tableColors.buttonDangerHoverBorder : tableColors.buttonDangerBorder,
        text: tableColors.buttonDangerText,
      }

  return {
    width: tableSpacing.actionButtonSize,
    height: tableSpacing.actionButtonSize,
    padding: '0',
    backgroundColor: colors.bg,
    border: `${tableColors.buttonBorderWidth} solid ${colors.border}`,
    borderRadius: tableColors.buttonBorderRadius,
    color: colors.text,
    cursor: 'pointer' as const,
    display: 'flex' as const,
    alignItems: 'center' as const,
    justifyContent: 'center' as const,
    transition: `all ${tableTransitions.standard}`,
  }
}
