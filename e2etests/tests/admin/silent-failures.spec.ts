/**
 * Silent failures — a failed request must not read as a legitimate empty state (#132)
 *
 * Each test forces one request to fail and asserts the page says so, rather than
 * rendering a number, a dropdown or a table that looks like real data:
 *
 *  - Members stat cards render "—", not "0" / "0,00 €", when the dashboard
 *    metrics call fails. A treasurer reads "0,00 €" as "nothing outstanding".
 *  - Products reports a failed category load, on the page and next to the
 *    (now empty) select in the form that requires a category.
 *  - Journal keeps a way back after a load failure instead of stranding the
 *    admin on "Error: …" until they happen to change a filter.
 *  - Dashboard admits its figures are stale when an auto-refresh fails, rather
 *    than re-rendering the last good numbers indefinitely.
 *
 * Failures are injected with page.route() — the same technique as
 * dashboard-revenue.spec.ts and mandate-document-extraction.spec.ts. Route
 * handlers are per-page and torn down with the page, so nothing leaks into a
 * parallel worker (Pattern 004), and no test data is created or mutated
 * (Pattern 001).
 *
 * Implements E2E Testing Patterns 001, 004, 005, 006, 008.
 */

import { Page } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'
import { ProductsPage } from '../../pages/ProductsPage'
import { JournalPage } from '../../pages/JournalPage'
import { DashboardPage } from '../../pages/DashboardPage'

/** Fail every call to `urlGlob` with a 500 until `page.unroute` removes it. */
async function failRequests(page: Page, urlGlob: string) {
  await page.route(urlGlob, async (route) => {
    await route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'server_error', message: 'Injected failure (#132 e2e)' }),
    })
  })
}

test.describe('Silent failures are reported, not disguised', () => {
  test('members stat cards show "—" when the metrics request fails, and recover on retry', async ({
    page,
  }) => {
    await failRequests(page, '**/api/admin/dashboard*')

    const membersPage = new MembersPage(page)
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // The whole point: an unknown balance must not be rendered as a zero one.
    await expect
      .poll(() => membersPage.getMemberCountCardValue(), { timeout: 10000 })
      .toBe('—')
    expect(await membersPage.getOpenBalanceCardValue()).toBe('—')
    await membersPage.expectMetricsErrorVisible()

    // The member list is a separate stream and stays usable.
    await membersPage.expectListSettledWithoutError()

    // Let the real endpoint through again; retry must fill the cards in.
    await page.unroute('**/api/admin/dashboard*')
    await membersPage.clickMetricsRetry()

    await membersPage.expectMetricsErrorHidden()
    await membersPage.waitForStatsToLoad()
    expect(await membersPage.getMemberCountCardValue()).toMatch(/\d/)
  })

  test('members shows the page error banner when the member list fails to load', async ({
    page,
  }) => {
    await failRequests(page, '**/api/admin/members*')

    const membersPage = new MembersPage(page)
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    await membersPage.expectErrorMessageVisible()
  })

  test('products reports a failed category load on the page and in the form', async ({ page }) => {
    await failRequests(page, '**/api/admin/categories*')

    const productsPage = new ProductsPage(page)
    await productsPage.navigate()
    await productsPage.expectPageVisible()

    await productsPage.expectCategoriesErrorVisible()

    // The form *requires* a category, so the empty select needs its own
    // explanation — the page banner is behind the modal overlay.
    await productsPage.openCreateModal()
    await productsPage.expectFormModalVisible()
    await productsPage.expectFormCategoriesErrorVisible()
  })

  test('journal offers a retry after a load failure instead of a dead end', async ({ page }) => {
    await failRequests(page, '**/api/admin/transactions*')

    const journalPage = new JournalPage(page)
    await journalPage.navigate()

    await journalPage.expectErrorMessageVisible()
    await journalPage.expectRetryButtonVisible()

    await page.unroute('**/api/admin/transactions*')
    await journalPage.clickRetry()

    // Retry re-runs the current query — the table (or a genuine empty state)
    // comes back without the admin having to touch a filter.
    await journalPage.waitForTableToLoad()
  })

  test('dashboard says its figures are stale when an auto-refresh fails', async ({ page }) => {
    const dashboardPage = new DashboardPage(page)
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await dashboardPage.expectPageVisible()
    await dashboardPage.expectStaleWarningHidden()

    // Break only the refreshes that follow the successful first load.
    await failRequests(page, '**/api/admin/dashboard*')

    // The page keeps rendering the numbers it already has — correct, as long as
    // it says how old they are. AUTO_REFRESH_INTERVAL is 10s.
    await dashboardPage.expectStaleWarningVisible()
    await dashboardPage.expectMetricsVisible()
    expect(await dashboardPage.getStaleWarningText()).not.toBe('')
  })
})
