/**
 * Settlements list — sort order (UC-A33)
 *
 * #120 gave the settlements list a *real* `created_by` sort: SettlementsRepository
 * ::listPaginated now maps 'created_by' to the admin's display name, where the old
 * ternary had s.created_at in both branches — so clicking "Created by" re-sorted by
 * date and the arrow landed on a heading the order had nothing to do with.
 *
 * This file pinned that old behaviour: it asserted that clicking "Created By"
 * re-ordered by created_at, so it went red on the very commit that fixed the bug.
 * It now asserts what #120 introduced — each header asks for its own sort key, and a
 * second click flips the direction. The proof that created_by really orders by admin
 * name (which needs two admins, and so a second login the browser session cannot
 * survive) lives in tests/api/settlements-sort.spec.ts.
 *
 * E2E Patterns applied:
 *  - Pattern 001: unique test data per test (its own settlements)
 *  - Pattern 003/004: database-agnostic assertions. The settlements list has no
 *    filter that isolates one test's rows, so ordering is asserted over the whole
 *    fetched page instead of by locating this test's rows within it. The previous
 *    version searched for its own rows on page 1, which quietly assumed the table
 *    was smaller than a page: as soon as the ascending page filled with older rows
 *    its settlements were no longer on it, and it failed with a -1 index that said
 *    nothing about sorting.
 */

import { Page } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'
import { SettlementsPage } from '../../pages/SettlementsPage'

/** settlements.created_at is a TIMESTAMP with 1-second resolution and no secondary
 *  sort key, so two rows created within the same second order unpredictably. */
const ONE_SECOND_APART = 1100

interface SettlementListRow {
  id: string
  created_at: string
  created_by_admin_name: string | null
}

interface CapturedList {
  url: string
  rows: SettlementListRow[]
}

/** Wait for the next settlements list fetch while `action` triggers it. */
async function captureList(page: Page, action: () => Promise<void>): Promise<CapturedList> {
  const responsePromise = page.waitForResponse(
    (resp) =>
      resp.url().includes('/api/admin/settlements?') &&
      resp.request().method() === 'GET' &&
      resp.status() === 200
  )
  await action()
  const response = await responsePromise
  const body = await response.json()
  return { url: response.url(), rows: (body.data ?? []) as SettlementListRow[] }
}

/**
 * Assert the page came back ordered by `field`.
 *
 * Comparison is case-insensitive because the columns are collated
 * utf8mb4_unicode_ci, and a missing display name (LEFT JOIN on a deleted admin)
 * is treated as the empty string, which sorts where MySQL puts NULL: first.
 */
function expectOrderedBy(
  rows: SettlementListRow[],
  field: 'created_at' | 'created_by_admin_name',
  direction: 'asc' | 'desc',
  context: string
) {
  const values = rows.map((row) => (row[field] ?? '').toLowerCase())
  const expected = [...values].sort()
  if (direction === 'desc') expected.reverse()
  expect(values, `${context}: page is not ordered by ${field} ${direction}`).toEqual(expected)
}

function indexOfId(rows: SettlementListRow[], id: string): number {
  return rows.findIndex((row) => row.id === id)
}

/** Widen the page so freshly created rows share it with other workers' rows. */
async function selectLargestPageSize(page: Page): Promise<CapturedList> {
  return captureList(page, async () => {
    await page.getByTestId('settlements-page-size-select').selectOption('100')
  })
}

test.describe('Settlements list sort order', () => {
  test('the Date header sorts by created_at and a second click flips the direction', async ({
    page,
    settlementFactory,
  }) => {
    const older = await settlementFactory.create()
    await page.waitForTimeout(ONE_SECOND_APART)
    const newer = await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Default sort is created_at desc, so the two newest rows in the table are
    // this test's own — the one case where looking for them is sound.
    const initial = await selectLargestPageSize(page)
    expectOrderedBy(initial.rows, 'created_at', 'desc', 'default')
    const iNewer = indexOfId(initial.rows, newer.id)
    const iOlder = indexOfId(initial.rows, older.id)
    expect(iNewer, 'default (desc): expected the newer settlement on the first page').toBeGreaterThanOrEqual(0)
    expect(iOlder, 'default (desc): expected the older settlement on the first page').toBeGreaterThanOrEqual(0)
    expect(iNewer, 'default (desc): newer settlement should sort first').toBeLessThan(iOlder)

    // Clicking the column already sorted desc flips it to asc.
    const ascending = await captureList(page, async () => {
      await page.getByTestId('settlements-header-date').click()
    })
    expect(ascending.url, 'Date header should request the created_at sort').toContain('sort=created_at')
    expect(ascending.url, 'Date header should flip to ascending').toContain('order=asc')
    expectOrderedBy(ascending.rows, 'created_at', 'asc', 'after clicking Date')
  })

  test('the Created By header sorts by created_by, not by date', async ({
    page,
    settlementFactory,
  }) => {
    await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await selectLargestPageSize(page)

    // The regression #120 fixed lives here: the header used to move its arrow
    // while the request still asked for the created_at sort.
    const ascending = await captureList(page, async () => {
      await page.getByTestId('settlements-header-created-by').click()
    })
    expect(ascending.url, 'Created By header should request the created_by sort').toContain('sort=created_by')
    expect(ascending.url, 'first click on a new column should sort ascending').toContain('order=asc')
    expectOrderedBy(ascending.rows, 'created_by_admin_name', 'asc', 'after clicking Created By')

    // Clicking the same column again flips the direction.
    const descending = await captureList(page, async () => {
      await page.getByTestId('settlements-header-created-by').click()
    })
    expect(descending.url, 'Created By should stay the sort column').toContain('sort=created_by')
    expect(descending.url, 'second click should flip to descending').toContain('order=desc')
    expectOrderedBy(descending.rows, 'created_by_admin_name', 'desc', 'after clicking Created By again')
  })
})
