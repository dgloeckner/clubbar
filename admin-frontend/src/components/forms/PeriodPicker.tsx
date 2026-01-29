/**
 * Period Picker Component (Segmented Control)
 * 
 * Allows quick selection of common time periods for filtering data.
 * Uses segmented control UI pattern for compact, clean selection.
 * 
 * Features:
 * - Predefined periods: 1M, 3M, 6M, 1Y, 2Y, All
 * - Calculates date ranges automatically
 * - Callback with dateFrom and dateTo ISO date strings
 */

import { useEffect, useState } from 'react'

export interface PeriodOption {
  key: string
  label: string
  days: number | null // null = all dates
}

export interface PeriodPickerProps {
  value?: string // 'all' | '1m' | '3m' | '6m' | '1y' | '2y'
  onPeriodChange?: (dateFrom: string | undefined, dateTo: string | undefined, periodKey: string) => void
  testId?: string
}

const PERIODS: PeriodOption[] = [
  { key: '1m', label: '1M', days: 30 },
  { key: '3m', label: '3M', days: 90 },
  { key: '6m', label: '6M', days: 180 },
  { key: '1y', label: '1J', days: 365 },
  { key: '2y', label: '2J', days: 730 },
  { key: 'all', label: 'Alle', days: null },
]

/**
 * Calculate date range for a period
 * @param days Number of days back (null = all dates)
 * @returns { dateFrom, dateTo } ISO date strings
 */
function getPeriodDates(days: number | null): { dateFrom: string | undefined; dateTo: string } {
  const today = new Date()
  const dateTo = today.toISOString().split('T')[0] // Today in YYYY-MM-DD
  
  if (days === null) {
    return { dateFrom: undefined, dateTo }
  }
  
  const fromDate = new Date(today)
  fromDate.setDate(fromDate.getDate() - days)
  const dateFrom = fromDate.toISOString().split('T')[0]
  
  return { dateFrom, dateTo }
}

export function PeriodPicker({ value = '3m', onPeriodChange, testId = 'period-picker' }: PeriodPickerProps) {
  const [activePeriod, setActivePeriod] = useState(value)

  // Call onPeriodChange when period changes
  useEffect(() => {
    const period = PERIODS.find(p => p.key === activePeriod)
    if (period && onPeriodChange) {
      const { dateFrom, dateTo } = getPeriodDates(period.days)
      onPeriodChange(dateFrom, dateTo, period.key)
    }
  }, [activePeriod, onPeriodChange])

  const handlePeriodClick = (key: string) => {
    setActivePeriod(key)
  }

  return (
    <div
      data-testid={testId}
      style={{
        display: 'inline-flex',
        backgroundColor: '#0d1829',
        borderRadius: '10px',
        padding: '4px',
        gap: '2px',
      }}
    >
      {PERIODS.map((period) => (
        <button
          key={period.key}
          onClick={() => handlePeriodClick(period.key)}
          data-testid={`${testId}-${period.key}`}
          style={{
            padding: '8px 14px',
            borderRadius: '8px',
            fontSize: '13px',
            fontWeight: 500,
            color: activePeriod === period.key ? 'white' : '#94a3b8',
            backgroundColor: activePeriod === period.key ? '#3b82f6' : 'transparent',
            border: 'none',
            cursor: 'pointer',
            transition: 'all 0.15s',
            whiteSpace: 'nowrap',
          }}
          onMouseEnter={(e) => {
            if (activePeriod !== period.key) {
              e.currentTarget.style.color = '#f1f5f9'
            }
          }}
          onMouseLeave={(e) => {
            if (activePeriod !== period.key) {
              e.currentTarget.style.color = '#94a3b8'
            }
          }}
        >
          {period.label}
        </button>
      ))}
    </div>
  )
}
