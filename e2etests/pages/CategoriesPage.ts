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
  is_active: boolean
  product_count: number
}

export class CategoriesPage extends BasePage {
  // Main page locators (PRIVATE)
  private readonly table = () => this.page.getByTestId('categories-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="categories-table-row-"]')
  private readonly createBtn = () => this.page.getByTestId('categories-create-button')
  private readonly loadingIndicator = () => this.page.getByTestId('categories-loading-indicator')

  // Modal locators (PRIVATE)
  private readonly formModal = () => this.page.getByTestId('categories-form-modal')
  private readonly iconSelectTrigger = () => this.page.getByTestId('categories-form-icon-select-trigger')
  private readonly iconSelectDropdown = () => this.page.getByTestId('categories-form-icon-select-dropdown')
  private readonly iconSelectOption = (iconName: string) =>
    this.page.getByTestId(`categories-form-icon-select-option-${iconName}`)
  private readonly formTitle = () => this.page.getByTestId('categories-form-title')
  private readonly formSubmitBtn = () => this.page.getByTestId('categories-form-submit-button')
  private readonly formCancelBtn = () => this.page.getByTestId('categories-form-cancel-button')
  private readonly formError = () => this.page.getByTestId('categories-form-error')

  // Language tabs
  private readonly languageTab = (lang: string) => this.page.getByTestId(`categories-form-name-tab-${lang}`)

  // Confirmation dialog
  private readonly confirmDialog = () => this.page.getByTestId('confirm-dialog')
  private readonly confirmMessage = () => this.page.getByTestId('confirm-dialog-message')
  private readonly confirmOkBtn = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly confirmCancelBtn = () => this.page.getByTestId('confirm-dialog-cancel')

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

  async expectFormModalVisible() {
    await expect(this.formModal()).toBeVisible()
  }

  async expectFormModalHidden() {
    // Wait up to 15 seconds for modal to close (includes API call time + parallel load)
    await expect(this.formModal()).not.toBeVisible({ timeout: 15000 })
  }

  async expectConfirmDialogVisible() {
    await expect(this.confirmDialog()).toBeVisible()
  }

  async expectConfirmDialogHidden() {
    await expect(this.confirmDialog()).not.toBeVisible()
  }

  async waitForLoadingToComplete() {
    await expect(this.loadingIndicator()).not.toBeVisible({ timeout: 10000 })
  }

  async expectCreateButtonEnabled() {
    const btn = this.createBtn()
    await expect(btn).toBeVisible()
    await expect(btn).toBeEnabled()
  }

  /**
   * TABLE INTERACTIONS
   */

  async getCategoryCount(): Promise<number> {
    return await this.tableRows().count()
  }

  /**
   * Returns the category ID of the first enabled (not-disabled) delete button.
   */
  async getFirstDeletableCategoryId(): Promise<string | null> {
    const deleteBtn = this.page.locator('[data-testid^="categories-delete-button-"]:not([disabled])').first()
    const count = await deleteBtn.count()
    if (count === 0) return null
    const testId = await deleteBtn.getAttribute('data-testid')
    return testId?.replace('categories-delete-button-', '') ?? null
  }

  async getCategoryName(categoryId: string): Promise<string> {
    const name = await this.page
      .getByTestId(`categories-table-cell-name-${categoryId}`)
      .textContent()
    return (name || '').trim()
  }

  async getCategoryStatus(categoryId: string): Promise<string> {
    // Toggle component stores state via aria-checked attribute
    const isActive = await this.page
      .getByTestId(`categories-status-toggle-${categoryId}`)
      .getAttribute('aria-checked')
    return isActive === 'true' ? 'Active' : 'Inactive'
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
    const input = this.page.getByTestId(`categories-form-name-input-${language}`)
    await input.fill(name)
  }

  async getCategoryNameValue(language: string): Promise<string> {
    const input = this.page.getByTestId(`categories-form-name-input-${language}`)
    return (await input.inputValue()) || ''
  }

  async selectLanguageTab(language: string) {
    await this.languageTab(language).click()
  }

