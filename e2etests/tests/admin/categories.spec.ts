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
      await authenticatedCategoriesPage.expectCreateButtonEnabled()
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

    /**
     * Regression for #88.
     *
     * The desktop create button only reset `selectedCategory`, leaving the modal
     * in "edit" mode with the previously edited category's values. Submitting
     * then matched neither branch of handleSubmit: the modal closed as if the
     * save had worked and no category was created.
     */
    test('E2E: create after cancelling an edit opens an empty create form and persists', async ({
      authenticatedCategoriesPage,
    }) => {
      const timestamp = Date.now()
      const seed = { de: `Seed für Create ${timestamp}`, en: `Seed for Create ${timestamp}` }

      // A category to edit first, with an icon so a stale icon would show up too.
      await authenticatedCategoriesPage.createCategory(seed, 'beer-pils')
      const seedId = await authenticatedCategoriesPage.findCategoryByName(seed.de)
      expect(seedId).toBeTruthy()

      // Edit it, then cancel — this is what poisoned the modal state.
      await authenticatedCategoriesPage.openEditModal(seedId!)
      await authenticatedCategoriesPage.expectFormModalVisible()
      const editTitle = await authenticatedCategoriesPage.getFormTitle()
      await authenticatedCategoriesPage.cancelForm()
      await authenticatedCategoriesPage.expectFormModalHidden()

      // Now open the create modal: it must be in create mode, not edit mode.
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      expect(await authenticatedCategoriesPage.getFormTitle()).not.toBe(editTitle)
      expect(await authenticatedCategoriesPage.getFormError()).toBeNull()
      expect(await authenticatedCategoriesPage.getCategoryNameValue('de')).toBe('')
      await authenticatedCategoriesPage.selectLanguageTab('en')
      expect(await authenticatedCategoriesPage.getCategoryNameValue('en')).toBe('')
      expect(await authenticatedCategoriesPage.getSelectedIconName()).toBeNull()

      // And submitting must actually create a category, not close silently.
      const created = `Created After Edit ${timestamp}`
      await authenticatedCategoriesPage.selectLanguageTab('de')
      await authenticatedCategoriesPage.fillCategoryName('de', created)
      await authenticatedCategoriesPage.submitForm()

      const createdId = await authenticatedCategoriesPage.findCategoryByName(created)
      expect(createdId).toBeTruthy()

      // The seed category must be untouched — the save must not have edited it.
      expect(await authenticatedCategoriesPage.getCategoryName(seedId!)).toBe(seed.de)
    })

    test('should display form with empty fields for create', async ({ authenticatedCategoriesPage }) => {
      // Pattern 006: Page object provides field getters
      await authenticatedCategoriesPage.openCreateModal()

      // Check German tab (default active tab)
      const deValue = await authenticatedCategoriesPage.getCategoryNameValue('de')
      expect(deValue).toBe('')

      // Switch to English tab and check
      await authenticatedCategoriesPage.selectLanguageTab('en')
      const enValue = await authenticatedCategoriesPage.getCategoryNameValue('en')
      expect(enValue).toBe('')
    })

  })

  /**
   * UC-A44: Activate/Deactivate Category
   */
  test.describe('UC-A44: Activate/Deactivate Category', () => {
    test('should deactivate category immediately without confirmation dialog', async ({ authenticatedCategoriesPage }) => {
      // Create a unique test category (Pattern 001: Test Data Isolation)
      const categoryName = `Deact ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({ de: categoryName })

      const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
      expect(categoryId).toBeTruthy()

      // Deactivation should be immediate — no confirm dialog
      await authenticatedCategoriesPage.toggleCategoryStatus(categoryId!)
      await authenticatedCategoriesPage.expectConfirmDialogHidden()

      // Status should have changed to Inactive
      const status = await authenticatedCategoriesPage.getCategoryStatus(categoryId!)
      expect(status).toBe('Inactive')
    })

    test('should show confirmation dialog when activating category and cancel keeps status unchanged', async ({ authenticatedCategoriesPage }) => {
      // Create a unique test category (Pattern 001: Test Data Isolation)
      const categoryName = `ActCancel ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({ de: categoryName })

      const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
      expect(categoryId).toBeTruthy()

      // First deactivate (immediate, no dialog) — toggleCategoryStatus waits for API internally
      await authenticatedCategoriesPage.toggleCategoryStatus(categoryId!)
      await authenticatedCategoriesPage.expectConfirmDialogHidden()

      // Now activate — shows confirmation dialog before API call
      await authenticatedCategoriesPage.clickStatusToggleExpectingDialog(categoryId!)
      await authenticatedCategoriesPage.expectConfirmDialogVisible()

      // Verify dialog has a meaningful message (translated, so check length only)
      const message = await authenticatedCategoriesPage.getConfirmMessage()
      expect(message).toBeTruthy()
      expect(message!.length).toBeGreaterThan(5)

      // Cancel — status should remain Inactive
      await authenticatedCategoriesPage.cancelStatusChange()
      await authenticatedCategoriesPage.expectConfirmDialogHidden()

      const statusAfterCancel = await authenticatedCategoriesPage.getCategoryStatus(categoryId!)
      expect(statusAfterCancel).toBe('Inactive')
    })

    test('should activate category when confirm dialog is confirmed', async ({ authenticatedCategoriesPage }) => {
      const categoryName = `ActConfirm ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({ de: categoryName })

      const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
      expect(categoryId).toBeTruthy()

      // Deactivate first (immediate, no dialog)
      await authenticatedCategoriesPage.toggleCategoryStatus(categoryId!)
      await authenticatedCategoriesPage.expectConfirmDialogHidden()
      expect(await authenticatedCategoriesPage.getCategoryStatus(categoryId!)).toBe('Inactive')

      // Activate: shows confirm dialog
      await authenticatedCategoriesPage.clickStatusToggleExpectingDialog(categoryId!)
      await authenticatedCategoriesPage.expectConfirmDialogVisible()

      // Confirm → category becomes Active
      await authenticatedCategoriesPage.confirmStatusChange(categoryId!)
      const status = await authenticatedCategoriesPage.getCategoryStatus(categoryId!)
      expect(status).toBe('Active')
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
      const categoryId = await authenticatedCategoriesPage.getFirstDeletableCategoryId()

      if (categoryId) {
        await authenticatedCategoriesPage.deleteCategory(categoryId)
        await authenticatedCategoriesPage.expectConfirmDialogVisible()

        const message = await authenticatedCategoriesPage.getConfirmMessage()
        expect(message).toBeTruthy()
        expect(message!.length).toBeGreaterThan(5)

        // Cancel delete
        await authenticatedCategoriesPage.cancelDelete()
        await authenticatedCategoriesPage.expectConfirmDialogHidden()
      }
    })

    test('should delete category when confirm dialog is confirmed', async ({ page, authenticatedCategoriesPage }) => {
      const categoryName = `ConfirmDel ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({ de: categoryName })

      const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
      expect(categoryId).toBeTruthy()

      // Trigger delete → confirm dialog appears
      await authenticatedCategoriesPage.deleteCategory(categoryId!)
      await authenticatedCategoriesPage.expectConfirmDialogVisible()

      // Capture DELETE response before clicking OK
      const deleteResponsePromise = page.waitForResponse(
        (resp) => resp.url().includes(`/api/admin/categories/${categoryId}`) && resp.request().method() === 'DELETE'
      )

      // Confirm delete
      await page.getByTestId('confirm-dialog-ok').click()

      // Wait for the DELETE to complete
      const deleteResp = await deleteResponsePromise
      expect(deleteResp.status()).toBe(204)

      // Poll until the category disappears from the list (React re-render after loadCategories())
      await expect.poll(() => authenticatedCategoriesPage.findCategoryByName(categoryName), { timeout: 10000 })
        .toBeNull()
    })

    test('should cancel delete and keep category', async ({ authenticatedCategoriesPage }) => {
      // Create a category
      const categoryName = `Cancel Delete ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })

      const countBefore = await authenticatedCategoriesPage.getCategoryCount()

      // Find an enabled delete button
      const categoryId = await authenticatedCategoriesPage.getFirstDeletableCategoryId()

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
      await authenticatedCategoriesPage.expectCreateButtonEnabled()
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

      // Open dropdown and verify it is visible with options
      await authenticatedCategoriesPage.openIconDropdown()
      await authenticatedCategoriesPage.expectIconDropdownVisible()

      const count = await authenticatedCategoriesPage.getIconOptionCount()
      expect(count).toBeGreaterThan(0)
    })

    test('should change selected icon when clicking option', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.navigate()
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      // Select first icon
      await authenticatedCategoriesPage.selectIcon('beer-pils')
      let selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toContain('beer-pils')

      // Select different icon
      await authenticatedCategoriesPage.selectIcon('beer-weizen')
      selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toContain('beer-weizen')
    })

    test('should clear icon when selecting none', async ({ authenticatedCategoriesPage }) => {
      await authenticatedCategoriesPage.navigate()
      await authenticatedCategoriesPage.openCreateModal()
      await authenticatedCategoriesPage.expectFormModalVisible()

      // Select an icon (categories use universal product icons)
      await authenticatedCategoriesPage.selectIcon('beer-pils')
      let selectedIcon = await authenticatedCategoriesPage.getSelectedIconName()
      expect(selectedIcon).toContain('beer-pils')

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
        icon: 'beer-pils',
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

      await authenticatedCategoriesPage.createCategory(originalData, 'beer-weizen')
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
          icon: 'beer-radler',
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
      // Use waitForResponse instead of waitForLoadingToComplete (which uses networkidle and fires
      // before React's useEffect triggers the categories API call after sort state change)
      const sortHeader = page.getByTestId('categories-sort-name')
      const sortedResponse = page.waitForResponse(
        (resp) => resp.url().includes('/api/admin/categories') && resp.status() === 200,
      )
      await sortHeader.click()
      await sortedResponse

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
