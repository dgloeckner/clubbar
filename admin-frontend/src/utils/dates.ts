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
