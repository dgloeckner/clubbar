/**
 * Locale-aware date entry and calendar math for `DateField`.
 *
 * The admin panel used `<input type="date">` everywhere, which is three
 * different controls depending on the browser and a bad one for a birth date
 * on all of them: Chrome opens on *today's* month, so reaching 1979 is either
 * a hundred clicks on a chevron or a discovery that the year segment can be
 * typed over; Firefox and WebKit disagree about whether there is a picker at
 * all; and on a phone the wheel starts at the current year. ADR-0045 made
 * `date_of_birth` mandatory on every member, so that control is now on the
 * critical path of creating one.
 *
 * This module is the pure half of the replacement — parsing, masking and
 * calendar arithmetic — kept out of the component so it can be unit-tested
 * (the component itself is covered by Playwright; see vite.config.ts).
 *
 * Everything here speaks ISO `YYYY-MM-DD` on the outside. That is what the API
 * takes and what `toIsoDate`/`parseApiDate` produce, and it is deliberately
 * *not* what the user sees: display order and separator come from the locale.
 * Dates are handled as local calendar days throughout, never as instants —
 * the trap `parseApiDate` documents.
 */

export type DateSegment = 'day' | 'month' | 'year'

export interface DateFormatSpec {
  /** Segment order as the locale writes it, e.g. `['day','month','year']` for de. */
  order: DateSegment[]
  /** The character between segments, e.g. `.` for de, `/` for en-US. */
  separator: string
}

const SEGMENT_WIDTH: Record<DateSegment, number> = { day: 2, month: 2, year: 4 }

/** A date whose parts are all distinguishable, used to read a locale's order. */
const PROBE = new Date(2001, 11, 31)

const FALLBACK_SPEC: DateFormatSpec = { order: ['day', 'month', 'year'], separator: '.' }

/**
 * The order and separator a locale writes a numeric date in, read from Intl
 * rather than from a table of our own.
 *
 * `formatToParts` is the only honest source for this: `de` is `31.12.2001`,
 * `en-GB` is `31/12/2001` and `en-US` is `12/31/2001`, and a hardcoded map
 * would be wrong the first time somebody adds a locale. An environment with a
 * trimmed ICU can return parts we cannot interpret, so an incomplete read
 * falls back to the German order rather than throwing inside a render.
 */
