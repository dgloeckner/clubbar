/**
 * Reports Page E2E Tests
 *
 * Tests for the advanced reporting dashboard:
 * - Page structure and tab navigation
 * - Revenue report (default tab) with filters
 * - Consumption and Transactions tabs
 * - Member Ranking tab (always anonymous — no display-mode toggle, #177)
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

    test('should display four tab buttons', async ({ page }) => {
      await expect(page.getByTestId('report-tab-revenue')).toBeVisible()
      await expect(page.getByTestId('report-tab-consumption')).toBeVisible()
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
      await expect(page.getByTestId('report-summary-unique-members')).toBeVisible()
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
      await expect(page.getByTestId('report-filter-group-by-trigger')).toBeVisible()
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

    test('should trigger CSV export download when export button is clicked', async ({ page }) => {
      await waitForReportLoaded(page)
      const exportResponsePromise = page.waitForResponse(
        (resp) =>
          resp.url().includes('/reports/revenue/export') &&
          resp.status() === 200,
        { timeout: 15000 }
      )
      await page.getByTestId('report-export-csv').click()
      const exportResponse = await exportResponsePromise
      expect(exportResponse.status()).toBe(200)
      const contentType = exportResponse.headers()['content-type']
      expect(contentType).toMatch(/csv|octet-stream/)
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

      // Open group-by dropdown and select 'day'
      await page.getByTestId('report-filter-group-by-trigger').click()
      await page.getByTestId('report-filter-group-by-option-day').click()

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
      await expect(page.getByTestId('report-filter-group-by-trigger')).toBeVisible()

      // Open dropdown and verify options
      await page.getByTestId('report-filter-group-by-trigger').click()
      await expect(page.getByTestId('report-filter-group-by-option-category')).toBeVisible()
      await expect(page.getByTestId('report-filter-group-by-option-product')).toBeVisible()
      await expect(page.getByTestId('report-filter-group-by-option-day')).toBeVisible()
      await expect(page.getByTestId('report-filter-group-by-option-month')).toBeVisible()
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

    test('consumption tab should load data with count-based chart', async ({ page }) => {
      await waitForReportLoaded(page)

      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/consumption') && resp.status() === 200
      )
      await page.getByTestId('report-tab-consumption').click()
      await responsePromise

      // Filters and chart should still be visible
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-chart')).toBeVisible()
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
      await expect(page.getByTestId('report-summary-unique-members')).toBeVisible()
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

    // #177: the ranking is always anonymous, so there is no display-mode
    // toggle any more — only a note saying the labels are positional.
    test('should not offer a named display mode', async ({ page }) => {
      await waitForRankingLoaded(page)
      await expect(page.getByTestId('ranking-anonymize')).toHaveCount(0)
      await expect(page.getByTestId('ranking-anonymous-note')).toBeVisible()
    })

    test('should display limit selector', async ({ page }) => {
      await expect(page.getByTestId('ranking-limit-trigger')).toBeVisible()
    })

    test('should display date filters on ranking tab', async ({ page }) => {
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })

    test('should display export button on ranking tab', async ({ page }) => {
      await waitForRankingLoaded(page)
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })

    // #94: this button used to hand back the list endpoint's JSON under a .csv
    // name. What lands on disk has to be a CSV.
    test('export downloads an actual CSV, not JSON', async ({ page }) => {
      await waitForRankingLoaded(page)

      const downloadPromise = page.waitForEvent('download', { timeout: 15000 })
      await page.getByTestId('report-export-csv').click()
      const download = await downloadPromise

      expect(download.suggestedFilename()).toMatch(/^report-member-ranking-.*\.csv$/)

      const path = await download.path()
      expect(path).not.toBeNull()
      const fs = await import('fs')
      const content = fs.readFileSync(path as string, 'utf-8')

      expect(content.split('\n')[0]).toBe('Rank;Member;Total EUR;Transactions')
      expect(content.startsWith('{')).toBe(false)
      await expect(page.getByTestId('report-export-error')).toBeHidden()
    })

    test('should never ask the API for a named ranking', async ({ page }) => {
      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/member-ranking') && resp.status() === 200
      )
      await page.getByTestId('report-apply-filter').click()
      const response = await responsePromise

      const url = new URL(response.url())
      expect(url.searchParams.has('anonymize')).toBe(false)

      const body = await response.json()
      for (const row of body.data) {
        expect(row.member_name).toBe(`Member ${row.rank}`)
      }
    })

    test('should show positional labels in the table, not member names', async ({ page }) => {
      await waitForRankingLoaded(page)

      const firstRow = page.getByTestId('report-row-0')
      await expect(firstRow).toBeVisible()
      await expect(firstRow.locator('td').nth(1)).toHaveText('Member 1')
    })

    test('limit selector should include standard options', async ({ page }) => {
      await expect(page.getByTestId('ranking-limit-trigger')).toBeVisible()

      // Open dropdown and verify options
      await page.getByTestId('ranking-limit-trigger').click()
      await expect(page.getByTestId('ranking-limit-option-10')).toBeVisible()
      await expect(page.getByTestId('ranking-limit-option-25')).toBeVisible()
      await expect(page.getByTestId('ranking-limit-option-50')).toBeVisible()
    })

    test('changing limit should send updated param to API', async ({ page }) => {
      // Open dropdown and select 10
      await page.getByTestId('ranking-limit-trigger').click()
      await page.getByTestId('ranking-limit-option-10').click()

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

    // #94: same bug as the ranking tab — the download was the list endpoint's
    // JSON wearing a .csv name.
    test('export downloads an actual CSV, not JSON', async ({ page }) => {
      await waitForTerminalLoaded(page)

      const downloadPromise = page.waitForEvent('download', { timeout: 15000 })
      await page.getByTestId('report-export-csv').click()
      const download = await downloadPromise

      expect(download.suggestedFilename()).toMatch(/^report-terminal-activity-.*\.csv$/)

      const path = await download.path()
      expect(path).not.toBeNull()
      const fs = await import('fs')
      const content = fs.readFileSync(path as string, 'utf-8')

      expect(content).toContain('Sessions\nDate;Start;End;Transactions;Revenue EUR')
      expect(content).toContain('Hourly Distribution\nHour;Transactions')
      expect(content).toContain('Terminals\nTerminal;Transactions;Last Sync')
      expect(content.startsWith('{')).toBe(false)
      await expect(page.getByTestId('report-export-error')).toBeHidden()
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
      // Let the load started by beforeEach finish before listening for the next
      // response. This test used to arm the waiter and then navigate to
      // /reports a second time, so the waiter could match the *first* page's
      // response — and a navigation discards the body of any response belonging
      // to the document it replaces, which surfaced as "Protocol error
      // (Network.getResponseBody): No resource with given identifier found".
      await waitForReportLoaded(page)

      const responsePromise = page.waitForResponse(
        (resp) => resp.url().includes('/reports/revenue') && resp.status() === 200
      )
      // Re-request in place rather than navigating again: the document stays
      // alive, so its response body stays readable. Same shape as the sibling
      // tests, which trigger their request from the loaded page.
      await page.getByTestId('report-apply-filter').click()
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
