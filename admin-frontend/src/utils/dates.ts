import { getClubTimeZone } from './clubTimeZone'

/**
 * Formats a Date as an ISO calendar date (YYYY-MM-DD) in the *local* timezone.
 *
 * `toISOString().split('T')[0]` converts to UTC first, so for a user west of
 * Greenwich an evening timestamp yields tomorrow's date. Date-only API fields
 * such as `settlement_date` mean the local calendar day, so they must be built
 * from the local components.
 *
 * This is formatting only — the business rule for which dates are valid lives
 * on the server (see `useExecutionDateInfo`).
 */
export function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/

/**
 * Is this API value a calendar day rather than an instant?
 *
 * The shape is the contract, on both sides of the wire — the backend's
 * `ClubTimeZone::moment()` branches on exactly the same rule. It decides
 * whether a value may be shifted into another zone at all: an instant must
 * be, and a calendar day must never be, because `settlement_date` means the
 * 21st everywhere and moving it is how a deadline loses a day.
 */
export function isDateOnly(value: string): boolean {
  return DATE_ONLY.test(value)
}

/**
 * An instant with no zone on it — `2026-09-01 19:33:12` as MariaDB spells it,
 * or `2026-09-01T19:33:12` after somebody replaced only the space.
 */
const ZONELESS_INSTANT = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)$/

/**
 * Parses a value from the API into a Date, reading date-only strings as the
 * *local* calendar day.
 *
 * This is the inverse of the trap `toIsoDate` avoids. `new Date('2026-08-05')`
 * is specified to parse as UTC midnight, so `Intl.DateTimeFormat` renders it as
 * the 4th for anyone west of Greenwich, and `setHours(0, 0, 0, 0)` on it lands
 * on the wrong calendar day — a settlement dated today reads as "Yesterday".
 * Date-only API fields (`settlement_date`, `mandate_signed_at`, …) denote a
 * calendar day, not an instant, so they are built from the local components.
 *
 * Anything with a time of day — `2026-08-05T22:15:00Z` — *is* an instant and is
 * left to the platform parser. An out-of-range date-only string yields an
 * invalid Date, matching what `new Date()` would have returned for it, so
 * callers keep their existing fallbacks.
 *
 * An instant that arrives *without* a zone is read as UTC, not as local time.
 * Every column behind this API holds UTC (`Shared\Time\Utc`), and the way out
 * is `DateFormatter::toUtcIso()`, which labels it "Z" — but an endpoint that
 * forgets the label emits a bare `2026-09-01 19:33:12`, which both `new Date()`
 * and the spec read as the reader's *local* time. Nothing about that failure is
 * visible: the string parses, the row renders, and every time on the screen is
 * quietly off by the reader's own offset (#365, and again on the notifications
 * queue). Reading it as UTC makes the frontend agree with the contract instead
 * of with whichever endpoint last forgot it.
 */
export function parseApiDate(value: string): Date {
  const match = DATE_ONLY.exec(value)
  if (!match) {
    const zoneless = ZONELESS_INSTANT.exec(value)
    return new Date(zoneless ? `${zoneless[1]}T${zoneless[2]}Z` : value)
  }

  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])

  // Built via setFullYear rather than the Date(y, m, d) constructor, which maps
  // two-digit years into the 1900s.
  const parsed = new Date(0)
  parsed.setFullYear(year, month - 1, day)
  parsed.setHours(0, 0, 0, 0)

  // The constructor rolls overflow forward (month 13 becomes January), which
  // would turn a malformed string into a plausible-looking date.
  if (parsed.getMonth() !== month - 1 || parsed.getDate() !== day) {
    return new Date(NaN)
  }
  return parsed
}

/**
 * The calendar day `date` falls on **in the club's zone**, as `YYYY-MM-DD`.
 *
 * `toIsoDate` answers the same question for the reader's own zone, which is
 * what a date *input* wants. A filter is different: it names a day of the
 * club's books, and the backend resolves it against a UTC column as the club's
 * day. Building it from the browser's calendar instead put a reader an hour
 * ahead of the club onto tomorrow's date for the two hours after their
 * midnight.
 *
 * Falls back to the local calendar day before the club's zone is known, which
 * is the behaviour this replaced.
 */
export function toClubIsoDate(date: Date, timeZone: string | undefined = getClubTimeZone()): string {
  if (timeZone === undefined) {
    return toIsoDate(date)
  }

  // `en-CA` renders ISO-ish, but assembling from parts is immune to a runtime
  // that disagrees about that.
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date)

  const at = (type: string): string => parts.find((p) => p.type === type)?.value ?? ''
  const year = at('year')
  const month = at('month')
  const day = at('day')

  return year && month && day ? `${year}-${month}-${day}` : toIsoDate(date)
}
