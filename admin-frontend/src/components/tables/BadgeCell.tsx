/**
 * BadgeCell Component
 *
 * Renders a table cell with a styled badge/chip:
 * - Styled background with padding
 * - Rounded corners
 * - Consistent font sizing
 * - Optional color customization
 *
 * Usage:
 * <BadgeCell
 *   label="Beer"
 *   testId="products-table-cell-category-123"
 * />
 */

import { tableSpacing, tableColors } from '../../styles/tableTokens'

interface BadgeCellProps {
  label: string
  testId?: string
  cellTestId?: string
  backgroundColor?: string
  textColor?: string
}

export function BadgeCell({
  label,
  testId,
  cellTestId,
  backgroundColor,
  textColor,
}: BadgeCellProps) {
  return (
    <td
      data-testid={cellTestId}
      style={{
        padding: tableSpacing.cellPadding,
        color: tableColors.cellText,
      }}
    >
      <span
        data-testid={testId}
        style={{
          display: 'inline-block',
          padding: `${tableSpacing.badgePaddingVertical} ${tableSpacing.badgePaddingHorizontal}`,
          backgroundColor: backgroundColor || tableColors.badgeBg,
          color: textColor || tableColors.badgeText,
          borderRadius: tableColors.badgeRadius,
          fontSize: tableColors.badgeFontSize,
          fontWeight: tableColors.badgeFontWeight,
        }}
      >
        {label}
      </span>
    </td>
  )
}
