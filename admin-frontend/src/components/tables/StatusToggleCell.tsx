/**
 * StatusToggleCell Component
 *
 * Renders a table cell with a Toggle component for active/inactive state:
 * - Centered within the cell
 * - Consistent padding from design tokens
 * - Toggle size and testId configurable
 *
 * Usage:
 * <StatusToggleCell
 *   enabled={product.is_active}
 *   onChange={() => handleStatusToggle(product)}
 *   testId="products-status-toggle-123"
 * />
 */

import { Toggle } from '../common/Toggle'
import { tableSpacing } from '../../styles/tableTokens'

interface StatusToggleCellProps {
  enabled: boolean
  onChange: () => void
  size?: 'small' | 'default' | 'large'
  testId?: string
  cellTestId?: string
}

export function StatusToggleCell({
  enabled,
  onChange,
  size = 'small',
  testId,
  cellTestId,
}: StatusToggleCellProps) {
  return (
    <td
      data-testid={cellTestId}
      style={{
        padding: tableSpacing.cellPadding,
        textAlign: 'center',
      }}
    >
      <Toggle isEnabled={enabled} onChange={onChange} size={size} testId={testId} />
    </td>
  )
}
