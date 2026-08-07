/**
 * Type Filter Component (Colored Toggle Pills)
 *
 * Filters transactions by type: All, Purchase, or Storno
 * Uses colored toggle pills with visual indicators (colored dots)
 *
 * Features:
 * - Colored dot indicators (green for purchase, orange for storno)
 * - Clean, compact button group design
 * - Active button highlighted in blue
 * - Callback on type change
 */

export interface TypeFilterProps {
  value?: 'all' | 'purchase' | 'storno'
  onTypeChange?: (type: 'all' | 'purchase' | 'storno') => void
  testId?: string
}

const TYPES = [
  { key: 'all', label: 'All Transactions' },
  { key: 'purchase', label: 'Purchases', dotColor: '#22c55e' }, // Green
  { key: 'storno', label: 'Stornos', dotColor: '#f97316' }, // Orange
] as const

export function TypeFilter({ value = 'all', onTypeChange, testId = 'type-filter' }: TypeFilterProps) {
  const handleClick = (type: 'all' | 'purchase' | 'storno') => {
    if (onTypeChange) {
      onTypeChange(type)
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
      {TYPES.map((type) => (
        <button
          key={type.key}
          onClick={() => handleClick(type.key as 'all' | 'purchase' | 'storno')}
          data-testid={`${testId}-${type.key}`}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            padding: '8px 14px',
            borderRadius: '8px',
            background: value === type.key ? '#3b82f6' : 'transparent',
            border: `1px solid ${value === type.key ? '#3b82f6' : 'rgba(71, 85, 105, 0.4)'}`,
            color: value === type.key ? 'white' : '#94a3b8',
            fontSize: '14px',
            fontWeight: 500,
            cursor: 'pointer',
            transition: 'all 0.15s',
          }}
          onMouseEnter={(e) => {
            if (value !== type.key) {
              e.currentTarget.style.borderColor = 'rgba(71, 85, 105, 0.6)'
              e.currentTarget.style.color = '#f1f5f9'
            }
          }}
          onMouseLeave={(e) => {
            if (value !== type.key) {
              e.currentTarget.style.borderColor = 'rgba(71, 85, 105, 0.4)'
              e.currentTarget.style.color = '#94a3b8'
            }
          }}
        >
          {/* Colored dot indicator (only for purchase/storno) */}
          {type.key !== 'all' && (
            <div
              style={{
                width: '4px',
                height: '16px',
                borderRadius: '2px',
                backgroundColor:
                  value === type.key ? 'white' : (type.dotColor as string),
                opacity: value === type.key ? 0.7 : 1,
              }}
            />
          )}
          {type.label}
        </button>
      ))}
    </div>
  )
}
