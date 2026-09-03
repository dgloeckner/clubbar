import { afterEach, describe, expect, it } from 'vitest'
import { setClubTimeZone, resetClubTimeZone } from './clubTimeZone'
import { isDateOnly, parseApiDate, toClubIsoDate, toIsoDate } from './dates'

describe('the timezone these tests run in', () => {
  it('is not UTC — on UTC none of the assertions below can fail', () => {
    // UTC is the single zone where local and UTC calendar days always agree,
    // so a suite pinned to it certifies date handling it never exercised.
    // vite.config.ts pins a non-UTC default; this guards that pin (#95).
    expect(new Date().getTimezoneOffset()).not.toBe(0)
  })
})

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

  it('names every hour of a day as that same day', () => {
    // The filter ranges on Journal, Settlements and Reports are built from
    // this. An admin loading a page at any hour must get today's date, not
    // whatever day it happens to be in Greenwich (#95).
    for (let hour = 0; hour < 24; hour++) {
      expect(toIsoDate(new Date(2026, 7, 5, hour, 30))).toBe('2026-08-05')
    }
  })
})

describe('parseApiDate', () => {
  it('reads a date-only string as the local calendar day', () => {
    // `new Date('2026-01-23')` is UTC midnight, which Intl then renders as the
    // 22nd for anyone west of Greenwich.
    expect(toIsoDate(parseApiDate('2026-01-23'))).toBe('2026-01-23')
  })

  it('anchors a date-only string at local midnight', () => {
    const date = parseApiDate('2026-01-23')
    expect(date.getHours()).toBe(0)
    expect(date.getMinutes()).toBe(0)
    expect(date.getSeconds()).toBe(0)
    expect(date.getMilliseconds()).toBe(0)
  })

  it('survives truncation to midnight, which is what relative labels do', () => {
    // `formatRelativeDate` calls setHours(0, 0, 0, 0) before comparing. On a
    // UTC-parsed value that step moves the date to the previous calendar day.
    const date = parseApiDate('2026-01-23')
    date.setHours(0, 0, 0, 0)
    expect(toIsoDate(date)).toBe('2026-01-23')
  })

  it('round-trips every day of a month', () => {
    for (let day = 1; day <= 31; day++) {
      const iso = `2026-01-${String(day).padStart(2, '0')}`
      expect(toIsoDate(parseApiDate(iso))).toBe(iso)
    }
  })

  it('leaves a timestamp to the platform parser — it is an instant, not a day', () => {
    expect(parseApiDate('2026-08-05T22:15:00Z').toISOString()).toBe(
      '2026-08-05T22:15:00.000Z'
    )
  })

  it('reads a zoneless timestamp as UTC, not as local time', () => {
    // What MariaDB stores and what an endpoint that forgot
    // `DateFormatter::toUtcIso()` emits. `new Date()` reads it as *local*,
    // which is how the notifications queue showed 19:33 for a mail queued at
    // 21:33 CEST — the string parses, so nothing looks wrong (#365).
    expect(parseApiDate('2026-09-01 19:33:12').toISOString()).toBe(
      '2026-09-01T19:33:12.000Z'
    )
  })

  it('reads a zoneless ISO timestamp as UTC too', () => {
    // The dashboard's recent transactions used to be built by replacing the
    // space with a "T" and nothing else, which is valid ISO 8601 *without* a
    // zone — and therefore local time to every parser.
    expect(parseApiDate('2026-09-01T19:33:12').toISOString()).toBe(
      '2026-09-01T19:33:12.000Z'
    )
  })

  it('reads a zoneless timestamp with no seconds, and with fractions, as UTC', () => {
    expect(parseApiDate('2026-09-01 19:33').toISOString()).toBe(
      '2026-09-01T19:33:00.000Z'
    )
    expect(parseApiDate('2026-09-01T19:33:12.500').toISOString()).toBe(
      '2026-09-01T19:33:12.500Z'
    )
  })

  it('leaves a labelled timestamp alone — the label wins over the default', () => {
    // Both directions: a "Z" and an explicit offset must not be re-read as UTC
    // a second time.
    expect(parseApiDate('2026-09-01T19:33:12Z').toISOString()).toBe(
      '2026-09-01T19:33:12.000Z'
    )
    expect(parseApiDate('2026-09-01T21:33:12+02:00').toISOString()).toBe(
      '2026-09-01T19:33:12.000Z'
    )
  })

  it('does not roll an out-of-range date forward', () => {
    expect(parseApiDate('2026-02-30').getTime()).toBeNaN()
    expect(parseApiDate('2026-13-01').getTime()).toBeNaN()
    expect(parseApiDate('2026-00-10').getTime()).toBeNaN()
    expect(parseApiDate('2026-01-32').getTime()).toBeNaN()
  })

  it('returns an invalid date for unparseable input', () => {
    expect(parseApiDate('not a date').getTime()).toBeNaN()
    expect(parseApiDate('').getTime()).toBeNaN()
  })

  it('keeps two-digit years out of the 1900s', () => {
    expect(parseApiDate('0050-01-01').getFullYear()).toBe(50)
  })

  it('accepts a leap day', () => {
    expect(toIsoDate(parseApiDate('2028-02-29'))).toBe('2028-02-29')
  })

  it('rejects a leap day in a common year', () => {
    expect(parseApiDate('2027-02-29').getTime()).toBeNaN()
  })
})

describe('toClubIsoDate', () => {
  afterEach(() => resetClubTimeZone())

  /**
   * A filter names a day of the club's books. Built from the reader's calendar
   * instead, a Kassenwart in Tokyo asking for "today" asked for a day the club
   * had not reached yet for the nine hours after their midnight.
   */
  it('is the club’s calendar day, not the reader’s', () => {
    setClubTimeZone('Europe/Berlin')

    // 22:30Z is already the 3rd in Berlin.
    expect(toClubIsoDate(new Date('2026-09-02T22:30:00Z'))).toBe('2026-09-03')
    // …and still the 2nd an hour earlier.
    expect(toClubIsoDate(new Date('2026-09-02T21:30:00Z'))).toBe('2026-09-02')
  })

  it('honours a club west of Greenwich', () => {
    setClubTimeZone('America/New_York')

    expect(toClubIsoDate(new Date('2026-09-02T02:30:00Z'))).toBe('2026-09-01')
  })

  it('falls back to the local calendar day before the config has been read', () => {
    const midday = new Date('2026-09-02T12:00:00Z')

    expect(toClubIsoDate(midday)).toBe(toIsoDate(midday))
  })
})

describe('isDateOnly', () => {
  it('tells a calendar day from an instant, which is what decides zone shifting', () => {
    expect(isDateOnly('2026-08-05')).toBe(true)
    expect(isDateOnly('2026-08-05T22:15:00Z')).toBe(false)
    expect(isDateOnly('2026-08-05 22:15:00')).toBe(false)
  })
})
