import type { APIRequestContext } from '@playwright/test'

/**
 * Date helpers for settlement tests.
 *
 * A SEPA execution_date must be a TARGET2 business day (issue #11). Tests that
 * derive it from `today + 7` would fail on roughly two weekdays in seven, and
 * more around Easter and Christmas, so they ask the backend instead of
 * reimplementing the rule here.
 */

/** Format a Date as YYYY-MM-DD using local calendar components. */
export function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** Today, as the settlement_date the API expects. */
export function today(): string {
  return toIsoDate(new Date())
}

/**
 * The earliest execution date the backend will accept — already rolled past
 * weekends and TARGET2 closing days.
 */
export async function minimumExecutionDate(request: APIRequestContext): Promise<string> {
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
