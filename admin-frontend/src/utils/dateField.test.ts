import { describe, expect, it } from 'vitest'
import {
  buildDatePlaceholder,
  buildMonthGrid,
  calculateAge,
  clampIso,
  compareIso,
  daysInMonth,
  expandTwoDigitYear,
  formatIsoForDisplay,
  getDateFormat,
  getMonthLabels,
  getWeekStart,
  getWeekdayLabels,
  getYearPage,
  isIsoInRange,
  makeIso,
  maskDateInput,
  parseDateInput,
  shiftIsoByDays,
  shiftIsoByMonths,
  shiftIsoByYears,
  shiftMonth,
  splitIso,
} from './dateField'

const DE = getDateFormat('de')
const EN_US = getDateFormat('en-US')

describe('getDateFormat', () => {
  it('reads the German order and separator', () => {
    expect(DE).toEqual({ order: ['day', 'month', 'year'], separator: '.' })
  })

  it('reads the US order and separator', () => {
    expect(EN_US).toEqual({ order: ['month', 'day', 'year'], separator: '/' })
  })

  it('reads the British order, which differs from the US one', () => {
    expect(getDateFormat('en-GB')).toEqual({ order: ['day', 'month', 'year'], separator: '/' })
  })

  it('falls back to the German order for an unusable locale', () => {
    expect(getDateFormat('not a locale')).toEqual({ order: ['day', 'month', 'year'], separator: '.' })
  })
})

describe('buildDatePlaceholder', () => {
  it('uses the translated letters in the locale order', () => {
    const letters = { day: 'TT', month: 'MM', year: 'JJJJ' }
    expect(buildDatePlaceholder(DE, letters)).toBe('TT.MM.JJJJ')
    expect(buildDatePlaceholder(EN_US, { day: 'DD', month: 'MM', year: 'YYYY' })).toBe('MM/DD/YYYY')
  })
})

describe('formatIsoForDisplay', () => {
  it('renders an ISO date in the locale order, zero padded', () => {
    expect(formatIsoForDisplay('1979-11-23', DE)).toBe('23.11.1979')
    expect(formatIsoForDisplay('1979-01-02', DE)).toBe('02.01.1979')
    expect(formatIsoForDisplay('1979-11-23', EN_US)).toBe('11/23/1979')
  })

  it('renders nothing for an empty or malformed value', () => {
    expect(formatIsoForDisplay('', DE)).toBe('')
    expect(formatIsoForDisplay('23.11.1979', DE)).toBe('')
    expect(formatIsoForDisplay('1979-02-31', DE)).toBe('')
  })
})

describe('maskDateInput', () => {
  it('groups a bare run of digits as the locale writes them', () => {
    expect(maskDateInput('2', DE)).toBe('2')
    expect(maskDateInput('23', DE)).toBe('23')
    expect(maskDateInput('231', DE)).toBe('23.1')
    expect(maskDateInput('23111979', DE)).toBe('23.11.1979')
    expect(maskDateInput('11231979', EN_US)).toBe('11/23/1979')
  })

  it('drops digits past the last segment', () => {
    expect(maskDateInput('2311197988', DE)).toBe('23.11.1979')
  })

  it('keeps the groups a user separated themselves', () => {
    // A fixed-width mask would read this as 11.19.90.
    expect(maskDateInput('1.1.1990', DE)).toBe('1.1.1990')
    expect(maskDateInput('23/11/1979', DE)).toBe('23.11.1979')
  })

  it('carries a digit typed past a full segment into the next one', () => {
    // Typing straight on: `23.11` + `1`. Trimming instead of carrying dropped
    // the keystroke and the field could never be finished by typing.
    expect(maskDateInput('23.111', DE)).toBe('23.11.1')
    expect(maskDateInput('23.111979', DE)).toBe('23.11.1979')
    // Overflow cascades: the extra 1 pushes every later segment along.
    expect(maskDateInput('231.11.1979', DE)).toBe('23.11.1197')
  })

  it('keeps a separator that was just typed', () => {
    expect(maskDateInput('23.', DE)).toBe('23.')
    expect(maskDateInput('23.11.', DE)).toBe('23.11.')
  })

  it('does not re-add a separator once the last segment is open', () => {
    expect(maskDateInput('23.11.1', DE)).toBe('23.11.1')
  })

  it('passes an ISO string through untouched', () => {
    expect(maskDateInput('1979-11-23', DE)).toBe('1979-11-23')
    expect(maskDateInput('1979-1', DE)).toBe('1979-1')
    expect(maskDateInput('1979-11-2345', DE)).toBe('1979-11-23')
  })

  it('leaves an empty field empty', () => {
    expect(maskDateInput('', DE)).toBe('')
  })
})

