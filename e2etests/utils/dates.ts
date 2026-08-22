import type { APIRequestContext } from '@playwright/test'
import type { ApiRequestLike } from './request-context'

/**
 * Date helpers: the bank's calendar, and the club's clock.
 *
 * A SEPA execution_date must be a TARGET2 business day (issue #11). Tests that
 * derive it from `today + 7` would fail on roughly two weekdays in seven, and
 * more around Easter and Christmas, so they ask the backend instead of
 * reimplementing the rule here.
 *
 * The `club*` helpers at the bottom are the other half of the same problem: a
 * server-rendered date is written in the club's zone, and the runner's is not
 * it.
 */

/** Format a Date as YYYY-MM-DD using local calendar components. */
export function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/**
 * Today according to the **server**.
 *
 * Deliberately not `new Date()` on the runner. The backend runs UTC while a
 * developer's machine does not, so between 22:00 and 24:00 CEST the two
 * disagree about what day it is — and the lead time is measured against the
 * server's day, not the runner's (issue #113).
 *
 * Since #113 no request carries a date the runner chose, so this is no longer
 * needed to *build* a settlement. It is still what an assertion about a
 * settlement's recorded `settlement_date` must compare against.
 */
export async function serverToday(request: ApiRequestLike): Promise<string> {
  const res = await request.get('/api/health')

  if (!res.ok()) {
    throw new Error(`Could not load server date from /api/health (${res.status()}): ${await res.text()}`)
  }

  const body = await res.json()
  const timestamp = body.timestamp as string | undefined
  if (!timestamp) {
    throw new Error(`/api/health returned no timestamp: ${JSON.stringify(body)}`)
  }

  return timestamp.slice(0, 10)
}

/**
 * The earliest execution date the backend will accept — already rolled past
 * weekends and TARGET2 closing days.
 */
export async function minimumExecutionDate(request: ApiRequestLike): Promise<string> {
  const res = await request.get('/api/admin/settlements/execution-date-info')

  if (!res.ok()) {
    throw new Error(
      `Could not load execution-date-info (${res.status()}): ${await res.text()}`
    )
  }

  const body = await res.json()
  return body.minimum_date as string
}

/**
 * Fixed dates that are never valid execution dates, for negative tests.
 * Hard-coded rather than computed, per E2E Pattern 003.
 */
export const INVALID_EXECUTION_DATES = {
  saturday: '2026-08-08',
  /** The Sunday reported in issue #11. */
  sunday: '2026-08-09',
  goodFriday: '2026-04-03',
  easterMonday: '2026-04-06',
  christmasDay: '2026-12-25',
  newYearsDay: '2027-01-01',
} as const

/**
 * Easter Sunday, Gregorian — Anonymous Gregorian (Meeus/Jones/Butcher).
 *
 * A deliberate exception to "ask the backend" above: the helpers below need a
 * date with a *known relationship* to the closing days ("the business day after
 * the Easter run", "a business day inside the lead time"), and no endpoint
 * answers that. `INVALID_EXECUTION_DATES` pins the result — the settlements
 * suite asserts this function reproduces those hard-coded dates.
 */
export function easterSunday(year: number): string {
  const a = year % 19
  const b = Math.floor(year / 100)
  const c = year % 100
  const d = Math.floor(b / 4)
  const e = b % 4
  const f = Math.floor((b + 8) / 25)
  const g = Math.floor((b - f + 1) / 3)
  const h = (19 * a + b - d - g + 15) % 30
  const i = Math.floor(c / 4)
  const k = c % 4
  const l = (32 + 2 * e + 2 * i - h - k) % 7
  const m = Math.floor((a + 11 * h + 22 * l) / 451)

  const month = Math.floor((h + l - 7 * m + 114) / 31)
  const day = ((h + l - 7 * m + 114) % 31) + 1

  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/** Shift an ISO date by whole days, in UTC so no local timezone can move it. */
export function shiftIsoDate(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

/** The six TARGET2 closing days of a year, ascending. */
export function target2ClosingDays(year: number): string[] {
  const easter = easterSunday(year)

  return [
    `${year}-01-01`,
    shiftIsoDate(easter, -2), // Good Friday
    shiftIsoDate(easter, 1), // Easter Monday
    `${year}-05-01`,
    `${year}-12-25`,
    `${year}-12-26`,
  ].sort()
}

/** Does TARGET2 settle on this date? Mon-Fri, minus the six closing days. */
export function isTarget2BusinessDay(iso: string): boolean {
  const weekday = new Date(`${iso}T00:00:00Z`).getUTCDay()
  if (weekday === 0 || weekday === 6) return false

  return !target2ClosingDays(Number(iso.slice(0, 4))).includes(iso)
}

/**
 * A bank business day that is inside the 7-day lead time — valid on the
 * business-day rule, and rejected only by the lead time (issue #113).
 *
 * Walks forward from tomorrow, so it never reaches today + 7: the longest run
 * of non-business days is the four at Easter, which puts the worst case at
 * today + 5.
 */
export async function businessDayInsideLeadTime(request: ApiRequestLike): Promise<string> {
  const today = await serverToday(request)

  for (let offset = 1; offset <= 6; offset++) {
    const candidate = shiftIsoDate(today, offset)
    if (isTarget2BusinessDay(candidate)) return candidate
  }

  throw new Error(`No business day within 6 days of ${today} — the closing-day calendar is wrong`)
}

/**
 * The Tuesday after Easter Monday, in the next Easter still far enough ahead to
 * clear the lead time. The first business day after the year's longest run of
 * closing days (Good Friday through Easter Monday).
 */
export function tuesdayAfterNextEaster(from: string = new Date().toISOString().slice(0, 10)): string {
  for (let year = Number(from.slice(0, 4)); year <= Number(from.slice(0, 4)) + 1; year++) {
    const tuesday = shiftIsoDate(easterSunday(year), 2)
    if (tuesday > shiftIsoDate(from, 7)) return tuesday
  }

  throw new Error(`No Easter Tuesday found after ${from}`)
}

/**
 * The zone the backend *reads* in — `CLUB_TIMEZONE`, Europe/Berlin unless a
 * deployment says otherwise (`App\Shared\Time\ClubTimeZone`, #637).
 *
 * Hard-coded rather than read from `process.env`: the value the backend uses
 * comes from `backend/.env` inside the container, which the Playwright process
 * cannot see, so an env var here would describe the runner and claim to
 * describe the server. The stack under test runs the default.
 */
export const CLUB_TIME_ZONE = 'Europe/Berlin'

/**
 * A stored instant as a German-language mail prints it: `28.08.2026`.
 *
 * The counterpart of `MailFormat::date()`. It matters that this is a *zone
 * conversion* and not a reformat: everything is stored in UTC (#365) and
 * rendered in the club's zone, so an instant at `2026-08-27 22:46Z` is the 28th
 * to the person reading the mail. Computing the same date from the runner's
 * clock agrees for 22 hours a day and is off by one for the other two.
 *
 * Only ever pass an **instant**. A calendar day — a settlement's due date, a
 * birth date — carries no time to convert and must be formatted verbatim;
 * shifting one is how a deadline silently moves a day.
 */
export function clubDate(instant: Date | string): string {
  return new Intl.DateTimeFormat('de-DE', {
    timeZone: CLUB_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(instant))
}

/** The same instant to the minute, as `MailFormat::dateTime()` writes it. */
export function clubDateTime(instant: Date | string): string {
  return new Intl.DateTimeFormat('de-DE', {
    timeZone: CLUB_TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(instant))
}
