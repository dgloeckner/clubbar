/**
 * Settlement Status Filter
 * Pill-based filter for settlement status (All | Open | Settled)
 *
 * Used by: JournalPage for filtering transactions by settlement state
 * Implements: UC-A22-B Filter Transactions by Settlement Status
 */

import { useTranslation } from 'react-i18next'

interface SettlementStatusFilterProps {
  value: 'all' | 'open' | 'settled'
  onChange: (value: 'all' | 'open' | 'settled') => void
  testId?: string
}

export function SettlementStatusFilter({
  value,
  onChange,
  testId = 'settlement-status-filter',
}: SettlementStatusFilterProps) {
  const { t } = useTranslation()

  const options: Array<{
    value: 'all' | 'open' | 'settled'
    label: string
    color: string
  }> = [
    { value: 'all', label: t('common.all'), color: '#6b7280' },
    { value: 'open', label: t('journal.open'), color: '#10b981' },
    { value: 'settled', label: t('journal.settled'), color: '#8b5cf6' },
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