describe('parseDateInput', () => {
  const today = new Date(2026, 7, 21)

  it('reads the locale order', () => {
    expect(parseDateInput('23.11.1979', DE, today)).toBe('1979-11-23')
    expect(parseDateInput('11/23/1979', EN_US, today)).toBe('1979-11-23')
  })

  it('accepts single-digit segments and any separator', () => {
    expect(parseDateInput('1.1.1990', DE, today)).toBe('1990-01-01')
    expect(parseDateInput('1/1/1990', DE, today)).toBe('1990-01-01')
    expect(parseDateInput('1 1 1990', DE, today)).toBe('1990-01-01')
  })

  it('accepts an unseparated run at the two unambiguous lengths', () => {
    expect(parseDateInput('23111979', DE, today)).toBe('1979-11-23')
    expect(parseDateInput('231179', DE, today)).toBe('1979-11-23')
    expect(parseDateInput('2311197', DE, today)).toBeNull()
  })

  it('reads an ISO string, whatever the locale', () => {
    expect(parseDateInput('1979-11-23', DE, today)).toBe('1979-11-23')
    expect(parseDateInput('1979-11-23', EN_US, today)).toBe('1979-11-23')
    expect(parseDateInput('1979-1-2', DE, today)).toBe('1979-01-02')
  })

  it('resolves a two-digit year into the past', () => {
    expect(parseDateInput('23.11.79', DE, today)).toBe('1979-11-23')
    expect(parseDateInput('23.11.20', DE, today)).toBe('2020-11-23')
    expect(parseDateInput('23.11.26', DE, today)).toBe('2026-11-23')
    expect(parseDateInput('23.11.27', DE, today)).toBe('1927-11-23')
  })

  it('rejects a date that does not exist rather than rolling it forward', () => {
    expect(parseDateInput('31.02.1979', DE, today)).toBeNull()
    expect(parseDateInput('29.02.1900', DE, today)).toBeNull()
    expect(parseDateInput('29.02.2000', DE, today)).toBe('2000-02-29')
    expect(parseDateInput('00.11.1979', DE, today)).toBeNull()
    expect(parseDateInput('23.13.1979', DE, today)).toBeNull()
  })

  it('rejects anything that is not a date yet', () => {
    expect(parseDateInput('', DE, today)).toBeNull()
    expect(parseDateInput('   ', DE, today)).toBeNull()
    expect(parseDateInput('23.11', DE, today)).toBeNull()
    expect(parseDateInput('23.11.1979.5', DE, today)).toBeNull()
    expect(parseDateInput('gestern', DE, today)).toBeNull()
    expect(parseDateInput('23.11.197', DE, today)).toBeNull()
  })

  it('ignores surrounding whitespace, as a paste carries it', () => {
    expect(parseDateInput('  23.11.1979 ', DE, today)).toBe('1979-11-23')
  })
})

describe('expandTwoDigitYear', () => {
  it('never resolves into the future', () => {
    expect(expandTwoDigitYear(79, 2026)).toBe(1979)
    expect(expandTwoDigitYear(26, 2026)).toBe(2026)
    expect(expandTwoDigitYear(27, 2026)).toBe(1927)
    expect(expandTwoDigitYear(0, 2026)).toBe(2000)
    expect(expandTwoDigitYear(99, 2099)).toBe(2099)
  })
})

