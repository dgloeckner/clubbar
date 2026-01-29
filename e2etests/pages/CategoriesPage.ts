/**
 * Categories Page Object
 *
 * Encapsulates all interactions with the categories management page.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions
 *
 * **CRITICAL PATTERN PRINCIPLES:**
 * 1. Page object provides HIGH-LEVEL SEMANTIC METHODS (not raw locators)
 * 2. Tests use page object methods, NOT page.locator() or page.getByTestId()
 * 3. All locators are PRIVATE and hidden from tests
 * 4. Page object handles data-testid selection internally
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export interface Category {
  id: string
  names: { [lang: string]: string }
  display_order: number
  is_active: boolean
  product_count: number
}

export class CategoriesPage extends BasePage {
  // Main page locators (PRIVATE)
  private readonly table = () => this.page.getByTestId('categories-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="categories-table-row-"]')
  private readonly createBtn = () => this.page.getByTestId('categories-create-button')
  private readonly emptyState = () => this.page.getByTestId('categories-empty-state')
  private readonly errorMessage = () => this.page.getByTestId('categories-error-message')

  // Modal locators (PRIVATE)
  private readonly formModal = () => this.page.getByTestId('categories-form-modal')
  private readonly formModalContent = () => this.page.getByTestId('categories-form-modal-content')
  private readonly formTitle = () => this.page.getByTestId('categories-form-title')
  private readonly formError = () => this.page.getByTestId('categories-form-error')
  private readonly iconSelectTrigger = () => this.page.getByTestId('categories-form-icon-select-trigger')
  private readonly iconSelectDropdown = () => this.page.getByTestId('categories-form-icon-select-dropdown')
  private readonly iconSelectOption = (iconName: string) =>
    this.page.getByTestId(`categories-form-icon-select-option-${iconName}`)
  private readonly formSubmitBtn = () => this.page.getByTestId('categories-form-submit-button')
  private readonly formCancelBtn = () => this.page.getByTestId('categories-form-cancel-button')

  // Language tabs
  private readonly languageTabs = () => this.page.locator('[data-testid^="categories-form-lang-tab-"]')
  private readonly languageTabContent = (lang: string) =>
    this.page.getByTestId(`categories-form-lang-content-${lang}`)

  // Confirmation dialog
  private readonly confirmDialog = () => this.page.getByTestId('categories-confirm-dialog')
  private readonly confirmMessage = () => this.page.getByTestId('categories-confirm-message')
  private readonly confirmOkBtn = () => this.page.getByTestId('categories-confirm-ok')
  private readonly confirmCancelBtn = () => this.page.getByTestId('categories-confirm-cancel')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to categories page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/categories')
  }

  /**
   * VISIBILITY EXPECTATIONS
   */

  async expectPageVisible() {
    await expect(this.page.getByTestId('categories-page')).toBeVisible()
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async expectTableHidden() {
    await expect(this.table()).not.toBeVisible()
  }

  async expectFormModalVisible() {
    await expect(this.formModal()).toBeVisible()
  }

  async expectFormModalHidden() {
    // Wait up to 15 seconds for modal to close (includes API call time + parallel load)
    await expect(this.formModal()).not.toBeVisible({ timeout: 15000 })
  }

  async expectEmptyStateVisible() {
    await expect(this.emptyState()).toBeVisible()
  }

  async expectErrorMessageVisible() {
    await expect(this.errorMessage()).toBeVisible()
  }

  async expectConfirmDialogVisible() {
    await expect(this.confirmDialog()).toBeVisible()
  }

  async expectConfirmDialogHidden() {
    await expect(this.confirmDialog()).not.toBeVisible()
  }

  async expectCategoryRowVisible(categoryId: string) {
    await expect(this.page.getByTestId(`categories-table-row-${categoryId}`)).toBeVisible()
  }

  /**
   * TABLE INTERACTIONS
   */

  async getCategoryCount(): Promise<number> {
    // Wait for any pending DOM updates (helps in parallel test scenarios)
    await this.page.waitForTimeout(200)
    return await this.tableRows().count()
  }

  async getCategoryName(categoryId: string): Promise<string> {
    const name = await this.page
      .getByTestId(`categories-table-cell-name-${categoryId}`)
      .textContent()
    return (name || '').trim()
  }

  async getCategoryStatus(categoryId: string): Promise<string> {
    // Toggle component stores state in data-checked attribute
    const isActive = await this.page
      .getByTestId(`categories-status-toggle-${categoryId}`)
      .getAttribute('data-checked')
    return isActive === 'true' ? 'Active' : 'Inactive'
  }

  async getCategoryProductCount(categoryId: string): Promise<number> {
    const count = await this.page
      .getByTestId(`categories-table-cell-product-count-${categoryId}`)
      .textContent()
    return parseInt(count || '0', 10)
  }

  async getCategoryOrder(categoryId: string): Promise<number> {
    const order = await this.page
      .getByTestId(`categories-table-cell-order-${categoryId}`)
      .textContent()
    return parseInt(order || '0', 10)
  }

  async findCategoryByName(name: string): Promise<string | null> {
    // Search through all visible rows to find a category with matching name
    const rows = this.tableRows()
    const rowCount = await rows.count()

    for (let i = 0; i < rowCount; i++) {
      const row = rows.nth(i)
      const dataTestId = await row.getAttribute('data-testid')
      if (dataTestId) {
        const categoryId = dataTestId.replace('categories-table-row-', '')
        const categoryName = await this.getCategoryName(categoryId)
        if (categoryName === name) {
          return categoryId
        }
      }
    }

    return null
  }

  /**
   * FORM MODAL INTERACTIONS
   */

  async openCreateModal() {
    await this.createBtn().click()
  }

  async openEditModal(categoryId: string) {
    await this.page.getByTestId(`categories-table-action-edit-${categoryId}`).click()
  }

  async fillCategoryName(language: string, name: string) {
    const input = this.languageTabContent(language).getByTestId(`categories-form-name-input-${language}`)
    await input.fill(name)
  }

  async getCategoryNameValue(language: string): Promise<string> {
    const input = this.languageTabContent(language).getByTestId(
      `categories-form-name-input-${language}`
    )
    return (await input.inputValue()) || ''
  }

  async selectLanguageTab(language: string) {
    await this.page.getByTestId(`categories-form-lang-tab-${language}`).click()
  }

  async submitForm() {
    // Click the submit button
    await this.formSubmitBtn().click()

    // Wait for the modal to start closing (form processing)
    // This helps us catch both the creation POST and the subsequent GET
    await this.page.waitForTimeout(100)

    // Wait for all network activity to settle
    // This should catch both the POST request and the GET request for reloading
    await this.page.waitForLoadState('networkidle', { timeout: 15000 })

    // Additional wait to ensure state updates propagate
    await this.page.waitForTimeout(200)
  }

  async cancelForm() {
    await this.formCancelBtn().click()
  }

  async selectIcon(iconName: string) {
    // Click trigger to open dropdown
    await this.iconSelectTrigger().click()
    await expect(this.iconSelectDropdown()).toBeVisible()

    // Click the icon option
    await this.iconSelectOption(iconName).click()
  }

  async clearIcon() {
    // Click trigger to open dropdown
    await this.iconSelectTrigger().click()
    await expect(this.iconSelectDropdown()).toBeVisible()

    // Click clear option
    await this.page.getByTestId('categories-form-icon-select-option-clear').click()
  }

  async expectIconDropdownVisible() {
    await expect(this.iconSelectDropdown()).toBeVisible()
  }

  async expectIconDropdownHidden() {
    await expect(this.iconSelectDropdown()).not.toBeVisible()
  }

  async getSelectedIconName(): Promise<string | null> {
    const text = await this.iconSelectTrigger().textContent()
    if (!text || text.includes('Select icon')) {
      return null
    }
    return text.trim()
  }

  async createCategory(names: { [lang: string]: string }, iconName?: string) {
    await this.openCreateModal()
    await this.expectFormModalVisible()

    // Fill in names for each language
    const languages = Object.keys(names)
    for (let i = 0; i < languages.length; i++) {
      if (i > 0) {
        await this.selectLanguageTab(languages[i])
      }
      await this.fillCategoryName(languages[i], names[languages[i]])
    }

    // Select icon if provided
    if (iconName) {
      await this.selectIcon(iconName)
    }

    await this.submitForm()
    await this.expectFormModalHidden()

    // After modal closes, wait for categories to actually load in the table
    // This ensures the GET /admin/categories request completes
    await this.page.waitForLoadState('networkidle', { timeout: 15000 })

    // Wait for the table to have updated rows (checks DOM stability)
    await this.page.waitForTimeout(500)

    // Verify table is still visible and has updated
    await expect(this.table()).toBeVisible()
  }

  async editCategory(categoryId: string, names: { [lang: string]: string }) {
    await this.openEditModal(categoryId)
    await this.expectFormModalVisible()

    // Fill in names for each language
    const languages = Object.keys(names)
    for (let i = 0; i < languages.length; i++) {
      if (i > 0) {
        await this.selectLanguageTab(languages[i])
      }
      await this.fillCategoryName(languages[i], names[languages[i]])
    }

    await this.submitForm()
    await this.expectFormModalHidden()

    // After modal closes, wait for categories to actually load in the table
    // This ensures the GET /admin/categories request completes
    await this.page.waitForLoadState('networkidle', { timeout: 10000 })
    await this.page.waitForTimeout(300)
  }

  /**
   * STATUS TOGGLE INTERACTIONS
   */

  async toggleCategoryStatus(categoryId: string) {
    await this.page.getByTestId(`categories-status-toggle-${categoryId}`).click()
  }

  async confirmStatusChange() {
    await this.confirmOkBtn().click()
    await this.expectConfirmDialogHidden()

    // Wait for all network activity to settle (includes PATCH and GET requests)
    await this.page.waitForLoadState('networkidle', { timeout: 15000 })

    // Additional wait to ensure state updates propagate
    await this.page.waitForTimeout(300)
  }

  async cancelStatusChange() {
    await this.confirmCancelBtn().click()
    await this.expectConfirmDialogHidden()
  }

  /**
   * DELETE INTERACTIONS
   */

  async deleteCategory(categoryId: string) {
    await this.page.getByTestId(`categories-table-action-delete-${categoryId}`).click()
  }

  async confirmDelete() {
    await this.confirmOkBtn().click()
    await this.expectConfirmDialogHidden()

    // Wait for all network activity to settle (includes DELETE and GET requests)
    await this.page.waitForLoadState('networkidle', { timeout: 15000 })

    // Additional wait to ensure state updates propagate
    await this.page.waitForTimeout(300)
  }

  async cancelDelete() {
    await this.confirmCancelBtn().click()
    await this.expectConfirmDialogHidden()
  }

  /**
   * DRAG & DROP INTERACTIONS
   */

  async dragCategory(fromIndex: number, toIndex: number) {
    const rows = this.tableRows()
    const fromRow = rows.nth(fromIndex)
    const toRow = rows.nth(toIndex)

    await fromRow.dragTo(toRow)
    await this.page.waitForLoadState('networkidle')
  }

  /**
   * ERROR HANDLING
   */

  async getErrorMessage(): Promise<string | null> {
    try {
      return await this.errorMessage().textContent()
    } catch {
      return null
    }
  }

  async getFormErrorMessage(): Promise<string | null> {
    try {
      return await this.formError().textContent()
    } catch {
      return null
    }
  }

  async getConfirmMessage(): Promise<string | null> {
    try {
      return await this.confirmMessage().textContent()
    } catch {
      return null
    }
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async isOnCategoriesPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/categories')
  }
}
