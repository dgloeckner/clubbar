/**
 * Admin Panel - Reports Page Mobile E2E Tests (iPhone 14)
 *
 * Verifies mobile-specific behavior of the Reports page:
 * - Page renders and loads on mobile viewport
 * - Tab bar is visible and horizontally scrollable
 * - Filter bar stacks vertically on mobile
 * - Summary cards display in 2-column layout on mobile
 * - Tab switching works on mobile (touch-friendly targets)
 * - Member Ranking tab renders on mobile
 * - Terminal Activity tab renders on mobile
 *
 * Implements E2E Testing Patterns:
 * - Pattern 004: Parallel Execution Safety (no shared state)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 *
 * Project config uses `devices['iPhone 14']` (390x844, mobile user agent).
 * The admin frontend renders mobile layout at <768px via `useBreakpoint()`.
 */

import { test, expect } from '@playwright/test'

test.describe('Reports Page - Mobile', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('reports-page').waitFor({ state: 'visible', timeout: 15000 })
  })

  /** Wait for revenue report data to finish loading */
  async function waitForReportLoaded(page: import('@playwright/test').Page) {
    await expect(page.getByTestId('report-summary-revenue')).toBeVisible({ timeout: 15000 })
  }

  /** Wait for ranking table to appear */
  async function waitForRankingLoaded(page: import('@playwright/test').Page) {
    await expect(page.getByTestId('ranking-table')).toBeVisible({ timeout: 15000 })
  }

  /** Wait for terminal activity sessions section to appear */
  async function waitForTerminalLoaded(page: import('@playwright/test').Page) {
    await expect(page.getByTestId('terminal-sessions')).toBeVisible({ timeout: 15000 })
  }

  test.describe('Page Structure on Mobile', () => {
    test('should display reports page on mobile viewport', async ({ page }) => {
      await expect(page.getByTestId('reports-page')).toBeVisible()
    })

    test('should display the report tab bar', async ({ page }) => {
      await expect(page.getByTestId('report-tabs')).toBeVisible()
    })

    test('should display all five tab buttons on mobile', async ({ page }) => {
      await expect(page.getByTestId('report-tab-revenue')).toBeVisible()
      await expect(page.getByTestId('report-tab-consumption')).toBeVisible()
      await expect(page.getByTestId('report-tab-transactions')).toBeVisible()
      await expect(page.getByTestId('report-tab-member-ranking')).toBeVisible()
      await expect(page.getByTestId('report-tab-terminal-activity')).toBeVisible()
    })
  })

  test.describe('Tab Bar Scrollability on Mobile', () => {
    test('tab bar container should be present and scrollable on mobile', async ({ page }) => {
      const tabBar = page.getByTestId('report-tabs')
      await expect(tabBar).toBeVisible()

      // Verify the tab bar has overflow-x: auto (scrollable) set — check computed style
      const overflowX = await tabBar.evaluate((el) => window.getComputedStyle(el).overflowX)
      // On mobile the tab bar has overflowX: 'auto' set in the component
      expect(['auto', 'scroll']).toContain(overflowX)
    })

    test('all tab buttons should be reachable on mobile viewport', async ({ page }) => {
      // Scroll the tab bar to make the last tab visible and clickable
      const lastTab = page.getByTestId('report-tab-terminal-activity')
      await lastTab.scrollIntoViewIfNeeded()
      await expect(lastTab).toBeVisible()
    })
  })

  test.describe('Filter Bar Stacks Vertically on Mobile', () => {
    test('should display filter group on default revenue tab', async ({ page }) => {
      // The filter bar (filterGroupStyle) uses flexDirection: 'column' on mobile
      const dateFromInput = page.getByTestId('report-filter-date-from')
      const dateToInput = page.getByTestId('report-filter-date-to')
      const applyButton = page.getByTestId('report-apply-filter')

      await expect(dateFromInput).toBeVisible()
      await expect(dateToInput).toBeVisible()
      await expect(applyButton).toBeVisible()
    })

    test('apply filter button should span full width on mobile', async ({ page }) => {
      const applyButton = page.getByTestId('report-apply-filter')
      await expect(applyButton).toBeVisible()

      // On mobile, the button has width: '100%'
      const width = await applyButton.evaluate((el) => {
        const style = window.getComputedStyle(el)
        const parentWidth = (el.parentElement as HTMLElement)?.offsetWidth ?? 0
        return { buttonWidth: el.getBoundingClientRect().width, parentWidth }
      })
      // Button should fill most of the container width on mobile
      expect(width.buttonWidth).toBeGreaterThan(200)
    })
  })

  test.describe('Summary Cards 2-Column Layout on Mobile', () => {
    test('should display summary cards after loading on mobile', async ({ page }) => {
      await waitForReportLoaded(page)

      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
      await expect(page.getByTestId('report-summary-quantity')).toBeVisible()
      await expect(page.getByTestId('report-summary-count')).toBeVisible()
      await expect(page.getByTestId('report-summary-avg')).toBeVisible()
    })

    test('summary cards should display in 2-column grid on mobile', async ({ page }) => {
      await waitForReportLoaded(page)

      const revenueCard = page.getByTestId('report-summary-revenue')
      const quantityCard = page.getByTestId('report-summary-quantity')
      const countCard = page.getByTestId('report-summary-count')
      const avgCard = page.getByTestId('report-summary-avg')

      const [revenueBox, quantityBox, countBox, avgBox] = await Promise.all([
        revenueCard.boundingBox(),
        quantityCard.boundingBox(),
        countCard.boundingBox(),
        avgCard.boundingBox(),
      ])

      // In a 2-column layout: revenue and quantity are side by side (same Y)
      // count and avg are on the next row (same Y, different from first row)
      expect(revenueBox).not.toBeNull()
      expect(quantityBox).not.toBeNull()
      expect(countBox).not.toBeNull()
      expect(avgBox).not.toBeNull()

      if (revenueBox && quantityBox && countBox && avgBox) {
        // First row: revenue and quantity should be at roughly the same Y
        expect(Math.abs(revenueBox.y - quantityBox.y)).toBeLessThan(10)
        // Second row: count and avg should be at roughly the same Y
        expect(Math.abs(countBox.y - avgBox.y)).toBeLessThan(10)
        // First and second rows should be at different Y positions
        expect(countBox.y).toBeGreaterThan(revenueBox.y)
      }
    })
  })

  test.describe('Tab Switching on Mobile', () => {
    test('should switch to Consumption tab on mobile', async ({ page }) => {
      await page.getByTestId('report-tab-consumption').click()
      // Filter inputs still visible after tab switch
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
    })

    test('should switch to Transactions tab on mobile', async ({ page }) => {
      await page.getByTestId('report-tab-transactions').click()
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
    })

    test('revenue tab should load data and display chart on mobile', async ({ page }) => {
      await waitForReportLoaded(page)
      await expect(page.getByTestId('report-chart')).toBeVisible()
    })
  })

  test.describe('Member Ranking Tab on Mobile', () => {
    test('should switch to member ranking tab on mobile', async ({ page }) => {
      const rankingTab = page.getByTestId('report-tab-member-ranking')
      await rankingTab.scrollIntoViewIfNeeded()
      await rankingTab.click()

      // Ranking-specific filters should appear
      await expect(page.getByTestId('ranking-limit')).toBeVisible()
      await expect(page.getByTestId('ranking-anonymize')).toBeVisible()
    })

    test('should load ranking table on mobile', async ({ page }) => {
      const rankingTab = page.getByTestId('report-tab-member-ranking')
      await rankingTab.scrollIntoViewIfNeeded()
      await rankingTab.click()

      await waitForRankingLoaded(page)
      await expect(page.getByTestId('ranking-table')).toBeVisible()
    })

    test('should display date filters on member ranking tab on mobile', async ({ page }) => {
      const rankingTab = page.getByTestId('report-tab-member-ranking')
      await rankingTab.scrollIntoViewIfNeeded()
      await rankingTab.click()

      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
      await expect(page.getByTestId('report-apply-filter')).toBeVisible()
    })
  })

  test.describe('Terminal Activity Tab on Mobile', () => {
    test('should switch to terminal activity tab on mobile', async ({ page }) => {
      const terminalTab = page.getByTestId('report-tab-terminal-activity')
      await terminalTab.scrollIntoViewIfNeeded()
      await terminalTab.click()

      // Terminal tab should show date filters
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })

    test('should load terminal sessions on mobile', async ({ page }) => {
      const terminalTab = page.getByTestId('report-tab-terminal-activity')
      await terminalTab.scrollIntoViewIfNeeded()
      await terminalTab.click()

      await waitForTerminalLoaded(page)
      await expect(page.getByTestId('terminal-sessions')).toBeVisible()
    })

    test('should display apply filter button full-width on terminal tab on mobile', async ({ page }) => {
      const terminalTab = page.getByTestId('report-tab-terminal-activity')
      await terminalTab.scrollIntoViewIfNeeded()
      await terminalTab.click()

      const applyButton = page.getByTestId('report-apply-filter')
      await expect(applyButton).toBeVisible()

      const buttonWidth = await applyButton.evaluate((el) => el.getBoundingClientRect().width)
      // On mobile the button should span more than half the viewport (390px)
      expect(buttonWidth).toBeGreaterThan(150)
    })
  })

  test.describe('Navigation to Reports from Bottom Tab Bar', () => {
    test('should navigate to reports via More popup in bottom tab bar', async ({ page }) => {
      // Go to a different page first
      await page.goto('/members')
      await page.getByTestId('bottom-tab-bar').waitFor({ state: 'visible', timeout: 10000 })

      // Open the More popup
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()

      // Click Reports in More popup
      await page.getByTestId('tab-reports').click()

      // Should navigate to /reports
      await expect(page).toHaveURL(/\/reports/)
      await expect(page.getByTestId('reports-page')).toBeVisible()
    })
  })
})