describe('makeIso / splitIso', () => {
  it('zero-pads every segment', () => {
    expect(makeIso(1979, 1, 2)).toBe('1979-01-02')
  })

  it('rejects impossible calendar values', () => {
    expect(makeIso(1979, 13, 1)).toBeNull()
    expect(makeIso(1979, 0, 1)).toBeNull()
    expect(makeIso(1979, 2, 30)).toBeNull()
    expect(makeIso(1979, 11, 0)).toBeNull()
    expect(makeIso(0, 1, 1)).toBeNull()
    expect(makeIso(1979.5, 1, 1)).toBeNull()
  })

  it('round-trips through splitIso', () => {
    expect(splitIso('1979-11-23')).toEqual({ year: 1979, month: 11, day: 23 })
    expect(splitIso('1979-02-31')).toBeNull()
    expect(splitIso('23.11.1979')).toBeNull()
    expect(splitIso('')).toBeNull()
  })
})

describe('daysInMonth', () => {
  it.each([
    [2024, 1, 29],
    [2023, 1, 28],
    [1900, 1, 28],
    [2000, 1, 29],
    [2026, 0, 31],
    [2026, 3, 30],
  ])('%i-%i has %i days', (year, month, expected) => {
    expect(daysInMonth(year, month)).toBe(expected)
  })
})

describe('range helpers', () => {
  it('compares ISO dates chronologically', () => {
    expect(compareIso('1979-11-23', '1979-11-24')).toBe(-1)
    expect(compareIso('1979-11-24', '1979-11-23')).toBe(1)
    expect(compareIso('1979-11-23', '1979-11-23')).toBe(0)
  })

  it('tests membership of an open or closed range', () => {
    expect(isIsoInRange('1979-11-23', '1900-01-01', '2026-08-21')).toBe(true)
    expect(isIsoInRange('2026-08-22', undefined, '2026-08-21')).toBe(false)
    expect(isIsoInRange('1899-12-31', '1900-01-01')).toBe(false)
    expect(isIsoInRange('1899-12-31')).toBe(true)
  })

  it('clamps onto the nearest bound', () => {
    expect(clampIso('2030-01-01', '1900-01-01', '2026-08-21')).toBe('2026-08-21')
    expect(clampIso('1800-01-01', '1900-01-01', '2026-08-21')).toBe('1900-01-01')
    expect(clampIso('1979-11-23', '1900-01-01', '2026-08-21')).toBe('1979-11-23')
  })
})

describe('buildMonthGrid', () => {
  const options = { weekStart: 1, todayIso: '2026-08-21' }

  it('always returns six rows of seven days', () => {
    for (const month of [0, 1, 4, 11]) {
      const grid = buildMonthGrid(2026, month, options)
      expect(grid).toHaveLength(6)
      expect(grid.every((week) => week.length === 7)).toBe(true)
    }
  })

  it('starts the first row on the locale first day', () => {
    // 1 April 2026 is a Wednesday, so a Monday-first grid opens on 30 March.
    expect(buildMonthGrid(2026, 3, options)[0][0].iso).toBe('2026-03-30')
    expect(buildMonthGrid(2026, 3, { ...options, weekStart: 0 })[0][0].iso).toBe('2026-03-29')
  })

  it('marks the days that belong to the month', () => {
    const grid = buildMonthGrid(2026, 3, options)
    const days = grid.flat()
    expect(days.filter((d) => d.inMonth)).toHaveLength(30)
    expect(days.find((d) => d.iso === '2026-03-30')?.inMonth).toBe(false)
    expect(days.find((d) => d.iso === '2026-04-01')?.inMonth).toBe(true)
  })

  it('marks today', () => {
    const days = buildMonthGrid(2026, 7, options).flat()
    expect(days.filter((d) => d.isToday).map((d) => d.iso)).toEqual(['2026-08-21'])
  })

  it('disables the days outside the allowed range', () => {
    const days = buildMonthGrid(2026, 7, { ...options, max: '2026-08-21' }).flat()
    expect(days.find((d) => d.iso === '2026-08-21')?.disabled).toBe(false)
    expect(days.find((d) => d.iso === '2026-08-22')?.disabled).toBe(true)
  })

  it('spans a year boundary without losing days', () => {
    const days = buildMonthGrid(2025, 11, options).flat()
    expect(days.find((d) => d.iso === '2026-01-01')?.inMonth).toBe(false)
    expect(days.filter((d) => d.inMonth)).toHaveLength(31)
  })
})

