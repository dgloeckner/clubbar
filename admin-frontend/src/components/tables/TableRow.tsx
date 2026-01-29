/**
 * TableRow Component
 *
 * Renders consistent table row with:
 * - Active/inactive state styling
 * - Hover effects
 * - Proper opacity and transitions
 *
 * Usage:
 * <TableRow id={product.id} isActive={product.is_active}>
 *   <td>...</td>
 *   <td>...</td>
 * </TableRow>
 */

import React, { useState } from 'react'
import { getRowStyle, tableColors } from '../../styles/tableTokens'

interface TableRowProps {
  id: string
  isActive?: boolean
  children: React.ReactNode
  onClick?: () => void
  testId?: string
}

export function TableRow({ id, isActive = true, children, onClick, testId }: TableRowProps) {
  const [isHovered, setIsHovered] = useState(false)

  const baseStyle = getRowStyle(isActive)
  const hoverBg = isHovered && isActive ? tableColors.rowActiveHoverBg : undefined

  return (
    <tr
      data-testid={testId || id}
      style={{
        ...baseStyle,
        backgroundColor: hoverBg || baseStyle.backgroundColor,
      }}
      onClick={onClick}
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      {children}
    </tr>
  )
}
