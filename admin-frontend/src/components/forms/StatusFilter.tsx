/**
 * Status Filter
 * Pill-based filter for settlement status (All | Active | Cancelled)
 *
 * Used by: SettlementsPage for filtering settlements by status
 */

import { useTranslation } from 'react-i18next'

interface StatusFilterProps {
  value: 'all' | 'active' | 'cancelled'
  onChange: (value: 'all' | 'active' | 'cancelled') => void
  testId?: string
}

export function StatusFilter({
  value,
  onChange,
  testId = 'status-filter',
}: StatusFilterProps) {
  const { t } = useTranslation()

  const options: Array<{
    value: 'all' | 'active' | 'cancelled'
    label: string
    color: string
  }> = [
    { value: 'all', label: t('common.all'), color: '#6b7280' },
    { value: 'active', label: t('settlements.active'), color: '#3b82f6' },
    { value: 'cancelled', label: t('settlements.cancelled'), color: '#ef4444' },
  ]

  return (
    <div
      data-testid={testId}
      style={{
        display: 'flex',
        gap: '8px',
      }}
    >
      {options.map((option) => (
        <button
          key={option.value}
          data-testid={`${testId}-${option.value}`}
          onClick={() => onChange(option.value)}
          style={{
            padding: '6px 12px',
            borderRadius: '20px',
            border: value === option.value ? `2px solid ${option.color}` : '1px solid #4b5563',
            backgroundColor: value === option.value ? `${option.color}20` : 'transparent',
            color: value === option.value ? option.color : '#a0aec0',
            fontSize: '13px',
            fontWeight: value === option.value ? 600 : 500,
            cursor: 'pointer',
            transition: 'all 0.15s',
            display: 'flex',
            alignItems: 'center',
            gap: '6px',
          }}
          onMouseEnter={(e) => {
            if (value !== option.value) {
              e.currentTarget.style.borderColor = option.color
              e.currentTarget.style.color = option.color
            }
          }}
          onMouseLeave={(e) => {
            if (value !== option.value) {
              e.currentTarget.style.borderColor = '#4b5563'
              e.currentTarget.style.color = '#a0aec0'
            }
          }}
        >
          {/* Colored dot indicator */}
          <span
            style={{
              display: 'inline-block',
              width: '8px',
              height: '8px',
              borderRadius: '50%',
              backgroundColor: option.color,
              opacity: value === option.value ? 1 : 0.5,
            }}
          />
          {option.label}
        </button>
      ))}
    </div>
  )
}