describe('shifting', () => {
  it('carries months across years in both directions', () => {
    expect(shiftMonth(2026, 11, 1)).toEqual({ year: 2027, month: 0 })
    expect(shiftMonth(2026, 0, -1)).toEqual({ year: 2025, month: 11 })
    expect(shiftMonth(2026, 5, 12)).toEqual({ year: 2027, month: 5 })
    expect(shiftMonth(2026, 5, -18)).toEqual({ year: 2024, month: 11 })
  })

  it('clamps the day instead of rolling a month over', () => {
    expect(shiftIsoByMonths('2026-01-31', 1)).toBe('2026-02-28')
    expect(shiftIsoByMonths('2024-01-31', 1)).toBe('2024-02-29')
    expect(shiftIsoByMonths('2026-03-31', -1)).toBe('2026-02-28')
    expect(shiftIsoByMonths('2026-08-21', 0)).toBe('2026-08-21')
  })

  it('moves whole days across month and year boundaries', () => {
    expect(shiftIsoByDays('2026-08-21', 1)).toBe('2026-08-22')
    expect(shiftIsoByDays('2026-08-31', 1)).toBe('2026-09-01')
    expect(shiftIsoByDays('2026-01-01', -1)).toBe('2025-12-31')
    expect(shiftIsoByDays('2026-08-21', -7)).toBe('2026-08-14')
  })

  it('moves whole years, clamping a leap day', () => {
    expect(shiftIsoByYears('2026-08-21', -30)).toBe('1996-08-21')
    expect(shiftIsoByYears('2024-02-29', 1)).toBe('2025-02-28')
  })

  it('leaves an unparseable value alone', () => {
    expect(shiftIsoByDays('', 1)).toBe('')
    expect(shiftIsoByMonths('nonsense', 1)).toBe('nonsense')
  })
})

describe('getYearPage', () => {
  it('aligns pages so paging is stable', () => {
    const page = getYearPage(1979)
    expect(page.start).toBe(1960)
    expect(page.end).toBe(1979)
    expect(page.years).toHaveLength(20)
    expect(getYearPage(1960).start).toBe(1960)
    expect(getYearPage(1959).start).toBe(1940)
  })

  it('honours a custom page size', () => {
    expect(getYearPage(2026, 12)).toEqual({
      start: 2016,
      end: 2027,
      years: Array.from({ length: 12 }, (_, i) => 2016 + i),
    })
  })
})

describe('locale calendar labels', () => {
  it('starts the German week on Monday and the US week on Sunday', () => {
    expect(getWeekStart('de')).toBe(1)
    expect(getWeekStart('en-US')).toBe(0)
    expect(getWeekStart('en-GB')).toBe(1)
    expect(getWeekStart('not a locale')).toBe(1)
  })

  it('lists weekdays from the first day of the week', () => {
    const monday = getWeekdayLabels('de', 1)
    expect(monday).toHaveLength(7)
    expect(monday[0]).toMatch(/^Mo/)
    expect(monday[6]).toMatch(/^So/)
    expect(getWeekdayLabels('de', 0)[0]).toMatch(/^So/)
  })

  it('lists the twelve months in calendar order', () => {
    const months = getMonthLabels('de')
    expect(months).toHaveLength(12)
    expect(months[0]).toBe('Januar')
    expect(months[11]).toBe('Dezember')
    expect(getMonthLabels('de', 'short')[3]).toMatch(/^Apr/)
  })
})

describe('calculateAge', () => {
  const today = new Date(2026, 7, 21)

  it('counts completed years', () => {
    expect(calculateAge('1979-11-23', today)).toBe(46)
    expect(calculateAge('1979-08-21', today)).toBe(47)
    expect(calculateAge('1979-08-22', today)).toBe(46)
    expect(calculateAge('2026-08-21', today)).toBe(0)
  })

  it('has no answer for a future or unusable date', () => {
    expect(calculateAge('2026-08-22', today)).toBeNull()
    expect(calculateAge('2030-01-01', today)).toBeNull()
    expect(calculateAge('', today)).toBeNull()
    expect(calculateAge('nonsense', today)).toBeNull()
  })
})
