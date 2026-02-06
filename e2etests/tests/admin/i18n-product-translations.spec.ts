/**
 * Admin Frontend - i18n Product Translations E2E Tests
 *
 * Tests that multilingual product names are:
 * 1. Created with DE/EN tabs in form
 * 2. Displayed in admin's current locale
 * 3. Preserved when editing
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (unique test data per test)
 * - Pattern 002: Authentication Isolation (authenticatedRequest fixture)
 * - Pattern 005: Using Test IDs (data-testid)
 * - Pattern 006: Page Object Model (ProductsPage, ProfilePage)
 * - Pattern 007: Page Object Fixtures (injected POMs)
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test, expect } from '../../fixtures/auth.fixture'

const API_BASE = 'http://localhost:8080/api'

test.describe('i18n Product Translations', () => {
  let testCategoryId: string

  test.beforeEach(async ({ authenticatedRequest, page }) => {
    // Reset admin's locale to German
    await authenticatedRequest.patch(`${API_BASE}/auth/profile`, {
      data: { locale: 'de' },
    })

    // Create a test category for products
    const timestamp = Date.now()
    const catResponse = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: {
        names: { de: `TestKat_${timestamp}`, en: `TestCat_${timestamp}` },
      },
    })
    const catData = await catResponse.json()
    testCategoryId = catData.id

    // Set localStorage and reload to apply German locale
    await page.goto('/products', { waitUntil: 'domcontentloaded' })
    await page.evaluate(() => {
      localStorage.setItem('adminLocale', 'de')
    })
    await page.reload()
  })

  test('should create product with German and English names', async ({ productsPage }) => {
    const timestamp = Date.now()
    const germanName = `Weizenbier_${timestamp}`
    const englishName = `WheatBeer_${timestamp}`

    await productsPage.waitForLoadingToComplete()
    await productsPage.openCreateModal()
    await productsPage.expectFormModalVisible()

    // Fill multilingual names
    await productsPage.fillProductFormMultilingual(
      { de: germanName, en: englishName },
      '3.50'
    )

    // Select category
    await productsPage.selectCategory(testCategoryId)

    // Submit
    await productsPage.submitForm()
    await productsPage.expectFormModalHidden()

    // Search for the product to find it (may not be on page 1)
    await productsPage.search(germanName)
    await productsPage.waitForLoadingToComplete()
    const productId = await productsPage.getProductIdByName(germanName)
    expect(productId).not.toBeNull()

    const rowData = await productsPage.getRowDataByProductId(productId)
    expect(rowData?.name).toBe(germanName)
  })

  test('should display product name in admin locale after switching to English', async ({
    productsPage,
    profilePage,
    mainLayoutPage,
  }) => {
    const timestamp = Date.now()
    const germanName = `Pilsner_${timestamp}`
    const englishName = `Lager_${timestamp}`

    // Create product with both names
    await productsPage.waitForLoadingToComplete()
    await productsPage.openCreateModal()
    await productsPage.fillProductFormMultilingual(
      { de: germanName, en: englishName },
      '2.80'
    )
    await productsPage.selectCategory(testCategoryId)
    await productsPage.submitForm()
    await productsPage.expectFormModalHidden()

    // Search for the product and verify German name is displayed
    await productsPage.search(germanName)
    await productsPage.waitForLoadingToComplete()
    let productId = await productsPage.getProductIdByName(germanName)
    expect(productId).not.toBeNull()

    // Switch to English locale
    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await mainLayoutPage.expectNavigationInLanguage('en')

    // Go back to products and search for English name
    await productsPage.navigate()
    await productsPage.search(englishName)
    await productsPage.waitForLoadingToComplete()

    // Verify English name is displayed
    productId = await productsPage.getProductIdByName(englishName)
    expect(productId).not.toBeNull()

    const rowData = await productsPage.getRowDataByProductId(productId)
    expect(rowData?.name).toBe(englishName)
  })

  test('should preserve both language names when editing product', async ({ productsPage }) => {
    const timestamp = Date.now()
    const germanName = `Hefeweizen_${timestamp}`
    const englishName = `HefeWheat_${timestamp}`

    // Create product with both names
    await productsPage.waitForLoadingToComplete()
    await productsPage.openCreateModal()
    await productsPage.fillProductFormMultilingual(
      { de: germanName, en: englishName },
      '4.00'
    )
    await productsPage.selectCategory(testCategoryId)
    await productsPage.submitForm()
    await productsPage.expectFormModalHidden()

    // Search for the product
    await productsPage.search(germanName)
    await productsPage.waitForLoadingToComplete()
    const productId = await productsPage.getProductIdByName(germanName)
    expect(productId).not.toBeNull()

    // Open edit modal
    await productsPage.clickEditButton(productId!)
    await productsPage.expectFormModalVisible()

    // Verify both names are loaded
    const loadedDeName = await productsPage.getFormNameDe()
    const loadedEnName = await productsPage.getFormNameEn()

    expect(loadedDeName).toBe(germanName)
    expect(loadedEnName).toBe(englishName)

    // Update only the price (leave names unchanged)
    const priceInput = await productsPage.getFormPriceValue()
    await productsPage.fillProductFormMultilingual(
      { de: germanName, en: englishName },
      '4.50'
    )
    await productsPage.submitForm()
    await productsPage.expectFormModalHidden()

    // Search again and verify product still has correct name
    await productsPage.search(germanName)
    await productsPage.waitForLoadingToComplete()
    const updatedRowData = await productsPage.getRowDataByProductId(productId)
    expect(updatedRowData?.name).toBe(germanName)
    // Price format varies by locale (€4.50 vs 4,50 €), check for numeric value
    expect(updatedRowData?.price).toMatch(/4[.,]50/)
  })

  test('should fallback to German when English name is empty', async ({
    productsPage,
    profilePage,
    mainLayoutPage,
  }) => {
    const timestamp = Date.now()
    const germanName = `NurDeutsch_${timestamp}`

    // Create product with only German name
    await productsPage.waitForLoadingToComplete()
    await productsPage.openCreateModal()
    await productsPage.fillProductFormMultilingual(
      { de: germanName },
      '5.00'
    )
    await productsPage.selectCategory(testCategoryId)
    await productsPage.submitForm()
    await productsPage.expectFormModalHidden()

    // Search and verify German name in German locale
    await productsPage.search(germanName)
    await productsPage.waitForLoadingToComplete()
    let productId = await productsPage.getProductIdByName(germanName)
    expect(productId).not.toBeNull()

    // Switch to English locale
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    // Go back to products and search for German name (should still work as fallback)
    await productsPage.navigate()
    await productsPage.search(germanName)
    await productsPage.waitForLoadingToComplete()

    // Product should still show German name (fallback when English is empty)
    productId = await productsPage.getProductIdByName(germanName)
    expect(productId).not.toBeNull()
  })
})
