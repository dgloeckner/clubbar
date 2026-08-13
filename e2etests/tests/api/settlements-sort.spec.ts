/**
 * Settlements list — sort keys (UC-A33)
 *
 * #120 gave the list a real `created_by` sort: SettlementsRepository::listPaginated
 * maps 'created_by' to the admin's display name, where the old ternary had
 * s.created_at in both branches, so clicking "Created by" silently re-sorted by
 * date. Proving that requires two settlements created by two *different* admins,
 * which needs a second logged-in admin context — so it lives here rather than in
 * the admin-chromium spec: `loginAs` opens a second session and that invalidates
 * the browser's, which a UI test cannot survive.
 *
 * E2E Patterns applied:
 *  - Pattern 001: unique test data per test (own admin, own settlements)
 *  - Pattern 003: assertions locate rows by id rather than by position
 *
 * The two settlements are built so that name order and date order DISAGREE: the
 * admin whose display name sorts first creates the *later* settlement. A list
 * ordered by created_at therefore cannot satisfy the created_by assertions, and
 * vice versa — without that, a page that happened to be ordered by date would
 * pass a created_by assertion by coincidence.
 *
 * Note: the settlements list has no filter that isolates one test's rows, so the
 * assertions need this test's two settlements to be on the page it fetches. With
 * per_page=100 that holds for a freshly migrated database, which is what CI runs
 * and what `scripts/dev-setup.sh` produces locally.
 */

import { test, expect, APIRequestContext } from '@playwright/test'
import { loginAs } from '../../utils/csrf'
import { settlementFactory as buildSettlementFactory } from '../../utils/settlements'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

/**
 * Serial within this file: these tests perform full password+MFA logins as the
 * shared seeded admin, and `totp_last_timestep` (#338) accepts one TOTP code
 * per 30-second step per account. Run 4-wide, the second login in a step is
 * correctly rejected as a replay and the test fails for a reason that has
 * nothing to do with what it is testing. E2E Pattern 004: a test that cannot
 * be made independent of a shared resource is run in order instead.
 */
test.describe.configure({ mode: 'default' })

const API_BASE = 'http://localhost:8080/api'

/** settlements.created_at is a TIMESTAMP with 1-second resolution. */
const ONE_SECOND_APART = 1100

interface SettlementListRow {
  id: string
  created_at: string
  created_by_admin_name: string | null
}

/**
 * The sorted list, paged through until every id the caller cares about has been
 * seen (E2E Pattern 003).
 *
 * One page of 100 is only enough while the table is small. This assertion is
 * about the *relative* order of two settlements this test just created, and the
 * newest rows sit at the far end of a `created_at asc` sort — so on any
 * long-lived database (a dev stack that has run the suite a few times) they fall
 * off page 1 and the test fails on volume rather than on sorting.
 */
async function fetchSettlements(
  request: Pick<APIRequestContext, 'get'>,
  sort: 'created_at' | 'created_by',
  order: 'asc' | 'desc',
  needles: string[] = []
): Promise<SettlementListRow[]> {
  const perPage = 100
  const rows: SettlementListRow[] = []
  const outstanding = new Set(needles)

  for (let page = 1; ; page++) {
    const response = await request.get(
      `${API_BASE}/admin/settlements?page=${page}&per_page=${perPage}&sort=${sort}&order=${order}`
    )
    expect(response.status(), await response.text()).toBe(200)

    const body = await response.json()
    const batch = (body.data ?? []) as SettlementListRow[]
    rows.push(...batch)
    batch.forEach((row) => outstanding.delete(row.id))

    const totalPages = body.pagination?.total_pages ?? page
    if (outstanding.size === 0 || page >= totalPages) return rows
  }
}

function positionOf(rows: SettlementListRow[], id: string, context: string): number {
  const index = rows.findIndex((row) => row.id === id)
  expect(index, `${context}: expected settlement ${id} on the fetched page`).toBeGreaterThanOrEqual(0)
  return index
}

test.describe('Settlements list sort keys', () => {
  test('created_by sorts by the admin display name, independently of created_at', async ({
    playwright,
  }) => {
    const seededAdmin = await loginAs(
      playwright,
      TEST_CREDENTIALS.admin.email,
      TEST_CREDENTIALS.admin.password
    )
    const terminal = await playwright.request.newContext({
      baseURL: API_BASE,
      extraHTTPHeaders: { Authorization: `Bearer ${TEST_CREDENTIALS.terminal.token}` },
    })

    const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`
    // Sorts before the seeded "Admin User" ascending, and last descending.
    const earlierName = `Aaa Sort Probe ${suffix}`

    const createAdmin = await seededAdmin.post(`${API_BASE}/admin/admin-users`, {
      data: {
        email: `aaa-sort-${suffix}@test.example.com`,
        display_name: earlierName,
        locale: 'de',
      },
    })
    expect(createAdmin.status(), await createAdmin.text()).toBe(201)
    const { admin, password } = await createAdmin.json()

    const probeAdmin = await loginAs(playwright, admin.email, password)
    try {
      // The seeded admin ("Admin User") creates the OLDER settlement; the probe
      // admin ("Aaa …") the NEWER one. Sorting by name therefore reverses the
      // order that sorting by date produces.
      const olderBySeededAdmin = await buildSettlementFactory(seededAdmin, terminal).create()
      await new Promise((resolve) => setTimeout(resolve, ONE_SECOND_APART))
      const newerByProbeAdmin = await buildSettlementFactory(probeAdmin, terminal).create()

      // Baseline: by date, the older settlement really is the older one.
      const probes = [olderBySeededAdmin.id, newerByProbeAdmin.id]
      const byDateAsc = await fetchSettlements(seededAdmin, 'created_at', 'asc', probes)
      expect(
        positionOf(byDateAsc, olderBySeededAdmin.id, 'created_at asc'),
        'created_at asc: the settlement created first should come first'
      ).toBeLessThan(positionOf(byDateAsc, newerByProbeAdmin.id, 'created_at asc'))

      // By name ascending "Aaa …" precedes "Admin User", which is the opposite
      // of the date order asserted above — so this cannot pass on a date sort.
      const byNameAsc = await fetchSettlements(seededAdmin, 'created_by', 'asc', probes)
      expect(
        positionOf(byNameAsc, newerByProbeAdmin.id, 'created_by asc'),
        `created_by asc: "${earlierName}" should precede "Admin User"`
      ).toBeLessThan(positionOf(byNameAsc, olderBySeededAdmin.id, 'created_by asc'))

      // And descending flips it back.
      const byNameDesc = await fetchSettlements(seededAdmin, 'created_by', 'desc', probes)
      expect(
        positionOf(byNameDesc, olderBySeededAdmin.id, 'created_by desc'),
        `created_by desc: "Admin User" should precede "${earlierName}"`
      ).toBeLessThan(positionOf(byNameDesc, newerByProbeAdmin.id, 'created_by desc'))

      // The rows carry the admin the settlement was actually created by.
      const probeRow = byNameAsc.find((row) => row.id === newerByProbeAdmin.id)
      expect(probeRow?.created_by_admin_name).toBe(earlierName)
    } finally {
      await probeAdmin.dispose()
      await terminal.dispose()
      await seededAdmin.dispose()
    }
  })
})
