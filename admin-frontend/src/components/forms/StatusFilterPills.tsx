/**
 * Status Filter Pills Component
 *
 * Filters items by status: All, Active, or Inactive
 * Uses toggle pills with blue highlight for active selection
 *
 * Features:
 * - Clean, compact button group design
 * - Active button highlighted in blue
 * - Inactive buttons show transparent background
 * - Callback on status change
 */

export interface StatusFilterPillsProps {
  value?: 'all' | 'active' | 'inactive'
  onChange?: (status: 'all' | 'active' | 'inactive') => void
  testId?: string
}

const STATUSES = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'inactive', label: 'Inactive' },
] as const

export function StatusFilterPills({ value = 'all', onChange, testId = 'status-filter-pills' }: StatusFilterPillsProps) {
  const handleClick = (status: 'all' | 'active' | 'inactive') => {
    if (onChange) {
      onChange(status)
    }
  }

  return (
    <div
      data-testid={testId}
      style={{
        display: 'inline-flex',
        gap: '8px',
      }}
    >
      {STATUSES.map((status) => (
        <button
          key={status.key}
          onClick={() => handleClick(status.key as 'all' | 'active' | 'inactive')}
          data-testid={`${testId}-${status.key}`}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            padding: '8px 14px',
            borderRadius: '8px',
            background: value === status.key ? '#3b82f6' : 'transparent',
            border: `1px solid ${value === status.key ? '#3b82f6' : 'rgba(71, 85, 105, 0.4)'}`,
            color: value === status.key ? 'white' : '#94a3b8',
            fontSize: '14px',
            fontWeight: 500,
            cursor: 'pointer',
            transition: 'all 0.15s',
          }}
          onMouseEnter={(e) => {
            if (value !== status.key) {
              e.currentTarget.style.borderColor = 'rgba(71, 85, 105, 0.6)'
              e.currentTarget.style.color = '#f1f5f9'
            }
          }}
          onMouseLeave={(e) => {
            if (value !== status.key) {
              e.currentTarget.style.borderColor = 'rgba(71, 85, 105, 0.4)'
              e.currentTarget.style.color = '#94a3b8'
            }
          }}
        >
          {status.label}
        </button>
      ))}
    </div>
  )
}
