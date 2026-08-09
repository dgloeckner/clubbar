import type { APIRequestContext } from '@playwright/test'
import type { ApiRequestLike } from './request-context'

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

/**
 * Today according to the **server**, as the `settlement_date` the API expects.
 *
 * Deliberately not `new Date()` on the runner. The backend runs UTC while a
 * developer's machine does not, so between 22:00 and 24:00 CEST the two
 * disagree about what day it is. A settlement then pairs a runner-dated
 * `settlement_date` with a server-dated `execution_date` from
 * `minimumExecutionDate()`, and the lead-time check — `execution_date >=
 * settlement_date + 7 days` — rejects a pair the server itself suggested. Every
 * settlement test failed for two hours a night, and never on CI, which is UTC
 * end to end.
 *
 * Both dates must come from the same clock, and the server's is the one the
 * rule is evaluated against.
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
