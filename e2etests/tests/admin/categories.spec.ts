import { test, expect } from '../../fixtures/pageObjects'

/**
 * Admin Frontend - Categories Page E2E Tests
 *
 * Tests the admin panel Categories management page CRUD operations.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 007: Page Object Fixtures
 * Implements E2E Testing Pattern 008: Playwright Assertions (expect API)
 *
 * **CRITICAL: Tests use PAGE OBJECT METHODS, NOT raw locators**
 *
 * ✅ CORRECT:
 *   await categoriesPage.expectTableVisible()
 *   await categoriesPage.getCategoryCount()
 *   await categoriesPage.createCategory({ de: 'Test', en: 'Test' })
 *
 * ❌ WRONG (don't do this):
 *   const table = page.locator('table, [role="table"]')
 *   const modal = page.locator('[role="dialog"], [class*="modal"]')
 *   const input = page.getByTestId('categories-form-name-input-de')
 *
 * Covers:
 * - UC-A44: Manage Categories
 *   - List categories
 *   - Create category
 *   - Edit category
 *   - Activate/Deactivate category
 *   - Delete category
 *   - Reorder categories
 *
 * Uses E2E Pattern 001: Test Data Isolation
 * - Each test creates unique test data (timestamps)
 * - No shared state between tests
 * - Safe for parallel execution
 */

