/**
 * Settlements list — sort order (UC-A33)
 *
 * SettlementsPage.tsx used to smuggle a legacy `sort`/`order` pair through an untyped
 * `Record` cast whenever the "Created By" column header was clicked, reaching for a
 * `created_by` sort the repository never implements (SettlementsRepository::listPaginated
 * always orders by created_at, regardless of sort key — only the direction is real).
 * Fixing the OpenAPI spec drift meant removing that cast, which required wiring `order`
 * up properly instead of pretending a `created_by` sort exists. This test proves both
 * column headers actually control the real, shared sort direction — not just their own
 * arrow indicator.
 *
 * E2E Patterns applied:
 *  - Pattern 001: Unique test data per test (own settlements via settlementFactory)
 *  - Pattern 002/004: The settlements list has no per-request filter that isolates this
 *    test's rows from other parallel workers', so the page size is widened to 100 and
 *    both settlement ids are asserted present in the fetched page rather than assumed
 */

import { Page } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'
import { SettlementsPage } from '../../pages/SettlementsPage'

interface SettlementListResponseItem {
  id: string
}

/** Click a sort header, capture the resulting GET /admin/settlements response, return row ids in order. */
async function clickAndGetOrder(page: Page, headerTestId: string): Promise<string[]> {
  const responsePromise = page.waitForResponse(
    (resp) =>
      resp.url().includes('/api/admin/settlements?') &&
      resp.request().method() === 'GET' &&
      resp.status() === 200
  )
  await page.getByTestId(headerTestId).click()
  const response = await responsePromise
  const body = await response.json()
  return ((body.data ?? []) as SettlementListResponseItem[]).map((s) => s.id)
}

function assertOrder(ids: string[], firstId: string, secondId: string, context: string) {
  const iFirst = ids.indexOf(firstId)
  const iSecond = ids.indexOf(secondId)
  expect(iFirst, `${context}: expected settlement ${firstId} in the fetched page`).toBeGreaterThanOrEqual(0)
  expect(iSecond, `${context}: expected settlement ${secondId} in the fetched page`).toBeGreaterThanOrEqual(0)
  expect(iFirst, `${context}: expected ${firstId} to sort before ${secondId}`).toBeLessThan(iSecond)
}

test.describe('Settlements list sort order', () => {
  test('Date and Created By headers both flip the real created_at sort direction', async ({
    page,
    settlementFactory,
  }) => {
    const older = await settlementFactory.create()
    // settlements.created_at is a plain TIMESTAMP (1-second resolution) with no secondary
    // sort key — two settlements created within the same second would sort unpredictably.
    await page.waitForTimeout(1100)
    const newer = await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Widen the page so both settlements are more likely to land on the same page
    // alongside whatever other parallel tests have created.
    const pageSizeResponse = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/settlements?') && resp.status() === 200
    )
    await page.getByTestId('settlements-page-size-select').selectOption('100')
    const initialBody = await (await pageSizeResponse).json()
    const initialIds = ((initialBody.data ?? []) as SettlementListResponseItem[]).map((s) => s.id)

    // Default sort: created_at_desc — the newer settlement sorts first
    assertOrder(initialIds, newer.id, older.id, 'default (desc)')

    // Click "Date" — same column, direction flips to ascending
    const afterDateClick = await clickAndGetOrder(page, 'settlements-header-date')
    assertOrder(afterDateClick, older.id, newer.id, 'after clicking Date (asc)')

    // Click "Created By" — different column, but the backend only ever sorts by
    // created_at; the first click on a new column always starts ascending
    const afterCreatedByClick = await clickAndGetOrder(page, 'settlements-header-created-by')
    assertOrder(afterCreatedByClick, older.id, newer.id, 'after clicking Created By (asc)')

    // Click "Created By" again — direction flips back to descending
    const afterCreatedBySecondClick = await clickAndGetOrder(page, 'settlements-header-created-by')
    assertOrder(afterCreatedBySecondClick, newer.id, older.id, 'after clicking Created By again (desc)')
  })
})
