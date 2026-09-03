/**
 * The dashboard states a sale's time on the club's clock (#365).
 *
 * The reported bug, exactly: a sale rung up at 20:42 in the clubhouse was
 * listed as 18:42 under "Letzte Buchungen", while the journal — the same row,
 * the same browser — said 20:42. The API had shipped the dashboard's instant
 * without its `Z`, so the browser read it as its own local time.
 *
 * Both halves of the fix are asserted here because only their combination is
 * visible end to end: the backend labelling the instant, and the panel
 * converting it with the zone `GET /api/instance-config` reports rather than
 * with whatever zone the reader's machine is in. The response is mocked for the
 * reason dashboard-revenue.spec.ts documents — recent_transactions is a global
 * "last 10 across the system" feed, so a real purchase can be pushed out of the
 * window by a parallel worker before the page reloads — and mocking is what
 * lets the instant be a fixed, known one instead of `now`.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { DashboardPage } from '../../pages/DashboardPage'


/** Serve a chosen instance config, so both fallback states can be exercised. */
async function mockInstanceConfig(
  page: import('@playwright/test').Page,
  body: { time_zone: string; time_zone_source: string }
): Promise<void> {
  await page.route('**/api/instance-config', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ instance_name: 'Club Bar', ...body }),
    })
  })
}

const TX_ID = '33333333-3333-4333-8333-333333333333'

/** 18:42:12 UTC — 20:42 in Berlin, which is when it was actually rung up. */
const SOLD_AT = '2026-09-02T18:42:12Z'

test.describe('Dashboard clock', () => {
  test('a sale is listed at the time the club rang it up, not at its UTC time', async ({ page }) => {
    // Deriving the expectation the way the app derives it would let this pass
    // vacuously, so it is pinned to the concrete case from the bug report. The
    // precondition is asserted rather than assumed: a deployment that changes
    // its zone should fail here and be updated deliberately.
    const config = await page.request.get('/api/instance-config').then((r) => r.json())
    expect(config.time_zone).toBe('Europe/Berlin')

    await page.route('**/api/admin/dashboard', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          metrics: {
            active_members: 1,
            inactive_members: 0,
            outstanding_balance_cents: 0,
            todays_revenue_cents: 150,
            wtd_revenue_cents: 150,
            mtd_revenue_cents: 150,
            terminal_count: 0,
            active_terminals: 0,
            settled_members: 0,
            sepa_issue_count: 0,
          },
          recent_transactions: [
            {
              id: TX_ID,
              member_id: '22222222-2222-4222-8222-222222222222',
              member_name: 'Anna Glöckner',
              terminal_name: 'Küche',
              type: 'purchase',
              amount_cents: 150,
              product_name: 'Alkoholfreies Bier (0,5l)',
              timestamp: SOLD_AT,
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

    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    const dashboardPage = new DashboardPage(page)
    await dashboardPage.expectRecentTransactionsVisible()

    // Berlin is +02:00 in September. Before the fix this read 18:42.
    expect(await dashboardPage.getTransactionTime(TX_ID)).toBe('02.09.2026, 20:42')
  })

  /**
   * A club that never stated its zone is reading its books on Berlin's clock by
   * accident. The fallback has to stay silent where it is used — a mail with
   * the wrong hour still reaches somebody, one that throws reaches nobody — so
   * the panel is the only place it can be reported, and a wrong hour looks
   * exactly like a right one on every screen that shows it.
   */
  test('an unconfigured zone is called out on the dashboard', async ({ page }) => {
    await mockInstanceConfig(page, { time_zone: 'Europe/Berlin', time_zone_source: 'default' })

    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })

    const warning = page.getByTestId('dashboard-timezone-warning')
    await expect(warning).toBeVisible()
    await expect(warning).toHaveAttribute('data-severity', 'warning')
    await expect(warning).toContainText('Europe/Berlin')
  })

  /** A typo is worse than silence: the club tried, and still has it wrong. */
  test('a zone that is not a zone is called out more loudly', async ({ page }) => {
    await mockInstanceConfig(page, { time_zone: 'Europe/Berlin', time_zone_source: 'invalid' })

    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })

    const warning = page.getByTestId('dashboard-timezone-warning')
    await expect(warning).toBeVisible()
    await expect(warning).toHaveAttribute('data-severity', 'error')
  })

  /** A club that chose its zone is told nothing — silence has to mean something. */
  test('a configured zone says nothing at all', async ({ page }) => {
    await mockInstanceConfig(page, { time_zone: 'Europe/Vienna', time_zone_source: 'configured' })

    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })

    await expect(page.getByTestId('dashboard-page')).toBeVisible()
    await expect(page.getByTestId('dashboard-timezone-warning')).toHaveCount(0)
  })
})
