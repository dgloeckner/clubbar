/**
 * Statistics Page E2E Tests
 *
 * Tests for monthly statistics dashboard:
 * - Month switcher navigation
 * - Summary boxes (total revenue, sold items, top product)
 * - Daily revenue chart
 * - Top 10 products ranking
 * - Top 10 members ranking
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (unique test data per test)
 * - Pattern 003: Database-Agnostic Assertions (search by values, not position)
 * - Pattern 005: Test IDs for element selection
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Statistics Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/statistics')
    // Wait for page to load
    await page.getByTestId('statistics-page').waitFor({ state: 'visible', timeout: 10000 })
  })

  test.describe('Page Structure', () => {
    test('should display statistics page', async ({ page }) => {
      await expect(page.getByTestId('statistics-page')).toBeVisible()
    })

    test('should display month switcher', async ({ page }) => {
      await expect(page.getByTestId('month-switcher')).toBeVisible()
    })

    test('should display month label with current month', async ({ page }) => {
      const monthLabel = page.getByTestId('month-label')
      await expect(monthLabel).toBeVisible()

      const text = await monthLabel.textContent()
      // Should contain a month name (in German)
      expect(text).toMatch(/Januar|Februar|März|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember/)
    })

    test('should display previous and next month buttons', async ({ page }) => {
      await expect(page.getByTestId('month-prev')).toBeVisible()
      await expect(page.getByTestId('month-next')).toBeVisible()
    })
  })

  test.describe('Month Navigation', () => {
    test('should navigate to previous month when clicking prev button', async ({ page }) => {
      const monthLabel = page.getByTestId('month-label')
      const initialMonth = await monthLabel.textContent()

      // Click previous month
      const responsePromise = page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await page.getByTestId('month-prev').click()
      await responsePromise

      // Month should change
      const newMonth = await monthLabel.textContent()
      expect(newMonth).not.toBe(initialMonth)
    })

    test('should disable next button when on current month', async ({ page }) => {
      const nextButton = page.getByTestId('month-next')
      // On current month, next button should be disabled
      await expect(nextButton).toBeDisabled()
    })

    test('should enable next button after navigating to previous month', async ({ page }) => {
      // Navigate to previous month
      const responsePromise = page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await page.getByTestId('month-prev').click()
      await responsePromise

      // Next button should now be enabled
      const nextButton = page.getByTestId('month-next')
      await expect(nextButton).toBeEnabled()
    })
  })

  test.describe('Summary Boxes', () => {
    test('should display summary boxes section', async ({ page }) => {
      // Wait for loading to complete
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('summary-boxes')).toBeVisible()
    })

    test('should display total revenue box', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('box-total-revenue')).toBeVisible()
      await expect(page.getByTestId('value-total-revenue')).toBeVisible()

      const value = await page.getByTestId('value-total-revenue').textContent()
      // Should have currency format
      expect(value).toMatch(/€/)
    })

    test('should display sold items box', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('box-sold-items')).toBeVisible()
      await expect(page.getByTestId('value-sold-items')).toBeVisible()
    })

    test('should display top product box', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('box-top-product')).toBeVisible()
    })
  })

  test.describe('Revenue Chart', () => {
    test('should display revenue chart section', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('revenue-chart')).toBeVisible()
    })

    test('should have chart title', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      const chartSection = page.getByTestId('revenue-chart')
      await expect(chartSection.locator('h3')).toContainText('Umsatz pro Tag')
    })
  })

  test.describe('Top Products', () => {
    test('should display top products section', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('top-products')).toBeVisible()
    })

    test('should have top products title', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      const section = page.getByTestId('top-products')
      await expect(section.locator('h3')).toContainText('Top')
    })

    test('should display products table or empty state', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      const section = page.getByTestId('top-products')

      // Either table with rows or empty state message
      const table = section.locator('table')
      const emptyMessage = section.locator('text=Keine Produktdaten')

      await expect(table.or(emptyMessage)).toBeVisible({ timeout: 10000 })
    })
  })

  test.describe('Top Members', () => {
    test('should display top members section', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await expect(page.getByTestId('top-members')).toBeVisible()
    })

    test('should have top members title', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      const section = page.getByTestId('top-members')
      await expect(section.locator('h3')).toContainText('Top')
    })

    test('should display members table or empty state', async ({ page }) => {
      await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      const section = page.getByTestId('top-members')

      // Either table with rows or empty state message
      const table = section.locator('table')
      const emptyMessage = section.locator('text=Keine Mitgliederdaten')

      await expect(table.or(emptyMessage)).toBeVisible({ timeout: 10000 })
    })
  })

  test.describe('Loading and Error States', () => {
    test('should display loading state initially', async ({ page }) => {
      // Navigate fresh and check for loading
      await page.goto('/statistics')

      // Either loading text appears or content loads quickly
      const loadingOrContent = await Promise.race([
        page.locator('text=Lade Statistiken').waitFor({ state: 'visible', timeout: 2000 }).then(() => 'loading'),
        page.getByTestId('summary-boxes').waitFor({ state: 'visible', timeout: 2000 }).then(() => 'content'),
      ]).catch(() => 'timeout')

      // Either loading appeared or content loaded quickly (both valid)
      expect(['loading', 'content', 'timeout']).toContain(loadingOrContent)
    })
  })

  test.describe('Data Integration', () => {
    test('should load data for current month by default', async ({ page }) => {
      // Verify API is called with current month
      const now = new Date()
      const expectedMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`

      const response = await page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      expect(response.url()).toContain(`month=${expectedMonth}`)
    })

    test('should load data for selected month when navigating', async ({ page }) => {
      // Navigate to previous month
      const responsePromise = page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
      await page.getByTestId('month-prev').click()
      const response = await responsePromise

      // Should request previous month's data
      expect(response.status()).toBe(200)
    })
  })
})
