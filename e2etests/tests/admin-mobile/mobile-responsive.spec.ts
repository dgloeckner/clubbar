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
import type { Page } from '@playwright/test'
import { randomUUID } from 'crypto'
import { csrfHeaders } from '../../utils/csrf'

const API_BASE = 'http://localhost:8080/api'

/**
 * A catalogue entry of this test's own, so a card list has something to show.
 *
 * The card views render only when their list is non-empty, and `seed.sql`
 * ships neither products nor categories — the club's own catalogue is the
 * first thing an admin creates. Borrowing whatever another spec left behind
 * makes the assertion depend on the order Playwright happened to shard the
 * suite in (Pattern 001).
 */
async function createCategory(page: Page): Promise<string> {
  const suffix = randomUUID().substring(0, 8)
  const response = await page.request.post(`${API_BASE}/admin/categories`, {
    data: { names: { de: `Mobil ${suffix}`, en: `Mobile ${suffix}` } },
    headers: await csrfHeaders(page),
  })
  expect(response.status(), await response.text()).toBe(201)

  return (await response.json()).id as string
}

async function createProduct(
  page: Page,
  categoryId: string,
  options: { minAge?: number | null; tag?: string } = {},
): Promise<string> {
  const suffix = randomUUID().substring(0, 8)
  const name = `${options.tag ?? 'Mobilprodukt'} ${suffix}`
  const response = await page.request.post(`${API_BASE}/admin/products`, {
    data: {
      names: { de: name, en: name },
      price_cents: 350,
      category_id: categoryId,
      min_age: options.minAge ?? null,
    },
    headers: await csrfHeaders(page),
  })
  expect(response.status(), await response.text()).toBe(201)

  return (await response.json()).id as string
}

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
      await expect(page.getByTestId('tab-dashboard')).toBeVisible()
      await expect(page.getByTestId('tab-members')).toBeVisible()
      await expect(page.getByTestId('tab-products')).toBeVisible()
      await expect(page.getByTestId('tab-journal')).toBeVisible()
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

    test('should navigate to Settlements via More popup', async ({ page }) => {
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()
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

      await expect(page.getByTestId('tab-settlements')).toBeVisible()
      await expect(page.getByTestId('tab-reports')).toBeVisible()
      await expect(page.getByTestId('tab-settings')).toBeVisible()
      await expect(page.getByTestId('tab-audit-log')).toBeVisible()
    })

    // #366: Categories is a tab of the Products page, not a top-level entry —
    // it no longer appears in the bottom tab bar's More popup.
    test('should navigate to Categories via the Products tab', async ({ page }) => {
      await page.getByTestId('tab-products').click()
      await expect(page).toHaveURL(/\/products/)
      await page.getByTestId('products-tab-categories').click()
      await expect(page).toHaveURL(/\/products\/categories/)
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
      // The card container only renders for a non-empty list — an empty one is
      // the `products-empty-state` instead. `seed.sql` ships no products, so
      // this used to pass only when some other spec happened to have created
      // one first, and failed whenever sharding put those specs elsewhere
      // (Pattern 001: a test owns the data it asserts on).
      //
      // The page is opened before the catalogue entry is created because the
      // CSRF token comes out of the app's own localStorage, which is not
      // readable until something has been loaded from the origin.
      await page.goto('/products')
      const categoryId = await createCategory(page)
      // Both cards are asserted on, so both have to be on the page that is
      // showing: the list is paginated and sorted by category, and this
      // catalogue entry has no claim to the first page. The shared tag is what
      // the search narrows the list down to.
      const tag = `MobAge${randomUUID().substring(0, 8)}`
      const plain = await createProduct(page, categoryId, { tag })
      const restricted = await createProduct(page, categoryId, { tag, minAge: 18 })
      await page.reload()

      await page.getByTestId('products-mobile-cards').waitFor({ state: 'visible', timeout: 15000 })
      await expect(page.getByTestId('products-mobile-cards')).toBeVisible()

      await page.getByTestId('products-search-input').fill(tag)
      await expect(page.getByTestId(`product-card-${restricted}`)).toBeVisible()
      await expect(page.getByTestId(`product-card-${plain}`)).toBeVisible()

      // The Jugendschutz badge, on the card as well as in the desktop table
      // (ADR-0045). On a phone the card *is* the product overview: a badge
      // that renders only in the table would hide the restriction on the one
      // screen the Getränkewart is most likely holding.
      await expect(page.getByTestId(`products-list-min-age-badge-${restricted}`)).toBeVisible()
      await expect(page.getByTestId(`products-list-min-age-badge-${restricted}`)).toContainText('18')
      await expect(page.getByTestId(`products-list-min-age-badge-${plain}`)).toHaveCount(0)
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
      // Same as Products above: no seeded categories, so the list is empty
      // until this test puts something in it.
      await page.goto('/products/categories')
      await createCategory(page)
      await page.reload()

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
