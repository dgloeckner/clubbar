import { test, expect } from '../../fixtures/pageObjects'

/**
 * Admin Frontend - Products Page E2E Tests (Consolidated)
 *
 * Tests the admin panel Products page CRUD operations through real user workflows.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 007: Page Object Fixtures
 * Implements E2E Testing Pattern 008: Playwright Assertions (expect API)
 *
 * **Consolidated Design:**
 * - Few, comprehensive test cases (not individual micro-tests)
 * - Each test represents a complete user workflow
 * - Uses getRowDataByProductId() for nice assertions
 * - No tests for just UI element existence
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data (timestamps)
 * - No shared state between tests
 * - Safe for parallel execution
 */

test.describe('Products Page - Complete User Workflows', () => {
  /**
   * UC-A06 & UC-A07: List and Create Product with Category
   */
  test('should navigate products page and create new product with category', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Test Product ${Date.now()}`
    const productPrice = '5.99'
    const categoryName = `Category ${Date.now()}`

    // Create a category first
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })

    // User navigates to products page and creates product
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.expectPageVisible()

    // Use createProduct which auto-selects a category
    await authenticatedProductsPage.createProduct(productName, productPrice)

    // Verify product was created by finding it in the table
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    const rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()

    if (rowData) {
      expect(rowData.name).toContain(productName)
      expect(rowData.price).toContain('5,99')
      expect(rowData.category).toBeTruthy() // Category must be set
      expect(rowData.isActive).toBe(true)
    }
  })

  /**
   * UC-A07: Create Product - Cancel Flow
   */
  test('should cancel product creation dialog without saving', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Should Not Save ${Date.now()}`

    // Create a category first to select in form
    const categoryName = `Cancel Test Category ${Date.now()}`
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })

    // Navigate to products
    await authenticatedProductsPage.navigate()

    // User opens and cancels create modal
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // Fill form with all required fields but cancel
    await authenticatedProductsPage.fillProductForm(productName, '99.99')
    await authenticatedProductsPage.cancelForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // Verify product was NOT created
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).toBeNull() // Product should not exist
  })

  /**
   * UC-A07: Create with Category - Positive Test
   */
  test('should create product with category and verify in table', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Product with Category ${Date.now()}`
    const productPrice = '7.99'
    const categoryName = `Test Category ${Date.now()}`

    // Create a category first and get its ID
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Navigate to products and create product with explicit category ID
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, productPrice, categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Verify product exists with correct data
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    const rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()

    if (rowData) {
      expect(rowData.name).toContain(productName)
      expect(rowData.price).toContain('7,99')
      expect(rowData.category).toBeTruthy() // Has a category assigned
    }
  })

  /**
   * UC-A07: Create without Category - Negative Test
   * Category is required and form should prevent submission without it
   */
  test('should prevent creating product without selecting a category', async ({ authenticatedProductsPage }) => {
    const productName = `No Category Product ${Date.now()}`
    const productPrice = '6.99'

    // User opens create modal
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // User fills name and price but DOES NOT select a category
    await authenticatedProductsPage.fillProductForm(productName, productPrice)

    // User attempts to submit without category selected
    // The form should have validation that requires category (required attribute on CategorySelect)
    try {
      await authenticatedProductsPage.submitForm()
      // If we get here, check if form is still visible (validation may prevent close)
      const isModalVisible = await authenticatedProductsPage.page
        .getByTestId('products-form-modal')
        .isVisible({ timeout: 2000 })
        .catch(() => false)

      // Modal should still be visible OR show validation error
      expect(isModalVisible).toBe(true)
    } catch (e) {
      // If submitForm throws (due to form validation), that's also valid
      // Modal should still be open
      await authenticatedProductsPage.expectFormModalVisible()
    }

    // Verify product was NOT created
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).toBeNull() // Product should not have been created without category
  })

  /**
   * UC-A42: Edit Product - Update and Verify
   */
  test('should edit product name and price, verify changes persist with category', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const originalName = `Original Product ${Date.now()}`
    const originalPrice = '5.00'
    const updatedName = `Updated Product ${Date.now()}`
    const updatedPrice = '9.99'

    // Create a category first and get its ID
    const categoryName = `Edit Test Category ${Date.now()}`
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create initial product with explicit category ID
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(originalName, originalPrice, categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Get product ID and verify initial state
    const productId = await authenticatedProductsPage.getProductIdByName(originalName)
    expect(productId).not.toBeNull()
    let rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()

    if (rowData) {
      expect(rowData.name).toContain(originalName)
      expect(rowData.price).toContain('5,00')
      expect(rowData.category).toBeTruthy() // Category was set during creation

      // User edits product
      await authenticatedProductsPage.editProduct(productId, updatedName, updatedPrice)
      await authenticatedProductsPage.expectFormModalHidden()

      // Verify changes in table
      rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
      expect(rowData).not.toBeNull()
      if (rowData) {
        expect(rowData.name).toContain(updatedName)
        expect(rowData.price).toContain('9,99')
        expect(rowData.category).toBeTruthy() // Category still set
      }
    }
  })

  /**
   * UC-A42: Edit Product - Cancel without Saving
   */
  test('should cancel product edit without saving changes', async ({ authenticatedProductsPage }) => {
    const productName = `Product to Edit ${Date.now()}`
    const originalPrice = '3.00'

    // Create product
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.createProduct(productName, originalPrice)
    await authenticatedProductsPage.expectFormModalHidden()

    // Open edit and attempt change, then cancel
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    await authenticatedProductsPage.clickEditButton(productId)
    await authenticatedProductsPage.expectFormModalVisible()

    // Try to change but cancel
    await authenticatedProductsPage.fillProductForm('Should Not Save', '99.99')
    await authenticatedProductsPage.cancelForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // Verify original values unchanged
    const rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData.name).toContain(productName)
    expect(rowData.price).toContain('3,00')
    expect(rowData.category).toBeTruthy() // Category unchanged
  })

  /**
   * UC-A43: Deactivate/Activate Product
   */
  test('should deactivate product with confirmation and reactivate', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Status Test ${Date.now()}`
    const categoryName = `Status Test Category ${Date.now()}`

    // Create a category first and get its ID
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create and verify active with category
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, '4.50', categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()
    let rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()
    if (rowData) {
      expect(rowData.isActive).toBe(true)
      expect(rowData.category).toBeTruthy() // Category is set
    }

    // Deactivate with confirmation
    await authenticatedProductsPage.clickStatusToggle(productId)
    await authenticatedProductsPage.expectConfirmDialogVisible()
    await authenticatedProductsPage.confirmDelete()
    await authenticatedProductsPage.waitForLoadingToComplete()

    // Verify deactivated
    rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()
    if (rowData) {
      expect(rowData.isActive).toBe(false)
    }

    // Reactivate (no confirmation dialog)
    await authenticatedProductsPage.clickStatusToggle(productId)
    const dialogVisible = await authenticatedProductsPage.page
      .getByTestId('products-confirm-dialog')
      .isVisible({ timeout: 1000 })
      .catch(() => false)
    expect(dialogVisible).toBe(false)

    await authenticatedProductsPage.waitForLoadingToComplete()

    // Verify reactivated
    rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowData).not.toBeNull()
    if (rowData) {
      expect(rowData.isActive).toBe(true)
    }
  })

  /**
   * UC-A43: Cancel Deactivation
   */
  test('should cancel product deactivation and keep status unchanged', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Cancel Deactivate ${Date.now()}`
    const categoryName = `Cancel Deactivate Category ${Date.now()}`

    // Create a category first and get its ID
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create product with category
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, '8.00', categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Try to deactivate but cancel
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()
    if (productId) {
      await authenticatedProductsPage.clickStatusToggle(productId)
      await authenticatedProductsPage.expectConfirmDialogVisible()
      await authenticatedProductsPage.cancelDelete()

      // Verify still active with category intact
      const rowData = await authenticatedProductsPage.getRowDataByProductId(productId)
      expect(rowData).not.toBeNull()
      if (rowData) {
        expect(rowData.isActive).toBe(true)
        expect(rowData.category).toBeTruthy()
      }
    }
  })

  /**
   * UC-A43: Delete Product
   */
  test('should delete product and remove from table permanently', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Delete Test ${Date.now()}`
    const categoryName = `Delete Test Category ${Date.now()}`

    // Create a category first and get its ID
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create and find product with category
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, '10.00', categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Verify product exists
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()
    const rowDataBeforeDelete = await authenticatedProductsPage.getRowDataByProductId(productId)
    expect(rowDataBeforeDelete).not.toBeNull()
    if (rowDataBeforeDelete) {
      expect(rowDataBeforeDelete.name).toContain(productName)
    }

    // Delete with confirmation
    if (productId) {
      await authenticatedProductsPage.clickDeleteButton(productId)
      await authenticatedProductsPage.expectConfirmDialogVisible()
      await authenticatedProductsPage.confirmDelete()
      await authenticatedProductsPage.waitForLoadingToComplete()

      // Verify product no longer exists in table
      const foundProductId = await authenticatedProductsPage.getProductIdByName(productName)
      expect(foundProductId).toBeNull() // Product should be deleted
    }
  })

  /**
   * Test Form Modal Initial State
   */
  test('should show empty form fields in create modal', async ({ authenticatedProductsPage }) => {
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    const nameValue = await authenticatedProductsPage.getFormNameValue()
    const priceValue = await authenticatedProductsPage.getFormPriceValue()

    expect(nameValue).toBe('')
    expect(priceValue).toBe('')

    await authenticatedProductsPage.cancelForm()
  })

  /**
   * Test Edit Modal Pre-fill
   */
  test('should pre-fill edit modal with existing product data', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Edit Modal Test ${Date.now()}`
    const productPrice = '9.99'
    const categoryName = `Edit Modal Test Category ${Date.now()}`

    // Create a category first and get its ID
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create product with explicit category
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, productPrice, categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Open edit modal
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()
    if (productId) {
      await authenticatedProductsPage.clickEditButton(productId)
      await authenticatedProductsPage.expectFormModalVisible()
      await authenticatedProductsPage.expectEditMode()

      // Verify form is pre-filled
      const nameValue = await authenticatedProductsPage.getFormNameValue()
      const priceValue = await authenticatedProductsPage.getFormPriceValue()

      expect(nameValue).toContain(productName)
      expect(priceValue).toContain('9.99') // Form input uses US format (raw value)

      await authenticatedProductsPage.cancelForm()
    }
  })

  /**
   * Test Page Navigation
   */
  test('should stay on products page after navigating away and back', async ({ authenticatedProductsPage }) => {
    await authenticatedProductsPage.navigate()
    const isOnPage = await authenticatedProductsPage.isOnProductsPage()
    expect(isOnPage).toBe(true)

    await authenticatedProductsPage.reloadPage()
    const isStillOnPage = await authenticatedProductsPage.isOnProductsPage()
    expect(isStillOnPage).toBe(true)
  })

  /**
   * Test Icon Selection UI
   * Implements UC: Icon Selection for Products
   */
  test('should display icon select in product form', async ({ authenticatedProductsPage }) => {
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // Verify icon select trigger exists
    const trigger = authenticatedProductsPage.page.getByTestId('products-form-icon-select-trigger')
    await expect(trigger).toBeVisible()

    // Verify trigger shows default text
    const text = await trigger.textContent()
    expect(text).toContain('Select icon')
  })

  test('should allow icon selection in dropdown', async ({ authenticatedProductsPage }) => {
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // Click trigger to open dropdown
    const trigger = authenticatedProductsPage.page.getByTestId('products-form-icon-select-trigger')
    await trigger.click()

    // Dropdown should be visible
    const dropdown = authenticatedProductsPage.page.getByTestId('products-form-icon-select-dropdown')
    await expect(dropdown).toBeVisible()

    // Should have multiple icon options
    const options = authenticatedProductsPage.page.locator('[data-testid^="products-form-icon-select-option-"]')
    const count = await options.count()
    expect(count).toBeGreaterThan(0)
  })

  test('should change selected icon when clicking option', async ({ authenticatedProductsPage }) => {
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // Select first icon
    await authenticatedProductsPage.selectIcon('PilsIcon')
    let selectedIcon = await authenticatedProductsPage.getSelectedIconName()
    expect(selectedIcon).toContain('PilsIcon')

    // Select different icon
    await authenticatedProductsPage.selectIcon('WeizenIcon')
    selectedIcon = await authenticatedProductsPage.getSelectedIconName()
    expect(selectedIcon).toContain('WeizenIcon')
  })

  test('should clear icon when selecting none', async ({ authenticatedProductsPage }) => {
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // Select an icon
    await authenticatedProductsPage.selectIcon('PilsIcon')
    let selectedIcon = await authenticatedProductsPage.getSelectedIconName()
    expect(selectedIcon).toContain('PilsIcon')

    // Clear icon
    await authenticatedProductsPage.clearIcon()
    selectedIcon = await authenticatedProductsPage.getSelectedIconName()
    expect(selectedIcon).toBeNull()
  })

  /**
   * Dispenser Requirement Tests
   * Tests the dispenser requirement flag for products
   */
  test('should create product with dispenser requirement and show badge', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Sauna Token ${Date.now()}`
    const productPrice = '3.00'
    const categoryName = `Wellness ${Date.now()}`

    // Create a category first
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Navigate to products and open create modal
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    // Fill product form
    await authenticatedProductsPage.fillProductForm(productName, productPrice)

    // Select category
    if (categoryId) {
      await authenticatedProductsPage.selectCategory(categoryId)
    }

    // Check requires dispenser checkbox
    await authenticatedProductsPage.checkRequiresDispenser()
    const isChecked = await authenticatedProductsPage.isRequiresDispenserChecked()
    expect(isChecked).toBe(true)

    // Submit form
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // Verify product was created
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    // Verify dispenser badge is visible in the list
    if (productId) {
      const hasBadge = await authenticatedProductsPage.hasDispenserBadge(productId)
      expect(hasBadge).toBe(true)

      // Verify badge content
      const badge = await authenticatedProductsPage.getDispenserBadge(productId)
      if (badge) {
        await expect(badge).toBeVisible()
        const badgeText = await badge.textContent()
        // Badge text could be in German or English depending on locale
        expect(badgeText).toMatch(/Dispenser|Ausgabeautomat/)
      }
    }
  })

  test('should edit product to toggle dispenser requirement on', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Regular Product ${Date.now()}`
    const productPrice = '5.00'
    const categoryName = `Edit Test Category ${Date.now()}`

    // Create a category first
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create product WITHOUT dispenser requirement
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, productPrice, categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Get product ID and verify no dispenser badge initially
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    if (productId) {
      let hasBadge = await authenticatedProductsPage.hasDispenserBadge(productId)
      expect(hasBadge).toBe(false)

      // Edit product to enable dispenser requirement
      await authenticatedProductsPage.clickEditButton(productId)
      await authenticatedProductsPage.expectFormModalVisible()

      // Toggle dispenser checkbox ON
      await authenticatedProductsPage.checkRequiresDispenser()
      const isChecked = await authenticatedProductsPage.isRequiresDispenserChecked()
      expect(isChecked).toBe(true)

      // Save changes
      await authenticatedProductsPage.submitForm()
      await authenticatedProductsPage.expectFormModalHidden()

      // Verify dispenser badge now appears
      hasBadge = await authenticatedProductsPage.hasDispenserBadge(productId)
      expect(hasBadge).toBe(true)

      // Verify badge content
      const badge = await authenticatedProductsPage.getDispenserBadge(productId)
      if (badge) {
        await expect(badge).toBeVisible()
        const badgeText = await badge.textContent()
        // Badge text could be in German or English depending on locale
        expect(badgeText).toMatch(/Dispenser|Ausgabeautomat/)
      }
    }
  })

  test('should edit product to toggle dispenser requirement off', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `Dispenser Product ${Date.now()}`
    const productPrice = '4.50'
    const categoryName = `Toggle Off Category ${Date.now()}`

    // Create a category first
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create product WITH dispenser requirement
    await authenticatedProductsPage.navigate()
    await authenticatedProductsPage.openCreateModal()
    await authenticatedProductsPage.expectFormModalVisible()

    await authenticatedProductsPage.fillProductForm(productName, productPrice)
    if (categoryId) {
      await authenticatedProductsPage.selectCategory(categoryId)
    }
    await authenticatedProductsPage.checkRequiresDispenser()
    await authenticatedProductsPage.submitForm()
    await authenticatedProductsPage.expectFormModalHidden()

    // Get product ID and verify dispenser badge is present
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    if (productId) {
      let hasBadge = await authenticatedProductsPage.hasDispenserBadge(productId)
      expect(hasBadge).toBe(true)

      // Edit product to disable dispenser requirement
      await authenticatedProductsPage.clickEditButton(productId)
      await authenticatedProductsPage.expectFormModalVisible()

      // Verify checkbox is currently checked
      let isChecked = await authenticatedProductsPage.isRequiresDispenserChecked()
      expect(isChecked).toBe(true)

      // Toggle dispenser checkbox OFF
      await authenticatedProductsPage.uncheckRequiresDispenser()
      isChecked = await authenticatedProductsPage.isRequiresDispenserChecked()
      expect(isChecked).toBe(false)

      // Save changes
      await authenticatedProductsPage.submitForm()
      await authenticatedProductsPage.expectFormModalHidden()

      // Verify dispenser badge is now gone
      hasBadge = await authenticatedProductsPage.hasDispenserBadge(productId)
      expect(hasBadge).toBe(false)
    }
  })

  test('should NOT show dispenser badge for regular products', async ({ authenticatedProductsPage, authenticatedCategoriesPage }) => {
    const productName = `No Dispenser Product ${Date.now()}`
    const productPrice = '2.50'
    const categoryName = `Regular Category ${Date.now()}`

    // Create a category first
    await authenticatedCategoriesPage.navigate()
    await authenticatedCategoriesPage.createCategory({ de: categoryName })
    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).not.toBeNull()

    // Create product WITHOUT checking dispenser requirement
    await authenticatedProductsPage.navigate()
    if (categoryId) {
      await authenticatedProductsPage.createProduct(productName, productPrice, categoryId)
    }
    await authenticatedProductsPage.expectFormModalHidden()

    // Verify product was created
    const productId = await authenticatedProductsPage.getProductIdByName(productName)
    expect(productId).not.toBeNull()

    // Verify NO dispenser badge is shown
    if (productId) {
      const hasBadge = await authenticatedProductsPage.hasDispenserBadge(productId)
      expect(hasBadge).toBe(false)
    }
  })

})
