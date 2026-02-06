/**
 * PriceCell Component
 *
 * Renders a table cell with formatted price in monospace font:
 * - Uses JetBrains Mono for consistent alignment
 * - Bold font weight for emphasis
 * - Locale-aware currency formatting
 * - Amounts in cents converted to decimal
 *
 * Usage:
 * <PriceCell
 *   priceCents={350}
 *   testId="products-table-cell-price-123"
 * />
 * Output (de): 3,50 €
 * Output (en): €3.50
 */

import { tableSpacing, tableColors } from '../../styles/tableTokens'
import { useFormatters } from '../../hooks/useFormatters'

interface PriceCellProps {
  priceCents: number
  testId?: string
  cellTestId?: string
}

export function PriceCell({
  priceCents,
  testId,
  cellTestId,
}: PriceCellProps) {
  const { formatPrice } = useFormatters()
  const formattedPrice = formatPrice(priceCents)

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
        {formattedPrice}
      </span>
    </td>
  )
}
