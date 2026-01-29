/**
 * TableHeader Component
 *
 * Renders consistent table header row with:
 * - Standardized styling from design tokens
 * - Optional sortable column headers
 * - Proper spacing and alignment
 *
 * Usage:
 * <TableHeader
 *   columns={[
 *     { id: 'status', label: 'Status', width: '60px', align: 'center' },
 *     { id: 'name', label: 'Name', sortable: true, sortKey: 'name' },
 *     { id: 'price', label: 'Price', sortable: true, sortKey: 'price' },
 *   ]}
 *   currentSort={{ key: 'name', direction: 'asc' }}
 *   onSort={(key, dir) => handleSort(key, dir)}
 * />
 */

import { SortableTableHeader } from './SortableTableHeader'
import { headerCellBaseStyle, headerRowStyle } from '../../styles/tableTokens'

export interface TableColumn {
  id: string
  label: string
  sortable?: boolean
  sortKey?: string
  align?: 'left' | 'center' | 'right'
  width?: string
  testId?: string
}

interface TableHeaderProps {
  columns: TableColumn[]
  currentSort?: { key: string; direction: 'asc' | 'desc' }
  onSort?: (key: string, direction: 'asc' | 'desc') => void
  testId?: string
}

export function TableHeader({
  columns,
  currentSort,
  onSort,
  testId = 'table-header',
}: TableHeaderProps) {
  return (
    <thead data-testid={testId}>
      <tr style={headerRowStyle}>
        {columns.map((col) => (
          <th
            key={col.id}
            data-testid={col.testId || `header-${col.id}`}
            style={{
              ...headerCellBaseStyle,
              textAlign: col.align || 'left',
              width: col.width,
            }}
          >
            {col.sortable && currentSort && onSort ? (
              <SortableTableHeader
                label={col.label}
                sortKey={col.sortKey || col.id}
                currentSort={currentSort}
                onSort={onSort}
              />
            ) : (
              col.label
            )}
          </th>
        ))}
      </tr>
    </thead>
  )
}
