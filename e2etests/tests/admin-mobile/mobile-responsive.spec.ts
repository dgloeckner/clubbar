/**
 * Admin Panel - Mobile Responsive E2E Tests (iPhone 14)
 *
 * Verifies the mobile-specific layout features of the admin panel:
 * - Bottom tab bar navigation (replaces desktop top nav)
 * - Mobile card views (replace desktop tables)
 * - MobileToolbar with search, sort dropdown, and collapsible filters
 * - Reports page (tab bar and filter bar)
 * - Footer hidden on mobile
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

test.describe('Mobile Responsive Layout', () => {
  test.describe('Bottom Tab Bar Navigation', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('bottom-tab-bar').waitFor({ state: 'visible', timeout: 10000 })
    })

    test('should display bottom tab bar', async ({ page }) => {
      await expect(page.getByTestId('bottom-tab-bar')).toBeVisible()
    })

    test('should display all 5 tabs', async ({ page }) => {
      await expect(page.getByTestId('tab-members')).toBeVisible()
      await expect(page.getByTestId('tab-products')).toBeVisible()
      await expect(page.getByTestId('tab-journal')).toBeVisible()
      await expect(page.getByTestId('tab-settlements')).toBeVisible()
      await expect(page.getByTestId('tab-more')).toBeVisible()
    })

    test('should navigate to Products when clicking Products tab', async ({ page }) => {
      await page.getByTestId('tab-products').click()
      await expect(page).toHaveURL(/\/products/)
    })

    test('should navigate to Journal when clicking Journal tab', async ({ page }) => {
      await page.getByTestId('tab-journal').click()
      await expect(page).toHaveURL(/\/journal/)
    })

    test('should navigate to Settlements when clicking Settlements tab', async ({ page }) => {
      await page.getByTestId('tab-settlements').click()
      await expect(page).toHaveURL(/\/settlements/)
    })

    test('should show More popup when clicking More tab', async ({ page }) => {
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()
    })

    test('should display all items in More popup', async ({ page }) => {
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()

      await expect(page.getByTestId('tab-categories')).toBeVisible()
      await expect(page.getByTestId('tab-reports')).toBeVisible()
      await expect(page.getByTestId('tab-settings')).toBeVisible()
      await expect(page.getByTestId('tab-audit-log')).toBeVisible()
    })

    test('should navigate to Categories from More popup', async ({ page }) => {
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()
      await page.getByTestId('tab-categories').click()
      await expect(page).toHaveURL(/\/categories/)
    })

    test('should navigate to Reports from More popup', async ({ page }) => {
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()
      await page.getByTestId('tab-reports').click()
      await expect(page).toHaveURL(/\/reports/)
    })
  })

  test.describe('Desktop Nav Hidden on Mobile', () => {
    test('should hide the desktop navigation bar', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('bottom-tab-bar').waitFor({ state: 'visible', timeout: 10000 })

      await expect(page.getByTestId('desktop-nav')).toBeHidden()
    })
  })

  test.describe('Mobile Card Views', () => {
    test('should show mobile cards on Members page', async ({ page }) => {
      await page.goto('/members')
      // Wait for data to load
      await page.getByTestId('members-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('members-mobile-cards')).toBeVisible()
    })

    test('should show mobile cards on Products page', async ({ page }) => {
      await page.goto('/products')
      await page.getByTestId('products-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('products-mobile-cards')).toBeVisible()
    })

    test('should show mobile cards on Journal page', async ({ page }) => {
      await page.goto('/journal')
      await page.getByTestId('journal-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('journal-mobile-cards')).toBeVisible()
    })

    test('should show mobile cards on Settlements page', async ({ page }) => {
      await page.goto('/settlements')
      await page.getByTestId('settlements-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('settlements-mobile-cards')).toBeVisible()
    })

    test('should show mobile cards on Categories page', async ({ page }) => {
      await page.goto('/categories')
      await page.getByTestId('categories-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('categories-mobile-cards')).toBeVisible()
    })

    test('should show mobile cards on Audit Log page', async ({ page }) => {
      await page.goto('/audit-log')
      await page.getByTestId('audit-log-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('audit-log-mobile-cards')).toBeVisible()
    })
  })

  test.describe('Mobile Toolbar', () => {
    test('should display mobile toolbar with sort button on Members page', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('members-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })

      const toolbar = page.getByTestId('members-mobile-toolbar')
      await expect(toolbar).toBeVisible()

      // Sort button should be visible
      const sortButton = page.getByTestId('members-mobile-toolbar-sort')
      await expect(sortButton).toBeVisible()
    })

    test('should open sort dropdown when clicking sort button', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('members-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })

      await page.getByTestId('members-mobile-toolbar-sort').click()
      await expect(page.getByTestId('members-mobile-toolbar-sort-dropdown')).toBeVisible()
    })

    test('should display filter toggle button on Members page', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('members-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })

      await expect(page.getByTestId('members-mobile-toolbar-filter-toggle')).toBeVisible()
    })

    test('should expand filters when clicking filter toggle', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('members-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })

      // Click filter toggle
      await page.getByTestId('members-mobile-toolbar-filter-toggle').click()

      // Expanded filters section should appear
      await expect(page.getByTestId('members-mobile-toolbar-filters')).toBeVisible()
    })

    test('should collapse filters when clicking filter toggle again', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('members-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })

      // Open filters
      await page.getByTestId('members-mobile-toolbar-filter-toggle').click()
      await expect(page.getByTestId('members-mobile-toolbar-filters')).toBeVisible()

      // Close filters
      await page.getByTestId('members-mobile-toolbar-filter-toggle').click()
      await expect(page.getByTestId('members-mobile-toolbar-filters')).toBeHidden()
    })
  })

  test.describe('Reports Page', () => {
    test('should display reports page on mobile', async ({ page }) => {
      await page.goto('/reports')
      await page.getByTestId('reports-page').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('reports-page')).toBeVisible()
    })
  })

  test.describe('Footer Hidden on Mobile', () => {
    test('should not display footer on mobile', async ({ page }) => {
      await page.goto('/members')
      await page.getByTestId('bottom-tab-bar').waitFor({ state: 'visible', timeout: 10000 })

      // Footer should not exist in the DOM on mobile (conditionally rendered)
      const footer = page.getByTestId('app-footer')
      await expect(footer).toHaveCount(0)
    })
  })
})
