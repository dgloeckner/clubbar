/**
 * Admin Frontend - Products Status Filter E2E Tests
 *
 * Tests that the All / Active / Inactive status filter on the Products page
 * correctly filters products by their is_active status.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (unique test data per test via timestamps)
 * - Pattern 002: Authentication Isolation (authenticatedRequest fixture)
 * - Pattern 003: Database-Agnostic Assertions (filter by test category, not position)
 * - Pattern 005: Using Test IDs (data-testid)
 * - Pattern 006: Page Object Model (ProductsPage methods)
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { ProductsPage } from '../../pages/ProductsPage'

const API_BASE = 'http://localhost:8080/api'

/**
 * Helper: Create a category via API and return its id.
 */
async function createCategory(
  authenticatedRequest: any,
  nameDe: string
): Promise<string> {
  const response = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
    data: { names: { de: nameDe, en: nameDe } },
  })
  expect(response.status()).toBe(201)
  const body = await response.json()
  return body.id
}

/**
 * Helper: Create a product via API in a specific category, return its id.
 */
async function createProduct(
  authenticatedRequest: any,
  nameDe: string,
  priceCents: number,
  categoryId: string,
  isActive: boolean = true
): Promise<string> {
  const response = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
    data: {
      names: { de: nameDe },
      price_cents: priceCents,
      category_id: categoryId,
      is_active: isActive,
    },
  })
  expect(response.status()).toBe(201)
  const body = await response.json()
  return body.id
}

/**
 * Helper: Deactivate a product via API.
 */
async function deactivateProduct(
  authenticatedRequest: any,
  productId: string
): Promise<void> {
  const response = await authenticatedRequest.patch(`${API_BASE}/admin/products/${productId}/status`, {
    data: { is_active: false },
  })
  expect(response.status()).toBe(200)
}

test.describe('Products Page - Status Filter', () => {
  /**
   * Test 1: Filter shows only active products
   */
  test('should show only active products when Active filter is selected', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `StatusCat ${ts}`)

    // Create 2 active and 1 inactive product
    await createProduct(authenticatedRequest, `ActiveProd1 ${ts}`, 100, categoryId, true)
    await createProduct(authenticatedRequest, `ActiveProd2 ${ts}`, 200, categoryId, true)
    const inactiveId = await createProduct(authenticatedRequest, `InactiveProd ${ts}`, 300, categoryId, true)
    await deactivateProduct(authenticatedRequest, inactiveId)

    // Navigate and filter by test category
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()
    await productsPage.filterByCategory(categoryId)

    // Click Active filter
    await productsPage.filterByStatus('active')

    // Verify only active products are shown
    const names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(2)
    expect(names.some((n) => n.includes(`ActiveProd1 ${ts}`))).toBe(true)
    expect(names.some((n) => n.includes(`ActiveProd2 ${ts}`))).toBe(true)
    // Inactive product should NOT be visible
    expect(names.some((n) => n.includes(`InactiveProd ${ts}`))).toBe(false)
  })

  /**
   * Test 2: Filter shows only inactive products
   */
  test('should show only inactive products when Inactive filter is selected', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `StatusCat2 ${ts}`)

    // Create 1 active and 2 inactive products
    await createProduct(authenticatedRequest, `ActiveOnly ${ts}`, 100, categoryId, true)
    const inactiveId1 = await createProduct(authenticatedRequest, `Inactive1 ${ts}`, 200, categoryId, true)
    const inactiveId2 = await createProduct(authenticatedRequest, `Inactive2 ${ts}`, 300, categoryId, true)
    await deactivateProduct(authenticatedRequest, inactiveId1)
    await deactivateProduct(authenticatedRequest, inactiveId2)

    // Navigate and filter by test category
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()
    await productsPage.filterByCategory(categoryId)

    // Click Inactive filter
    await productsPage.filterByStatus('inactive')

    // Verify only inactive products are shown
    const names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(2)
    expect(names.some((n) => n.includes(`Inactive1 ${ts}`))).toBe(true)
    expect(names.some((n) => n.includes(`Inactive2 ${ts}`))).toBe(true)
    // Active product should NOT be visible
    expect(names.some((n) => n.includes(`ActiveOnly ${ts}`))).toBe(false)
  })

  /**
   * Test 3: All filter shows both active and inactive products
   */
  test('should show all products when All filter is selected after filtering', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `StatusCat3 ${ts}`)

    // Create 1 active and 1 inactive product
    await createProduct(authenticatedRequest, `AllActive ${ts}`, 100, categoryId, true)
    const inactiveId = await createProduct(authenticatedRequest, `AllInactive ${ts}`, 200, categoryId, true)
    await deactivateProduct(authenticatedRequest, inactiveId)

    // Navigate and filter by test category
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()
    await productsPage.filterByCategory(categoryId)

    // First filter to Active only
    await productsPage.filterByStatus('active')
    let names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(1)

    // Then switch to All — both products should appear
    await productsPage.filterByStatus('all')
    names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(2)
    expect(names.some((n) => n.includes(`AllActive ${ts}`))).toBe(true)
    expect(names.some((n) => n.includes(`AllInactive ${ts}`))).toBe(true)
  })

  /**
   * Test 4: Status filter sends correct API parameter
   */
  test('should send status parameter in API request', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `StatusAPI ${ts}`)
    await createProduct(authenticatedRequest, `APIProd ${ts}`, 100, categoryId, true)

    // Navigate
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Click Active filter — verify API call includes status=active
    const activeResponsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.url().includes('status=active'),
      { timeout: 10000 }
    )
    await page.getByTestId('products-filter-status-active').click()
    const activeResponse = await activeResponsePromise
    expect(activeResponse.status()).toBe(200)

    // Click Inactive filter — verify API call includes status=inactive
    const inactiveResponsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.url().includes('status=inactive'),
      { timeout: 10000 }
    )
    await page.getByTestId('products-filter-status-inactive').click()
    const inactiveResponse = await inactiveResponsePromise
    expect(inactiveResponse.status()).toBe(200)

    // Click All filter — verify API call does NOT include status param
    const allResponsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && !resp.url().includes('status='),
      { timeout: 10000 }
    )
    await page.getByTestId('products-filter-status-all').click()
    const allResponse = await allResponsePromise
    expect(allResponse.status()).toBe(200)
  })
})
