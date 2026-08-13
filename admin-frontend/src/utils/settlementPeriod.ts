/**
 * The transaction period a settlement swept, as one display string.
 *
 * The settlements list shows the run's own date; the period underneath it is
 * only worth a second line when it says something that date does not (#378).
 * A run made today over transactions all booked today printed
 * "12.08.2026" above "12.08. – 12.08.2026" — the same day written twice, in
 * two different shapes, which is what made the column unreadable.
 *
 * Two rules follow from that:
 *   - a period that is one day, and that day is the run's own date, is dropped;
 *   - the range itself is rendered by `Intl.DateTimeFormat.formatRange`, which
 *     collapses the parts the two ends share ("12.–15.08.2026") and returns a
 *     single date when they are the same day — in the active locale, rather
 *     than the hand-rolled `dd.mm.` this replaces.
 */

import { parseApiDate } from './dates'

function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  )
}

function parseValid(value: string | null | undefined): Date | null {
  if (!value) return null
  const parsed = parseApiDate(value)
  return isNaN(parsed.getTime()) ? null : parsed
}

/**
 * @param minStr    earliest transaction date in the settlement (API value)
 * @param maxStr    latest transaction date in the settlement (API value)
 * @param createdAt the settlement's own date, already shown in the same cell
 * @param locale    Intl locale tag, e.g. `de-DE` (from `useFormatters`)
 * @returns the period to show, or `null` when there is nothing left to say
 */
export function formatTransactionPeriod(
  minStr: string | null | undefined,
  maxStr: string | null | undefined,
  createdAt: string | null | undefined,
  locale: string = 'de-DE',
): string | null {
  const min = parseValid(minStr)
  const max = parseValid(maxStr)
  if (!min || !max) return null

  if (isSameDay(min, max)) {
    const created = parseValid(createdAt)
    if (created && isSameDay(min, created)) return null
  }

  // `formatRange` rejects a reversed pair, and the two fields describe one set
  // of transactions either way round — so order them rather than lose the line.
  const [from, to] = min <= max ? [min, max] : [max, min]

  try {
    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).formatRange(from, to)
  } catch {
    return null
  }
}
