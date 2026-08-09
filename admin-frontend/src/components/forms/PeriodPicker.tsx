/**
 * Period Picker Component (Segmented Control)
 *
 * Allows quick selection of common time periods for filtering data.
 * Uses segmented control UI pattern for compact, clean selection.
 *
 * Features:
 * - Predefined periods: 1M, 3M, 6M, 1Y, 2Y, All
 * - Calculates date ranges automatically (see utils/periods)
 * - Callback with dateFrom and dateTo ISO date strings
 *
 * The selected period is owned by the consumer (`value`), and the range is
 * announced from the click handler — never from an effect. An effect that
 * calls `onPeriodChange` on render re-fires whenever the parent re-renders
 * with a fresh handler identity, and since the handler resets the list to
 * page 1, paging was impossible on Journal and Settlements (#89). The initial
 * range is not announced at all: consumers seed it from `getPeriodRange()`.
 */

import { useTranslation } from 'react-i18next'
import { PERIOD_KEYS, getPeriodRange, DEFAULT_PERIOD, type PeriodKey } from '../../utils/periods'

export interface PeriodPickerProps {
  value?: PeriodKey
  onPeriodChange?: (dateFrom: string | undefined, dateTo: string, periodKey: PeriodKey) => void
  testId?: string
}

export function PeriodPicker({ value = DEFAULT_PERIOD, onPeriodChange, testId = 'period-picker' }: PeriodPickerProps) {
  const { t } = useTranslation()

  const handlePeriodClick = (key: PeriodKey) => {
    if (!onPeriodChange) return
    const { dateFrom, dateTo } = getPeriodRange(key)
    onPeriodChange(dateFrom, dateTo, key)
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
      {PERIOD_KEYS.map((key) => (
        <button
          key={key}
          onClick={() => handlePeriodClick(key)}
          data-testid={`${testId}-${key}`}
          style={{
            padding: '8px 14px',
            borderRadius: '8px',
            fontSize: '13px',
            fontWeight: 500,
            color: value === key ? 'white' : '#94a3b8',
            backgroundColor: value === key ? '#3b82f6' : 'transparent',
            border: 'none',
            cursor: 'pointer',
            transition: 'all 0.15s',
            whiteSpace: 'nowrap',
          }}
          onMouseEnter={(e) => {
            if (value !== key) {
              e.currentTarget.style.color = '#f1f5f9'
            }
          }}
          onMouseLeave={(e) => {
            if (value !== key) {
              e.currentTarget.style.color = '#94a3b8'
            }
          }}
        >
          {t(`periods.${key}`)}
        </button>
      ))}
    </div>
  )
}
