/**
 * Dashboard — WTD/MTD revenue and per-transaction terminal name (UC-A80)
 *
 * dashboard.spec.ts covers structural presence of the dashboard's sections but never
 * asserts on the wtd_revenue_cents/mtd_revenue_cents stat cards or on terminal_name in
 * the recent-transactions list — both of which used to be read through `(metrics as any)`
 * / `(tx: any)` casts in DashboardPage.tsx before the OpenAPI spec declared them.
 *
 * Two tests, two different strategies:
 *  - Revenue: a real purchase against the live backend, asserted as a monotonic
 *    before/after delta. WTD/MTD are cumulative sums for the current week/month, so a
 *    fresh purchase can only push them up — safe under concurrent writes from other
 *    workers (Pattern 004), no test.skip() needed.
 *  - terminal_name: the dashboard's recent_transactions is a global "last 10 across the
 *    whole system" feed with no per-item lookup, so a real purchase from this test could
 *    be pushed out of that window by unrelated parallel activity before the page reloads.
 *    Asserting on it for real would mean either a flaky test or a data-dependent
 *    test.skip() — the latter is a banned pattern in this suite (ESLint rule
 *    clubbar/no-data-dependent-skip, ruling #146: it lets a real wiring break through
 *    the API pass silently as "skipped" instead of failing). Mocking the dashboard
 *    response (same technique as silent-failures.spec.ts) keeps the
 *    assertion on the exact rendering code this issue changed, deterministically.
 */

import { Page } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'
import { DashboardPage } from '../../pages/DashboardPage'

/** Parse an Intl.NumberFormat('de-DE', {style:'currency', currency:'EUR'}) string back to cents. */
function parsePriceCents(text: string): number {
  const cleaned = text.replace(/[^\d,.-]/g, '')
  const normalized = cleaned.replace(/\./g, '').replace(',', '.')
  return Math.round(parseFloat(normalized) * 100)
}

async function gotoDashboard(page: Page): Promise<DashboardPage> {
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('[data-testid="dashboard-page"]', { timeout: 5000 })
  return new DashboardPage(page)
}

test.describe('Dashboard revenue and terminal name', () => {
  test('WTD/MTD revenue grows by at least a fresh purchase amount', async ({ page, testTransactions }) => {
    const dashboardPage = await gotoDashboard(page)
    await dashboardPage.expectMetricsVisible()

    const wtdValue = page.getByTestId('stat-card-woche-(wtd)-value')
    const mtdValue = page.getByTestId('stat-card-monat-(mtd)-value')
    await expect(wtdValue).toBeVisible()
    await expect(mtdValue).toBeVisible()

    const wtdBefore = parsePriceCents((await wtdValue.textContent()) ?? '')
    const mtdBefore = parsePriceCents((await mtdValue.textContent()) ?? '')

    const member = await testTransactions.createMember('DashboardRevenue', 'E2E', 'dashboard-revenue')
    const amountCents = 4321
    await testTransactions.createSyncTransaction(member.id, amountCents, 'Dashboard revenue e2e test')

    await page.reload({ waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="dashboard-page"]', { timeout: 5000 })
    await dashboardPage.expectMetricsVisible()

    const wtdAfter = parsePriceCents((await wtdValue.textContent()) ?? '')
    const mtdAfter = parsePriceCents((await mtdValue.textContent()) ?? '')
    expect(wtdAfter).toBeGreaterThanOrEqual(wtdBefore + amountCents)
    expect(mtdAfter).toBeGreaterThanOrEqual(mtdBefore + amountCents)
  })

  test('shows the terminal name on a recent transaction row, and colours amounts per ADR-0042', async ({ page }) => {
    const mockTerminalName = 'Mock Bar Terminal'
    const mockTxId = '11111111-1111-4111-8111-111111111111'
    const mockStornoId = '33333333-3333-4333-8333-333333333333'

    await page.route('**/api/admin/dashboard', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          metrics: {
            active_members: 1,
            inactive_members: 0,
            outstanding_balance_cents: 0,
            todays_revenue_cents: 500,
            wtd_revenue_cents: 500,
            mtd_revenue_cents: 500,
            terminal_count: 1,
            active_terminals: 1,
            settled_members: 0,
            sepa_issue_count: 0,
          },
          recent_transactions: [
            {
              id: mockTxId,
              member_id: '22222222-2222-4222-8222-222222222222',
              member_name: 'Mock Member',
              terminal_name: mockTerminalName,
              type: 'purchase',
              amount_cents: 500,
              product_name: 'Mock Product',
              timestamp: new Date().toISOString(),
            },
            {
              id: mockStornoId,
              member_id: '22222222-2222-4222-8222-222222222222',
              member_name: 'Mock Member',
              terminal_name: mockTerminalName,
              type: 'storno',
              amount_cents: -500,
              product_name: 'Mock Product',
              timestamp: new Date().toISOString(),
            },
          ],
          terminal_status: [],
          system_status: {
            last_settlement_date: null,
            pending_settlement_count: 0,
            total_members: 1,
            total_transactions: 1,
            database_health: 'ok',
          },
          alerts: {
            sepa_issues: { count: 0, severity: 'none', message: 'No SEPA data issues' },
          },
        }),
      })
    })

    const dashboardPage = await gotoDashboard(page)
    await dashboardPage.expectRecentTransactionsVisible()

    const row = page.getByTestId(`dashboard-transaction-${mockTxId}`)
    await expect(row).toBeVisible()
    await expect(row).toContainText(mockTerminalName)

    // ADR-0042: an everyday purchase is not an error, so it is not red — it
    // stays in the primary text colour, and only money in the member's favour
    // is green. Pinned here and not just in the unit test because this is the
    // rule that has drifted three times (#28, #93, #376) and what the reader
    // actually sees is the rendered colour.
    await dashboardPage.expectTransactionAmountNeutral(mockTxId)
    await dashboardPage.expectTransactionAmountCredit(mockStornoId)
  })
})
