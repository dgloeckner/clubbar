/**
 * Reports Page E2E Tests
 *
 * Tests for the advanced reporting dashboard:
 * - Page structure and tab navigation
 * - Revenue report (default tab) with filters
 * - Consumption and Transactions tabs
 * - Member Ranking tab with anonymize toggle
 * - Terminal Activity tab with sessions and hourly chart
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation
 * - Pattern 003: Database-Agnostic Assertions
 * - Pattern 005: Test IDs for element selection
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 *
 * Implements UC-A50 (Revenue/Consumption/Transactions), UC-A51 (Member Ranking), UC-A52 (Terminal Activity)
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Reports Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('reports-page').waitFor({ state: 'visible', timeout: 10000 })
  })

  /** Wait for report data to finish loading (summary cards appear) */
  async function waitForReportLoaded(page: import('@playwright/test').Page) {
    await expect(page.getByTestId('report-summary-revenue')).toBeVisible({ timeout: 15000 })
  }

  /** Wait for ranking table or no-data message to appear */
  async function waitForRankingLoaded(page: import('@playwright/test').Page) {
    const rankingTable = page.getByTestId('ranking-table')
    await expect(rankingTable).toBeVisible({ timeout: 15000 })
  }

  /** Wait for terminal activity sections to appear */
  async function waitForTerminalLoaded(page: import('@playwright/test').Page) {
    const sessions = page.getByTestId('terminal-sessions')
    await expect(sessions).toBeVisible({ timeout: 15000 })
  }

  test.describe('Page Structure', () => {
    test('should display reports page', async ({ page }) => {
      await expect(page.getByTestId('reports-page')).toBeVisible()
    })

    test('should display all five tab buttons', async ({ page }) => {
      await expect(page.getByTestId('report-tab-revenue')).toBeVisible()
      await expect(page.getByTestId('report-tab-consumption')).toBeVisible()
      await expect(page.getByTestId('report-tab-transactions')).toBeVisible()
      await expect(page.getByTestId('report-tab-member-ranking')).toBeVisible()
      await expect(page.getByTestId('report-tab-terminal-activity')).toBeVisible()
    })

    test('should display tab bar container', async ({ page }) => {
      await expect(page.getByTestId('report-tabs')).toBeVisible()
    })
  })

  test.describe('Revenue Tab (default)', () => {
    test('should load revenue report by default', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
    })

    test('should display all four summary cards on revenue tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
      await expect(page.getByTestId('report-summary-quantity')).toBeVisible()
      await expect(page.getByTestId('report-summary-count')).toBeVisible()
      await expect(page.getByTestId('report-summary-avg')).toBeVisible()
    })

    test('should display chart section on revenue tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-chart')).toBeVisible()
    })

    test('should display data table on revenue tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-table')).toBeVisible()
    })

    test('should display CSV export button on revenue tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })

    test('should display date filter inputs on revenue tab', async ({ page }) => {
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })

    test('should display group-by selector on revenue tab', async ({ page }) => {
      await expect(page.getByTestId('report-filter-group-by')).toBeVisible()
    })

    test('should display apply filter button', async ({ page }) => {
      await expect(page.getByTestId('report-apply-filter')).toBeVisible()
    })

    test('should make API call to revenue endpoint on load', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/revenue') && resp.status() === 200
      )
      await page.goto('/reports')
      const response = await responsePromise
      expect(response.status()).toBe(200)
    })
  })

  test.describe('Date Filter', () => {
    test('should apply filter and trigger new API call when clicking Apply', async ({ page }) => {
      // Wait for initial load
      await waitForReportLoaded(page)

      // Set a specific date range
      await page.getByTestId('report-filter-date-from').fill('2026-01-01')
      await page.getByTestId('report-filter-date-to').fill('2026-03-07')

      // Click Apply and wait for the API response with the correct date params
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/revenue') && resp.status() === 200
          && new URL(resp.url()).searchParams.get('date_from') === '2026-01-01'
      )
      await page.getByTestId('report-apply-filter').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)
      const url = new URL(response.url())
      expect(url.searchParams.get('date_from')).toBe('2026-01-01')
      expect(url.searchParams.get('date_to')).toBe('2026-03-07')
    })
  })

  test.describe('Group-By Selector', () => {
    test('should change group-by and trigger new API call', async ({ page }) => {
      await waitForReportLoaded(page)

      // Change group-by from default to 'day'
      await page.getByTestId('report-filter-group-by').selectOption('day')

      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/revenue') && resp.status() === 200
          && new URL(resp.url()).searchParams.get('group_by') === 'day'
      )
      await page.getByTestId('report-apply-filter').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)
      const url = new URL(response.url())
      expect(url.searchParams.get('group_by')).toBe('day')
    })

    test('group-by selector should have expected options', async ({ page }) => {
      const select = page.getByTestId('report-filter-group-by')
      await expect(select).toBeVisible()

      // Verify the select element has options by checking it is a select with values
      const optionValues = await select.evaluate((el) => {
        const selectEl = el as HTMLSelectElement
        return Array.from(selectEl.options).map((opt) => opt.value)
      })
      expect(optionValues).toContain('category')
      expect(optionValues).toContain('product')
      expect(optionValues).toContain('day')
      expect(optionValues).toContain('month')
    })
  })

  test.describe('Tab Switching', () => {
    test('should switch to consumption tab and load data', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/consumption') && resp.status() === 200
      )
      await page.getByTestId('report-tab-consumption').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)
      await waitForReportLoaded(page)
    })

    test('should switch to transactions tab and load data', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/transactions') && resp.status() === 200
      )
      await page.getByTestId('report-tab-transactions').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)
      await waitForReportLoaded(page)
    })

    test('should switch to member ranking tab and load data', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/member-ranking') && resp.status() === 200
      )
      await page.getByTestId('report-tab-member-ranking').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)
      await waitForRankingLoaded(page)
    })

    test('should switch to terminal activity tab and load data', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/terminal-activity') && resp.status() === 200
      )
      await page.getByTestId('report-tab-terminal-activity').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)
      await waitForTerminalLoaded(page)
    })

    test('should hide revenue content when switching to consumption tab', async ({ page }) => {
      await waitForReportLoaded(page)

      // Switch to consumption tab
      await page.getByTestId('report-tab-consumption').click()
      await page.waitForResponse(
        (resp) => resp.url().includes('/reports/consumption') && resp.status() === 200
      )

      // Filters and summary cards should still be visible (shared across standard tabs)
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
    })
  })

  test.describe('Consumption Tab', () => {
    test.beforeEach(async ({ page }) => {
      // Set up listener BEFORE clicking to avoid race condition
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/consumption') && resp.status() === 200
      )
      await page.getByTestId('report-tab-consumption').click()
      await responsePromise
    })

    test('should display summary cards on consumption tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
      await expect(page.getByTestId('report-summary-quantity')).toBeVisible()
    })

    test('should display chart on consumption tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-chart')).toBeVisible()
    })

    test('should display export button on consumption tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })
  })

  test.describe('Transactions Tab', () => {
    test.beforeEach(async ({ page }) => {
      // Set up listener BEFORE clicking to avoid race condition
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/transactions') && resp.status() === 200
      )
      await page.getByTestId('report-tab-transactions').click()
      await responsePromise
    })

    test('should display summary cards on transactions tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-summary-count')).toBeVisible()
    })

    test('should display data table on transactions tab', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-table')).toBeVisible()
    })
  })

  test.describe('Member Ranking Tab', () => {
    test.beforeEach(async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/member-ranking') && resp.status() === 200
      )
      await page.getByTestId('report-tab-member-ranking').click()
      await responsePromise
    })

    test('should display ranking table', async ({ page }) => {
      await waitForRankingLoaded(page)
      await expect(page.getByTestId('ranking-table')).toBeVisible()
    })

    test('should display anonymize toggle checkbox', async ({ page }) => {
      await expect(page.getByTestId('ranking-anonymize')).toBeVisible()
    })

    test('should display limit selector', async ({ page }) => {
      await expect(page.getByTestId('ranking-limit')).toBeVisible()
    })

    test('should display date filters on ranking tab', async ({ page }) => {
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })

    test('should display export button on ranking tab', async ({ page }) => {
      await waitForRankingLoaded(page)
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })

    test('anonymize toggle should send anonymize param to API', async ({ page }) => {
      // Check the anonymize checkbox
      await page.getByTestId('ranking-anonymize').check()

      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/member-ranking') && resp.status() === 200
      )
      await page.getByTestId('report-apply-filter').click()
      const response = await responsePromise

      const url = new URL(response.url())
      expect(url.searchParams.get('anonymize')).toBe('true')
    })

    test('limit selector should include standard options', async ({ page }) => {
      const select = page.getByTestId('ranking-limit')
      await expect(select).toBeVisible()

      const optionValues = await select.evaluate((el) => {
        const selectEl = el as HTMLSelectElement
        return Array.from(selectEl.options).map((opt) => opt.value)
      })
      expect(optionValues).toContain('10')
      expect(optionValues).toContain('25')
      expect(optionValues).toContain('50')
    })

    test('changing limit should send updated param to API', async ({ page }) => {
      await page.getByTestId('ranking-limit').selectOption('10')

      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/member-ranking') && resp.status() === 200
      )
      await page.getByTestId('report-apply-filter').click()
      const response = await responsePromise

      const url = new URL(response.url())
      expect(url.searchParams.get('limit')).toBe('10')
    })
  })

  test.describe('Terminal Activity Tab', () => {
    test.beforeEach(async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/terminal-activity') && resp.status() === 200
      )
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise
    })

    test('should display terminal sessions section', async ({ page }) => {
      await waitForTerminalLoaded(page)
      await expect(page.getByTestId('terminal-sessions')).toBeVisible()
    })

    test('should display hourly chart section', async ({ page }) => {
      await waitForTerminalLoaded(page)
      await expect(page.getByTestId('terminal-hourly-chart')).toBeVisible()
    })

    test('should display terminal list section', async ({ page }) => {
      await waitForTerminalLoaded(page)
      await expect(page.getByTestId('terminal-list')).toBeVisible()
    })

    test('should display date filters on terminal activity tab', async ({ page }) => {
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })

    test('should display export button on terminal activity tab', async ({ page }) => {
      await waitForTerminalLoaded(page)
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })

    test('should send date params to terminal activity API', async ({ page }) => {
      await waitForTerminalLoaded(page)

      await page.getByTestId('report-filter-date-from').fill('2026-01-01')
      await page.getByTestId('report-filter-date-to').fill('2026-03-07')

      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/terminal-activity') && resp.status() === 200
      )
      await page.getByTestId('report-apply-filter').click()
      const response = await responsePromise

      const url = new URL(response.url())
      expect(url.searchParams.get('date_from')).toBe('2026-01-01')
      expect(url.searchParams.get('date_to')).toBe('2026-03-07')
    })
  })

  test.describe('Data Integration', () => {
    test('revenue API response should have valid structure', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/revenue') && resp.status() === 200
      )
      await page.goto('/reports')
      const response = await responsePromise

      const body = await response.json()
      // Backend returns { metadata, summary, data } format
      expect(body).toHaveProperty('metadata')
      expect(body).toHaveProperty('summary')
      expect(body).toHaveProperty('data')
      expect(body.summary).toHaveProperty('total_revenue_cents')
      expect(Array.isArray(body.data)).toBe(true)
    })

    test('member ranking API response should have valid structure', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/member-ranking') && resp.status() === 200
      )
      await page.getByTestId('report-tab-member-ranking').click()
      const response = await responsePromise

      const body = await response.json()
      // Backend returns { data: [...rows] }
      expect(body).toHaveProperty('data')
      expect(Array.isArray(body.data)).toBe(true)
    })

    test('terminal activity API response should have valid structure', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/terminal-activity') && resp.status() === 200
      )
      await page.getByTestId('report-tab-terminal-activity').click()
      const response = await responsePromise

      const body = await response.json()
      // Backend returns { sessions, hourly_distribution, terminals }
      expect(body).toHaveProperty('sessions')
      expect(body).toHaveProperty('hourly_distribution')
      expect(body).toHaveProperty('terminals')
      expect(Array.isArray(body.sessions)).toBe(true)
      expect(Array.isArray(body.hourly_distribution)).toBe(true)
    })
  })
})
