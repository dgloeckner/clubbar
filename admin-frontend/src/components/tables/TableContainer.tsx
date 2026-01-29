/**
 * TableContainer Component
 *
 * Wraps table with consistent styling:
 * - Horizontal scroll on overflow
 * - Rounded corners
 * - Proper spacing
 *
 * Usage:
 * <TableContainer testId="products-table">
 *   <thead>...</thead>
 *   <tbody>...</tbody>
 * </TableContainer>
 */

import { tableWrapperStyles, tableElementStyles } from '../../styles/tableTokens'

interface TableContainerProps {
  children: React.ReactNode
  testId?: string
}

export function TableContainer({ children, testId = 'table-wrapper' }: TableContainerProps) {
  return (
    <div data-testid={testId} style={tableWrapperStyles}>
      <table style={tableElementStyles}>{children}</table>
    </div>
  )
}
