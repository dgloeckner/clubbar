/**
 * Admin Frontend - Products Search E2E Tests
 *
 * Tests that the search bar on the Products page correctly filters
 * products by name via the backend search parameter.
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

test.describe('Products Page - Search', () => {
  /**
   * Test 1: Search filters products by name
   */
  test('should filter products by name when searching', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `SearchCat ${ts}`)

    await createProduct(authenticatedRequest, `Apfelsaft ${ts}`, 250, categoryId)
    await createProduct(authenticatedRequest, `Orangensaft ${ts}`, 300, categoryId)
    await createProduct(authenticatedRequest, `Mineralwasser ${ts}`, 150, categoryId)

    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Filter by test category first to isolate test data
    await productsPage.filterByCategory(categoryId)
    const allNames = await productsPage.getAllProductNamesInOrder()
    expect(allNames.length).toBe(3)

    // Search for "Apfel" — should show only Apfelsaft
    await productsPage.search(`Apfelsaft ${ts}`)
    const filtered = await productsPage.getAllProductNamesInOrder()
    expect(filtered.length).toBe(1)
    expect(filtered[0]).toContain(`Apfelsaft ${ts}`)
  })

  /**
   * Test 2: Search returns multiple matches
   */
  test('should return multiple matches for partial search', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `SearchMulti ${ts}`)

    // Products with shared substring "saft"
    await createProduct(authenticatedRequest, `Apfelsaft ${ts}`, 250, categoryId)
    await createProduct(authenticatedRequest, `Orangensaft ${ts}`, 300, categoryId)
    await createProduct(authenticatedRequest, `Mineralwasser ${ts}`, 150, categoryId)

    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()
    await productsPage.filterByCategory(categoryId)

    // Search for "saft" — should match Apfelsaft and Orangensaft
    await productsPage.search(`saft ${ts}`)
    const filtered = await productsPage.getAllProductNamesInOrder()
    expect(filtered.length).toBe(2)
    expect(filtered.some((n) => n.includes(`Apfelsaft ${ts}`))).toBe(true)
    expect(filtered.some((n) => n.includes(`Orangensaft ${ts}`))).toBe(true)
  })

  /**
   * Test 3: Clearing search restores all products
   */
  test('should restore all products when search is cleared', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `SearchClear ${ts}`)

    await createProduct(authenticatedRequest, `ClearTestA ${ts}`, 100, categoryId)
    await createProduct(authenticatedRequest, `ClearTestB ${ts}`, 200, categoryId)

    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()
    await productsPage.filterByCategory(categoryId)

    // Verify both products visible
    let names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(2)

    // Search to filter down to 1
    await productsPage.search(`ClearTestA ${ts}`)
    names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(1)

    // Clear search — both products should return
    await productsPage.clearSearch()
    names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(2)
  })

  /**
   * Test 4: Search sends correct API parameter
   */
  test('should send search parameter in API request', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `SearchAPI ${ts}`)
    await createProduct(authenticatedRequest, `APISearch ${ts}`, 100, categoryId)

    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()

    // Type in search — verify API call includes search param
    const searchTerm = `APISearch ${ts}`
    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/products') && resp.url().includes('search='),
      { timeout: 10000 }
    )
    await page.getByTestId('products-search-input').clear()
    await page.getByTestId('products-search-input').fill(searchTerm)
    const response = await responsePromise
    expect(response.status()).toBe(200)
  })

  /**
   * Test 5: Search with no results shows empty state
   */
  test('should show empty state when search has no results', async ({
    page,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const categoryId = await createCategory(authenticatedRequest, `SearchEmpty ${ts}`)
    await createProduct(authenticatedRequest, `EmptyTest ${ts}`, 100, categoryId)

    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })
    const productsPage = new ProductsPage(page)
    await productsPage.waitForLoadingToComplete()
    await productsPage.filterByCategory(categoryId)

    // Search for something that won't match
    await productsPage.search(`NONEXISTENT_PRODUCT_${ts}`)
    const names = await productsPage.getAllProductNamesInOrder()
    expect(names.length).toBe(0)
  })
})