export function getDateFormat(locale: string): DateFormatSpec {
  let parts: Intl.DateTimeFormatPart[]
  try {
    parts = new Intl.DateTimeFormat(locale, {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(PROBE)
  } catch {
    return FALLBACK_SPEC
  }

  const order = parts
    .filter((p) => p.type === 'day' || p.type === 'month' || p.type === 'year')
    .map((p) => p.type as DateSegment)

  if (order.length !== 3) return FALLBACK_SPEC

  const literal = parts.find((p) => p.type === 'literal' && /[^\s‏‎]/.test(p.value))
  // Trimmed because some locales pad the separator ("31. 12. 2001").
  const separator = literal ? literal.value.trim().slice(0, 1) : FALLBACK_SPEC.separator

  return { order, separator: separator || FALLBACK_SPEC.separator }
}

/**
 * The format hint shown next to the field, built from the locale's order and
 * translated segment letters (TT.MM.JJJJ in German, DD/MM/YYYY in English).
 *
 * The letters cannot come from Intl — they are words, not data — so the
 * component passes them in from the translation catalogue.
 */
export function buildDatePlaceholder(spec: DateFormatSpec, letters: Record<DateSegment, string>): string {
  return spec.order.map((segment) => letters[segment]).join(spec.separator)
}

/** Formats an ISO date for display, or `''` for an empty/unparseable value. */
export function formatIsoForDisplay(iso: string, spec: DateFormatSpec): string {
  const parts = splitIso(iso)
  if (!parts) return ''
  const rendered: Record<DateSegment, string> = {
    day: String(parts.day).padStart(2, '0'),
    month: String(parts.month).padStart(2, '0'),
    year: String(parts.year).padStart(4, '0'),
  }
  return spec.order.map((segment) => rendered[segment]).join(spec.separator)
}

/**
 * Normalises what the user has typed so far, without committing to a value.
 *
 * Two behaviours, chosen by whether the text contains a separator at all:
 *
 * - **Digits only** — grouped by the locale's segment widths, so typing
 *   `23111979` yields `23.11.1979` and the separators appear as you go. This
 *   is the fast path for someone entering a birth date from a form.
 * - **Separators typed** — the groups are taken as typed and only capped and
 *   re-joined, so `1.1.1990` stays three groups and becomes `01.01.1990` on
 *   blur rather than the `11.19.90` a fixed-width mask would produce.
 *
 * An ISO string is passed through untouched. Nobody types one, but Playwright
 * fills one, the API returns one, and reformatting it mid-keystroke would
 * corrupt it.
 */
export function maskDateInput(raw: string, spec: DateFormatSpec): string {
  if (isIsoLike(raw)) return raw.slice(0, 10)

  const groups = raw.split(/\D+/).filter((g, i, all) => g !== '' || i < all.length - 1)
  const typedSeparator = /\D/.test(raw)

  if (!typedSeparator) {
    const digits = raw.replace(/\D/g, '')
    return groupByWidth(digits, spec)
  }

  const capped = spillOverfullGroups(groups, spec)

  const joined = capped.join(spec.separator)
  // A trailing separator the user just typed is kept, so the caret does not
  // appear to swallow it and the next digit starts a fresh segment.
  const endsWithSeparator = /\D$/.test(raw) && capped.length < spec.order.length
  return endsWithSeparator ? `${joined}${spec.separator}` : joined
}

/**
 * Caps each group at its segment's width and **carries the overflow into the
 * next segment**, so typing straight on past a full segment advances instead
 * of silently dropping the keystroke.
 *
 * Without the carry, `23.11` + `1` is `23.111`, whose middle group is trimmed
 * back to `11` — the digit disappears and the field can never be finished by
 * typing (#date-picker, caught by the E2E suite).
 */
function spillOverfullGroups(groups: string[], spec: DateFormatSpec): string[] {
  const out: string[] = []
  let carry = ''

  for (const [index, segment] of spec.order.entries()) {
    const value = carry + (groups[index] ?? '')
    carry = ''
    if (value === '' && index >= groups.length) break

    const width = SEGMENT_WIDTH[segment]
    if (value.length > width) {
      carry = value.slice(width)
      out.push(value.slice(0, width))
    } else {
      out.push(value)
    }
  }

  return out
}

function groupByWidth(digits: string, spec: DateFormatSpec): string {
  const out: string[] = []
  let rest = digits
  for (const segment of spec.order) {
    if (rest === '') break
    const width = SEGMENT_WIDTH[segment]
    out.push(rest.slice(0, width))
    rest = rest.slice(width)
  }
  return out.join(spec.separator)
}

function isIsoLike(raw: string): boolean {
  return /^\d{4}-\d*(-\d*)?$/.test(raw)
}

/**
 * Reads typed text into an ISO date, or `null` when it is not a date yet.
 *
 * Deliberately lenient about *shape* and strict about *value*: single-digit
 * segments, any separator, and a bare 6/8-digit run are all accepted, while
 * 31 February is rejected rather than rolled forward into March the way the
 * `Date` constructor would.
 */
export function parseDateInput(text: string, spec: DateFormatSpec, today: Date = new Date()): string | null {
  const trimmed = text.trim()
  if (trimmed === '') return null

  const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(trimmed)
  if (iso) return makeIso(Number(iso[1]), Number(iso[2]), Number(iso[3]))

  const groups = trimmed.split(/\D+/).filter((g) => g !== '')
  let segments: string[]

  if (groups.length === 1) {
    const digits = groups[0]
    // A bare run of digits is only a date at the two unambiguous lengths:
    // every segment two wide, or every segment its full width.
    if (digits.length !== 6 && digits.length !== 8) return null
    const yearWidth = digits.length === 8 ? 4 : 2
    segments = []
    let rest = digits
    for (const segment of spec.order) {
      const width = segment === 'year' ? yearWidth : 2
      segments.push(rest.slice(0, width))
      rest = rest.slice(width)
    }
  } else if (groups.length === 3) {
    segments = groups
  } else {
    return null
  }

  const values: Partial<Record<DateSegment, number>> = {}
  for (const [index, segment] of spec.order.entries()) {
    const raw = segments[index]
    if (!/^\d{1,4}$/.test(raw)) return null
    if (segment === 'year' && raw.length === 3) return null
    values[segment] =
      segment === 'year' && raw.length <= 2 ? expandTwoDigitYear(Number(raw), today.getFullYear()) : Number(raw)
  }

  return makeIso(values.year!, values.month!, values.day!)
}

/**
 * Maps a two-digit year onto the most recent matching year, so `79` is 1979
 * and not 2079.
 *
 * Every date field in the panel records something that has already happened —
 * a birth date, a mandate signature, a report range — so leaning into the past
 * is right for all of them. Four digits are what the placeholder asks for and
 * are never ambiguous; this only rescues a shorthand somebody typed anyway.
 */
export function expandTwoDigitYear(twoDigit: number, currentYear: number): number {
  const century = Math.floor(currentYear / 100) * 100
  const candidate = century + twoDigit
  return candidate > currentYear ? candidate - 100 : candidate
}

/** Builds `YYYY-MM-DD` from calendar numbers, rejecting a date that does not exist. */
export function makeIso(year: number, month: number, day: number): string | null {
  if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return null
  if (year < 1 || year > 9999 || month < 1 || month > 12 || day < 1 || day > 31) return null
  if (day > daysInMonth(year, month - 1)) return null
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

export function splitIso(iso: string): { year: number; month: number; day: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '')
  if (!match) return null
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  if (month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month - 1)) return null
  return { year, month, day }
}

/** Days in a zero-based month, leap years included. */
export function daysInMonth(year: number, month: number): number {
  // Day 0 of the next month is the last day of this one.
  const probe = new Date(2000, 0, 1)
  probe.setFullYear(year, month + 1, 0)
  return probe.getDate()
}

/** Lexicographic comparison, which for zero-padded ISO dates is chronological. */
export function compareIso(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0
}

export function isIsoInRange(iso: string, min?: string, max?: string): boolean {
  if (min && compareIso(iso, min) < 0) return false
  if (max && compareIso(iso, max) > 0) return false
  return true
}

export function clampIso(iso: string, min?: string, max?: string): string {
  if (min && compareIso(iso, min) < 0) return min
  if (max && compareIso(iso, max) > 0) return max
  return iso
}

export interface CalendarDay {
  iso: string
  day: number
  /** False for the leading/trailing days that fill the first and last week. */
  inMonth: boolean
  isToday: boolean
  /** Outside `min`/`max` — rendered, but not selectable. */
  disabled: boolean
}

export interface MonthGridOptions {
  /** 0 = Sunday, 1 = Monday. */
  weekStart: number
  min?: string
  max?: string
  todayIso: string
}

/**
 * A six-row calendar page for one month.
 *
 * Always six rows, never five: a grid that changes height as you page through
 * months makes the buttons under it jump, and on a phone that is a mis-tap.
 */
export function buildMonthGrid(year: number, month: number, options: MonthGridOptions): CalendarDay[][] {
  const first = new Date(2000, 0, 1)
  first.setFullYear(year, month, 1)
  first.setHours(0, 0, 0, 0)

  const offset = (first.getDay() - options.weekStart + 7) % 7
  const start = new Date(first)
  start.setDate(first.getDate() - offset)

  const weeks: CalendarDay[][] = []
  const cursor = new Date(start)

  for (let week = 0; week < 6; week++) {
    const days: CalendarDay[] = []
    for (let weekday = 0; weekday < 7; weekday++) {
      const iso = `${String(cursor.getFullYear()).padStart(4, '0')}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`
      days.push({
        iso,
        day: cursor.getDate(),
        inMonth: cursor.getMonth() === month && cursor.getFullYear() === year,
        isToday: iso === options.todayIso,
        disabled: !isIsoInRange(iso, options.min, options.max),
      })
      cursor.setDate(cursor.getDate() + 1)
    }
    weeks.push(days)
  }

  return weeks
}

/** Moves a year/month pair by whole months, carrying across year boundaries. */
export function shiftMonth(year: number, month: number, delta: number): { year: number; month: number } {
  const total = year * 12 + month + delta
  return { year: Math.floor(total / 12), month: ((total % 12) + 12) % 12 }
}

/**
 * Moves an ISO date by whole months, clamping the day rather than rolling it
 * over — 31 January plus a month is 28/29 February, not 2/3 March, which is
 * what keyboard paging through months has to do to stay predictable.
 */
export function shiftIsoByMonths(iso: string, delta: number): string {
  const parts = splitIso(iso)
  if (!parts) return iso
  const { year, month } = shiftMonth(parts.year, parts.month - 1, delta)
  const day = Math.min(parts.day, daysInMonth(year, month))
  return makeIso(year, month + 1, day)!
}

/** Moves an ISO date by whole days. */
export function shiftIsoByDays(iso: string, delta: number): string {
  const parts = splitIso(iso)
  if (!parts) return iso
  const date = new Date(2000, 0, 1)
  date.setFullYear(parts.year, parts.month - 1, parts.day + delta)
  return makeIso(date.getFullYear(), date.getMonth() + 1, date.getDate())!
}

/** Moves an ISO date by whole years, clamping 29 February onto the 28th. */
export function shiftIsoByYears(iso: string, delta: number): string {
  return shiftIsoByMonths(iso, delta * 12)
}

export const YEAR_PAGE_SIZE = 20

export interface YearPage {
  start: number
  end: number
  years: number[]
}

/**
 * The block of years the year view shows, aligned so that paging is stable:
 * the page containing 1979 is always 1960–1979, whichever year you arrived
 * from, so the grid does not reshuffle under the pointer.
 */
export function getYearPage(year: number, size: number = YEAR_PAGE_SIZE): YearPage {
  const start = Math.floor(year / size) * size
  const years = Array.from({ length: size }, (_, i) => start + i)
  return { start, end: start + size - 1, years }
}

/**
 * Whether a locale's week starts on Sunday (0) or Monday (1).
 *
 * `Intl.Locale.getWeekInfo` knows this for every locale and is used where the
 * runtime has it; the fallback covers only the regions whose admins we
 * actually have, and defaults to Monday, which is both the German convention
 * and the ISO-8601 one.
 */
export function getWeekStart(locale: string): number {
  type WeekInfoCapable = Intl.Locale & {
    getWeekInfo?: () => { firstDay: number }
    weekInfo?: { firstDay: number }
  }
  try {
    const resolved = new Intl.Locale(locale) as WeekInfoCapable
    const info = resolved.getWeekInfo?.() ?? resolved.weekInfo
    // Intl reports Monday as 1 and Sunday as 7; JS `getDay()` calls Sunday 0.
    if (info && typeof info.firstDay === 'number') return info.firstDay % 7
  } catch {
    /* falls through to the region table */
  }

  const region = locale.split(/[-_]/)[1]?.toUpperCase()
  const sundayFirst = ['US', 'CA', 'JP', 'IL', 'KR', 'TW', 'HK', 'MX', 'BR', 'PH', 'IN', 'ZA']
  return region && sundayFirst.includes(region) ? 0 : 1
}

/** Weekday initials in display order, starting at the locale's first day. */
export function getWeekdayLabels(locale: string, weekStart: number): string[] {
  const formatter = new Intl.DateTimeFormat(locale, { weekday: 'short' })
  // 2024-01-07 is a Sunday, so adding the weekday index walks a whole week.
  return Array.from({ length: 7 }, (_, i) => {
    const date = new Date(2024, 0, 7 + ((weekStart + i) % 7))
    return formatter.format(date)
  })
}

/** Month names in calendar order, `long` for the header, `short` for the grid. */
export function getMonthLabels(locale: string, style: 'long' | 'short' = 'long'): string[] {
  const formatter = new Intl.DateTimeFormat(locale, { month: style })
  return Array.from({ length: 12 }, (_, month) => formatter.format(new Date(2024, month, 1)))
}

/**
 * Completed years between a date and today, or `null` if that is not a
 * sensible question (unparseable, or in the future).
 *
 * Shown under the birth-date field as a check on what was typed: a slip in the
 * year is invisible in `12.03.1997` and obvious in "29 years old". The number
 * is also the one ADR-0045 gates a sale on, so the admin sees what the
 * terminal will compute.
 */
export function calculateAge(iso: string, today: Date = new Date()): number | null {
  const parts = splitIso(iso)
  if (!parts) return null

  let age = today.getFullYear() - parts.year
  const hadBirthday =
    today.getMonth() + 1 > parts.month ||
    (today.getMonth() + 1 === parts.month && today.getDate() >= parts.day)
  if (!hadBirthday) age -= 1

  return age < 0 ? null : age
}