test.describe('Admin Frontend - Categories Page', () => {
  /**
   * UC-A44: List Categories
   */
  test.describe('UC-A44: List Categories', () => {
    test('should display categories page', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Use page object methods
      await authenticatedCategoriesPage.expectPageVisible()
      await authenticatedCategoriesPage.expectTableVisible()
    })


    test('should verify page is on categories route', async ({ page, authenticatedCategoriesPage }) => {
      // Pattern 006: Verify we're on the right page
      await authenticatedCategoriesPage.expectPageVisible()
      expect(page.url()).toContain('/categories')
    })

    test('should display create button', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Create button should be clickable
      const button = authenticatedCategoriesPage.page.getByTestId('categories-create-button')
      expect(await button.isVisible()).toBeTruthy()
      expect(await button.isEnabled()).toBeTruthy()
    })
  })

  /**
   * UC-A44: Create Category
   */
  test.describe('UC-A44: Create Category', () => {
    test('should open create category modal', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Use page object methods
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()
    })

    test('should cancel create modal without submitting', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Semantic actions through page object
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      await authenticatedCategoriesPage.cancelForm()
      await authenticatedCategoriesPage.expectFormModalHidden()
    })

    test('should create category with single language', async ({ authenticatedCategoriesPage }) => {
      // Pattern 001: Create unique test data per test
      const categoryName = `Test Category ${Date.now()}`

      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      // Pattern 008: Wait for form to close and verify
      await authenticatedCategoriesPage.expectFormModalHidden()
      await authenticatedCategoriesPage.expectTableVisible()

      // Verify category appears in list
      const count = await authenticatedCategoriesPage.getCategoryCount()
      expect(count).toBeGreaterThan(0)
    })

    test('should create category with multilingual names', async ({ authenticatedCategoriesPage }) => {
      // Pattern 001: Create unique test data per test
      const timestamp = Date.now()
      const categoryNames = {
        de: `Deutsche Kategorie ${timestamp}`,
        en: `English Category ${timestamp}`,
      }

      await authenticatedCategoriesPage.createCategory(categoryNames)

      // Pattern 008: Verify creation successful
      await authenticatedCategoriesPage.expectFormModalHidden()
      await authenticatedCategoriesPage.expectTableVisible()
    })

    test('should display form with empty fields for create', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Page object provides field getters
      await authenticatedCategoriesPage.openCreateModal()

      const deValue = await authenticatedCategoriesPage.getCategoryNameValue('de')
      const enValue = await authenticatedCategoriesPage.getCategoryNameValue('en')

      expect(deValue).toBe('')
      expect(enValue).toBe('')
    })

  })

  /**
   * UC-A44: Edit Category
   */
  test.describe('UC-A44: Edit Category', () => {
    test.beforeEach(async ({ authenticatedCategoriesPage }) => {
      // Create a test category for edit tests
      const categoryName = `Editable ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })
    })

  })

  /**
   * UC-A44: Activate/Deactivate Category
   */
  test.describe('UC-A44: Activate/Deactivate Category', () => {
    test.beforeEach(async ({ authenticatedCategoriesPage }) => {
      // Create a test category for status toggle tests
      const categoryName = `Toggle Test ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })
    })


    test('should show confirmation dialog on status toggle', async ({ authenticatedCategoriesPage }) => {
      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-status-toggle-"]')
        .first()
        .getAttribute('data-testid')
      const categoryId = categoryIdAttr?.replace('categories-status-toggle-', '') || ''

      if (categoryId) {
        await authenticatedCategoriesPage.toggleCategoryStatus(categoryId)
        await authenticatedCategoriesPage.expectConfirmDialogVisible()

        // Verify dialog has message
        const message = await authenticatedCategoriesPage.getConfirmMessage()
        expect(message).toBeTruthy()
      }
    })

    test('should cancel status change via confirmation dialog', async ({ authenticatedCategoriesPage }) => {
      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-status-toggle-"]')
        .first()
        .getAttribute('data-testid')
      const categoryId = categoryIdAttr?.replace('categories-status-toggle-', '') || ''

      if (categoryId) {
        const initialStatus = await authenticatedCategoriesPage.getCategoryStatus(categoryId)

        await authenticatedCategoriesPage.toggleCategoryStatus(categoryId)
        await authenticatedCategoriesPage.expectConfirmDialogVisible()

        // Cancel change
        await authenticatedCategoriesPage.cancelStatusChange()
        await authenticatedCategoriesPage.expectConfirmDialogHidden()

        // Status should remain unchanged
        const statusAfterCancel = await authenticatedCategoriesPage.getCategoryStatus(categoryId)
        expect(statusAfterCancel).toBe(initialStatus)
      }
    })
  })

  /**
   * UC-A44: Delete Category
   */
  test.describe('UC-A44: Delete Category', () => {

    test('should show confirmation before delete', async ({ authenticatedCategoriesPage }) => {
      // Create a category
      const categoryName = `Confirm Delete ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      // Find an empty (enabled) delete button
      const deleteButtons = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-delete-button-"]:not([disabled])')

      const buttonCount = await deleteButtons.count()

      if (buttonCount > 0) {
        const categoryIdAttr = await deleteButtons.first().getAttribute('data-testid')
        const categoryId = categoryIdAttr?.replace('categories-delete-button-', '') || ''

        if (categoryId) {
          await authenticatedCategoriesPage.deleteCategory(categoryId)
          await authenticatedCategoriesPage.expectConfirmDialogVisible()

          const message = await authenticatedCategoriesPage.getConfirmMessage()
          expect(message).toBeTruthy()

          // Cancel delete
          await authenticatedCategoriesPage.cancelDelete()
          await authenticatedCategoriesPage.expectConfirmDialogHidden()
        }
      }
    })

    test('should cancel delete and keep category', async ({ authenticatedCategoriesPage }) => {
      // Create a category
      const categoryName = `Cancel Delete ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      const countBefore = await authenticatedCategoriesPage.getCategoryCount()

      // Find an empty (enabled) delete button
      const deleteButtons = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-delete-button-"]:not([disabled])')

      const buttonCount = await deleteButtons.count()

      if (buttonCount > 0) {
        const categoryIdAttr = await deleteButtons.first().getAttribute('data-testid')
        const categoryId = categoryIdAttr?.replace('categories-delete-button-', '') || ''

        if (categoryId) {
          await authenticatedCategoriesPage.deleteCategory(categoryId)
          await authenticatedCategoriesPage.expectConfirmDialogVisible()

          await authenticatedCategoriesPage.cancelDelete()
          await authenticatedCategoriesPage.expectConfirmDialogHidden()

          // Count should remain same
          const countAfter = await authenticatedCategoriesPage.getCategoryCount()
          expect(countAfter).toBe(countBefore)
        }
      }
    })
  })

  /**
   * Modal Interactions (General)
   */
  test.describe('Modal Interactions', () => {
    test('should close modal when clicking cancel', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: High-level modal methods
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      await authenticatedCategoriesPage.cancelForm()
      await authenticatedCategoriesPage.expectFormModalHidden()
    })

    test('should fill form with category names in multiple languages', async ({ authenticatedCategoriesPage }) => {
      const testData = {
        de: `Deutsch ${Date.now()}`,
        en: `English ${Date.now()}`,
      }

      await authenticatedCategoriesPage.openCreateModal()

      // Fill German
      await authenticatedCategoriesPage.fillCategoryName('de', testData.de)
      expect(await authenticatedCategoriesPage.getCategoryNameValue('de')).toContain(testData.de)

      // Switch to English
      await authenticatedCategoriesPage.selectLanguageTab('en')
      await authenticatedCategoriesPage.fillCategoryName('en', testData.en)
      expect(await authenticatedCategoriesPage.getCategoryNameValue('en')).toContain(testData.en)

      await authenticatedCategoriesPage.cancelForm()
    })

    test('should switch between language tabs in form', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.openCreateModal()

      // Start on German tab
      await authenticatedCategoriesPage.fillCategoryName('de', 'German Name')

      // Switch to English tab
      await authenticatedCategoriesPage.selectLanguageTab('en')
      await authenticatedCategoriesPage.fillCategoryName('en', 'English Name')

      // Switch back to German and verify value is still there
      await authenticatedCategoriesPage.selectLanguageTab('de')
      const germanValue = await authenticatedCategoriesPage.getCategoryNameValue('de')
      expect(germanValue).toContain('German Name')

      await authenticatedCategoriesPage.cancelForm()
    })
  })

  /**
   * Page State Verification
   */
  test.describe('Page State Verification', () => {
    test('should verify page is on categories route', async ({ page, authenticatedCategoriesPage }) => {
      // Pattern 006: Verify we're on the right page
      await authenticatedCategoriesPage.expectPageVisible()
      expect(page.url()).toContain('/categories')
    })

    test('should display create button as enabled', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Create button should be clickable and enabled
      const button = authenticatedCategoriesPage.page.getByTestId('categories-create-button')
      expect(await button.isVisible()).toBeTruthy()
      expect(await button.isEnabled()).toBeTruthy()
    })

    test('should maintain form state when switching language tabs', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.openCreateModal()

      const germanName = `German ${Date.now()}`
      const englishName = `English ${Date.now()}`

      // Fill German
      await authenticatedCategoriesPage.selectLanguageTab('de')
      await authenticatedCategoriesPage.fillCategoryName('de', germanName)

      // Fill English
      await authenticatedCategoriesPage.selectLanguageTab('en')
      await authenticatedCategoriesPage.fillCategoryName('en', englishName)

      // Verify both are preserved
      await authenticatedCategoriesPage.selectLanguageTab('de')
      expect(await authenticatedCategoriesPage.getCategoryNameValue('de')).toContain(germanName)

      await authenticatedCategoriesPage.selectLanguageTab('en')
      expect(await authenticatedCategoriesPage.getCategoryNameValue('en')).toContain(englishName)

      await authenticatedCategoriesPage.cancelForm()
    })

    /**
     * Test Icon Selection
     * Implements UC: Icon Selection for Categories
     */
    test('should allow icon selection in dropdown', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.navigate()
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      // Click trigger to open dropdown
      const trigger = authenticatedCategoriesPage.page.getByTestId('categories-form-icon-select-trigger')
      await trigger.click()

      // Dropdown should be visible
      const dropdown = authenticatedCategoriesPage.page.getByTestId('categories-form-icon-select-dropdown')
      await expect(dropdown).toBeVisible()

      // Should have multiple icon options
      const options = authenticatedCategoriesPage.page.locator('[data-testid^="categories-form-icon-select-option-"]')
      const count = await options.count()
      expect(count).toBeGreaterThan(0)
    })

    test('should change selected icon when clicking option', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.navigate()
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      // Select first icon
      await authenticatedCategoriesPage.selectIcon('PilsIcon')
      let selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toContain('PilsIcon')

      // Select different icon
      await authenticatedCategoriesPage.selectIcon('WeizenIcon')
      selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toContain('WeizenIcon')
    })

    test('should clear icon when selecting none', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.navigate()
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      // Select an icon (categories use universal product icons)
      await authenticatedCategoriesPage.selectIcon('PilsIcon')
      let selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toContain('PilsIcon')

      // Clear icon
      await authenticatedCategoriesPage.clearIcon()
      selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toBeNull()
    })

  })

  /**
   * UC-A44: Comprehensive E2E Tests with Persistence Verification
   * Following the pattern from members tests:
   * - Create/Edit with ALL fields
   * - Re-open modal to verify persistence (database round-trip)
   * - Verify no errors during save
   */
  test.describe('UC-A44: Comprehensive E2E Tests', () => {
    test('E2E: should create category with all fields and verify persistence', async ({ authenticatedCategoriesPage, page }) => {
      // Pattern 001: Unique test data per test
      const timestamp = Date.now()
      const testData = {
        names: {
          de: `Getränke E2E ${timestamp}`,
          en: `Beverages E2E ${timestamp}`,
        },
        icon: 'PilsIcon',
      }

      const initialCount = await authenticatedCategoriesPage.getCategoryCount()

      // Create category
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      // Fill German name
      await authenticatedCategoriesPage.fillCategoryName('de', testData.names.de)

      // Switch to English tab and fill
      await authenticatedCategoriesPage.selectLanguageTab('en')
      await authenticatedCategoriesPage.fillCategoryName('en', testData.names.en)

      // Select icon
      await authenticatedCategoriesPage.selectIcon(testData.icon)

      // Submit form (waits for loading to complete)
      await authenticatedCategoriesPage.submitForm()

      // Verify category exists in database by finding it
      const newCount = await authenticatedCategoriesPage.getCategoryCount()
      expect(newCount).toBeGreaterThan(0)

      // Find created category by name
      const categoryId = await authenticatedCategoriesPage.findCategoryByName(testData.names.de)
      expect(categoryId).toBeTruthy()

      if (categoryId) {
        // PERSISTENCE CHECK: Re-open edit modal to verify all fields saved to database
        await authenticatedCategoriesPage.openEditModal(categoryId)
        await authenticatedCategoriesPage.expectFormModalVisible()

        // Verify German name persisted
        const savedDeValue = await authenticatedCategoriesPage.getCategoryNameValue('de')
        expect(savedDeValue).toBe(testData.names.de)

        // Verify English name persisted
        await authenticatedCategoriesPage.selectLanguageTab('en')
        const savedEnValue = await authenticatedCategoriesPage.getCategoryNameValue('en')
        expect(savedEnValue).toBe(testData.names.en)

        // Verify icon persisted
        const savedIcon = await authenticatedCategoriesPage.getSelectedIconName()
        expect(savedIcon).toContain(testData.icon)

        await authenticatedCategoriesPage.cancelForm()
      }
    })

    test('E2E: should edit all category fields and verify persistence', async ({ authenticatedCategoriesPage, page }) => {
      // Pattern 001: Create test category first
      const timestamp = Date.now()
      const originalData = {
        de: `Original Category ${timestamp}`,
        en: `Original English ${timestamp}`,
      }

      await authenticatedCategoriesPage.createCategory(originalData, 'WeizenIcon')
      // createCategory waits for loading to complete

      // Find the created category
      const categoryId = await authenticatedCategoriesPage.findCategoryByName(originalData.de)
      expect(categoryId).toBeTruthy()

      if (categoryId) {
        // Open edit modal
        await authenticatedCategoriesPage.openEditModal(categoryId)
        await authenticatedCategoriesPage.expectFormModalVisible()

        // Edit all fields with new unique values
        const newData = {
          names: {
            de: `Bearbeitet DE ${Date.now()}`,
            en: `Edited EN ${Date.now()}`,
          },
          icon: 'RadlerIcon',
        }

        // Fill German name
        await authenticatedCategoriesPage.fillCategoryName('de', newData.names.de)

        // Switch to English and fill
        await authenticatedCategoriesPage.selectLanguageTab('en')
        await authenticatedCategoriesPage.fillCategoryName('en', newData.names.en)

        // Change icon
        await authenticatedCategoriesPage.selectIcon(newData.icon)

        // Submit form (waits for loading to complete)
        await authenticatedCategoriesPage.submitForm()

        // Verify edited name appears in table
        const updatedName = await authenticatedCategoriesPage.getCategoryName(categoryId)
        expect(updatedName).toBe(newData.names.de)

        // PERSISTENCE CHECK: Re-open modal to verify all edited fields saved
        await authenticatedCategoriesPage.openEditModal(categoryId)
        await authenticatedCategoriesPage.expectFormModalVisible()

        // Verify German name persisted
        const savedDeValue = await authenticatedCategoriesPage.getCategoryNameValue('de')
        expect(savedDeValue).toBe(newData.names.de)

        // Verify English name persisted
        await authenticatedCategoriesPage.selectLanguageTab('en')
        const savedEnValue = await authenticatedCategoriesPage.getCategoryNameValue('en')
        expect(savedEnValue).toBe(newData.names.en)

        // Verify icon persisted
        const savedIcon = await authenticatedCategoriesPage.getSelectedIconName()
        expect(savedIcon).toContain(newData.icon)

        await authenticatedCategoriesPage.cancelForm()
      }
    })
  })

  /**
   * UC-A44: Sorting Tests
   * Verify sorting by name works correctly
   */
  test.describe('UC-A44: Sorting', () => {
    test('should sort categories by name ascending', async ({ authenticatedCategoriesPage, page }) => {
      // Create test categories with known sort order
      const timestamp = Date.now()
      await authenticatedCategoriesPage.createCategory({ de: `AAA Sort Test ${timestamp}` })
      await authenticatedCategoriesPage.createCategory({ de: `ZZZ Sort Test ${timestamp}` })
      await authenticatedCategoriesPage.createCategory({ de: `MMM Sort Test ${timestamp}` })

      // Click sort header to sort ascending
      const sortHeader = page.getByTestId('categories-sort-name')
      await sortHeader.click()
      await authenticatedCategoriesPage.waitForLoadingToComplete()

      // Verify first category name starts with A (or comes first alphabetically)
      const firstCategoryId = await page
        .locator('[data-testid^="categories-table-row-"]')
        .first()
        .getAttribute('data-testid')
        .then((id) => id?.replace('categories-table-row-', '') || '')

      if (firstCategoryId) {
        const firstName = await authenticatedCategoriesPage.getCategoryName(firstCategoryId)
        const secondCategoryId = await page
          .locator('[data-testid^="categories-table-row-"]')
          .nth(1)
          .getAttribute('data-testid')
          .then((id) => id?.replace('categories-table-row-', '') || '')

        if (secondCategoryId) {
          const secondName = await authenticatedCategoriesPage.getCategoryName(secondCategoryId)
          // Verify alphabetical order
          expect(firstName.toLowerCase() <= secondName.toLowerCase()).toBeTruthy()
        }
      }
    })

  })
})
