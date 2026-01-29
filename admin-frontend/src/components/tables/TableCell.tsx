/**
 * TableCell Component
 *
 * Generic table cell with:
 * - Consistent padding and text color
 * - Optional monospace font for numbers
 * - Proper alignment
 *
 * Usage:
 * <TableCell monospace>€25.00</TableCell>
 * <TableCell align="center">Status</TableCell>
 */

import { tableSpacing, tableColors } from '../../styles/tableTokens'

interface TableCellProps {
  children: React.ReactNode
  align?: 'left' | 'center' | 'right'
  monospace?: boolean
  testId?: string
}

export function TableCell({ children, align = 'left', monospace, testId }: TableCellProps) {
  return (
    <td
      data-testid={testId}
      style={{
        padding: tableSpacing.cellPadding,
        color: tableColors.cellText,
        textAlign: align,
        fontFamily: monospace ? 'JetBrains Mono, monospace' : 'inherit',
        fontSize: monospace ? '14px' : 'inherit',
        fontWeight: monospace ? '700' : 'inherit',
      }}
    >
      {children}
    </td>
  )
}
