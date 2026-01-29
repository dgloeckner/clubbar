/**
 * PriceCell Component
 *
 * Renders a table cell with formatted price in monospace font:
 * - Uses JetBrains Mono for consistent alignment
 * - Bold font weight for emphasis
 * - Currency symbol (€) prepended
 * - Amounts in cents converted to decimal
 *
 * Usage:
 * <PriceCell
 *   priceCents={350}
 *   testId="products-table-cell-price-123"
 * />
 * Output: €3.50
 */

import { tableSpacing, tableColors } from '../../styles/tableTokens'

interface PriceCellProps {
  priceCents: number
  currency?: string
  testId?: string
  cellTestId?: string
}

export function PriceCell({
  priceCents,
  currency = '€',
  testId,
  cellTestId,
}: PriceCellProps) {
  const formattedPrice = (priceCents / 100).toFixed(2)

  return (
    <td
      data-testid={cellTestId}
      style={{
        padding: tableSpacing.cellPadding,
        color: tableColors.cellText,
        fontFamily: 'JetBrains Mono, monospace',
        fontSize: '14px',
        fontWeight: '700',
      }}
    >
      <span data-testid={testId}>
        {currency}
        {formattedPrice}
      </span>
    </td>
  )
}