  async submitForm() {
    // Set up response promise BEFORE clicking to capture the categories list reload
    const categoriesReloadPromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/categories') && resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.formSubmitBtn().click()

    // Wait for form to close (indicates API call succeeded)
    await this.expectFormModalHidden()

    // Wait for categories list reload response
    await categoriesReloadPromise

    // Wait for loading indicator to clear
    await this.waitForLoadingToComplete()
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

  /**
   * The modal heading, which reflects the modal mode ("Create ..." vs "Edit ...").
   * Used to prove the create button opens the modal in create mode (#88).
   */
  async getFormTitle(): Promise<string> {
    return ((await this.formTitle().textContent()) || '').trim()
  }

  /**
   * The in-modal error banner, or null when the form shows no error.
   */
  async getFormError(): Promise<string | null> {
    if ((await this.formError().count()) === 0) return null
    return (await this.formError().textContent())?.trim() ?? null
  }

  async getSelectedIconName(): Promise<string | null> {
    const text = await this.iconSelectTrigger().textContent()
    if (!text || text.includes('Select icon')) {
      return null
    }
    return text.trim()
  }

  async openIconDropdown() {
    await this.iconSelectTrigger().click()
    await expect(this.iconSelectDropdown()).toBeVisible()
  }

  async expectIconDropdownVisible() {
    await expect(this.iconSelectDropdown()).toBeVisible()
  }

  async getIconOptionCount(): Promise<number> {
    return await this.page.locator('[data-testid^="categories-form-icon-select-option-"]').count()
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
    // submitForm already waits for loading to complete
  }

  /**
   * STATUS TOGGLE INTERACTIONS
   */

  async toggleCategoryStatus(categoryId: string) {
    const responsePromise = this.page.waitForResponse(
      (r) => {
        const url = new URL(r.url())
        return url.pathname.endsWith(`/admin/categories/${categoryId}`) && r.request().method() === 'PATCH'
      },
      { timeout: 10000 }
    )
    await this.page.getByTestId(`categories-status-toggle-${categoryId}`).click()
    await responsePromise
  }

  /**
   * Click the status toggle when a confirmation dialog is expected to appear.
   * Unlike toggleCategoryStatus(), does NOT wait for PATCH (which only fires after confirm).
   */
  async clickStatusToggleExpectingDialog(categoryId: string) {
    await this.page.getByTestId(`categories-status-toggle-${categoryId}`).click()
  }

  async cancelStatusChange() {
    await this.confirmCancelBtn().click()
    await this.expectConfirmDialogHidden()
  }

  async confirmStatusChange(categoryId: string) {
    const responsePromise = this.page.waitForResponse(
      (r) => {
        const url = new URL(r.url())
        return url.pathname.endsWith(`/admin/categories/${categoryId}`) && r.request().method() === 'PATCH'
      },
      { timeout: 10000 }
    )
    await this.confirmOkBtn().click()
    await this.expectConfirmDialogHidden()
    await responsePromise
    await this.waitForLoadingToComplete()
  }

  /**
   * DELETE INTERACTIONS
   */

  async deleteCategory(categoryId: string) {
    await this.page.getByTestId(`categories-table-action-delete-${categoryId}`).click()
  }

  async confirmDelete() {
    // Set up response promise BEFORE clicking to capture the categories list reload
    const categoriesReloadPromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/categories') && resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.confirmOkBtn().click()
    await this.expectConfirmDialogHidden()

    // Wait for categories list reload response
    await categoriesReloadPromise

    // Wait for loading indicator to clear
    await this.waitForLoadingToComplete()
  }

  async cancelDelete() {
    await this.confirmCancelBtn().click()
    await this.expectConfirmDialogHidden()
  }

  /**
   * ERROR HANDLING
   */

  async getConfirmMessage(): Promise<string | null> {
    if (await this.confirmMessage().count() === 0) return null
    return this.confirmMessage().textContent()
  }

  /**
   * ACCESSIBLE NAMES (#138)
   *
   * The desktop row actions are icon-only; their accessible name has to name
   * the category or every row exposes the same two anonymous controls.
   */

  async expectRowActionsNameTheCategory(categoryId: string, categoryName: string) {
    await expect(
      this.page.getByTestId(`categories-table-action-edit-${categoryId}`)
    ).toHaveAccessibleName(new RegExp(categoryName))
    await expect(
      this.page.getByTestId(`categories-table-action-delete-${categoryId}`)
    ).toHaveAccessibleName(new RegExp(categoryName))
  }
}
