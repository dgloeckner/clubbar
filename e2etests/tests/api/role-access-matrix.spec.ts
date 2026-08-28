/**
 * The access matrix (ADR-0044, #517).
 *
 * The regression net for the whole epic. Every other test in the suite runs as
 * an `admin` and therefore cannot notice a boundary moving: a route quietly
 * granted to a lesser office, or quietly taken away from one, looks identical
 * from the seat that reaches everything.
 *
 * So this file states the grant table once, as data, and walks it as each
 * lesser office. Every (role, endpoint) pair is either **refused by name** —
 * 403 `insufficient_role`, the middleware's answer before any controller runs —
 * or **not refused on role grounds**, whatever the endpoint then makes of a
 * deliberately empty body.
 *
 * ### What is asserted, and what is not
 *
 * "Not 403 `insufficient_role`" rather than "2xx" is the whole point. Reaching
 * an endpoint with an empty body and a placeholder id lands on 400, 404 or 422,
 * and that is a *pass*: the question here is whether the office may knock, not
 * what the door says afterwards. Asserting 2xx would mean sending real bodies
 * to sixty endpoints, which is a different test and a far more destructive one.
 *
 * `admin` is not walked. It reaches everything by construction — the backend's
 * own `RouteRoleMapCompletenessTest` asserts exactly that against the real
 * route collector — and the rest of this suite runs as one all day.
 *
 * ### The two nets, and why neither replaces the other
 *
 * Adding a route to `routes.php` without classifying it fails
 * `RouteRoleMapCompletenessTest` in PHPUnit, not this file: a new route simply
 * does not appear in the table below. That backend test is the completeness
 * half. This is the *behaviour* half — it proves the map is enforced through
 * the real stack, over a real session, for an account that really holds one
 * role.
 *
 * ### Isolation (Patterns 001, 002, 004)
 *
 * Each worker mints its own Kassenwart and Getränkewart (see
 * `fixtures/roleRequests.ts`) rather than demoting the shared seeded admin,
 * which every other spec is authenticated as. Nothing here mutates anything:
 * every path carries an id that belongs to nobody.
 */

import { test, expect } from '../../fixtures/roleRequests'
import type { CsrfAwareContext } from '../../utils/csrf'

const API_BASE = 'http://localhost:8080/api'

/** An id in the right shape that belongs to nothing, so a reached route 404s. */
const NOWHERE = '00000000-0000-4000-8000-000000000000'

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
type LesserRole = 'kassenwart' | 'getraenkewart'

/**
 * The grants, transcribed from `RouteRoleMap` — by hand and deliberately so.
 * A table generated from the thing under test proves only that the generator
 * ran.
 *
 * Each entry lists the **lesser** offices that may reach it. `admin` is implied
 * everywhere and never written down; an empty list means `admin`-only.
 */
const TREASURY: LesserRole[] = ['kassenwart']
const BAR: LesserRole[] = ['getraenkewart']
const EVERY: LesserRole[] = ['kassenwart', 'getraenkewart']
const ADMIN_ONLY: LesserRole[] = []

interface Entry {
  method: Method
  path: string
  roles: LesserRole[]
  /**
   * Skip the *allowed* direction for this entry and assert only the refusals.
   *
   * For the two endpoints that settle money in bulk. Reaching them with an
   * empty body would rely on validation to stop a run that covers every
   * unsettled member, and a test that depends on a 400 to avoid settling the
   * club is not a test anybody should have to trust. The allowed direction for
   * these is covered by the Kassenwart settlement flow below, which runs one
   * for real against members it created itself.
   */
  refusalOnly?: true
}

