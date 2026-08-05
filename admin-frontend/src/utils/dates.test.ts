import { describe, expect, it } from 'vitest'
import { toIsoDate } from './dates'

describe('toIsoDate', () => {
  it('formats a date as YYYY-MM-DD', () => {
    expect(toIsoDate(new Date(2026, 7, 5))).toBe('2026-08-05')
  })

  it('zero-pads single-digit months and days', () => {
    expect(toIsoDate(new Date(2026, 0, 1))).toBe('2026-01-01')
  })

  it('uses local calendar components, not UTC', () => {
    // 23:30 local on 2026-08-05. toISOString() would roll this to 2026-08-06
    // for any timezone west of Greenwich; the local calendar day is the 5th.
    expect(toIsoDate(new Date(2026, 7, 5, 23, 30))).toBe('2026-08-05')

    // Same trap in the other direction: 00:30 local would roll back a day for
    // timezones east of Greenwich.
    expect(toIsoDate(new Date(2026, 7, 5, 0, 30))).toBe('2026-08-05')
  })

  it('handles the year boundary', () => {
    expect(toIsoDate(new Date(2026, 11, 31, 22, 0))).toBe('2026-12-31')
  })
})
