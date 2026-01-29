/**
 * IconCell Component
 *
 * Renders a table cell with an icon + text label:
 * - Icon displayed on the left (20px size)
 * - Text label on the right
 * - Consistent spacing from design tokens
 * - Optional icon size override
 *
 * Usage:
 * <IconCell
 *   icon={PilsIcon}
 *   label="Premium Pils"
 *   iconTestId="products-table-cell-icon-123"
 *   labelTestId="products-table-cell-name-123"
 * />
 */

import React from 'react'
import { tableSpacing, tableColors } from '../../styles/tableTokens'

interface IconCellProps {
  icon: React.FC<{ size: number }>
  label: string
  iconSize?: number
  iconTestId?: string
  labelTestId?: string
  cellTestId?: string
}

export function IconCell({
  icon: Icon,
  label,
  iconSize = 20,
  iconTestId,
  labelTestId,
  cellTestId,
}: IconCellProps) {
  return (
    <td
      data-testid={cellTestId}
      style={{
        padding: tableSpacing.cellPadding,
        color: tableColors.cellText,
        display: 'flex',
        alignItems: 'center',
        gap: tableSpacing.iconGap,
      }}
    >
      <Icon size={iconSize} data-testid={iconTestId} />
      <span data-testid={labelTestId} style={{ fontWeight: '500' }}>
        {label}
      </span>
    </td>
  )
}
