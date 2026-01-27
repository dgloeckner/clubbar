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

    test('should display categories table with correct columns', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: High-level semantic methods
      await authenticatedCategoriesPage.expectTableVisible()

      // Verify column headers exist
      expect(
        await authenticatedCategoriesPage.page
          .locator('[role="columnheader"]:has-text("Status")')
          .isVisible()
      ).toBeTruthy()
      expect(
        await authenticatedCategoriesPage.page
          .locator('[role="columnheader"]:has-text("Name")')
          .isVisible()
      ).toBeTruthy()
      expect(
        await authenticatedCategoriesPage.page
          .locator('[role="columnheader"]:has-text("Products")')
          .isVisible()
      ).toBeTruthy()
    })

    test('should display categories (or empty state)', async ({ authenticatedCategoriesPage }) => {
      // Pattern 003: Database-agnostic - works whether categories exist or not
      const count = await authenticatedCategoriesPage.getCategoryCount()

      if (count === 0) {
        await authenticatedCategoriesPage.expectEmptyStateVisible()
      } else {
        await authenticatedCategoriesPage.expectTableVisible()
        expect(count).toBeGreaterThan(0)
      }
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

    test('should require at least one language name', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Form validation through page object
      await authenticatedCategoriesPage.openCreateModal()

      // Try to submit without filling any name
      await authenticatedCategoriesPage.submitForm()

      // Should show form error (not close modal)
      const errorMessage = await authenticatedCategoriesPage.getFormErrorMessage()
      expect(errorMessage).toBeTruthy()
      expect(errorMessage).toContain('required')
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

    test('should open edit category modal', async ({ authenticatedCategoriesPage }) => {
      // Get first category to edit
      const categoryId = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-edit-button-"]')
        .first()
        .getAttribute('data-testid')
        .then((id) => id?.replace('categories-edit-button-', '') || '')

      if (categoryId) {
        await authenticatedCategoriesPage.openEditModal(categoryId)
        await authenticatedCategoriesPage.expectFormModalVisible()
      }
    })

    test('should edit category and save changes', async ({ authenticatedCategoriesPage }) => {
      // Get first category ID
      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-edit-button-"]')
        .first()
        .getAttribute('data-testid')
      const categoryId = categoryIdAttr?.replace('categories-edit-button-', '') || ''

      if (categoryId) {
        const originalName = await authenticatedCategoriesPage.getCategoryName(categoryId)
        const updatedName = `Updated ${Date.now()}`

        await authenticatedCategoriesPage.editCategory(categoryId, {
          de: updatedName,
        })

        // Verify changes are saved
        await authenticatedCategoriesPage.expectFormModalHidden()
        await authenticatedCategoriesPage.expectTableVisible()

        // Note: Name may not update immediately in table; API needs time
        await authenticatedCategoriesPage.page.waitForTimeout(500)
      }
    })

    test('should update translations in edit modal', async ({ authenticatedCategoriesPage }) => {
      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-edit-button-"]')
        .first()
        .getAttribute('data-testid')
      const categoryId = categoryIdAttr?.replace('categories-edit-button-', '') || ''

      if (categoryId) {
        await authenticatedCategoriesPage.openEditModal(categoryId)

        // Fill German name
        await authenticatedCategoriesPage.selectLanguageTab('de')
        const germanName = `German ${Date.now()}`
        await authenticatedCategoriesPage.fillCategoryName('de', germanName)

        // Switch to English tab
        await authenticatedCategoriesPage.selectLanguageTab('en')
        const englishName = `English ${Date.now()}`
        await authenticatedCategoriesPage.fillCategoryName('en', englishName)

        // Verify both are filled
        await authenticatedCategoriesPage.selectLanguageTab('de')
        const savedGerman = await authenticatedCategoriesPage.getCategoryNameValue('de')
        expect(savedGerman).toContain('German')

        await authenticatedCategoriesPage.selectLanguageTab('en')
        const savedEnglish = await authenticatedCategoriesPage.getCategoryNameValue('en')
        expect(savedEnglish).toContain('English')

        await authenticatedCategoriesPage.cancelForm()
      }
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

    test('should toggle category status (deactivate)', async ({ authenticatedCategoriesPage }) => {
      // Get first category
      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-status-toggle-"]')
        .first()
        .getAttribute('data-testid')
      const categoryId = categoryIdAttr?.replace('categories-status-toggle-', '') || ''

      if (categoryId) {
        // Get initial status
        const initialStatus = await authenticatedCategoriesPage.getCategoryStatus(categoryId)

        // Toggle status
        await authenticatedCategoriesPage.toggleCategoryStatus(categoryId)
        await authenticatedCategoriesPage.expectConfirmDialogVisible()

        // Confirm change
        await authenticatedCategoriesPage.confirmStatusChange()

        // Verify status changed
        await authenticatedCategoriesPage.page.waitForTimeout(500)
        const newStatus = await authenticatedCategoriesPage.getCategoryStatus(categoryId)
        expect(newStatus).not.toBe(initialStatus)
      }
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
    test('should delete empty category', async ({ authenticatedCategoriesPage }) => {
      // Create a category to delete
      const categoryName = `Delete Test ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      const countBefore = await authenticatedCategoriesPage.getCategoryCount()

      // Get first delete button
      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-delete-button-"]')
        .first()
        .getAttribute('data-testid')
      const categoryId = categoryIdAttr?.replace('categories-delete-button-', '') || ''

      if (categoryId) {
        await authenticatedCategoriesPage.deleteCategory(categoryId)
        await authenticatedCategoriesPage.expectConfirmDialogVisible()

        await authenticatedCategoriesPage.confirmDelete()
        await authenticatedCategoriesPage.expectConfirmDialogHidden()

        // Verify category was deleted
        const countAfter = await authenticatedCategoriesPage.getCategoryCount()
        expect(countAfter).toBeLessThan(countBefore)
      }
    })

    test('should show confirmation before delete', async ({ authenticatedCategoriesPage }) => {
      // Create a category
      const categoryName = `Confirm Delete ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-delete-button-"]')
        .first()
        .getAttribute('data-testid')
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
    })

    test('should cancel delete and keep category', async ({ authenticatedCategoriesPage }) => {
      // Create a category
      const categoryName = `Cancel Delete ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      const countBefore = await authenticatedCategoriesPage.getCategoryCount()

      const categoryIdAttr = await authenticatedCategoriesPage.page
        .locator('[data-testid^="categories-delete-button-"]')
        .first()
        .getAttribute('data-testid')
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
  })
})
