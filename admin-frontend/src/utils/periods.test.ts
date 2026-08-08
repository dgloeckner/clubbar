import { describe, expect, it } from 'vitest'
import { DEFAULT_PERIOD, PERIOD_KEYS, getPeriodRange, type PeriodKey } from './periods'

const reference = new Date(2026, 7, 8) // 2026-08-08, local

describe('getPeriodRange', () => {
  it('ends the range on the reference day', () => {
    for (const period of PERIOD_KEYS) {
      expect(getPeriodRange(period, reference).dateTo).toBe('2026-08-08')
    }
  })

  it('counts the preset back from the reference day', () => {
    expect(getPeriodRange('1m', reference).dateFrom).toBe('2026-07-09') // -30d
    expect(getPeriodRange('3m', reference).dateFrom).toBe('2026-05-10') // -90d
    expect(getPeriodRange('6m', reference).dateFrom).toBe('2026-02-09') // -180d
    expect(getPeriodRange('1y', reference).dateFrom).toBe('2025-08-08') // -365d
    expect(getPeriodRange('2y', reference).dateFrom).toBe('2024-08-08') // -730d
  })

  it('leaves the lower bound open for "all"', () => {
    expect(getPeriodRange('all', reference).dateFrom).toBeUndefined()
  })

  it('does not mutate the reference date', () => {
    const today = new Date(2026, 7, 8)
    getPeriodRange('1y', today)
    expect(toLocalParts(today)).toEqual([2026, 7, 8])
  })

  it('uses local calendar days, not UTC ones', () => {
    // 23:30 local: converting to UTC first would roll `dateTo` to the 9th for
    // any timezone west of Greenwich, and the filter would ask for a day the
    // admin never selected.
    expect(getPeriodRange('1m', new Date(2026, 7, 8, 23, 30)).dateTo).toBe('2026-08-08')
    expect(getPeriodRange('1m', new Date(2026, 7, 8, 0, 30)).dateTo).toBe('2026-08-08')
  })

  it('defaults the reference day to today', () => {
    const { dateTo } = getPeriodRange('3m')
    const now = new Date()
    expect(dateTo).toBe(
      `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
    )
  })
})

describe('period presets', () => {
  it('offers every preset the pages filter by, in display order', () => {
    expect(PERIOD_KEYS).toEqual<PeriodKey[]>(['1m', '3m', '6m', '1y', '2y', 'all'])
  })

  it('defaults to a preset it actually offers', () => {
    expect(PERIOD_KEYS).toContain(DEFAULT_PERIOD)
  })
})

function toLocalParts(date: Date): [number, number, number] {
  return [date.getFullYear(), date.getMonth(), date.getDate()]
}
