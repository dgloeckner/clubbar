/**
 * TableSearchToolbar Component
 *
 * Optional toolbar with search field for tables:
 * - Search input with debouncing
 * - Optional additional controls (buttons, filters)
 * - Consistent styling with design tokens
 *
 * Usage:
 * <TableSearchToolbar
 *   value={search}
 *   onChange={setSearch}
 *   placeholder="Search members..."
 *   testId="members-search-toolbar"
 *   actions={<button>Create</button>}
 * />
 */

import { tableColors, tableSpacing } from '../../styles/tableTokens'

interface TableSearchToolbarProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  testId?: string
  actions?: React.ReactNode
}

export function TableSearchToolbar({
  value,
  onChange,
  placeholder = 'Search...',
  testId,
  actions,
}: TableSearchToolbarProps) {
  return (
    <div
      data-testid={testId}
      style={{
        display: 'flex',
        gap: tableSpacing.actionButtonGap,
        alignItems: 'center',
        padding: tableSpacing.cellPadding,
        borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
        backgroundColor: 'transparent',
      }}
    >
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        style={{
          flex: 1,
          padding: `${tableSpacing.cellPaddingVertical} ${tableSpacing.cellPaddingHorizontal}`,
          backgroundColor: 'rgba(15, 29, 50, 0.4)',
          border: `1px solid ${tableColors.rowActiveBorder}`,
          borderRadius: '6px',
          color: tableColors.cellText,
          fontSize: '14px',
          fontFamily: 'inherit',
        }}
      />
      {actions}
    </div>
  )
}
