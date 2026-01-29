/**
 * Sortable Table Header Component
 * Makes table headers clickable to sort
 * Based on prototypes/Pagination.jsx SortableHeader
 *
 * Props:
 * - label: Display label
 * - sortKey: Data key for this column
 * - currentSort: Current sort state { key, direction }
 * - onSort: Callback with (key, direction)
 * - testId: Test ID for the button
 */

interface SortableTableHeaderProps {
  label: string
  sortKey: string
  currentSort: { key: string; direction: 'asc' | 'desc' }
  onSort: (key: string, direction: 'asc' | 'desc') => void
  testId?: string
}

function ArrowUpIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="19" x2="12" y2="5" />
      <polyline points="5 12 12 5 19 12" />
    </svg>
  )
}

function ArrowDownIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="5" x2="12" y2="19" />
      <polyline points="19 12 12 19 5 12" />
    </svg>
  )
}

function ArrowUpDownIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M7 15l5 5 5-5" />
      <path d="M7 9l5-5 5 5" />
    </svg>
  )
}

export function SortableTableHeader({
  label,
  sortKey,
  currentSort,
  onSort,
  testId = 'sortable-header',
}: SortableTableHeaderProps) {
  const isActive = currentSort.key === sortKey
  const direction = isActive ? currentSort.direction : null

  return (
    <button
      data-testid={testId}
      onClick={() => onSort(sortKey, isActive && direction === 'asc' ? 'desc' : 'asc')}
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 6,
        background: 'transparent',
        border: 'none',
        color: isActive ? '#3b82f6' : '#cbd5e1',
        fontSize: 12,
        fontWeight: 600,
        textTransform: 'uppercase',
        letterSpacing: '0.05em',
        cursor: 'pointer',
        padding: '8px 0',
      }}
    >
      {label}
      <span style={{ opacity: isActive ? 1 : 0.4 }}>
        {direction === 'asc' ? <ArrowUpIcon /> : direction === 'desc' ? <ArrowDownIcon /> : <ArrowUpDownIcon />}
      </span>
    </button>
  )
}
