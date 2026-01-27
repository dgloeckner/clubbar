import { test, expect } from '../../fixtures/pageObjects'

/**
 * Admin Frontend - Products Page E2E Tests
 *
 * Tests the admin panel Products page CRUD operations.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 007: Page Object Fixtures
 * Implements E2E Testing Pattern 008: Playwright Assertions (expect API)
 *
 * **CRITICAL: Tests use PAGE OBJECT METHODS, NOT raw locators**
 *
 * ✅ CORRECT:
 *   await productsPage.expectTableVisible()
 *   await productsPage.getProductCount()
 *   await productsPage.createProduct('Test', '5.99')
 *
 * ❌ WRONG (don't do this):
 *   const table = page.locator('table, [role="table"]')
 *   const modal = page.locator('[role="dialog"], [class*="modal"]')
 *   const input = page.getByTestId('products-search-input')
 *
 * Covers:
 * - Login flow
 * - List products
 * - Display product table
 * - Open create product modal
 * - Create new product
 * - Search/filter products
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data (timestamps)
 * - No shared state between tests
 * - Safe for parallel execution
 */

test.describe('Admin Frontend - Products Page', () => {
  /**
   * UC-A06: List Products
   */
  test.describe('UC-A06: List Products', () => {
    test('should display products page', async ({ authenticatedProductsPage }) => {
      // Pattern 006: Use page object methods, not raw locators
      await authenticatedProductsPage.expectPageVisible()
      await authenticatedProductsPage.expectTableVisible()
    })

    test('should display products table', async ({ authenticatedProductsPage }) => {
      // Pattern 006: High-level semantic methods
      await authenticatedProductsPage.expectTableVisible()
      const count = await authenticatedProductsPage.getProductCount()
      expect(count).toBeGreaterThanOrEqual(0)
    })

    test('should display products (or empty state)', async ({ authenticatedProductsPage }) => {
      // Pattern 003: Database-agnostic - works whether products exist or not
      const count = await authenticatedProductsPage.getProductCount()

      if (count === 0) {
        await authenticatedProductsPage.expectEmptyStateVisible()
      } else {
        await authenticatedProductsPage.expectTableVisible()
        expect(count).toBeGreaterThan(0)
      }
    })
  })

  /**
   * UC-A07: Create Product
   */
  test.describe('UC-A07: Create Product', () => {
    test('should open create product modal', async ({ authenticatedProductsPage }) => {
      // Pattern 006: Use page object methods
      await authenticatedProductsPage.openCreateModal()
      await authenticatedProductsPage.expectFormModalVisible()
    })

    test('should cancel create modal without submitting', async ({ authenticatedProductsPage }) => {
      // Pattern 006: Semantic actions through page object
      await authenticatedProductsPage.openCreateModal()
      await authenticatedProductsPage.expectFormModalVisible()

      await authenticatedProductsPage.cancelForm()
      await authenticatedProductsPage.expectFormModalHidden()
    })

    test('should fill and submit product form', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
      const productName = `Test Product ${Date.now()}`
      const productPrice = '5.99'

      // Pattern 001: Create unique test data per test
      // Navigate to products and use page object auto-select for any available active category
      await authenticatedProductsPage.navigate()

      // Create product - page object will auto-select first available category when modal opens
      await authenticatedProductsPage.createProduct(productName, productPrice)

      // Pattern 008: Wait for form to close and verify
      await authenticatedProductsPage.expectFormModalHidden()
    })

    test('should successfully create and close product modal', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const productName = `Created Product ${Date.now()}`
      const productPrice = '12.99'

      // Navigate to products page
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.expectPageVisible()
      await authenticatedProductsPage.expectTableVisible()

      // Create product - verifies form fills, submits, and modal closes
      await authenticatedProductsPage.createProduct(productName, productPrice)

      // Verify modal closed after creation (indicates successful API call)
      await authenticatedProductsPage.expectFormModalHidden()

      // Verify we're back on the products page
      await authenticatedProductsPage.expectTableVisible()
    })

    test('should display form with empty fields for create', async ({ authenticatedProductsPage }) => {
      // Pattern 006: Page object provides field getters
      await authenticatedProductsPage.openCreateModal()

      const name = await authenticatedProductsPage.getFormNameValue()
      const price = await authenticatedProductsPage.getFormPriceValue()

      expect(name).toBe('')
      expect(price).toBe('')
    })

    test('should create product with auto-selected category', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
      // Pattern 001: Create unique test data
      const productName = `Categorized Product ${Date.now()}`
      const productPrice = '8.50'

      // First, create a category to ensure one exists
      const categoryName = `Product Category ${Date.now()}`
      await authenticatedCategoriesPage.navigate()
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      // Navigate to products
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.expectPageVisible()

      // Create product (auto-selects first available category)
      await authenticatedProductsPage.createProduct(productName, productPrice)

      // Verify product was created successfully
      // (modal closed indicates successful API call and product was persisted)
      await authenticatedProductsPage.expectFormModalHidden()
      await authenticatedProductsPage.expectTableVisible()

      // Verify the page is still on products and table is visible
      const isOnPage = await authenticatedProductsPage.isOnProductsPage()
      expect(isOnPage).toBe(true)
    })
  })


  /**
   * Modal Interactions (General)
   */
  test.describe('Modal Interactions', () => {
    test('should close modal when clicking cancel', async ({ authenticatedProductsPage }) => {
      // Pattern 006: High-level modal methods
      await authenticatedProductsPage.openCreateModal()
      await authenticatedProductsPage.expectFormModalVisible()

      await authenticatedProductsPage.cancelForm()
      await authenticatedProductsPage.expectFormModalHidden()
    })

    test('should fill all form fields', async ({ authenticatedProductsPage }) => {
      const testData = {
        name: `Coffee ${Date.now()}`,
        price: '3.50',
      }

      await authenticatedProductsPage.openCreateModal()

      // Pattern 006: Fill form through page object
      await authenticatedProductsPage.fillProductForm(testData.name, testData.price)

      // Pattern 006: Verify field values through page object
      expect(await authenticatedProductsPage.getFormNameValue()).toContain(testData.name)
      expect(await authenticatedProductsPage.getFormPriceValue()).toContain(testData.price)
    })
  })

  /**
   * UC-A42: Edit Product
   */
  test.describe('UC-A42: Edit Product', () => {
    test('should open edit modal with pre-filled values', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const productName = `Edit Test ${Date.now()}`
      const productPrice = '9.99'

      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(productName, productPrice)
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Click edit button using product ID
      await authenticatedProductsPage.clickEditButton(productId)
      await authenticatedProductsPage.expectFormModalVisible()
      await authenticatedProductsPage.expectEditMode()

      // Verify form has pre-filled values
      const nameValue = await authenticatedProductsPage.getFormNameValue()
      const priceValue = await authenticatedProductsPage.getFormPriceValue()

      expect(nameValue).toContain(productName)
      expect(priceValue).toContain('9.99')
    })

    test('should update product and reflect changes in table', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const originalName = `Original ${Date.now()}`
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(originalName, '5.00')
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Edit the product
      const updatedName = `Updated ${Date.now()}`
      await authenticatedProductsPage.editProduct(productId, updatedName, '7.50')

      // Verify updated values in table using product ID
      const displayedName = await authenticatedProductsPage.getProductNameInRow(productId)
      const displayedPrice = await authenticatedProductsPage.getProductPriceInRow(productId)

      expect(displayedName).toContain(updatedName)
      expect(displayedPrice).toContain('7.50')
    })

    test('should cancel edit without saving changes', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const productName = `Cancel Edit ${Date.now()}`
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(productName, '3.00')
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Open edit modal and attempt changes
      await authenticatedProductsPage.clickEditButton(productId)
      await authenticatedProductsPage.expectFormModalVisible()
      await authenticatedProductsPage.fillProductForm('Should Not Save', '99.99')
      await authenticatedProductsPage.cancelForm()
      await authenticatedProductsPage.expectFormModalHidden()

      // Verify original values still in table using product ID
      const displayedName = await authenticatedProductsPage.getProductNameInRow(productId)
      const displayedPrice = await authenticatedProductsPage.getProductPriceInRow(productId)

      expect(displayedName).toContain(productName)
      expect(displayedPrice).toContain('3.00')
    })
  })

  /**
   * UC-A43: Deactivate/Activate Product
   */
  test.describe('UC-A43: Deactivate/Activate Product', () => {
    test('should deactivate product with confirmation', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const productName = `Deactivate Test ${Date.now()}`
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(productName, '4.50')
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Verify initially active
      let status = await authenticatedProductsPage.getProductStatus(productId)
      expect(status).toBe('active')

      // Toggle status and confirm deactivation
      await authenticatedProductsPage.clickStatusToggle(productId)
      await authenticatedProductsPage.expectConfirmDialogVisible()
      await authenticatedProductsPage.confirmDelete()
      await authenticatedProductsPage.waitForLoadingToComplete()

      // Verify status changed to inactive
      status = await authenticatedProductsPage.getProductStatus(productId)
      expect(status).toBe('inactive')
    })

    test('should activate product without confirmation', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create and deactivate a product
      const productName = `Activate Test ${Date.now()}`
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(productName, '6.00')
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Deactivate product
      await authenticatedProductsPage.toggleProductStatus(productId, true)
      let status = await authenticatedProductsPage.getProductStatus(productId)
      expect(status).toBe('inactive')

      // Activate (should be immediate, no confirmation)
      await authenticatedProductsPage.clickStatusToggle(productId)

      // Verify no confirmation dialog appeared
      const dialogVisible = await authenticatedProductsPage.page
        .getByTestId('products-confirm-dialog')
        .isVisible({ timeout: 1000 })
        .catch(() => false)
      expect(dialogVisible).toBe(false)

      // Verify status changed to active
      await authenticatedProductsPage.waitForLoadingToComplete()
      status = await authenticatedProductsPage.getProductStatus(productId)
      expect(status).toBe('active')
    })

    test('should cancel deactivation', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const productName = `Cancel Deactivate ${Date.now()}`
      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(productName, '8.00')
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Toggle status and cancel
      await authenticatedProductsPage.clickStatusToggle(productId)
      await authenticatedProductsPage.expectConfirmDialogVisible()
      await authenticatedProductsPage.cancelDelete()

      // Verify status unchanged
      const status = await authenticatedProductsPage.getProductStatus(productId)
      expect(status).toBe('active')
    })

    test('should show delete confirmation and permanently delete product', async ({ authenticatedProductsPage }) => {
      // Pattern 001: Create unique test product
      const productName = `Delete Test ${Date.now()}`
      const initialCount = await authenticatedProductsPage.getProductCount()

      await authenticatedProductsPage.navigate()
      await authenticatedProductsPage.createProduct(productName, '10.00')
      await authenticatedProductsPage.expectFormModalHidden()

      // Get product ID from table
      const productId = await authenticatedProductsPage.getFirstProductId()

      // Click delete button and confirm
      await authenticatedProductsPage.clickDeleteButton(productId)
      await authenticatedProductsPage.expectConfirmDialogVisible()
      await authenticatedProductsPage.confirmDelete()
      await authenticatedProductsPage.waitForLoadingToComplete()

      // Verify product is permanently deleted from database
      // After deletion, product count should be back to initial
      const finalCount = await authenticatedProductsPage.getProductCount()
      expect(finalCount).toBe(initialCount)

      // Verify product row is no longer visible in DOM
      const isVisible = await authenticatedProductsPage.page
        .locator(`[data-testid="products-table-row-${productId}"]`)
        .isVisible({ timeout: 1000 })
        .catch(() => false)
      expect(isVisible).toBe(false)
    })
  })

  /**
   * Page State Verification
   */
  test.describe('Page State Verification', () => {
    test('should verify page is on products route', async ({ page, authenticatedProductsPage }) => {
      // Pattern 006: Verify we're on the right page
      await authenticatedProductsPage.expectPageVisible()
      expect(page.url()).toContain('/products')
    })

    test('should display create button', async ({ authenticatedProductsPage }) => {
      // Pattern 006: Create button should be clickable
      await authenticatedProductsPage.openCreateModal()
      await authenticatedProductsPage.expectFormModalVisible()
    })
  })
})
