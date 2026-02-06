/**
 * Admin Frontend - Products Sorting E2E Tests
 *
 * Tests that clicking sort headers on the Products page actually changes
 * the display order by verifying the backend sort_by parameter is sent
 * and the response is rendered in the correct order.
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
  categoryId: string
): Promise<string> {
  const response = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
    data: {
      names: { de: nameDe },
      price_cents: priceCents,
      category_id: categoryId,
    },
  })
  expect(response.status()).toBe(201)
  const body = await response.json()
  return body.id
}

test.describe('Products Page - Sorting', () => {
  /**
   * Test 1: Sort by name ascending then descending
   */
  test('should sort products by name ascending and descending', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const catName = `SortNameCat ${ts}`
    const categoryId = await createCategory(authenticatedRequest, catName)

    // Create 4 products with distinct names (created in non-alphabetical order)
    await createProduct(authenticatedRequest, `Delta ${ts}`, 400, categoryId)
    await createProduct(authenticatedRequest, `Alpha ${ts}`, 100, categoryId)
    await createProduct(authenticatedRequest, `Charlie ${ts}`, 300, categoryId)
    await createProduct(authenticatedRequest, `Bravo ${ts}`, 200, categoryId)

    // Navigate to products page
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Filter by our test category to isolate test data
    await productsPage.filterByCategory(categoryId)

    // Default sort is name_asc — verify alphabetical order
    const namesAsc = await productsPage.getAllProductNamesInOrder()
    expect(namesAsc.length).toBe(4)
    expect(namesAsc[0]).toContain(`Alpha ${ts}`)
    expect(namesAsc[1]).toContain(`Bravo ${ts}`)
    expect(namesAsc[2]).toContain(`Charlie ${ts}`)
    expect(namesAsc[3]).toContain(`Delta ${ts}`)

    // Click name header to toggle to desc
    await productsPage.sortBy('name')

    const namesDesc = await productsPage.getAllProductNamesInOrder()
    expect(namesDesc.length).toBe(4)
    expect(namesDesc[0]).toContain(`Delta ${ts}`)
    expect(namesDesc[1]).toContain(`Charlie ${ts}`)
    expect(namesDesc[2]).toContain(`Bravo ${ts}`)
    expect(namesDesc[3]).toContain(`Alpha ${ts}`)
  })

  /**
   * Test 2: Sort by price ascending then descending
   */
  test('should sort products by price ascending and descending', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const catName = `SortPriceCat ${ts}`
    const categoryId = await createCategory(authenticatedRequest, catName)

    // Create 4 products with distinct prices (non-sorted order)
    await createProduct(authenticatedRequest, `Prod5 ${ts}`, 500, categoryId)
    await createProduct(authenticatedRequest, `Prod1 ${ts}`, 100, categoryId)
    await createProduct(authenticatedRequest, `Prod3 ${ts}`, 300, categoryId)
    await createProduct(authenticatedRequest, `Prod2 ${ts}`, 200, categoryId)

    // Navigate to products page
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Filter by test category
    await productsPage.filterByCategory(categoryId)

    // Click price header to sort by price asc
    await productsPage.sortBy('price')

    const pricesAsc = await productsPage.getAllProductPricesInOrder()
    expect(pricesAsc.length).toBe(4)
    expect(pricesAsc[0]).toContain('1.00')
    expect(pricesAsc[1]).toContain('2.00')
    expect(pricesAsc[2]).toContain('3.00')
    expect(pricesAsc[3]).toContain('5.00')

    // Click again to toggle to price desc
    await productsPage.sortBy('price')

    const pricesDesc = await productsPage.getAllProductPricesInOrder()
    expect(pricesDesc.length).toBe(4)
    expect(pricesDesc[0]).toContain('5.00')
    expect(pricesDesc[1]).toContain('3.00')
    expect(pricesDesc[2]).toContain('2.00')
    expect(pricesDesc[3]).toContain('1.00')
  })

  /**
   * Test 3: Sort by category sends correct API parameter and renders results
   *
   * With 1000+ products in the DB, cross-category visual ordering can't be
   * reliably verified in a paginated view. Instead we verify:
   * 1. The category sort header sends sort_by=category to the API
   * 2. The response renders without errors
   * 3. Products remain visible after sorting
   */
  test('should sort products by category', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const catId = await createCategory(authenticatedRequest, `CatSort ${ts}`)

    // Create 2 products in the same category
    await createProduct(authenticatedRequest, `CatProd1 ${ts}`, 300, catId)
    await createProduct(authenticatedRequest, `CatProd2 ${ts}`, 200, catId)

    // Navigate to products page
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Filter by our test category to see our products
    await productsPage.filterByCategory(catId)

    // Click category sort header — verify the API call includes sort_by=category_asc
    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.url().includes('sort_by=category_asc'),
      { timeout: 10000 }
    )
    await page.getByTestId('products-table-header-category').click()
    const response = await responsePromise
    expect(response.status()).toBe(200)

    // Verify products are still visible after category sort
    await productsPage.waitForLoadingToComplete()
    const names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(2)
    // Both products should be present
    const hasProd1 = names.some((n) => n.includes(`CatProd1 ${ts}`))
    const hasProd2 = names.some((n) => n.includes(`CatProd2 ${ts}`))
    expect(hasProd1).toBe(true)
    expect(hasProd2).toBe(true)
  })

  /**
   * Test 4: Default sort is name ascending (no click needed)
   */
  test('should default to name ascending sort', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const catName = `DefaultSortCat ${ts}`
    const categoryId = await createCategory(authenticatedRequest, catName)

    // Create products in reverse alphabetical order
    await createProduct(authenticatedRequest, `Zebra ${ts}`, 400, categoryId)
    await createProduct(authenticatedRequest, `Apple ${ts}`, 100, categoryId)
    await createProduct(authenticatedRequest, `Mango ${ts}`, 300, categoryId)

    // Navigate — don't click any sort header
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Filter by test category (this triggers an API call with the existing sort_by=name_asc default)
    await productsPage.filterByCategory(categoryId)

    // Verify alphabetical order without clicking any sort header
    const names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(3)
    expect(names[0]).toContain(`Apple ${ts}`)
    expect(names[1]).toContain(`Mango ${ts}`)
    expect(names[2]).toContain(`Zebra ${ts}`)
  })
})