const MATRIX: Entry[] = [
  // ── the caller's own account ─────────────────────────────────────────────
  // `POST /auth/logout`, `POST /auth/2fa/setup` and `POST /auth/2fa/reset` are
  // classified `EVERY` and deliberately absent: each acts on the session or the
  // secret this worker's context is holding, so probing them would sign the
  // fixture out from under every later test in the worker.
  { method: 'GET', path: '/auth/profile', roles: EVERY },
  { method: 'PATCH', path: '/auth/profile', roles: EVERY },
  { method: 'PATCH', path: '/auth/change-password', roles: EVERY },
  { method: 'POST', path: '/auth/2fa/confirm', roles: EVERY },

  // ── the installation itself ──────────────────────────────────────────────
  { method: 'GET', path: '/admin/security-check', roles: ADMIN_ONLY },
  // The backups page (#693). An archive carries the audit log, every admin's
  // TOTP ciphertext and the database password.
  { method: 'GET', path: '/admin/backups', roles: ADMIN_ONLY },
  { method: 'GET', path: '/admin/backups/remote', roles: ADMIN_ONLY },
  { method: 'GET', path: `/admin/backups/clubbar-19700101-000000-${NOWHERE.slice(0, 8)}.cbb`, roles: ADMIN_ONLY },
  { method: 'PATCH', path: '/admin/instance-config', roles: ADMIN_ONLY },
  // TREASURY since #677: the scheduler gate refuses the treasurer's finalize
  // button, so the treasurer has to be able to see that it is closed. The
  // response is redacted for them — what this file asserts is only who may
  // knock; `scheduler-payload-split.spec.ts` asserts what they hear.
  { method: 'GET', path: '/admin/scheduler', roles: TREASURY },

  // ── the club's financial position ────────────────────────────────────────
  { method: 'GET', path: '/admin/dashboard', roles: TREASURY },
  { method: 'GET', path: '/admin/statistics/monthly', roles: TREASURY },

  // ── members ──────────────────────────────────────────────────────────────
  { method: 'GET', path: '/admin/members', roles: TREASURY },
  { method: 'POST', path: '/admin/members', roles: TREASURY },
  { method: 'GET', path: '/admin/members/credit-balances', roles: TREASURY },
  { method: 'GET', path: '/admin/members/collection-holds', roles: TREASURY },
  { method: 'GET', path: '/admin/members/mandate-missing', roles: TREASURY },
  { method: 'GET', path: `/admin/members/${NOWHERE}`, roles: TREASURY },
  { method: 'PATCH', path: `/admin/members/${NOWHERE}`, roles: TREASURY },
  { method: 'DELETE', path: `/admin/members/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/members/${NOWHERE}/export`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/members/${NOWHERE}/anonymize`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/members/${NOWHERE}/collection-hold/clear`, roles: TREASURY },

  // ── the drinks list ──────────────────────────────────────────────────────
  { method: 'GET', path: '/admin/categories', roles: BAR },
  { method: 'POST', path: '/admin/categories', roles: BAR },
  { method: 'PATCH', path: `/admin/categories/${NOWHERE}`, roles: BAR },
  { method: 'PATCH', path: `/admin/categories/${NOWHERE}/status`, roles: BAR },
  { method: 'DELETE', path: `/admin/categories/${NOWHERE}`, roles: BAR },
  { method: 'GET', path: '/admin/products', roles: BAR },
  { method: 'POST', path: '/admin/products', roles: BAR },
  { method: 'PATCH', path: `/admin/products/${NOWHERE}`, roles: BAR },
  { method: 'PATCH', path: `/admin/products/${NOWHERE}/status`, roles: BAR },
  { method: 'DELETE', path: `/admin/products/${NOWHERE}`, roles: BAR },

  // ── transactions, Storno included ────────────────────────────────────────
  { method: 'GET', path: '/admin/transactions', roles: TREASURY },
  { method: 'GET', path: '/admin/transactions/export', roles: TREASURY },
  { method: 'GET', path: `/admin/members/${NOWHERE}/transactions`, roles: TREASURY },
  { method: 'POST', path: `/admin/transactions/${NOWHERE}/storno`, roles: TREASURY },

  // ── accounts, the audit log, keys ────────────────────────────────────────
  { method: 'GET', path: '/admin/admin-users', roles: ADMIN_ONLY },
  { method: 'POST', path: '/admin/admin-users', roles: ADMIN_ONLY },
  { method: 'GET', path: `/admin/admin-users/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'PATCH', path: `/admin/admin-users/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'DELETE', path: `/admin/admin-users/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/admin-users/${NOWHERE}/reactivate`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/admin-users/${NOWHERE}/reset-password`, roles: ADMIN_ONLY },
  { method: 'GET', path: '/admin/audit-log', roles: ADMIN_ONLY },
  { method: 'GET', path: '/admin/encryption-keys', roles: ADMIN_ONLY },
  { method: 'POST', path: '/admin/encryption-keys', roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/encryption-keys/${NOWHERE}/activate`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/encryption-keys/${NOWHERE}/revoke`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/encryption-keys/${NOWHERE}/rotate-batch`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/encryption-keys/${NOWHERE}/complete-rotation`, roles: ADMIN_ONLY },

  // ── settlements, SEPA export included ────────────────────────────────────
  { method: 'GET', path: '/admin/settlements/execution-date-info', roles: TREASURY },
  { method: 'GET', path: '/admin/settlements/filter-preview', roles: TREASURY },
  { method: 'POST', path: '/admin/settlements/settle-filter', roles: TREASURY, refusalOnly: true },
  { method: 'POST', path: '/admin/settlements/preview', roles: TREASURY },
  { method: 'GET', path: '/admin/settlements/reversal-candidates', roles: TREASURY },
  { method: 'POST', path: '/admin/settlements', roles: TREASURY, refusalOnly: true },
  { method: 'GET', path: '/admin/settlements', roles: TREASURY },
  { method: 'GET', path: `/admin/settlements/${NOWHERE}`, roles: TREASURY },
  { method: 'DELETE', path: `/admin/settlements/${NOWHERE}/cancel`, roles: TREASURY },
  { method: 'POST', path: `/admin/settlements/${NOWHERE}/submit`, roles: TREASURY },
  { method: 'POST', path: `/admin/settlements/${NOWHERE}/reverse`, roles: TREASURY },
  { method: 'POST', path: `/admin/settlements/${NOWHERE}/export/sepa-xml`, roles: TREASURY },
  { method: 'GET', path: `/admin/settlements/${NOWHERE}/export/csv`, roles: TREASURY },
  { method: 'GET', path: `/admin/settlements/${NOWHERE}/export-transactions`, roles: TREASURY },

  // ── SEPA configuration: read for the office, write for the operator ──────
  { method: 'GET', path: '/admin/sepa-config', roles: TREASURY },
  { method: 'POST', path: '/admin/sepa-config', roles: ADMIN_ONLY },
  { method: 'PUT', path: '/admin/sepa-config', roles: ADMIN_ONLY },
  { method: 'PATCH', path: '/admin/sepa-config', roles: ADMIN_ONLY },

  // ── mail: admin only, and load-bearing ──────────────────────────────────
  { method: 'GET', path: '/admin/mail-config', roles: ADMIN_ONLY },
  { method: 'PATCH', path: '/admin/mail-config', roles: ADMIN_ONLY },
  { method: 'POST', path: '/admin/mail-config/test-mail', roles: ADMIN_ONLY },

  // ── the queue of what was announced to members ──────────────────────────
  { method: 'GET', path: '/admin/notifications', roles: TREASURY },
  { method: 'POST', path: `/admin/notifications/${NOWHERE}/retry`, roles: TREASURY },

  // ── reports: the one surface all three offices share ────────────────────
  { method: 'GET', path: '/admin/reports/terminal-activity', roles: EVERY },
  { method: 'GET', path: '/admin/reports/revenue', roles: EVERY },

  // ── bank lookup ─────────────────────────────────────────────────────────
  { method: 'GET', path: '/admin/bank-lookup', roles: TREASURY },

  // ── terminals ───────────────────────────────────────────────────────────
  { method: 'GET', path: '/admin/terminals', roles: ADMIN_ONLY },
  { method: 'POST', path: '/admin/terminals', roles: ADMIN_ONLY },
  { method: 'GET', path: `/admin/terminals/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'PATCH', path: `/admin/terminals/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'DELETE', path: `/admin/terminals/${NOWHERE}`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/terminals/${NOWHERE}/rotate-token`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/terminals/${NOWHERE}/revoke`, roles: ADMIN_ONLY },
  { method: 'POST', path: `/admin/terminals/${NOWHERE}/anomalies/${NOWHERE}/acknowledge`, roles: ADMIN_ONLY },
]

/** Issue one probe with an empty body, and report how it was answered. */
async function probe(
  ctx: CsrfAwareContext,
  entry: Entry,
): Promise<{ status: number; error?: string }> {
  const url = `${API_BASE}${entry.path}`
  const options = entry.method === 'GET' || entry.method === 'DELETE' ? undefined : { data: {} }

  const response = await (entry.method === 'GET'
    ? ctx.get(url)
    : entry.method === 'POST'
      ? ctx.post(url, options)
      : entry.method === 'PATCH'
        ? ctx.patch(url, options)
        : entry.method === 'PUT'
          ? ctx.put(url, options)
          : ctx.delete(url))

  const status = response.status()
  let error: string | undefined
  try {
    error = (await response.json())?.error
  } catch {
    // A CSV export answers with a file, not JSON. Reaching it at all is the
    // answer this file wants.
  }

  return { status, error }
}

function isRoleRefusal(answer: { status: number; error?: string }): boolean {
  return answer.status === 403 && answer.error === 'insufficient_role'
}

/**
 * Walk the whole table as one office and report every disagreement at once.
 *
 * Collected rather than asserted one at a time on purpose: a boundary that
 * moved usually moved for a section, and "these eleven routes now answer 403"
 * is a diagnosis, while the first of them failing alone is a puzzle.
 */
async function walk(ctx: CsrfAwareContext, role: LesserRole): Promise<string[]> {
  const mismatches: string[] = []

  for (const entry of MATRIX) {
    const allowed = entry.roles.includes(role)
    if (allowed && entry.refusalOnly) continue

    const answer = await probe(ctx, entry)
    const refused = isRoleRefusal(answer)

    if (allowed && refused) {
      mismatches.push(`${entry.method} ${entry.path} — refused, but ${role} should reach it`)
    } else if (!allowed && !refused) {
      mismatches.push(
        `${entry.method} ${entry.path} — answered ${answer.status} ${answer.error ?? ''}`.trim() +
          `, but ${role} should be refused with 403 insufficient_role`,
      )
    }
  }

  return mismatches
}

test.describe('Role access matrix (ADR-0044)', () => {
  test('a Kassenwart reaches the treasury and is refused everything else', async ({
    kassenwartRequest,
  }) => {
    test.setTimeout(120_000)

    expect(await walk(kassenwartRequest, 'kassenwart')).toEqual([])
  })

  test('a Getränkewart reaches the drinks list and is refused everything else', async ({
    getraenkewartRequest,
  }) => {
    test.setTimeout(120_000)

    expect(await walk(getraenkewartRequest, 'getraenkewart')).toEqual([])
  })

  /**
   * The table is only worth what its coverage is worth, so it says out loud how
   * much of the map it carries. The backend's completeness test is what fails
   * when a route is added; this is what would notice the table quietly shrinking.
   */
  test('the table covers every section of the grant table', async () => {
    const sections = [
      '/auth/',
      '/admin/members',
      '/admin/categories',
      '/admin/products',
      '/admin/transactions',
      '/admin/admin-users',
      '/admin/audit-log',
      '/admin/encryption-keys',
      '/admin/settlements',
      '/admin/sepa-config',
      '/admin/mail-config',
      '/admin/notifications',
      '/admin/reports/',
      '/admin/terminals',
    ]

    for (const section of sections) {
      expect(MATRIX.some((entry) => entry.path.startsWith(section)), section).toBe(true)
    }

    expect(MATRIX.length).toBeGreaterThan(70)
  })
})
