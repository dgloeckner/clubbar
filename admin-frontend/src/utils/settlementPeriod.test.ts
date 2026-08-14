import { describe, expect, it } from 'vitest'
import { formatTransactionPeriod } from './settlementPeriod'

const CREATED = '2026-08-12T09:30:00Z'

describe('formatTransactionPeriod', () => {
  it('drops a one-day period that repeats the settlement date', () => {
    expect(formatTransactionPeriod('2026-08-12', '2026-08-12', CREATED, 'de-DE')).toBeNull()
  })

  it('keeps a one-day period from another day', () => {
    expect(formatTransactionPeriod('2026-08-10', '2026-08-10', CREATED, 'de-DE')).toBe('10.08.2026')
  })

  it('collapses the shared parts of a range within one year', () => {
    expect(formatTransactionPeriod('2026-08-12', '2026-08-15', CREATED, 'de-DE')).toBe(
      '12.–15.08.2026',
    )
  })

  it('spells out both ends across a year boundary', () => {
    const range = formatTransactionPeriod('2025-12-15', '2026-08-12', CREATED, 'de-DE')
    expect(range).toContain('15.12.2025')
    expect(range).toContain('12.08.2026')
  })

  it('formats in the active locale', () => {
    expect(formatTransactionPeriod('2026-08-10', '2026-08-10', CREATED, 'en-GB')).toBe('10/08/2026')
  })

  it('returns null when either end is missing', () => {
    expect(formatTransactionPeriod(null, '2026-08-12', CREATED, 'de-DE')).toBeNull()
    expect(formatTransactionPeriod('2026-08-12', undefined, CREATED, 'de-DE')).toBeNull()
  })

  it('returns null for an unparseable date', () => {
    expect(formatTransactionPeriod('not-a-date', '2026-08-12', CREATED, 'de-DE')).toBeNull()
    expect(formatTransactionPeriod('2026-13-40', '2026-08-12', CREATED, 'de-DE')).toBeNull()
  })

  it('renders a reversed pair rather than dropping it', () => {
    expect(formatTransactionPeriod('2026-08-15', '2026-08-12', CREATED, 'de-DE')).toBe(
      '12.–15.08.2026',
    )
  })

  it('reads date-only values as local days, so the period survives a westward timezone', () => {
    // `new Date('2026-08-12')` is UTC midnight — west of Greenwich that renders
    // as the 11th, and the one-day period would no longer match the run's date.
    // `test:timezones` runs this suite in America/Los_Angeles for exactly this.
    expect(formatTransactionPeriod('2026-08-12', '2026-08-12', '2026-08-12', 'de-DE')).toBeNull()
    expect(formatTransactionPeriod('2026-08-12', '2026-08-12', null, 'de-DE')).toBe('12.08.2026')
  })
})
