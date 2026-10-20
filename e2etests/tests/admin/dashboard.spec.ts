import { test, expect } from '../../fixtures/pageObjects'

test.describe('Admin Dashboard Page (UC-A80)', () => {

  test('displays dashboard page with all sections', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectMetricsVisible()
    await authenticatedDashboardPage.expectRecentTransactionsVisible()
    await authenticatedDashboardPage.expectTerminalStatusVisible()
    await authenticatedDashboardPage.expectAlertsVisible()
    await authenticatedDashboardPage.expectSystemStatusVisible()
  })

  test('displays 3 stat cards in metrics section', async ({ authenticatedDashboardPage, page }) => {
    await authenticatedDashboardPage.expectPageVisible()

    const metricsSection = page.getByTestId('dashboard-metrics')
    // Each StatCard has child elements (icon-container, label, value) that also start with "stat-card-".
    // Use :not() to exclude children and count only top-level card containers.
    const statCards = metricsSection.locator('[data-testid^="stat-card-"]:not([data-testid$="-icon-container"]):not([data-testid$="-label"]):not([data-testid$="-value"])')
    await expect(statCards).toHaveCount(3)
  })

  test('displays recent transactions from API', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectRecentTransactionsVisible()

    const count = await authenticatedDashboardPage.getTransactionCount()
    expect(count).toBeGreaterThanOrEqual(0)
    expect(count).toBeLessThanOrEqual(10)
  })

  test('displays terminal status entries', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectTerminalStatusVisible()

    const count = await authenticatedDashboardPage.getTerminalCount()
    expect(count).toBeGreaterThanOrEqual(0)
  })

  test('displays SEPA alert with severity', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectAlertsVisible()

    const message = await authenticatedDashboardPage.getSepaAlertMessage()
    expect(message.length).toBeGreaterThan(0)
  })

  test('displays system status fields', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectSystemStatusVisible()

    await authenticatedDashboardPage.expectSystemStatusField('last-settlement')
    await authenticatedDashboardPage.expectSystemStatusField('pending-settlements')
    await authenticatedDashboardPage.expectSystemStatusField('total-members')
    await authenticatedDashboardPage.expectSystemStatusField('total-transactions')
    await authenticatedDashboardPage.expectSystemStatusField('database-health')
  })

  test('transaction count names the 30-day window it covers', async ({ authenticatedDashboardPage }) => {
    // The count is the last 30 days, not every booking ever made. Labelled
    // "Total Transactions" it read as the whole journal, and an admin
    // comparing it with the journal's 3-month default saw two numbers that
    // could not both be right. The label must say which window it counts.
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectSystemStatusLabelToContain('total-transactions', '30')
  })

  test('dashboard auto-refreshes data', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.waitForRefresh()
    await authenticatedDashboardPage.expectMetricsVisible()
  })

  test('dashboard is the post-login landing page', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' })
    await page.waitForURL('**/dashboard', { timeout: 5000 })
    await expect(page.getByTestId('dashboard-page')).toBeVisible()
  })

  test('dashboard nav item is visible and navigable', async ({ authenticatedDashboardPage, page }) => {
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="members-page"]', { timeout: 5000 })

    await page.locator('[data-testid="nav-dashboard"]').click()
    await page.waitForURL('**/dashboard', { timeout: 5000 })
    await expect(page.getByTestId('dashboard-page')).toBeVisible()
  })
})
